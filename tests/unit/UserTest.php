<?php

require_once __DIR__ . '/../../core/User.php';

class UserTest extends TestCase
{
    public function testFromRowWithoutAvatar(): void
    {
        $user = User::fromRow(['id' => '5', 'login' => 'bob', 'name' => 'Bob', 'f_id' => null]);

        $this->assertSame(['userId' => 5, 'login' => 'bob', 'name' => 'Bob', 'avatar' => null], $user);
    }

    public function testFromRowWithAvatar(): void
    {
        $user = User::fromRow([
            'id' => 5, 'login' => 'bob', 'name' => 'Bob',
            'f_id' => '9', 'f_type' => 'image', 'f_path' => 'static/uploads/a.png', 'f_size' => '10',
            'f_filename' => 'a.png', 'f_extension' => 'png', 'f_data' => '{"small_path":"static/uploads/a_small.png"}',
        ]);

        $this->assertSame(9, $user['avatar']['fileId']);
        $this->assertSame('http://files.test/static/uploads/a_small.png', $user['avatar']['data']->smallUrl);
    }

    public function testFindByIdMissing(): void
    {
        $this->assertNull(User::findById(999));
    }

    public function testFindByIdLoadsAvatar(): void
    {
        $id     = $this->insertUser('alice', 'Alice');
        $fileId = $this->insertFile($id);
        DB::get()->prepare('UPDATE users SET avatar_file_id = ? WHERE id = ?')->execute([$fileId, $id]);

        $user = User::findById($id);

        $this->assertSame('Alice', $user['name']);
        $this->assertSame($fileId, $user['avatar']['fileId']);
    }

    public function testFindByIdsSkipsMissingAndDeduplicates(): void
    {
        $a = $this->insertUser('a');
        $b = $this->insertUser('b');

        $users = User::findByIds([$b, $a, $b, 999, (string) $a]);

        $this->assertCount(2, $users);
        $this->assertSame('a', $users[$a]['login']);
        $this->assertSame('b', $users[$b]['login']);
    }

    public function testFindByIdsEmpty(): void
    {
        $this->assertSame([], User::findByIds([]));
    }

    public function testFindListOrdersByIdAndLimits(): void
    {
        foreach (['u1', 'u2', 'u3'] as $login) {
            $this->insertUser($login);
        }

        $this->assertSame(['u1', 'u2'], array_column(User::findList(null, null, 2), 'login'));
    }

    public function testFindListByMaxId(): void
    {
        $first = $this->insertUser('u1');
        $this->insertUser('u2');

        $this->assertSame(['u2'], array_column(User::findList(null, $first, 10), 'login'));
    }

    public function testFindListSearchesNameAndLogin(): void
    {
        $this->insertUser('ivan', 'Иван Петров');
        $this->insertUser('petrov_login', 'Someone');
        $this->insertUser('other', 'Other');

        // Поиск без учёта регистра, в том числе для кириллицы
        $this->assertSame(['ivan'], array_column(User::findList('петров', null, 10), 'login'));
        $this->assertSame(['petrov_login'], array_column(User::findList('PETROV', null, 10), 'login'));
    }

    public function testFindListEscapesLikeWildcards(): void
    {
        $this->insertUser('abc', 'abc');
        $this->insertUser('a_c', 'a_c');

        $this->assertSame(['a_c'], array_column(User::findList('_', null, 10), 'login'));
        $this->assertSame([], User::findList('%', null, 10));
    }
}
