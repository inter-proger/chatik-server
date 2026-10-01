<?php

class AuthTest extends TestCase
{
    // ---- /auth/signup ----

    public function testSignupReturnsUserAndLogsIn(): void
    {
        $client = $this->client();
        $res = $client->post('/auth/signup', ['login' => 'alice_1', 'password' => 'Passw0rd', 'name' => 'Алиса Test']);

        $this->assertSuccess($res);
        $this->assertSame(['userId' => 1, 'login' => 'alice_1', 'name' => 'Алиса Test', 'avatar' => null], $res['response']);

        $this->assertSuccess($client->post('/profile/get'));
    }

    public function testSignupTrimsFields(): void
    {
        $res = $this->client()->post('/auth/signup', ['login' => ' bob ', 'password' => ' Passw0rd ', 'name' => ' Bob ']);

        $this->assertSame('bob', $res['response']['login']);
        $this->assertSame('Bob', $res['response']['name']);
        $this->assertSuccess($this->client()->post('/auth/login', ['login' => 'bob', 'password' => 'Passw0rd']));
    }

    public function testSignupStoresPasswordHashed(): void
    {
        $this->signup('alice');

        $hash = DB::get()->query("SELECT password_hash FROM users WHERE login = 'alice'")->fetchColumn();
        $this->assertTrue(password_verify('Passw0rd', $hash));
    }

    public function testSignupInvalidLogin(): void
    {
        foreach (['', 'with space', 'dash-dash', 'точка.', str_repeat('a', 65)] as $login) {
            $res = $this->client()->post('/auth/signup', ['login' => $login, 'password' => 'Passw0rd', 'name' => 'N']);
            $this->assertError(ErrorCode::INVALID_LOGIN, $res);
        }
    }

    public function testSignupLoginMaxLength(): void
    {
        $res = $this->client()->post('/auth/signup', ['login' => str_repeat('a', 64), 'password' => 'Passw0rd', 'name' => 'N']);
        $this->assertSuccess($res);
    }

    public function testSignupInvalidPassword(): void
    {
        foreach (['Pass0rd', 'password1', 'PASSWORD1', 'Password', ''] as $password) {
            $res = $this->client()->post('/auth/signup', ['login' => 'alice', 'password' => $password, 'name' => 'N']);
            $this->assertError(ErrorCode::INVALID_PASSWORD, $res);
        }
    }

    public function testSignupInvalidName(): void
    {
        foreach (['', '   ', 'Bob!', '<script>', str_repeat('я', 129)] as $name) {
            $res = $this->client()->post('/auth/signup', ['login' => 'alice', 'password' => 'Passw0rd', 'name' => $name]);
            $this->assertError(ErrorCode::INVALID_NAME, $res);
        }
    }

    public function testSignupNameMaxLengthCountsCharacters(): void
    {
        $res = $this->client()->post('/auth/signup', ['login' => 'alice', 'password' => 'Passw0rd', 'name' => str_repeat('я', 128)]);
        $this->assertSuccess($res);
    }

    public function testSignupValidatesLoginFirst(): void
    {
        $res = $this->client()->post('/auth/signup', ['login' => '', 'password' => '', 'name' => '']);
        $this->assertError(ErrorCode::INVALID_LOGIN, $res);
    }

    public function testSignupLoginTaken(): void
    {
        $this->signup('alice');

        $res = $this->client()->post('/auth/signup', ['login' => 'alice', 'password' => 'Passw0rd', 'name' => 'Other']);
        $this->assertError(ErrorCode::LOGIN_TAKEN, $res);
    }

    // ---- /auth/login ----

    public function testLoginReturnsUserAndLogsIn(): void
    {
        $this->insertUser('alice', 'Alice');

        $client = $this->client();
        $res = $client->post('/auth/login', ['login' => 'alice', 'password' => 'Passw0rd']);

        $this->assertSuccess($res);
        $this->assertSame(['userId' => 1, 'login' => 'alice', 'name' => 'Alice', 'avatar' => null], $res['response']);
        $this->assertSame('alice', $client->post('/profile/get')['response']['login']);
    }

    public function testLoginRegeneratesSessionId(): void
    {
        $this->insertUser('alice');
        $client = $this->client();
        $client->post('/profile/get');
        $before = $client->cookies[session_name()] ?? null;

        $client->post('/auth/login', ['login' => 'alice', 'password' => 'Passw0rd']);

        $this->assertTrue(($client->cookies[session_name()] ?? null) !== null);
        $this->assertFalse($before === $client->cookies[session_name()], 'Session id must change on login');
    }

    public function testLoginWrongPassword(): void
    {
        $this->insertUser('alice');

        $res = $this->client()->post('/auth/login', ['login' => 'alice', 'password' => 'Wrong0pass']);
        $this->assertError(ErrorCode::WRONG_CREDENTIALS, $res);
    }

    public function testLoginUnknownUser(): void
    {
        $res = $this->client()->post('/auth/login', ['login' => 'nobody', 'password' => 'Passw0rd']);
        $this->assertError(ErrorCode::WRONG_CREDENTIALS, $res);
    }

    public function testLoginInvalidLogin(): void
    {
        $res = $this->client()->post('/auth/login', ['login' => 'bad login', 'password' => 'Passw0rd']);
        $this->assertError(ErrorCode::INVALID_LOGIN, $res);
    }

    public function testLoginShortPassword(): void
    {
        $res = $this->client()->post('/auth/login', ['login' => 'alice', 'password' => '12345']);
        $this->assertError(ErrorCode::INVALID_PASSWORD, $res);
    }

    public function testLoginReturnsAvatar(): void
    {
        $id     = $this->insertUser('alice');
        $fileId = $this->insertFile($id);
        DB::get()->prepare('UPDATE users SET avatar_file_id = ? WHERE id = ?')->execute([$fileId, $id]);

        $res = $this->client()->post('/auth/login', ['login' => 'alice', 'password' => 'Passw0rd']);
        $this->assertSame($fileId, $res['response']['avatar']['fileId']);
    }

    // ---- /auth/logout ----

    public function testLogout(): void
    {
        [$client] = $this->signup('alice');

        $this->assertSuccess($client->post('/auth/logout'));
        $this->assertSame([], $client->cookies);
        $this->assertError(ErrorCode::NOT_AUTHENTICATED, $client->post('/profile/get'));
    }

    public function testLogoutInvalidatesSessionOnServer(): void
    {
        [$client] = $this->signup('alice');
        $stolen = $this->client();
        $stolen->cookies = $client->cookies;

        $client->post('/auth/logout');

        $this->assertError(ErrorCode::NOT_AUTHENTICATED, $stolen->post('/profile/get'));
    }

    public function testLogoutNotAuthenticated(): void
    {
        $this->assertError(ErrorCode::NOT_AUTHENTICATED, $this->client()->post('/auth/logout'));
    }
}
