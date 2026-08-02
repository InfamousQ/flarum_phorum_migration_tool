<?php

namespace InfamousQ\FlarumPhorumMigrationTool\Tests\integration\Console;

use Flarum\Discussion\Discussion;
use Flarum\Settings\SettingsRepositoryInterface;
use Flarum\User\User;
use InfamousQ\FlarumPhorumMigrationTool\Model\PhorumMapping;
use InfamousQ\FlarumPhorumMigrationTool\Tests\integration\Support\FakeConnector;
use InfamousQ\FlarumPhorumMigrationTool\Tests\integration\Support\TestablePhorumMigrateDiscussionsCommand;
use InfamousQ\FlarumPhorumMigrationTool\Tests\integration\Support\TestablePhorumMigrateTagsCommand;
use InfamousQ\FlarumPhorumMigrationTool\Tests\integration\Support\TestablePhorumMigrateUsersCommand;
use InfamousQ\FlarumPhorumMigrationTool\Tests\integration\TestCase;
use Symfony\Component\Console\Tester\CommandTester;

class PhorumResetUsersCommandTest extends TestCase
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

    protected function resetCommandTester(): CommandTester
    {
        return new CommandTester($this->console()->find('phorum:reset:users'));
    }

    /**
     * @test
     */
    public function it_reports_nothing_to_reset_when_no_users_are_mapped()
    {
        $tester = $this->resetCommandTester();
        $tester->execute(['--force' => true]);

        $this->assertStringContainsString('nothing to reset', $tester->getDisplay());
        $this->assertSame(0, $tester->getStatusCode());
    }

    /**
     * @test
     */
    public function it_deletes_migrated_users_but_not_ones_matched_by_email_when_forced()
    {
        $existing = User::register('PreExisting', 'shared@example.com', 'password');
        $existing->save();

        $connector = new FakeConnector();
        $connector->users = [
            ['user_id' => 1, 'display_name' => 'Created', 'real_name' => '', 'email' => 'created@example.com', 'active' => 1, 'admin' => 0],
            ['user_id' => 2, 'display_name' => 'Matched', 'real_name' => '', 'email' => 'shared@example.com', 'active' => 1, 'admin' => 0],
        ];
        $users = $this->usersCommand()->runStep($connector);

        $tester = $this->resetCommandTester();
        $tester->execute(['--force' => true]);

        $this->assertNull(User::find($users[1]->id));
        $this->assertNotNull(User::find($existing->id));
        $this->assertSame(0, PhorumMapping::where('phorum_data_type', PhorumMapping::DATA_TYPE_USER)->count());
        $this->assertStringContainsString('Users deleted: 1', $tester->getDisplay());
        $this->assertStringContainsString('Users skipped (pre-existing', $tester->getDisplay());
    }

    /**
     * @test
     */
    public function it_anonymizes_rather_than_deletes_content_still_authored_by_a_deleted_user()
    {
        $connector = new FakeConnector();
        $connector->users = [
            ['user_id' => 1, 'display_name' => 'Alice', 'real_name' => '', 'email' => 'alice@example.com', 'active' => 1, 'admin' => 0],
        ];
        $connector->forums = [['forum_id' => 10, 'name' => 'Phorum General', 'description' => '', 'parent_id' => 0, 'display_order' => 1]];
        $connector->threadStartingMessages = [
            ['forum_id' => 10, 'thread' => 100, 'user_id' => 1, 'subject' => 'Thread', 'status' => 2, 'sort' => 0, 'closed' => 0],
        ];
        $this->usersCommand()->runStep($connector);
        $this->tagsCommand()->runStep($connector);
        $discussions = $this->discussionsCommand()->runStep($connector);

        $tester = $this->resetCommandTester();
        $tester->execute(['--force' => true]);

        // The discussion is NOT deleted by a users-only reset - its author reference is
        // just nulled out at the DB level (ON DELETE SET NULL), per the command's own warning.
        $discussion = Discussion::find($discussions[100]);
        $this->assertNotNull($discussion);
        $this->assertNull($discussion->user_id);
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
        $users = $this->usersCommand()->runStep($connector);

        $tester = $this->resetCommandTester();
        $tester->setInputs(['n']);
        $tester->execute([]);

        $this->assertStringContainsString('Aborted', $tester->getDisplay());
        $this->assertNotNull(User::find($users[1]->id));
    }
}
