# KetarinWeb

KetarinWeb is a small PHP/SQLite web application for watching software release
pages, detecting new versions, downloading installers, and optionally running a
post-download Bash command.

It is inspired by Ketarin, but designed to run with a PHP-capable web server,
SQLite and cron jobs.

## Features

- Ketarin-style application list with status, target path, category, and version
- SQLite storage, no external database server required
- Ketarin XML import
- Variable-based URL and version detection
- Ketarin-like variable functions such as `regexreplace`, `replace`, `regex`,
  `tolower`, `toupper`, `filename`, `ext`, `urlencode`, and others
- Download progress shown in the web UI
- Notify-only mode for version checks without downloading
- Per-application Bash command queued after successful downloads
- Separate command cron with atomic job claiming
- Email notification via PHP `mail()`
- File browser with bookmarks for choosing local target paths
- Internationalized user interface with JSON language files in `lang/`

The detailed user guide is available in the main menu under `Help`.

## Requirements

- PHP 8.4 or newer
- A PHP-capable web server, for example:
  - Apache with PHP enabled
  - PHP-FPM behind nginx
- PHP extensions/features:
  - `pdo_sqlite`
  - `sqlite3`
  - `simplexml`
  - `session`
  - `libxml`
  - `pcre`
  - `curl` or `allow_url_fopen=On`
  - `curl` or `openssl` for HTTPS downloads
  - `proc_open` for Bash command jobs
- Write access for the web server user to:
  - `data/`
  - configured download target directories
- Bash on the host that executes command jobs

KetarinWeb checks required PHP modules on startup and shows a clear message if
something is missing.

## Installation on a Web Server

Place the `ketarinweb` project directory under your web server document root,
for example:

```text
/var/www/html/ketarinweb
```

Open the app in your browser:

```text
http://your-host/ketarinweb/
```

On first access the SQLite database is created automatically:

```text
data/ketarinweb.sqlite
```

Make sure the web server user can write to `data/`:

```sh
chown -R www-data:www-data /path/to/ketarinweb/data
chmod -R u+rwX /path/to/ketarinweb/data
```

Adjust the user/group for your distribution.

## Cron Jobs

Run the daily update check:

```cron
0 3 * * * /usr/bin/php /path/to/ketarinweb/cron.php
```

Run queued Bash commands once per minute:

```cron
* * * * * /usr/bin/php /path/to/ketarinweb/command_cron.php
```

Cron output is quiet by default. It prints only when an error happens. Add
`--verbose` or `-v` to print normal status lines.

## Author

PapaZivi <papazivi@papazivi.de>

## Acknowledgements

Special thanks to the author and contributors of Ketarin. Without Ketarin and
its ideas, this project would not have been possible.

## License

KetarinWeb is licensed under the GNU General Public License v2.0.