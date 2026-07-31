<?php

namespace InfamousQ\FlarumPhorumMigrationTool\Tests\integration;

use Flarum\Testing\integration\TestCase as FlarumTestCase;

class TestCase extends FlarumTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->extension('infamousq-phorum-migration-tool');

        $this->prepareDatabase([
            'phorum_mapping' => [],
        ]);

        $this->app();
    }
}
