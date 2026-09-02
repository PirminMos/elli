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

/** Laeuft gerade ein Update-Skript? */
function laeuftUpdate(): bool
{
    $treffer = trim((string)@shell_exec('pgrep -f "/app/update.sh" 2>/dev/null'));
    return $treffer !== '';
}

/**
 * Zustand aus dem Protokoll ableiten.
 * bereit  – kein Lauf bekannt
 * laeuft  – Skript arbeitet noch
 * fertig  – letzter Lauf war erfolgreich
 * fehler  – letzter Lauf ist gescheitert (oder wurde abgebrochen)
 */
function lauf(string $logDatei): array
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

    // Kein Endezeichen, aber auch kein laufender Prozess -> abgebrochen.
    if ($state === 'laeuft' && !laeuftUpdate()) {
        $state = 'fehler';
        $fehler = $fehler ?: 'Das Update wurde unerwartet beendet.';
    }

    // Protokoll begrenzen: die letzten Zeilen sind die interessanten.
    if (count($zeilen) > 400) {
        $zeilen = array_slice($zeilen, -400);
    }

    return ['state' => $state, 'schritt' => $schritt, 'log' => $zeilen, 'fehler' => $fehler];
}

/** Lokalen Stand mit GitHub vergleichen. */
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

    $stand = '';
    if (git($repo, ['status', '--porcelain'], $stand) === 0) {
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

    $ergebnis['moeglich']         = true;
    $ergebnis['lokal']            = $lokal;
    $ergebnis['entfernt']         = $entfernt;
    $ergebnis['anzahl']           = (int)$anzahl;
    $ergebnis['commits']          = $commits;
    $ergebnis['updateVerfuegbar'] = (int)$anzahl > 0;

    return $ergebnis;
}

$pfad   = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
$pfad   = rtrim((string)$pfad, '/');
$post   = $_SERVER['REQUEST_METHOD'] === 'POST';

switch ($pfad) {
    case '':
    case '/status':
        echo json_encode(['dienst' => 'elli-updater'] + lauf($LOG), JSON_UNESCAPED_UNICODE);
        break;

    case '/pruefen':
        echo json_encode(
            ['dienst' => 'elli-updater', 'pruefung' => pruefen($REPO)] + lauf($LOG),
            JSON_UNESCAPED_UNICODE
        );
        break;

    case '/update':
        if (!$post) {
            http_response_code(405);
            echo json_encode(['error' => 'POST erwartet']);
            break;
        }
        if (laeuftUpdate()) {
            http_response_code(409);
            echo json_encode(['error' => 'Es laeuft bereits ein Update.']);
            break;
        }
        @unlink($LOG);
        // Im Hintergrund starten und sofort antworten – der Fortschritt wird
        // ueber /status abgeholt. Ohne nohup/& wuerde die Anfrage minutenlang
        // haengen und der eingebaute PHP-Server nichts anderes mehr annehmen.
        exec('nohup sh ' . escapeshellarg($SKRIPT) . ' >> ' . escapeshellarg($LOG) . ' 2>&1 &');
        usleep(300000); // kurz warten, damit /status direkt "laeuft" meldet
        echo json_encode(['gestartet' => true] + lauf($LOG), JSON_UNESCAPED_UNICODE);
        break;

    case '/quittieren':
        @unlink($LOG);
        echo json_encode(['ok' => true] + lauf($LOG), JSON_UNESCAPED_UNICODE);
        break;

    default:
        http_response_code(404);
        echo json_encode(['error' => 'Unbekannter Endpunkt: ' . $pfad]);
}
