<?php

namespace InfamousQ\FlarumPhorumMigrationTool\Tests\integration\Console;

use Flarum\Discussion\Discussion;
use Flarum\Settings\SettingsRepositoryInterface;
use InfamousQ\FlarumPhorumMigrationTool\Tests\integration\Support\FakeConnector;
use InfamousQ\FlarumPhorumMigrationTool\Tests\integration\Support\RecordingLogger;
use InfamousQ\FlarumPhorumMigrationTool\Tests\integration\Support\TestablePhorumMigrateDiscussionsCommand;
use InfamousQ\FlarumPhorumMigrationTool\Tests\integration\Support\TestablePhorumMigrateTagsCommand;
use InfamousQ\FlarumPhorumMigrationTool\Tests\integration\Support\TestablePhorumMigrateUsersCommand;
use InfamousQ\FlarumPhorumMigrationTool\Tests\integration\TestCase;

class PhorumMigrateDiscussionsCommandTest extends TestCase
{
    protected function usersCommand(): TestablePhorumMigrateUsersCommand
    {
        return new TestablePhorumMigrateUsersCommand(
            $this->app()->getContainer()->make(SettingsRepositoryInterface::class)
        );
    }

    protected function tagsCommand(): TestablePhorumMigrateTagsCommand
    {
        return new TestablePhorumMigrateTagsCommand(
            $this->app()->getContainer()->make(SettingsRepositoryInterface::class)
        );
    }

    protected function command(): TestablePhorumMigrateDiscussionsCommand
    {
        return new TestablePhorumMigrateDiscussionsCommand(
            $this->app()->getContainer()->make(SettingsRepositoryInterface::class)
        );
    }

    protected function fixtureUsersAndTags(FakeConnector $connector): void
    {
        $connector->users = [
            ['user_id' => 1, 'display_name' => 'Alice', 'real_name' => '', 'email' => 'alice@example.com', 'active' => 1, 'admin' => 0],
        ];
        $connector->forums = [
            ['forum_id' => 10, 'name' => 'Phorum General', 'description' => '', 'parent_id' => 0, 'display_order' => 1, 'pub_perms' => 1, 'reg_perms' => 15],
        ];

        // Separate command instances, mirroring separate `phorum:migrate:users` /
        // `phorum:migrate:tags` CLI invocations that ran before this step.
        $this->usersCommand()->runStep($connector);
        $this->tagsCommand()->runStep($connector);
    }

    /**
     * @test
     */
    public function it_creates_discussions_with_sticky_locked_and_hidden_flags_derived_from_the_message()
    {
        $connector = new FakeConnector();
        $this->fixtureUsersAndTags($connector);

        $connector->threadStartingMessages = [
            // sticky, approved (visible), not locked
            ['forum_id' => 10, 'thread' => 100, 'user_id' => 1, 'subject' => 'Sticky thread', 'status' => 2, 'sort' => 1, 'closed' => 0],
            // not sticky, on hold (hidden), locked
            ['forum_id' => 10, 'thread' => 101, 'user_id' => 1, 'subject' => 'Hidden locked thread', 'status' => -1, 'sort' => 0, 'closed' => 1],
        ];

        // Step under test again loads its prerequisites (users/tags) from
        // phorum_mapping rather than receiving them in-process.
        $discussions = $this->command()->runStep($connector);

        $sticky = Discussion::find($discussions[100]);
        $this->assertTrue((bool) $sticky->is_sticky);
        $this->assertFalse((bool) $sticky->is_locked);
        $this->assertNull($sticky->hidden_at);

        $hiddenLocked = Discussion::find($discussions[101]);
        $this->assertFalse((bool) $hiddenLocked->is_sticky);
        $this->assertTrue((bool) $hiddenLocked->is_locked);
        $this->assertNotNull($hiddenLocked->hidden_at);
    }

    /**
     * @test
     */
    public function it_skips_thread_starting_messages_with_unknown_author_or_forum()
    {
        $connector = new FakeConnector();
        $this->fixtureUsersAndTags($connector);

        $connector->threadStartingMessages = [
            ['forum_id' => 10, 'thread' => 100, 'user_id' => 999, 'subject' => 'Unknown author', 'status' => 2, 'sort' => 0, 'closed' => 0],
            ['forum_id' => 999, 'thread' => 101, 'user_id' => 1, 'subject' => 'Unknown forum', 'status' => 2, 'sort' => 0, 'closed' => 0],
        ];

        $command = $this->command();
        $logger = new RecordingLogger();
        $command->setLogger($logger);

        $discussions = $command->runStep($connector);

        $this->assertSame([], $discussions);
        $this->assertTrue($logger->hasRecordMatching('critical', 'Unknown Phorum user id'));
        $this->assertTrue($logger->hasRecordMatching('critical', 'Unknown Phorum forum id'));
    }

    /**
     * @test
     */
    public function it_reuses_the_existing_mapped_discussion_when_re_run_as_a_separate_command_instance()
    {
        $connector = new FakeConnector();
        $this->fixtureUsersAndTags($connector);

        $connector->threadStartingMessages = [
            ['forum_id' => 10, 'thread' => 100, 'user_id' => 1, 'subject' => 'Thread', 'status' => 2, 'sort' => 0, 'closed' => 0],
        ];

        $first = $this->command()->runStep($connector);
        $second = $this->command()->runStep($connector);

        $this->assertSame($first[100], $second[100]);
        $this->assertSame(1, Discussion::query()->where('id', $first[100])->count());
    }

    /**
     * @test
     */
    public function it_picks_up_a_new_thread_alongside_an_already_migrated_one_on_a_second_run()
    {
        $connector = new FakeConnector();
        $this->fixtureUsersAndTags($connector);

        $connector->threadStartingMessages = [
            ['forum_id' => 10, 'thread' => 100, 'user_id' => 1, 'subject' => 'Thread one', 'status' => 2, 'sort' => 0, 'closed' => 0],
        ];
        $first = $this->command()->runStep($connector);

        $connector->threadStartingMessages[] = ['forum_id' => 10, 'thread' => 101, 'user_id' => 1, 'subject' => 'Thread two', 'status' => 2, 'sort' => 0, 'closed' => 0];
        $second = $this->command()->runStep($connector);

        $this->assertSame($first[100], $second[100]);
        $this->assertArrayHasKey(101, $second);
        $this->assertSame(2, Discussion::query()->count());
    }
}
