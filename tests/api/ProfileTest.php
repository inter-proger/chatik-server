<?php

class ProfileTest extends TestCase
{
    // ---- /profile/get ----

    public function testGet(): void
    {
        [$client, $user] = $this->signup('alice', 'Alice');

        $res = $client->post('/profile/get');

        $this->assertSuccess($res);
        $this->assertSame($user, $res['response']);
    }

    public function testGetNotAuthenticated(): void
    {
        $this->assertError(ErrorCode::NOT_AUTHENTICATED, $this->client()->post('/profile/get'));
    }

    public function testGetWithForgedSessionCookie(): void
    {
        $client = $this->client();
        $client->cookies[session_name()] = 'forgedsessionid1234567890';

        $this->assertError(ErrorCode::NOT_AUTHENTICATED, $client->post('/profile/get'));
    }

    public function testGetAfterUserDeleted(): void
    {
        [$client, $user] = $this->signup('alice');
        DB::get()->prepare('DELETE FROM users WHERE id = ?')->execute([$user['userId']]);

        $this->assertError(ErrorCode::NOT_AUTHENTICATED, $client->post('/profile/get'));

        // Сессия сброшена: пользователь с тем же id после пересоздания не «унаследует» её
        $this->insertUser('reborn');
        DB::get()->exec('UPDATE users SET id = ' . $user['userId']);
        $this->assertError(ErrorCode::NOT_AUTHENTICATED, $client->post('/profile/get'));
    }

    // ---- /profile/setAvatar ----

    public function testSetAvatar(): void
    {
        [$client, $user] = $this->signup('alice');
        $fileId = $this->insertFile($user['userId']);

        $res = $client->post('/profile/setAvatar', ['fileId' => $fileId]);

        $this->assertSuccess($res);
        $this->assertSame($fileId, $res['response']['avatar']['fileId']);
        $this->assertSame($fileId, $client->post('/profile/get')['response']['avatar']['fileId']);
    }

    public function testSetAvatarNotAuthenticated(): void
    {
        $this->assertError(ErrorCode::NOT_AUTHENTICATED, $this->client()->post('/profile/setAvatar', ['fileId' => 1]));
    }

    public function testSetAvatarInvalidFileId(): void
    {
        [$client] = $this->signup('alice');

        foreach (['', '0', '-1', 'abc', '1.5'] as $fileId) {
            $this->assertError(ErrorCode::FILE_NOT_FOUND, $client->post('/profile/setAvatar', ['fileId' => $fileId]));
        }
    }

    public function testSetAvatarMissingFile(): void
    {
        [$client] = $this->signup('alice');

        $this->assertError(ErrorCode::FILE_NOT_FOUND, $client->post('/profile/setAvatar', ['fileId' => 777]));
    }

    public function testSetAvatarForeignFile(): void
    {
        [$client] = $this->signup('alice');
        $fileId = $this->insertFile($this->insertUser('bob'));

        $this->assertError(ErrorCode::FILE_NOT_FOUND, $client->post('/profile/setAvatar', ['fileId' => $fileId]));
    }

    public function testSetAvatarNotImage(): void
    {
        [$client, $user] = $this->signup('alice');
        $fileId = $this->insertFile($user['userId'], 'other', []);

        $this->assertError(ErrorCode::FILE_NOT_IMAGE, $client->post('/profile/setAvatar', ['fileId' => $fileId]));
    }

    // ---- /profile/removeAvatar ----

    public function testRemoveAvatar(): void
    {
        [$client, $user] = $this->signup('alice');
        $client->post('/profile/setAvatar', ['fileId' => $this->insertFile($user['userId'])]);

        $res = $client->post('/profile/removeAvatar');

        $this->assertSuccess($res);
        $this->assertNull($res['response']['avatar']);
        $this->assertNull($client->post('/profile/get')['response']['avatar']);
    }

    public function testRemoveAvatarWhenNone(): void
    {
        [$client] = $this->signup('alice');

        $this->assertNull($client->post('/profile/removeAvatar')['response']['avatar']);
    }

    public function testRemoveAvatarNotAuthenticated(): void
    {
        $this->assertError(ErrorCode::NOT_AUTHENTICATED, $this->client()->post('/profile/removeAvatar'));
    }
}
