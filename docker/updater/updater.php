<?php
/**
 * elli – Update-Dienst (kleiner HTTP-Dienst, laeuft im updater-Container)
 *
 * Endpunkte (alle liefern JSON):
 *   GET  /status       Aktueller Zustand + Protokoll des laufenden Updates
 *   GET  /pruefen      Holt den Stand von GitHub und vergleicht ihn mit lokal
 *   POST /update       Startet das Update im Hintergrund (update.sh)
 *   POST /quittieren   Loescht das Protokoll des letzten Laufs
 *
 * Der Dienst haengt am Docker-Socket des Hosts und kann Container neu bauen.
 * Er ist deshalb in docker-compose.yml bewusst nur an 127.0.0.1 gebunden.
 */

$REPO  = getenv('REPO_DIR') ?: '/repo';
$STATE = getenv('STATE_DIR') ?: '/state';
$LOG   = $STATE . '/update.log';
$PID   = $STATE . '/update.pid';
$SKRIPT = '/app/update.sh';

if (!is_dir($STATE)) @mkdir($STATE, 0777, true);

// Die Oberflaeche laeuft auf einem anderen Port (8080) als dieser Dienst
// (8081) – ohne CORS-Kopf wuerde der Browser die Antwort verwerfen.
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: Content-Type');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

/** Git-Aufruf im Projektverzeichnis; eigene Hooks bleiben aussen vor. */
function git(string $repo, array $args, ?string &$ausgabe = null): int
{
    $cmd = 'git -C ' . escapeshellarg($repo) . ' -c core.hooksPath=/nonexistent ';
    $cmd .= implode(' ', array_map('escapeshellarg', $args)) . ' 2>&1';
    $zeilen = [];
    $code = 0;
    exec($cmd, $zeilen, $code);
    $ausgabe = trim(implode("\n", $zeilen));
    return $code;
}

/**
 * Laeuft der gestartete Update-Prozess noch?
 *
 * Bewusst ueber die gemerkte PID und nicht ueber den Skriptnamen: ein Update
 * tauscht unter Umstaenden diesen Dienst selbst aus, waehrend das Skript noch
 * arbeitet – ein Namensmuster wuerde dann ins Leere greifen und einen
 * laufenden Vorgang als Absturz melden.
 */
function laeuftUpdate(string $pidDatei): bool
{
    if (!is_file($pidDatei)) return false;
    $pid = (int)trim((string)@file_get_contents($pidDatei));
    if ($pid <= 0 || !is_dir('/proc/' . $pid)) return false;

    // Beendete Kindprozesse bleiben als Zombie im Prozessbaum stehen (der
    // eingebaute PHP-Server erntet sie nicht ab) – die zaehlen nicht.
    $stat = (string)@file_get_contents('/proc/' . $pid . '/stat');
    $nachName = strrchr($stat, ')');
    return !($nachName !== false && preg_match('/^\)\s+Z/', $nachName));
}

/**
 * Zustand aus dem Protokoll ableiten.
 * bereit  – kein Lauf bekannt
 * laeuft  – Skript arbeitet noch
 * fertig  – letzter Lauf war erfolgreich
 * fehler  – letzter Lauf ist gescheitert (oder wurde abgebrochen)
 */
function lauf(string $logDatei, string $pidDatei): array
{
    if (!is_file($logDatei)) {
        return ['state' => 'bereit', 'schritt' => '', 'log' => [], 'fehler' => ''];
    }

    $inhalt = (string)@file_get_contents($logDatei);
    $zeilen = preg_split('/\r\n|\r|\n/', $inhalt);
    $zeilen = array_values(array_filter($zeilen, function ($z) { return trim($z) !== ''; }));

    $schritt = '';
    $fehler  = '';
    $state   = 'laeuft';
    foreach ($zeilen as $z) {
        if (strncmp($z, '==> ', 4) === 0) {
            $schritt = substr($z, 4);
        } elseif (strncmp($z, '### FERTIG', 10) === 0) {
            $state = 'fertig';
            $schritt = 'Fertig';
        } elseif (strncmp($z, '### FEHLER', 10) === 0) {
            $state = 'fehler';
            $fehler = trim(substr($z, 10));
        }
    }

    // Kein Endezeichen und kein laufender Prozess: entweder wirklich
    // abgestuerzt – oder der Dienst wurde zwischendurch neu gestartet und
    // kennt die PID nicht mehr. Ein Protokoll, in das noch geschrieben wird,
    // gilt deshalb weiter als laufend (Build-Schritte koennen lange schweigen).
    if ($state === 'laeuft' && !laeuftUpdate($pidDatei)) {
        $frisch = is_file($logDatei) && (time() - (int)@filemtime($logDatei)) < 600;
        if (!$frisch) {
            $state = 'fehler';
            $fehler = $fehler ?: 'Das Update wurde unerwartet beendet.';
        }
    }

    // Protokoll begrenzen: die letzten Zeilen sind die interessanten.
    if (count($zeilen) > 400) {
        $zeilen = array_slice($zeilen, -400);
    }

    return ['state' => $state, 'schritt' => $schritt, 'log' => $zeilen, 'fehler' => $fehler];
}

/**
 * Laeuft die Anwendung mit einem aelteren Stand als dem im Projektordner?
 *
 * Der Vergleich Projektordner<->GitHub allein genuegt nicht: wer selbst
 * committet (oder von Hand gepullt hat, ohne neu zu bauen), hat einen
 * aktuellen Ordner und trotzdem einen veralteten Container. Deshalb den
 * Erstellungszeitpunkt des laufenden web-Containers gegen den Zeitpunkt des
 * aktuellen Commits halten.
 */
function containerVeraltet(string $repo): bool
{
    $projekt = trim((string)@shell_exec(
        'docker inspect "$(hostname)" --format \'{{index .Config.Labels "com.docker.compose.project"}}\' 2>/dev/null'
    ));
    if ($projekt === '') $projekt = 'elli';

    $id = trim((string)@shell_exec(
        'docker ps -q'
        . ' --filter ' . escapeshellarg('label=com.docker.compose.project=' . $projekt)
        . ' --filter ' . escapeshellarg('label=com.docker.compose.service=web')
        . ' 2>/dev/null'
    ));
    if ($id === '') return false;
    $id = strtok($id, "\n");

    $erstellt = trim((string)@shell_exec(
        'docker inspect ' . escapeshellarg($id) . ' --format \'{{.Created}}\' 2>/dev/null'
    ));
    $erstelltZeit = $erstellt !== '' ? strtotime($erstellt) : 0;
    if (!$erstelltZeit) return false;

    $commitZeit = '';
    git($repo, ['log', '-1', '--format=%ct', 'HEAD'], $commitZeit);
    $commitZeit = (int)trim($commitZeit);
    if (!$commitZeit) return false;

    return $erstelltZeit < $commitZeit;
}

/** Lokalen Stand mit GitHub und mit der laufenden Version vergleichen. */
function pruefen(string $repo): array
{
    $ergebnis = [
        'moeglich'         => false,
        'updateVerfuegbar' => false,
        'anzahl'           => 0,
        'commits'          => [],
        'lokal'            => '',
        'entfernt'         => '',
        'sauber'           => true,
        'nurNeuBauen'      => false,
        'meldung'          => '',
    ];

    if (!is_dir($repo . '/.git')) {
        $ergebnis['meldung'] = 'Diese Installation ist keine Git-Kopie – ein automatisches Update ist nicht moeglich.';
        return $ergebnis;
    }

    git($repo, ['config', '--global', '--replace-all', 'safe.directory', $repo]);

    $zweig = '';
    git($repo, ['rev-parse', '--abbrev-ref', 'HEAD'], $zweig);
    if ($zweig === '' || $zweig === 'HEAD') $zweig = 'main';

    // Nur versionierte Aenderungen zaehlen – unversionierte Dateien (.env,
    // backups/, Editor-Ordner) stehen einem Update nicht im Weg.
    $stand = '';
    if (git($repo, ['status', '--porcelain', '--untracked-files=no'], $stand) === 0) {
        $ergebnis['sauber'] = trim($stand) === '';
    }

    $netz = '';
    if (git($repo, ['fetch', '--prune', 'origin'], $netz) !== 0) {
        $ergebnis['meldung'] = 'GitHub ist gerade nicht erreichbar: ' . $netz;
        return $ergebnis;
    }

    $lokal = '';
    $entfernt = '';
    git($repo, ['rev-parse', '--short', 'HEAD'], $lokal);
    git($repo, ['rev-parse', '--short', 'origin/' . $zweig], $entfernt);

    $anzahl = '';
    git($repo, ['rev-list', '--count', 'HEAD..origin/' . $zweig], $anzahl);

    $liste = '';
    // %x09 = Tabulator (Git expandiert kein \t in Formatzeichenketten)
    git($repo, ['log', '--pretty=format:%h%x09%ad%x09%s', '--date=short', '-n', '30', 'HEAD..origin/' . $zweig], $liste);

    $commits = [];
    foreach (preg_split('/\r\n|\r|\n/', $liste) as $zeile) {
        if (trim($zeile) === '') continue;
        $teile = explode("\t", $zeile);
        if (count($teile) < 3) continue;
        $commits[] = ['hash' => $teile[0], 'date' => $teile[1], 'subject' => $teile[2]];
    }

    // Auch ohne neue Commits kann ein Update noetig sein: naemlich dann, wenn
    // die laufende Anwendung aelter ist als der Stand im Projektordner.
    $veraltet = containerVeraltet($repo);

    $ergebnis['moeglich']         = true;
    $ergebnis['lokal']            = $lokal;
    $ergebnis['entfernt']         = $entfernt;
    $ergebnis['anzahl']           = (int)$anzahl;
    $ergebnis['commits']          = $commits;
    $ergebnis['nurNeuBauen']      = (int)$anzahl === 0 && $veraltet;
    $ergebnis['updateVerfuegbar'] = (int)$anzahl > 0 || $veraltet;

    return $ergebnis;
}

$pfad   = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
$pfad   = rtrim((string)$pfad, '/');
$post   = $_SERVER['REQUEST_METHOD'] === 'POST';

switch ($pfad) {
    case '':
    case '/status':
        echo json_encode(['dienst' => 'elli-updater'] + lauf($LOG, $PID), JSON_UNESCAPED_UNICODE);
        break;

    case '/pruefen':
        echo json_encode(
            ['dienst' => 'elli-updater', 'pruefung' => pruefen($REPO)] + lauf($LOG, $PID),
            JSON_UNESCAPED_UNICODE
        );
        break;

    case '/update':
        if (!$post) {
            http_response_code(405);
            echo json_encode(['error' => 'POST erwartet']);
            break;
        }
        if (laeuftUpdate($PID)) {
            http_response_code(409);
            echo json_encode(['error' => 'Es laeuft bereits ein Update.']);
            break;
        }
        @unlink($LOG);
        @unlink($PID);
        // Mit einer Kopie arbeiten: das Update aktualisiert unter Umstaenden
        // update.sh selbst, und die Shell liest ihr Skript waehrend des Laufs
        // haeppchenweise nach – eine Aenderung mittendrin waere fatal.
        $laufSkript = $STATE . '/update-lauf.sh';
        if (!@copy($SKRIPT, $laufSkript)) {
            http_response_code(500);
            echo json_encode(['error' => 'Update-Skript nicht gefunden.']);
            break;
        }
        // Im Hintergrund starten und sofort antworten – der Fortschritt wird
        // ueber /status abgeholt. Ohne nohup/& wuerde die Anfrage minutenlang
        // haengen und der eingebaute PHP-Server nichts anderes mehr annehmen.
        // PID merken – daran erkennt /status, ob der Vorgang noch laeuft.
        $pid = trim((string)shell_exec(
            'nohup sh ' . escapeshellarg($laufSkript) . ' >> ' . escapeshellarg($LOG) . ' 2>&1 & echo $!'
        ));
        @file_put_contents($PID, $pid);
        usleep(300000); // kurz warten, damit /status direkt "laeuft" meldet
        echo json_encode(['gestartet' => true] + lauf($LOG, $PID), JSON_UNESCAPED_UNICODE);
        break;

    case '/quittieren':
        @unlink($LOG);
        @unlink($PID);
        echo json_encode(['ok' => true] + lauf($LOG, $PID), JSON_UNESCAPED_UNICODE);
        break;

    default:
        http_response_code(404);
        echo json_encode(['error' => 'Unbekannter Endpunkt: ' . $pfad]);
}
