# KetarinWeb

KetarinWeb ist eine kleine PHP/SQLite-Webanwendung, die Webseiten oder APIs auf
neue Programmversionen prüft, Downloads an definierte Ziele speichert und nach
einem erfolgreichen Download optional ein Bash-Script ausführt.

Die Anwendung ist von Ketarin inspiriert, läuft aber mit einem PHP-fähigen
Webserver, SQLite und Cronjobs.

## Features

- Ketarin-ähnliche Anwendungsliste mit Status, Zielpfad, Kategorie und Version
- SQLite-Speicher, kein externer Datenbankserver nötig
- Ketarin-XML-Import
- URL- und Versionsermittlung über Variablen
- Ketarin-ähnliche Variablenfunktionen wie `regexreplace`, `replace`, `regex`,
  `tolower`, `toupper`, `filename`, `ext`, `urlencode` und weitere
- Download-Fortschritt in der Weboberfläche
- Notify-only-Modus für Versionsprüfungen ohne Download
- Bash-Command je Anwendung nach erfolgreichem Download
- Separater Command-Cron mit atomarem Job-Claiming
- E-Mail-Benachrichtigung über PHP `mail()`
- Dateibrowser mit Lesezeichen für lokale Zielpfade
- Internationalisierte Oberfläche mit JSON-Sprachdateien in `lang/`

Die ausführliche Bedienungsanleitung ist im Hauptmenü unter `Hilfe` zu finden.

## Requirements

- PHP 8.4 oder neuer
- PHP-fähiger Webserver, zum Beispiel:
  - Apache mit aktiviertem PHP
  - PHP-FPM hinter nginx
- PHP-Erweiterungen/Funktionen:
  - `pdo_sqlite`
  - `sqlite3`
  - `simplexml`
  - `session`
  - `libxml`
  - `pcre`
  - `curl` oder `allow_url_fopen=On`
  - `curl` oder `openssl` für HTTPS-Downloads
  - `proc_open` für Bash-Command-Jobs
- Schreibrechte für den Webserver-Benutzer auf:
  - `data/`
  - konfigurierte Download-Zielordner
- Bash auf dem Host, der Command-Jobs ausführt

KetarinWeb prüft benötigte PHP-Module beim Start und zeigt eine klare Meldung,
wenn etwas fehlt.

## Installation on a Web Server

Lege das Projektverzeichnis `ketarinweb` unter dem DocumentRoot deines
Webservers ab, zum Beispiel:

```text
/var/www/html/ketarinweb
```

Rufe die Anwendung im Browser auf:

```text
http://dein-host/ketarinweb/
```

Beim ersten Aufruf wird die SQLite-Datenbank automatisch angelegt:

```text
data/ketarinweb.sqlite
```

Der Webserver-Benutzer muss Schreibrechte auf `data/` haben:

```sh
chown -R www-data:www-data /pfad/zu/ketarinweb/data
chmod -R u+rwX /pfad/zu/ketarinweb/data
```

Benutzer und Gruppe je nach Distribution anpassen.

## Cron Jobs

Täglicher Update-Lauf:

```cron
0 3 * * * /usr/bin/php /pfad/zu/ketarinweb/cron.php
```

Ausstehende Bash-Commands einmal pro Minute ausführen:

```cron
* * * * * /usr/bin/php /pfad/zu/ketarinweb/command_cron.php
```

Cronjobs sind standardmäßig ruhig und geben nur bei Fehlern etwas aus. Mit
`--verbose` oder `-v` werden normale Statuszeilen ausgegeben.

## Author

PapaZivi <papazivi@papazivi.de>

## Acknowledgements

Ein besonderer Dank geht an den Autor und die Mitwirkenden von Ketarin. Ohne
Ketarin und seine Ideen wäre dieses Projekt nicht möglich gewesen.

## License

KetarinWeb steht unter der GNU General Public License v2.0.