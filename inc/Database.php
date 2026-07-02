<?php
declare(strict_types=1);

final class Database
{
    private ?PDO $pdo = null;

    public function pdo(): PDO
    {
        if ($this->pdo instanceof PDO) {
            return $this->pdo;
        }
        if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
            throw new RuntimeException('PHP extension pdo_sqlite is missing.');
        }
        if (!is_dir(KW_DATA)) {
            mkdir(KW_DATA, 0775, true);
        }
        $this->pdo = new PDO('sqlite:' . KW_DB);
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->exec('PRAGMA foreign_keys = ON');
        $this->migrate();
        return $this->pdo;
    }

    private function migrate(): void
    {
        $this->pdo?->exec("
            CREATE TABLE IF NOT EXISTS apps (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                name TEXT NOT NULL,
                category TEXT NOT NULL DEFAULT '',
                enabled INTEGER NOT NULL DEFAULT 1,
                download_url_template TEXT NOT NULL DEFAULT '{url}',
                target_path TEXT NOT NULL DEFAULT '',
                target_type TEXT NOT NULL DEFAULT 'file',
                beta_policy TEXT NOT NULL DEFAULT 'default',
                update_mode TEXT NOT NULL DEFAULT 'download',
                prevent_parallel_downloads INTEGER NOT NULL DEFAULT 0,
                check_only INTEGER NOT NULL DEFAULT 0,
                http_referer TEXT NOT NULL DEFAULT '',
                http_user_agent TEXT NOT NULL DEFAULT '',
                ignore_missing_file INTEGER NOT NULL DEFAULT 0,
                change_indicator TEXT NOT NULL DEFAULT 'version',
                website TEXT NOT NULL DEFAULT '',
                notes TEXT NOT NULL DEFAULT '',
                command_enabled INTEGER NOT NULL DEFAULT 0,
                command_script TEXT NOT NULL DEFAULT '',
                current_version TEXT NOT NULL DEFAULT '',
                current_download_url TEXT NOT NULL DEFAULT '',
                current_target_path TEXT NOT NULL DEFAULT '',
                last_checked TEXT,
                last_updated TEXT,
                status TEXT NOT NULL DEFAULT 'new',
                error TEXT NOT NULL DEFAULT '',
                download_bytes INTEGER NOT NULL DEFAULT 0,
                download_total INTEGER NOT NULL DEFAULT 0,
                download_updated_at TEXT,
                created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
            );
            CREATE TABLE IF NOT EXISTS variables (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                app_id INTEGER NOT NULL,
                name TEXT NOT NULL,
                kind TEXT NOT NULL DEFAULT 'regex',
                url TEXT NOT NULL DEFAULT '',
                post_data TEXT NOT NULL DEFAULT '',
                search TEXT NOT NULL DEFAULT '',
                start_text TEXT NOT NULL DEFAULT '',
                end_text TEXT NOT NULL DEFAULT '',
                regex TEXT NOT NULL DEFAULT '',
                regex_flags TEXT NOT NULL DEFAULT 'is',
                text_value TEXT NOT NULL DEFAULT '',
                last_value TEXT NOT NULL DEFAULT '',
                last_content TEXT NOT NULL DEFAULT '',
                sort_order INTEGER NOT NULL DEFAULT 0,
                FOREIGN KEY(app_id) REFERENCES apps(id) ON DELETE CASCADE
            );
            CREATE INDEX IF NOT EXISTS idx_variables_app ON variables(app_id, sort_order, id);
            CREATE TABLE IF NOT EXISTS file_bookmarks (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                name TEXT NOT NULL,
                path TEXT NOT NULL UNIQUE,
                created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
            );
            CREATE TABLE IF NOT EXISTS command_jobs (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                app_id INTEGER NOT NULL,
                status TEXT NOT NULL DEFAULT 'pending',
                reason TEXT NOT NULL DEFAULT 'manual',
                payload_json TEXT NOT NULL DEFAULT '',
                log_file TEXT NOT NULL DEFAULT '',
                error TEXT NOT NULL DEFAULT '',
                created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
                started_at TEXT,
                finished_at TEXT,
                FOREIGN KEY(app_id) REFERENCES apps(id) ON DELETE CASCADE
            );
            CREATE INDEX IF NOT EXISTS idx_command_jobs_status ON command_jobs(status, id);
            CREATE TABLE IF NOT EXISTS command_logs (
                app_id INTEGER PRIMARY KEY,
                job_id INTEGER NOT NULL DEFAULT 0,
                status TEXT NOT NULL DEFAULT '',
                exit_code INTEGER,
                variables_json TEXT NOT NULL DEFAULT '',
                script TEXT NOT NULL DEFAULT '',
                output TEXT NOT NULL DEFAULT '',
                log_text TEXT NOT NULL DEFAULT '',
                log_json TEXT NOT NULL DEFAULT '',
                created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY(app_id) REFERENCES apps(id) ON DELETE CASCADE
            );
            CREATE TABLE IF NOT EXISTS settings (
                key TEXT PRIMARY KEY,
                value TEXT NOT NULL DEFAULT ''
            );
        ");
        $this->ensureColumn('variables', 'start_text', "TEXT NOT NULL DEFAULT ''");
        $this->ensureColumn('variables', 'end_text', "TEXT NOT NULL DEFAULT ''");
        $this->ensureColumn('variables', 'regex_flags', "TEXT NOT NULL DEFAULT 'is'");
        $this->ensureColumn('apps', 'command_enabled', "INTEGER NOT NULL DEFAULT 0");
        $this->ensureColumn('apps', 'command_script', "TEXT NOT NULL DEFAULT ''");
        $this->ensureColumn('apps', 'target_type', "TEXT NOT NULL DEFAULT 'file'");
        $this->ensureColumn('apps', 'update_mode', "TEXT NOT NULL DEFAULT 'download'");
        $this->ensureColumn('apps', 'prevent_parallel_downloads', "INTEGER NOT NULL DEFAULT 0");
        $this->ensureColumn('apps', 'check_only', "INTEGER NOT NULL DEFAULT 0");
        $this->ensureColumn('apps', 'http_referer', "TEXT NOT NULL DEFAULT ''");
        $this->ensureColumn('apps', 'http_user_agent', "TEXT NOT NULL DEFAULT ''");
        $this->ensureColumn('apps', 'ignore_missing_file', "INTEGER NOT NULL DEFAULT 0");
        $this->ensureColumn('apps', 'change_indicator', "TEXT NOT NULL DEFAULT 'version'");
        $this->ensureColumn('apps', 'website', "TEXT NOT NULL DEFAULT ''");
        $this->ensureColumn('apps', 'notes', "TEXT NOT NULL DEFAULT ''");
        $this->ensureColumn('apps', 'current_target_path', "TEXT NOT NULL DEFAULT ''");
        $this->ensureColumn('apps', 'download_bytes', "INTEGER NOT NULL DEFAULT 0");
        $this->ensureColumn('apps', 'download_total', "INTEGER NOT NULL DEFAULT 0");
        $this->ensureColumn('apps', 'download_updated_at', "TEXT");
        $this->pdo?->prepare("
            UPDATE apps
            SET target_type = 'folder'
            WHERE target_type = 'file' AND (substr(target_path, -1) = ? OR substr(target_path, -1) = ?)
        ")->execute(['/', '\\']);
    }

    private function ensureColumn(string $table, string $column, string $definition): void
    {
        $columns = $this->pdo?->query('PRAGMA table_info(' . $table . ')')->fetchAll(PDO::FETCH_ASSOC) ?: [];
        foreach ($columns as $existing) {
            if ($existing['name'] === $column) {
                return;
            }
        }
        $this->pdo?->exec('ALTER TABLE ' . $table . ' ADD COLUMN ' . $column . ' ' . $definition);
    }
}
