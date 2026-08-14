<?php
declare(strict_types=1);

final class SelfUpdateChecker
{
    private const RELEASE_API = 'https://api.github.com/repos/PapaZivi/ketarinweb/releases/latest';
    private const RELEASE_PAGE = 'https://github.com/PapaZivi/ketarinweb/releases/latest';

    public function __construct(
        private readonly HttpClient $http,
        private readonly SettingsRepository $settings,
    ) {
    }

    public function check(): array
    {
        $release = json_decode(
            $this->http->request(self::RELEASE_API),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );
        if (!is_array($release)) {
            throw new RuntimeException('KetarinWeb release information is invalid.');
        }

        $latestVersion = $this->normalizeVersion((string)($release['tag_name'] ?? ''));
        if ($latestVersion === '') {
            throw new RuntimeException('KetarinWeb release has no valid version tag.');
        }

        $releaseUrl = filter_var((string)($release['html_url'] ?? ''), FILTER_VALIDATE_URL)
            ? (string)$release['html_url']
            : self::RELEASE_PAGE;
        if (version_compare($latestVersion, KW_VERSION, '<=')) {
            $this->settings->setSelfUpdateAvailable('', '');
            return [
                'status' => 'current',
                'message' => 'KetarinWeb: current (' . KW_VERSION . ')',
                'version' => KW_VERSION,
                'url' => $releaseUrl,
                'self_update' => true,
            ];
        }

        $this->settings->setSelfUpdateAvailable($latestVersion, $releaseUrl);

        return [
            'status' => 'update-found',
            'message' => 'KetarinWeb: update found, no download',
            'old_version' => KW_VERSION,
            'version' => $latestVersion,
            'url' => $releaseUrl,
            'self_update' => true,
            'notify' => $latestVersion !== $this->settings->selfUpdateNotifiedVersion(),
        ];
    }

    public function markNotified(string $version): void
    {
        $version = $this->normalizeVersion($version);
        if ($version !== '') {
            $this->settings->markSelfUpdateNotified($version);
        }
    }

    private function normalizeVersion(string $version): string
    {
        $version = preg_replace('/^v/i', '', trim($version)) ?? '';
        return preg_match('/^\d+(?:\.\d+){1,2}$/', $version) ? $version : '';
    }
}
