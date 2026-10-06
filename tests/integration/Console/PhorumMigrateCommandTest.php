<?php

namespace InfamousQ\FlarumPhorumMigrationTool\Tests\integration\Console;

use Flarum\Discussion\Discussion;
use Flarum\Group\Group;
use Flarum\Group\Permission;
use Flarum\Post\Post;
use Flarum\Settings\SettingsRepositoryInterface;
use Flarum\Tags\Tag;
use Flarum\User\User;
use InfamousQ\FlarumPhorumMigrationTool\Model\PhorumMapping;
use InfamousQ\FlarumPhorumMigrationTool\Tests\integration\Support\FakeConnector;
use InfamousQ\FlarumPhorumMigrationTool\Tests\integration\Support\RecordingLogger;
use InfamousQ\FlarumPhorumMigrationTool\Tests\integration\Support\TestablePhorumMigrateCommand;
use InfamousQ\FlarumPhorumMigrationTool\Tests\integration\TestCase;

class PhorumMigrateCommandTest extends TestCase
{
    protected function command(): TestablePhorumMigrateCommand
    {
        return new TestablePhorumMigrateCommand(
            $this->app()->getContainer()->make(SettingsRepositoryInterface::class)
        );
    }

    // --- importUserGroups -------------------------------------------------

    /**
     * @test
     */
    public function it_creates_a_flarum_group_for_each_phorum_group()
    {
        $connector = new FakeConnector();
        $connector->userGroups = [
            ['group_id' => 1, 'name' => 'Admins'],
            ['group_id' => 2, 'name' => 'Members'],
        ];

        $groups = $this->command()->runImportUserGroups($connector);

        $this->assertCount(2, $groups);
        $this->assertSame('Admins', $groups[1]->name_singular);
        $this->assertSame('Members', $groups[2]->name_singular);
        $this->assertSame($groups[1]->id, PhorumMapping::getFlarumIdForPhorumId(PhorumMapping::DATA_TYPE_USER_GROUP, 1));
    }

    /**
     * @test
     */
    public function it_reuses_the_existing_mapped_group_on_a_second_run_without_overwriting_its_name()
    {
        $connector = new FakeConnector();
        $connector->userGroups = [['group_id' => 1, 'name' => 'Admins']];

        $first = $this->command()->runImportUserGroups($connector);
        // Phorum's own name changing on a later run must not clobber a name that
        // may have been hand-edited inside Flarum since the first run.
        $connector->userGroups = [['group_id' => 1, 'name' => 'Admins Renamed']];
        $second = $this->command()->runImportUserGroups($connector);

        $this->assertSame($first[1]->id, $second[1]->id);
        $this->assertSame(1, Group::query()->where('id', $first[1]->id)->count());
        $this->assertSame('Admins', Group::find($first[1]->id)->name_singular);
    }

    // --- importUsers --------------------------------------------------------

    /**
     * @test
     */
    public function it_creates_a_new_flarum_user_for_an_unknown_phorum_user()
    {
        $connector = new FakeConnector();
        $connector->users = [
            ['user_id' => 1, 'display_name' => 'Alice', 'real_name' => 'Alice A', 'email' => 'alice@example.com', 'active' => 1, 'admin' => 0],
        ];

        $users = $this->command()->runImportUsers($connector);

        $this->assertSame('Alice', $users[1]->username);
        $this->assertSame('alice@example.com', $users[1]->email);
        $mapping = PhorumMapping::getMappingForPhorumId(PhorumMapping::DATA_TYPE_USER, 1);
        $this->assertSame($users[1]->id, $mapping->flarum_id);
        $this->assertFalse((bool) $mapping->existing);
    }

    /**
     * @test
     */
    public function it_gives_new_users_a_random_password_instead_of_a_shared_default()
    {
        $connector = new FakeConnector();
        $connector->users = [
            ['user_id' => 1, 'display_name' => 'Alice', 'real_name' => '', 'email' => 'alice@example.com', 'active' => 1, 'admin' => 0],
            ['user_id' => 2, 'display_name' => 'Bob', 'real_name' => '', 'email' => 'bob@example.com', 'active' => 1, 'admin' => 0],
        ];

        $users = $this->command()->runImportUsers($connector);

        $this->assertFalse($users[1]->checkPassword('test'));
        $this->assertFalse($users[2]->checkPassword('test'));
        $this->assertNotSame($users[1]->password, $users[2]->password);
    }

    /**
     * @test
     */
    public function it_matches_an_existing_flarum_user_by_email_instead_of_duplicating()
    {
        $existingUser = User::register('PreExisting', 'shared@example.com', 'password');
        $existingUser->save();

        $connector = new FakeConnector();
        $connector->users = [
            ['user_id' => 1, 'display_name' => 'Alice', 'real_name' => 'Alice A', 'email' => 'shared@example.com', 'active' => 1, 'admin' => 0],
        ];

        $users = $this->command()->runImportUsers($connector);

        $this->assertSame($existingUser->id, $users[1]->id);
        $this->assertSame('Alice', User::find($existingUser->id)->username);
        $mapping = PhorumMapping::getMappingForPhorumId(PhorumMapping::DATA_TYPE_USER, 1);
        $this->assertTrue((bool) $mapping->existing);
    }

    /**
     * @test
     */
    public function it_reuses_the_same_flarum_user_on_a_second_run_without_overwriting_it()
    {
        $connector = new FakeConnector();
        $connector->users = [
            ['user_id' => 1, 'display_name' => 'Alice', 'real_name' => 'Alice A', 'email' => 'alice@example.com', 'active' => 1, 'admin' => 0],
        ];
        $first = $this->command()->runImportUsers($connector);

        // Phorum's own display name/email changing on a later run must not clobber
        // a rename/email change made inside Flarum since the first run.
        $connector->users = [
            ['user_id' => 1, 'display_name' => 'Alice Renamed', 'real_name' => 'Alice A', 'email' => 'alice2@example.com', 'active' => 1, 'admin' => 0],
        ];
        $second = $this->command()->runImportUsers($connector);

        $this->assertSame($first[1]->id, $second[1]->id);
        $this->assertSame(1, User::query()->where('id', $first[1]->id)->count());
        $updated = User::find($first[1]->id);
        $this->assertSame('Alice', $updated->username);
        $this->assertSame('alice@example.com', $updated->email);
    }

    /**
     * @test
     */
    public function it_appends_migrated_suffix_when_a_new_user_would_collide_with_an_unrelated_username()
    {
        $existingUser = User::register('docker', 'docker@localhost', 'password');
        $existingUser->save();

        $connector = new FakeConnector();
        $connector->users = [
            ['user_id' => 1, 'display_name' => 'docker', 'real_name' => '', 'email' => 'docker@phorum.example.com', 'active' => 1, 'admin' => 0],
        ];

        $users = $this->command()->runImportUsers($connector);

        $this->assertSame('docker_migrated', $users[1]->username);
        $this->assertSame('docker@phorum.example.com', $users[1]->email);
        $this->assertNotSame($existingUser->id, $users[1]->id);
    }

    /**
     * @test
     */
    public function it_appends_migrated_suffix_when_an_email_matched_user_would_rename_into_an_unrelated_username()
    {
        $sharedEmailUser = User::register('PreExisting', 'shared@example.com', 'password');
        $sharedEmailUser->save();
        $usernameOwner = User::register('Alice', 'alice@example.com', 'password');
        $usernameOwner->save();

        $connector = new FakeConnector();
        $connector->users = [
            ['user_id' => 1, 'display_name' => 'Alice', 'real_name' => '', 'email' => 'shared@example.com', 'active' => 1, 'admin' => 0],
        ];

        $users = $this->command()->runImportUsers($connector);

        $this->assertSame($sharedEmailUser->id, $users[1]->id);
        $this->assertSame('Alice_migrated', $users[1]->username);
        $this->assertSame('Alice', User::find($usernameOwner->id)->username);
    }

    /**
     * @test
     */
    public function it_does_not_append_a_suffix_for_a_clean_email_match()
    {
        $existingUser = User::register('PreExisting', 'shared@example.com', 'password');
        $existingUser->save();

        $connector = new FakeConnector();
        $connector->users = [
            ['user_id' => 1, 'display_name' => 'Alice', 'real_name' => '', 'email' => 'shared@example.com', 'active' => 1, 'admin' => 0],
        ];

        $users = $this->command()->runImportUsers($connector);

        $this->assertSame('Alice', $users[1]->username);
    }

    /**
     * @test
     */
    public function it_throws_when_both_the_desired_and_migrated_usernames_are_already_taken()
    {
        User::register('docker', 'docker@localhost', 'password')->save();
        User::register('docker_migrated', 'docker_migrated@localhost', 'password')->save();

        $connector = new FakeConnector();
        $connector->users = [
            ['user_id' => 1, 'display_name' => 'docker', 'real_name' => '', 'email' => 'docker@phorum.example.com', 'active' => 1, 'admin' => 0],
        ];

        $this->expectException(\RuntimeException::class);

        $this->command()->runImportUsers($connector);
    }

    // --- importUserGroupMapping ---------------------------------------------

    /**
     * @test
     */
    public function it_attaches_users_to_groups_only_for_approved_or_moderator_status()
    {
        $connector = new FakeConnector();
        $connector->userGroups = [['group_id' => 1, 'name' => 'GroupA']];
        $connector->users = [
            ['user_id' => 1, 'display_name' => 'Approved', 'real_name' => '', 'email' => 'a@example.com', 'active' => 1, 'admin' => 0],
            ['user_id' => 2, 'display_name' => 'Moderator', 'real_name' => '', 'email' => 'b@example.com', 'active' => 1, 'admin' => 0],
            ['user_id' => 3, 'display_name' => 'Suspended', 'real_name' => '', 'email' => 'c@example.com', 'active' => 1, 'admin' => 0],
            ['user_id' => 4, 'display_name' => 'Unapproved', 'real_name' => '', 'email' => 'd@example.com', 'active' => 1, 'admin' => 0],
        ];
        $connector->userGroupMap = [
            ['user_id' => 1, 'group_id' => 1, 'status' => 1],
            ['user_id' => 2, 'group_id' => 1, 'status' => 2],
            ['user_id' => 3, 'group_id' => 1, 'status' => -1],
            ['user_id' => 4, 'group_id' => 1, 'status' => 0],
        ];

        $command = $this->command();
        $groups = $command->runImportUserGroups($connector);
        $users = $command->runImportUsers($connector);
        $command->runImportUserGroupMapping($connector, $groups, $users);

        $memberIds = $groups[1]->users()->pluck('id')->all();
        $this->assertContains($users[1]->id, $memberIds);
        $this->assertContains($users[2]->id, $memberIds);
        $this->assertNotContains($users[3]->id, $memberIds);
        $this->assertNotContains($users[4]->id, $memberIds);
    }

    /**
     * @test
     */
    public function it_skips_mapping_rows_referencing_unknown_users_or_groups_without_erroring()
    {
        $connector = new FakeConnector();
        $connector->userGroups = [['group_id' => 1, 'name' => 'GroupA']];
        $connector->users = [
            ['user_id' => 1, 'display_name' => 'Known', 'real_name' => '', 'email' => 'known@example.com', 'active' => 1, 'admin' => 0],
        ];
        $connector->userGroupMap = [
            ['user_id' => 999, 'group_id' => 1, 'status' => 1], // unknown user
            ['user_id' => 1, 'group_id' => 999, 'status' => 1], // unknown group
        ];

        $command = $this->command();
        $groups = $command->runImportUserGroups($connector);
        $users = $command->runImportUsers($connector);

        // Should not throw despite the unresolvable rows.
        $command->runImportUserGroupMapping($connector, $groups, $users);

        $this->assertSame([], $groups[1]->users()->pluck('id')->all());
    }

    // --- importPhorumForumsAsTags -------------------------------------------

    /**
     * @test
     */
    public function it_creates_a_hidden_tag_for_each_active_phorum_forum()
    {
        $connector = new FakeConnector();
        $connector->forums = [
            ['forum_id' => 10, 'name' => 'Phorum General', 'description' => 'Phorum general discussion', 'parent_id' => 0, 'display_order' => 1, 'pub_perms' => 1, 'reg_perms' => 15],
        ];

        $tags = $this->command()->runImportPhorumForumsAsTags($connector);

        $this->assertSame('Phorum General', $tags[10]->name);
        $this->assertTrue((bool) $tags[10]->is_hidden);
        $this->assertSame($tags[10]->id, PhorumMapping::getFlarumIdForPhorumId(PhorumMapping::DATA_TYPE_TAG, 10));
    }

    /**
     * @test
     */
    public function it_reuses_the_existing_mapped_tag_on_a_second_run()
    {
        $connector = new FakeConnector();
        $connector->forums = [
            ['forum_id' => 10, 'name' => 'Phorum General', 'description' => '', 'parent_id' => 0, 'display_order' => 1, 'pub_perms' => 1, 'reg_perms' => 15],
        ];

        $first = $this->command()->runImportPhorumForumsAsTags($connector);
        $second = $this->command()->runImportPhorumForumsAsTags($connector);

        $this->assertSame($first[10]->id, $second[10]->id);
        $this->assertSame(1, Tag::query()->where('id', $first[10]->id)->count());
    }

    /**
     * @test
     */
    public function it_leaves_a_guest_readable_phorum_forum_unrestricted()
    {
        $connector = new FakeConnector();
        $connector->forums = [
            ['forum_id' => 10, 'name' => 'Public', 'description' => '', 'parent_id' => 0, 'display_order' => 1, 'pub_perms' => 1, 'reg_perms' => 15],
        ];

        $tags = $this->command()->runImportPhorumForumsAsTags($connector);

        $this->assertFalse((bool) $tags[10]->is_restricted);
        $this->assertSame(0, Permission::query()->where('permission', 'like', "tag{$tags[10]->id}.%")->count());
    }

    /**
     * @test
     */
    public function it_restricts_a_registered_only_phorum_forum_to_members()
    {
        $connector = new FakeConnector();
        $connector->forums = [
            // read + reply, but no new topics, for registered users
            ['forum_id' => 10, 'name' => 'Members only', 'description' => '', 'parent_id' => 0, 'display_order' => 1, 'pub_perms' => 0, 'reg_perms' => 3],
        ];

        $tags = $this->command()->runImportPhorumForumsAsTags($connector);
        $tagId = $tags[10]->id;

        $this->assertTrue((bool) $tags[10]->is_restricted);
        $memberPerms = Permission::query()->where('group_id', Group::MEMBER_ID)->pluck('permission')->all();
        $this->assertContains("tag{$tagId}.viewForum", $memberPerms);
        $this->assertContains("tag{$tagId}.discussion.reply", $memberPerms);
        $this->assertNotContains("tag{$tagId}.startDiscussion", $memberPerms);
        $this->assertSame(0, Permission::query()->where('group_id', Group::GUEST_ID)->where('permission', 'like', "tag{$tagId}.%")->count());
    }

    /**
     * @test
     */
    public function it_restricts_a_group_only_phorum_forum_to_the_mapped_groups_that_could_read_it()
    {
        $connector = new FakeConnector();
        $connector->userGroups = [
            ['group_id' => 1, 'name' => 'Board'],
            ['group_id' => 2, 'name' => 'Banned from board'],
        ];
        $connector->forums = [
            ['forum_id' => 10, 'name' => 'Board only', 'description' => '', 'parent_id' => 0, 'display_order' => 1, 'pub_perms' => 0, 'reg_perms' => 0],
        ];
        $connector->forumGroupPermissions = [
            ['forum_id' => 10, 'group_id' => 1, 'permission' => 15],
            ['forum_id' => 10, 'group_id' => 2, 'permission' => 0],
            ['forum_id' => 10, 'group_id' => 999, 'permission' => 15], // unmapped group, skipped
        ];

        $command = $this->command();
        $groups = $command->runImportUserGroups($connector);
        $tags = $command->runImportPhorumForumsAsTags($connector);
        $tagId = $tags[10]->id;

        $this->assertTrue((bool) $tags[10]->is_restricted);
        $boardPerms = Permission::query()->where('group_id', $groups[1]->id)->pluck('permission')->all();
        $this->assertContains("tag{$tagId}.viewForum", $boardPerms);
        $this->assertContains("tag{$tagId}.startDiscussion", $boardPerms);
        $this->assertSame(0, Permission::query()->where('group_id', $groups[2]->id)->count());
        $this->assertSame(0, Permission::query()->where('group_id', Group::MEMBER_ID)->where('permission', 'like', "tag{$tagId}.%")->count());
    }

    /**
     * @test
     */
    public function it_treats_a_forum_with_unknown_permissions_as_restricted()
    {
        $connector = new FakeConnector();
        $connector->forums = [
            ['forum_id' => 10, 'name' => 'No perms columns', 'description' => '', 'parent_id' => 0, 'display_order' => 1],
        ];

        $tags = $this->command()->runImportPhorumForumsAsTags($connector);

        $this->assertTrue((bool) $tags[10]->is_restricted);
    }

    // --- importPhorumMessagesAsDiscussions ----------------------------------

    protected function fixtureUsersAndTags(FakeConnector $connector, TestablePhorumMigrateCommand $command): array
    {
        $connector->users = [
            ['user_id' => 1, 'display_name' => 'Alice', 'real_name' => '', 'email' => 'alice@example.com', 'active' => 1, 'admin' => 0],
        ];
        $connector->forums = [
            ['forum_id' => 10, 'name' => 'Phorum General', 'description' => '', 'parent_id' => 0, 'display_order' => 1, 'pub_perms' => 1, 'reg_perms' => 15],
        ];

        $users = $command->runImportUsers($connector);
        $tags = $command->runImportPhorumForumsAsTags($connector);

        return [$users, $tags];
    }

    /**
     * @test
     */
    public function it_creates_discussions_with_sticky_locked_and_hidden_flags_derived_from_the_message()
    {
        $connector = new FakeConnector();
        $command = $this->command();
        [$users, $tags] = $this->fixtureUsersAndTags($connector, $command);

        $connector->threadStartingMessages = [
            // sticky, approved (visible), not locked
            ['forum_id' => 10, 'thread' => 100, 'user_id' => 1, 'subject' => 'Sticky thread', 'status' => 2, 'sort' => 1, 'closed' => 0],
            // not sticky, on hold (hidden), locked
            ['forum_id' => 10, 'thread' => 101, 'user_id' => 1, 'subject' => 'Hidden locked thread', 'status' => -1, 'sort' => 0, 'closed' => 1],
        ];

        $discussions = $command->runImportPhorumMessagesAsDiscussions($connector, $users, $tags);

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
        $command = $this->command();
        [$users, $tags] = $this->fixtureUsersAndTags($connector, $command);

        $connector->threadStartingMessages = [
            ['forum_id' => 10, 'thread' => 100, 'user_id' => 999, 'subject' => 'Unknown author', 'status' => 2, 'sort' => 0, 'closed' => 0],
            ['forum_id' => 999, 'thread' => 101, 'user_id' => 1, 'subject' => 'Unknown forum', 'status' => 2, 'sort' => 0, 'closed' => 0],
        ];

        $logger = new RecordingLogger();
        $command->setLogger($logger);

        $discussions = $command->runImportPhorumMessagesAsDiscussions($connector, $users, $tags);

        $this->assertSame([], $discussions);
        $this->assertTrue($logger->hasRecordMatching('critical', 'Unknown Phorum user id'));
        $this->assertTrue($logger->hasRecordMatching('critical', 'Unknown Phorum forum id'));
    }

    /**
     * @test
     */
    public function it_reuses_the_existing_mapped_discussion_on_a_second_run()
    {
        $connector = new FakeConnector();
        $command = $this->command();
        [$users, $tags] = $this->fixtureUsersAndTags($connector, $command);

        $connector->threadStartingMessages = [
            ['forum_id' => 10, 'thread' => 100, 'user_id' => 1, 'subject' => 'Thread', 'status' => 2, 'sort' => 0, 'closed' => 0],
        ];

        $first = $command->runImportPhorumMessagesAsDiscussions($connector, $users, $tags);
        $second = $command->runImportPhorumMessagesAsDiscussions($connector, $users, $tags);

        $this->assertSame($first[100], $second[100]);
        $this->assertSame(1, Discussion::query()->where('id', $first[100])->count());
    }

    // --- importPhorumMessageForThread ----------------------------------------

    protected function fixtureDiscussion(FakeConnector $connector, TestablePhorumMigrateCommand $command): array
    {
        [$users, $tags] = $this->fixtureUsersAndTags($connector, $command);
        $connector->threadStartingMessages = [
            ['forum_id' => 10, 'thread' => 100, 'user_id' => 1, 'subject' => 'Thread', 'status' => 2, 'sort' => 0, 'closed' => 0],
        ];
        $discussions = $command->runImportPhorumMessagesAsDiscussions($connector, $users, $tags);

        return [$users, $discussions];
    }

    /**
     * @test
     */
    public function it_creates_posts_backdated_to_the_original_phorum_timestamp()
    {
        $connector = new FakeConnector();
        $command = $this->command();
        [$users, $discussions] = $this->fixtureDiscussion($connector, $command);

        $connector->threadMessages[100] = [
            ['message_id' => 1000, 'user_id' => 1, 'body' => 'First post', 'datestamp' => 1600000000, 'status' => 2],
            ['message_id' => 1001, 'user_id' => 1, 'body' => 'Second post', 'datestamp' => 1600003600, 'status' => 2],
        ];

        $posts = $command->runImportPhorumMessageForThread($connector, 100, $discussions[100], $users);

        $this->assertCount(2, $posts);
        $this->assertSame(1600000000, $posts[0]->created_at->getTimestamp());
        $this->assertSame(1600003600, $posts[1]->created_at->getTimestamp());

        $discussion = Discussion::find($discussions[100]);
        $this->assertSame(2, $discussion->comment_count);
        $this->assertSame($posts[0]->id, $discussion->first_post_id);
        $this->assertSame($posts[1]->id, $discussion->last_post_id);
    }

    /**
     * @test
     */
    public function it_hides_posts_whose_phorum_message_was_on_hold_or_hidden()
    {
        $connector = new FakeConnector();
        $command = $this->command();
        [$users, $discussions] = $this->fixtureDiscussion($connector, $command);

        $connector->threadMessages[100] = [
            ['message_id' => 1000, 'user_id' => 1, 'body' => 'Approved post', 'datestamp' => 1600000000, 'status' => 2],
            ['message_id' => 1001, 'user_id' => 1, 'body' => 'On hold post', 'datestamp' => 1600000001, 'status' => -1],
            ['message_id' => 1002, 'user_id' => 1, 'body' => 'Moderator hidden post', 'datestamp' => 1600000002, 'status' => -2],
        ];

        $posts = $command->runImportPhorumMessageForThread($connector, 100, $discussions[100], $users);

        $this->assertNull($posts[0]->hidden_at);
        $this->assertNotNull($posts[1]->hidden_at);
        $this->assertNotNull($posts[2]->hidden_at);
    }

    /**
     * @test
     */
    public function it_does_not_hide_posts_just_because_their_phorum_thread_is_closed()
    {
        $connector = new FakeConnector();
        $command = $this->command();
        [$users, $discussions] = $this->fixtureDiscussion($connector, $command);

        // Phorum sets `closed` on every message of a locked thread; that locks the
        // discussion, it must not hide the posts.
        $connector->threadMessages[100] = [
            ['message_id' => 1000, 'user_id' => 1, 'body' => 'Post in locked thread', 'datestamp' => 1600000000, 'status' => 2, 'closed' => 1],
        ];

        $posts = $command->runImportPhorumMessageForThread($connector, 100, $discussions[100], $users);

        $this->assertNull($posts[0]->hidden_at);
    }

    /**
     * @test
     */
    public function it_reuses_the_existing_mapped_post_on_a_second_run()
    {
        $connector = new FakeConnector();
        $command = $this->command();
        [$users, $discussions] = $this->fixtureDiscussion($connector, $command);

        $connector->threadMessages[100] = [
            ['message_id' => 1000, 'user_id' => 1, 'body' => 'Post', 'datestamp' => 1600000000, 'status' => 2],
        ];

        $first = $command->runImportPhorumMessageForThread($connector, 100, $discussions[100], $users);
        $second = $command->runImportPhorumMessageForThread($connector, 100, $discussions[100], $users);

        $this->assertSame($first[0]->id, $second[0]->id);
        $this->assertSame(1, Post::query()->where('id', $first[0]->id)->count());
    }

    /**
     * @test
     */
    public function it_skips_and_logs_a_message_whose_mapped_post_belongs_to_a_different_discussion()
    {
        $connector = new FakeConnector();
        $command = $this->command();
        [$users, $discussions] = $this->fixtureDiscussion($connector, $command);

        // Create a second, unrelated discussion/thread and map message 1000 to it.
        $connector->threadStartingMessages[] = ['forum_id' => 10, 'thread' => 200, 'user_id' => 1, 'subject' => 'Other thread', 'status' => 2, 'sort' => 0, 'closed' => 0];
        [$_, $tagsUnused] = [null, null];
        $tags = $command->runImportPhorumForumsAsTags($connector);
        $otherDiscussions = $command->runImportPhorumMessagesAsDiscussions($connector, $users, $tags);

        $connector->threadMessages[200] = [
            ['message_id' => 1000, 'user_id' => 1, 'body' => 'Belongs elsewhere', 'datestamp' => 1600000000, 'status' => 2],
        ];
        $command->runImportPhorumMessageForThread($connector, 200, $otherDiscussions[200], $users);

        // Now message_id 1000 is mapped to a post on the OTHER discussion. Re-processing
        // thread 100 (which does not contain that post) should skip it, not attach it.
        $logger = new RecordingLogger();
        $command->setLogger($logger);
        $connector->threadMessages[100] = [
            ['message_id' => 1000, 'user_id' => 1, 'body' => 'Belongs elsewhere', 'datestamp' => 1600000000, 'status' => 2],
        ];
        $posts = $command->runImportPhorumMessageForThread($connector, 100, $discussions[100], $users);

        $this->assertSame([], $posts);
        $this->assertTrue($logger->hasRecordMatching('critical', 'Post linked to wrong Discussion'));
    }

    // --- Full pipeline -------------------------------------------------------

    /**
     * @test
     */
    public function full_migration_pipeline_is_idempotent_across_two_runs()
    {
        $connector = new FakeConnector();
        $connector->userGroups = [['group_id' => 1, 'name' => 'Members']];
        $connector->users = [
            ['user_id' => 1, 'display_name' => 'Alice', 'real_name' => '', 'email' => 'alice@example.com', 'active' => 1, 'admin' => 0],
        ];
        $connector->userGroupMap = [['user_id' => 1, 'group_id' => 1, 'status' => 1]];
        $connector->forums = [['forum_id' => 10, 'name' => 'Phorum General', 'description' => '', 'parent_id' => 0, 'display_order' => 1, 'pub_perms' => 1, 'reg_perms' => 15]];
        $connector->threadStartingMessages = [
            ['forum_id' => 10, 'thread' => 100, 'user_id' => 1, 'subject' => 'Thread', 'status' => 2, 'sort' => 0, 'closed' => 0],
        ];
        $connector->threadMessages[100] = [
            ['message_id' => 1000, 'user_id' => 1, 'body' => 'Post', 'datestamp' => 1600000000, 'status' => 2],
        ];

        $this->command()->runFullMigration($connector);
        $this->command()->runFullMigration($connector);

        $this->assertSame(1, Group::query()->where('name_singular', 'Members')->count());
        $this->assertSame(1, User::query()->where('email', 'alice@example.com')->count());
        $this->assertSame(1, Tag::query()->where('name', 'Phorum General')->count());
        $this->assertSame(1, Discussion::query()->count());
        $this->assertSame(1, Post::query()->where('type', 'comment')->count());
    }
}
