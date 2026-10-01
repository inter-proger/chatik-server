<?php

require_once __DIR__ . '/../../core/Conversation.php';

class ConversationTest extends TestCase
{
    public function testFromRow(): void
    {
        $conversation = Conversation::fromRow(
            ['id' => '3', 'type' => 'group', 'name' => 'Team', 'f_id' => null],
            [1, 2],
            null
        );

        $this->assertSame([
            'conversationId' => 3,
            'type'           => 'group',
            'memberIds'      => [1, 2],
            'name'           => 'Team',
            'avatar'         => null,
            'opponentUserId' => null,
        ], $conversation);
    }

    public function testFromRowWithAvatar(): void
    {
        $conversation = Conversation::fromRow([
            'id' => 3, 'type' => 'group', 'name' => 'Team',
            'f_id' => 4, 'f_type' => 'image', 'f_path' => 'static/uploads/g.png', 'f_size' => 1,
            'f_filename' => 'g.png', 'f_extension' => 'png', 'f_data' => '{}',
        ], [], null);

        $this->assertSame(4, $conversation['avatar']['fileId']);
    }

    public function testFindOrCreateSingleCreates(): void
    {
        $a = $this->insertUser('a');
        $b = $this->insertUser('b');

        $conversation = Conversation::findOrCreateSingle($b, $a);

        $this->assertSame('single', $conversation['type']);
        $this->assertSame([$a, $b], $conversation['memberIds']);
        $this->assertSame($a, $conversation['opponentUserId']);
        $this->assertNull($conversation['name']);

        $key = DB::get()->query('SELECT single_key FROM conversations')->fetchColumn();
        $this->assertSame("{$a}_{$b}", $key);
    }

    public function testFindOrCreateSingleReturnsExistingInAnyOrder(): void
    {
        $a = $this->insertUser('a');
        $b = $this->insertUser('b');

        $first  = Conversation::findOrCreateSingle($a, $b);
        $second = Conversation::findOrCreateSingle($b, $a);

        $this->assertSame($first['conversationId'], $second['conversationId']);
        $this->assertSame($b, $first['opponentUserId']);
        $this->assertSame($a, $second['opponentUserId']);
        $this->assertSame('1', (string) DB::get()->query('SELECT COUNT(*) FROM conversations')->fetchColumn());
    }

    public function testFindByIdMissing(): void
    {
        $this->assertNull(Conversation::findById(42, 1));
    }

    public function testFindByIdForNonMemberViewer(): void
    {
        $a = $this->insertUser('a');
        $b = $this->insertUser('b');
        $c = $this->insertUser('c');
        $id = Conversation::findOrCreateSingle($a, $b)['conversationId'];

        // Для постороннего собеседник — первый участник, отличный от него
        $this->assertSame($a, Conversation::findById($id, $c)['opponentUserId']);
    }

    public function testFindListByMemberOnlyOwnNewestFirst(): void
    {
        $a = $this->insertUser('a');
        $b = $this->insertUser('b');
        $c = $this->insertUser('c');

        $ab = Conversation::findOrCreateSingle($a, $b)['conversationId'];
        $bc = Conversation::findOrCreateSingle($b, $c)['conversationId'];
        $ac = Conversation::findOrCreateSingle($a, $c)['conversationId'];

        $list = Conversation::findListByMember($a, 0, 10);
        $this->assertSame([$ac, $ab], array_column($list, 'conversationId'));
        $this->assertSame([$c, $b], array_column($list, 'opponentUserId'));

        $this->assertSame([$ab], array_column(Conversation::findListByMember($b, 1, 1), 'conversationId'));
    }

    public function testFindListByMemberEmpty(): void
    {
        $this->assertSame([], Conversation::findListByMember($this->insertUser('a'), 0, 10));
    }

    public function testGroupHasNoOpponent(): void
    {
        $a  = $this->insertUser('a');
        $db = DB::get();
        $db->exec("INSERT INTO conversations (type, name) VALUES ('group', 'Team')");
        $id = (int) $db->lastInsertId();
        $db->prepare('INSERT INTO conversation_members (conversation_id, user_id) VALUES (?, ?)')->execute([$id, $a]);

        $conversation = Conversation::findById($id, $a);

        $this->assertSame('Team', $conversation['name']);
        $this->assertSame([$a], $conversation['memberIds']);
        $this->assertNull($conversation['opponentUserId']);
    }
}
