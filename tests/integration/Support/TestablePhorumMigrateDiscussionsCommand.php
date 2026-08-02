<?php

namespace InfamousQ\FlarumPhorumMigrationTool\Tests\integration\Support;

use Flarum\Settings\SettingsRepositoryInterface;
use InfamousQ\FlarumPhorumMigrationTool\Console\PhorumMigrateDiscussionsCommand;
use InfamousQ\FlarumPhorumMigrationTool\Phorum\Connector;

class TestablePhorumMigrateDiscussionsCommand extends PhorumMigrateDiscussionsCommand
{
    use TestableCommandSetup;

    public function __construct(SettingsRepositoryInterface $settings)
    {
        parent::__construct($settings);
        $this->setUpTestable();
    }

    /**
     * Rebuilds the user/tag maps from phorum_mapping itself (exactly like
     * fire() does), rather than accepting them as arguments - so callers can
     * exercise the same "reload prerequisites from the DB" path a real
     * standalone `phorum:migrate:discussions` invocation would use.
     */
    public function runStep(Connector $connector): array
    {
        $users = $this->loadUserMap();
        $tags = $this->loadTagMap();

        return $this->importPhorumMessagesAsDiscussions($connector, $users, $tags);
    }
}
