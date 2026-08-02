<?php

namespace InfamousQ\FlarumPhorumMigrationTool\Tests\integration\Support;

use Flarum\Settings\SettingsRepositoryInterface;
use InfamousQ\FlarumPhorumMigrationTool\Console\PhorumMigrateUserGroupsCommand;
use InfamousQ\FlarumPhorumMigrationTool\Phorum\Connector;

class TestablePhorumMigrateUserGroupsCommand extends PhorumMigrateUserGroupsCommand
{
    use TestableCommandSetup;

    public function __construct(SettingsRepositoryInterface $settings)
    {
        parent::__construct($settings);
        $this->setUpTestable();
    }

    public function loadGroupMapPublic(): array
    {
        return $this->loadGroupMap();
    }

    public function loadUserMapPublic(): array
    {
        return $this->loadUserMap();
    }

    /**
     * Rebuilds the group/user maps from phorum_mapping itself (exactly like
     * fire() does), rather than accepting them as arguments - so callers can
     * exercise the same "reload prerequisites from the DB" path a real
     * standalone `phorum:migrate:user-groups` invocation would use.
     */
    public function runStep(Connector $connector): void
    {
        $groups = $this->loadGroupMap();
        $users = $this->loadUserMap();
        $this->importUserGroupMapping($connector, $groups, $users);
    }
}
