<?php

require_once __DIR__ . '/../../config/session.php';

class RouterTest extends TestCase
{
    public function testUnknownRoute(): void
    {
        $client = $this->client();
        $res = $client->get('/nope');

        $this->assertSame(404, $client->lastStatus);
        $this->assertSame(['status' => 0, 'errorCode' => 404], $res);
    }

    public function testRouteIsMatchedWithoutQueryString(): void
    {
        $client = $this->client();
        $client->get('/files/get?fileId=1');

        $this->assertSame(200, $client->lastStatus);
    }

    public function testRouteIsCaseSensitive(): void
    {
        $client = $this->client();
        $client->get('/Files/Get');

        $this->assertSame(404, $client->lastStatus);
    }

    public function testJsonContentType(): void
    {
        $client = $this->client();
        $client->get('/files/get');

        $this->assertSame(['application/json; charset=utf-8'], $client->lastHeaders['content-type']);
    }

    public function testSessionCookieIsExtendedOnAuthorizedRequest(): void
    {
        [$client] = $this->signup('alice');

        $client->post('/profile/get');

        $cookie = $this->sessionCookie($client);
        $this->assertTrue($cookie !== null, 'Session cookie must be re-sent');
        $this->assertTrue(stripos($cookie, 'HttpOnly') !== false, 'Cookie must be HttpOnly');
        $this->assertTrue(stripos($cookie, 'SameSite=Lax') !== false, 'Cookie must be SameSite=Lax');
        // BASE_URL в тестовом конфиге на http — флаг Secure не ставится
        $this->assertFalse(stripos($cookie, 'secure') !== false, 'Cookie must not be Secure on http');

        preg_match('/Max-Age=(\d+)/i', $cookie, $m);
        $this->assertSame(SESSION_LIFETIME, (int) ($m[1] ?? 0));
    }

    public function testLogoutResponseIsEmptyObject(): void
    {
        [$client] = $this->signup('alice');

        $raw = $this->rawPost('/auth/logout', [], $client->cookies);

        $this->assertSame('{"status":1,"response":{}}', $raw);
    }

    private function sessionCookie(ApiClient $client): ?string
    {
        foreach ($client->lastHeaders['set-cookie'] ?? [] as $header) {
            if (str_starts_with($header, session_name() . '=')) {
                return $header;
            }
        }
        return null;
    }

    private function rawPost(string $path, array $fields, array $cookies = []): string
    {
        $ch = curl_init(self::$baseUrl . $path);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => http_build_query($fields),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => $cookies
                ? ['Cookie: ' . implode('; ', array_map(fn($k, $v) => "$k=$v", array_keys($cookies), $cookies))]
                : [],
        ]);

        return curl_exec($ch);
    }
}
