<?php
declare(strict_types=1);

final class SettingsRepository
{
    public function __construct(private readonly Database $database)
    {
    }

    public function all(): array
    {
        $rows = $this->database->pdo()->query('SELECT key, value FROM settings')->fetchAll(PDO::FETCH_KEY_PAIR);
        return [
            'email_to' => (string)($rows['email_to'] ?? ''),
            'email_from' => (string)($rows['email_from'] ?? ''),
            'email_from_name' => (string)($rows['email_from_name'] ?? 'KetarinWeb'),
            'file_browser_action' => in_array(($rows['file_browser_action'] ?? 'double'), ['single', 'double'], true) ? (string)$rows['file_browser_action'] : 'double',
            'language' => I18n::normalizeLocale((string)($rows['language'] ?? 'en_US')),
        ];
    }

    public function save(array $settings): void
    {
        $stmt = $this->database->pdo()->prepare('
            INSERT INTO settings (key, value) VALUES (?, ?)
            ON CONFLICT(key) DO UPDATE SET value = excluded.value
        ');
        foreach (['email_to', 'email_from', 'email_from_name', 'file_browser_action', 'language'] as $key) {
            $stmt->execute([$key, trim((string)($settings[$key] ?? ''))]);
        }
    }
}
