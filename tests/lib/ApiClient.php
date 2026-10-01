<?php

/**
 * HTTP-клиент к тестовому серверу. Хранит cookie между запросами,
 * так что один экземпляр — это один «браузер» со своей сессией.
 */
class ApiClient
{
    /** @var array<string, string> */
    public array $cookies = [];

    /** HTTP-код и заголовки последнего ответа (имена заголовков в нижнем регистре) */
    public int $lastStatus = 0;
    public array $lastHeaders = [];

    public function __construct(private string $baseUrl)
    {
    }

    /**
     * POST multipart/form-data. Значения-CURLFile уходят как файлы.
     * Вложенные массивы (param[]=...) передаются как query-строка, чтобы PHP собрал их в массив.
     */
    public function post(string $path, array $fields = []): array
    {
        $hasFile = (bool) array_filter($fields, fn($v) => $v instanceof CURLFile);
        $body = $hasFile ? $fields : http_build_query($fields);

        return $this->request($path, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => $body]);
    }

    public function get(string $path, array $query = []): array
    {
        return $this->request($path . ($query ? '?' . http_build_query($query) : ''), []);
    }

    private function request(string $path, array $options): array
    {
        $headers = [];
        $ch = curl_init($this->baseUrl . $path);
        curl_setopt_array($ch, $options + [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_HTTPHEADER     => $this->cookies ? ['Cookie: ' . $this->cookieHeader()] : [],
            CURLOPT_HEADERFUNCTION => function ($ch, $line) use (&$headers) {
                if (str_contains($line, ':')) {
                    [$name, $value] = explode(':', $line, 2);
                    $headers[strtolower(trim($name))][] = trim($value);
                }
                return strlen($line);
            },
        ]);

        $body = curl_exec($ch);
        if ($body === false) {
            throw new RuntimeException('HTTP request failed: ' . curl_error($ch));
        }
        $this->lastStatus  = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $this->lastHeaders = $headers;

        foreach ($headers['set-cookie'] ?? [] as $cookie) {
            $this->applySetCookie($cookie);
        }

        $json = json_decode($body, true);
        if (!is_array($json)) {
            throw new RuntimeException("Response is not JSON (HTTP {$this->lastStatus}): $body");
        }
        return $json;
    }

    private function applySetCookie(string $header): void
    {
        $parts = array_map('trim', explode(';', $header));
        [$name, $value] = explode('=', array_shift($parts), 2);

        $expired = $value === '' || $value === 'deleted';
        foreach ($parts as $attr) {
            if (stripos($attr, 'expires=') === 0 && strtotime(substr($attr, 8)) < time()) {
                $expired = true;
            }
        }

        if ($expired) {
            unset($this->cookies[$name]);
        } else {
            $this->cookies[$name] = $value;
        }
    }

    private function cookieHeader(): string
    {
        return implode('; ', array_map(fn($k, $v) => "$k=$v", array_keys($this->cookies), $this->cookies));
    }
}
