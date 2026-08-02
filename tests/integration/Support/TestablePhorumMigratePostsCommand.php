<?php

namespace InfamousQ\FlarumPhorumMigrationTool\Tests\integration\Support;

use Flarum\Settings\SettingsRepositoryInterface;
use InfamousQ\FlarumPhorumMigrationTool\Console\PhorumMigratePostsCommand;
use InfamousQ\FlarumPhorumMigrationTool\Phorum\Connector;

class TestablePhorumMigratePostsCommand extends PhorumMigratePostsCommand
{
    use TestableCommandSetup;

    public function __construct(SettingsRepositoryInterface $settings)
    {
        parent::__construct($settings);
        $this->setUpTestable();
    }

    /**
     * Rebuilds the user/discussion maps from phorum_mapping itself (exactly
     * like fire() does), rather than accepting them as arguments - so callers
     * can exercise the same "reload prerequisites from the DB" path a real
     * standalone `phorum:migrate:posts` invocation would use.
     */
    public function runStep(Connector $connector): void
    {
        $users = $this->loadUserMap();
        $discussions = $this->loadDiscussionMap();

        $this->importPhorumMessages($connector, $discussions, $users);
    }
}
