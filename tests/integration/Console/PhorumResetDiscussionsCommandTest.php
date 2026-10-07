<?php

namespace InfamousQ\FlarumPhorumMigrationTool\Tests\integration\Console;

use Flarum\Discussion\Discussion;
use Flarum\Post\Post;
use Flarum\Settings\SettingsRepositoryInterface;
use InfamousQ\FlarumPhorumMigrationTool\Model\PhorumMapping;
use InfamousQ\FlarumPhorumMigrationTool\Tests\integration\Support\FakeConnector;
use InfamousQ\FlarumPhorumMigrationTool\Tests\integration\Support\TestablePhorumMigrateDiscussionsCommand;
use InfamousQ\FlarumPhorumMigrationTool\Tests\integration\Support\TestablePhorumMigratePostsCommand;
use InfamousQ\FlarumPhorumMigrationTool\Tests\integration\Support\TestablePhorumMigrateTagsCommand;
use InfamousQ\FlarumPhorumMigrationTool\Tests\integration\Support\TestablePhorumMigrateUsersCommand;
use InfamousQ\FlarumPhorumMigrationTool\Tests\integration\TestCase;
use Symfony\Component\Console\Tester\CommandTester;

class PhorumResetDiscussionsCommandTest extends TestCase
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

    protected function discussionsCommand(): TestablePhorumMigrateDiscussionsCommand
    {
        return new TestablePhorumMigrateDiscussionsCommand(
            $this->app()->getContainer()->make(SettingsRepositoryInterface::class)
        );
    }

    protected function postsCommand(): TestablePhorumMigratePostsCommand
    {
        return new TestablePhorumMigratePostsCommand(
            $this->app()->getContainer()->make(SettingsRepositoryInterface::class)
        );
    }

    protected function resetCommandTester(): CommandTester
    {
        return new CommandTester($this->console()->find('phorum:reset:discussions'));
    }

    /**
     * @test
     */
    public function it_reports_nothing_to_reset_when_no_discussions_are_mapped()
    {
        $tester = $this->resetCommandTester();
        $tester->execute(['--force' => true]);

        $this->assertStringContainsString('nothing to reset', $tester->getDisplay());
        $this->assertSame(0, $tester->getStatusCode());
    }

    /**
     * @test
     */
    public function it_deletes_the_discussion_and_its_posts_and_cleans_up_both_types_of_mapping_rows()
    {
        $connector = new FakeConnector();
        $connector->users = [
            ['user_id' => 1, 'display_name' => 'Alice', 'real_name' => '', 'email' => 'alice@example.com', 'active' => 1, 'admin' => 0],
        ];
        $connector->forums = [['forum_id' => 10, 'name' => 'Phorum General', 'description' => '', 'parent_id' => 0, 'display_order' => 1, 'pub_perms' => 1, 'reg_perms' => 15]];
        $connector->threadStartingMessages = [
            ['forum_id' => 10, 'thread' => 100, 'user_id' => 1, 'subject' => 'Thread', 'status' => 2, 'sort' => 2, 'closed' => 0],
        ];
        $connector->threadMessages[100] = [
            ['message_id' => 1000, 'user_id' => 1, 'body' => 'Post', 'datestamp' => 1600000000, 'status' => 2],
        ];
        $this->usersCommand()->runStep($connector);
        $this->tagsCommand()->runStep($connector);
        $discussions = $this->discussionsCommand()->runStep($connector);
        $this->postsCommand()->runStep($connector);

        $tester = $this->resetCommandTester();
        $tester->execute(['--force' => true]);

        $this->assertNull(Discussion::find($discussions[100]));
        $this->assertSame(0, Post::query()->where('discussion_id', $discussions[100])->count());
        $this->assertSame(0, PhorumMapping::where('phorum_data_type', PhorumMapping::DATA_TYPE_DISCUSSION)->count());
        // The cascaded post's own bookkeeping (a different migration step's data type) must
        // also be cleaned up, otherwise a later phorum:migrate:posts run would find a stale
        // message-id mapping pointing at a post that no longer exists.
        $this->assertSame(0, PhorumMapping::where('phorum_data_type', PhorumMapping::DATA_TYPE_MESSAGE)->count());
        $this->assertStringContainsString('Discussions deleted: 1', $tester->getDisplay());
        $this->assertStringContainsString('Posts deleted: 1', $tester->getDisplay());
    }

    /**
     * @test
     */
    public function it_aborts_and_deletes_nothing_when_confirmation_is_declined()
    {
        $connector = new FakeConnector();
        $connector->users = [
            ['user_id' => 1, 'display_name' => 'Alice', 'real_name' => '', 'email' => 'alice@example.com', 'active' => 1, 'admin' => 0],
        ];
        $connector->forums = [['forum_id' => 10, 'name' => 'Phorum General', 'description' => '', 'parent_id' => 0, 'display_order' => 1, 'pub_perms' => 1, 'reg_perms' => 15]];
        $connector->threadStartingMessages = [
            ['forum_id' => 10, 'thread' => 100, 'user_id' => 1, 'subject' => 'Thread', 'status' => 2, 'sort' => 2, 'closed' => 0],
        ];
        $this->usersCommand()->runStep($connector);
        $this->tagsCommand()->runStep($connector);
        $discussions = $this->discussionsCommand()->runStep($connector);

        $tester = $this->resetCommandTester();
        $tester->setInputs(['n']);
        $tester->execute([]);

        $this->assertStringContainsString('Aborted', $tester->getDisplay());
        $this->assertNotNull(Discussion::find($discussions[100]));
    }
}
