<?php

namespace InfamousQ\FlarumPhorumMigrationTool\Tests\integration\Console;

use Flarum\Discussion\Discussion;
use Flarum\Post\Post;
use Flarum\Settings\SettingsRepositoryInterface;
use InfamousQ\FlarumPhorumMigrationTool\Tests\integration\Support\FakeConnector;
use InfamousQ\FlarumPhorumMigrationTool\Tests\integration\Support\TestablePhorumMigrateDiscussionsCommand;
use InfamousQ\FlarumPhorumMigrationTool\Tests\integration\Support\TestablePhorumMigratePostsCommand;
use InfamousQ\FlarumPhorumMigrationTool\Tests\integration\Support\TestablePhorumMigrateTagsCommand;
use InfamousQ\FlarumPhorumMigrationTool\Tests\integration\Support\TestablePhorumMigrateUsersCommand;
use InfamousQ\FlarumPhorumMigrationTool\Tests\integration\TestCase;

class PhorumMigratePostsCommandTest extends TestCase
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

    protected function command(): TestablePhorumMigratePostsCommand
    {
        return new TestablePhorumMigratePostsCommand(
            $this->app()->getContainer()->make(SettingsRepositoryInterface::class)
        );
    }

    /**
     * Runs steps 2, 4 and 5 (users, tags, discussions), each through its own
     * fresh command instance - mirroring three separate `phorum:migrate:*` CLI
     * invocations that happened before this step - and returns the discussion
     * id keyed by Phorum thread id, for tests to reference.
     */
    protected function fixtureDiscussion(FakeConnector $connector): array
    {
        $connector->users = [
            ['user_id' => 1, 'display_name' => 'Alice', 'real_name' => '', 'email' => 'alice@example.com', 'active' => 1, 'admin' => 0],
        ];
        $connector->forums = [
            ['forum_id' => 10, 'name' => 'Phorum General', 'description' => '', 'parent_id' => 0, 'display_order' => 1],
        ];
        $connector->threadStartingMessages = [
            ['forum_id' => 10, 'thread' => 100, 'user_id' => 1, 'subject' => 'Thread', 'status' => 2, 'sort' => 0, 'closed' => 0],
        ];

        $this->usersCommand()->runStep($connector);
        $this->tagsCommand()->runStep($connector);

        return $this->discussionsCommand()->runStep($connector);
    }

    /**
     * @test
     */
    public function it_creates_posts_backdated_to_the_original_phorum_timestamp()
    {
        $connector = new FakeConnector();
        $discussions = $this->fixtureDiscussion($connector);

        $connector->threadMessages[100] = [
            ['message_id' => 1000, 'user_id' => 1, 'body' => 'First post', 'datestamp' => 1600000000, 'closed' => 0],
            ['message_id' => 1001, 'user_id' => 1, 'body' => 'Second post', 'datestamp' => 1600003600, 'closed' => 0],
        ];

        // Step under test loads its prerequisites (users/discussions) from
        // phorum_mapping rather than receiving them in-process.
        $this->command()->runStep($connector);

        $discussion = Discussion::find($discussions[100]);
        $this->assertSame(2, $discussion->comment_count);
        $this->assertSame(2, Post::query()->where('type', 'comment')->count());

        $posts = Post::query()->where('discussion_id', $discussion->id)->orderBy('id')->get();
        $this->assertSame(1600000000, $posts[0]->created_at->getTimestamp());
        $this->assertSame(1600003600, $posts[1]->created_at->getTimestamp());
        $this->assertSame($posts[0]->id, $discussion->first_post_id);
        $this->assertSame($posts[1]->id, $discussion->last_post_id);
    }

    /**
     * @test
     */
    public function it_hides_posts_whose_phorum_message_was_marked_closed()
    {
        $connector = new FakeConnector();
        $this->fixtureDiscussion($connector);

        $connector->threadMessages[100] = [
            ['message_id' => 1000, 'user_id' => 1, 'body' => 'Closed post', 'datestamp' => 1600000000, 'closed' => 1],
        ];

        $this->command()->runStep($connector);

        $post = Post::query()->where('type', 'comment')->first();
        $this->assertNotNull($post->hidden_at);
    }

    /**
     * @test
     */
    public function it_reuses_the_existing_mapped_post_when_re_run_as_a_separate_command_instance()
    {
        $connector = new FakeConnector();
        $this->fixtureDiscussion($connector);

        $connector->threadMessages[100] = [
            ['message_id' => 1000, 'user_id' => 1, 'body' => 'Post', 'datestamp' => 1600000000, 'closed' => 0],
        ];

        $this->command()->runStep($connector);
        $this->command()->runStep($connector);

        $this->assertSame(1, Post::query()->where('type', 'comment')->count());
    }

    /**
     * @test
     */
    public function it_imports_only_the_new_reply_alongside_an_already_migrated_one_on_a_second_run()
    {
        $connector = new FakeConnector();
        $this->fixtureDiscussion($connector);

        $connector->threadMessages[100] = [
            ['message_id' => 1000, 'user_id' => 1, 'body' => 'First post', 'datestamp' => 1600000000, 'closed' => 0],
        ];
        $this->command()->runStep($connector);

        // A new reply shows up in Phorum after the first `phorum:migrate:posts`
        // run - a fresh command instance re-running the step should only add
        // the new post, leaving the already-migrated one untouched.
        $connector->threadMessages[100][] = ['message_id' => 1001, 'user_id' => 1, 'body' => 'Second post', 'datestamp' => 1600003600, 'closed' => 0];
        $this->command()->runStep($connector);

        $this->assertSame(2, Post::query()->where('type', 'comment')->count());
    }
}
