<?php
declare(strict_types=1);

namespace Kalk\Support;

final class HttpException extends \RuntimeException
{
}

/**
 * Cienka warstwa nad cURL: poprawny User-Agent, kompresja, ponawianie prób.
 */
final class HttpClient
{
    private int $timeout;
    private int $connectTimeout;
    private int $retries;

    public function __construct(?int $timeout = null, ?int $connectTimeout = null, ?int $retries = null)
    {
        if (!function_exists('curl_init')) {
            throw new HttpException('Rozszerzenie PHP "curl" jest wymagane (php-curl).');
        }
        $this->timeout = $timeout ?? Config::int('http_timeout', 180);
        $this->connectTimeout = $connectTimeout ?? Config::int('http_connect_timeout', 15);
        $this->retries = $retries ?? Config::int('http_retries', 2);
    }

    /** @param array<string,scalar> $query */
    public function getJson(string $url, array $query = [], array $headers = []): array
    {
        if ($query !== []) {
            $url .= (str_contains($url, '?') ? '&' : '?') . http_build_query($query);
        }
        return $this->decode($this->request('GET', $url, null, $headers), $url);
    }

    /** @param array<string,scalar> $fields */
    public function postJson(string $url, array $fields, array $headers = []): array
    {
        $body = http_build_query($fields);
        $headers[] = 'Content-Type: application/x-www-form-urlencoded';
        return $this->decode($this->request('POST', $url, $body, $headers), $url);
    }

    private function decode(string $raw, string $url): array
    {
        $data = json_decode($raw, true);
        if (!is_array($data)) {
            $preview = substr(trim($raw), 0, 300);
            throw new HttpException("Odpowiedź z {$url} nie jest poprawnym JSON-em. Początek treści: {$preview}");
        }
        return $data;
    }

    public function request(string $method, string $url, ?string $body, array $headers = []): string
    {
        $headers[] = 'User-Agent: ' . Config::userAgent();
        $headers[] = 'Accept: application/json';
        $headers[] = 'Accept-Language: pl,en;q=0.7';

        $attempt = 0;
        $lastError = '';
        while ($attempt <= $this->retries) {
            $ch = curl_init();
            curl_setopt_array($ch, [
                CURLOPT_URL            => $url,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_CUSTOMREQUEST  => $method,
                CURLOPT_HTTPHEADER     => $headers,
                CURLOPT_TIMEOUT        => $this->timeout,
                CURLOPT_CONNECTTIMEOUT => $this->connectTimeout,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_MAXREDIRS      => 5,
                CURLOPT_ENCODING       => '', // gzip/deflate
                CURLOPT_USERAGENT      => Config::userAgent(),
            ]);
            if ($body !== null) {
                curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
            }
            $response = curl_exec($ch);
            $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            $curlError = curl_error($ch);
            curl_close($ch);

            if ($response !== false && $status >= 200 && $status < 300) {
                return (string) $response;
            }

            $lastError = $curlError !== ''
                ? "błąd połączenia: {$curlError}"
                : "kod HTTP {$status}" . ($response !== false ? ' - ' . substr(trim((string) $response), 0, 200) : '');

            // 4xx (poza 429) nie ma sensu ponawiać.
            if ($status >= 400 && $status < 500 && $status !== 429) {
                break;
            }
            $attempt++;
            if ($attempt <= $this->retries) {
                sleep(2 ** $attempt);
            }
        }

        throw new HttpException("Zapytanie {$method} {$url} nie powiodło się ({$lastError}).");
    }
}
