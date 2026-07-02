<?php
declare(strict_types=1);

final class RuntimeRequirements
{
    public static function assertSatisfied(): void
    {
        $missing = self::missing();
        if ($missing === []) {
            return;
        }

        http_response_code(500);
        if (PHP_SAPI === 'cli') {
            fwrite(STDERR, "KetarinWeb cannot start. Missing requirements:\n");
            foreach ($missing as $item) {
                fwrite(STDERR, '- ' . $item . "\n");
            }
            exit(1);
        }

        $loadedIni = php_ini_loaded_file() ?: 'no php.ini reported';
        $sapi = PHP_SAPI;

        echo '<!doctype html><html lang="en"><head><meta charset="utf-8"><title>KetarinWeb requirements</title>';
        echo '<style>body{font:14px Arial,sans-serif;margin:32px;color:#222;line-height:1.45}code{background:#eee;padding:2px 4px}li{margin:6px 0}.hint{color:#555}</style>';
        echo '</head><body><h1>KetarinWeb cannot start</h1><p>Please enable these items in the PHP configuration used by this web server:</p><ul>';
        foreach ($missing as $item) {
            echo '<li>' . htmlspecialchars($item, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</li>';
        }
        echo '</ul><p class="hint">Current SAPI: <code>' . htmlspecialchars($sapi, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</code><br>';
        echo 'Loaded php.ini: <code>' . htmlspecialchars($loadedIni, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</code></p>';
        echo '<p class="hint">For HTTPS downloads, enable either <code>curl</code> or <code>openssl</code>. Apache, PHP-FPM and the PHP built-in web server can use different PHP configurations.</p></body></html>';
        exit;
    }

    private static function missing(): array
    {
        $missing = [];

        if (PHP_VERSION_ID < 80400) {
            $missing[] = 'PHP >= 8.4';
        }
        if (!class_exists(PDO::class)) {
            $missing[] = 'PDO';
        } elseif (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
            $missing[] = 'pdo_sqlite';
        }
        if (!function_exists('simplexml_load_file')) {
            $missing[] = 'SimpleXML';
        }
        if (!extension_loaded('session')) {
            $missing[] = 'session';
        }
        if (!extension_loaded('libxml')) {
            $missing[] = 'libxml';
        }
        if (!extension_loaded('pcre')) {
            $missing[] = 'pcre';
        }
        if (!function_exists('curl_init') && !filter_var(ini_get('allow_url_fopen'), FILTER_VALIDATE_BOOLEAN)) {
            $missing[] = 'curl or allow_url_fopen=On';
        }
        if (!function_exists('curl_init') && !extension_loaded('openssl')) {
            $missing[] = 'curl or openssl for HTTPS downloads';
        }
        if (!function_exists('proc_open')) {
            $missing[] = 'proc_open for Bash scripts after downloads';
        }

        return $missing;
    }
}
