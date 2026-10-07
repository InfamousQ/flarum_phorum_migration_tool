<?php

namespace InfamousQ\FlarumPhorumMigrationTool\Tests\integration\Preflight;

use Flarum\Settings\SettingsRepositoryInterface;
use Flarum\User\User;
use InfamousQ\FlarumPhorumMigrationTool\Preflight\UsernameCheck;
use InfamousQ\FlarumPhorumMigrationTool\Tests\integration\Support\FakeConnector;
use InfamousQ\FlarumPhorumMigrationTool\Tests\integration\Support\TestablePhorumMigrateUsersCommand;
use InfamousQ\FlarumPhorumMigrationTool\Tests\integration\TestCase;

class UsernameCheckTest extends TestCase
{
    protected function phorumUser(int $id, string $name, string $email): array
    {
        return ['user_id' => $id, 'display_name' => $name, 'real_name' => '', 'email' => $email, 'active' => 1, 'admin' => 0];
    }

    /**
     * @test
     */
    public function it_passes_when_every_username_can_be_resolved()
    {
        User::register('docker', 'docker@localhost', 'password')->save();

        $connector = new FakeConnector();
        $connector->users = [$this->phorumUser(1, 'docker', 'docker@phorum.example.com')];

        $this->assertSame([], (new UsernameCheck())->run($connector));
    }

    /**
     * @test
     */
    public function it_reports_a_user_whose_username_and_migrated_variant_are_both_taken_in_flarum()
    {
        User::register('docker', 'docker@localhost', 'password')->save();
        User::register('docker_migrated', 'docker_migrated@localhost', 'password')->save();

        $connector = new FakeConnector();
        $connector->users = [$this->phorumUser(1, 'docker', 'docker@phorum.example.com')];

        $problems = (new UsernameCheck())->run($connector);

        $this->assertCount(1, $problems);
        $this->assertStringContainsString('Phorum user 1', $problems[0]);
        $this->assertStringContainsString("'docker_migrated'", $problems[0]);
    }

    /**
     * @test
     */
    public function it_reports_clashes_between_phorum_users_in_the_same_run_after_sanitizing()
    {
        $connector = new FakeConnector();
        $connector->users = [
            $this->phorumUser(1, 'John Smith', 'john1@example.com'),
            $this->phorumUser(2, 'John_Smith', 'john2@example.com'),
            $this->phorumUser(3, 'John.Smith', 'john3@example.com'),
        ];

        $problems = (new UsernameCheck())->run($connector);

        $this->assertCount(1, $problems);
        $this->assertStringContainsString('Phorum user 3', $problems[0]);
    }

    /**
     * @test
     */
    public function it_frees_the_old_username_of_an_account_renamed_by_an_email_merge()
    {
        User::register('oldname', 'shared@example.com', 'password')->save();
        User::register('oldname_migrated', 'other@localhost', 'password')->save();

        $connector = new FakeConnector();
        $connector->users = [
            // Merged into "oldname" by email and renames it, so "oldname" is free for user 2
            $this->phorumUser(1, 'newname', 'shared@example.com'),
            $this->phorumUser(2, 'oldname', 'user2@example.com'),
        ];

        $this->assertSame([], (new UsernameCheck())->run($connector));
    }

    /**
     * @test
     */
    public function it_skips_users_the_import_skips_or_has_already_mapped()
    {
        User::register('docker', 'docker@localhost', 'password')->save();
        User::register('docker_migrated', 'docker_migrated@localhost', 'password')->save();

        $connector = new FakeConnector();
        $connector->users = [
            ['user_id' => 1, 'display_name' => 'docker', 'real_name' => '', 'email' => 'spam@example.com', 'active' => 0, 'admin' => 0, 'message_count' => 0],
        ];

        $this->assertSame([], (new UsernameCheck())->run($connector));
    }

    /**
     * @test
     */
    public function it_reports_the_guest_placeholder_only_when_phorum_has_guest_messages()
    {
        User::register('Guest', 'guest@localhost', 'password')->save();
        User::register('Guest_migrated', 'guest_migrated@localhost', 'password')->save();

        $connector = new FakeConnector();
        $this->assertSame([], (new UsernameCheck())->run($connector));

        $connector->threadMessages[100] = [
            ['message_id' => 1000, 'user_id' => 0, 'body' => 'Guest post', 'datestamp' => 1600000000, 'status' => 2],
        ];
        $problems = (new UsernameCheck())->run($connector);

        $this->assertCount(1, $problems);
        $this->assertStringContainsString('guest messages', $problems[0]);
    }

    /**
     * @test
     */
    public function the_migrate_command_prints_the_problems_and_reports_failure_when_a_check_fails()
    {
        User::register('docker', 'docker@localhost', 'password')->save();
        User::register('docker_migrated', 'docker_migrated@localhost', 'password')->save();

        $connector = new FakeConnector();
        $connector->users = [$this->phorumUser(1, 'docker', 'docker@phorum.example.com')];

        $command = new TestablePhorumMigrateUsersCommand(
            $this->app()->getContainer()->make(SettingsRepositoryInterface::class)
        );

        $this->assertFalse($command->runPreflight($connector));
        $this->assertStringContainsString('Pre-flight checks failed', $command->bufferedOutput->fetch());
        $this->assertSame(0, User::query()->where('email', 'docker@phorum.example.com')->count());
    }
}
