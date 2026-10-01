<?php

require_once __DIR__ . '/../../core/Action.php';

class ActionTest extends TestCase
{
    public function testUsersDeduplicatesAndSorts(): void
    {
        $this->assertSame(
            ['type' => 'users', 'data' => ['userIds' => [1, 3, 7]]],
            Action::users([7, '3', 1, 7, 3])
        );
    }

    public function testUsersEmpty(): void
    {
        $this->assertNull(Action::users([]));
    }
}
