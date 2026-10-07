<?php

namespace InfamousQ\FlarumPhorumMigrationTool\Tests\integration\Support;

use Flarum\Settings\SettingsRepositoryInterface;
use InfamousQ\FlarumPhorumMigrationTool\Console\PhorumMigrateUsersCommand;
use InfamousQ\FlarumPhorumMigrationTool\Phorum\Connector;

class TestablePhorumMigrateUsersCommand extends PhorumMigrateUsersCommand
{
    use TestableCommandSetup;

    public function __construct(SettingsRepositoryInterface $settings)
    {
        parent::__construct($settings);
        $this->setUpTestable();
    }

    public function runStep(Connector $connector): array
    {
        return $this->importUsers($connector);
    }

    public function runPreflight(Connector $connector): bool
    {
        return $this->runPreflightChecks($connector);
    }
}
