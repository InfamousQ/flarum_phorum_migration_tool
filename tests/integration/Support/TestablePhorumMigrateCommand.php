<?php

namespace InfamousQ\FlarumPhorumMigrationTool\Tests\integration\Support;

use Flarum\Settings\SettingsRepositoryInterface;
use InfamousQ\FlarumPhorumMigrationTool\Console\PhorumMigrateCommand;
use InfamousQ\FlarumPhorumMigrationTool\Model\PhorumMapping;
use InfamousQ\FlarumPhorumMigrationTool\Phorum\Connector;
use Psr\Log\NullLogger;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

/**
 * Exposes PhorumMigrateCommand's protected per-step import methods as public
 * methods, so integration tests can exercise (and assert on) each step of the
 * migration pipeline individually against a FakeConnector, without having to
 * go through a real `phorum:migrate` console invocation (which would require
 * a real Connector/DB per settings).
 */
class TestablePhorumMigrateCommand extends PhorumMigrateCommand
{
    /** @var BufferedOutput */
    public $bufferedOutput;

    public function __construct(SettingsRepositoryInterface $settings)
    {
        parent::__construct($settings);

        // Populate the protected $input/$output/$logger properties normally
        // set up by Command::run()/execute(), since we call the import*
        // methods directly instead of going through fire().
        $this->input = new ArrayInput([]);
        $this->bufferedOutput = new BufferedOutput();
        $this->output = $this->bufferedOutput;
        $this->setLogger(new NullLogger());
    }

    public function runImportUserGroups(Connector $connector): array
    {
        return $this->importUserGroups($connector);
    }

    public function runImportUsers(Connector $connector): array
    {
        return $this->importUsers($connector);
    }

    public function runImportUserGroupMapping(Connector $connector, array $userGroups, array $users): void
    {
        $this->importUserGroupMapping($connector, $userGroups, $users);
    }

    public function runImportPhorumForumsAsTags(Connector $connector): array
    {
        return $this->importPhorumForumsAsTags($connector);
    }

    public function runImportPhorumMessagesAsDiscussions(Connector $connector, array $users, array $tags): array
    {
        return $this->importPhorumMessagesAsDiscussions($connector, $users, $tags);
    }

    /**
     * Mirrors PhorumMigrateCommand::importPhorumMessageForThread(), but preloads/persists
     * the Phorum-id-to-Flarum-id mapping for just this one thread's messages, so existing
     * per-thread tests (which exercise one thread at a time against a FakeConnector) don't
     * need to know about the shared preload/bulk-insert bookkeeping importPhorumMessages()
     * does across threads in a real run.
     */
    public function runImportPhorumMessageForThread(Connector $connector, int $phorumThreadId, $discussionId, array $users): array
    {
        $messages = $connector->getAllThreadMessages();
        $threadMessages = array_values(array_filter($messages, fn ($message) => $message['thread'] == $phorumThreadId));

        $messageIdToPostId = PhorumMapping::where('phorum_data_type', PhorumMapping::DATA_TYPE_MESSAGE)
            ->pluck('flarum_id', 'phorum_id')
            ->all();
        $newMappings = [];

        $posts = $this->importPhorumMessageForThread($phorumThreadId, $discussionId, $users, $threadMessages, $messageIdToPostId, $newMappings);

        if (!empty($newMappings)) {
            PhorumMapping::insert($newMappings);
        }

        return $posts;
    }

    /**
     * Runs the same sequence of steps as fire(), against a supplied
     * (fake) Connector, mirroring PhorumMigrateCommand::fire()'s orchestration
     * without needing settings-based DB credentials.
     */
    public function runFullMigration(Connector $connector): void
    {
        $userGroups = $this->runImportUserGroups($connector);
        $users = $this->runImportUsers($connector);
        $this->runImportUserGroupMapping($connector, $userGroups, $users);
        $tags = $this->runImportPhorumForumsAsTags($connector);
        $discussions = $this->runImportPhorumMessagesAsDiscussions($connector, $users, $tags);
        $this->importPhorumMessages($connector, $discussions, $users);
    }
}
