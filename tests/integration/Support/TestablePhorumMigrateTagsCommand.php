<?php

namespace InfamousQ\FlarumPhorumMigrationTool\Tests\integration\Support;

use Flarum\Settings\SettingsRepositoryInterface;
use InfamousQ\FlarumPhorumMigrationTool\Console\PhorumMigrateTagsCommand;
use InfamousQ\FlarumPhorumMigrationTool\Phorum\Connector;

class TestablePhorumMigrateTagsCommand extends PhorumMigrateTagsCommand
{
    use TestableCommandSetup;

    public function __construct(SettingsRepositoryInterface $settings)
    {
        parent::__construct($settings);
        $this->setUpTestable();
    }

    public function runStep(Connector $connector): array
    {
        return $this->importPhorumForumsAsTags($connector);
    }
}
