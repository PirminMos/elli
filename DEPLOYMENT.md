# elli – Bereitstellung mit Docker

Der komplette Stack läuft in **zwei Containern**:

| Container | Inhalt |
|-----------|--------|
| `web`     | Apache + PHP 8.2 (`api.php` inkl. Composer-Abhängigkeiten) **und** das gebaute Vue-Frontend |
| `db`      | MariaDB 11 – Tabellen werden beim ersten Start automatisch angelegt |

Frontend und API laufen im selben Container unter derselben Herkunft
(`http://localhost:8080`) → **kein CORS, keine fest verdrahtete IP**.

---

## Voraussetzung (Windows)

[**Docker Desktop für Windows**](https://www.docker.com/products/docker-desktop/) installieren und starten
(nutzt WSL2 – einmalig „Enable WSL2“ bestätigen).

---

## Starten (aus diesem Ordner)

```powershell
copy .env.example .env          # optional: Passwörter in .env anpassen
docker compose up -d --build
```

Dann im Browser: **http://localhost:8080**

Weitere Befehle:

```powershell
docker compose logs -f          # Logs ansehen
docker compose down             # stoppen (DB-Daten bleiben erhalten)
docker compose down -v          # ALLES löschen inkl. Datenbank
```

Beim allerersten Start baut Docker das Frontend und installiert die
PHP-Bibliotheken – das dauert ein paar Minuten. Danach geht es sofort.

---

## Automatisch neu bauen nach `git pull`

Ein mitgelieferter Git-Hook (`.githooks/post-merge`) baut den Stack nach
jedem `git pull` automatisch neu. Einmalig pro Clone aktivieren:

```powershell
git config core.hooksPath .githooks
```

Danach genügt `git pull` – der Hook führt anschließend selbst
`docker compose up -d --build` aus. Der Hook feuert bei `git pull` (Merge
und Fast-Forward), nicht bei reinem `git fetch` oder `git pull --rebase`.
Abschalten: `git config --unset core.hooksPath`.

---

## Für andere über GitHub bereitstellen

**Variante A – bauen beim Nutzer (einfachste):**
Repo pushen. Andere machen nur:

```powershell
git clone <repo-url>
cd elli
docker compose up -d --build
```

**Variante B – fertiges Image, kein Build beim Nutzer:**
Die mitgelieferte GitHub-Action (`.github/workflows/docker-image.yml`)
baut das `web`-Image bei jedem Push auf `main` automatisch und legt es in
der GitHub Container Registry ab. In `docker-compose.yml` dann den
`build:`-Block des `web`-Service ersetzen durch:

```yaml
    image: ghcr.io/<dein-github-name>/elli-web:main
```

Andere brauchen dann nur `docker-compose.yml` + `.env` und:

```powershell
docker compose up -d
```

---

## Wichtig: Datenbank

`docker/db/init/01-schema.sql` erzeugt die **leeren Tabellen** (nur Struktur).
Das Schema wurde aus dem `describe`-Abzug rekonstruiert.

Willst du deine **vorhandenen Daten** mitliefern, ersetze die Datei durch
einen echten Dump deiner laufenden DB:

```bash
mysqldump -u elli_user -p elli > docker/db/init/01-schema.sql
```

Das Init-Skript läuft **nur bei leerem Daten-Volume** (erster Start bzw.
nach `docker compose down -v`).

---

## Daten auf einen anderen Server umziehen

Die Datenbank ist voll portabel. Für den Umzug gibt es zwei Skripte (im
Ordner `scripts/`), die encoding-sicher arbeiten (der Dump entsteht *im*
Container und wird per `docker compose cp` kopiert – so kann keine Shell,
insbesondere PowerShell unter Windows, die Datei nach UTF‑16 umkodieren
und Umlaute zerstören).

**Auf dem alten Server – Backup ziehen:**

```bash
./scripts/backup.sh                 # -> elli-backup-JJJJMMTT-HHMMSS.sql
```

Die entstandene `.sql`-Datei auf den neuen Server kopieren (USB, scp, …).

**Auf dem neuen Server – einspielen:**

```bash
git clone https://github.com/DEINUSER/elli.git
cd elli
docker compose up -d --build        # legt leere DB an
./scripts/restore.sh elli-backup-20260714-153000.sql
```

Der Restore überschreibt die (leere) Ziel-Datenbank vollständig mit dem
Dump. Danach läuft die neue Instanz mit exakt den Daten des alten Servers.

> Windows-Hinweis: Die Skripte laufen in Git Bash (kommt mit Git für
> Windows). `mariadb-dump` / `docker compose cp` erledigen Encoding und
> Dateiübertragung – bitte **nicht** `docker exec … > datei.sql` in
> PowerShell verwenden, das erzeugt UTF‑16 und beschädigt den Dump.

---

## Automatische Hintergrund-Backups

Der Stack enthält einen kleinen `backup`-Container, der **still im
Hintergrund** läuft und die Datenbank in Intervallen sichert – der Nutzer
merkt davon nichts. Er startet automatisch mit `docker compose up -d`.

- Ablage: Ordner `backups/` (auf dem Host, per `.gitignore` ausgenommen)
- Dateiname: `elli-JJJJMMTT-HHMMSS.sql`
- Sofort ein Backup beim Start, danach alle `BACKUP_INTERVAL` Sekunden
- Rotation: nur die neuesten `BACKUP_KEEP` Dateien bleiben erhalten

Standardwerte (in `.env` anpassbar):

```
BACKUP_INTERVAL=21600   # alle 6 Stunden
BACKUP_KEEP=28          # 28 Dateien behalten (~1 Woche)
```

Prüfen, was der Backup-Dienst tut:

```powershell
docker compose logs -f backup
```

Ein solches Backup wieder einspielen (bei Bedarf):

```bash
./scripts/restore.sh backups/elli-20260714-202449.sql
```

Deaktivieren: den `backup`-Service-Block aus `docker-compose.yml` entfernen.
