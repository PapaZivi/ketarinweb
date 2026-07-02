<?php
declare(strict_types=1);

final class I18n
{
    /** @var array<string,string> */
    private array $messages = [];

    /** @var array{name:string,iso2:string,iso3:string} */
    private array $meta = ['name' => 'English', 'iso2' => 'en', 'iso3' => 'eng'];

    public function __construct(private readonly string $locale = 'en_US')
    {
        $this->load($locale);
    }

    public static function normalizeLocale(string $locale): string
    {
        $locale = preg_replace('/[^A-Za-z_ -]+/', '', trim($locale)) ?: 'en_US';
        $locale = str_replace(['-', ' '], '_', $locale);
        if (!preg_match('/^[a-z]{2}_[A-Z]{2}$/', $locale)) {
            $parts = explode('_', $locale);
            if (count($parts) >= 2) {
                $locale = strtolower($parts[0]) . '_' . strtoupper($parts[1]);
            }
        }
        return preg_match('/^[a-z]{2}_[A-Z]{2}$/', $locale) ? $locale : 'en_US';
    }

    /**
     * @return array<int,array{locale:string,name:string,iso2:string,iso3:string}>
     */
    public static function available(): array
    {
        $languages = [];
        foreach (glob(KW_ROOT . '/lang/*.json') ?: [] as $file) {
            $locale = basename($file, '.json');
            $data = json_decode((string)file_get_contents($file), true);
            if (!is_array($data)) {
                continue;
            }
            $languages[] = [
                'locale' => self::normalizeLocale($locale),
                'name' => (string)($data['name'] ?? $locale),
                'iso2' => (string)($data['iso2'] ?? ''),
                'iso3' => (string)($data['iso3'] ?? ''),
            ];
        }
        usort($languages, static fn (array $a, array $b): int => strcasecmp($a['name'], $b['name']));
        return $languages ?: [['locale' => 'en_US', 'name' => 'English', 'iso2' => 'en', 'iso3' => 'eng']];
    }

    public function locale(): string
    {
        return $this->locale;
    }

    public function meta(string $key): string
    {
        return $this->meta[$key] ?? '';
    }

    public function t(string $key, array $replace = []): string
    {
        $text = $this->messages[$key] ?? $key;
        foreach ($replace as $name => $value) {
            $text = str_replace('{' . $name . '}', (string)$value, $text);
        }
        return $text;
    }

    /**
     * @param array<int,string> $keys
     * @return array<string,string>
     */
    public function subset(array $keys): array
    {
        $messages = [];
        foreach ($keys as $key) {
            $messages[$key] = $this->t($key);
        }
        return $messages;
    }

    private function load(string $locale): void
    {
        $fallback = $this->readLanguageFile('en_US');
        $selected = $locale === 'en_US' ? $fallback : $this->readLanguageFile($locale);
        $data = $selected ?: $fallback;
        $fallbackMessages = is_array($fallback['messages'] ?? null) ? $fallback['messages'] : [];
        $selectedMessages = is_array($data['messages'] ?? null) ? $data['messages'] : [];
        $this->messages = array_replace($fallbackMessages, $selectedMessages);
        $this->meta = [
            'name' => (string)($data['name'] ?? 'English'),
            'iso2' => (string)($data['iso2'] ?? 'en'),
            'iso3' => (string)($data['iso3'] ?? 'eng'),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function readLanguageFile(string $locale): array
    {
        $locale = self::normalizeLocale($locale);
        $file = KW_ROOT . '/lang/' . $locale . '.json';
        if (!is_file($file)) {
            return [];
        }
        $data = json_decode((string)file_get_contents($file), true);
        return is_array($data) ? $data : [];
    }
}
