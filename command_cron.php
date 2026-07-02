<?php
declare(strict_types=1);
require __DIR__ . '/inc/init.php';

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    echo "CLI only\n";
    exit(1);
}

$repository = new AppRepository(new Database());
$updater = new Updater($repository, new HttpClient());
$verbose = in_array('--verbose', $argv, true) || in_array('-v', $argv, true);

$results = [];
$lines = [];
$hasError = false;
$recordResult = static function (array $result) use (&$results, &$lines, &$hasError, $verbose): void {
    $line = '[' . date('Y-m-d H:i:s') . '] ' . ($result['message'] ?? $result['status']);
    $results[] = $result;
    $lines[] = $line;
    if (!is_dir(KW_DATA . '/logs')) {
        mkdir(KW_DATA . '/logs', 0775, true);
    }
    file_put_contents(KW_DATA . '/logs/command-cron.log', $line . PHP_EOL, FILE_APPEND);

    $isError = in_array((string)($result['status'] ?? ''), ['error', 'failed'], true);
    if ($isError) {
        $hasError = true;
    }
    if ($verbose || $isError) {
        echo $line . PHP_EOL;
    }
};

$updater->runQueuedCommands(20, $recordResult);

if ($results === [] && $verbose) {
    $line = '[' . date('Y-m-d H:i:s') . '] idle no command jobs';
    if (!is_dir(KW_DATA . '/logs')) {
        mkdir(KW_DATA . '/logs', 0775, true);
    }
    file_put_contents(KW_DATA . '/logs/command-cron.log', $line . PHP_EOL, FILE_APPEND);
    echo $line . PHP_EOL;
}
