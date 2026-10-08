<?php

namespace InfamousQ\FlarumPhorumMigrationTool\Tests\integration\Console;

use Flarum\Discussion\Discussion;
use Flarum\Post\Post;
use Flarum\Settings\SettingsRepositoryInterface;
use Flarum\User\User;
use InfamousQ\FlarumPhorumMigrationTool\Console\AbstractPhorumMigrateCommand;
use InfamousQ\FlarumPhorumMigrationTool\Model\PhorumMapping;
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
            ['forum_id' => 10, 'name' => 'Phorum General', 'description' => '', 'parent_id' => 0, 'display_order' => 1, 'pub_perms' => 1, 'reg_perms' => 15],
        ];
        $connector->threadStartingMessages = [
            ['forum_id' => 10, 'thread' => 100, 'user_id' => 1, 'subject' => 'Thread', 'status' => 2, 'sort' => 2, 'closed' => 0],
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
            ['message_id' => 1000, 'user_id' => 1, 'body' => 'First post', 'datestamp' => 1600000000, 'status' => 2],
            ['message_id' => 1001, 'user_id' => 1, 'body' => 'Second post', 'datestamp' => 1600003600, 'status' => 2],
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
    public function it_hides_posts_whose_phorum_message_was_not_approved()
    {
        $connector = new FakeConnector();
        $this->fixtureDiscussion($connector);

        $connector->threadMessages[100] = [
            ['message_id' => 1000, 'user_id' => 1, 'body' => 'Approved post', 'datestamp' => 1600000000, 'status' => 2],
            ['message_id' => 1001, 'user_id' => 1, 'body' => 'On hold post', 'datestamp' => 1600000001, 'status' => -1],
            ['message_id' => 1002, 'user_id' => 1, 'body' => 'Moderator hidden post', 'datestamp' => 1600000002, 'status' => -2],
        ];

        $this->command()->runStep($connector);

        $posts = Post::query()->where('type', 'comment')->orderBy('created_at')->get();
        $this->assertNull($posts[0]->hidden_at);
        $this->assertNotNull($posts[1]->hidden_at);
        $this->assertNotNull($posts[2]->hidden_at);
    }

    /**
     * @test
     */
    public function it_reuses_the_existing_mapped_post_when_re_run_as_a_separate_command_instance()
    {
        $connector = new FakeConnector();
        $this->fixtureDiscussion($connector);

        $connector->threadMessages[100] = [
            ['message_id' => 1000, 'user_id' => 1, 'body' => 'Post', 'datestamp' => 1600000000, 'status' => 2],
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
            ['message_id' => 1000, 'user_id' => 1, 'body' => 'First post', 'datestamp' => 1600000000, 'status' => 2],
        ];
        $this->command()->runStep($connector);

        // A new reply shows up in Phorum after the first `phorum:migrate:posts`
        // run - a fresh command instance re-running the step should only add
        // the new post, leaving the already-migrated one untouched.
        $connector->threadMessages[100][] = ['message_id' => 1001, 'user_id' => 1, 'body' => 'Second post', 'datestamp' => 1600003600, 'status' => 2];
        $this->command()->runStep($connector);

        $this->assertSame(2, Post::query()->where('type', 'comment')->count());
    }

    /**
     * @test
     */
    public function it_leaves_a_thread_with_nothing_new_alone_on_a_re_run()
    {
        $connector = new FakeConnector();
        $discussions = $this->fixtureDiscussion($connector);
        $connector->threadMessages[100] = [
            ['message_id' => 1000, 'user_id' => 1, 'body' => 'First', 'datestamp' => 1600000000, 'status' => 2],
        ];
        $this->command()->runStep($connector);

        // A marker the import would overwrite if it processed the thread again
        Discussion::query()->where('id', $discussions[100])->update(['comment_count' => 99]);
        $this->command()->runStep($connector);
        $this->assertSame(99, Discussion::find($discussions[100])->comment_count);

        $connector->threadMessages[100][] = ['message_id' => 1001, 'user_id' => 1, 'body' => 'Second', 'datestamp' => 1600003600, 'status' => 2];
        $this->command()->runStep($connector);
        $this->assertSame(2, Discussion::find($discussions[100])->comment_count);
    }

    /**
     * @test
     */
    public function it_hides_an_already_migrated_post_on_a_re_run_once_its_phorum_message_is_hidden()
    {
        $connector = new FakeConnector();
        $this->fixtureDiscussion($connector);
        $connector->threadMessages[100] = [
            ['message_id' => 1000, 'user_id' => 1, 'body' => 'First', 'datestamp' => 1600000000, 'status' => 2],
            ['message_id' => 1001, 'user_id' => 1, 'body' => 'Second', 'datestamp' => 1600003600, 'status' => 2],
        ];
        $this->command()->runStep($connector);

        $connector->threadMessages[100][1]['status'] = -2;
        $this->command()->runStep($connector);

        $postId = PhorumMapping::getFlarumIdForPhorumId(PhorumMapping::DATA_TYPE_MESSAGE, 1001);
        $this->assertNotNull(Post::find($postId)->hidden_at);
    }

    /**
     * Posts/discussions are bulk-inserted via plain ->save() calls, which
     * bypasses Flarum's command bus - so the Posted/Started events that
     * normally keep users.comment_count/discussion_count in sync never fire.
     * This step must recompute both counters itself.
     *
     * @test
     */
    public function it_syncs_the_author_comment_and_discussion_counts()
    {
        $connector = new FakeConnector();
        $discussions = $this->fixtureDiscussion($connector);

        $connector->threadMessages[100] = [
            ['message_id' => 1000, 'user_id' => 1, 'body' => 'First post', 'datestamp' => 1600000000, 'status' => 2],
            ['message_id' => 1001, 'user_id' => 1, 'body' => 'Second post', 'datestamp' => 1600003600, 'status' => 2],
        ];

        $this->command()->runStep($connector);

        $discussion = Discussion::find($discussions[100]);
        $author = User::find($discussion->user_id);

        $this->assertSame(2, $author->comment_count);
        $this->assertSame(1, $author->discussion_count);
    }

    /**
     * Hidden posts must not count towards the discussion's comment count or
     * become its last post, same as core's own counters.
     *
     * @test
     */
    public function it_leaves_hidden_posts_out_of_the_discussion_counters()
    {
        $connector = new FakeConnector();
        $discussions = $this->fixtureDiscussion($connector);

        $connector->threadMessages[100] = [
            ['message_id' => 1000, 'user_id' => 1, 'body' => 'Visible post', 'datestamp' => 1600000000, 'status' => 2],
            ['message_id' => 1001, 'user_id' => 1, 'body' => 'Hidden post', 'datestamp' => 1600003600, 'status' => -2],
        ];

        $this->command()->runStep($connector);

        $discussion = Discussion::find($discussions[100]);
        $visible = Post::query()->where('discussion_id', $discussion->id)->whereNull('hidden_at')->first();
        $this->assertSame(1, $discussion->comment_count);
        $this->assertSame($visible->id, $discussion->first_post_id);
        $this->assertSame($visible->id, $discussion->last_post_id);
    }

    /**
     * @test
     */
    public function it_attributes_phorum_guest_messages_to_a_single_locked_placeholder_user()
    {
        $connector = new FakeConnector();
        $connector->users = [
            ['user_id' => 1, 'display_name' => 'Alice', 'real_name' => '', 'email' => 'alice@example.com', 'active' => 1, 'admin' => 0],
        ];
        $connector->forums = [
            ['forum_id' => 10, 'name' => 'Phorum General', 'description' => '', 'parent_id' => 0, 'display_order' => 1, 'pub_perms' => 1, 'reg_perms' => 15],
        ];
        $connector->threadStartingMessages = [
            ['forum_id' => 10, 'thread' => 100, 'user_id' => 0, 'subject' => 'Guest thread', 'status' => 2, 'sort' => 2, 'closed' => 0],
        ];
        $connector->threadMessages[100] = [
            ['message_id' => 1000, 'user_id' => 0, 'body' => 'Guest post', 'datestamp' => 1600000000, 'status' => 2],
            ['message_id' => 1001, 'user_id' => 1, 'body' => 'Member reply', 'datestamp' => 1600000001, 'status' => 2],
            ['message_id' => 1002, 'user_id' => 0, 'body' => 'Another guest post', 'datestamp' => 1600000002, 'status' => 2],
        ];

        $this->usersCommand()->runStep($connector);
        $this->tagsCommand()->runStep($connector);
        $discussions = $this->discussionsCommand()->runStep($connector);
        $this->command()->runStep($connector);
        // Re-running both steps must reuse the same placeholder
        $this->discussionsCommand()->runStep($connector);
        $this->command()->runStep($connector);

        $guests = User::query()->where('email', AbstractPhorumMigrateCommand::GUEST_EMAIL)->get();
        $this->assertCount(1, $guests);
        $guest = $guests[0];
        $this->assertSame(
            $guest->id,
            (int) PhorumMapping::getFlarumIdForPhorumId(PhorumMapping::DATA_TYPE_USER, AbstractPhorumMigrateCommand::PHORUM_GUEST_USER_ID)
        );

        $discussion = Discussion::find($discussions[100]);
        $this->assertSame($guest->id, $discussion->user_id);
        $this->assertSame(3, $discussion->comment_count);
        $this->assertSame(2, Post::query()->where('user_id', $guest->id)->count());

        // Not usable as an account
        $this->assertFalse((bool) $guest->is_email_confirmed);
        $this->assertNotNull($guest->suspended_until);
        $this->assertTrue($guest->suspended_until->isFuture());
        $this->assertSame(0, $guest->groups()->count());
    }

    /**
     * @test
     */
    public function it_rolls_back_a_thread_that_fails_part_way_so_a_re_run_does_not_duplicate_posts()
    {
        $connector = new FakeConnector();
        $discussions = $this->fixtureDiscussion($connector);

        $connector->threadMessages[100] = [
            ['message_id' => 1000, 'user_id' => 1, 'body' => 'First post', 'datestamp' => 1600000000, 'status' => 2],
            ['message_id' => 1001, 'user_id' => 99, 'body' => 'Fails', 'datestamp' => 1600003600, 'status' => 2],
        ];

        $failing = new class($this->app()->getContainer()->make(SettingsRepositoryInterface::class)) extends TestablePhorumMigratePostsCommand {
            protected function resolveAuthor($p_user_id, array &$users): ?User
            {
                if (99 === (int) $p_user_id) {
                    throw new \RuntimeException('Simulated failure');
                }

                return parent::resolveAuthor($p_user_id, $users);
            }
        };

        try {
            $failing->runStep($connector);
            $this->fail('Expected the simulated failure to propagate');
        } catch (\RuntimeException $e) {
            $this->assertSame('Simulated failure', $e->getMessage());
        }

        $this->assertSame(0, Post::query()->where('discussion_id', $discussions[100])->count());
        $this->assertSame(0, PhorumMapping::where('phorum_data_type', PhorumMapping::DATA_TYPE_MESSAGE)->count());

        $connector->threadMessages[100][1]['user_id'] = 1;
        $this->command()->runStep($connector);

        $this->assertSame(2, Post::query()->where('discussion_id', $discussions[100])->count());
        $this->assertSame(2, PhorumMapping::where('phorum_data_type', PhorumMapping::DATA_TYPE_MESSAGE)->count());
    }

    /**
     * @test
     */
    public function it_recreates_a_mapped_discussion_and_its_posts_after_the_discussion_was_deleted_in_flarum()
    {
        $connector = new FakeConnector();
        $discussions = $this->fixtureDiscussion($connector);

        $connector->threadMessages[100] = [
            ['message_id' => 1000, 'user_id' => 1, 'body' => 'First post', 'datestamp' => 1600000000, 'status' => 2],
        ];
        $this->command()->runStep($connector);

        // posts.discussion_id cascades, so the post goes too
        Discussion::find($discussions[100])->delete();

        $recreated = $this->discussionsCommand()->runStep($connector);
        $this->command()->runStep($connector);

        $this->assertNotEquals($discussions[100], $recreated[100]);
        $discussion = Discussion::find($recreated[100]);
        $this->assertNotNull($discussion);
        $this->assertSame(1, $discussion->comment_count);
        $this->assertSame(1, Post::query()->where('discussion_id', $discussion->id)->count());

        // Stale mapping rows were replaced, not duplicated
        $this->assertSame(1, PhorumMapping::where('phorum_data_type', PhorumMapping::DATA_TYPE_DISCUSSION)->count());
        $this->assertSame(1, PhorumMapping::where('phorum_data_type', PhorumMapping::DATA_TYPE_MESSAGE)->count());
        $this->assertSame(
            $discussion->first_post_id,
            (int) PhorumMapping::getFlarumIdForPhorumId(PhorumMapping::DATA_TYPE_MESSAGE, 1000)
        );
    }

    /**
     * Posts are bulk-inserted, so the migration numbers them itself: from 1 in a new
     * discussion, and after any reply already written in Flarum on a later run.
     *
     * @test
     */
    public function it_numbers_posts_in_order_and_after_existing_replies_on_a_rerun()
    {
        $connector = new FakeConnector();
        $discussions = $this->fixtureDiscussion($connector);
        $connector->threadMessages[100] = [
            ['message_id' => 1000, 'user_id' => 1, 'body' => 'First post', 'datestamp' => 1600000000, 'status' => 2],
            ['message_id' => 1001, 'user_id' => 1, 'body' => 'Second post', 'datestamp' => 1600003600, 'status' => -2],
        ];
        $this->command()->runStep($connector);

        $discussion = Discussion::find($discussions[100]);
        $this->assertSame([1, 2], $discussion->posts()->orderBy('number')->pluck('number')->all());

        // A reply written in Flarum, then a new Phorum message migrated after it
        // Numbered explicitly: Post's model hooks that would number it don't always fire under the test harness
        $reply = \Flarum\Post\CommentPost::reply($discussion->id, 'Written in Flarum', $discussion->user_id, '127.0.0.1');
        $reply->number = 3;
        $reply->save();
        $connector->threadMessages[100][] = ['message_id' => 1002, 'user_id' => 1, 'body' => 'Third post', 'datestamp' => 1600007200, 'status' => 2];
        $this->command()->runStep($connector);

        $newPost = Post::find(PhorumMapping::getFlarumIdForPhorumId(PhorumMapping::DATA_TYPE_MESSAGE, 1002));
        $this->assertSame(4, $newPost->number);
        $this->assertSame('Third post', $newPost->content);
        $this->assertSame(1600007200, $newPost->created_at->getTimestamp());
        $this->assertNotNull(Post::find(PhorumMapping::getFlarumIdForPhorumId(PhorumMapping::DATA_TYPE_MESSAGE, 1001))->hidden_at);
        $this->assertSame(4, Post::where('discussion_id', $discussion->id)->count());
    }
}
