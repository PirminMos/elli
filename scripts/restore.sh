#!/bin/sh
# Spielt einen mit backup.sh erstellten Dump in die elli-Datenbank ein.
# ACHTUNG: ueberschreibt die aktuelle Datenbank des Ziel-Stacks komplett.
# Encoding-sicher via 'docker compose cp' (kein Shell-Umleiten der Datei).
#
# Ablauf auf dem NEUEN Server:
#   1. Repo klonen, 'docker compose up -d --build' (legt leere DB an)
#   2. ./scripts/restore.sh <backup-datei.sql>
#
# Nutzung:  ./scripts/restore.sh <backup-datei.sql> [--yes]
set -e
cd "$(dirname "$0")/.."

IN="$1"
if [ -z "$IN" ] || [ ! -f "$IN" ]; then
  echo "Nutzung: ./scripts/restore.sh <backup-datei.sql> [--yes]"
  exit 1
fi

if [ "$2" != "--yes" ]; then
  echo "[elli] ACHTUNG: Dies ueberschreibt die aktuelle elli-Datenbank dieses Stacks."
  printf "Fortfahren? (j/N) "
  read -r a
  case "$a" in
    j|J) ;;
    *) echo "Abgebrochen."; exit 0 ;;
  esac
fi

docker compose cp "$IN" db:/tmp/elli-restore.sql
docker compose exec -T db sh -c \
  'mariadb --default-character-set=utf8mb4 -u"$MARIADB_USER" -p"$MARIADB_PASSWORD" "$MARIADB_DATABASE" < /tmp/elli-restore.sql'
docker compose exec -T db rm -f /tmp/elli-restore.sql

echo "[elli] Restore abgeschlossen."
