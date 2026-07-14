#!/bin/sh
# Sichert die komplette elli-Datenbank (Schema + Daten) in eine .sql-Datei.
# Encoding-sicher: der Dump entsteht IM Container und wird per 'docker compose cp'
# herauskopiert - so kann keine Shell (z.B. PowerShell) die Datei nach UTF-16
# umkodieren und Umlaute zerstoeren.
#
# Nutzung:
#   ./scripts/backup.sh                 -> elli-backup-JJJJMMTT-HHMMSS.sql
#   ./scripts/backup.sh mein-backup.sql -> eigener Dateiname
set -e
cd "$(dirname "$0")/.."

OUT="${1:-elli-backup-$(date +%Y%m%d-%H%M%S).sql}"

echo "[elli] Erstelle Dump in der Datenbank..."
docker compose exec -T db sh -c \
  'mariadb-dump --single-transaction --no-tablespaces --default-character-set=utf8mb4 \
     -u"$MARIADB_USER" -p"$MARIADB_PASSWORD" "$MARIADB_DATABASE" > /tmp/elli-backup.sql'

docker compose cp db:/tmp/elli-backup.sql "$OUT"
docker compose exec -T db rm -f /tmp/elli-backup.sql

echo "[elli] Fertig: $OUT"
