<?php
declare(strict_types=1);

final class Flash
{
    public function set(string $message, string $type = 'success'): void
    {
        $this->ensureSession();
        $_SESSION['flash'] = ['message' => $message, 'type' => $type];
    }

    public function pull(): ?array
    {
        $this->ensureSession();
        $flash = $_SESSION['flash'] ?? null;
        unset($_SESSION['flash']);
        return $flash;
    }

    private function ensureSession(): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_start();
        }
    }
}
