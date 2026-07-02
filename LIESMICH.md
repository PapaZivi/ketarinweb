# KetarinWeb

KetarinWeb ist eine kleine Webanwendung als Linux-tauglicher Ersatz für
Ketarin. Sie prüft Webseiten oder APIs auf neue Programmversionen, speichert
Downloads an definierte Ziele und kann nach einem erfolgreichen Download ein
Bash-Script ausführen.

Die Anwendung läuft unter Apache mit PHP und nutzt SQLite als Datenbank.

## Was kann KetarinWeb?

- Programme in einer Ketarin-ähnlichen Liste verwalten
- Ketarin-XML importieren
- Versionen und Download-URLs über Variablen ermitteln
- Ketarin-ähnliche Variablenfunktionen nutzen
- Dateien herunterladen und Fortschritt im Frontend anzeigen
- Nur benachrichtigen, ohne Download
- Nach erfolgreichem Download Bash-Commands einplanen
- Commands über separaten Minuten-Cron ausführen
- E-Mail bei gefundenen Updates verschicken
- Lokale Zielpfade über Dateibrowser mit Bookmarks auswählen

Die ausführliche Bedienungsanleitung wird im Hauptmenü unter `Hilfe` zu
finden sein.

## Voraussetzungen

- PHP 8.4 oder neuer
- Apache mit PHP
- PHP-Erweiterungen/Funktionen:
  - `pdo_sqlite`
  - `sqlite3`
  - `simplexml`
  - `curl` oder `allow_url_fopen=On`
  - `curl` oder `openssl` für HTTPS-Downloads
- Schreibrechte für den Apache-Benutzer auf:
  - `data/`
  - alle konfigurierten Download-Zielordner
- Bash für Command-Scripte

Fehlende PHP-Module werden beim Start angezeigt.

## Installation

Das Projekt liegt als eigenes Projektverzeichnis unter dem Apache DocumentRoot,
zum Beispiel:

```text
/var/www/html/ketarinweb
```

Im Browser aufrufen:

```text
http://dein-host/ketarinweb/
```

Die SQLite-Datenbank wird automatisch angelegt:

```text
data/ketarinweb.sqlite
```

Auf Linux müssen die Schreibrechte passen, zum Beispiel:

```sh
chown -R www-data:www-data /pfad/zu/ketarinweb/data
chmod -R u+rwX /pfad/zu/ketarinweb/data
```

Benutzer und Gruppe je nach Distribution anpassen.

## Cronjobs

Täglicher Update-Lauf:

```cron
0 3 * * * /usr/bin/php /pfad/zu/ketarinweb/cron.php
```

Command-Jobs jede Minute ausführen:

```cron
* * * * * /usr/bin/php /pfad/zu/ketarinweb/command_cron.php
```

Standardmäßig geben die Cronjobs nichts aus. Ausgabe gibt es nur bei Fehlern.
Mit `--verbose` oder `-v` werden normale Statuszeilen ausgegeben:

```sh
/usr/bin/php /pfad/zu/ketarinweb/cron.php --verbose
/usr/bin/php /pfad/zu/ketarinweb/command_cron.php -v
```

Logs liegen hier:

```text
data/logs/cron.log
data/logs/command-cron.log
```

## Cron-Optionen

Nur prüfen, nichts herunterladen:

```sh
/usr/bin/php /pfad/zu/ketarinweb/cron.php --check-only
```

Nur eine App ausführen:

```sh
/usr/bin/php /pfad/zu/ketarinweb/cron.php --id=79
```

Mehrere Apps ausführen:

```sh
/usr/bin/php /pfad/zu/ketarinweb/cron.php --id=79,80
/usr/bin/php /pfad/zu/ketarinweb/cron.php 79,80
```

Eine deaktivierte App einmalig ausführen:

```sh
/usr/bin/php /pfad/zu/ketarinweb/cron.php --id=79 --force
```

Nur Command-Jobs über den Haupt-Cron ausführen:

```sh
/usr/bin/php /pfad/zu/ketarinweb/cron.php --commands
```

`cron.php` kennt diese Schalter:

- `--check-only`: Updates erkennen, aber nichts herunterladen.
- `--id=ID`: eine Anwendung ausführen.
- `--id=ID,ID`: mehrere Anwendungen ausführen.
- `ID` oder `ID,ID`: Kurzform für die Auswahl per ID.
- `--force`: eine ausgewählte deaktivierte Anwendung einmalig erlauben.
- `--commands`: ausstehende Bash-Command-Jobs statt Updatechecks abarbeiten.
- `--verbose` oder `-v`: normale Statusausgabe aktivieren.

`command_cron.php` kennt diese Schalter:

- `--verbose` oder `-v`: normale Statusausgabe aktivieren.

## Programme bearbeiten

### Application

Hier werden Name, Kategorie, Aktiv-Status, Download-URL, Update-Verhalten und
Zielpfad gesetzt.

Update-Verhalten:

- `Download update`: Update herunterladen
- `Notify only`: nur Version prüfen und E-Mail senden, kein Download

Bei `Notify only` wird kein Command eingeplant.

### Advanced settings

Hier stehen:

- HTTP Referer
- User-Agent
- fehlende Datei ignorieren
- Variable als Änderungsindikator

Der Änderungsindikator bestimmt, welche Variable als "neue Version" gilt. In
den meisten Fällen ist das `version`. Die Auswahl zeigt nur Variablen, die für
die App wirklich existieren.

### Commands

Hier kann ein Bash-Script hinterlegt werden. Es wird nicht direkt im Browser
ausgeführt. Stattdessen wird nach einem erfolgreichen Download ein Command-Job
in SQLite angelegt. `command_cron.php` arbeitet diese Jobs ab.

Platzhalter:

```text
{file}
{target}
{url}
{version}
{name}
```

Alle App-Variablen können ebenfalls verwendet werden.

Der letzte Command-Log wird je Anwendung in SQLite gespeichert und im
Eigenschaftenfenster im Reiter `Logs` angezeigt. Das JSON kann dort
heruntergeladen werden.

Ein Command-Job wird atomar von `pending` auf `running` gesetzt. Dadurch kann
ein zweiter Cronprozess denselben Job nicht nochmal starten.

### Information

Hier stehen:

- Website
- Notizen
- Last checked
- Current URL

## Variablen

Variablen werden mit geschweiften Klammern verwendet:

```text
{version}
{url}
{name}
```

Typischer Aufbau:

- `version`: Webseite/API laden und Version per Regex extrahieren
- `url`: finale Download-URL bauen oder extrahieren

Variablen werden nur dann aufgelöst, wenn sie wirklich gebraucht werden. Eine
alte importierte Variable kann also nicht stören, solange sie nicht referenziert
wird.

## Variablenfunktionen

Ketarin-ähnliche Funktionen werden unterstützt. Eine ausführliche Anleitung
mit Beispielen wird in der Anwendung unter `Hilfe` zu finden sein.

## Ketarin-Import

Ketarin-XML kann über `Import...` importiert werden.

Importierte Anwendungen sind zuerst deaktiviert. Das ist Absicht, weil Windows-
Zielpfade wie `C:\...` oder `O:\...` unter Linux nicht funktionieren.

Nach dem Import:

1. Variablen prüfen.
2. Zielpfad anpassen.
3. Änderungsindikator wählen.
4. Anwendung aktivieren.

## E-Mail

E-Mail wird konfiguriert unter:

```text
File > Settings > Emailsettings
```

Mögliche Angaben:

- Empfängeradresse
- Absenderadresse
- Absendername

Versendet wird über PHP `mail()`. Der Server muss dafür entsprechend
konfiguriert sein.

Eine E-Mail wird verschickt, wenn mindestens eine aktivierte Anwendung
`update-found` oder `downloaded` liefert.

## Dateibrowser

Der eingebaute Dateibrowser kann lokale Zielpfade auswählen und Bookmarks für
Ordner speichern.

Das Klickverhalten wird eingestellt unter:

```text
File > Settings > Files
```

Optionen:

- einfacher Klick
- Doppelklick

## Daten und Logs

```text
data/
  ketarinweb.sqlite
  logs/
    cron.log
    command-cron.log
```

Downloads können natürlich in beliebige konfigurierte Zielordner geschrieben
werden.

## Hinweise

- Normale Cronläufe verarbeiten nur aktivierte Anwendungen.
- Deaktivierte Anwendungen können einmalig mit `--force` ausgeführt werden.
- `last_checked` wird bei jedem erfolgreichen Check gesetzt.
- `last_updated` wird nur bei gefundenem Update/Download gesetzt oder einmalig
  initialisiert, wenn es noch leer ist.
- Nach einem erfolgreichen Download wird ein Command nur dann eingeplant, wenn
  Commands aktiviert und ein Bash-Script hinterlegt ist.

## Autor

PapaZivi <papazivi@papazivi.de>

## Danksagung

Ein besonderer Dank geht an den Autor und die Mitwirkenden von Ketarin. Ohne
Ketarin und seine Ideen wäre dieses Projekt nicht möglich gewesen.

## Lizenz

KetarinWeb steht unter der GNU General Public License v2.0.

