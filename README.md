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

The detailed user guide will be available in the main menu under `Help`.

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
something is missing. The relevant `php.ini` is the one used by the runtime that
serves the web UI. Apache, PHP-FPM and CLI PHP can use different PHP
configurations.

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
`--verbose` or `-v` to print normal status lines:

```sh
/usr/bin/php /path/to/ketarinweb/cron.php --verbose
/usr/bin/php /path/to/ketarinweb/command_cron.php -v
```

Logs are always written to:

```text
data/logs/cron.log
data/logs/command-cron.log
```

## Cron Options

Check without downloading:

```sh
/usr/bin/php /path/to/ketarinweb/cron.php --check-only
```

Run only one application:

```sh
/usr/bin/php /path/to/ketarinweb/cron.php --id=79
```

Run multiple applications:

```sh
/usr/bin/php /path/to/ketarinweb/cron.php --id=79,80
/usr/bin/php /path/to/ketarinweb/cron.php 79,80
```

Force a disabled application for a one-off run:

```sh
/usr/bin/php /path/to/ketarinweb/cron.php --id=79 --force
```

Process command jobs through the main cron:

```sh
/usr/bin/php /path/to/ketarinweb/cron.php --commands
```

`cron.php` supports these switches:

- `--check-only`: detect updates, but do not download files.
- `--id=ID`: process one application.
- `--id=ID,ID`: process multiple applications.
- `ID` or `ID,ID`: shorthand for selecting applications by ID.
- `--force`: allow a selected disabled application to run once.
- `--commands`: process pending Bash command jobs instead of update checks.
- `--verbose` or `-v`: print normal status output.

`command_cron.php` supports these switches:

- `--verbose` or `-v`: print normal status output.

## Application Settings

### Application

The main tab contains:

- application name
- category
- enabled flag
- download URL template
- update behavior:
  - `Download update`
  - `Notify only`
- target file path

`Notify only` detects a changed version and sends an email if email settings are
configured, but it does not download and does not queue a command.

### Advanced Settings

The advanced tab contains:

- HTTP referer
- User-Agent
- missing-file handling
- change indicator variable

The change indicator decides which variable means "this application changed".
For most applications this is `version`. The dropdown only lists variables that
actually exist for the selected application.

### Commands

Commands are Bash scripts that run after a successful download. A command is not
started directly by the web request. Instead, KetarinWeb creates a command job in
SQLite and `command_cron.php` processes it.

Useful placeholders:

```text
{file}
{target}
{url}
{version}
{name}
```

All application variables are also available.

The latest command execution log is stored per application in SQLite and can be
viewed in the application properties under `Logs`. The JSON log can be
downloaded from that tab.

Each log contains:

- resolved variables
- Bash script
- stdout/stderr output
- exit code

Command jobs are protected against double execution. A job is claimed with an
atomic status update from `pending` to `running`; a second cron process will skip
it.

### Information

The information tab contains:

- website
- notes
- last checked
- current URL

## Variables

Variables can be used inside URL templates, target paths, other variables, and
commands:

```text
{version}
{url}
{name}
```

A common setup is:

- `version`: read a page or API and extract the version with a regular expression
- `url`: build or extract the final download URL

Variables are resolved lazily. Only variables that are actually referenced are
resolved. Old imported variables do not break an application unless they are used.

## Variable Functions

Ketarin-style functions are supported. A detailed guide with examples will be
available in the application under `Help`.

## Importing Ketarin XML

Use `Import...` in the toolbar to import a Ketarin XML export.

Imported applications are disabled by default. This avoids accidental failures
when a Windows target path such as `C:\...` or `O:\...` is imported on Linux.

After importing:

1. Review variables.
2. Fix target paths.
3. Choose the change indicator.
4. Enable the application.

## Email Notifications

Configure email in:

```text
File > Settings > Emailsettings
```

Available settings:

- recipient address
- sender address
- sender name

Sending uses PHP's default `mail()` configuration. Configure your server's MTA or
PHP mail settings accordingly.

Emails are sent when enabled applications return `update-found` or `downloaded`.

## File Browser

Target paths can be selected with the built-in file browser. It supports
bookmarks for frequently used directories.

The click behavior is configurable in:

```text
File > Settings > Files
```

Options:

- single click
- double click

## Data Layout

```text
data/
  ketarinweb.sqlite
  logs/
    cron.log
    command-cron.log
```

The configured download targets may be outside the application directory.

## Notes

- Only enabled applications are processed by normal cron runs.
- Disabled applications can be run once with `--force`.
- `last_checked` changes on every successful check.
- `last_updated` changes only when an update is detected/downloaded, or when it
  was empty and the first successful check initializes it.
- The web UI polls progress while downloads are running.

## Author

PapaZivi <papazivi@papazivi.de>

## Acknowledgements

Special thanks to the author and contributors of Ketarin. Without Ketarin and
its ideas, this project would not have been possible.

## License

KetarinWeb is licensed under the GNU General Public License v2.0.
