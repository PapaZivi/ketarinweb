<?php
declare(strict_types=1);

final class Mailer
{
    public function __construct(private readonly SettingsRepository $settings)
    {
    }

    public function sendUpdateNotification(array $results): bool
    {
        $updates = array_values(array_filter($results, static function (array $result): bool {
            return in_array($result['status'] ?? '', ['update-found', 'downloaded'], true);
        }));
        if ($updates === []) {
            return false;
        }

        $settings = $this->settings->all();
        $to = $settings['email_to'];
        if ($to === '') {
            return false;
        }

        $subject = 'KetarinWeb updates found: ' . count($updates);
        $body = "KetarinWeb found updates:\n\n";
        foreach ($updates as $result) {
            $body .= '- ' . ($result['message'] ?? $result['status']) . "\n";
            if (($result['status'] ?? '') === 'update-found') {
                $body .= "  Action: notification without download\n";
            }
            if (!empty($result['version'])) {
                $body .= '  Version: ' . $result['version'] . "\n";
            }
            if (!empty($result['url'])) {
                $body .= '  URL: ' . $result['url'] . "\n";
            }
            if (!empty($result['target'])) {
                $body .= '  Target: ' . $result['target'] . "\n";
            }
            $body .= "\n";
        }

        return mail($to, $this->encodeHeader($subject), $body, implode("\r\n", $this->headers($settings)));
    }

    public function sendTest(): bool
    {
        $settings = $this->settings->all();
        $to = $settings['email_to'];
        $from = $settings['email_from'];
        if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
            throw new RuntimeException('No valid recipient address configured.');
        }
        if (!filter_var($from, FILTER_VALIDATE_EMAIL)) {
            throw new RuntimeException('No valid sender address configured.');
        }

        $subject = 'KetarinWeb test mail';
        $body = "This is a KetarinWeb test mail.\n\nIf this mail arrived, sending through PHP mail() works.\n";
        return mail($to, $this->encodeHeader($subject), $body, implode("\r\n", $this->headers($settings)));
    }

    private function headers(array $settings): array
    {
        $headers = [];
        $fromName = $this->encodeHeader(($settings['email_from_name'] ?? '') ?: 'KetarinWeb');
        $headers[] = 'From: ' . $fromName . ' <' . $settings['email_from'] . '>';
        $headers[] = 'Content-Type: text/plain; charset=UTF-8';
        $headers[] = 'X-Mailer: KetarinWeb';
        return $headers;
    }

    private function encodeHeader(string $value): string
    {
        if (preg_match('/^[\x20-\x7E]*$/', $value)) {
            return $value;
        }
        return '=?UTF-8?B?' . base64_encode($value) . '?=';
    }
}
