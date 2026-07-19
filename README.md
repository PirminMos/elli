# elli

**elli** ist eine Webanwendung zur Stunden- und Einsatzplanung an Schulen.
Sie bündelt alle Grunddaten eines Schuljahres – Lehrkräfte, Klassen, Räume,
Fächer und Aktivitäten – und erstellt daraus fertige, konfliktgeprüfte
Pläne, die sich als Word-Dokumente exportieren lassen.

## Was elli leistet

- **Basisdaten verwalten** – Erstkräfte (Lehrkräfte), Zweitkräfte,
  Klassen, Räume, Schulfächer und Aktivitäten je Schuljahr, inklusive
  Verfügbarkeiten, Zeitrastern und Soll-Stunden (Stundentafeln).
- **Pläne erstellen** – per Drag & Drop:
  - Schülerstundenpläne (je Klasse)
  - Lehrerstundenpläne (je Erstkraft)
  - Raumbelegungspläne (je Raum)
  - Diensteinsatzpläne (je Zweitkraft)
  - Gesamtplan als Überblick
- **Automatische Konfliktprüfung** – erkennt Doppelbelegungen von Räumen,
  Lehrkräften und Klassen sowie Verstöße gegen Raum-Öffnungszeiten und das
  Klassen-Zeitraster, bevor ein Termin gespeichert wird.
- **Word-Export** – jeder Plan lässt sich über hinterlegte `.docx`-Vorlagen
  als sauber formatiertes Dokument herunterladen.
- **Schuljahrverwaltung** – neue Schuljahre anlegen und auf Wunsch die
  Basisdaten des Vorjahres automatisch übernehmen.
- **Dokumenten-Import** – bestehende Dateien einlesen, um Daten zu
  übernehmen.

## Was das Projekt mitbringt

- **Docker-Setup** – der komplette Stack (Apache + PHP, MariaDB) startet
  mit einem einzigen `docker compose up`.
- **Automatische Hintergrund-Backups** – ein Sidecar-Container sichert die
  Datenbank regelmäßig und rotierend, ohne dass der Nutzer etwas tun muss.
- **Umzug & Wiederherstellung** – Skripte für vollständige, portable
  Datenbank-Dumps (`scripts/backup.sh` / `scripts/restore.sh`).
- **Auto-Deploy** – ein Git-Hook baut den Stack nach jedem `git pull`
  automatisch neu.

## Technik

- **Frontend:** Vue 3 + Vite (Single-Page-Anwendung)
- **Backend:** PHP (`src/assets/api.php`) mit PhpWord / PhpSpreadsheet
- **Datenbank:** MariaDB
- **Betrieb:** Docker Compose

## Schnellstart (Docker)

```sh
cp .env.example .env        # optional: Passwörter anpassen
docker compose up -d --build
```

Danach im Browser: **http://localhost:8080** – beim ersten Start führt ein
Assistent durch das Anlegen des ersten Schuljahres.

Ausführliche Anleitung (Bereitstellung, Backups, Datenumzug) siehe
[DEPLOYMENT.md](DEPLOYMENT.md).

## Lokale Entwicklung (Frontend)

```sh
npm install
npm run dev     # Entwicklungsserver mit Hot-Reload
npm run build   # Produktions-Build nach dist/
```
