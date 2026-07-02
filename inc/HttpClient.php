<?php
declare(strict_types=1);

final class HttpClient
{
    public function request(string $url, string $postData = '', array $options = []): string
    {
        if (function_exists('curl_init')) {
            return $this->curlRequest($url, $postData, $options);
        }
        return $this->streamRequest($url, $postData, $options);
    }

    private function curlRequest(string $url, string $postData, array $options): string
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_CONNECTTIMEOUT => 20,
            CURLOPT_TIMEOUT => 120,
            CURLOPT_USERAGENT => (string)($options['user_agent'] ?? 'KetarinWeb/1.0'),
            CURLOPT_HTTPHEADER => $this->headers($options),
            CURLOPT_ENCODING => '',
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
            throw new RuntimeException('HTTP ' . $code . ' for ' . $url);
        }
        return (string)$body;
    }

    private function streamRequest(string $url, string $postData, array $options): string
    {
        $headers = ['User-Agent: ' . (string)($options['user_agent'] ?? 'KetarinWeb/1.0')];
        $headers = array_merge($headers, $this->headers($options));
        $http = [
            'header' => implode("\r\n", $headers) . "\r\n",
            'timeout' => 120,
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
        return $this->decodeBody((string)$body, $http_response_header ?? []);
    }

    public function headers(array $options = [], bool $allowCompression = true): array
    {
        $headers = [
            'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
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
        return $headers;
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
