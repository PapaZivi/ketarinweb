<?php
declare(strict_types=1);
require __DIR__ . '/inc/init.php';

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    echo "CLI only\n";
    exit(1);
}

$download = !in_array('--check-only', $argv, true);
$force = in_array('--force', $argv, true);
$commandsOnly = in_array('--commands', $argv, true);
$verbose = in_array('--verbose', $argv, true) || in_array('-v', $argv, true);
$repository = new AppRepository(new Database());
$settingsRepository = new SettingsRepository(new Database());
$updater = new Updater($repository, new HttpClient());
$ids = [];
foreach (array_slice($argv, 1) as $arg) {
    if (str_starts_with($arg, '--id=')) {
        $ids = array_merge($ids, explode(',', substr($arg, 5)));
        continue;
    }
    if (preg_match('/^\d+(,\d+)*$/', $arg)) {
        $ids = array_merge($ids, explode(',', $arg));
    }
}
$ids = array_values(array_unique(array_filter(array_map('intval', $ids), static fn (int $id): bool => $id > 0)));

$results = [];
$lines = [];
$hasError = false;
if (!is_dir(KW_DATA . '/logs')) {
    mkdir(KW_DATA . '/logs', 0775, true);
}
$recordResult = static function (array $result) use (&$results, &$lines, &$hasError, $verbose): void {
    $line = '[' . date('Y-m-d H:i:s') . '] ' . ($result['message'] ?? $result['status']);
    $results[] = $result;
    $lines[] = $line;
    file_put_contents(KW_DATA . '/logs/cron.log', $line . PHP_EOL, FILE_APPEND);

    $isError = in_array((string)($result['status'] ?? ''), ['error', 'failed'], true);
    if ($isError) {
        $hasError = true;
    }
    if ($verbose || $isError) {
        echo $line . PHP_EOL;
    }
};

if ($commandsOnly) {
    $updater->runQueuedCommands(20, $recordResult);
} elseif ($ids !== []) {
    foreach ($ids as $id) {
        try {
            $app = $repository->find($id);
            if (!$app) {
                $recordResult(['status' => 'error', 'message' => 'ID ' . $id . ' not found']);
                continue;
            }
            if (!$app['enabled'] && !$force) {
                $recordResult(['status' => 'skipped', 'message' => $app['name'] . ' is disabled']);
                continue;
            }
            $recordResult($updater->run($id, $download, $force));
        } catch (Throwable $e) {
            $recordResult(['status' => 'error', 'message' => $e->getMessage()]);
        }
    }
} else {
    $updater->runAll($download, $recordResult);
}
if (!$commandsOnly) {
    $mailer = new Mailer($settingsRepository);
    if ($mailer->sendUpdateNotification($results)) {
        file_put_contents(KW_DATA . '/logs/cron.log', '[' . date('Y-m-d H:i:s') . '] mail update notification sent' . PHP_EOL, FILE_APPEND);
    }
}
