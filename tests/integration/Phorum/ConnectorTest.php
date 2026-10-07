<?php

namespace InfamousQ\FlarumPhorumMigrationTool\Tests\integration\Phorum;

use InfamousQ\FlarumPhorumMigrationTool\Phorum\Connector;
use InfamousQ\FlarumPhorumMigrationTool\Tests\integration\Support\PhorumDatabase;
use PHPUnit\Framework\TestCase;

/**
 * Runs Connector's SQL against real Phorum-shaped tables in the test database,
 * which FakeConnector-based tests can't cover. Doesn't need Flarum booted.
 */
class ConnectorTest extends TestCase
{
    /** @var PhorumDatabase */
    protected $phorum;

    /** @var \PDO */
    protected $pdo;

    protected function setUp(): void
    {
        parent::setUp();

        $this->phorum = new PhorumDatabase();
        $this->phorum->createTables();
        $this->pdo = $this->phorum->pdo;
    }

    protected function tearDown(): void
    {
        $this->phorum->dropTables();

        parent::tearDown();
    }

    protected function connector(): Connector
    {
        return $this->phorum->connector();
    }

    protected function insert(string $table, array $row): void
    {
        $this->phorum->insert($table, $row);
    }

    /**
     * @test
     */
    public function it_leaves_out_folders_and_inactive_forums()
    {
        $this->insert('forums', ['forum_id' => 1, 'name' => 'Folder', 'folder_flag' => 1]);
        $this->insert('forums', ['forum_id' => 2, 'name' => 'Forum', 'parent_id' => 1]);
        $this->insert('forums', ['forum_id' => 3, 'name' => 'Inactive', 'active' => 0]);

        $forums = iterator_to_array($this->connector()->getForums());

        $this->assertSame(['2'], array_map('strval', array_column($forums, 'forum_id')));
    }

    /**
     * @test
     */
    public function it_leaves_out_thread_moved_notices()
    {
        // Thread 10 was moved from forum 1 to forum 2, leaving notice 20 behind in forum 1
        $this->insert('messages', ['message_id' => 10, 'forum_id' => 2, 'thread' => 10, 'subject' => 'Moved thread', 'body' => 'Start']);
        $this->insert('messages', ['message_id' => 11, 'forum_id' => 2, 'thread' => 10, 'parent_id' => 10, 'subject' => 'Re', 'body' => 'Reply']);
        $this->insert('messages', ['message_id' => 20, 'forum_id' => 1, 'thread' => 20, 'subject' => 'Moved thread', 'body' => '', 'moved' => 1]);
        // A guest's moved notice must not make the guest placeholder user necessary
        $this->insert('messages', ['message_id' => 30, 'forum_id' => 1, 'thread' => 30, 'user_id' => 0, 'subject' => 'Guest thread', 'body' => '', 'moved' => 1]);
        $this->pdo->exec('UPDATE '.PhorumDatabase::PREFIX.'messages SET user_id = 1 WHERE message_id IN (10, 11, 20)');

        $connector = $this->connector();
        $starting = iterator_to_array($connector->getThreadStartingMessages());
        $all = iterator_to_array($connector->getAllThreadMessages());

        $this->assertSame(['10'], array_map('strval', array_column($starting, 'thread')));
        $this->assertSame(['10', '11'], array_map('strval', array_column($all, 'message_id')));
        $this->assertFalse($connector->hasGuestMessages());
    }

    /**
     * @test
     */
    public function it_reads_4_byte_characters_intact()
    {
        $this->insert('messages', ['message_id' => 10, 'forum_id' => 1, 'thread' => 10, 'subject' => 'Emoji', 'body' => 'Hello 😀']);

        $all = iterator_to_array($this->connector()->getAllThreadMessages());

        $this->assertSame('Hello 😀', $all[0]['body']);
    }
}
