<?php

namespace InfamousQ\FlarumPhorumMigrationTool\Tests\integration\Console;

use Flarum\Notification\Notification;
use Flarum\Settings\SettingsRepositoryInterface;
use Flarum\User\User;
use InfamousQ\FlarumPhorumMigrationTool\Model\PhorumMapping;
use InfamousQ\FlarumPhorumMigrationTool\Tests\integration\Support\FakeConnector;
use InfamousQ\FlarumPhorumMigrationTool\Tests\integration\Support\TestablePhorumMigrateUsersCommand;
use InfamousQ\FlarumPhorumMigrationTool\Tests\integration\TestCase;

class PhorumMigrateUsersCommandTest extends TestCase
{
    protected function command(): TestablePhorumMigrateUsersCommand
    {
        return new TestablePhorumMigrateUsersCommand(
            $this->app()->getContainer()->make(SettingsRepositoryInterface::class)
        );
    }

    /**
     * @test
     */
    public function it_creates_a_new_flarum_user_for_an_unknown_phorum_user()
    {
        $connector = new FakeConnector();
        $connector->users = [
            ['user_id' => 1, 'display_name' => 'Alice', 'real_name' => 'Alice A', 'email' => 'alice@example.com', 'active' => 1, 'admin' => 0],
        ];

        $users = $this->command()->runStep($connector);

        $this->assertSame('Alice', $users[1]->username);
        $this->assertSame('alice@example.com', $users[1]->email);
        $mapping = PhorumMapping::getMappingForPhorumId(PhorumMapping::DATA_TYPE_USER, 1);
        $this->assertSame($users[1]->id, $mapping->flarum_id);
        $this->assertFalse((bool) $mapping->existing);
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

        $users = $this->command()->runStep($connector);

        $this->assertSame($existingUser->id, $users[1]->id);
        $this->assertSame('Alice', User::find($existingUser->id)->username);
        $mapping = PhorumMapping::getMappingForPhorumId(PhorumMapping::DATA_TYPE_USER, 1);
        $this->assertTrue((bool) $mapping->existing);
    }

    /**
     * @test
     */
    public function it_does_not_overwrite_an_already_mapped_user_when_re_run_as_a_separate_command_instance()
    {
        $connector = new FakeConnector();
        $connector->users = [
            ['user_id' => 1, 'display_name' => 'Alice', 'real_name' => '', 'email' => 'alice@example.com', 'active' => 1, 'admin' => 0],
        ];
        $first = $this->command()->runStep($connector);

        // Simulate the user being renamed/re-emailed inside Flarum after the first
        // migrate:users run - a later sync must not clobber that.
        $first[1]->rename('AliceInFlarum');
        $first[1]->changeEmail('alice-in-flarum@example.com');
        $first[1]->save();

        // Phorum's own data for this user is unchanged, and a fresh command
        // instance stands in for a second, separate `phorum:migrate:users` run.
        $second = $this->command()->runStep($connector);

        $this->assertSame($first[1]->id, $second[1]->id);
        $updated = User::find($first[1]->id);
        $this->assertSame('AliceInFlarum', $updated->username);
        $this->assertSame('alice-in-flarum@example.com', $updated->email);
    }

    /**
     * @test
     */
    public function it_creates_only_the_newly_appearing_phorum_user_on_a_second_run()
    {
        $connector = new FakeConnector();
        $connector->users = [
            ['user_id' => 1, 'display_name' => 'Alice', 'real_name' => '', 'email' => 'alice@example.com', 'active' => 1, 'admin' => 0],
        ];
        $first = $this->command()->runStep($connector);

        // A new Phorum user shows up (e.g. they posted after the first migration
        // pass) - re-running the users step from a fresh command instance should
        // pick them up without touching the already-migrated user.
        $connector->users[] = ['user_id' => 2, 'display_name' => 'Bob', 'real_name' => '', 'email' => 'bob@example.com', 'active' => 1, 'admin' => 0];
        $second = $this->command()->runStep($connector);

        $this->assertSame($first[1]->id, $second[1]->id);
        $this->assertSame('Bob', $second[2]->username);
        $this->assertSame(1, User::query()->where('email', 'alice@example.com')->count());
        $this->assertSame(1, User::query()->where('email', 'bob@example.com')->count());
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

        $users = $this->command()->runStep($connector);

        $this->assertSame('docker_migrated', $users[1]->username);
        $this->assertSame('docker@phorum.example.com', $users[1]->email);
        $this->assertNotSame($existingUser->id, $users[1]->id);
    }

    /**
     * @test
     */
    public function it_converts_the_phorum_display_name_to_a_valid_flarum_username()
    {
        $connector = new FakeConnector();
        $connector->users = [
            ['user_id' => 1, 'display_name' => 'Matti Meikäläinen', 'real_name' => '', 'email' => 'matti@example.com', 'active' => 1, 'admin' => 0],
        ];

        $users = $this->command()->runStep($connector);

        $this->assertSame('Matti_Meikalainen', $users[1]->username);
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

        $this->command()->runStep($connector);
    }

    /**
     * @test
     */
    public function it_confirms_the_email_of_an_active_phorum_user_and_does_not_suspend_them()
    {
        $connector = new FakeConnector();
        $connector->users = [
            ['user_id' => 1, 'display_name' => 'Alice', 'real_name' => '', 'email' => 'alice@example.com', 'active' => 1, 'admin' => 0, 'message_count' => 5],
        ];

        $users = $this->command()->runStep($connector);

        $alice = User::find($users[1]->id);
        $this->assertTrue((bool) $alice->is_email_confirmed);
        $this->assertNull($alice->suspended_until);
    }

    /**
     * @test
     */
    public function it_skips_inactive_or_pending_phorum_users_without_messages()
    {
        $connector = new FakeConnector();
        $connector->users = [
            ['user_id' => 1, 'display_name' => 'Spammer', 'real_name' => '', 'email' => 'spam@example.com', 'active' => 0, 'admin' => 0, 'message_count' => 0],
            // PHORUM_USER_PENDING_EMAIL
            ['user_id' => 2, 'display_name' => 'Pending', 'real_name' => '', 'email' => 'pending@example.com', 'active' => -2, 'admin' => 0, 'message_count' => 0],
        ];

        $users = $this->command()->runStep($connector);

        $this->assertSame([], $users);
        $this->assertSame(0, User::query()->whereIn('email', ['spam@example.com', 'pending@example.com'])->count());
        $this->assertNull(PhorumMapping::getFlarumIdForPhorumId(PhorumMapping::DATA_TYPE_USER, 1));
        $this->assertNull(PhorumMapping::getFlarumIdForPhorumId(PhorumMapping::DATA_TYPE_USER, 2));
    }

    /**
     * @test
     */
    public function it_imports_an_inactive_phorum_user_with_messages_as_indefinitely_suspended()
    {
        $connector = new FakeConnector();
        $connector->users = [
            ['user_id' => 1, 'display_name' => 'Deactivated', 'real_name' => '', 'email' => 'deactivated@example.com', 'active' => 0, 'admin' => 0, 'message_count' => 3],
        ];

        $users = $this->command()->runStep($connector);

        $user = User::find($users[1]->id);
        $this->assertSame('2038-01-01', $user->suspended_until->format('Y-m-d'));
        $this->assertSame('Deactivated in Phorum', $user->suspend_reason);
        $this->assertFalse((bool) $user->is_email_confirmed);
    }

    /**
     * @test
     */
    public function it_sends_no_suspension_notification_and_opts_suspended_users_out_of_suspension_emails()
    {
        $connector = new FakeConnector();
        $connector->users = [
            ['user_id' => 1, 'display_name' => 'Deactivated', 'real_name' => '', 'email' => 'deactivated@example.com', 'active' => 0, 'admin' => 0, 'message_count' => 3],
            ['user_id' => 2, 'display_name' => 'Alice', 'real_name' => '', 'email' => 'alice@example.com', 'active' => 1, 'admin' => 0, 'message_count' => 3],
        ];

        $users = $this->command()->runStep($connector);

        $this->assertSame(0, Notification::query()->count());

        $suspended = User::find($users[1]->id);
        $this->assertFalse($suspended->shouldEmail('userSuspended'));
        $this->assertFalse($suspended->shouldEmail('userUnsuspended'));

        // Active users keep Flarum's default preferences.
        $active = User::find($users[2]->id);
        $this->assertTrue($active->shouldEmail('userSuspended'));
        $this->assertTrue($active->shouldEmail('userUnsuspended'));
    }

    /**
     * @test
     */
    public function it_imports_an_inactive_phorum_user_with_an_unknown_message_count_as_suspended_rather_than_skipping()
    {
        $connector = new FakeConnector();
        $connector->users = [
            ['user_id' => 1, 'display_name' => 'Deactivated', 'real_name' => '', 'email' => 'deactivated@example.com', 'active' => 0, 'admin' => 0],
        ];

        $users = $this->command()->runStep($connector);

        $this->assertNotNull(User::find($users[1]->id)->suspended_until);
    }

    /**
     * @test
     */
    public function it_does_not_confirm_or_suspend_an_existing_flarum_user_matched_by_email()
    {
        // Someone registered on Flarum with this email but never confirmed it.
        $existingUser = User::register('PreExisting', 'shared@example.com', 'password');
        $existingUser->save();

        $connector = new FakeConnector();
        $connector->users = [
            ['user_id' => 1, 'display_name' => 'Alice', 'real_name' => '', 'email' => 'shared@example.com', 'active' => 1, 'admin' => 0, 'message_count' => 5],
        ];

        $this->command()->runStep($connector);

        $reloaded = User::find($existingUser->id);
        $this->assertFalse((bool) $reloaded->is_email_confirmed);
        $this->assertNull($reloaded->suspended_until);
    }
}
