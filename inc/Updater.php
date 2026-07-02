<?php
declare(strict_types=1);

final class Updater
{
    private array $requestOptions = [];

    public function __construct(
        private readonly AppRepository $apps,
        private readonly HttpClient $http,
    ) {
    }

    public function run(int $id, bool $download = true, bool $force = false): array
    {
        $app = $this->apps->find($id);
        if (!$app) {
            throw new RuntimeException('Application not found.');
        }
        if (!$app['enabled'] && !$force) {
            return ['status' => 'skipped', 'message' => $app['name'] . ' is disabled.'];
        }
        $this->apps->markStatus((int)$app['id'], 'updating');
        try {
            $this->requestOptions = $this->requestOptions($app);
            $values = $this->resolveVariables((int)$app['id'], $this->requiredVariableRoots($app));
            $indicatorName = (string)($app['change_indicator'] ?? 'version') ?: 'version';
            $version = (string)($values[$indicatorName] ?? '');
            $notifyOnly = (string)($app['update_mode'] ?? 'download') === 'notify';
            $checkOnly = !empty($app['check_only']);
            $url = Support::replaceVariables((string)$app['download_url_template'], $values);
            $hasUrl = $url !== '' && $url !== '{url}';
            $needsDownloadTarget = !$notifyOnly && !$checkOnly && $download;
            if (!$hasUrl && $needsDownloadTarget) {
                throw new RuntimeException('No download URL resolved.');
            }
            $targetInfo = $hasUrl ? $this->resolveTargetInfo($app, $url, $values) : ['target' => (string)($app['current_target_path'] ?: $app['target_path']), 'directory' => false];
            $target = (string)$targetInfo['target'];
            $existingTarget = $targetInfo['directory'] && (string)($app['current_target_path'] ?? '') !== '' ? (string)$app['current_target_path'] : $target;
            $indicatorChanged = $version !== '' && $version !== (string)$app['current_version'];
            $missingChanged = $needsDownloadTarget && empty($app['ignore_missing_file']) && !is_file($existingTarget);
            $urlChanged = $hasUrl && $version === '' && $url !== (string)$app['current_download_url'];
            $changed = $force || $missingChanged || $indicatorChanged || $urlChanged;
            $status = $changed ? 'update-found' : 'current';
            if ($notifyOnly && $changed) {
                $status = 'update-found';
            } elseif ($checkOnly && $changed) {
                $status = 'update-found';
            } elseif ($download && $changed) {
                $target = $this->download((int)$app['id'], $url, $target, $targetInfo['directory']);
                $status = 'downloaded';
                $this->queueCommand($app, 'download', $target, $url, $version, $values);
            }
            $touchLastUpdated = $status === 'downloaded' || ($changed && ($notifyOnly || $checkOnly || !$download));
            $this->apps->markResult((int)$app['id'], $version, $url, $target, $status, $touchLastUpdated);
            return ['status' => $status, 'message' => $this->resultMessage((string)$app['name'], $status, $checkOnly || $notifyOnly || !$download), 'target' => $target, 'url' => $url, 'version' => $version];
        } catch (Throwable $e) {
            $this->apps->markError((int)$app['id'], $e->getMessage());
            throw new RuntimeException($app['name'] . ': ' . $e->getMessage(), 0, $e);
        }
    }

    public function runAll(bool $download = true, ?callable $onResult = null): array
    {
        $results = [];
        foreach ($this->apps->enabledIds() as $id) {
            try {
                $result = $this->run((int)$id, $download);
            } catch (Throwable $e) {
                $result = ['status' => 'error', 'message' => $e->getMessage()];
            }
            $results[] = $result;
            if ($onResult !== null) {
                $onResult($result);
            }
        }
        return $results;
    }

    private function resultMessage(string $name, string $status, bool $withoutDownload): string
    {
        if ($status === 'update-found' && $withoutDownload) {
            return $name . ': update found, no download';
        }
        return $name . ': ' . $status;
    }

    public function queueCommandOnly(int $id): array
    {
        $app = $this->apps->find($id);
        if (!$app) {
            throw new RuntimeException('Application not found.');
        }
        if (empty($app['command_enabled']) || trim((string)$app['command_script']) === '') {
            throw new RuntimeException('No command configured for this application.');
        }
        $jobId = $this->apps->enqueueCommand((int)$app['id'], 'manual');
        return ['status' => 'command-queued', 'message' => $app['name'] . ': command queued', 'job_id' => $jobId];
    }

    public function runQueuedCommands(int $limit = 10, ?callable $onResult = null): array
    {
        $results = [];
        foreach ($this->apps->pendingCommandJobs($limit) as $job) {
            $jobId = (int)$job['id'];
            $appId = (int)$job['app_id'];
            if (!$this->apps->claimCommandJob($jobId, $appId)) {
                continue;
            }
            try {
                $app = $this->apps->find($appId);
                if (!$app) {
                    throw new RuntimeException('Application not found.');
                }
                if (empty($app['command_enabled']) || trim((string)$app['command_script']) === '') {
                    throw new RuntimeException('No enabled command script configured.');
                }
                $payload = json_decode((string)$job['payload_json'], true);
                if (!is_array($payload)) {
                    $payload = [];
                }
                $values = $payload['values'] ?? null;
                if (!is_array($values)) {
                    $this->requestOptions = $this->requestOptions($app);
                    $values = $this->resolveVariables($appId, $this->requiredVariableRoots($app, true));
                }
                $target = (string)($payload['target'] ?? '');
                $url = (string)($payload['url'] ?? '');
                $version = (string)($payload['version'] ?? '');
                if ($url === '') {
                    $url = Support::replaceVariables((string)$app['download_url_template'], $values);
                }
                if ($version === '') {
                    $version = (string)($values['version'] ?? $app['current_version'] ?? '');
                }
                if ($target === '') {
                    $target = $this->resolveTarget($app, $url, $values);
                }
                $logFile = $this->runCommand($app, $target, $url, $version, $values, $job);
                $this->apps->markCommandJobDone($jobId, $appId, $logFile);
                $result = ['status' => 'done', 'message' => $app['name'] . ': command executed', 'job_id' => $jobId];
            } catch (Throwable $e) {
                $this->apps->markCommandJobFailed($jobId, $appId, $e->getMessage());
                $result = ['status' => 'failed', 'message' => $e->getMessage(), 'job_id' => $jobId];
            }
            $results[] = $result;
            if ($onResult !== null) {
                $onResult($result);
            }
        }
        return $results;
    }

    private function resolveVariables(int $appId, array $roots = []): array
    {
        $values = [];
        $variables = [];
        foreach ($this->apps->variables($appId) as $var) {
            $variables[$var['name']] = $var;
        }
        $names = $roots === [] ? array_keys($variables) : $roots;
        foreach (array_unique($names) as $name) {
            if (!isset($variables[$name])) {
                continue;
            }
            $this->resolveVariable($name, $variables, $values);
        }
        return $values;
    }

    private function requiredVariableRoots(array $app, bool $includeCommand = false): array
    {
        $roots = ['version'];
        $indicator = (string)($app['change_indicator'] ?? 'version');
        if ($indicator !== '') {
            $roots[] = $indicator;
        }
        $roots = array_merge($roots, $this->variableNames((string)$app['download_url_template']));
        $roots = array_merge($roots, $this->variableNames((string)$app['target_path']));
        if ($includeCommand || (!empty($app['command_enabled']) && trim((string)($app['command_script'] ?? '')) !== '')) {
            $roots = array_merge($roots, $this->variableNames((string)($app['command_script'] ?? '')));
        }
        return array_values(array_unique($roots));
    }

    private function variableNames(string $template): array
    {
        if ($template === '') {
            return [];
        }
        preg_match_all('/\{([^{}\r\n]+)\}/', $template, $matches);
        $names = [];
        foreach ($matches[1] ?? [] as $placeholder) {
            $name = explode(':', $placeholder, 2)[0];
            if (preg_match('/^[A-Za-z0-9_.-]+$/', $name)) {
                $names[] = $name;
            }
        }
        return $names;
    }

    private function requestOptions(array $app): array
    {
        return [
            'referer' => (string)($app['http_referer'] ?? ''),
            'user_agent' => (string)($app['http_user_agent'] ?? '') ?: 'KetarinWeb/1.0',
        ];
    }

    private function resolveVariable(string $name, array $variables, array &$values, array $stack = []): string
    {
        if (array_key_exists($name, $values)) {
            return (string)$values[$name];
        }
        if (!isset($variables[$name])) {
            return '{' . $name . '}';
        }
        if (isset($stack[$name])) {
            throw new RuntimeException('Circular variable: ' . implode(' -> ', array_keys($stack)) . ' -> ' . $name);
        }

        $stack[$name] = true;
        $var = $variables[$name];
        try {
            if ($var['kind'] === 'text') {
                $value = $this->replaceVariableDependencies($var['text_value'], $variables, $values, $stack);
                $content = $value;
            } else {
                $url = $this->replaceVariableDependencies($var['url'], $variables, $values, $stack);
                $postData = $this->replaceVariableDependencies($var['post_data'], $variables, $values, $stack);
                $content = $this->http->request($url, $postData, $this->requestOptions);
                if ($var['search'] !== '') {
                    $searchValues = ['content' => $content] + $values;
                    $haystack = $this->replaceVariableDependencies($var['search'], $variables, $searchValues, $stack);
                } else {
                    $haystack = $content;
                }
                $value = match ($var['kind']) {
                    'startend' => $this->startEndValue(
                        $this->replaceVariableDependencies($var['start_text'], $variables, $values, $stack),
                        $this->replaceVariableDependencies($var['end_text'], $variables, $values, $stack),
                        $haystack
                    ),
                    'url' => trim($haystack),
                    default => $this->regexValue($this->replaceVariableDependencies($var['regex'], $variables, $values, $stack), $haystack, (string)($var['regex_flags'] ?? 'is')),
                };
            }
        } catch (Throwable $e) {
            throw new RuntimeException('Variable ' . $name . ': ' . $e->getMessage(), 0, $e);
        }

        $values[$name] = $value;
        $this->apps->updateVariableResult((int)$var['id'], $value, $content);
        return $value;
    }

    private function replaceVariableDependencies(string $template, array $variables, array &$values, array $stack): string
    {
        return Support::replaceVariables($template, $values, function (string $name) use ($variables, &$values, $stack): string {
            if (array_key_exists($name, $values)) {
                return (string)$values[$name];
            }
            if (isset($variables[$name])) {
                return $this->resolveVariable($name, $variables, $values, $stack);
            }
            return '{' . $name . '}';
        });
    }

    private function regexValue(string $pattern, string $content, string $flags = ''): string
    {
        if ($pattern === '') {
            return trim($content);
        }
        $regex = $this->regexPattern($pattern, $flags);
        if (str_contains($flags, 'g')) {
            if (!preg_match_all($regex, $content, $matches)) {
                throw new RuntimeException('Regular expression did not match.');
            }
            $values = $matches[1] ?? $matches[0] ?? [];
            return trim(implode("\n", array_map('strval', $values)));
        }
        if (!preg_match($regex, $content, $matches)) {
            throw new RuntimeException('Regular expression did not match.');
        }
        return trim((string)($matches[1] ?? $matches[0]));
    }

    private function regexPattern(string $pattern, string $flags): string
    {
        $modifiers = $this->regexModifiers($flags);
        $delimiter = $this->regexDelimiter($pattern);
        return $delimiter . $this->escapeRegexDelimiter($pattern, $delimiter) . $delimiter . $modifiers;
    }

    private function regexDelimiter(string $pattern): string
    {
        foreach (['/', '~', '#', '%', '!', '@', ';', '`'] as $delimiter) {
            if (!$this->hasUnescapedDelimiter($pattern, $delimiter)) {
                return $delimiter;
            }
        }
        return '~';
    }

    private function hasUnescapedDelimiter(string $pattern, string $delimiter): bool
    {
        $escaped = false;
        $length = strlen($pattern);
        for ($i = 0; $i < $length; $i++) {
            $char = $pattern[$i];
            if ($escaped) {
                $escaped = false;
                continue;
            }
            if ($char === '\\') {
                $escaped = true;
                continue;
            }
            if ($char === $delimiter) {
                return true;
            }
        }
        return false;
    }

    private function escapeRegexDelimiter(string $pattern, string $delimiter): string
    {
        $result = '';
        $escaped = false;
        $length = strlen($pattern);
        for ($i = 0; $i < $length; $i++) {
            $char = $pattern[$i];
            if ($escaped) {
                $result .= '\\' . $char;
                $escaped = false;
                continue;
            }
            if ($char === '\\') {
                $escaped = true;
                continue;
            }
            $result .= $char === $delimiter ? '\\' . $char : $char;
        }
        return $result . ($escaped ? '\\' : '');
    }

    private function regexModifiers(string $flags): string
    {
        $modifiers = '';
        foreach (['i', 'm', 's'] as $flag) {
            if (str_contains($flags, $flag)) {
                $modifiers .= $flag;
            }
        }
        return $modifiers;
    }

    private function startEndValue(string $start, string $end, string $content): string
    {
        if ($start === '' || $end === '') {
            return trim($content);
        }
        $startPos = strpos($content, $start);
        if ($startPos === false) {
            return '';
        }
        $valueStart = $startPos + strlen($start);
        $endPos = strpos($content, $end, $valueStart);
        if ($endPos === false) {
            return '';
        }
        return trim(substr($content, $valueStart, $endPos - $valueStart));
    }

    private function resolveTarget(array $app, string $url, array $values): string
    {
        return $this->resolveTargetInfo($app, $url, $values)['target'];
    }

    /**
     * @return array{target: string, directory: bool}
     */
    private function resolveTargetInfo(array $app, string $url, array $values): array
    {
        $target = Support::replaceVariables((string)$app['target_path'], $values + ['name' => $app['name']]);
        if ($target === '') {
            $basename = basename(parse_url($url, PHP_URL_PATH) ?: preg_replace('/\s+/', '_', $app['name']));
            return ['target' => KW_DATA . '/downloads/' . $this->safeDownloadFilename($basename, $app), 'directory' => false];
        }
        $isDirectory = (string)($app['target_type'] ?? 'file') === 'folder';
        if ($isDirectory) {
            $target = rtrim($target, "/\\") . DIRECTORY_SEPARATOR . $this->filenameFromUrl($url, $app);
        }
        return ['target' => $target, 'directory' => $isDirectory];
    }

    private function download(int $appId, string $url, string $target, bool $useDeliveredFilename = false): string
    {
        $dir = dirname($target);
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new RuntimeException('Target folder could not be created: ' . $dir);
        }
        $tmp = $target . '.part';
        $headers = array_merge(
            ['User-Agent: ' . (string)($this->requestOptions['user_agent'] ?? 'KetarinWeb/1.0')],
            $this->http->headers($this->requestOptions, false)
        );
        $context = stream_context_create(['http' => ['header' => implode("\r\n", $headers) . "\r\n"]]);
        $in = @fopen($url, 'rb', false, $context);
        if (!$in) {
            throw new RuntimeException('Download could not be opened: ' . $url);
        }
        if ($useDeliveredFilename) {
            $deliveredFilename = $this->contentDispositionFilename($in);
            if ($deliveredFilename !== '') {
                $target = $dir . DIRECTORY_SEPARATOR . $deliveredFilename;
                $tmp = $target . '.part';
            }
        }
        $total = $this->contentLength($in);
        $this->apps->markDownloadStarted($appId, $total);
        $out = @fopen($tmp, 'wb');
        if (!$out) {
            fclose($in);
            throw new RuntimeException('Target file could not be written: ' . $tmp);
        }
        $bytes = 0;
        $lastUpdate = 0.0;
        while (!feof($in)) {
            $chunk = fread($in, 1024 * 256);
            if ($chunk === false) {
                fclose($in);
                fclose($out);
                @unlink($tmp);
                throw new RuntimeException('Download could not be read: ' . $url);
            }
            if ($chunk === '') {
                continue;
            }
            $offset = 0;
            $length = strlen($chunk);
            while ($offset < $length) {
                $written = fwrite($out, substr($chunk, $offset));
                if ($written === false || $written === 0) {
                    fclose($in);
                    fclose($out);
                    @unlink($tmp);
                    throw new RuntimeException('Download could not be written: ' . $tmp);
                }
                $offset += $written;
                $bytes += $written;
            }
            $now = microtime(true);
            if ($now - $lastUpdate >= 0.35) {
                $this->apps->markDownloadProgress($appId, $bytes, $total);
                $lastUpdate = $now;
            }
        }
        $this->apps->markDownloadProgress($appId, $bytes, $total);
        fclose($in);
        fclose($out);
        if (!rename($tmp, $target)) {
            @unlink($tmp);
            throw new RuntimeException('Download could not be finalized: ' . $target);
        }
        return $target;
    }

    private function contentLength($stream): int
    {
        $meta = stream_get_meta_data($stream);
        $headers = $meta['wrapper_data'] ?? [];
        if (!is_array($headers)) {
            return 0;
        }
        foreach ($headers as $header) {
            if (is_string($header) && preg_match('/^Content-Length:\s*(\d+)/i', $header, $matches)) {
                return (int)$matches[1];
            }
        }
        return 0;
    }

    private function contentDispositionFilename($stream): string
    {
        foreach ($this->responseHeaders($stream) as $header) {
            if (!is_string($header) || !preg_match('/^Content-Disposition:\s*(.+)$/i', $header, $matches)) {
                continue;
            }
            $value = $matches[1];
            if (preg_match('/filename\*=UTF-8\'\'([^;]+)/i', $value, $fileMatches)) {
                return $this->safeDownloadFilename(rawurldecode(trim($fileMatches[1], " \t\"'")), []);
            }
            if (preg_match('/filename="?([^";]+)"?/i', $value, $fileMatches)) {
                return $this->safeDownloadFilename(trim($fileMatches[1]), []);
            }
        }
        return '';
    }

    private function responseHeaders($stream): array
    {
        $meta = stream_get_meta_data($stream);
        $headers = $meta['wrapper_data'] ?? [];
        return is_array($headers) ? $headers : [];
    }

    private function filenameFromUrl(string $url, array $app): string
    {
        $basename = basename((string)(parse_url($url, PHP_URL_PATH) ?: ''));
        return $this->safeDownloadFilename($basename, $app);
    }

    private function safeDownloadFilename(string $filename, array $app): string
    {
        $filename = trim(str_replace(["\0", '/', '\\'], '', $filename));
        if ($filename === '' || $filename === '.' || $filename === '..') {
            $filename = preg_replace('/\s+/', '_', (string)($app['name'] ?? 'download')) ?: 'download';
        }
        return $filename;
    }

    private function queueCommand(array $app, string $reason, string $target, string $url, string $version, array $values): void
    {
        if (empty($app['command_enabled']) || trim((string)$app['command_script']) === '') {
            return;
        }
        $this->apps->enqueueCommand((int)$app['id'], $reason, [
            'target' => $target,
            'url' => $url,
            'version' => $version,
            'values' => $values,
        ]);
    }

    private function runCommand(array $app, string $target, string $url, string $version, array $values, array $job = []): string
    {
        if (empty($app['command_enabled']) || trim((string)$app['command_script']) === '') {
            return '';
        }
        if (!function_exists('proc_open')) {
            throw new RuntimeException('proc_open is missing; Bash script cannot be executed.');
        }

        $scriptValues = $values + [
            'file' => $target,
            'target' => $target,
            'url' => $url,
            'version' => $version,
            'name' => $app['name'],
        ];
        $script = Support::replaceVariables((string)$app['command_script'], $scriptValues);
        $script = str_replace(["\r\n", "\r"], "\n", $script);
        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $process = proc_open('bash -s', $descriptors, $pipes, dirname($target), [
            'KETARINWEB_FILE' => $target,
            'KETARINWEB_URL' => $url,
            'KETARINWEB_VERSION' => $version,
            'KETARINWEB_APP_NAME' => (string)$app['name'],
        ]);
        if (!is_resource($process)) {
            $this->writeCommandLog($app, $scriptValues, $script, "Bash script could not be started.\n", null, $job);
            throw new RuntimeException('Bash script could not be started.');
        }
        fwrite($pipes[0], $script);
        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[2]);
        $code = proc_close($process);
        $output = trim((string)$stderr . "\n" . (string)$stdout);
        $logFile = $this->writeCommandLog($app, $scriptValues, $script, $output, $code, $job);
        if ($code !== 0) {
            throw new RuntimeException('Bash script failed (' . $code . '): ' . $output);
        }
        return $logFile;
    }

    private function writeCommandLog(array $app, array $values, string $script, string $output, ?int $exitCode, array $job = []): string
    {
        ksort($values, SORT_NATURAL | SORT_FLAG_CASE);
        $lines = [];
        foreach ($values as $name => $value) {
            $lines[] = $name . ': ' . str_replace(["\r\n", "\r"], "\n", (string)$value);
        }
        $lines[] = '';
        $lines[] = 'Bash script:';
        $lines[] = rtrim($script);
        $lines[] = '';
        $lines[] = 'Bash script output:';
        $lines[] = 'Exit code: ' . ($exitCode === null ? 'n/a' : (string)$exitCode);
        $lines[] = rtrim($output);
        $lines[] = '';

        $json = json_encode([
            'timestamp' => date(DATE_ATOM),
            'app' => [
                'id' => (int)$app['id'],
                'name' => (string)$app['name'],
            ],
            'job' => $job ? [
                'id' => (int)$job['id'],
                'reason' => (string)$job['reason'],
            ] : null,
            'variables' => $values,
            'bash_script' => $script,
            'output' => $output,
            'exit_code' => $exitCode,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($json === false) {
            $json = '{}';
        }

        $this->apps->saveCommandLog((int)$app['id'], [
            'job_id' => isset($job['id']) ? (int)$job['id'] : 0,
            'status' => $exitCode === 0 ? 'done' : 'failed',
            'exit_code' => $exitCode,
            'variables_json' => json_encode($values, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '{}',
            'script' => $script,
            'output' => $output,
            'log_text' => implode("\n", $lines),
            'log_json' => $json,
        ]);
        return 'database';
    }
}
