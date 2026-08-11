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
        $loginPasswordHash = (string)($rows['login_password_hash'] ?? '');
        return [
            'email_to' => (string)($rows['email_to'] ?? ''),
            'email_from' => (string)($rows['email_from'] ?? ''),
            'email_from_name' => (string)($rows['email_from_name'] ?? 'KetarinWeb'),
            'file_browser_action' => in_array(($rows['file_browser_action'] ?? 'double'), ['single', 'double'], true) ? (string)$rows['file_browser_action'] : 'double',
            'language' => I18n::normalizeLocale((string)($rows['language'] ?? 'en_US')),
            'login_enabled' => (string)($rows['login_enabled'] ?? '0') === '1' && $loginPasswordHash !== '',
            'login_password_hash' => $loginPasswordHash,
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

        $current = $this->database->pdo()->query('SELECT key, value FROM settings')->fetchAll(PDO::FETCH_KEY_PAIR);
        $loginEnabled = !empty($settings['login_enabled']) ? '1' : '0';
        $password = (string)($settings['login_password'] ?? '');
        $passwordConfirm = (string)($settings['login_password_confirm'] ?? '');
        $passwordHash = (string)($current['login_password_hash'] ?? '');
        if ($password !== '' || $passwordConfirm !== '') {
            if ($password !== $passwordConfirm) {
                throw new RuntimeException('Login passwords do not match.');
            }
            $passwordHash = password_hash($password, PASSWORD_DEFAULT);
        }
        if ($loginEnabled === '1' && $passwordHash === '') {
            throw new RuntimeException('A login password is required.');
        }
        $stmt->execute(['login_enabled', $loginEnabled]);
        $stmt->execute(['login_password_hash', $passwordHash]);
    }
}
