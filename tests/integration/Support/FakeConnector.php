<?php

namespace InfamousQ\FlarumPhorumMigrationTool\Tests\integration\Support;

use InfamousQ\FlarumPhorumMigrationTool\Phorum\Connector;

/**
 * Test double for Connector that skips the real PDO/MySQL connection and
 * instead serves fixture rows supplied by the test. Connector's own methods
 * don't declare return types (they normally hand back a \PDOStatement), so
 * returning plain arrays here satisfies every caller's foreach-only usage.
 */
class FakeConnector extends Connector
{
    public array $userGroups = [];
    public array $users = [];
    public array $userGroupMap = [];
    public array $forums = [];
    public array $threadStartingMessages = [];

    /** @var array Keyed by Phorum thread id => array of message rows */
    public array $threadMessages = [];

    public function __construct()
    {
        // Intentionally does not call parent::__construct() - no real DB connection.
    }

    public function getUserGroups()
    {
        return $this->userGroups;
    }

    public function getUsers()
    {
        return $this->users;
    }

    public function getUserToUserGroupMap()
    {
        return $this->userGroupMap;
    }

    public function getForums()
    {
        return $this->forums;
    }

    public function getThreadStartingMessages($limit = null)
    {
        return $this->threadStartingMessages;
    }

    public function getThreadMessages(int $thread_id)
    {
        return $this->threadMessages[$thread_id] ?? [];
    }
}
