<?php

namespace InfamousQ\FlarumPhorumMigrationTool\Tests\integration;

use Flarum\Testing\integration\ConsoleTestCase as FlarumConsoleTestCase;

class TestCase extends FlarumConsoleTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Dependencies must be enabled before the extension that depends on them,
        // since ExtensionManager::enable() checks getExtensionDependencyIds().
        $this->extension('flarum-tags', 'flarum-sticky', 'flarum-lock', 'infamousq-phorum-migration-tool');

        $this->prepareDatabase([
            'phorum_mapping' => [],
        ]);

        $this->app();
    }
}
