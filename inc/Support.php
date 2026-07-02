<?php
declare(strict_types=1);

final class Support
{
    public static function h(null|string|int $value): string
    {
        return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    public static function now(): string
    {
        return date('Y-m-d H:i:s');
    }

    public static function redirect(string $url = 'index.php'): never
    {
        header('Location: ' . $url);
        exit;
    }

    public static function replaceVariables(string $template, array $values, ?callable $resolver = null): string
    {
        return preg_replace_callback('/\{([^{}\r\n]+)\}/', static function (array $match) use ($values, $resolver): string {
            $parts = explode(':', $match[1]);
            $name = array_shift($parts);
            if (!is_string($name) || !preg_match('/^[A-Za-z0-9_.-]+$/', $name)) {
                return $match[0];
            }
            if (array_key_exists($name, $values)) {
                $value = (string)$values[$name];
            } elseif ($resolver) {
                $value = (string)$resolver($name);
            } else {
                return $match[0];
            }
            if ($parts === []) {
                return $value;
            }
            return self::applyVariableFunction($value, (string)array_shift($parts), $parts, $values, $resolver);
        }, $template);
    }

    private static function applyVariableFunction(string $value, string $function, array $args, array $values, ?callable $resolver): string
    {
        $function = strtolower($function);
        $args = array_map(static fn (string $arg): string => self::replaceVariables($arg, $values, $resolver), $args);
        return match ($function) {
            'directory' => self::directoryName($value),
            'empty' => '',
            'ext' => self::extension($value),
            'filename' => self::fileName($value),
            'formatfilesize' => self::formatFileSize($value),
            'ifempty' => $value !== '' ? $value : self::argumentVariable((string)($args[0] ?? ''), $values, $resolver),
            'ifemptythenerror' => $value !== '' ? $value : throw new RuntimeException((string)($args[0] ?? 'Variable ist leer.')),
            'multireplace' => self::multiReplace($value, $args, false),
            'multireplacei' => self::multiReplace($value, $args, true),
            'padleft' => str_pad($value, max(0, (int)($args[0] ?? 0)), self::padString($args[1] ?? ' '), STR_PAD_LEFT),
            'padright' => str_pad($value, max(0, (int)($args[0] ?? 0)), self::padString($args[1] ?? ' '), STR_PAD_RIGHT),
            'regex' => self::regexMatch($value, (string)($args[0] ?? ''), (int)($args[1] ?? 1)),
            'regexreplace' => self::regexReplace($value, (string)($args[0] ?? ''), (string)($args[1] ?? '')),
            'replace' => str_replace((string)($args[0] ?? ''), (string)($args[1] ?? ''), $value),
            'split' => self::splitPart($value, (string)($args[0] ?? ''), (int)($args[1] ?? 0)),
            'startuppath' => defined('KW_ROOT') ? KW_ROOT : __DIR__,
            'tolower' => strtolower($value),
            'toupper' => strtoupper($value),
            'trim' => self::trimValue($value, $args[0] ?? null),
            'trimend' => self::trimValue($value, $args[0] ?? null, false, true),
            'trimstart' => self::trimValue($value, $args[0] ?? null, true, false),
            'urldecode' => rawurldecode($value),
            'urlencode' => rawurlencode($value),
            default => $value,
        };
    }

    private static function argumentVariable(string $name, array $values, ?callable $resolver): string
    {
        if ($name === '') {
            return '';
        }
        if (array_key_exists($name, $values)) {
            return (string)$values[$name];
        }
        return $resolver ? (string)$resolver($name) : '';
    }

    private static function padString(string $value): string
    {
        return $value === '' ? ' ' : $value;
    }

    private static function regexPattern(string $pattern): string
    {
        if ($pattern === '') {
            return '~(?:)~si';
        }
        $delimiter = self::regexDelimiter($pattern);
        return $delimiter . self::escapeRegexDelimiter($pattern, $delimiter) . $delimiter . 'si';
    }

    private static function regexDelimiter(string $pattern): string
    {
        foreach (['/', '~', '#', '%', '!', '@', ';', '`'] as $delimiter) {
            if (!self::hasUnescapedDelimiter($pattern, $delimiter)) {
                return $delimiter;
            }
        }
        return '~';
    }

    private static function hasUnescapedDelimiter(string $pattern, string $delimiter): bool
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

    private static function escapeRegexDelimiter(string $pattern, string $delimiter): string
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

    private static function regexMatch(string $value, string $pattern, int $group): string
    {
        if (!preg_match(self::regexPattern($pattern), $value, $matches)) {
            return '';
        }
        return (string)($matches[$group] ?? '');
    }

    private static function regexReplace(string $value, string $pattern, string $replacement): string
    {
        return (string)preg_replace(self::regexPattern($pattern), $replacement, $value);
    }

    private static function multiReplace(string $value, array $args, bool $caseInsensitive): string
    {
        $separator = (string)($args[0] ?? '|');
        $search = explode($separator, (string)($args[1] ?? ''));
        $replace = explode($separator, (string)($args[2] ?? ''));
        foreach ($search as $index => $needle) {
            $replacement = (string)($replace[$index] ?? '');
            $value = $caseInsensitive ? str_ireplace($needle, $replacement, $value) : str_replace($needle, $replacement, $value);
        }
        return $value;
    }

    private static function splitPart(string $value, string $separator, int $index): string
    {
        if ($separator === '') {
            return $value;
        }
        $parts = explode($separator, $value);
        if ($index < 0) {
            $index = count($parts) + $index;
        }
        return (string)($parts[$index] ?? '');
    }

    private static function trimValue(string $value, ?string $chars, bool $left = true, bool $right = true): string
    {
        if ($chars === null) {
            return $left && $right ? trim($value) : ($left ? ltrim($value) : rtrim($value));
        }
        return $left && $right ? trim($value, $chars) : ($left ? ltrim($value, $chars) : rtrim($value, $chars));
    }

    private static function fileName(string $value): string
    {
        $path = parse_url($value, PHP_URL_PATH);
        return basename($path !== false && $path !== null ? $path : $value);
    }

    private static function directoryName(string $value): string
    {
        $path = parse_url($value, PHP_URL_PATH);
        $dir = dirname($path !== false && $path !== null ? $path : $value);
        if (preg_match('~^https?://~i', $value)) {
            $parts = parse_url($value);
            if (is_array($parts) && isset($parts['scheme'], $parts['host'])) {
                return $parts['scheme'] . '://' . $parts['host'] . (isset($parts['port']) ? ':' . $parts['port'] : '') . ($dir === '/' ? '' : $dir);
            }
        }
        return $dir === '.' ? '' : $dir;
    }

    private static function extension(string $value): string
    {
        $extension = pathinfo(self::fileName($value), PATHINFO_EXTENSION);
        return (string)$extension;
    }

    private static function formatFileSize(string $value): string
    {
        $bytes = (float)$value;
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $index = 0;
        while ($bytes >= 1024 && $index < count($units) - 1) {
            $bytes /= 1024;
            $index++;
        }
        return ($index === 0 ? (string)(int)$bytes : number_format($bytes, 1, '.', '')) . ' ' . $units[$index];
    }
}
