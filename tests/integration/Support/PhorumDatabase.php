<?php

namespace InfamousQ\FlarumPhorumMigrationTool\Tests\integration\Support;

use Flarum\Testing\integration\UsesTmpDir;
use InfamousQ\FlarumPhorumMigrationTool\Phorum\Connector;

/**
 * Phorum-shaped tables (only the columns Connector reads) in the test database,
 * for exercising Connector's real SQL instead of FakeConnector's fixture arrays.
 * Uses a connection of its own, so the tables are committed and visible to
 * Connector no matter what transaction Flarum's connection is in, and prefixes
 * them so they can't clash with Flarum's.
 */
class PhorumDatabase
{
    use UsesTmpDir;

    const PREFIX = 'phorumtest_';

    const TABLES = [
        'groups' => 'group_id INT PRIMARY KEY, name VARCHAR(255) NOT NULL',
        'users' => 'user_id INT PRIMARY KEY, display_name VARCHAR(255) NOT NULL DEFAULT \'\', real_name VARCHAR(255) NOT NULL DEFAULT \'\',
            email VARCHAR(255) NOT NULL DEFAULT \'\', active TINYINT NOT NULL DEFAULT 1, admin TINYINT NOT NULL DEFAULT 0',
        'user_group_xref' => 'user_id INT NOT NULL, group_id INT NOT NULL, status TINYINT NOT NULL DEFAULT 1, PRIMARY KEY (user_id, group_id)',
        'forums' => 'forum_id INT PRIMARY KEY, name VARCHAR(50), description TEXT, parent_id INT NOT NULL DEFAULT 0,
            display_order INT NOT NULL DEFAULT 0, pub_perms INT NOT NULL DEFAULT 0, reg_perms INT NOT NULL DEFAULT 0,
            active TINYINT NOT NULL DEFAULT 1, folder_flag TINYINT NOT NULL DEFAULT 0',
        'forum_group_xref' => 'forum_id INT NOT NULL, group_id INT NOT NULL, permission INT NOT NULL DEFAULT 0, PRIMARY KEY (forum_id, group_id)',
        'messages' => 'message_id INT PRIMARY KEY, forum_id INT NOT NULL, thread INT NOT NULL, parent_id INT NOT NULL DEFAULT 0,
            user_id INT NOT NULL DEFAULT 0, subject VARCHAR(255), body TEXT, status TINYINT NOT NULL DEFAULT 2,
            sort TINYINT NOT NULL DEFAULT 2, closed TINYINT NOT NULL DEFAULT 0, datestamp INT NOT NULL DEFAULT 0,
            moved TINYINT NOT NULL DEFAULT 0, KEY user_id (user_id), KEY thread_message (thread, message_id)',
    ];

    /** @var \PDO */
    public $pdo;

    public function __construct()
    {
        // The database `composer test:setup` set up for the integration tests
        $db = (include $this->tmpDir().'/config.php')['database'];
        $this->pdo = new \PDO(
            Connector::buildDsn($db['host'], $db['database']).";port={$db['port']}",
            $db['username'],
            $db['password']
        );
        $this->pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
    }

    public function createTables(): void
    {
        $this->dropTables();
        foreach (self::TABLES as $table => $columns) {
            $this->pdo->exec('CREATE TABLE '.self::PREFIX."{$table} ({$columns}) DEFAULT CHARSET=utf8mb4");
        }
    }

    public function dropTables(): void
    {
        $tables = array_map(fn ($table) => self::PREFIX.$table, array_keys(self::TABLES));
        $this->pdo->exec('DROP TABLE IF EXISTS '.implode(', ', $tables));
    }

    public function insert(string $table, array $row): void
    {
        $this->insertMany($table, [$row]);
    }

    /**
     * @param array[] $rows Rows sharing the same columns
     */
    public function insertMany(string $table, array $rows): void
    {
        foreach (array_chunk($rows, 1000) as $chunk) {
            $columns = implode(', ', array_keys($chunk[0]));
            $row_placeholders = '('.implode(', ', array_fill(0, count($chunk[0]), '?')).')';
            $placeholders = implode(', ', array_fill(0, count($chunk), $row_placeholders));
            $this->pdo->prepare('INSERT INTO '.self::PREFIX."{$table} ({$columns}) VALUES {$placeholders}")
                ->execute(array_merge(...array_map('array_values', $chunk)));
        }
    }

    /**
     * A Connector reading these tables over this connection, since Connector
     * itself can't be given a port.
     */
    public function connector(): Connector
    {
        return new class($this->pdo, self::PREFIX) extends Connector {
            public function __construct(\PDO $pdo, string $prefix)
            {
                $this->pdo = $pdo;
                $this->table_prefix = $prefix;
            }
        };
    }
}
