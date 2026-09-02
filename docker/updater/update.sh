#!/bin/sh
# =====================================================================
# elli – Update-Ablauf (wird vom Update-Dienst im Hintergrund gestartet)
#
# 1. Repository pruefen   2. Neue Dateien von GitHub holen
# 3. Programm neu bauen   4. Neue Version starten
#
# Die Ausgabe landet komplett im Protokoll (/state/update.log) und wird in
# der Weboberflaeche angezeigt. Zeilen mit "==> " markieren einen neuen
# Schritt, "### FERTIG" / "### FEHLER" das Ende.
# =====================================================================
set -e

REPO="${REPO_DIR:-/repo}"
COMPOSE_DATEI="$REPO/docker-compose.yml"

schritt() { echo "==> $1"; }
# trap loeschen, damit die Fehlerzeile nicht doppelt im Protokoll steht
abbruch() { echo "### FEHLER $1"; trap - EXIT; exit 1; }

# Jeder unerwartete Fehler (set -e) endet ebenfalls mit einer Fehlerzeile,
# damit die Oberflaeche nicht ewig "laeuft" anzeigt.
trap 'code=$?; if [ "$code" -ne 0 ]; then echo "### FEHLER Abbruch mit Code $code"; fi' EXIT

echo "--- Update gestartet: $(date "+%d.%m.%Y %H:%M:%S") ---"

# ---------------------------------------------------------------------
schritt "Projekt pruefen"
[ -d "$REPO/.git" ] || abbruch "Unter /repo liegt kein Git-Projekt – bitte per 'git clone' installieren."
[ -f "$COMPOSE_DATEI" ] || abbruch "docker-compose.yml nicht gefunden."

git config --global --replace-all safe.directory "$REPO"

if [ -n "$(git -C "$REPO" status --porcelain)" ]; then
    abbruch "Es gibt lokale Aenderungen am Projekt. Das Update wuerde sie ueberschreiben und wurde deshalb abgebrochen."
fi

# Der eigene post-merge-Hook wuerde erneut 'docker compose up --build'
# starten – hier unerwuenscht, deshalb Hooks fuer diesen Aufruf abschalten.
GIT="git -C $REPO -c core.hooksPath=/nonexistent"

ZWEIG=$($GIT rev-parse --abbrev-ref HEAD)
echo "Zweig: $ZWEIG"
echo "Stand vorher: $($GIT rev-parse --short HEAD)"

# Compose-Projektname aus dem eigenen Container ableiten, damit wir genau
# den laufenden Stack anfassen und nicht versehentlich einen zweiten bauen.
PROJEKT=$(docker inspect "$(hostname)" --format '{{index .Config.Labels "com.docker.compose.project"}}' 2>/dev/null || true)
[ -n "$PROJEKT" ] || PROJEKT=elli
echo "Stack: $PROJEKT"

# .env nur mitgeben, wenn vorhanden – sonst wuerden eigene Passwoerter
# durch die Standardwerte aus der docker-compose.yml ersetzt.
if [ -f "$REPO/.env" ]; then
    COMPOSE="docker compose --env-file $REPO/.env -f $COMPOSE_DATEI -p $PROJEKT"
else
    COMPOSE="docker compose -f $COMPOSE_DATEI -p $PROJEKT"
fi

# ---------------------------------------------------------------------
schritt "Neue Dateien von GitHub holen"
$GIT fetch --prune origin
$GIT pull --ff-only
echo "Stand nachher: $($GIT rev-parse --short HEAD)"

# ---------------------------------------------------------------------
schritt "Programm neu bauen (das dauert am laengsten)"
# --progress plain: zeilenweise Ausgabe, damit man im Wartefenster sieht,
# was gerade passiert (Pakete entpacken, Abhaengigkeiten installieren ...).
$COMPOSE build --progress plain web

# ---------------------------------------------------------------------
schritt "Neue Version starten"
# --no-deps: nur der web-Container wird ersetzt. Datenbank und Backup-Dienst
# laufen unangetastet weiter – die Daten bleiben also erhalten.
$COMPOSE up -d --no-deps web

schritt "Aufraeumen"
docker image prune -f >/dev/null 2>&1 || true

echo "### FERTIG"
