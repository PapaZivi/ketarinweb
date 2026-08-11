<?php
declare(strict_types=1);

final class HttpClient
{
    public function request(string $url, string $postData = '', array $options = []): string
    {
        $options['_url'] = $url;
        if (function_exists('curl_init')) {
            return $this->curlRequest($url, $postData, $options);
        }
        return $this->streamRequest($url, $postData, $options);
    }

    private function curlRequest(string $url, string $postData, array $options): string
    {
        $ch = curl_init($url);
        $responseHeaders = [];
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_CONNECTTIMEOUT => 20,
            CURLOPT_TIMEOUT => 120,
            CURLOPT_USERAGENT => $this->userAgent($options),
            CURLOPT_HTTPHEADER => $this->headers($options),
            CURLOPT_ENCODING => '',
            CURLOPT_HEADERFUNCTION => static function ($curl, string $header) use (&$responseHeaders): int {
                $length = strlen($header);
                $header = trim($header);
                if ($header !== '') {
                    $responseHeaders[] = $header;
                }
                return $length;
            },
        ]);
        if (!empty($options['referer'])) {
            curl_setopt($ch, CURLOPT_REFERER, (string)$options['referer']);
        }
        if ($postData !== '') {
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $postData);
        }
        $body = curl_exec($ch);
        if ($body === false) {
            $error = curl_error($ch);
            curl_close($ch);
            throw new RuntimeException($error);
        }
        $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        if ($code >= 400) {
            throw new RuntimeException($this->httpErrorMessage($code, $url, (string)$body, $responseHeaders));
        }
        return (string)$body;
    }

    private function streamRequest(string $url, string $postData, array $options): string
    {
        $headers = ['User-Agent: ' . $this->userAgent($options)];
        $headers = array_merge($headers, $this->headers($options));
        $http = [
            'header' => implode("\r\n", $headers) . "\r\n",
            'timeout' => 120,
            'ignore_errors' => true,
        ];
        if ($postData !== '') {
            $http['method'] = 'POST';
            $http['header'] .= "Content-Type: application/x-www-form-urlencoded\r\n";
            $http['content'] = $postData;
        }
        $context = stream_context_create(['http' => $http]);
        $body = @file_get_contents($url, false, $context);
        if ($body === false) {
            throw new RuntimeException('URL could not be loaded: ' . $url);
        }
        $responseHeaders = $http_response_header ?? [];
        $code = $this->statusCode($responseHeaders);
        $decodedBody = $this->decodeBody((string)$body, $responseHeaders);
        if ($code >= 400) {
            throw new RuntimeException($this->httpErrorMessage($code, $url, $decodedBody, $responseHeaders));
        }
        return $decodedBody;
    }

    public function headers(array $options = [], bool $allowCompression = true): array
    {
        $isGitHubApi = $this->isGitHubApi((string)($options['_url'] ?? ''));
        $headers = [
            $isGitHubApi ? 'Accept: application/vnd.github+json' : 'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
            'Accept-Language: de,en-US;q=0.9,en;q=0.8',
            'Upgrade-Insecure-Requests: 1',
            'Sec-Fetch-Dest: document',
            'Sec-Fetch-Mode: navigate',
            'Sec-Fetch-Site: none',
            'Sec-Fetch-User: ?1',
            'Pragma: no-cache',
            'Cache-Control: no-cache',
        ];
        if ($allowCompression) {
            $headers[] = 'Accept-Encoding: gzip, deflate';
        }
        if (!empty($options['referer'])) {
            $headers[] = 'Referer: ' . (string)$options['referer'];
        }
        if ($isGitHubApi) {
            $headers[] = 'X-GitHub-Api-Version: 2022-11-28';
            $token = trim((string)(getenv('KETARINWEB_GITHUB_TOKEN') ?: ''));
            if ($token !== '') {
                $headers[] = 'Authorization: Bearer ' . $token;
            }
        }
        return $headers;
    }

    private function userAgent(array $options): string
    {
        $userAgent = trim((string)($options['user_agent'] ?? ''));
        if ($userAgent !== '') {
            return $userAgent;
        }

        return 'KetarinWeb/' . (defined('KW_VERSION') ? (string)KW_VERSION : 'dev');
    }

    private function statusCode(array $headers): int
    {
        $code = 0;
        foreach ($headers as $header) {
            if (is_string($header) && preg_match('~^HTTP/\S+\s+(\d{3})\b~', $header, $matches)) {
                $code = (int)$matches[1];
            }
        }
        return $code;
    }

    private function httpErrorMessage(int $code, string $url, string $body, array $headers): string
    {
        $message = 'HTTP ' . $code . ' for ' . $url;
        $json = json_decode($body, true);
        if (is_array($json) && isset($json['message'])) {
            $message .= ': ' . (string)$json['message'];
        }
        if ($this->isGitHubApi($url)) {
            $remaining = $this->headerValue($headers, 'x-ratelimit-remaining');
            $reset = $this->headerValue($headers, 'x-ratelimit-reset');
            if ($remaining === '0') {
                $message .= ' (GitHub API rate limit reached';
                if ($reset !== '' && ctype_digit($reset)) {
                    $message .= ', reset at ' . date('Y-m-d H:i:s', (int)$reset);
                }
                $message .= '. Set KETARINWEB_GITHUB_TOKEN for authenticated requests.)';
            }
        }
        return $message;
    }

    private function headerValue(array $headers, string $name): string
    {
        foreach ($headers as $header) {
            if (is_string($header) && stripos($header, $name . ':') === 0) {
                return trim(substr($header, strlen($name) + 1));
            }
        }
        return '';
    }

    private function isGitHubApi(string $url): bool
    {
        return strtolower((string)(parse_url($url, PHP_URL_HOST) ?: '')) === 'api.github.com';
    }
    private function decodeBody(string $body, array $headers): string
    {
        foreach ($headers as $header) {
            if (!is_string($header) || !preg_match('/^Content-Encoding:\s*(gzip|deflate)\b/i', $header, $matches)) {
                continue;
            }
            $decoded = zlib_decode($body);
            return $decoded === false ? $body : $decoded;
        }
        return $body;
    }
}
