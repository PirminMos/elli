# elli unter Windows einrichten – Schritt für Schritt

Diese Anleitung richtet sich an **absolute Einsteiger**. Du brauchst kein
Vorwissen. Am Ende läuft elli auf deinem Windows-Rechner und startet
automatisch mit, wenn du den Computer einschaltest.

Plane ungefähr **30–45 Minuten** ein (das meiste ist Warten beim
Herunterladen). Du brauchst: einen Windows-10/11-Rechner und eine
Internetverbindung.

> **Kurz zur Idee:** elli besteht aus mehreren Teilen (Webseite, Datenbank).
> Ein Programm namens **Docker** verpackt alle Teile und startet sie
> gemeinsam – du musst dich um die Einzelteile nicht kümmern. **GitHub
> Desktop** lädt den elli-Programmcode auf deinen Rechner.

---

## Teil 1 – Docker Desktop installieren

Docker ist das Programm, das elli startet.

1. Öffne im Browser: **https://www.docker.com/products/docker-desktop/**
2. Klick auf **„Download for Windows"**. Es lädt eine Datei namens
   `Docker Desktop Installer.exe` herunter.
3. Öffne die heruntergeladene Datei (Doppelklick). Falls Windows fragt
   „Möchten Sie zulassen, dass diese App Änderungen vornimmt?" → **Ja**.
4. Im Installationsfenster den Haken bei **„Use WSL 2 instead of Hyper-V"**
   gesetzt lassen und auf **„OK"** / **„Install"** klicken.
5. Warte, bis die Installation fertig ist. Wenn er dich zum **Neustart**
   auffordert → Rechner neu starten.
6. Nach dem Neustart **Docker Desktop öffnen** (Startmenü → „Docker
   Desktop" eintippen → Enter).
7. Beim ersten Start:
   - Nutzungsbedingungen mit **„Accept"** bestätigen.
   - Falls er meldet, dass ein **WSL-Update** nötig ist, folge dem
     angezeigten Link/Knopf, installiere es und starte Docker Desktop erneut.
   - Ein Konto brauchst du **nicht** – falls ein Login-Fenster kommt, kannst
     du es überspringen („Continue without signing in" o. Ä.).
8. **Fertig, wenn:** unten rechts in der Taskleiste ein kleines **Wal-Symbol**
   🐳 erscheint und ruhig (nicht animiert) ist. Im Docker-Fenster steht dann
   unten links **„Engine running"**.

> Lass Docker Desktop ab jetzt einfach laufen.

---

## Teil 2 – GitHub Desktop installieren

Damit lädst du den elli-Programmcode herunter.

1. Öffne im Browser: **https://desktop.github.com/**
2. Klick auf **„Download for Windows"** und öffne die heruntergeladene Datei.
3. GitHub Desktop installiert sich von selbst und öffnet sich danach.
4. Wenn nach einem GitHub-Konto gefragt wird:
   - Hast du eins? Dann anmelden.
   - Hast du keins? Du kannst für öffentliche Projekte auch **ohne Konto**
     fortfahren (nach „Skip"/„Sign in later" suchen). Für ein **privates**
     elli-Repo musst du dich anmelden und Zugriff haben.

---

## Teil 3 – elli herunterladen (klonen)

1. In GitHub Desktop oben links: **File → Clone repository…**
2. Wechsle auf den Reiter **„URL"**.
3. Trage die Adresse des elli-Projekts ein:
   ```
   https://github.com/PirminMos/elli.git
   ```
4. Unter **„Local path"** ist ein Ordner voreingestellt (z. B.
   `C:\Users\DeinName\Documents\GitHub\elli`). Das ist in Ordnung – merke
   dir diesen Pfad.
5. Klick **„Clone"**. Der Download dauert nur wenige Sekunden.

Du hast jetzt einen Ordner `elli` mit allen Dateien auf deinem Rechner.

---

## Teil 4 – elli zum ersten Mal starten

1. In GitHub Desktop oben: **Repository → Show in Explorer**. Es öffnet sich
   der `elli`-Ordner im Datei-Explorer.
2. Klick oben in die **Adresszeile** des Explorers (dort steht der Pfad),
   tippe `powershell` und drücke **Enter**. Es öffnet sich ein blaues
   Fenster (PowerShell), das bereits im elli-Ordner steht.
3. Tippe (oder kopiere) folgenden Befehl und drücke **Enter**:
   ```powershell
   docker compose up -d --build
   ```
4. **Jetzt heißt es warten.** Beim allerersten Mal baut Docker alles
   zusammen – das dauert **einige Minuten** und es rauscht viel Text durch.
   Das ist normal. Fertig ist es, wenn unten wieder eine normale Eingabe-
   zeile erscheint und keine Fehlermeldung (rot) kam.

---

## Teil 5 – elli öffnen

Öffne deinen Browser und gehe zu:

```
http://localhost:8080
```

elli begrüßt dich und führt dich durch das **Anlegen des ersten
Schuljahres**. Danach kannst du loslegen. 🎉

---

## Teil 6 – Docker automatisch beim Systemstart starten

Damit elli nach jedem Einschalten von allein bereitsteht:

1. Öffne **Docker Desktop**.
2. Klick oben rechts auf das **Zahnrad-Symbol ⚙️** (Settings).
3. Im Bereich **„General"** den Haken setzen bei:
   **„Start Docker Desktop when you sign in to your computer"**.
4. Mit **„Apply & restart"** bestätigen.

Ab jetzt gilt: Rechner einschalten → Docker startet automatisch → elli läuft
von selbst wieder (die Container sind so eingestellt, dass sie mit Docker
mitstarten). Du musst nur noch den Browser auf `http://localhost:8080`
öffnen.

---

## Der Alltag – die wichtigsten Handgriffe

Öffne dazu wie in **Teil 4** eine PowerShell im elli-Ordner.

| Was du willst | Befehl |
|---|---|
| elli starten | `docker compose up -d` |
| elli stoppen | `docker compose down` |
| Nach einem Update neu aufbauen | `docker compose up -d --build` |

- **Deine Daten bleiben erhalten** – auch nach `docker compose down`, nach
  einem Neustart oder Update. (Nur der Zusatz `-v` würde die Datenbank
  löschen – also **niemals** `docker compose down -v` tippen, außer du willst
  wirklich alles zurücksetzen.)
- **Automatische Backups:** elli sichert die Datenbank im Hintergrund selbst.
  Die Sicherungen liegen im Ordner `backups` im elli-Verzeichnis.

### elli aktualisieren (neue Version holen)

1. In **GitHub Desktop** oben auf **„Fetch origin"** klicken. Wenn es
   danach **„Pull origin"** anzeigt, dieses klicken (holt die neue Version).
2. Danach in der PowerShell im elli-Ordner:
   ```powershell
   docker compose up -d --build
   ```

---

## Wenn etwas klemmt

- **„port is already allocated" / Port 8080 belegt:** elli (oder ein anderes
  Programm) läuft schon. Erst `docker compose down` ausführen, dann erneut
  starten.
- **Der Befehl `docker` wird nicht gefunden:** Docker Desktop läuft nicht.
  Starte es und warte auf das ruhige Wal-Symbol 🐳, dann den Befehl erneut.
- **Die Seite lädt nicht:** Kurz warten (nach dem Start braucht die Datenbank
  ein paar Sekunden) und `http://localhost:8080` neu laden. Prüfe, ob das
  Wal-Symbol läuft.
- **Beim Klonen kommt eine Zugriffsfehlermeldung:** Das elli-Repo ist evtl.
  privat – du musst in GitHub Desktop mit einem berechtigten Konto angemeldet
  sein.

Wenn du nicht weiterkommst: Schreib die genaue (rote) Fehlermeldung auf –
damit lässt sich das Problem fast immer schnell lösen.
