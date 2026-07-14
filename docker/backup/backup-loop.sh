#!/bin/sh
# Laeuft als eigener Container dauerhaft im Hintergrund und sichert die
# Datenbank in Intervallen nach /backups (auf den Host gemountet).
# Alte Backups werden rotiert (nur die neuesten BACKUP_KEEP bleiben).
#
# Gesteuert ueber Umgebungsvariablen (siehe docker-compose.yml / .env):
#   DB_HOST, DB_NAME, DB_USER, DB_PASSWORD
#   BACKUP_INTERVAL  Sekunden zwischen zwei Sicherungen
#   BACKUP_KEEP      Anzahl aufzuhebender Backup-Dateien
set -u

mkdir -p /backups
echo "[backup] Start. Intervall=${BACKUP_INTERVAL}s, behalte ${BACKUP_KEEP} Dateien."

while true; do
  ts=$(date +%Y%m%d-%H%M%S)
  out="/backups/elli-${ts}.sql"

  # Dump zuerst in eine .tmp-Datei, dann atomar umbenennen -> nie ein
  # halbfertiges Backup sichtbar. Redirection laeuft in sh (UTF-8-sicher).
  if mariadb-dump --single-transaction --no-tablespaces --default-character-set=utf8mb4 \
        -h "$DB_HOST" -u"$DB_USER" -p"$DB_PASSWORD" "$DB_NAME" > "${out}.tmp" 2>/tmp/backup-err; then
    mv "${out}.tmp" "$out"
    echo "[backup] $(date '+%F %T') ok: ${out} ($(wc -c < "$out") Bytes)"

    # Rotation: alles ausser den neuesten BACKUP_KEEP loeschen
    ls -1t /backups/elli-*.sql 2>/dev/null | tail -n +"$((BACKUP_KEEP + 1))" | while read -r old; do
      rm -f "$old"
      echo "[backup] rotiert (geloescht): $old"
    done
  else
    echo "[backup] FEHLER beim Dump: $(cat /tmp/backup-err 2>/dev/null)"
    rm -f "${out}.tmp"
  fi

  sleep "$BACKUP_INTERVAL"
done
