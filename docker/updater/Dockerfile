# =====================================================================
# elli – Update-Dienst
#
# Kleiner PHP-Webdienst, der auf Zuruf aus dem Git-Repository aktualisiert
# und den web-Container neu baut. Er spricht dafuer ueber den Docker-Socket
# des Hosts mit dem Docker-Daemon (siehe docker-compose.yml) und liegt
# bewusst NICHT im web-Container: der wird beim Update ersetzt und koennte
# sich sonst nicht selbst neu bauen.
# =====================================================================
FROM php:8.2-cli-alpine

# docker-cli + compose/buildx-Plugin fuer den Rebuild, git fuer das Update.
RUN apk add --no-cache docker-cli docker-cli-compose docker-cli-buildx git \
    && mkdir -p /state

# Fallback, falls das Verzeichnis nicht per Bind-Mount ueberlagert wird.
# Im Normalbetrieb liegt /app auf ./docker/updater, damit Aenderungen am
# Update-Dienst selbst mit dem naechsten git pull wirksam werden.
COPY docker/updater/updater.php docker/updater/update.sh /app/

ENV REPO_DIR=/repo \
    STATE_DIR=/state

EXPOSE 8080
CMD ["php", "-S", "0.0.0.0:8080", "/app/updater.php"]
