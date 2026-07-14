<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
require __DIR__ . '/vendor/autoload.php';

use PhpOffice\PhpWord\IOFactory as WordIO;
use PhpOffice\PhpSpreadsheet\IOFactory as ExcelIO;
use Smalot\PdfParser\Parser as PdfParser;

header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, GET, OPTIONS, DELETE");
header("Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With");
header("Content-Type: application/json; charset=UTF-8");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// DB-Zugang aus Umgebungsvariablen (Docker). Fallback = lokale Entwicklung.
$host     = getenv('DB_HOST')     ?: "localhost";
$db_name  = getenv('DB_NAME')     ?: "elli";
$username = getenv('DB_USER')     ?: "elli_user";
$password = getenv('DB_PASSWORD') ?: "1234";

try {
    $conn = new PDO("mysql:host=$host;dbname=$db_name;charset=utf8", $username, $password);
    $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch(PDOException $e) {
    die(json_encode(["error" => "Verbindung fehlgeschlagen"]));
}

$action = $_GET['action'] ?? '';

/**
 * Zentrale Konfliktprüfung für einen zu speichernden Termin.
 * Prüft gegen die Datenbank:
 *  - Raum-Öffnungszeiten (raum_verfuegbarkeit, falls nicht immer_verfuegbar)
 *  - Raum-Doppelbelegung (termin_raeume)
 *  - Doppelbelegung von Erst-/Zweitkräften (termin_verantwortliche)
 *  - Klassen-Doppelbelegung und Klassen-Zeitraster (klassen_zeitraster)
 *
 * $opts:
 *   'tag','start','ende'   Pflicht (Zeiten als HH:MM oder HH:MM:SS)
 *   'raum_ids'             array von Raum-IDs
 *   'kraefte'              array von ['id'=>int,'typ'=>'erst'|'zweit']
 *   'klassen_id'           int|null  -> Belegungs- + Rasterprüfung
 *   'exclude_termin_ids'   Termin-IDs, die ignoriert werden (werden ersetzt/gelöscht)
 *
 * @return string[] Konfliktmeldungen (leer = alles frei)
 */
function elli_finde_konflikte(PDO $conn, array $opts) {
    $konflikte = [];
    $tag   = $opts['tag'];
    $start = substr((string)$opts['start'], 0, 8);
    $ende  = substr((string)$opts['ende'], 0, 8);
    if (strlen($start) === 5) $start .= ':00';
    if (strlen($ende) === 5)  $ende  .= ':00';

    $excludes = array_values(array_filter(array_map('intval', $opts['exclude_termin_ids'] ?? [])));
    $exSql = '';
    if ($excludes) {
        $exSql = ' AND t.id NOT IN (' . implode(',', array_fill(0, count($excludes), '?')) . ')';
    }

    // 1. Räume: Öffnungszeiten + Doppelbelegung
    foreach (($opts['raum_ids'] ?? []) as $rid) {
        $rid = (int)$rid;
        if (!$rid) continue;

        $stmtR = $conn->prepare("SELECT name, immer_verfuegbar FROM raum WHERE id = ?");
        $stmtR->execute([$rid]);
        $raum = $stmtR->fetch(PDO::FETCH_ASSOC);
        if (!$raum) continue;

        // Regel: Ohne hinterlegte Zeitfenster gilt ein Raum als durchgängig verfügbar
        // (Mo–Fr). Nur wenn Fenster existieren, ist er ausschließlich in diesen frei.
        if ((int)$raum['immer_verfuegbar'] === 0) {
            $stmtAlle = $conn->prepare("SELECT tag, startzeit, endzeit FROM raum_verfuegbarkeit
                                        WHERE raum_id = ?
                                        ORDER BY FIELD(tag,'Montag','Dienstag','Mittwoch','Donnerstag','Freitag'), startzeit");
            $stmtAlle->execute([$rid]);
            $fenster = $stmtAlle->fetchAll(PDO::FETCH_ASSOC);

            if (!empty($fenster)) {
                $passt = false;
                foreach ($fenster as $f) {
                    if ($f['tag'] === $tag && $f['startzeit'] <= $start && $f['endzeit'] >= $ende) {
                        $passt = true;
                        break;
                    }
                }
                if (!$passt) {
                    $liste = implode(', ', array_map(function ($f) {
                        return $f['tag'] . ' ' . substr($f['startzeit'],0,5) . '–' . substr($f['endzeit'],0,5);
                    }, $fenster));
                    $konflikte[] = "🚫 {$raum['name']} ist $tag " . substr($start,0,5) . "–" . substr($ende,0,5) .
                        " nicht verfügbar. Verfügbar: $liste.";
                }
            }
            // keine Fenster hinterlegt -> Raum gilt als durchgängig verfügbar
        }

        $sql = "SELECT COALESCE(sf.name, a.name, 'Termin') AS bez, t.start, t.ende
                FROM termin_raeume tr
                JOIN termin t ON t.id = tr.termin_id
                LEFT JOIN schulfach sf ON sf.id = t.schulfach_id
                LEFT JOIN aktivitaet a ON a.id = t.aktivitaet_id
                WHERE tr.raum_id = ? AND t.tag = ? AND t.start < ? AND t.ende > ?" . $exSql . " LIMIT 1";
        $stmt = $conn->prepare($sql);
        $stmt->execute(array_merge([$rid, $tag, $ende, $start], $excludes));
        if ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $konflikte[] = "❌ Raum belegt: {$raum['name']} durch \"{$row['bez']}\" (" .
                substr($row['start'],0,5) . "–" . substr($row['ende'],0,5) . ")";
        }
    }

    // 2. Erst-/Zweitkräfte: Doppelbelegung
    foreach (($opts['kraefte'] ?? []) as $k) {
        $kid = (int)($k['id'] ?? 0);
        $ktyp = ($k['typ'] ?? 'erst') === 'zweit' ? 'zweit' : 'erst';
        if (!$kid) continue;

        $nameTable = $ktyp === 'zweit' ? 'zweitkraft' : 'erstkraft';
        $sql = "SELECT kr.name AS kraft_name, COALESCE(sf.name, a.name, 'Termin') AS bez, t.start, t.ende
                FROM termin_verantwortliche tv
                JOIN termin t ON t.id = tv.termin_id
                JOIN $nameTable kr ON kr.id = tv.kraft_id
                LEFT JOIN schulfach sf ON sf.id = t.schulfach_id
                LEFT JOIN aktivitaet a ON a.id = t.aktivitaet_id
                WHERE tv.kraft_id = ? AND tv.kraft_typ = ? AND t.tag = ? AND t.start < ? AND t.ende > ?" . $exSql . " LIMIT 1";
        $stmt = $conn->prepare($sql);
        $stmt->execute(array_merge([$kid, $ktyp, $tag, $ende, $start], $excludes));
        if ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $konflikte[] = "👤 {$row['kraft_name']} ist bereits verplant: \"{$row['bez']}\" ($tag " .
                substr($row['start'],0,5) . "–" . substr($row['ende'],0,5) . ")";
        }
    }

    // 3. Klasse: Doppelbelegung + Zeitraster
    $klassenId = (int)($opts['klassen_id'] ?? 0);
    if ($klassenId) {
        $stmtKN = $conn->prepare("SELECT name FROM klassen WHERE id = ?");
        $stmtKN->execute([$klassenId]);
        $klassenName = $stmtKN->fetchColumn() ?: ('#' . $klassenId);

        $sql = "SELECT COALESCE(sf.name, a.name, 'Termin') AS bez, t.start, t.ende
                FROM termin t
                LEFT JOIN schulfach sf ON sf.id = t.schulfach_id
                LEFT JOIN aktivitaet a ON a.id = t.aktivitaet_id
                WHERE t.klassen_id = ? AND t.tag = ? AND t.start < ? AND t.ende > ?" . $exSql . " LIMIT 1";
        $stmt = $conn->prepare($sql);
        $stmt->execute(array_merge([$klassenId, $tag, $ende, $start], $excludes));
        if ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $konflikte[] = "🏫 Klasse $klassenName ist bereits belegt: \"{$row['bez']}\" ($tag " .
                substr($row['start'],0,5) . "–" . substr($row['ende'],0,5) . ")";
        }

        // Zeitraster: der Termin muss von den (überlappenden) Rasterstunden der Klasse
        // abgedeckt sein (gleiche Semantik wie isKlasseVerfuegbar im Frontend).
        $stmtZ = $conn->prepare("SELECT startzeit, endzeit FROM klassen_zeitraster
                                 WHERE klasse_id = ? AND startzeit < ? AND endzeit > ?");
        $stmtZ->execute([$klassenId, $ende, $start]);
        $slots = $stmtZ->fetchAll(PDO::FETCH_ASSOC);
        if (empty($slots)) {
            // Nur bemängeln, wenn die Klasse überhaupt ein Raster gepflegt hat
            $stmtHat = $conn->prepare("SELECT COUNT(*) FROM klassen_zeitraster WHERE klasse_id = ?");
            $stmtHat->execute([$klassenId]);
            if ((int)$stmtHat->fetchColumn() > 0) {
                $konflikte[] = "⏰ Zeitraum passt in keine Rasterstunde der Klasse $klassenName ($tag " .
                    substr($start,0,5) . "–" . substr($ende,0,5) . ")";
            }
        } else {
            $minStart = min(array_column($slots, 'startzeit'));
            $maxEnde  = max(array_column($slots, 'endzeit'));
            if ($minStart > $start || $maxEnde < $ende) {
                $konflikte[] = "⏰ Zeitraum überschreitet das Zeitraster der Klasse $klassenName (Raster: " .
                    substr($minStart,0,5) . "–" . substr($maxEnde,0,5) . ")";
            }
        }
    }

    return $konflikte;
}

// --- SCHULJAHRE LADEN ---
if ($action === 'get_schuljahre') {
    try {
        $stmt = $conn->query("SELECT id, schuljahr, adresse FROM schule ORDER BY id DESC");
        echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
    } catch (PDOException $e) {
        echo json_encode(["error" => $e->getMessage()]);
    }
    exit;
}

// --- NEUES SCHULJAHR ANLEGEN (Wird vom "+" Button aufgerufen) ---
if ($action === 'add_schuljahr') {
    $data = json_decode(file_get_contents('php://input'), true);
    $schuljahr = $data['schuljahr'] ?? '';
    // Adresse als JSON-String speichern
    $adresse = json_encode($data['adresse'] ?? ['name' => '', 'strasse' => '', 'stadt' => '']);

    try {
        // Die ID lassen wir weg, MariaDB füllt sie automatisch aus
        $stmt = $conn->prepare("INSERT INTO schule (schuljahr, adresse) VALUES (?, ?)");
        $stmt->execute([$schuljahr, $adresse]);

        echo json_encode(["success" => true, "id" => $conn->lastInsertId()]);
    } catch (PDOException $e) {
        // Falls es knallt, geben wir eine saubere Fehlermeldung
        header('Content-Type: application/json', true, 500);
        echo json_encode(["success" => false, "error" => $e->getMessage()]);
    }
    exit;
}

// --- EINSTELLUNGEN LADEN ---
if ($action === 'get_settings') {
    try {
        $stmt = $conn->prepare("SELECT wert FROM einstellungen WHERE schluessel = 'nutzername'");
        $stmt->execute();
        $res = $stmt->fetch(PDO::FETCH_ASSOC);
        echo json_encode(['nutzername' => $res['wert'] ?? 'Nutzer']);
    } catch (PDOException $e) {
        echo json_encode(['error' => $e->getMessage()]);
    }
    exit;
}

// --- EINSTELLUNG SPEICHERN ---
if ($action === 'save_setting') {
    $data = json_decode(file_get_contents('php://input'), true);

    if (!isset($data['schluessel']) || !isset($data['wert'])) {
        echo json_encode(['success' => false, 'error' => 'Daten unvollständig']);
        exit;
    }

    try {
        // "INSERT ... ON DUPLICATE KEY UPDATE" erledigt alles in einem Schritt
        $sql = "INSERT INTO einstellungen (schluessel, wert)
                VALUES (:schluessel, :wert)
                ON DUPLICATE KEY UPDATE wert = :wert_update";

        $stmt = $conn->prepare($sql);
        $stmt->execute([
            ':schluessel'   => $data['schluessel'],
            ':wert'         => $data['wert'],
            ':wert_update'  => $data['wert'] // Der Wert für den Fall, dass es schon existiert
        ]);

        echo json_encode(['success' => true]);
    } catch (PDOException $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

// --- ADRESSE SEPARAT SPEICHERN (Für Auto-Save) ---
if ($action === 'save_address') {
    $data = json_decode(file_get_contents("php://input"), true);
    $sid = $data['schuljahr_id'] ?? null;

    if ($sid && isset($data['adresse'])) {
        try {
            $stmt = $conn->prepare("UPDATE schule SET adresse = :adr WHERE id = :id");
            $stmt->execute([
                ':adr' => json_encode($data['adresse']), // Speichert als JSON-String
                ':id'  => $sid
            ]);
            echo json_encode(['success' => true]);
        } catch (PDOException $e) {
            echo json_encode(['error' => $e->getMessage()]);
        }
    } else {
        echo json_encode(['error' => 'Fehlende Daten: id=' . $sid]);
    }
    exit;
}

if ($action === 'get_address') {
    // 1. Parameter auslesen und validieren
        $schuljahr_id = isset($_GET['schuljahr_id']) ? (int)$_GET['schuljahr_id'] : 0;

        if ($schuljahr_id <= 0) {
            http_response_code(400);
            echo json_encode(["success" => false, "error" => "Ungültige oder fehlende schuljahr_id"]);
            exit;
        }

        try {
            // 2. SQL Query vorbereiten (Prepared Statement)
            $stmt = $conn->prepare("SELECT id, schuljahr, adresse FROM schule WHERE id = :id LIMIT 1");
            $stmt->bindParam(':id', $schuljahr_id, PDO::PARAM_INT);
            $stmt->execute();

            $result = $stmt->fetch(PDO::FETCH_ASSOC);

            // 3. Ergebnis prüfen und ausgeben
            if ($result) {
                echo json_encode([
                    "success" => true,
                    "data" => $result
                ]);
            } else {
                http_response_code(404);
                echo json_encode([
                    "success" => false,
                    "error" => "Keine Daten für diese ID gefunden"
                ]);
            }
        } catch (PDOException $e) {
            http_response_code(500);
            echo json_encode([
                "success" => false,
                "error" => "Datenbankfehler: " . $e->getMessage()
            ]);
        }
}

// 2. EDITOR-DATEN LADEN (Listen für die Dropdowns)
if ($action === 'load_editor_data') {
    try {
        // Wir erwarten die ID aus der Tabelle 'schule'
        $sid = $_GET['schuljahr_id'] ?? null;

        if (!$sid) {
                echo json_encode(["error" => "Keine Schuljahr-ID geliefert"]);
                exit;
            }

        $res = [];

        // Erstkräfte des gewählten Jahres
        $stmt = $conn->prepare("SELECT * FROM erstkraft WHERE schuljahr_id = :sid ORDER BY name ASC");
        $stmt->execute([':sid' => $sid]);
        $res['erstkraefte'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Zweitkräfte des gewählten Jahres
        $stmt = $conn->prepare("SELECT * FROM zweitkraft WHERE schuljahr_id = :sid ORDER BY name ASC");
                $stmt->execute([':sid' => $sid]);
                $zweitkraefte = $stmt->fetchAll(PDO::FETCH_ASSOC);

                foreach ($zweitkraefte as &$zk) {
                    $stmtM = $conn->prepare("
                        SELECT zst.id, zst.aktivitaet_id, a.name AS aktivitaet_name,
                               zst.einsatzort, zst.soll_stunden, zst.besetzung_typ
                        FROM zweitkraft_stundentafel AS zst
                        LEFT JOIN aktivitaet AS a ON a.id = zst.aktivitaet_id
                        WHERE zst.zweitkraft_id = ?
                    ");
                    $stmtM->execute([$zk['id']]);

                    $zk['pflichtstunden_masse'] = $stmtM->fetchAll(PDO::FETCH_ASSOC);
                }
                $res['zweitkraefte'] = $zweitkraefte;

        // Räume des gewählten Jahres
        $stmt = $conn->prepare("SELECT id, name, unterrichtsfach, immer_verfuegbar FROM raum WHERE schuljahr_id = :sid ORDER BY name ASC");
                $stmt->execute([':sid' => $sid]);
                $raeume = $stmt->fetchAll(PDO::FETCH_ASSOC);

                foreach ($raeume as &$raum) {
                    // Typ-Casting für JS-Sicherheit
                    $raum['id'] = (int)$raum['id'];
                    $raum['immer_verfuegbar'] = (bool)$raum['immer_verfuegbar'];

                    // Verfügbarkeiten laden
                    $vStmt = $conn->prepare("SELECT tag, startzeit, endzeit FROM raum_verfuegbarkeit WHERE raum_id = ?");
                    $vStmt->execute([$raum['id']]);
                    $raum['verfuegbarkeiten'] = $vStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
                }
                unset($raum); // Referenz sicherheitshalber löschen
                $res['raeume'] = $raeume;

        $stmt = $conn->prepare("SELECT * FROM aktivitaet WHERE schuljahr_id = :sid ORDER BY name ASC");
        $stmt->execute([':sid' => $sid]);
        $res['aktivitaeten'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $stmt = $conn->prepare("SELECT * FROM schulfach WHERE schuljahr_id = :sid ORDER BY name ASC");
        $stmt->execute([':sid' => $sid]);
        $res['faecher'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

        echo json_encode($res);

    } catch (PDOException $e) {
        header('Content-Type: application/json', true, 500);
        echo json_encode(["error" => $e->getMessage()]);
    }
    exit;
}

if ($action === 'load_schuelerstundenplaene') {
    $schuljahrId = $_GET['schuljahr_id'] ?? null;

    if (!$schuljahrId) {
        echo json_encode(["success" => false, "error" => "Schuljahr-ID fehlt"]);
        exit;
    }

    try {
        // Wir holen ID und Name aus der Tabelle 'klassen'
        // 'termin_anzahl' ist ein nettes Extra für die Liste
        $stmt = $conn->prepare("
            SELECT id, name,
            (SELECT COUNT(*) FROM termin WHERE klassen_id = klassen.id) as termin_anzahl
            FROM klassen
            WHERE schuljahr_id = ?
            ORDER BY name ASC
        ");
        $stmt->execute([$schuljahrId]);
        $plaene = $stmt->fetchAll(PDO::FETCH_ASSOC);

        echo json_encode([
            "success" => true,
            "plaene" => $plaene
        ]);
    } catch (Exception $e) {
        echo json_encode(["success" => false, "error" => $e->getMessage()]);
    }
    exit;
}

if ($action === 'load_activities') {
    $sid = $_GET['schuljahr_id'] ?? null;

    // 1. Alle Aktivitäten des Schuljahres holen
    $stmt = $conn->prepare("SELECT id, name, typ FROM aktivitaet WHERE schuljahr_id = :sid");
    $stmt->execute([':sid' => $sid]);
    $activities = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($activities as &$act) {
        // 2. Termine für diese Aktivität holen
        // Wir nutzen wieder GROUP_CONCAT, um alle Räume eines Termins in einer Zeile zu haben
        $stmtT = $conn->prepare("
            SELECT
                t.id,
                t.tag,
                t.start AS uhrzeit,
                t.ende AS endzeit,
                GROUP_CONCAT(r.name SEPARATOR ', ') as raum_name,
                GROUP_CONCAT(r.id SEPARATOR ',') as raeume
            FROM termin t
            LEFT JOIN termin_raeume tr ON t.id = tr.termin_id
            LEFT JOIN raum r           ON tr.raum_id = r.id
            WHERE t.aktivitaet_id = ?
            GROUP BY t.id
        ");
        $stmtT->execute([$act['id']]);
        $act['termine'] = $stmtT->fetchAll(PDO::FETCH_ASSOC);

        foreach ($act['termine'] as &$term) {
            // IDs für das Frontend wieder in ein echtes Array umwandeln
            $term['raeume'] = $term['raeume'] ? explode(',', $term['raeume']) : [];

            // 3. Verantwortliche für jeden Termin holen (unverändert)
            $stmtV = $conn->prepare("
                SELECT
                    CASE WHEN tv.kraft_typ = 'erst' THEN e.name ELSE z.name END as name,
                    tv.kraft_typ
                FROM termin_verantwortliche tv
                LEFT JOIN erstkraft e ON tv.kraft_id = e.id AND tv.kraft_typ = 'erst'
                LEFT JOIN zweitkraft z ON tv.kraft_id = z.id AND tv.kraft_typ = 'zweit'
                WHERE tv.termin_id = ?
            ");
            $stmtV->execute([$term['id']]);
            $term['verantwortliche'] = $stmtV->fetchAll(PDO::FETCH_ASSOC);
        }
    }
    echo json_encode($activities);
    exit;
}

if ($action === 'get_activity_details') {
    $id = $_GET['id'] ?? null;
    if (!$id) {
        echo json_encode(["success" => false, "error" => "Keine ID angegeben"]);
        exit;
    }

    try {
        // 1. Aktivität holen
        $stmt = $conn->prepare("SELECT * FROM aktivitaet WHERE id = ?");
        $stmt->execute([$id]);
        $activity = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$activity) {
            echo json_encode(["success" => false, "error" => "Aktivität nicht gefunden"]);
            exit;
        }

        // 2. Termine für diese Aktivität holen (mit Raum-Logik)
        $stmtT = $conn->prepare("
                     SELECT t.id, t.tag, t.start, t.ende, t.klassen_id, t.schulfach_id, t.stunden_id,
                            GROUP_CONCAT(DISTINCT tr.raum_id SEPARATOR ',') as raum_ids_string
                     FROM termin t
                     LEFT JOIN termin_raeume tr ON t.id = tr.termin_id
                     WHERE t.aktivitaet_id = ?
                     GROUP BY t.id
                 ");
        $stmtT->execute([$id]);
        $termine = $stmtT->fetchAll(PDO::FETCH_ASSOC);

        foreach ($termine as &$term) {
            // IDs in Integer umwandeln für JS-Kompatibilität
            $term['id'] = (int)$term['id'];

            // 3. Verantwortliche für EXAKT diesen Termin holen
            // Hier lag oft der Fehler: Wir brauchen die Verknüpfungstabelle
            $stmtV = $conn->prepare("
                SELECT kraft_id AS id, kraft_typ AS type
                FROM termin_verantwortliche
                WHERE termin_id = ?
            ");
            $stmtV->execute([$term['id']]);
            $verant = $stmtV->fetchAll(PDO::FETCH_ASSOC);

            // WICHTIG: Wir weisen es dem Feld 'verantwortliche' zu
            // Das Frontend erwartet hier ein Array von Objekten {id, type}
            $term['verantwortliche'] = array_map(function($v) {
                return [
                    'id' => (int)$v['id'],
                    'type' => $v['type']
                ];
            }, $verant);

            // 4. Räume aufbereiten
            if (!empty($term['raum_ids_string'])) {
                $term['raeume'] = array_map('intval', explode(',', $term['raum_ids_string']));
            } else {
                $term['raeume'] = [];
            }

            // Unnötige Felder entfernen, um das Frontend-Modell sauber zu halten
            unset($term['raum_ids_string']);
        }

        $activity['termine'] = $termine;

        echo json_encode([
            "success" => true,
            "data" => $activity
        ]);

    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(["success" => false, "error" => $e->getMessage()]);
    }
    exit;
}

if ($action === 'get_raum_details') {
    $raum_id = $_GET['id'];

    // 1. Raum-Stammdaten (zur Sicherheit nochmal aktuell laden)
    $stmt1 = $conn->prepare("SELECT * FROM raum WHERE id = ?");
    $stmt1->execute([$raum_id]);
    $raum = $stmt1->fetch(PDO::FETCH_ASSOC);

    // 2. Verfügbarkeiten/Sperrzeiten laden
    $stmt2 = $conn->prepare("SELECT * FROM raum_verfuegbarkeit WHERE raum_id = ?");
    $stmt2->execute([$raum_id]);
    $verfuegbarkeit = $stmt2->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode([
        "raum" => $raum,
        "verfuegbarkeit" => $verfuegbarkeit
    ]);
}

if ($action === 'load_raeume') {
    $schuljahr_id = intval($_GET['schuljahr_id'] ?? 0);

    if ($schuljahr_id === 0) {
        echo json_encode(["error" => "Keine gültige Schuljahr-ID"]);
        exit;
    }

    try {
        // 1. Basis-Daten holen
        $stmt = $conn->prepare("SELECT id, name, unterrichtsfach, immer_verfuegbar FROM raum WHERE schuljahr_id = ? ORDER BY name ASC");
        $stmt->execute([$schuljahr_id]);
        $raeume = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // 2. Verfügbarkeiten pro Raum zuordnen
        foreach ($raeume as &$raum) {
            // IDs und Booleans explizit konvertieren für JS-Typsicherheit
            $raum['id'] = (int)$raum['id'];
            $raum['immer_verfuegbar'] = (bool)$raum['immer_verfuegbar'];

            $vStmt = $conn->prepare("SELECT tag, startzeit, endzeit FROM raum_verfuegbarkeit WHERE raum_id = ?");
            $vStmt->execute([$raum['id']]);

            // fetchAll liefert ein Array von Objekten [{tag: 'Montag', ...}, ...]
            $raum['verfuegbarkeiten'] = $vStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        }

        header('Content-Type: application/json'); // Wichtig für den Browser!
        echo json_encode($raeume);
    } catch (PDOException $e) {
        http_response_code(500);
        echo json_encode(["error" => $e->getMessage()]);
    }
    exit;
}

if ($action === 'load_faecher') {
    $schuljahr_id = $_GET['schuljahr_id'];
    // Durch SELECT * wird die neue Spalte 'farbe' automatisch mitgeladen
    $stmt = $conn->prepare("SELECT * FROM schulfach WHERE schuljahr_id = ?");
    $stmt->execute([$schuljahr_id]);
    echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
    exit;
}

if ($action === 'save_fach') {
    $data = json_decode(file_get_contents('php://input'), true);

    // Wir extrahieren die Farbe aus dem JSON-Body des Frontends
    $farbe = isset($data['farbe']) ? $data['farbe'] : null;
    $name = trim($data['name'] ?? '');
    if (empty($name)) {
            // Wenn kein Name da ist, brechen wir ab und senden einen Fehler,
            // anstatt eine leere Zeile (ID 60) zu erzeugen.
            echo json_encode(["success" => false, "error" => "Schulfach ohne Namen kann nicht gespeichert werden."]);
            exit;
        }

    if (isset($data['id']) && $data['id']) {
        // UPDATE: Das Feld 'farbe' wird hinzugefügt
        $stmt = $conn->prepare("UPDATE schulfach SET name = ?, benoetigte_raeume = ?, farbe = ? WHERE id = ?");
        $stmt->execute([$data['name'], $data['benoetigte_raeume'], $farbe, $data['id']]);
        $id = $data['id'];
    } else {
        // INSERT: Das Feld 'farbe' wird beim Neuanlegen mitgespeichert
        $stmt = $conn->prepare("INSERT INTO schulfach (schuljahr_id, name, benoetigte_raeume, farbe) VALUES (?, ?, ?, ?)");
        $stmt->execute([$data['schuljahr_id'], $data['name'], $data['benoetigte_raeume'], $farbe]);
        $id = $conn->lastInsertId();
    }
    echo json_encode(["success" => true, "id" => $id]);
    exit;
}

// --- RAUM SPEICHERN (Inkl. Verfügbarkeiten) ---
if ($action === 'save_raum') {
    $data = json_decode(file_get_contents('php://input'), true);

    if (!$data) {
        echo json_encode(["error" => "Keine Daten empfangen"]);
        exit;
    }

    try {
        $conn->beginTransaction();

        // 1. Hauptdaten des Raums (Tabelle: raum)
        if (!empty($data['id'])) {
            // UPDATE: Bestehenden Raum aktualisieren
            $stmt = $conn->prepare("UPDATE raum SET name = ?, unterrichtsfach = ?, immer_verfuegbar = ? WHERE id = ?");
            $stmt->execute([
                $data['name'],
                $data['unterrichtsfach'],
                $data['immer_verfuegbar'] ? 1 : 0,
                $data['id']
            ]);
            $raum_id = $data['id'];
        } else {
            // INSERT: Neuen Raum anlegen
            $stmt = $conn->prepare("INSERT INTO raum (schuljahr_id, name, unterrichtsfach, immer_verfuegbar) VALUES (?, ?, ?, ?)");
            $stmt->execute([
                $data['schuljahr_id'],
                $data['name'],
                $data['unterrichtsfach'],
                $data['immer_verfuegbar'] ? 1 : 0
            ]);
            $raum_id = $conn->lastInsertId();
        }

        // 2. Verfügbarkeiten verarbeiten (Tabelle: raum_verfuegbarkeit)

        // Wir löschen zuerst IMMER die alten Einträge für diesen Raum
        $delStmt = $conn->prepare("DELETE FROM raum_verfuegbarkeit WHERE raum_id = ?");
        $delStmt->execute([$raum_id]);

        // Nur wenn der Raum NICHT "immer verfügbar" ist, speichern wir die spezifischen Zeitfenster
        if (!$data['immer_verfuegbar']) {
            if (!empty($data['verfuegbarkeiten']) && is_array($data['verfuegbarkeiten'])) {
                $insVStmt = $conn->prepare("INSERT INTO raum_verfuegbarkeit (raum_id, tag, startzeit, endzeit) VALUES (?, ?, ?, ?)");
                foreach ($data['verfuegbarkeiten'] as $v) {
                    // Nur speichern, wenn ein Tag gewählt wurde
                    if (!empty($v['tag'])) {
                        $insVStmt->execute([
                            $raum_id,
                            $v['tag'],
                            $v['startzeit'] ?: null,
                            $v['endzeit'] ?: null
                        ]);
                    }
                }
            }
        }

        $conn->commit();
        echo json_encode(["success" => true, "id" => $raum_id]);

    } catch (PDOException $e) {
        $conn->rollBack();
        echo json_encode(["error" => "Datenbankfehler: " . $e->getMessage()]);
    }
}

if ($action === 'save_activity') {
    $json = file_get_contents('php://input');
    $data = json_decode($json, true);

    if (!$data) {
        echo json_encode(["success" => false, "error" => "Leere Daten empfangen"]);
        exit;
    }

    $sid = $data['schuljahr_id'] ?? null;
    $aktId = $data['id'] ?? null;
    $einsatzort = $data['einsatzort'] ?? null;

    try {
        $conn->beginTransaction();
        $konflikte = [];

        // --- 1. VALIDIERUNG ---
        if (!empty($data['termine'])) {
            foreach ($data['termine'] as $t) {
                $tag = $t['tag'];
                $start = $t['uhrzeit'] ?? $t['start'] ?? null;
                $ende = $t['endzeit'] ?? $t['ende'] ?? null;
                $raeumeInput = $t['raeume'] ?? [];
                $verantwortlicheInput = $t['verantwortliche'] ?? [];

                if (!$start) continue;
                if (!$ende) {
                    $ende = date('H:i:s', strtotime($start) + 2700);
                }

                foreach ($raeumeInput as $raumId) {
                    if (!$raumId) continue;

                    // Raum-Verfügbarkeit prüfen
                    $stmtR = $conn->prepare("SELECT name, immer_verfuegbar FROM raum WHERE id = ?");
                    $stmtR->execute([$raumId]);
                    $raumBasis = $stmtR->fetch();

                    // Regel: Ohne hinterlegte Zeitfenster gilt ein Raum als durchgängig
                    // verfügbar; nur wenn Fenster existieren, ist er nur in diesen frei.
                    if ($raumBasis && (int)$raumBasis['immer_verfuegbar'] === 0) {
                        $stmtAlleF = $conn->prepare("SELECT tag, startzeit, endzeit FROM raum_verfuegbarkeit
                                                     WHERE raum_id = ?
                                                     ORDER BY FIELD(tag,'Montag','Dienstag','Mittwoch','Donnerstag','Freitag'), startzeit");
                        $stmtAlleF->execute([$raumId]);
                        $fensterAkt = $stmtAlleF->fetchAll(PDO::FETCH_ASSOC);

                        if (!empty($fensterAkt)) {
                            $passtAkt = false;
                            foreach ($fensterAkt as $f) {
                                if ($f['tag'] === $tag && $f['startzeit'] <= $start && $f['endzeit'] >= $ende) {
                                    $passtAkt = true;
                                    break;
                                }
                            }
                            if (!$passtAkt) {
                                $liste = implode(', ', array_map(function ($f) {
                                    return $f['tag'] . ' ' . substr($f['startzeit'],0,5) . '–' . substr($f['endzeit'],0,5);
                                }, $fensterAkt));
                                $konflikte[] = "🚫 " . $raumBasis['name'] . " ist $tag nicht verfügbar. Verfügbar: $liste.";
                            }
                        }
                    }

                    // Raum-Belegung prüfen
                    $sqlRaum = "SELECT r.name, COALESCE(a.name, 'Unterricht') as akt_name
                                FROM termin_raeume tr
                                JOIN raum r ON tr.raum_id = r.id
                                JOIN termin t ON tr.termin_id = t.id
                                LEFT JOIN aktivitaet a ON t.aktivitaet_id = a.id
                                WHERE t.tag = ? AND tr.raum_id = ? AND (t.start < ? AND t.ende > ?)";
                    $paramsRaum = [$tag, $raumId, $ende, $start];
                    if ($aktId) { $sqlRaum .= " AND (t.aktivitaet_id IS NULL OR t.aktivitaet_id != ?)"; $paramsRaum[] = $aktId; }
                    $stmt = $conn->prepare($sqlRaum);
                    $stmt->execute($paramsRaum);
                    if ($row = $stmt->fetch()) {
                        $konflikte[] = "❌ Raum belegt: " . $row['name'] . " durch '" . $row['akt_name'] . "'";
                    }
                }

                // --- B) LEHRKRÄFTE-VERFÜGBARKEIT PRÜFEN ---
                foreach ($verantwortlicheInput as $v) {
                    $kid = null;
                    $ktyp = 'erst';

                    // ID und Typ extrahieren (analog zu deiner Speicherlogik)
                    if (is_array($v)) {
                        $kid = $v['id'];
                        $ktyp = $v['type'] ?? 'erst';
                    } else {
                        $parts = explode('-', $v);
                        $kid = $parts[1] ?? null;
                        $ktyp = (strpos($v, 'e-') === 0) ? 'erst' : 'zweit';
                    }

                    if (!$kid) continue;

                    // SQL zur Prüfung von Terminüberschneidungen für diese Kraft
                    $sqlKraft = "
                        SELECT t.tag, t.start, t.ende, COALESCE(a.name, 'Unterricht') as akt_name,
                               (CASE WHEN ? = 'erst' THEN e.name ELSE z.name END) as lehrer_name
                        FROM termin_verantwortliche tv
                        JOIN termin t ON tv.termin_id = t.id
                        LEFT JOIN aktivitaet a ON t.aktivitaet_id = a.id
                        LEFT JOIN erstkraft e ON (tv.kraft_id = e.id AND tv.kraft_typ = 'erst')
                        LEFT JOIN zweitkraft z ON (tv.kraft_id = z.id AND tv.kraft_typ = 'zweit')
                        WHERE tv.kraft_id = ?
                          AND tv.kraft_typ = ?
                          AND t.tag = ?
                          AND (t.start < ? AND t.ende > ?)
                    "; // [cite: 126, 128, 135, 138, 140, 145, 147, 149, 151]

                    $paramsKraft = [$ktyp, $kid, $ktyp, $tag, $ende, $start];

                    // Falls wir editieren: eigenen Termin ignorieren
                    if ($aktId) {
                        $sqlKraft .= " AND (t.aktivitaet_id IS NULL OR t.aktivitaet_id != ?)";
                        $paramsKraft[] = $aktId;
                    }

                    $stmtK = $conn->prepare($sqlKraft);
                    $stmtK->execute($paramsKraft);

                    if ($rowK = $stmtK->fetch()) {
                        $konflikte[] = sprintf(
                            "👤 %s ist bereits verplant: '%s' am %s von %s bis %s",
                            $rowK['lehrer_name'],
                            $rowK['akt_name'],
                            $tag,
                            date('H:i', strtotime($rowK['start'])),
                            date('H:i', strtotime($rowK['ende']))
                        );
                    }
                }
            }
        }

        if (!empty($konflikte)) {
            $conn->rollBack();
            echo json_encode(['success' => false, 'error' => implode("\n", array_unique($konflikte))]);
            exit;
        }

        // --- 2. SPEICHERN ---
        if ($aktId) {
            $conn->prepare("UPDATE aktivitaet SET typ = ?, name = ?, einsatzort = ? WHERE id = ?")
                             ->execute([$data['typ'] ?? 'AG', $data['name'] ?? 'Unbenannt', $einsatzort, $aktId]);
            // Clean Slate
            $conn->prepare("DELETE FROM termin_raeume WHERE termin_id IN (SELECT id FROM termin WHERE aktivitaet_id = ?)")->execute([$aktId]);
            $conn->prepare("DELETE FROM termin_verantwortliche WHERE termin_id IN (SELECT id FROM termin WHERE aktivitaet_id = ?)")->execute([$aktId]);
            $conn->prepare("DELETE FROM termin WHERE aktivitaet_id = ?")->execute([$aktId]);
            $aktivitaetId = $aktId;
        } else {
            $stmtAct = $conn->prepare("INSERT INTO aktivitaet (schuljahr_id, typ, name, einsatzort) VALUES (?, ?, ?, ?)");
            $stmtAct->execute([$sid, $data['typ'] ?? 'AG', $data['name'] ?? 'Unbenannt', $einsatzort]);
            $aktivitaetId = $conn->lastInsertId();
        }

        if (!empty($data['termine'])) {
            foreach ($data['termine'] as $t) {
                $start = $t['uhrzeit'] ?? $t['start'] ?? null;
                if (!$start) continue;
                $ende = $t['endzeit'] ?? $t['ende'] ?? date('H:i:s', strtotime($start) + 2700);

                $stmtT = $conn->prepare("INSERT INTO termin (aktivitaet_id, tag, start, ende, stunden_id, is_differenzierung) VALUES (?, ?, ?, ?, ?, ?)");
                $stmtT->execute([$aktivitaetId, $t['tag'], $start, $ende, $t['stunden_id'] ?? null, $t['is_differenzierung'] ?? 0]);
                $terminId = $conn->lastInsertId();

                // A) Verantwortliche speichern (NUR DIESER EINE BLOCK!)
                if (!empty($t['verantwortliche']) && is_array($t['verantwortliche'])) {
                    $stmtV = $conn->prepare("INSERT INTO termin_verantwortliche (termin_id, kraft_id, kraft_typ) VALUES (?, ?, ?)");
                    $bereitsGespeichert = [];

                    foreach ($t['verantwortliche'] as $v) {
                        $kid = null; $ktyp = 'erst';
                        if (is_array($v)) {
                            $kid = $v['id']; $ktyp = $v['type'] ?? 'erst';
                        } else {
                            $parts = explode('-', $v);
                            $kid = $parts[1] ?? null;
                            $ktyp = (strpos($v, 'e-') === 0) ? 'erst' : 'zweit';
                        }

                        if ($kid) {
                            $key = $kid . '-' . $ktyp;
                            if (!in_array($key, $bereitsGespeichert)) {
                                $stmtV->execute([$terminId, (int)$kid, $ktyp]);
                                $bereitsGespeichert[] = $key;
                            }
                        }
                    }
                }

                // B) Räume speichern
                if (!empty($t['raeume'])) {
                    $stmtTR = $conn->prepare("INSERT INTO termin_raeume (termin_id, raum_id) VALUES (?, ?)");
                    foreach ($t['raeume'] as $rid) {
                        if ($rid) $stmtTR->execute([$terminId, $rid]);
                    }
                }
            }
        }

        $conn->commit();
        echo json_encode(['success' => true]);

    } catch (Exception $e) {
        if (isset($conn) && $conn->inTransaction()) $conn->rollBack();
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

// --- ERSTKRAFT SPEICHERN ---
if ($action === 'save_erstkraft') {
    $data = json_decode(file_get_contents('php://input'), true);

    try {
        if (isset($data['id']) && $data['id']) {
            // Update bestehend
            $stmt = $conn->prepare("UPDATE erstkraft SET
                name = ?, titel = ?, kuerzel = ?,
                farbe = ?, textfarbe = ?,
                pflichtstunden = ?, ermaessigung = ?, upz = ?, faecher = ?
                WHERE id = ?");

            $stmt->execute([
                $data['name'], $data['titel'], $data['kuerzel'],
                $data['farbe'], $data['textfarbe'],
                $data['pflichtstunden'], $data['ermaessigung'], $data['upz'], $data['faecher'],
                $data['id']
            ]);
            $id = $data['id'];
        } else {
            // Neu anlegen - HIER textfarbe ergänzt:
            $stmt = $conn->prepare("INSERT INTO erstkraft
                (schuljahr_id, name, titel, kuerzel, farbe, textfarbe, pflichtstunden, ermaessigung, upz, faecher)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt->execute([
                $data['schuljahr_id'], $data['name'], $data['titel'], $data['kuerzel'],
                $data['farbe'], $data['textfarbe'] ?? '#ffffff', // Fallback auf Weiß, falls nicht gesetzt
                $data['pflichtstunden'], $data['ermaessigung'], $data['upz'], $data['faecher']
            ]);
            $id = $conn->lastInsertId();
        }
        echo json_encode(['success' => true, 'id' => $id]);
    } catch (PDOException $e) {
        header('Content-Type: application/json', true, 500);
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

if ($action === 'delete_element') {
    $id = $_GET['id'] ?? null;
    $type = $_GET['type'] ?? '';

    // Mapping von Frontend-Kategorien auf echte Datenbank-Tabellen
    $mapping = [
        'aktivitaet' => 'aktivitaet',
        'raum'       => 'raum',
        'erstkraft'  => 'erstkraft',
        'zweitkraft' => 'zweitkraft',
        'schulfach'  => 'schulfach',
        'schuelerstundenplan' => 'klassen' // WICHTIG!
    ];

    if ($id && isset($mapping[$type])) {
        $table = $mapping[$type];
        try {
            $conn->beginTransaction();

            if ($type === 'schuelerstundenplan') {
                // 1. Abhängigkeiten in termin_verantwortliche löschen
                $stmt = $conn->prepare("DELETE FROM termin_verantwortliche WHERE termin_id IN (SELECT id FROM termin WHERE klassen_id = ?)");
                $stmt->execute([$id]);

                // 2. Termine löschen
                $stmt = $conn->prepare("DELETE FROM termin WHERE klassen_id = ?");
                $stmt->execute([$id]);

                // 3. Stundentafel löschen (Spalte heißt laut deiner txt 'klasse_id')
                $stmt = $conn->prepare("DELETE FROM stundentafel WHERE klasse_id = ?");
                $stmt->execute([$id]);

                // 4. Zeitraster löschen
                $stmt = $conn->prepare("DELETE FROM klassen_zeitraster WHERE klasse_id = ?");
                $stmt->execute([$id]);

                // 5. Die Klasse selbst löschen
                $stmt = $conn->prepare("DELETE FROM klassen WHERE id = ?");
                $stmt->execute([$id]);
            } else {
                // Standard-Löschung für einfache Stammdaten
                $stmt = $conn->prepare("DELETE FROM `$table` WHERE id = ?");
                $stmt->execute([$id]);
            }

            $conn->commit();
            echo json_encode(["success" => true]);
        } catch (Exception $e) {
            if ($conn->inTransaction()) $conn->rollBack();
            // Falls ein SQL-Fehler auftritt, senden wir ihn als JSON, NICHT als Text!
            echo json_encode(["success" => false, "error" => $e->getMessage()]);
        }
    } else {
        echo json_encode(["success" => false, "error" => "Ungültiger Typ oder fehlende ID: $type"]);
    }
    exit;
}

if ($action === 'save_zweitkraft') {
    $data = json_decode(file_get_contents('php://input'), true);
    try {
        $conn->beginTransaction();

        // 1. STAMMDATEN SPEICHERN
        if (isset($data['id']) && $data['id']) {
            $stmt = $conn->prepare("UPDATE zweitkraft SET
                name = ?, kuerzel = ?, typ = ?,
                farbe = ?, textfarbe = ?,
                ermaessigung = ?, grund_ermaessigung = ?, upz = ?
                WHERE id = ?");
            $stmt->execute([
                $data['name'], $data['kuerzel'], $data['typ'],
                $data['farbe'], $data['textfarbe'],
                $data['ermaessigung'], $data['grund_ermaessigung'], $data['upz'], $data['id']
            ]);
            $id = $data['id'];
        } else {
            // INSERT
            $stmt = $conn->prepare("INSERT INTO zweitkraft
                (schuljahr_id, name, kuerzel, typ, farbe, textfarbe, ermaessigung, grund_ermaessigung, upz)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt->execute([
                $data['schuljahr_id'], $data['name'], $data['kuerzel'], $data['typ'],
                $data['farbe'], $data['textfarbe'] ?? '#ffffff',
                $data['ermaessigung'], $data['grund_ermaessigung'], $data['upz']
            ]);
            $id = $conn->lastInsertId();
        }

        // 2. STUNDENTAFEL (SOLL-Stunden je Aktivität/Einsatzort) SYNCHRONISIEREN
        // Lösche alte Einträge
        $stmtDel = $conn->prepare("DELETE FROM zweitkraft_stundentafel WHERE zweitkraft_id = ?");
        $stmtDel->execute([$id]);

        // 'pflichtstunden_masse' bleibt der Feldname aus dem Frontend-Payload;
        // jede Zeile kann jetzt zusätzlich 'aktivitaet_id' und 'besetzung_typ' mitbringen.
        if (!empty($data['pflichtstunden_masse']) && is_array($data['pflichtstunden_masse'])) {

            $stmtIns = $conn->prepare("
                INSERT INTO zweitkraft_stundentafel (zweitkraft_id, aktivitaet_id, einsatzort, soll_stunden, besetzung_typ)
                VALUES (?, ?, ?, ?, ?)
            ");

            foreach ($data['pflichtstunden_masse'] as $mass) {
                $sollStunden = $mass['soll_stunden'] ?? $mass['stunden'] ?? 0;
                // Nur speichern, wenn Stunden, Ort oder Aktivität gefüllt sind
                if (!empty($sollStunden) || !empty($mass['einsatzort']) || !empty($mass['aktivitaet_id'])) {
                    $stmtIns->execute([
                        $id,
                        !empty($mass['aktivitaet_id']) ? (int)$mass['aktivitaet_id'] : null,
                        $mass['einsatzort'] ?? null,
                        str_replace(',', '.', (string)$sollStunden),
                        $mass['besetzung_typ'] ?? 'einzel'
                    ]);
                }
            }
        }

        $conn->commit();
        echo json_encode(['success' => true, 'id' => $id]);

    } catch (PDOException $e) {
        if ($conn->inTransaction()) {
            $conn->rollBack();
        }
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

if ($action === 'import_document') {
    if (!isset($_FILES['document'])) {
        echo json_encode(["success" => false, "error" => "Keine Datei hochgeladen"]);
        exit;
    }

    $file = $_FILES['document'];
    $tmpPath = $file['tmp_name'];
    $fileName = $file['name'];
    $extension = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));

    try {
        if ($extension === 'doc') {
            // Antiword ausführen und Fehler in die Variable umleiten (2>&1)
            $command = "antiword -w 0 " . escapeshellarg($tmpPath) . " 2>&1";
            $extractedText = shell_exec($command);

            if ($extractedText === null || strpos($extractedText, 'not found') !== false) {
                throw new Exception("Antiword ist auf dem Server nicht installiert oder ein Fehler ist aufgetreten.");
            }
        } else {
            throw new Exception("Aktuell werden nur .doc Dateien unterstützt.");
        }

        // SICHERE KODIERUNG (Ersatz für utf8_encode)
        $utf8Text = mb_convert_encoding($extractedText, 'UTF-8', 'UTF-8');

        echo json_encode([
            "success" => true,
            "file" => $fileName,
            "text" => $utf8Text
        ]);

    } catch (Exception $e) {
        // Falls ein Fehler auftritt, senden wir ihn sauber als JSON zurück
        echo json_encode([
            "success" => false,
            "error" => $e->getMessage()
        ]);
    }
    exit;
}

if ($action === 'save_schuelerstundenplan') {
    $json = file_get_contents('php://input');
    $data = json_decode($json, true);

    $plan = $data['plan'];
    $klassenName = $data['klassenName'] ?? 'Unbenannte Klasse';
    $schuljahrId = $plan['schuljahr_id'] ?? null;

    try {
        $conn->beginTransaction();

        // 1. KLASSE ANLEGEN ODER AKTUALISIEREN
        if (!empty($plan['id']) && is_numeric($plan['id'])) {
            $klasseId = $plan['id'];
            $stmt = $conn->prepare("UPDATE klassen SET name = ?, schuljahr_id = ? WHERE id = ?");
            $stmt->execute([$klassenName, $schuljahrId, $klasseId]);
        } else {
            $stmt = $conn->prepare("INSERT INTO klassen (name, schuljahr_id) VALUES (?, ?)");
            $stmt->execute([$klassenName, $schuljahrId]);
            $klasseId = $conn->lastInsertId();
        }

        // 2. ZEITRASTER SPEICHERN (Muss vor den Terminen passieren!)
        if (isset($data['zeitRaster']) && is_array($data['zeitRaster'])) {
            // Altes Raster löschen
            $stmtDelRaster = $conn->prepare("DELETE FROM klassen_zeitraster WHERE klasse_id = ?");
            $stmtDelRaster->execute([$klasseId]);

            // Neues Raster einfügen
            $stmtInsRaster = $conn->prepare("INSERT INTO klassen_zeitraster (klasse_id, stunden_index, startzeit, endzeit) VALUES (?, ?, ?, ?)");
            foreach ($data['zeitRaster'] as $index => $stunde) {
                // $index entspricht hier der stunden_id (1, 2, 3...)
                $stmtInsRaster->execute([
                    $klasseId,
                    $index,
                    $stunde['start'] ?? '08:00',
                    $stunde['ende'] ?? '08:45'
                ]);
            }
        }

        // 3. DAS AKTUELL GESPEICHERTE RASTER LADEN (für das Mapping)
        // Wir holen uns die Zeiten direkt aus der DB, um sicher zu sein
        $stmtRasterMap = $conn->prepare("SELECT stunden_index, startzeit, endzeit FROM klassen_zeitraster WHERE klasse_id = ?");
        $stmtRasterMap->execute([$klasseId]);
        $rasterMapping = $stmtRasterMap->fetchAll(PDO::FETCH_UNIQUE | PDO::FETCH_ASSOC);

        // 3b. KONFLIKTPRÜFUNG gegen die DB, BEVOR etwas gelöscht wird.
        //     Die bestehenden Termine dieser Klasse werden gleich ersetzt und
        //     deshalb bei der Prüfung ausgenommen.
        $stmtOwn = $conn->prepare("SELECT id FROM termin WHERE klassen_id = ?");
        $stmtOwn->execute([$klasseId]);
        $eigeneTerminIds = $stmtOwn->fetchAll(PDO::FETCH_COLUMN);

        $alleKonflikte = [];
        foreach ($plan['termine'] as $t) {
            $stundenId = (int)$t['stunden_id'];
            $chkStart = isset($rasterMapping[$stundenId]) ? $rasterMapping[$stundenId]['startzeit'] : ($t['start'] ?? '00:00');
            $chkEnde  = isset($rasterMapping[$stundenId]) ? $rasterMapping[$stundenId]['endzeit'] : ($t['ende'] ?? '00:00');

            $rawRaum = $t['raum_ids'] ?? $t['raumId'] ?? $t['raum_id'] ?? null;
            $chkRaeume = empty($rawRaum) ? [] : (is_array($rawRaum) ? $rawRaum : [$rawRaum]);

            $chkKraefte = [];
            if (!empty($t['erstkraft_id']))  $chkKraefte[] = ['id' => $t['erstkraft_id'], 'typ' => 'erst'];
            if (!empty($t['zweitkraft_id'])) $chkKraefte[] = ['id' => $t['zweitkraft_id'], 'typ' => 'zweit'];

            $alleKonflikte = array_merge($alleKonflikte, elli_finde_konflikte($conn, [
                'tag' => $t['tag'],
                'start' => $chkStart,
                'ende' => $chkEnde,
                'raum_ids' => $chkRaeume,
                'kraefte' => $chkKraefte,
                // Eigene Klasse nicht prüfen: Parallel-Slots sind hier Differenzierung
                'klassen_id' => null,
                'exclude_termin_ids' => $eigeneTerminIds,
            ]));
        }
        if (!empty($alleKonflikte)) {
            $conn->rollBack();
            echo json_encode(['success' => false, 'error' => implode("\n", array_unique($alleKonflikte))]);
            exit;
        }

        // 4. CLEAN SLATE: ALTE TERMINE LÖSCHEN
        $stmtDelRaeume = $conn->prepare("DELETE FROM termin_raeume WHERE termin_id IN (SELECT id FROM termin WHERE klassen_id = ?)");
        $stmtDelRaeume->execute([$klasseId]);

        $stmtDelVerant = $conn->prepare("DELETE FROM termin_verantwortliche WHERE termin_id IN (SELECT id FROM termin WHERE klassen_id = ?)");
        $stmtDelVerant->execute([$klasseId]);

        $stmtDelTermin = $conn->prepare("DELETE FROM termin WHERE klassen_id = ?");
        $stmtDelTermin->execute([$klasseId]);

        // 5. NEUE TERMINE EINFÜGEN
        $stmtTermin = $conn->prepare("INSERT INTO termin
            (klassen_id, aktivitaet_id, schulfach_id, tag, stunden_id, start, ende, is_differenzierung)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)");

        $stmtRaum = $conn->prepare("INSERT INTO termin_raeume (termin_id, raum_id) VALUES (?, ?)");
        $stmtVerant = $conn->prepare("INSERT INTO termin_verantwortliche (termin_id, kraft_id, kraft_typ) VALUES (?, ?, ?)");

        foreach ($plan['termine'] as $t) {
            $stundenId = (int)$t['stunden_id'];

            // DIE KORREKTUR: Wir nehmen die Zeit aus unserem Mapping statt aus $t['start']
            $echterStart = isset($rasterMapping[$stundenId]) ? $rasterMapping[$stundenId]['startzeit'] : ($t['start'] ?? '00:00');
            $echtesEnde  = isset($rasterMapping[$stundenId]) ? $rasterMapping[$stundenId]['endzeit'] : ($t['ende'] ?? '00:00');

            $checkDiff = 0;
            if (isset($t['is_differenzierung'])) {
                $val = $t['is_differenzierung'];
            } elseif (isset($t['ist_differenzierung'])) {
                $val = $t['ist_differenzierung'];
            }

            // Wenn der Wert 1, true oder "1" ist, setzen wir checkDiff auf 1
            if ($val === 1 || $val === true || $val === "1") {
                $checkDiff = 1;
            }

            $aktId = !empty($t['aktivitaet_id']) ? $t['aktivitaet_id'] : null;

            $stmtTermin->execute([
                $klasseId,
                $aktId,
                $t['schulfach_id'] ?? null,
                $t['tag'],
                $stundenId,
                $echterStart,
                $echtesEnde,
                $checkDiff,
            ]);

            $terminId = $conn->lastInsertId();

            // Raum-IDs verknüpfen
            $raumIds = [];

            // Prüfe alle gängigen Varianten (Singular/Plural, Snake/CamelCase)
            $rawRaumData = $t['raum_ids'] ?? $t['raumId'] ?? $t['raum_id'] ?? null;

            if (!empty($rawRaumData)) {
                // Falls es ein Array ist (z.B. Multi-Select), direkt nehmen
                if (is_array($rawRaumData)) {
                    $raumIds = $rawRaumData;
                } else {
                    // Falls es ein einzelner Wert/String ist, in Array umwandeln
                    $raumIds = [$rawRaumData];
                }
            }

            foreach ($raumIds as $rid) {
                if (!empty($rid) && (is_numeric($rid) || is_string($rid))) {
                    $stmtRaum->execute([$terminId, (int)$rid]);
                }
            }

            // Verantwortliche (Erst- und Zweitkräfte)
            if (!empty($t['erstkraft_id'])) {
                $stmtVerant->execute([$terminId, $t['erstkraft_id'], 'erst']);
            }
            if (!empty($t['zweitkraft_id'])) {
                $stmtVerant->execute([$terminId, $t['zweitkraft_id'], 'zweit']);
            }
        }

        // 6. STUNDENTAFEL AKTUALISIEREN
        // Das Frontend sendet je Fach: { schulfach_id, verbund, diff }.
        // "verbund" wird als besetzung_typ 'einzel', "diff" als 'doppel' gespeichert.
        if (isset($data['stundentafel']) && is_array($data['stundentafel'])) {
            $stmtDelStaf = $conn->prepare("DELETE FROM stundentafel WHERE klasse_id = ?");
            $stmtDelStaf->execute([$klasseId]);

            $stmtStaf = $conn->prepare("INSERT INTO stundentafel
                (klasse_id, fach_id, soll_stunden, besetzung_typ)
                VALUES (?, ?, ?, ?)");

            foreach ($data['stundentafel'] as $zeile) {
                $fachId = $zeile['schulfach_id'] ?? $zeile['fach_id'] ?? null;
                if (!$fachId) continue;

                $verbund = floatval($zeile['verbund'] ?? $zeile['soll_klassenverbund'] ?? 0);
                $diff    = floatval($zeile['diff'] ?? $zeile['soll_differenzierung'] ?? 0);

                if ($verbund > 0) {
                    $stmtStaf->execute([$klasseId, $fachId, $verbund, 'einzel']);
                }
                if ($diff > 0) {
                    $stmtStaf->execute([$klasseId, $fachId, $diff, 'doppel']);
                }
            }
        }

        $conn->commit();
        echo json_encode(["success" => true, "klasseId" => $klasseId]);

    } catch (Exception $e) {
        if ($conn->inTransaction()) { $conn->rollBack(); }
        echo json_encode(["success" => false, "error" => $e->getMessage()]);
    }
    exit;
}

if ($action === 'get_schuelerstundenplan') {
    $klasseId = $_GET['klasseId'] ?? null;
    if (!$klasseId) {
        echo json_encode(["success" => false, "error" => "Keine ID"]);
        exit;
    }

    try {
        // 1. Grunddaten der Klasse (unverändert)
        $stmtKlasse = $conn->prepare("SELECT id, name, schuljahr_id FROM klassen WHERE id = ?");
        $stmtKlasse->execute([$klasseId]);
        $klasse = $stmtKlasse->fetch(PDO::FETCH_ASSOC);
        if (!$klasse) throw new Exception("Klasse nicht gefunden");

        // 2. Termine mit der neuen n:m Raum-Logik
        // Wir nutzen GROUP_CONCAT, um alle Raumnamen in einen String zu fassen (z.B. "R101, R102")
        $stmtTermine = $conn->prepare("
                SELECT t.*,
                       f.name AS fach_name, f.farbe AS fach_farbe,
                       -- Erstkraft über Verknüpfungstabelle holen
                       (SELECT e.kuerzel FROM termin_verantwortliche tv
                        JOIN erstkraft e ON tv.kraft_id = e.id
                        WHERE tv.termin_id = t.id AND tv.kraft_typ = 'erst' LIMIT 1) AS erstkraft_kuerzel,
                       (SELECT tv.kraft_id FROM termin_verantwortliche tv
                        WHERE tv.termin_id = t.id AND tv.kraft_typ = 'erst' LIMIT 1) AS erstkraft_id,
                       -- Zweitkraft über Verknüpfungstabelle holen
                       (SELECT z.kuerzel FROM termin_verantwortliche tv
                        JOIN zweitkraft z ON tv.kraft_id = z.id
                        WHERE tv.termin_id = t.id AND tv.kraft_typ = 'zweit' LIMIT 1) AS zweitkraft_kuerzel,
                       (SELECT tv.kraft_id FROM termin_verantwortliche tv
                        WHERE tv.termin_id = t.id AND tv.kraft_typ = 'zweit' LIMIT 1) AS zweitkraft_id,
                       GROUP_CONCAT(r.name SEPARATOR ', ') AS raum_namen,
                       GROUP_CONCAT(r.id SEPARATOR ',') AS raum_ids
                FROM termin t
                LEFT JOIN schulfach f ON t.schulfach_id = f.id
                LEFT JOIN termin_raeume tr ON t.id = tr.termin_id
                LEFT JOIN raum r           ON tr.raum_id = r.id
                WHERE t.klassen_id = ?
                GROUP BY t.id
            ");
        $stmtTermine->execute([$klasseId]);
        $termineRaw = $stmtTermine->fetchAll(PDO::FETCH_ASSOC);

        $aufbereiteteTermine = [];
        foreach ($termineRaw as $t) {
            $isDiff = ($t['is_differenzierung'] == 1);

            // Raum-IDs als Array für das Frontend aufbereiten
            $raumIdsArray = $t['raum_ids'] ? explode(',', $t['raum_ids']) : [];
            $raumIdsArray = array_map('intval', $raumIdsArray);

            $aufbereiteteTermine[] = [
                'id'                 => (int)$t['id'],
                'uuid'               => $t['uuid'] ?? uniqid('t_', true),
                'schulfach_id'       => (int)$t['schulfach_id'],
                'tag'                => $t['tag'],
                'stunden_id'         => (int)$t['stunden_id'],
                'start'              => substr($t['start'], 0, 5),
                'ende'               => substr($t['ende'], 0, 5),
                'erstkraft_id'       => (int)$t['erstkraft_id'],
                'zweitkraft_id'      => $t['zweitkraft_id'] ? (int)$t['zweitkraft_id'] : null,
                'raum_ids'           => $raumIdsArray, // Jetzt als Liste von IDs verfügbar
                'fachName'           => $t['fach_name'] ?? 'Unbekannt',
                'farbe'              => $t['fach_farbe'] ?? '#e0e0e0',
                'is_differenzierung' => $isDiff,
                'ist_differenzierung' => $isDiff,
                'ist_klassenverbund'  => !$isDiff,
                'display' => [
                    'fachName'      => $t['fach_name'] ?? '?',
                    'lehrerKuerzel' => $t['erstkraft_kuerzel'] ?? ($t['erstkraft_name'] ?? '??'),
                    'farbe'         => $t['fach_farbe'] ?? '#e0e0e0',
                    // Wenn kein Raum zugeordnet ist, zeigen wir ein leeres Array-Symbol oder Text
                    'raumName'      => $t['raum_namen'] ?: '--'
                ]
            ];
        }

        // 3. Stundentafel
        $stmtStaf = $conn->prepare("SELECT fach_id, soll_stunden, besetzung_typ FROM stundentafel WHERE klasse_id = ?");
        $stmtStaf->execute([$klasseId]);
        $tempTafel = [];
        foreach ($stmtStaf->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $fId = $row['fach_id'];
            if (!isset($tempTafel[$fId])) {
                $tempTafel[$fId] = ['schulfach_id' => (int)$fId, 'verbund' => 0, 'diff' => 0];
            }
            if ($row['besetzung_typ'] === 'einzel') $tempTafel[$fId]['verbund'] = (float)$row['soll_stunden'];
            if ($row['besetzung_typ'] === 'doppel') $tempTafel[$fId]['diff']    = (float)$row['soll_stunden'];
        }

        // 4. Zeitraster
        $stmtRaster = $conn->prepare("SELECT stunden_index, startzeit, endzeit FROM klassen_zeitraster WHERE klasse_id = ? ORDER BY stunden_index ASC");
        $stmtRaster->execute([$klasseId]);
        $zeitRaster = $stmtRaster->fetchAll(PDO::FETCH_ASSOC);

        // 4b. stunden_id anhand der tatsächlichen Startzeit an das Klassenraster angleichen.
        //     Termine, die in einem anderen Kontext angelegt wurden (z.B. über den
        //     Lehrerplan mit abweichender Rasterzählung), tragen eine stunden_id,
        //     die sich auf ein FREMDES Raster bezieht und daher nicht zu diesem
        //     klassen_zeitraster passt. Das Grid ordnet Termine aber über stunden_id
        //     ein – ohne diese Korrektur würden solche Termine unsichtbar bleiben.
        //     Maßgeblich ist die Uhrzeit aus der Tabelle termin.
        $startZuIndex = [];
        foreach ($zeitRaster as $zr) {
            $startZuIndex[substr((string)$zr['startzeit'], 0, 5)] = (int)$zr['stunden_index'];
        }
        foreach ($aufbereiteteTermine as &$__t) {
            if (isset($startZuIndex[$__t['start']])) {
                $__t['stunden_id'] = $startZuIndex[$__t['start']];
            }
        }
        unset($__t);

        // FINALE AUSGABE (Achte auf $aufbereiteteTermine!)
        echo json_encode([
            "success" => true,
            "data" => [
                "plan" => [
                    "id"           => (int)$klasse['id'],
                    "klasse_name"  => $klasse['name'],
                    "schuljahr_id" => (int)$klasse['schuljahr_id'],
                    "termine"      => $aufbereiteteTermine
                ],
                "stundentafel" => array_values($tempTafel),
                "zeitRaster"   => $zeitRaster
            ]
        ]);
    } catch (Exception $e) {
        echo json_encode(["success" => false, "error" => $e->getMessage()]);
    }
    exit;
}

if ($action === 'get_lehrerverfuegbarkeiten') {
    $sid = $_GET['schuljahr_id'] ?? null;

    if (!$sid) {
        echo json_encode(["success" => false, "error" => "Schuljahr-ID fehlt"]);
        exit;
    }

    try {
        // Wir passen das Query an, um die neuen termin_raeume zu berücksichtigen
        $stmt = $conn->prepare("
            select t.id AS termin_id, t.klassen_id, k.name as klassen_name, t.aktivitaet_id, a.name as aktivitaet, t.schulfach_id, s.name as fach, t.tag, t.start, t.ende, tv.kraft_id, e.name, e.upz
            from termin as t
            join termin_verantwortliche as tv on t.id = tv.termin_id
            join erstkraft as e on e.id = tv.kraft_id
            left join klassen as k on k.id = t.klassen_id
            left join aktivitaet as a on t.aktivitaet_id = a.id
            left join schulfach as s on t.schulfach_id = s.id
            where e.schuljahr_id = ?
        ");
        $stmt->execute([$sid]);
        $allData = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $lehrerMap = [];

        foreach ($allData as $row) {
            $kid = $row['kraft_id'];

            // Lehrer-Struktur anlegen, falls noch nicht vorhanden
            if (!isset($lehrerMap[$kid])) {
                $lehrerMap[$kid] = [
                    'id'      => $kid,
                    'name'    => $row['name'],
                    'upz'     => $row['upz'] ?? 0,
                    'termine' => []
                ];
            }

            // Termin zum jeweiligen Lehrer hinzufügen
            $lehrerMap[$kid]['termine'][] = [
                'termin_id'     => $row['termin_id'],
                'tag'           => $row['tag'],
                'start'         => $row['start'],
                'ende'          => $row['ende'],
                'klasse'        => $row['klassen_name'],
                'klassen_id'    => $row['klassen_id'],
                'fach'          => $row['fach'],
                'fach_id'       => $row['schulfach_id'],
                'aktivitaet'    => $row['aktivitaet'],
                'aktivitaet_id' => $row['aktivitaet_id']
            ];
        }

        // Wir geben eine Liste aller Lehrer zurück
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            "success" => true,
            "data" => array_values($lehrerMap) // array_values macht daraus ein sauberes JSON-Array []
        ], JSON_PRETTY_PRINT);

    } catch (Exception $e) {
        echo json_encode(["success" => false, "error" => $e->getMessage()]);
    }
    exit;
}

if ($action === 'export_lehrerstundenplan') {
    // Exportiert den Lehrerstundenplan einer Erstkraft als Word-Datei (.docx).
    // Basiert auf dem Template lehrerstundenplan_template.docx (gleicher Ordner),
    // das per ${platzhalter} über PHPWord TemplateProcessor befüllt wird.
    // Sehr ähnlich zu export_diensteinsatzplan, nur mit Fach/Klasse/+//++ statt Einsatzort.
    $schuljahr_id = isset($_GET['schuljahr_id']) ? (int)$_GET['schuljahr_id'] : 0;
    $erstkraft_id = isset($_GET['erstkraft_id']) ? (int)$_GET['erstkraft_id'] : 0;

    if (!$schuljahr_id || !$erstkraft_id) {
        echo json_encode(["success" => false, "error" => "schuljahr_id und erstkraft_id sind Pflicht"]);
        exit;
    }

    ob_start();

    try {
        $esc = function ($v) {
            return htmlspecialchars((string)$v, ENT_QUOTES | ENT_XML1, 'UTF-8');
        };
        $fmtStunden = function ($h) {
            $s = number_format((float)$h, 2, ',', '');
            if (substr($s, -1) === '0') $s = substr($s, 0, -1);
            if (substr($s, -1) === ',') $s = substr($s, 0, -1);
            return $s;
        };

        // 1. Schule
        $stmtS = $conn->prepare("SELECT schuljahr, adresse FROM schule WHERE id = ?");
        $stmtS->execute([$schuljahr_id]);
        $schule = $stmtS->fetch(PDO::FETCH_ASSOC) ?: ['schuljahr' => '', 'adresse' => null];

        $adresse = json_decode($schule['adresse'] ?? '', true) ?: [];
        $nameZeilen = preg_split('/\r\n|\r|\n/', trim($adresse['name'] ?? ''));
        $schule1 = trim($nameZeilen[0] ?? '');
        $schule2 = trim(implode(' ', array_slice($nameZeilen, 1)));
        $ortTeile = array_filter([trim($adresse['strasse'] ?? ''), trim($adresse['stadt'] ?? '')]);
        $schule3 = implode(', ', $ortTeile);

        // 2. Lehrkraft
        $stmtE = $conn->prepare("SELECT name, titel, pflichtstunden, ermaessigung, ermaessigung_grund, upz
                                 FROM erstkraft WHERE id = ? AND schuljahr_id = ?");
        $stmtE->execute([$erstkraft_id, $schuljahr_id]);
        $e = $stmtE->fetch(PDO::FETCH_ASSOC);
        if (!$e) {
            ob_end_clean();
            echo json_encode(["success" => false, "error" => "Lehrkraft nicht gefunden"]);
            exit;
        }

        // 3. Termine (Fach ODER Aktivität, plus Klasse und Differenzierung)
        $stmtT = $conn->prepare("SELECT t.tag, t.start, t.ende, t.is_differenzierung,
                                         k.name AS klasse, s.name AS fach, a.name AS aktivitaet
                                  FROM termin_verantwortliche tv
                                  JOIN termin t ON t.id = tv.termin_id
                                  LEFT JOIN klassen k ON k.id = t.klassen_id
                                  LEFT JOIN schulfach s ON s.id = t.schulfach_id
                                  LEFT JOIN aktivitaet a ON a.id = t.aktivitaet_id
                                  WHERE tv.kraft_typ = 'erst' AND tv.kraft_id = ?
                                  ORDER BY t.start");
        $stmtT->execute([$erstkraft_id]);
        $termine = $stmtT->fetchAll(PDO::FETCH_ASSOC);

        $byTag = [];
        foreach ($termine as $t) {
            $byTag[$t['tag']][] = $t;
        }

        // 3b. Schulfach-Blöcke zusammenfassen: ein Schulfach belegt nur EINE Zeile.
        //     Das Frontend legt ein mehrstündiges Fach als mehrere 45-min-Termine an;
        //     aufeinanderfolgende, zeitlich anschließende Termine desselben Fachs
        //     (gleiche Klasse & Differenzierung) werden zu einer Zeitspanne verschmolzen.
        //     Aktivitäten bleiben unangetastet.
        foreach ($byTag as $tag => $liste) {
            usort($liste, function ($a, $b) { return strcmp($a['start'], $b['start']); });
            $merged = [];
            foreach ($liste as $t) {
                $istFach = trim((string)$t['fach']) !== '';
                $n = count($merged);
                if ($istFach && $n > 0
                    && trim((string)$merged[$n - 1]['fach']) !== ''
                    && trim((string)$merged[$n - 1]['fach'])   === trim((string)$t['fach'])
                    && trim((string)$merged[$n - 1]['klasse']) === trim((string)$t['klasse'])
                    && (int)$merged[$n - 1]['is_differenzierung'] === (int)$t['is_differenzierung']
                    && $t['start'] <= $merged[$n - 1]['ende']) {
                    // Gleiches Fach schließt direkt an -> Zeitspanne verlängern statt neue Zeile
                    if ($t['ende'] > $merged[$n - 1]['ende']) {
                        $merged[$n - 1]['ende'] = $t['ende'];
                    }
                    continue;
                }
                $merged[] = $t;
            }
            $byTag[$tag] = $merged;
        }

        // 4. UPZ-Aufschlüsselung aus lehrer_stundentafel: Gesamtstunden in Schulfächern
        //    als ein Posten ("Unterricht"), danach Stunden je Aktivität einzeln.
        $stmtP = $conn->prepare("SELECT ls.fach_id, ls.aktivitaet_id, ls.soll_stunden, a.name AS aktivitaet_name
                                 FROM lehrer_stundentafel ls
                                 LEFT JOIN aktivitaet a ON a.id = ls.aktivitaet_id
                                 WHERE ls.erstkraft_id = ?");
        $stmtP->execute([$erstkraft_id]);
        $unterrichtSumme = 0.0;
        $aktivitaetSummen = [];
        foreach ($stmtP->fetchAll(PDO::FETCH_ASSOC) as $p) {
            $stunden = (float)$p['soll_stunden'];
            if ($p['fach_id']) {
                $unterrichtSumme += $stunden;
            } elseif ($p['aktivitaet_id']) {
                $name = $p['aktivitaet_name'] ?: 'Sonstiges';
                $aktivitaetSummen[$name] = ($aktivitaetSummen[$name] ?? 0) + $stunden;
            }
        }
        $upzTeile = [];
        if ($unterrichtSumme > 0) $upzTeile[] = $fmtStunden($unterrichtSumme) . ' Unterricht';
        foreach ($aktivitaetSummen as $name => $stunden) {
            $upzTeile[] = $fmtStunden($stunden) . ' ' . $name;
        }
        $upzText = $e['upz'] . (count($upzTeile) ? ' (' . implode(', ', $upzTeile) . ')' : '');

        // 5. Ersteller: Anzeigename aus den Einstellungen (nutzername)
        $stmtU = $conn->prepare("SELECT wert FROM einstellungen WHERE schluessel = 'nutzername'");
        $stmtU->execute();
        $ersteller = $stmtU->fetchColumn() ?: '';

        // 6. Template befüllen
        $tplPath = __DIR__ . '/lehrerstundenplan_template.docx';
        if (!file_exists($tplPath)) {
            ob_end_clean();
            echo json_encode(["success" => false, "error" => "Template fehlt: lehrerstundenplan_template.docx"]);
            exit;
        }
        $tpl = new \PhpOffice\PhpWord\TemplateProcessor($tplPath);

        $tpl->setValue('schuljahr', $esc($schule['schuljahr']));
        $tpl->setValue('lehrer', $esc($e['name'] . ($e['titel'] ? ', ' . $e['titel'] : '')));
        $tpl->setValue('schule1', $esc($schule1));
        $tpl->setValue('schule2', $esc($schule2));
        $tpl->setValue('schule3', $esc($schule3));
        $tpl->setValue('regel', $esc($e['pflichtstunden']));
        $tpl->setValue('upz', $esc($upzText));
        $tpl->setValue('erm', $esc($e['ermaessigung']));
        $tpl->setValue('grund', $esc($e['ermaessigung_grund']));
        $tpl->setValue('erstellt', date('d.m.y'));
        $tpl->setValue('ersteller', $esc($ersteller));

        // Tages-Slots: Präfix + Anzahl freier Zeilen im Template
        $slots = [
            'Montag'     => ['m', 10],
            'Dienstag'   => ['di', 9],
            'Mittwoch'   => ['mi', 9],
            'Donnerstag' => ['d', 10],
            'Freitag'    => ['f', 9],
        ];

        foreach ($slots as $tag => [$prefix, $anzahl]) {
            $liste = $byTag[$tag] ?? [];
            for ($i = 1; $i <= $anzahl; $i++) {
                $t = $liste[$i - 1] ?? null;
                if ($t) {
                    $zeit = substr($t['start'], 0, 5) . ' – ' . substr($t['ende'], 0, 5);
                    $istSchulfach = trim((string)$t['fach']) !== '';
                    // Überlange Titel werden im Template per fester Zeilenhöhe geclippt (maxLines=1).
                    $fach = trim((string)($t['fach'] ?: $t['aktivitaet'] ?: '-'));
                    $klasse = trim((string)$t['klasse']);
                    // +/++ gilt nur für Schulfächer; Aktivitäten (Schulleitung, MSD, ...)
                    // bekommen kein Zeichen.
                    $marke = $istSchulfach ? (((int)$t['is_differenzierung'] === 1) ? '++' : '+') : '';
                    $tpl->setValue($prefix . $i . 'z', $esc($zeit));
                    $tpl->setValue($prefix . $i . 'f', $esc($fach));
                    $tpl->setValue($prefix . $i . 'k', $esc($klasse));
                    $tpl->setValue($prefix . $i . 'p', $esc($marke));
                } else {
                    $tpl->setValue($prefix . $i . 'z', '');
                    $tpl->setValue($prefix . $i . 'f', '');
                    $tpl->setValue($prefix . $i . 'k', '');
                    $tpl->setValue($prefix . $i . 'p', '');
                }
            }
        }

        // 7. Als Download ausliefern
        $tmp = tempnam(sys_get_temp_dir(), 'lsp');
        $tpl->saveAs($tmp);

        $dateiname = 'Lehrerstundenplan_' . preg_replace('/[^A-Za-z0-9_\-]+/', '_', $e['name']) . '.docx';

        ob_end_clean();
        header('Content-Type: application/vnd.openxmlformats-officedocument.wordprocessingml.document');
        header('Content-Disposition: attachment; filename="' . $dateiname . '"');
        header('Content-Length: ' . filesize($tmp));
        readfile($tmp);
        unlink($tmp);
        exit;

    } catch (Exception $ex) {
        if (ob_get_level()) ob_end_clean();
        http_response_code(500);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(["success" => false, "error" => "Export fehlgeschlagen", "message" => $ex->getMessage()]);
        exit;
    }
}

if ($action === 'export_schuelerstundenplan') {
    // Exportiert den Stundenplan einer Klasse als Word-Datei (.docx).
    // Template: schuelerstundenplan_template.docx – Wochenraster (Mo–Fr) + Stundentafel.
    // Variable Zeilen (Zeitraster, Fächer) werden per PHPWord cloneRow erzeugt.
    $klasse_id = isset($_GET['klasseId']) ? (int)$_GET['klasseId'] : (isset($_GET['klasse_id']) ? (int)$_GET['klasse_id'] : 0);
    if (!$klasse_id) {
        echo json_encode(["success" => false, "error" => "klasseId fehlt"]);
        exit;
    }

    ob_start();
    try {
        $esc = function ($v) {
            return htmlspecialchars((string)$v, ENT_QUOTES | ENT_XML1, 'UTF-8');
        };
        $fmtNum = function ($h) {
            $s = number_format((float)$h, 2, ',', '');
            $s = rtrim(rtrim($s, '0'), ',');
            return $s === '' ? '0' : $s;
        };
        $hhmm = function ($t) { return substr((string)$t, 0, 5); };            // "08:15:00" -> "08:15"
        $zeitLabel = function ($s, $e) use ($hhmm) {
            return str_replace(':', '.', $hhmm($s)) . '-' . str_replace(':', '.', $hhmm($e));
        };

        // 1. Klasse + Schule
        $stmtK = $conn->prepare("SELECT id, name, schuljahr_id FROM klassen WHERE id = ?");
        $stmtK->execute([$klasse_id]);
        $klasse = $stmtK->fetch(PDO::FETCH_ASSOC);
        if (!$klasse) { ob_end_clean(); echo json_encode(["success" => false, "error" => "Klasse nicht gefunden"]); exit; }

        $stmtS = $conn->prepare("SELECT schuljahr, adresse FROM schule WHERE id = ?");
        $stmtS->execute([$klasse['schuljahr_id']]);
        $schule = $stmtS->fetch(PDO::FETCH_ASSOC) ?: ['schuljahr' => '', 'adresse' => null];
        $adresse = json_decode($schule['adresse'] ?? '', true) ?: [];
        $schulname = trim(preg_replace('/\s+/', ' ', (string)($adresse['name'] ?? '')));

        // 2. Zeitraster der Klasse (individuelle Stundenzeiten)
        $stmtR = $conn->prepare("SELECT stunden_index, startzeit, endzeit
                                 FROM klassen_zeitraster WHERE klasse_id = ? ORDER BY stunden_index ASC");
        $stmtR->execute([$klasse_id]);
        $raster = $stmtR->fetchAll(PDO::FETCH_ASSOC);

        // 3. Termine der Klasse inkl. aller verantwortlichen Lehrkräfte (Namen)
        $stmtT = $conn->prepare("
            SELECT t.tag, t.start,
                   GROUP_CONCAT(DISTINCT COALESCE(e.name, z.name) ORDER BY COALESCE(e.name, z.name) SEPARATOR ' / ') AS lehrer
            FROM termin t
            LEFT JOIN termin_verantwortliche tv ON tv.termin_id = t.id
            LEFT JOIN erstkraft  e ON e.id = tv.kraft_id AND tv.kraft_typ = 'erst'
            LEFT JOIN zweitkraft z ON z.id = tv.kraft_id AND tv.kraft_typ = 'zweit'
            WHERE t.klassen_id = ?
            GROUP BY t.id, t.tag, t.start
        ");
        $stmtT->execute([$klasse_id]);

        // Nur den Nachnamen verwenden = letzter Namensteil ("Vorname Nachname");
        // ein evtl. Titel nach Komma wird vorher entfernt.
        $nachname = function ($name) {
            $name = trim(preg_replace('/,.*/', '', (string)$name));
            $parts = preg_split('/\s+/', $name, -1, PREG_SPLIT_NO_EMPTY);
            if (!$parts) return trim((string)$name);
            return $parts[count($parts) - 1];
        };

        // Lehrer je (Tag, Startzeit) sammeln – mehrere Termine (z.B. äußere
        // Differenzierung mit zwei Gruppen) ergeben zwei Namen im Slot.
        $tage = ['Montag', 'Dienstag', 'Mittwoch', 'Donnerstag', 'Freitag'];
        $belegung = []; // [start]['Montag'] = ['Nachname1','Nachname2']
        foreach ($stmtT->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $start = $hhmm($r['start']);
            $tag = $r['tag'];
            if (!in_array($tag, $tage, true)) continue;
            foreach (explode(' / ', (string)$r['lehrer']) as $name) {
                $name = $nachname($name);
                if ($name === '') continue;
                if (!isset($belegung[$start][$tag])) $belegung[$start][$tag] = [];
                if (!in_array($name, $belegung[$start][$tag], true)) $belegung[$start][$tag][] = $name;
            }
        }

        // 4. Stundentafel: je Fach 1* (einzel/Klassenverband) und 2** (doppel/Differenzierung)
        $stmtST = $conn->prepare("SELECT s.name AS fach, st.soll_stunden, st.besetzung_typ
                                  FROM stundentafel st
                                  LEFT JOIN schulfach s ON s.id = st.fach_id
                                  WHERE st.klasse_id = ?");
        $stmtST->execute([$klasse_id]);
        $tafel = [];
        foreach ($stmtST->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $fach = $r['fach'] ?: '—';
            if (!isset($tafel[$fach])) $tafel[$fach] = ['fach' => $fach, 'v' => 0.0, 'd' => 0.0];
            if ($r['besetzung_typ'] === 'doppel') $tafel[$fach]['d'] += (float)$r['soll_stunden'];
            else $tafel[$fach]['v'] += (float)$r['soll_stunden'];
        }
        $tafel = array_values($tafel);
        usort($tafel, function ($a, $b) { return strcmp($a['fach'], $b['fach']); });
        $sumV = 0; $sumD = 0;
        foreach ($tafel as $t) { $sumV += $t['v']; $sumD += $t['d']; }

        // 5. Ersteller (Anzeigename aus den Einstellungen)
        $stmtU = $conn->prepare("SELECT wert FROM einstellungen WHERE schluessel = 'nutzername'");
        $stmtU->execute();
        $ersteller = $stmtU->fetchColumn() ?: '';

        // 6. Dokument-Body als WordML aufbauen (dynamisch, damit Zellen bei
        //    äußerer Differenzierung in zwei Zellen geteilt werden können).
        $tplPath = __DIR__ . '/schuelerstundenplan_template.docx';
        if (!file_exists($tplPath)) {
            ob_end_clean();
            echo json_encode(["success" => false, "error" => "Template fehlt: schuelerstundenplan_template.docx"]);
            exit;
        }

        // --- WordML-Helfer ---
        $run = function ($text, $bold = false, $size = 20) use ($esc) {
            if ($text === '' || $text === null) return '';
            $b = $bold ? '<w:b/>' : '';
            return '<w:r><w:rPr>' . $b . '<w:sz w:val="' . $size . '"/><w:szCs w:val="' . $size . '"/></w:rPr>'
                 . '<w:t xml:space="preserve">' . $esc($text) . '</w:t></w:r>';
        };
        $para = function ($text = '', $bold = false, $size = 20, $align = 'left', $after = 0) use ($run) {
            $b = $bold ? '<w:b/>' : '';
            return '<w:p><w:pPr><w:spacing w:after="' . $after . '"/><w:jc w:val="' . $align . '"/>'
                 . '<w:rPr>' . $b . '<w:sz w:val="' . $size . '"/><w:szCs w:val="' . $size . '"/></w:rPr></w:pPr>'
                 . $run($text, $bold, $size) . '</w:p>';
        };
        $tcell = function ($inner, $w, $span = 1, $shade = null, $valign = 'center', $nomar = false) {
            $pr = '<w:tcW w:w="' . $w . '" w:type="dxa"/>';
            if ($span > 1) $pr .= '<w:gridSpan w:val="' . $span . '"/>';
            if ($shade) $pr .= '<w:shd w:val="clear" w:color="auto" w:fill="' . $shade . '"/>';
            // Null-Zellränder für die äußere Anordnung, damit die inneren Tabellen
            // ihre volle Breite (inkl. rechtem Rahmen) behalten und nicht abgeschnitten werden.
            if ($nomar) $pr .= '<w:tcMar><w:top w:w="0" w:type="dxa"/><w:left w:w="0" w:type="dxa"/><w:bottom w:w="0" w:type="dxa"/><w:right w:w="0" w:type="dxa"/></w:tcMar>';
            $pr .= '<w:vAlign w:val="' . $valign . '"/>';
            return '<w:tc><w:tcPr>' . $pr . '</w:tcPr>' . $inner . '</w:tc>';
        };
        $trow = function ($cells, $h = null) {
            $pr = $h ? '<w:trPr><w:trHeight w:val="' . $h . '"/></w:trPr>' : '';
            return '<w:tr>' . $pr . implode('', $cells) . '</w:tr>';
        };
        $tbl = function ($grid, $rows, $borders = true) {
            $cols = '';
            foreach ($grid as $w) $cols .= '<w:gridCol w:w="' . $w . '"/>';
            $bd = '';
            if ($borders) {
                foreach (['top','left','bottom','right','insideH','insideV'] as $s)
                    $bd .= '<w:' . $s . ' w:val="single" w:sz="6" w:space="0" w:color="000000"/>';
                $bd = '<w:tblBorders>' . $bd . '</w:tblBorders>';
            }
            $pr = '<w:tblPr><w:tblW w:w="0" w:type="auto"/>' . $bd . '<w:tblLayout w:type="fixed"/>'
                . '<w:tblLook w:val="04A0" w:firstRow="1" w:lastRow="0" w:firstColumn="1" w:lastColumn="0" w:noHBand="0" w:noVBand="1"/></w:tblPr>';
            return '<w:tbl>' . $pr . '<w:tblGrid>' . $cols . '</w:tblGrid>' . implode('', $rows) . '</w:tbl>';
        };

        // --- Kopf (2-spaltig wie im Original: links Titel/Schule, rechts Zeitraum/Klasse) ---
        $HL = 8500; $HR = 5786;
        $kopf = $tbl([$HL, $HR], [
            $trow([
                $tcell($para('Schüler-Stundenplan   Schuljahr ' . $schule['schuljahr'], true, 32, 'left'), $HL, 1, null, 'center', true),
                $tcell($para('für die Zeit vom ______________ – ______________', true, 22, 'left'), $HR, 1, null, 'center', true),
            ]),
            $trow([
                $tcell($para($schulname, true, 24, 'left'), $HL, 1, null, 'center', true),
                $tcell($para('Klasse ' . $klasse['name'], true, 24, 'left'), $HR, 1, null, 'center', true),
            ]),
        ], false) . $para('', false, 8, 'left', 60);

        // --- Wochenraster (Zeit + 5 Tage à 2 Sub-Spalten für Differenzierung) ---
        $SUB = 866; $ZW = 1740; $DAYW = 2 * $SUB;
        $gridWT = array_merge([$ZW], array_fill(0, 10, $SUB)); // 11 Spalten
        $headCells = [$tcell($para('Unterrichtszeit', true, 18, 'center'), $ZW, 1, 'D9D9D9')];
        foreach (['Montag','Dienstag','Mittwoch','Donnerstag','Freitag'] as $tagName)
            $headCells[] = $tcell($para($tagName, true, 18, 'center'), $DAYW, 2, 'D9D9D9');
        $wtRows = [$trow($headCells, 320)];

        $tagKey = ['Montag','Dienstag','Mittwoch','Donnerstag','Freitag'];
        foreach ($raster as $slot) {
            $start = $hhmm($slot['startzeit']);
            $cells = [$tcell($para($zeitLabel($slot['startzeit'], $slot['endzeit']), false, 18, 'center'), $ZW)];
            foreach ($tagKey as $tag) {
                $namen = $belegung[$start][$tag] ?? [];
                if (count($namen) >= 2) {
                    // Äußere Differenzierung: Zelle in zwei Zellen teilen (ein Name je Gruppe)
                    $links  = $namen[0];
                    $rechts = count($namen) > 2 ? implode(' / ', array_slice($namen, 1)) : $namen[1];
                    $cells[] = $tcell($para($links, false, 18, 'center'), $SUB);
                    $cells[] = $tcell($para($rechts, false, 18, 'center'), $SUB);
                } else {
                    // Ein Name (oder leer): eine Zelle über beide Sub-Spalten
                    $txt = $namen ? $namen[0] : '----';
                    $cells[] = $tcell($para($txt, false, 18, 'center'), $DAYW, 2);
                }
            }
            $wtRows[] = $trow($cells, 460);
        }
        if (empty($raster)) {
            $leer = [$tcell($para('', false, 18, 'center'), $ZW)];
            foreach ($tagKey as $tag) $leer[] = $tcell($para('----', false, 18, 'center'), $DAYW, 2);
            $wtRows[] = $trow($leer, 460);
        }
        $timetable = $tbl($gridWT, $wtRows);
        $legende = $para('1* Im Klassenverband      2** äußere Differenzierung', false, 18, 'left', 0);

        // --- Stundentafel (rechts) mit Bereichen: Pflichtfächer, Wahlpflichtbereich, Wahlfächer ---
        $SF = 1800; $S1 = 560; $S2 = 560; $SS = 700; $STW = $SF + $S1 + $S2 + $SS;

        $stRows = [$trow([$tcell($para('Stundentafel für die Klasse ' . $klasse['name'], true, 20, 'center'), $STW, 4, 'D9D9D9')], 320)];
        // Spaltenkopf
        $stRows[] = $trow([
            $tcell($para('Fach', true, 18, 'left'), $SF, 1, 'EFEFEF'),
            $tcell($para('1*', true, 18, 'center'), $S1, 1, 'EFEFEF'),
            $tcell($para('2**', true, 18, 'center'), $S2, 1, 'EFEFEF'),
            $tcell($para('Std.-zahl', true, 18, 'center'), $SS, 1, 'EFEFEF'),
        ], 300);

        // Bereichs-Überschrift (grau) und Leerzeile (zum händischen Ausfüllen)
        $bereich = function ($titel) use ($trow, $tcell, $para, $SF, $S1, $S2, $SS) {
            return $trow([
                $tcell($para($titel, true, 18, 'left'), $SF, 1, 'EFEFEF'),
                $tcell($para(''), $S1, 1, 'EFEFEF'),
                $tcell($para(''), $S2, 1, 'EFEFEF'),
                $tcell($para(''), $SS, 1, 'EFEFEF'),
            ], 300);
        };
        $leer = function () use ($trow, $tcell, $para, $SF, $S1, $S2, $SS) {
            return $trow([$tcell($para(''), $SF), $tcell($para(''), $S1), $tcell($para(''), $S2), $tcell($para(''), $SS)], 300);
        };

        // Bereich 1: Pflichtfächer (aus DB)
        $stRows[] = $bereich('Pflichtfächer');
        foreach ($tafel as $t) {
            $stRows[] = $trow([
                $tcell($para($t['fach'], false, 18, 'left'), $SF),
                $tcell($para($t['v'] > 0 ? $fmtNum($t['v']) : '', false, 18, 'center'), $S1),
                $tcell($para($t['d'] > 0 ? $fmtNum($t['d']) : '', false, 18, 'center'), $S2),
                $tcell($para($fmtNum($t['v'] + $t['d']), false, 18, 'center'), $SS),
            ], 280);
        }

        // Freiraum-Zeilen auf die Höhe des Wochenrasters verteilen. Wahlpflichtbereich
        // und Wahlfächer werden nach dem Export händisch ausgefüllt und bekommen daher
        // leere Zeilen. Überzähliger Platz geht an die Pflichtfächer (wie im Original).
        $emptyPflicht = 3; $emptyWahlpflicht = 3; $emptyWahlfaecher = 2;
        $ttMin = 320 + max(1, count($raster)) * 460;
        $fixH  = 320 + 300 + 300 + count($tafel) * 280 + 300 + 300 + 300; // Titel,Kopf,Pflicht-Label,Fächer,Wahlpfl-Label,Wahlf-Label,Summe
        $rest = (int)floor(($ttMin - $fixH) / 300) - ($emptyPflicht + $emptyWahlpflicht + $emptyWahlfaecher);
        if ($rest > 0) $emptyPflicht += $rest;

        for ($i = 0; $i < $emptyPflicht; $i++)     $stRows[] = $leer();
        $stRows[] = $bereich('Wahlpflichtbereich');
        for ($i = 0; $i < $emptyWahlpflicht; $i++) $stRows[] = $leer();
        $stRows[] = $bereich('Wahlfächer');
        for ($i = 0; $i < $emptyWahlfaecher; $i++)  $stRows[] = $leer();

        // Summe
        $stRows[] = $trow([
            $tcell($para('Wochenstunden insgesamt', true, 18, 'left'), $SF, 1, 'D9D9D9'),
            $tcell($para($fmtNum($sumV), true, 18, 'center'), $S1, 1, 'D9D9D9'),
            $tcell($para($fmtNum($sumD), true, 18, 'center'), $S2, 1, 'D9D9D9'),
            $tcell($para($fmtNum($sumV + $sumD), true, 18, 'center'), $SS, 1, 'D9D9D9'),
        ], 300);
        $stundentafel = $tbl([$SF, $S1, $S2, $SS], $stRows);

        // --- Äußere 2-Spalten-Anordnung: links Raster+Legende, rechts Stundentafel ---
        // Null-Zellränder (nomar=true), damit die inneren Tabellen ihre volle Breite
        // inkl. rechtem Rahmen behalten.
        $LCOL = 10500; $RCOL = 3786;
        $outer = $tbl([$LCOL, $RCOL], [
            $trow([
                $tcell($timetable . $legende . '<w:p/>', $LCOL, 1, null, 'top', true),
                $tcell($stundentafel . '<w:p/>', $RCOL, 1, null, 'top', true),
            ]),
        ], false);

        // --- Fuß ---
        $FW = 4762;
        $fuss = $tbl([$FW, $FW, $FW], [
            $trow([
                $tcell($para('erstellt am ' . date('d.m.y') . '  durch ' . $ersteller, false, 18), $FW),
                $tcell($para('Genehmigt am ____________  durch ____________', false, 18), $FW),
                $tcell($para('gesehen am ____________', false, 18), $FW),
            ], 300),
            $trow([
                $tcell($para('Unterschrift Klassenleiter/in: ____________', false, 18), $FW),
                $tcell($para('Unterschrift Schulleiter/Stellvertreterin: ____________', false, 18), $FW),
                $tcell($para('Regierung von Ndb. ____________', false, 18), $FW),
            ], 300),
        ], true);

        $body = $kopf . $outer . '<w:p/>' . $fuss;

        // 7. Body in die Skelett-document.xml des Templates einsetzen (Namespaces/sectPr behalten)
        $tmpl = tempnam(sys_get_temp_dir(), 'ssp');
        copy($tplPath, $tmpl);
        $zip = new ZipArchive();
        if ($zip->open($tmpl) !== true) throw new Exception("Template konnte nicht geöffnet werden");
        $docXml = $zip->getFromName('word/document.xml');
        $docOpen = '';
        if (preg_match('/<w:document[^>]*>/', $docXml, $mm)) $docOpen = $mm[0];
        $sectPr = '';
        if (preg_match('/<w:sectPr.*?<\/w:sectPr>/s', $docXml, $ms)) $sectPr = $ms[0];
        $newDoc = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\r\n"
                . $docOpen . '<w:body>' . $body . $sectPr . '</w:body></w:document>';
        $zip->deleteName('word/document.xml');
        $zip->addFromString('word/document.xml', $newDoc);
        $zip->close();

        $dateiname = 'Schuelerstundenplan_' . preg_replace('/[^A-Za-z0-9_\-]+/', '_', $klasse['name']) . '.docx';
        ob_end_clean();
        header('Content-Type: application/vnd.openxmlformats-officedocument.wordprocessingml.document');
        header('Content-Disposition: attachment; filename="' . $dateiname . '"');
        header('Content-Length: ' . filesize($tmpl));
        readfile($tmpl);
        unlink($tmpl);
        exit;

    } catch (Exception $ex) {
        if (ob_get_level()) ob_end_clean();
        http_response_code(500);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(["success" => false, "error" => "Export fehlgeschlagen", "message" => $ex->getMessage()]);
        exit;
    }
}

if ($action === 'export_raumbelegungsplan') {
    // Exportiert den Belegungsplan eines Raums als Word-Datei (.docx).
    // Kopf (Raumbelegungsplan / Schuljahr / Raumname) + Wochentabelle Mo–Fr,
    // je Zeitslot die im Raum stattfindenden Fächer/Aktivitäten. Comic Sans wie im Beispiel.
    $raum_id = isset($_GET['raumId']) ? (int)$_GET['raumId'] : (isset($_GET['raum_id']) ? (int)$_GET['raum_id'] : 0);
    if (!$raum_id) {
        echo json_encode(["success" => false, "error" => "raumId fehlt"]);
        exit;
    }

    ob_start();
    try {
        $esc = function ($v) {
            return htmlspecialchars((string)$v, ENT_QUOTES | ENT_XML1, 'UTF-8');
        };
        $hhmm = function ($t) { return substr((string)$t, 0, 5); };
        $zeitLabel = function ($s, $e) use ($hhmm) {
            return str_replace(':', '.', $hhmm($s)) . ' – ' . str_replace(':', '.', $hhmm($e));
        };

        // 1. Raum + Schule (Schuljahr)
        $stmtR = $conn->prepare("SELECT r.name, r.schuljahr_id, s.schuljahr
                                 FROM raum r LEFT JOIN schule s ON s.id = r.schuljahr_id
                                 WHERE r.id = ?");
        $stmtR->execute([$raum_id]);
        $raum = $stmtR->fetch(PDO::FETCH_ASSOC);
        if (!$raum) { ob_end_clean(); echo json_encode(["success" => false, "error" => "Raum nicht gefunden"]); exit; }

        // 2. Termine, die diesen Raum belegen (inkl. Klasse + Fach/Aktivität)
        $stmtT = $conn->prepare("SELECT t.tag, t.start, t.ende,
                                        k.name AS klasse, sf.name AS fach, a.name AS aktivitaet
                                 FROM termin_raeume tr
                                 JOIN termin t ON t.id = tr.termin_id
                                 LEFT JOIN klassen k   ON k.id  = t.klassen_id
                                 LEFT JOIN schulfach sf ON sf.id = t.schulfach_id
                                 LEFT JOIN aktivitaet a ON a.id  = t.aktivitaet_id
                                 WHERE tr.raum_id = ?
                                 ORDER BY t.start, t.ende");
        $stmtT->execute([$raum_id]);

        // Label je Termin: Klasse + Fach/Aktivität (ohne Dubletten)
        $mkLabel = function ($klasse, $fach, $akt) {
            $fa = trim((string)($fach !== '' && $fach !== null ? $fach : $akt));
            $k  = trim((string)$klasse);
            if ($k !== '' && $fa !== '' && stripos($fa, $k) === false && stripos($k, $fa) === false)
                return $k . ' ' . $fa;
            return $fa !== '' ? $fa : $k;
        };

        // Termine, die länger als eine Unterrichtseinheit (45 Min.) dauern, werden in
        // einzelne 45-Minuten-Slots zerlegt (ausgehend vom eigenen Start des Termins),
        // damit sie sich in die übrige Rasterzeile einfügen statt eine überlange
        // Sonderzeile zu erzeugen.
        $SLOT_MIN = 45;
        $toMin = function ($hm) {
            [$h, $m] = explode(':', $hm);
            return ((int)$h) * 60 + (int)$m;
        };
        $toHm = function ($min) {
            return sprintf('%02d:%02d', intdiv($min, 60), $min % 60);
        };

        $tage = ['Montag', 'Dienstag', 'Mittwoch', 'Donnerstag', 'Freitag'];
        $slots = [];      // key "s|e" => ['s'=>, 'e'=>]
        $belegung = [];   // [key][tag] = [labels]
        foreach ($stmtT->fetchAll(PDO::FETCH_ASSOC) as $t) {
            if (!in_array($t['tag'], $tage, true)) continue;
            $label = $mkLabel($t['klasse'], $t['fach'], $t['aktivitaet']);
            if ($label === '') continue;

            $startMin = $toMin($hhmm($t['start']));
            $endMin   = $toMin($hhmm($t['ende']));

            if ($endMin - $startMin > $SLOT_MIN) {
                // In 45-Minuten-Slots zerlegen
                $cur = $startMin;
                while ($cur < $endMin) {
                    $chunkEnd = min($cur + $SLOT_MIN, $endMin);
                    $s = $toHm($cur); $e = $toHm($chunkEnd);
                    $key = $s . '|' . $e;
                    $slots[$key] = ['s' => $s, 'e' => $e];
                    if (!isset($belegung[$key][$t['tag']]) || !in_array($label, $belegung[$key][$t['tag']], true))
                        $belegung[$key][$t['tag']][] = $label;
                    $cur = $chunkEnd;
                }
            } else {
                $s = $hhmm($t['start']); $e = $hhmm($t['ende']);
                $key = $s . '|' . $e;
                $slots[$key] = ['s' => $s, 'e' => $e];
                if (!isset($belegung[$key][$t['tag']]) || !in_array($label, $belegung[$key][$t['tag']], true))
                    $belegung[$key][$t['tag']][] = $label;
            }
        }
        // Slots nach Startzeit sortieren
        uasort($slots, function ($a, $b) {
            return $a['s'] === $b['s'] ? strcmp($a['e'], $b['e']) : strcmp($a['s'], $b['s']);
        });

        // --- WordML-Helfer (Comic Sans wie im Beispiel) ---
        $FONT = '<w:rFonts w:ascii="Comic Sans MS" w:hAnsi="Comic Sans MS"/>';
        $run = function ($text, $bold = false, $size = 24) use ($esc, $FONT) {
            if ($text === '' || $text === null) return '';
            $b = $bold ? '<w:b/>' : '';
            return '<w:r><w:rPr>' . $FONT . $b . '<w:sz w:val="' . $size . '"/><w:szCs w:val="' . $size . '"/></w:rPr>'
                 . '<w:t xml:space="preserve">' . $esc($text) . '</w:t></w:r>';
        };
        $para = function ($text = '', $bold = false, $size = 24, $align = 'center', $after = 0) use ($run, $FONT) {
            $b = $bold ? '<w:b/>' : '';
            return '<w:p><w:pPr><w:spacing w:after="' . $after . '"/><w:jc w:val="' . $align . '"/>'
                 . '<w:rPr>' . $FONT . $b . '<w:sz w:val="' . $size . '"/><w:szCs w:val="' . $size . '"/></w:rPr></w:pPr>'
                 . $run($text, $bold, $size) . '</w:p>';
        };
        $tcell = function ($inner, $w, $shade = null, $valign = 'center') {
            $pr = '<w:tcW w:w="' . $w . '" w:type="dxa"/>';
            if ($shade) $pr .= '<w:shd w:val="clear" w:color="auto" w:fill="' . $shade . '"/>';
            $pr .= '<w:vAlign w:val="' . $valign . '"/>';
            return '<w:tc><w:tcPr>' . $pr . '</w:tcPr>' . ($inner !== '' ? $inner : '<w:p/>') . '</w:tc>';
        };
        $trow = function ($cells, $h = null) {
            $pr = $h ? '<w:trPr><w:trHeight w:val="' . $h . '"/></w:trPr>' : '';
            return '<w:tr>' . $pr . implode('', $cells) . '</w:tr>';
        };
        $tbl = function ($grid, $rows) {
            $cols = '';
            foreach ($grid as $w) $cols .= '<w:gridCol w:w="' . $w . '"/>';
            $bd = '';
            foreach (['top','left','bottom','right','insideH','insideV'] as $s)
                $bd .= '<w:' . $s . ' w:val="single" w:sz="4" w:space="0" w:color="auto"/>';
            $pr = '<w:tblPr><w:tblW w:w="0" w:type="auto"/><w:tblBorders>' . $bd . '</w:tblBorders>'
                . '<w:tblLayout w:type="fixed"/><w:tblLook w:val="04A0"/></w:tblPr>';
            return '<w:tbl>' . $pr . '<w:tblGrid>' . $cols . '</w:tblGrid>' . implode('', $rows) . '</w:tbl>';
        };

        // --- Kopf ---
        $kopf  = $para('Raumbelegungsplan', true, 40, 'center', 60);
        $kopf .= $para('für das Schuljahr ' . $raum['schuljahr'] . '          ' . $raum['name'], true, 30, 'center', 160);

        // --- Wochentabelle (Stunde + Mo–Fr) ---
        $SW = 2286; $DW = 2400; // Stunde + 5 Tage = 2286 + 12000 = 14286
        $grid = [$SW, $DW, $DW, $DW, $DW, $DW];
        $head = [$tcell($para('Stunde', true, 24, 'center'), $SW, 'D9D9D9')];
        foreach ($tage as $tagName) $head[] = $tcell($para($tagName, true, 24, 'center'), $DW, 'D9D9D9');
        $rows = [$trow($head, 420)];

        if (empty($slots)) {
            $rows[] = $trow(array_merge(
                [$tcell($para('', false, 24), $SW)],
                array_map(function () use ($tcell, $para, $DW) { return $tcell('', $DW); }, $tage)
            ), 560);
        } else {
            foreach ($slots as $key => $sl) {
                $cells = [$tcell($para($zeitLabel($sl['s'], $sl['e']), false, 24, 'center'), $SW)];
                foreach ($tage as $tag) {
                    $labels = $belegung[$key][$tag] ?? [];
                    $inner = '';
                    foreach ($labels as $lab) $inner .= $para($lab, true, 24, 'center');
                    $cells[] = $tcell($inner, $DW);
                }
                $rows[] = $trow($cells, 560);
            }
        }
        $table = $tbl($grid, $rows);

        $body = $kopf . $table;

        // --- In Skelett-document.xml einsetzen ---
        $tplPath = __DIR__ . '/raumbelegungsplan_template.docx';
        if (!file_exists($tplPath)) {
            ob_end_clean();
            echo json_encode(["success" => false, "error" => "Template fehlt: raumbelegungsplan_template.docx"]);
            exit;
        }
        $tmpl = tempnam(sys_get_temp_dir(), 'rbp');
        copy($tplPath, $tmpl);
        $zip = new ZipArchive();
        if ($zip->open($tmpl) !== true) throw new Exception("Template konnte nicht geöffnet werden");
        $docXml = $zip->getFromName('word/document.xml');
        $docOpen = '';
        if (preg_match('/<w:document[^>]*>/', $docXml, $mm)) $docOpen = $mm[0];
        $sectPr = '';
        if (preg_match('/<w:sectPr.*?<\/w:sectPr>/s', $docXml, $ms)) $sectPr = $ms[0];
        $newDoc = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\r\n"
                . $docOpen . '<w:body>' . $body . $sectPr . '</w:body></w:document>';
        $zip->deleteName('word/document.xml');
        $zip->addFromString('word/document.xml', $newDoc);
        $zip->close();

        $dateiname = 'Raumbelegungsplan_' . preg_replace('/[^A-Za-z0-9_\-]+/', '_', $raum['name']) . '.docx';
        ob_end_clean();
        header('Content-Type: application/vnd.openxmlformats-officedocument.wordprocessingml.document');
        header('Content-Disposition: attachment; filename="' . $dateiname . '"');
        header('Content-Length: ' . filesize($tmpl));
        readfile($tmpl);
        unlink($tmpl);
        exit;

    } catch (Exception $ex) {
        if (ob_get_level()) ob_end_clean();
        http_response_code(500);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(["success" => false, "error" => "Export fehlgeschlagen", "message" => $ex->getMessage()]);
        exit;
    }
}

if ($action === 'get_lehrerstundenplan') {
    $erstkraft_id = isset($_GET['erstkraft_id']) ? (int)$_GET['erstkraft_id'] : 0;
    $schuljahr_id = isset($_GET['schuljahr_id']) ? (int)$_GET['schuljahr_id'] : 0;

    // Fehler-Anzeige für PHP-Fehler (hilft bei 500ern)
    ini_set('display_errors', 0); // Im Live-Betrieb auf 0, Fehlermeldung kommt via JSON
    error_reporting(E_ALL);

    try {
        $sql = "select e.name, e.titel, e.kuerzel, e.pflichtstunden, e.ermaessigung, e.upz,
                t.id, t.tag, t.stunden_id, t.start, t.ende, t.is_differenzierung,
                k.name as klassen_name, k.id as klassen_id,
                a.id as aktivitaet_id, a.name as aktivitaet_name, a.typ as aktivitaet_typ,
                s.id as schulfach_id, s.name as schulfach_name, s.benoetigte_raeume as schulfach_benoetigte_raeume, s.farbe as schulfach_farbe,
                r.id as raum_id, r.name as raum_name, r.immer_verfuegbar as raum_immer_verfuegbar
                from erstkraft as e
                left join termin_verantwortliche as tv on tv.kraft_id = e.id
                left join termin as t on t.id = tv.termin_id
                left join aktivitaet as a on a.id = t.aktivitaet_id
                left join schulfach as s on s.id = t.schulfach_id
                left join klassen as k on k.id = t.klassen_id
                left join termin_raeume as tr on tr.termin_id = t.id
                left join raum as r on r.id = tr.raum_id
                where e.id = ? and e.schuljahr_id = ?";

        $stmt = $conn->prepare($sql);
        // Wichtig: Reihenfolge der Parameter muss exakt zu den ? im SQL passen
        $stmt->execute([$erstkraft_id, $schuljahr_id]);
        $data = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $sql2 = "SELECT
                     ls.fach_id,
                     ls.aktivitaet_id,
                     ls.soll_stunden,
                     ls.besetzung_typ,
                     e.id,
                     e.name AS lehrer_name,
                     COALESCE(s.name, a.name) AS bezeichnung
                 FROM lehrer_stundentafel AS ls
                 JOIN erstkraft AS e ON ls.erstkraft_id = e.id
                 LEFT JOIN schulfach AS s ON ls.fach_id = s.id
                 LEFT JOIN aktivitaet AS a ON ls.aktivitaet_id = a.id
                 WHERE e.id = ? AND e.schuljahr_id = ?";
        $stmt2 = $conn->prepare($sql2);
                // Wichtig: Reihenfolge der Parameter muss exakt zu den ? im SQL passen
        $stmt2->execute([$erstkraft_id, $schuljahr_id]);
        $data2 = $stmt2->fetchAll(PDO::FETCH_ASSOC);

        if ($data) {
            $resultMap = [
                'name'                => $data[0]['name'] ?? null,
                'titel'               => $data[0]['titel'] ?? null,
                'kuerzel'             => $data[0]['kuerzel'] ?? null,
                'pflichtstunden'      => $data[0]['pflichtstunden'] ?? 0,
                'ermaessigung'        => $data[0]['ermaessigung'] ?? 0,
                'upz'                 => $data[0]['upz'] ?? 0,
                'termine'             => [],
                'lehrer_stundentafel' => []
            ];

            $termineTemp = [];

            foreach ($data as $row) {
                    $tid = $row['id']; // Wir merken uns die ID des Termins
                    if (!$tid) continue;

                    if (!isset($termineTemp[$tid])) {
                        $termineTemp[$tid] = [
                            'termin_id'       => $row['id'],
                            'tag'             => $row['tag'],
                            'start'           => $row['start'],
                            'ende'            => $row['ende'],
                            'klasse'          => $row['klassen_name'],
                            'klassen_id'      => $row['klassen_id'],
                            'fach'            => $row['schulfach_name'],
                            'fach_id'         => $row['schulfach_id'],
                            'schulfach_farbe' => $row['schulfach_farbe'],
                            'schulfach_benoetigte_raeume' => $row['schulfach_benoetigte_raeume'],
                            'stunden_id'      => $row['stunden_id'],
                            'is_differenzierung' => $row['is_differenzierung'],
                            'aktivitaet'      => $row['aktivitaet_name'],
                            'aktivitaet_id'   => $row['aktivitaet_id'],
                            'raeume'          => [],
                            'raum_ids'        => [],
                            'immer_verfuegbar' => $row['raum_immer_verfuegbar'],
                        ];
                    }
                    // 2. Raum hinzufügen (nur wenn vorhanden und noch nicht für diesen Termin registriert)
                    if (!empty($row['raum_id'])) {
                            if (!in_array((int)$row['raum_id'], $termineTemp[$tid]['raum_ids'])) {
                                $termineTemp[$tid]['raeume'][]   = $row['raum_name'];
                                $termineTemp[$tid]['raum_ids'][] = (int)$row['raum_id'];
                            }
                    }
                }

            // 3. Am Ende die gruppierten Termine in das finale Resultat schieben
            $resultMap['termine'] = array_values($termineTemp);

            if($data2) {
                foreach ($data2 as $row2) {
                    $resultMap['lehrer_stundentafel'][] = [
                        'fach_id'       => $row2['fach_id'],
                        'aktivitaet_id' => $row2['aktivitaet_id'],
                        'soll_stunden'  => $row2['soll_stunden'],
                        'besetzung'     => $row2['besetzung_typ'],
                        'bezeichnung'   => $row2['bezeichnung'],
                    ];
                }
            }

            header('Content-Type: application/json; charset=utf-8');
            // WANDLE DAS ARRAY IN JSON UM:
            echo json_encode($resultMap, JSON_PRETTY_PRINT);
        } else {
            http_response_code(404);
            echo json_encode(['error' => 'Keine Daten gefunden für Kraft ID ' . $erstkraft_id]);
        }
    } catch (PDOException $e) {
        http_response_code(500);
        echo json_encode([
            'error' => 'Datenbankfehler',
            'message' => $e->getMessage(),
            'trace' => $e->getTraceAsString()
        ]);
    }
    exit;
}

if ($action === 'save_klasse') {
    $data = json_decode(file_get_contents("php://input"), true);

    $name_json = $data['klasse'];
    $schuljahr_id = $data['schuljahr_id'];

    try {
        // 1. Prüfen, ob die Kombination existiert
        $checkStmt = $conn->prepare("SELECT id FROM klassen WHERE name = :name AND schuljahr_id = :s_id");
        $checkStmt->execute([':name' => $name_json, ':s_id' => $schuljahr_id]);
        $existingKlasse = $checkStmt->fetch(PDO::FETCH_ASSOC);

        if ($existingKlasse) {
            echo json_encode(['success' => false, 'error' => 'Diese Klasse existiert bereits in diesem Schuljahr.']);
            exit; // Hier abbrechen, sonst wird das Zeitraster doppelt/falsch verarbeitet
        }

        // 2. Klasse neu anlegen
        $stmt = $conn->prepare("INSERT INTO klassen (name, schuljahr_id) VALUES (:name, :schuljahr_id)");
        $stmt->execute([
            ':name'         => $name_json,
            ':schuljahr_id' => $schuljahr_id
        ]);

        // Die ID der soeben eingefügten Klasse holen
        $klasseId = $conn->lastInsertId();

        // 3. ZEITRASTER SPEICHERN
        // (Löschen ist bei einer brandneuen Klasse eigentlich nicht nötig, schadet aber auch nicht)
        $stmtDelRaster = $conn->prepare("DELETE FROM klassen_zeitraster WHERE klasse_id = ?");
        $stmtDelRaster->execute([$klasseId]);

        $stmtInsRaster = $conn->prepare("INSERT INTO klassen_zeitraster (klasse_id, stunden_index, startzeit, endzeit) VALUES (?, ?, ?, ?)");

        $startTime = new DateTime('08:15');
        $duration = new DateInterval('PT45M');

        for ($i = 1; $i <= 10; $i++) {
            $startStr = $startTime->format('H:i');
            $startTime->add($duration);
            $endStr = $startTime->format('H:i');

            $stmtInsRaster->execute([
                $klasseId,
                $i,
                $startStr,
                $endStr
            ]);
        }

        // Erst wenn ALLES fertig ist, Erfolg melden
        echo json_encode(['success' => true, 'id' => $klasseId]);

    } catch (PDOException $e) {
        echo json_encode(['success' => false, 'error' => 'Datenbankfehler: ' . $e->getMessage()]);
    }
    exit;
}

if ($action === 'get_raum_verfuegbarkeit') {
    $schuljahr_id = isset($_GET['schuljahr_id']) ? (int)$_GET['schuljahr_id'] : 0;

    // Fehler-Anzeige für PHP-Fehler (hilft bei 500ern)
    ini_set('display_errors', 0); // Im Live-Betrieb auf 0, Fehlermeldung kommt via JSON
    error_reporting(E_ALL);

    try {
        $sql = "select
                	r.id, r.name, r.unterrichtsfach, r.immer_verfuegbar, tr.termin_id, t.start, t.ende, t.tag, t.schulfach_id, t.aktivitaet_id
                from raum as r
                left join termin_raeume as tr on tr.raum_id = r.id
                left join termin as t on tr.termin_id = t.id
                where r.schuljahr_id = ?";

        $stmt = $conn->prepare($sql);
        // Wichtig: Reihenfolge der Parameter muss exakt zu den ? im SQL passen
        $stmt->execute([$schuljahr_id]);
        $data = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $sql2 = "select r.id, r.name, rv.tag as tag, rv.startzeit as start, rv.endzeit as ende
                 from raum as r
                 left join raum_verfuegbarkeit as rv on r.id = rv.raum_id
                 where r.schuljahr_id = ?";

        $stmt2 = $conn->prepare($sql2);
        // Wichtig: Reihenfolge der Parameter muss exakt zu den ? im SQL passen
        $stmt2->execute([$schuljahr_id]);
        $data2 = $stmt2->fetchAll(PDO::FETCH_ASSOC);

        $raeume = [];

                // Schritt A: Räume und Termine gruppieren
                foreach ($data as $row) {
                    $rid = $row['id'];

                    if (!isset($raeume[$rid])) {
                        $raeume[$rid] = [
                            'id'               => $rid,
                            'name'             => $row['name'],
                            'unterrichtsfach'  => $row['unterrichtsfach'],
                            'immer_verfuegbar' => (int)$row['immer_verfuegbar'],
                            'termine'          => [],
                            'verfuegbarkeiten' => []
                        ];
                    }

                    // Termin nur hinzufügen, wenn auch einer existiert (wegen LEFT JOIN)
                    if ($row['tag'] !== null) {
                        $raeume[$rid]['termine'][] = [
                            'tag'           => $row['tag'],
                            'start'         => $row['start'],
                            'ende'          => $row['ende'],
                            'termin_id'     => $row['termin_id'],
                            'fach_id'       => $row['schulfach_id'],
                            'aktivitaet_id' => $row['aktivitaet_id']
                        ];
                    }
                }

                // Schritt B: Verfügbarkeiten den Räumen zuordnen
                foreach ($data2 as $row2) {
                    $rid = $row2['id'];
                    if (isset($raeume[$rid]) && $row2['tag'] !== null) {
                        $raeume[$rid]['verfuegbarkeiten'][] = [
                            'tag'   => $row2['tag'],
                            'start' => $row2['start'],
                            'ende'  => $row2['ende']
                        ];
                    }
                }

                header('Content-Type: application/json; charset=utf-8');
                // array_values sorgt dafür, dass wir ein sauberes JSON-Array [] bekommen, statt eines Objekts {}
                echo json_encode(array_values($raeume), JSON_PRETTY_PRINT);

            } catch (PDOException $e) {
                http_response_code(500);
                echo json_encode(['error' => 'Datenbankfehler', 'message' => $e->getMessage()]);
            }
            exit;
        }

    if ($action === 'get_klassen_verfuegbarkeit') {
        $schuljahr_id = isset($_GET['schuljahr_id']) ? (int)$_GET['schuljahr_id'] : 0;

        ini_set('display_errors', 0);
        error_reporting(E_ALL);

        try {
            // 1. Wir nutzen LEFT JOIN, damit auch Klassen ohne Termine erscheinen
            $sql = "SELECT k.id, k.name, t.id as termin_id, t.tag, t.stunden_id, t.start, t.ende,
                           COALESCE(a.name, s.name) as fach
                    FROM klassen as k
                    LEFT JOIN termin as t ON k.id = t.klassen_id
                    LEFT JOIN aktivitaet as a ON a.id = t.aktivitaet_id
                    LEFT JOIN schulfach as s ON s.id = t.schulfach_id
                    WHERE k.schuljahr_id = ?";

            $stmt = $conn->prepare($sql);
            $stmt->execute([$schuljahr_id]);
            $allKlassenData = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // 2. Zeitraster abrufen
            $stmt2 = $conn->prepare("SELECT klasse_id, stunden_index, startzeit, endzeit FROM klassen_zeitraster");
            $stmt2->execute();
            $zeitrasterData = $stmt2->fetchAll(PDO::FETCH_ASSOC);

            $klassenMap = [];

            // Schritt A: Klassen und ihre Termine gruppieren
            foreach ($allKlassenData as $row) {
                $kid = $row['id']; // Klassen-ID

                if (!isset($klassenMap[$kid])) {
                    $klassenMap[$kid] = [
                        'id'               => $kid,
                        'name'             => $row['name'],
                        'termine'          => [],
                        'verfuegbarkeiten' => []
                    ];
                }

                // Nur hinzufügen, wenn ein Termin existiert
                if ($row['termin_id'] !== null) {
                    $klassenMap[$kid]['termine'][] = [
                        'termin_id'  => $row['termin_id'],
                        'tag'        => $row['tag'],
                        'start'      => $row['start'],
                        'ende'       => $row['ende'],
                        'stunden_id' => $row['stunden_id'],
                        'fach'       => $row['fach'],
                    ];
                }
            }

            // Schritt B: Zeitraster zuordnen
            foreach ($zeitrasterData as $row2) {
                $kid = $row2['klasse_id'];
                if (isset($klassenMap[$kid])) {
                    $klassenMap[$kid]['verfuegbarkeiten'][] = [
                        'stunden_index' => $row2['stunden_index'],
                        'startzeit'     => $row2['startzeit'],
                        'endzeit'       => $row2['endzeit']
                    ];
                }
            }

            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(array_values($klassenMap), JSON_PRETTY_PRINT);

        } catch (PDOException $e) {
            http_response_code(500);
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        }
        exit;
    }

    if ($action === 'save_lehrerstundenplan') {
          $data = json_decode(file_get_contents("php://input"), true);
          $sid = $data['schuljahr_id'] ?? null;
          $lehrerId = $data['erstkraft_id'];


          try {
              $conn->beginTransaction();

            // 1. Alle aktuell in der DB vorhandenen Termin-IDs dieses Lehrers holen
            // Wir gehen davon aus, dass ein Termin über 'termin_verantwortliche' diesem Lehrer zugeordnet ist.
            $stmtOld = $conn->prepare("
                SELECT termin_id
                FROM termin_verantwortliche
                WHERE kraft_id = ? AND kraft_typ = 'erst'
            ");
            $stmtOld->execute([$lehrerId]);
            $dbIds = $stmtOld->fetchAll(PDO::FETCH_COLUMN);

            // 2. IDs sammeln, die vom Frontend gesendet wurden (nur die numerischen, bestehenden)
            $frontendIds = [];
            foreach ($data['termine'] as $t) {
                if (is_numeric($t['termin_id'])) {
                    $frontendIds[] = (int)$t['termin_id'];
                }
            }

            // 2b. KONFLIKTPRÜFUNG gegen die DB, bevor gelöscht/gespeichert wird.
            //     Alle bestehenden Termine dieser Lehrkraft werden ausgenommen
            //     (sie werden ersetzt oder gelöscht).
            $excludeIds = array_values(array_unique(array_merge($dbIds, $frontendIds)));
            $alleKonflikte = [];
            foreach ($data['termine'] as $t) {
                if (empty($t['tag']) || empty($t['start']) || empty($t['ende'])) continue;
                $alleKonflikte = array_merge($alleKonflikte, elli_finde_konflikte($conn, [
                    'tag' => $t['tag'],
                    'start' => $t['start'],
                    'ende' => $t['ende'],
                    'raum_ids' => $t['raum_ids'] ?? [],
                    'kraefte' => [['id' => $lehrerId, 'typ' => 'erst']],
                    'klassen_id' => $t['klassen_id'] ?? null,
                    'exclude_termin_ids' => $excludeIds,
                ]));
            }
            if (!empty($alleKonflikte)) {
                $conn->rollBack();
                echo json_encode(['success' => false, 'error' => implode("\n", array_unique($alleKonflikte))]);
                exit;
            }

            // 3. Differenz berechnen: Was ist in der DB, aber NICHT im Request? -> Löschkandidaten
            $idsToDelete = array_values(array_diff($dbIds, $frontendIds)); // <--- WICHTIG: array_values

            if (!empty($idsToDelete)) {
                // Platzhalter für IN-Statement vorbereiten (?,?,?)
                $placeholders = implode(',', array_fill(0, count($idsToDelete), '?'));

                // Zuerst Verknüpfungen lösen (falls kein ON DELETE CASCADE im SQL gesetzt ist)
                $conn->prepare("DELETE FROM termin_raeume WHERE termin_id IN ($placeholders)")->execute($idsToDelete);
                $conn->prepare("DELETE FROM termin_verantwortliche WHERE termin_id IN ($placeholders)")->execute($idsToDelete);

                // Dann die Termine selbst löschen
                $conn->prepare("DELETE FROM termin WHERE id IN ($placeholders)")->execute($idsToDelete);
            }

              // 2. Lehrer-Stundentafel aktualisieren
              // Zuerst alte Einträge löschen, dann neue einfügen
              $conn->prepare("DELETE FROM lehrer_stundentafel WHERE erstkraft_id = ?")->execute([$lehrerId]);

              $sqlStundentafel = "INSERT INTO lehrer_stundentafel (erstkraft_id, fach_id, aktivitaet_id, soll_stunden, besetzung_typ)
                                  VALUES (?, ?, ?, ?, ?)";
              $stmtStafel = $conn->prepare($sqlStundentafel);
              foreach ($data['lehrer_stundentafel'] as $zeile) {
                  $sollStunden = 0;
                  $besetzung = 'einzel';

                  // 1. Prüfung: Kommt es aus dem "Neu/Bearbeiten"-Modal?
                  // (Dort nutzt du soll_differenzierung / soll_klassenverbund)
                  if (!empty($zeile['soll_differenzierung']) && $zeile['soll_differenzierung'] > 0) {
                      $sollStunden = $zeile['soll_differenzierung'];
                      $besetzung = 'doppel';
                  }
                  elseif (!empty($zeile['soll_klassenverbund']) && $zeile['soll_klassenverbund'] > 0) {
                      $sollStunden = $zeile['soll_klassenverbund'];
                      $besetzung = 'einzel';
                  }
                  // 2. Prüfung: Sind es unveränderte Bestandsdaten aus der DB?
                  // (Laut deinem Log heißen sie dort 'soll_stunden' und 'besetzung')
                  else {
                      // Wir nehmen 'soll_stunden' oder 'soll' (als Fallback)
                      $sollStunden = $zeile['soll_stunden'] ?? $zeile['soll'] ?? 0;

                      // Wir nehmen 'besetzung' oder 'besetzung_typ'
                      $besetzung = $zeile['besetzung'] ?? $zeile['besetzung_typ'] ?? 'einzel';
                  }

                  // WICHTIG: Nur speichern, wenn wir eine ID (Fach oder Aktivität) UND Stunden haben
                  $fachId = $zeile['fach_id'] ?? null;
                  $aktId = $zeile['aktivitaet_id'] ?? null;

                  if (($fachId || $aktId) && $sollStunden > 0) {
                      $stmtStafel->execute([
                          $lehrerId,
                          $fachId,
                          $aktId,
                          $sollStunden,
                          $besetzung
                      ]);
                  }
              }

              // 3. Termine verarbeiten
              foreach ($data['termine'] as $t) {
                  $isNew = !isset($t['termin_id']) || !is_numeric($t['termin_id']);

                  if ($isNew) {
                      // Neuen Termin anlegen
                      $sqlTermin = "INSERT INTO termin (klassen_id, aktivitaet_id, schulfach_id, tag, stunden_id, start, ende, is_differenzierung)
                                    VALUES (?, ?, ?, ?, ?, ?, ?, ?)";
                      $stmtT = $conn->prepare($sqlTermin);
                      $stmtT->execute([
                          $t['klassen_id'], $t['aktivitaet_id'], $t['fach_id'],
                          $t['tag'], $t['stunden_id'], $t['start'], $t['ende'], $t['is_differenzierung']
                      ]);
                      $currentTerminId = $conn->lastInsertId();
                  } else {
                      // Bestehenden Termin aktualisieren
                      $currentTerminId = $t['termin_id'];
                      $sqlTermin = "UPDATE termin SET
                                      klassen_id = ?, aktivitaet_id = ?, schulfach_id = ?,
                                      tag = ?, stunden_id = ?, start = ?, ende = ?, is_differenzierung = ?
                                    WHERE id = ?";
                      $conn->prepare($sqlTermin)->execute([
                          $t['klassen_id'], $t['aktivitaet_id'], $t['fach_id'],
                          $t['tag'], $t['stunden_id'], $t['start'], $t['ende'], $t['is_differenzierung'],
                          $currentTerminId
                      ]);
                  }

                  // Räume verknüpfen (termin_raeume)
                  // Erst alle alten Räume für diesen Termin entfernen
                  $conn->prepare("DELETE FROM termin_raeume WHERE termin_id = ?")->execute([$currentTerminId]);
                  if (!empty($t['raum_ids'])) {
                      $stmtR = $conn->prepare("INSERT INTO termin_raeume (termin_id, raum_id) VALUES (?, ?)");
                      foreach ($t['raum_ids'] as $rid) {
                          $stmtR->execute([$currentTerminId, $rid]);
                      }
                  }

                  // Verantwortlichkeit setzen (termin_verantwortliche) [cite: 145, 147, 149, 150]
                  // Sicherstellen, dass die Lehrkraft als 'erst' Kraft eingetragen ist
                  $conn->prepare("DELETE FROM termin_verantwortliche WHERE termin_id = ? AND kraft_id = ?")->execute([$currentTerminId, $lehrerId]);
                  $sqlVerant = "INSERT INTO termin_verantwortliche (termin_id, kraft_id, kraft_typ) VALUES (?, ?, 'erst')";
                  $conn->prepare($sqlVerant)->execute([$currentTerminId, $lehrerId]);
              }

              $conn->commit();
              echo json_encode(["success" => true, "message" => "Gespeichert"]);
              exit;

          } catch (Exception $e) {
              $conn->rollBack();
              echo json_encode(["success" => false, "error" => $e->getMessage()]);
              exit;
          }
      }

  if ($action === 'save_diensteinsatzplan') {
      // Speichert die IST-Termine einer Zweitkraft (Diensteinsatzplan).
      // Die SOLL-Stundentafel (zweitkraft_stundentafel) wird hier NICHT angefasst –
      // die wird ueber 'save_zweitkraft' gepflegt.
      $data          = json_decode(file_get_contents("php://input"), true);
      $zweitkraft_id = isset($data['zweitkraft_id']) ? (int)$data['zweitkraft_id'] : 0;
      $termine       = $data['termine'] ?? [];

      if (!$zweitkraft_id) {
          echo json_encode(["success" => false, "error" => "Zweitkraft-ID fehlt"]);
          exit;
      }

      try {
          $conn->beginTransaction();

          // 1. Aktuell in der DB vorhandene Termin-IDs dieser Zweitkraft ermitteln
          $stmtOld = $conn->prepare("
              SELECT termin_id
              FROM termin_verantwortliche
              WHERE kraft_id = ? AND kraft_typ = 'zweit'
          ");
          $stmtOld->execute([$zweitkraft_id]);
          $dbIds = $stmtOld->fetchAll(PDO::FETCH_COLUMN);

          // 2. Vom Frontend gesendete, bereits bestehende (numerische) IDs sammeln
          $frontendIds = [];
          foreach ($termine as $t) {
              if (isset($t['termin_id']) && is_numeric($t['termin_id'])) {
                  $frontendIds[] = (int)$t['termin_id'];
              }
          }

          // 2b. KONFLIKTPRÜFUNG gegen die DB, bevor gelöscht/gespeichert wird.
          //     Alle bestehenden Termine dieser Zweitkraft werden ausgenommen
          //     (sie werden ersetzt oder gelöscht).
          $excludeIds = array_values(array_unique(array_merge($dbIds, $frontendIds)));
          $alleKonflikte = [];
          foreach ($termine as $t) {
              if (empty($t['tag']) || empty($t['start']) || empty($t['ende'])) continue;
              $alleKonflikte = array_merge($alleKonflikte, elli_finde_konflikte($conn, [
                  'tag' => $t['tag'],
                  'start' => $t['start'],
                  'ende' => $t['ende'],
                  'raum_ids' => $t['raum_ids'] ?? [],
                  'kraefte' => [['id' => $zweitkraft_id, 'typ' => 'zweit']],
                  'klassen_id' => $t['klassen_id'] ?? null,
                  'exclude_termin_ids' => $excludeIds,
              ]));
          }
          if (!empty($alleKonflikte)) {
              $conn->rollBack();
              echo json_encode(['success' => false, 'error' => implode("\n", array_unique($alleKonflikte))]);
              exit;
          }

          // 3. Loeschkandidaten: in DB, aber nicht mehr im Request
          $idsToDelete = array_values(array_diff($dbIds, $frontendIds));
          if (!empty($idsToDelete)) {
              $placeholders = implode(',', array_fill(0, count($idsToDelete), '?'));

              // a) Zuerst nur die Verantwortlichkeit DIESER Zweitkraft entfernen
              $paramsDel = array_merge($idsToDelete, [$zweitkraft_id]);
              $conn->prepare("DELETE FROM termin_verantwortliche
                              WHERE termin_id IN ($placeholders) AND kraft_id = ? AND kraft_typ = 'zweit'")
                   ->execute($paramsDel);

              // b) Termin nur dann komplett loeschen, wenn keine andere Kraft mehr verantwortlich ist
              $stmtRest = $conn->prepare("SELECT termin_id FROM termin_verantwortliche WHERE termin_id IN ($placeholders)");
              $stmtRest->execute($idsToDelete);
              $stillReferenced = $stmtRest->fetchAll(PDO::FETCH_COLUMN);
              $orphans = array_values(array_diff($idsToDelete, $stillReferenced));

              if (!empty($orphans)) {
                  $ph2 = implode(',', array_fill(0, count($orphans), '?'));
                  $conn->prepare("DELETE FROM termin_raeume WHERE termin_id IN ($ph2)")->execute($orphans);
                  $conn->prepare("DELETE FROM termin WHERE id IN ($ph2)")->execute($orphans);
              }
          }

          // 4. Termine anlegen / aktualisieren
          foreach ($termine as $t) {
              $isNew = !isset($t['termin_id']) || !is_numeric($t['termin_id']);

              // Einsatzort wird NICHT am Termin gespeichert – er haengt fest an der
              // Aktivität (aktivitaet.einsatzort) und wird beim Lesen von dort geholt.
              $klassenId  = !empty($t['klassen_id']) ? $t['klassen_id'] : null;
              $aktId      = !empty($t['aktivitaet_id']) ? $t['aktivitaet_id'] : null;
              $stundenId  = !empty($t['stunden_id']) ? $t['stunden_id'] : null;
              $isDiff     = !empty($t['is_differenzierung']) ? 1 : 0;

              if ($isNew) {
                  $sqlTermin = "INSERT INTO termin
                                (klassen_id, aktivitaet_id, schulfach_id, tag, stunden_id, start, ende, is_differenzierung)
                                VALUES (?, ?, NULL, ?, ?, ?, ?, ?)";
                  $conn->prepare($sqlTermin)->execute([
                      $klassenId, $aktId, $t['tag'], $stundenId,
                      $t['start'], $t['ende'], $isDiff
                  ]);
                  $currentTerminId = $conn->lastInsertId();
              } else {
                  $currentTerminId = (int)$t['termin_id'];
                  $sqlTermin = "UPDATE termin SET
                                  klassen_id = ?, aktivitaet_id = ?, schulfach_id = NULL,
                                  tag = ?, stunden_id = ?, start = ?, ende = ?,
                                  is_differenzierung = ?
                                WHERE id = ?";
                  $conn->prepare($sqlTermin)->execute([
                      $klassenId, $aktId, $t['tag'], $stundenId,
                      $t['start'], $t['ende'], $isDiff,
                      $currentTerminId
                  ]);
              }

              // Raeume neu verknuepfen
              $conn->prepare("DELETE FROM termin_raeume WHERE termin_id = ?")->execute([$currentTerminId]);
              if (!empty($t['raum_ids'])) {
                  $stmtR = $conn->prepare("INSERT INTO termin_raeume (termin_id, raum_id) VALUES (?, ?)");
                  foreach ($t['raum_ids'] as $rid) {
                      if ($rid) $stmtR->execute([$currentTerminId, $rid]);
                  }
              }

              // Zweitkraft als Verantwortliche sicherstellen (kraft_typ = 'zweit')
              $conn->prepare("DELETE FROM termin_verantwortliche WHERE termin_id = ? AND kraft_id = ? AND kraft_typ = 'zweit'")
                   ->execute([$currentTerminId, $zweitkraft_id]);
              $conn->prepare("INSERT INTO termin_verantwortliche (termin_id, kraft_id, kraft_typ) VALUES (?, ?, 'zweit')")
                   ->execute([$currentTerminId, $zweitkraft_id]);
          }

          $conn->commit();
          echo json_encode(["success" => true, "message" => "Diensteinsatzplan gespeichert"]);
          exit;

      } catch (Exception $e) {
          $conn->rollBack();
          echo json_encode(["success" => false, "error" => $e->getMessage()]);
          exit;
      }
  }

  if ($action === 'export_diensteinsatzplan') {
      // Exportiert den Diensteinsatzplan einer Zweitkraft als Word-Datei (.docx).
      // Basiert auf dem Template diensteinsatzplan_template.docx (gleicher Ordner),
      // das per ${platzhalter} über PHPWord TemplateProcessor befüllt wird.
      $schuljahr_id  = isset($_GET['schuljahr_id']) ? (int)$_GET['schuljahr_id'] : 0;
      $zweitkraft_id = isset($_GET['zweitkraft_id']) ? (int)$_GET['zweitkraft_id'] : 0;

      if (!$schuljahr_id || !$zweitkraft_id) {
          echo json_encode(["success" => false, "error" => "schuljahr_id und zweitkraft_id sind Pflicht"]);
          exit;
      }

      // Notices o.Ä. dürfen die Binärdatei nicht verschmutzen
      ob_start();

      try {
          $esc = function ($v) {
              return htmlspecialchars((string)$v, ENT_QUOTES | ENT_XML1, 'UTF-8');
          };
          // Stunden im deutschen Format: 3.75 -> "3,75", 2 -> "2,0", 1.5 -> "1,5"
          $fmtStunden = function ($h) {
              $s = number_format((float)$h, 2, ',', '');
              if (substr($s, -1) === '0') $s = substr($s, 0, -1);
              return $s;
          };

          // 1. Schule (Schuljahr + Adresse als JSON {name, strasse, stadt})
          $stmtS = $conn->prepare("SELECT schuljahr, adresse FROM schule WHERE id = ?");
          $stmtS->execute([$schuljahr_id]);
          $schule = $stmtS->fetch(PDO::FETCH_ASSOC) ?: ['schuljahr' => '', 'adresse' => null];

          $adresse = json_decode($schule['adresse'] ?? '', true) ?: [];
          $nameZeilen = preg_split('/\r\n|\r|\n/', trim($adresse['name'] ?? ''));
          $schule1 = trim($nameZeilen[0] ?? '');
          $schule2 = trim(implode(' ', array_slice($nameZeilen, 1)));
          $ortTeile = array_filter([trim($adresse['strasse'] ?? ''), trim($adresse['stadt'] ?? '')]);
          $schule3 = implode(', ', $ortTeile);

          // 2. Zweitkraft
          $stmtZ = $conn->prepare("SELECT name, typ, upz, ermaessigung, grund_ermaessigung
                                   FROM zweitkraft WHERE id = ? AND schuljahr_id = ?");
          $stmtZ->execute([$zweitkraft_id, $schuljahr_id]);
          $z = $stmtZ->fetch(PDO::FETCH_ASSOC);
          if (!$z) {
              ob_end_clean();
              echo json_encode(["success" => false, "error" => "Zweitkraft nicht gefunden"]);
              exit;
          }

          // 3. Termine (Einsatzort kommt aus der Aktivität)
          $stmtT = $conn->prepare("SELECT t.tag, t.start, t.ende,
                                          k.name AS klasse, a.name AS aktivitaet, a.einsatzort
                                   FROM termin_verantwortliche tv
                                   JOIN termin t ON t.id = tv.termin_id
                                   LEFT JOIN klassen k ON k.id = t.klassen_id
                                   LEFT JOIN aktivitaet a ON a.id = t.aktivitaet_id
                                   WHERE tv.kraft_typ = 'zweit' AND tv.kraft_id = ?
                                   ORDER BY t.start");
          $stmtT->execute([$zweitkraft_id]);
          $termine = $stmtT->fetchAll(PDO::FETCH_ASSOC);

          $byTag = [];
          foreach ($termine as $t) {
              $byTag[$t['tag']][] = $t;
          }

          // 3b. Pflichtstundenmaß-Aufschlüsselung: SOLL-Stunden je Einsatzort
          //     aus zweitkraft_stundentafel, z.B. "18,75 IB Schule + 19,5 HPT".
          $stmtP = $conn->prepare("SELECT einsatzort, SUM(soll_stunden) AS summe
                                   FROM zweitkraft_stundentafel
                                   WHERE zweitkraft_id = ?
                                   GROUP BY einsatzort
                                   ORDER BY einsatzort");
          $stmtP->execute([$zweitkraft_id]);
          $pflichtTeile = [];
          foreach ($stmtP->fetchAll(PDO::FETCH_ASSOC) as $p) {
              $ort = trim((string)$p['einsatzort']);
              if ($ort === '' || (float)$p['summe'] <= 0) continue;
              $pflichtTeile[] = $fmtStunden($p['summe']) . ' ' . $ort;
          }
          // Wenn Einsatzort-SOLL vorhanden: Aufschlüsselung, sonst reines UPZ-Maß
          $pflichtText = $pflichtTeile ? implode(' + ', $pflichtTeile) : (string)$z['upz'];

          // 4. Template befüllen
          $tplPath = __DIR__ . '/diensteinsatzplan_template.docx';
          if (!file_exists($tplPath)) {
              ob_end_clean();
              echo json_encode(["success" => false, "error" => "Template fehlt: diensteinsatzplan_template.docx"]);
              exit;
          }
          $tpl = new \PhpOffice\PhpWord\TemplateProcessor($tplPath);

          $tpl->setValue('schuljahr', $esc($schule['schuljahr']));
          $tpl->setValue('zweitkraft', $esc($z['name'] . ($z['typ'] ? ', ' . $z['typ'] : '')));
          $tpl->setValue('schule1', $esc($schule1));
          $tpl->setValue('schule2', $esc($schule2));
          $tpl->setValue('schule3', $esc($schule3));
          $tpl->setValue('pflicht', $esc($pflichtText));
          $tpl->setValue('erm', $esc($z['ermaessigung']));
          $tpl->setValue('grund', $esc($z['grund_ermaessigung']));
          $tpl->setValue('erstellt', date('d.m.y'));

          // Tages-Slots: Präfix + Anzahl freier Zeilen im Template
          $slots = [
              'Montag'     => ['m', 11],
              'Dienstag'   => ['di', 10],
              'Mittwoch'   => ['mi', 10],
              'Donnerstag' => ['d', 11],
              'Freitag'    => ['f', 10],
          ];

          foreach ($slots as $tag => [$prefix, $anzahl]) {
              $liste = $byTag[$tag] ?? [];
              for ($i = 1; $i <= $anzahl; $i++) {
                  $t = $liste[$i - 1] ?? null;
                  if ($t) {
                      $zeit = substr($t['start'], 0, 5) . ' – ' . substr($t['ende'], 0, 5);
                      // Einsatzort-Spalte: Klasse + Aktivität + Einsatzort (ohne Dubletten)
                      $teile = [];
                      foreach ([$t['klasse'], $t['aktivitaet'], $t['einsatzort']] as $teil) {
                          $teil = trim((string)$teil);
                          if ($teil === '') continue;
                          $schonDrin = false;
                          foreach ($teile as $vorhanden) {
                              if (stripos($vorhanden, $teil) !== false) { $schonDrin = true; break; }
                          }
                          if (!$schonDrin) $teile[] = $teil;
                      }
                      $dauer = (strtotime($t['ende']) - strtotime($t['start'])) / 3600;
                      $tpl->setValue($prefix . $i . 'z', $esc($zeit));
                      $tpl->setValue($prefix . $i . 'e', $esc(implode(' ', $teile)));
                      $tpl->setValue($prefix . $i . 's', $dauer > 0 ? $fmtStunden($dauer) : '');
                  } else {
                      $tpl->setValue($prefix . $i . 'z', '');
                      $tpl->setValue($prefix . $i . 'e', '');
                      $tpl->setValue($prefix . $i . 's', '');
                  }
              }
          }

          // 5. Als Download ausliefern
          $tmp = tempnam(sys_get_temp_dir(), 'dep');
          $tpl->saveAs($tmp);

          $dateiname = 'Diensteinsatzplan_' . preg_replace('/[^A-Za-z0-9_\-]+/', '_', $z['name']) . '.docx';

          ob_end_clean(); // evtl. Notices verwerfen
          header('Content-Type: application/vnd.openxmlformats-officedocument.wordprocessingml.document');
          header('Content-Disposition: attachment; filename="' . $dateiname . '"');
          header('Content-Length: ' . filesize($tmp));
          readfile($tmp);
          unlink($tmp);
          exit;

      } catch (Exception $e) {
          if (ob_get_level()) ob_end_clean();
          http_response_code(500);
          header('Content-Type: application/json; charset=utf-8');
          echo json_encode(["success" => false, "error" => "Export fehlgeschlagen", "message" => $e->getMessage()]);
          exit;
      }
  }

  if ($action === 'get_diensteinsatzplan') {
      // schuljahr_id ist Pflicht. zweitkraft_id ist optional: wenn gesetzt, wird auf
      // genau eine Zweitkraft gefiltert, sonst werden ALLE Zweitkräfte des Schuljahrs geladen.
      $schuljahr_id  = isset($_GET['schuljahr_id']) ? (int)$_GET['schuljahr_id'] : 0;
      $zweitkraft_id = isset($_GET['zweitkraft_id']) ? (int)$_GET['zweitkraft_id'] : 0;

      if (!$schuljahr_id) {
          echo json_encode(["success" => false, "error" => "Schuljahr-ID fehlt"]);
          exit;
      }

      try {
          // 1. Stammdaten aller (bzw. der einen gewünschten) Zweitkräfte
          $sqlZ = "SELECT id, name, typ, kuerzel, farbe, textfarbe, ermaessigung, grund_ermaessigung, upz
                    FROM zweitkraft
                    WHERE schuljahr_id = ?" . ($zweitkraft_id ? " AND id = ?" : "") . "
                    ORDER BY name";
          $paramsZ = $zweitkraft_id ? [$schuljahr_id, $zweitkraft_id] : [$schuljahr_id];
          $stmtZ = $conn->prepare($sqlZ);
          $stmtZ->execute($paramsZ);
          $zweitkraefte = $stmtZ->fetchAll(PDO::FETCH_ASSOC);

          if (!$zweitkraefte) {
              header('Content-Type: application/json; charset=utf-8');
              echo json_encode(["success" => true, "data" => []]);
              exit;
          }

          $zIds = array_column($zweitkraefte, 'id');
          $inZ  = implode(',', array_fill(0, count($zIds), '?'));

          // 2. Alle Termine dieser Zweitkräfte (kraft_typ = 'zweit' verhindert Verwechslung mit Erstkräften)
          // Einsatzort ist fest an der Aktivität (a.einsatzort) – die termin-Tabelle
          // hat KEINE eigene einsatzort-Spalte.
          $sqlT = "SELECT
                      tv.kraft_id AS zweitkraft_id,
                      t.id AS termin_id, t.tag, t.start, t.ende, t.is_differenzierung,
                      a.einsatzort AS einsatzort,
                      k.id AS klassen_id, k.name AS klassen_name,
                      s.id AS schulfach_id, s.name AS schulfach_name, s.farbe AS schulfach_farbe,
                      a.id AS aktivitaet_id, a.name AS aktivitaet_name, a.typ AS aktivitaet_typ
                   FROM termin_verantwortliche AS tv
                   JOIN termin AS t ON t.id = tv.termin_id
                   LEFT JOIN klassen AS k ON k.id = t.klassen_id
                   LEFT JOIN schulfach AS s ON s.id = t.schulfach_id
                   LEFT JOIN aktivitaet AS a ON a.id = t.aktivitaet_id
                   WHERE tv.kraft_typ = 'zweit' AND tv.kraft_id IN ($inZ)
                   ORDER BY t.tag, t.start";
          $stmtT = $conn->prepare($sqlT);
          $stmtT->execute($zIds);
          $termineRaw = $stmtT->fetchAll(PDO::FETCH_ASSOC);

          // 3. Räume für alle betroffenen Termine in einem Rutsch nachladen (kein Kreuzprodukt)
          $terminIds = array_values(array_unique(array_column($termineRaw, 'termin_id')));
          $raeumeByTermin = [];
          if ($terminIds) {
              $inT = implode(',', array_fill(0, count($terminIds), '?'));
              $sqlR = "SELECT tr.termin_id, r.id AS raum_id, r.name AS raum_name
                       FROM termin_raeume AS tr
                       JOIN raum AS r ON r.id = tr.raum_id
                       WHERE tr.termin_id IN ($inT)";
              $stmtR = $conn->prepare($sqlR);
              $stmtR->execute($terminIds);
              foreach ($stmtR->fetchAll(PDO::FETCH_ASSOC) as $r) {
                  $raeumeByTermin[$r['termin_id']][] = ['id' => (int)$r['raum_id'], 'name' => $r['raum_name']];
              }
          }

          // 4. SOLL-Stundentafel je Aktivität/Einsatzort (zweitkraft_stundentafel) - eigene, saubere Liste
          $sqlP = "SELECT zst.zweitkraft_id, zst.einsatzort, zst.soll_stunden, zst.besetzung_typ,
                          zst.aktivitaet_id, a.name AS aktivitaet_name
                   FROM zweitkraft_stundentafel AS zst
                   LEFT JOIN aktivitaet AS a ON a.id = zst.aktivitaet_id
                   WHERE zst.zweitkraft_id IN ($inZ)";
          $stmtP = $conn->prepare($sqlP);
          $stmtP->execute($zIds);
          $stundentafelByZweitkraft = [];
          foreach ($stmtP->fetchAll(PDO::FETCH_ASSOC) as $p) {
              $stundentafelByZweitkraft[$p['zweitkraft_id']][] = [
                  'aktivitaet_id'  => $p['aktivitaet_id'] !== null ? (int)$p['aktivitaet_id'] : null,
                  'aktivitaet'     => $p['aktivitaet_name'],
                  'einsatzort'     => $p['einsatzort'],
                  'soll_stunden'   => (float)$p['soll_stunden'],
                  'besetzung_typ'  => $p['besetzung_typ']
              ];
          }

          // 5. Termine nach Zweitkraft gruppieren (Map: zweitkraft_id -> termin_id -> Termin)
          $termineByZweitkraft = [];
          foreach ($termineRaw as $row) {
              $zid = $row['zweitkraft_id'];
              $tid = $row['termin_id'];
              $termineByZweitkraft[$zid][$tid] = [
                  'termin_id'          => (int)$tid,
                  'tag'                => $row['tag'],
                  'start'              => $row['start'],
                  'ende'               => $row['ende'],
                  'is_differenzierung' => (bool)$row['is_differenzierung'],
                  'einsatzort'         => $row['einsatzort'], // NEU: direkt am Termin gespeichert
                  'klassen_id'         => $row['klassen_id'] !== null ? (int)$row['klassen_id'] : null,
                  'klasse'             => $row['klassen_name'],
                  'schulfach_id'       => $row['schulfach_id'] !== null ? (int)$row['schulfach_id'] : null,
                  'fach'               => $row['schulfach_name'],
                  'schulfach_farbe'    => $row['schulfach_farbe'],
                  'aktivitaet_id'      => $row['aktivitaet_id'] !== null ? (int)$row['aktivitaet_id'] : null,
                  'aktivitaet'         => $row['aktivitaet_name'],
                  'aktivitaet_typ'     => $row['aktivitaet_typ'],
                  'raeume'             => $raeumeByTermin[$tid] ?? []
              ];
          }

          // 6. Gesamtergebnis: jede Zweitkraft als eigene Map mit allen Infos + verschachtelter Termine-Map
          $result = [];
          foreach ($zweitkraefte as $z) {
              $zid = $z['id'];
              $result[] = [
                  'id'                          => (int)$zid,
                  'name'                        => $z['name'],
                  'typ'                         => $z['typ'],
                  'kuerzel'                     => $z['kuerzel'],
                  'farbe'                       => $z['farbe'],
                  'textfarbe'                   => $z['textfarbe'],
                  'ermaessigung'                => (int)$z['ermaessigung'],
                  'grund_ermaessigung'          => $z['grund_ermaessigung'],
                  'upz'                         => (int)$z['upz'],
                  'stundentafel'                => $stundentafelByZweitkraft[$zid] ?? [],
                  'termine'                     => array_values($termineByZweitkraft[$zid] ?? [])
              ];
          }

          header('Content-Type: application/json; charset=utf-8');
          echo json_encode(["success" => true, "data" => $result], JSON_PRETTY_PRINT);

      } catch (PDOException $e) {
          http_response_code(500);
          echo json_encode([
              "success" => false,
              "error"   => "Datenbankfehler",
              "message" => $e->getMessage()
          ]);
      }
      exit;
  }

if ($action === 'zweitkraft-einsatzorte') {
    // 1. Output-Puffer löschen, falls vorher schon was geschrieben wurde
    if (ob_get_length()) ob_end_clean();

    // 2. Sicherstellen, dass kein HTML-Content-Type gesetzt wird
    header('Content-Type: application/json; charset=utf-8');

    // 3. Alle Fehler unterdrücken (damit Notices nicht das JSON zerstören)
    error_reporting(0);

    $id = isset($_GET['schuljahr_id']) ? (int)$_GET['schuljahr_id'] : 0;

    try {
        $stmt = $conn->prepare("SELECT DISTINCT zst.einsatzort
                               FROM zweitkraft_stundentafel AS zst
                               LEFT JOIN zweitkraft AS z ON z.id = zst.zweitkraft_id
                               WHERE z.schuljahr_id = ?
                               AND zst.einsatzort IS NOT NULL");
        $stmt->execute([$id]);
        $data = $stmt->fetchAll(PDO::FETCH_COLUMN);

        // 4. NUR das JSON ausgeben
        echo json_encode($data);

        // 5. WICHTIG: Das Skript sofort hier beenden,
        // damit kein restlicher Code (Footer/HTML) angehängt wird
        exit;
    } catch (Exception $e) {
        // Bei Fehler ein valides JSON-Fehlerobjekt senden
        echo json_encode(['error' => $e->getMessage()]);
        exit;
    }
}


?>
