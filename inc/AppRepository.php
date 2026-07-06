<?php
declare(strict_types=1);

final class AppRepository
{
    public function __construct(private readonly Database $database)
    {
    }

    public function all(string $sort = 'date', string $direction = 'desc'): array
    {
        $columns = [
            'application' => 'name COLLATE NOCASE',
            'updated' => 'COALESCE(last_updated, last_checked, created_at)',
            'progress' => 'status COLLATE NOCASE',
            'target' => 'COALESCE(NULLIF(current_target_path, ""), target_path) COLLATE NOCASE',
            'category' => 'category COLLATE NOCASE',
            'version' => 'current_version COLLATE NOCASE',
            'date' => 'COALESCE(last_updated, last_checked, created_at)',
        ];
        $sortSql = $columns[$sort] ?? $columns['date'];
        $directionSql = strtolower($direction) === 'asc' ? 'ASC' : 'DESC';
        $tieBreaker = $sort === 'application' ? 'id ASC' : 'name COLLATE NOCASE ASC';

        return $this->database->pdo()->query("
            SELECT a.*, (
                SELECT COUNT(*) FROM variables v WHERE v.app_id = a.id
            ) AS variable_count
            FROM apps a
            ORDER BY {$sortSql} {$directionSql}, {$tieBreaker}
        ")->fetchAll(PDO::FETCH_ASSOC);
    }

    public function enabledIds(): array
    {
        return $this->database->pdo()
            ->query('SELECT id FROM apps WHERE enabled = 1 ORDER BY name COLLATE NOCASE')
            ->fetchAll(PDO::FETCH_COLUMN);
    }

    public function find(int $id): ?array
    {
        $stmt = $this->database->pdo()->prepare('SELECT * FROM apps WHERE id = ?');
        $stmt->execute([$id]);
        $app = $stmt->fetch(PDO::FETCH_ASSOC);
        return $app ?: null;
    }

    public function findByName(string $name): ?array
    {
        $stmt = $this->database->pdo()->prepare('SELECT * FROM apps WHERE name = ? ORDER BY id LIMIT 1');
        $stmt->execute([$name]);
        $app = $stmt->fetch(PDO::FETCH_ASSOC);
        return $app ?: null;
    }

    public function variables(int $appId): array
    {
        $stmt = $this->database->pdo()->prepare('SELECT * FROM variables WHERE app_id = ? ORDER BY sort_order, id');
        $stmt->execute([$appId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function save(array $data): int
    {
        $id = (int)($data['id'] ?? 0);
        $updateMode = (string)($data['update_mode'] ?? 'download');
        $targetType = (string)($data['target_type'] ?? 'file');
        $params = [
            trim((string)($data['name'] ?? '')),
            trim((string)($data['category'] ?? '')),
            !empty($data['enabled']) ? 1 : 0,
            trim((string)($data['download_url_template'] ?? '{url}')),
            trim((string)($data['target_path'] ?? '')),
            in_array($targetType, ['file', 'folder'], true) ? $targetType : 'file',
            (string)($data['beta_policy'] ?? 'default'),
            in_array($updateMode, ['download', 'notify'], true) ? $updateMode : 'download',
            0,
            0,
            trim((string)($data['http_referer'] ?? '')),
            trim((string)($data['http_user_agent'] ?? '')),
            !empty($data['ignore_missing_file']) ? 1 : 0,
            trim((string)($data['change_indicator'] ?? 'version')) ?: 'version',
            trim((string)($data['website'] ?? '')),
            (string)($data['notes'] ?? ''),
            !empty($data['command_enabled']) ? 1 : 0,
            (string)($data['command_script'] ?? ''),
            Support::now(),
        ];
        if ($params[0] === '') {
            throw new RuntimeException('Name is missing.');
        }
        if ($id > 0) {
            $params[] = $id;
            $this->database->pdo()->prepare('
                UPDATE apps SET name = ?, category = ?, enabled = ?, download_url_template = ?,
                    target_path = ?, target_type = ?, beta_policy = ?, update_mode = ?, prevent_parallel_downloads = ?, check_only = ?,
                    http_referer = ?, http_user_agent = ?, ignore_missing_file = ?, change_indicator = ?, website = ?, notes = ?,
                    command_enabled = ?, command_script = ?, updated_at = ?
                WHERE id = ?
            ')->execute($params);
            return $id;
        }
        $this->database->pdo()->prepare('
            INSERT INTO apps (name, category, enabled, download_url_template, target_path, target_type, beta_policy, update_mode,
                prevent_parallel_downloads, check_only, http_referer, http_user_agent, ignore_missing_file, change_indicator, website, notes,
                command_enabled, command_script, updated_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ')->execute($params);
        return (int)$this->database->pdo()->lastInsertId();
    }

    public function saveVariables(int $appId, array $rows): void
    {
        $pdo = $this->database->pdo();
        $pdo->beginTransaction();
        $pdo->prepare('DELETE FROM variables WHERE app_id = ?')->execute([$appId]);
        $stmt = $pdo->prepare('
            INSERT INTO variables (app_id, name, kind, url, post_data, search, start_text, end_text, regex, regex_flags, text_value, sort_order)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ');
        $i = 0;
        foreach ($rows['name'] ?? [] as $idx => $name) {
            $name = trim((string)$name);
            if ($name === '') {
                continue;
            }
            $stmt->execute([
                $appId,
                $name,
                (string)($rows['kind'][$idx] ?? 'regex'),
                trim((string)($rows['url'][$idx] ?? '')),
                (string)($rows['post_data'][$idx] ?? ''),
                (string)($rows['search'][$idx] ?? ''),
                (string)($rows['start_text'][$idx] ?? ''),
                (string)($rows['end_text'][$idx] ?? ''),
                (string)($rows['regex'][$idx] ?? ''),
                $this->regexFlags((string)($rows['regex_flags'][$idx] ?? 'is')),
                (string)($rows['text_value'][$idx] ?? ''),
                $i++,
            ]);
        }
        $pdo->commit();
    }

    public function delete(int $id): void
    {
        $this->database->pdo()->prepare('DELETE FROM apps WHERE id = ?')->execute([$id]);
    }

    public function deleteMany(array $ids): int
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids), static fn (int $id): bool => $id > 0)));
        if ($ids === []) {
            return 0;
        }
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $this->database->pdo()->prepare('DELETE FROM apps WHERE id IN (' . $placeholders . ')');
        $stmt->execute($ids);
        return $stmt->rowCount();
    }

    public function copy(int $id): int
    {
        $app = $this->find($id);
        if (!$app) {
            throw new RuntimeException('Application not found.');
        }
        $pdo = $this->database->pdo();
        $pdo->beginTransaction();
        $pdo->prepare('
            INSERT INTO apps (name, category, enabled, download_url_template, target_path, target_type, beta_policy, update_mode,
                prevent_parallel_downloads, check_only, http_referer, http_user_agent, ignore_missing_file, change_indicator, website, notes,
                command_enabled, command_script, status, updated_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, "new", ?)
        ')->execute([
            $app['name'] . ' Copy',
            $app['category'],
            $app['enabled'],
            $app['download_url_template'],
            $app['target_path'],
            $app['target_type'] ?? 'file',
            $app['beta_policy'],
            $app['update_mode'],
            $app['prevent_parallel_downloads'] ?? 0,
            $app['check_only'] ?? 0,
            $app['http_referer'] ?? '',
            $app['http_user_agent'] ?? '',
            $app['ignore_missing_file'] ?? 0,
            $app['change_indicator'] ?? 'version',
            $app['website'] ?? '',
            $app['notes'] ?? '',
            $app['command_enabled'],
            $app['command_script'],
            Support::now(),
        ]);
        $newId = (int)$pdo->lastInsertId();
        $stmt = $pdo->prepare('
            INSERT INTO variables (app_id, name, kind, url, post_data, search, start_text, end_text, regex, regex_flags, text_value, sort_order)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ');
        foreach ($this->variables($id) as $var) {
            $stmt->execute([
                $newId,
                $var['name'],
                $var['kind'],
                $var['url'],
                $var['post_data'],
                $var['search'],
                $var['start_text'],
                $var['end_text'],
                $var['regex'],
                $var['regex_flags'] ?? 'is',
                $var['text_value'],
                $var['sort_order'],
            ]);
        }
        $pdo->commit();
        return $newId;
    }

    private function regexFlags(string $flags): string
    {
        $result = '';
        foreach (['i', 'g', 'm', 's'] as $flag) {
            if (str_contains($flags, $flag)) {
                $result .= $flag;
            }
        }
        return $result;
    }

    public function updateVariableResult(int $id, string $value, string $content): void
    {
        $this->database->pdo()
            ->prepare('UPDATE variables SET last_value = ?, last_content = ? WHERE id = ?')
            ->execute([$value, substr($content, 0, 500000), $id]);
    }

    public function markResult(int $id, string $version, string $url, string $target, string $status, bool $touchLastUpdated): void
    {
        $now = Support::now();
        $this->database->pdo()->prepare('
            UPDATE apps SET current_version = ?, current_download_url = ?, current_target_path = ?,
                last_checked = ?,
                last_updated = CASE
                    WHEN ? IN ("downloaded", "update-found") OR ? = 1 OR last_updated IS NULL OR last_updated = "" THEN ?
                    ELSE last_updated
                END,
                status = ?, error = "", download_bytes = 0, download_total = 0, download_updated_at = NULL, updated_at = ?
            WHERE id = ?
        ')->execute([$version, $url, $target, $now, $status, $touchLastUpdated ? 1 : 0, $now, $status, $now, $id]);
    }

    public function setImportedLastUpdated(int $id, string $lastUpdated): void
    {
        if ($lastUpdated === '') {
            return;
        }
        $timestamp = strtotime($lastUpdated);
        if ($timestamp === false) {
            return;
        }
        $this->database->pdo()->prepare('
            UPDATE apps SET last_updated = COALESCE(last_updated, ?), updated_at = ? WHERE id = ?
        ')->execute([date('Y-m-d H:i:s', $timestamp), Support::now(), $id]);
    }

    public function markError(int $id, string $error): void
    {
        $this->database->pdo()
            ->prepare('UPDATE apps SET last_checked = ?, status = "error", error = ?, download_bytes = 0, download_total = 0, download_updated_at = NULL, updated_at = ? WHERE id = ?')
            ->execute([Support::now(), $error, Support::now(), $id]);
    }

    public function markStatus(int $id, string $status, string $error = ''): void
    {
        $this->database->pdo()
            ->prepare('UPDATE apps SET status = ?, error = ?, updated_at = ? WHERE id = ?')
            ->execute([$status, $error, Support::now(), $id]);
    }

    public function markDownloadStarted(int $id, int $total = 0): void
    {
        $this->database->pdo()->prepare('
            UPDATE apps
            SET status = "downloading", error = "", download_bytes = 0, download_total = ?, download_updated_at = ?, updated_at = ?
            WHERE id = ?
        ')->execute([$total, Support::now(), Support::now(), $id]);
    }

    public function markDownloadProgress(int $id, int $bytes, int $total = 0): void
    {
        $this->database->pdo()->prepare('
            UPDATE apps
            SET status = "downloading", download_bytes = ?, download_total = CASE WHEN ? > 0 THEN ? ELSE download_total END,
                download_updated_at = ?, updated_at = ?
            WHERE id = ?
        ')->execute([$bytes, $total, $total, Support::now(), Support::now(), $id]);
    }

    public function progressSnapshot(): array
    {
        return $this->database->pdo()->query('
            SELECT id, status, error, download_bytes, download_total, current_version, target_path, current_target_path, last_checked, last_updated, category
            FROM apps
            ORDER BY id
        ')->fetchAll(PDO::FETCH_ASSOC);
    }

    public function enqueueCommand(int $appId, string $reason = 'manual', array $payload = []): int
    {
        $existing = $this->database->pdo()->prepare('
            SELECT id FROM command_jobs WHERE app_id = ? AND status IN ("pending", "running") ORDER BY id LIMIT 1
        ');
        $existing->execute([$appId]);
        $existingId = (int)($existing->fetchColumn() ?: 0);
        if ($existingId > 0) {
            $this->markStatus($appId, 'command pending');
            return $existingId;
        }
        $this->database->pdo()->prepare('
            INSERT INTO command_jobs (app_id, reason, payload_json, created_at)
            VALUES (?, ?, ?, ?)
        ')->execute([$appId, $reason, json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), Support::now()]);
        $this->markStatus($appId, 'command pending');
        return (int)$this->database->pdo()->lastInsertId();
    }

    public function pendingCommandJobs(int $limit = 10): array
    {
        $stmt = $this->database->pdo()->prepare('
            SELECT j.*, a.name AS app_name
            FROM command_jobs j
            JOIN apps a ON a.id = j.app_id
            WHERE j.status = "pending"
            ORDER BY j.id
            LIMIT ?
        ');
        $stmt->bindValue(1, $limit, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function claimCommandJob(int $jobId, int $appId): bool
    {
        $stmt = $this->database->pdo()->prepare('
            UPDATE command_jobs SET status = "running", started_at = ?, error = "" WHERE id = ? AND status = "pending"
        ');
        $stmt->execute([Support::now(), $jobId]);
        if ($stmt->rowCount() !== 1) {
            return false;
        }
        $this->markStatus($appId, 'command running');
        return true;
    }

    public function markCommandJobDone(int $jobId, int $appId, string $logFile): void
    {
        $this->database->pdo()->prepare('
            UPDATE command_jobs SET status = "done", finished_at = ?, log_file = ?, error = "" WHERE id = ?
        ')->execute([Support::now(), $logFile, $jobId]);
        $this->markStatus($appId, 'command ok');
    }

    public function markCommandJobFailed(int $jobId, int $appId, string $error, string $logFile = ''): void
    {
        $this->database->pdo()->prepare('
            UPDATE command_jobs SET status = "failed", finished_at = ?, log_file = ?, error = ? WHERE id = ?
        ')->execute([Support::now(), $logFile, $error, $jobId]);
        $this->markStatus($appId, 'command error', $error);
    }

    public function commandLog(int $appId): ?array
    {
        $stmt = $this->database->pdo()->prepare('SELECT * FROM command_logs WHERE app_id = ?');
        $stmt->execute([$appId]);
        $log = $stmt->fetch(PDO::FETCH_ASSOC);
        return $log ?: null;
    }

    public function saveCommandLog(int $appId, array $data): void
    {
        $this->database->pdo()->prepare('
            INSERT INTO command_logs (
                app_id, job_id, status, exit_code, variables_json, script, output, log_text, log_json, created_at, updated_at
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ON CONFLICT(app_id) DO UPDATE SET
                job_id = excluded.job_id,
                status = excluded.status,
                exit_code = excluded.exit_code,
                variables_json = excluded.variables_json,
                script = excluded.script,
                output = excluded.output,
                log_text = excluded.log_text,
                log_json = excluded.log_json,
                updated_at = excluded.updated_at
        ')->execute([
            $appId,
            (int)($data['job_id'] ?? 0),
            (string)($data['status'] ?? ''),
            array_key_exists('exit_code', $data) && $data['exit_code'] !== null ? (int)$data['exit_code'] : null,
            (string)($data['variables_json'] ?? ''),
            (string)($data['script'] ?? ''),
            (string)($data['output'] ?? ''),
            (string)($data['log_text'] ?? ''),
            (string)($data['log_json'] ?? ''),
            Support::now(),
            Support::now(),
        ]);
    }
}
