<?php

namespace InfamousQ\FlarumPhorumMigrationTool\Tests\unit\Phorum;

use InfamousQ\FlarumPhorumMigrationTool\Phorum\Connector;
use PHPUnit\Framework\TestCase;

class ConnectorTest extends TestCase
{
    public function test_the_connection_uses_utf8mb4_so_4_byte_characters_survive()
    {
        $this->assertSame('mysql:host=db;dbname=phorum;charset=utf8mb4', Connector::buildDsn('db', 'phorum'));
    }
}
