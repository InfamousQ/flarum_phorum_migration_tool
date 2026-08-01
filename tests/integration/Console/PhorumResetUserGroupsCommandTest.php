<?php

namespace InfamousQ\FlarumPhorumMigrationTool\Tests\integration\Console;

use Flarum\Settings\SettingsRepositoryInterface;
use InfamousQ\FlarumPhorumMigrationTool\Tests\integration\Support\FakeConnector;
use InfamousQ\FlarumPhorumMigrationTool\Tests\integration\Support\TestablePhorumMigrateGroupsCommand;
use InfamousQ\FlarumPhorumMigrationTool\Tests\integration\Support\TestablePhorumMigrateUserGroupsCommand;
use InfamousQ\FlarumPhorumMigrationTool\Tests\integration\Support\TestablePhorumMigrateUsersCommand;
use InfamousQ\FlarumPhorumMigrationTool\Tests\integration\TestCase;
use Symfony\Component\Console\Tester\CommandTester;

class PhorumResetUserGroupsCommandTest extends TestCase
{
    protected function groupsCommand(): TestablePhorumMigrateGroupsCommand
    {
        return new TestablePhorumMigrateGroupsCommand(
            $this->app()->getContainer()->make(SettingsRepositoryInterface::class)
        );
    }

    protected function usersCommand(): TestablePhorumMigrateUsersCommand
    {
        return new TestablePhorumMigrateUsersCommand(
            $this->app()->getContainer()->make(SettingsRepositoryInterface::class)
        );
    }

    protected function userGroupsCommand(): TestablePhorumMigrateUserGroupsCommand
    {
        return new TestablePhorumMigrateUserGroupsCommand(
            $this->app()->getContainer()->make(SettingsRepositoryInterface::class)
        );
    }

    protected function resetCommandTester(): CommandTester
    {
        return new CommandTester($this->console()->find('phorum:reset:user-groups'));
    }

    /**
     * @test
     */
    public function it_reports_nothing_to_reset_when_there_is_no_membership()
    {
        $tester = $this->resetCommandTester();
        $tester->execute(['--force' => true]);

        $this->assertStringContainsString('nothing to reset', $tester->getDisplay());
        $this->assertSame(0, $tester->getStatusCode());
    }

    /**
     * @test
     */
    public function it_removes_membership_between_migrated_users_and_groups_without_deleting_either()
    {
        $connector = new FakeConnector();
        $connector->userGroups = [['group_id' => 1, 'name' => 'GroupA']];
        $connector->users = [
            ['user_id' => 1, 'display_name' => 'Alice', 'real_name' => '', 'email' => 'alice@example.com', 'active' => 1, 'admin' => 0],
        ];
        $connector->userGroupMap = [['user_id' => 1, 'group_id' => 1, 'status' => 1]];

        $groups = $this->groupsCommand()->runStep($connector);
        $users = $this->usersCommand()->runStep($connector);
        $this->userGroupsCommand()->runStep($connector);

        $this->assertContains($users[1]->id, $groups[1]->users()->pluck('id')->all());

        $tester = $this->resetCommandTester();
        $tester->execute(['--force' => true]);

        $this->assertStringContainsString('Group memberships removed: 1', $tester->getDisplay());
        $this->assertNotContains($users[1]->id, $groups[1]->fresh()->users()->pluck('id')->all());
        // Neither the group nor the user is deleted - only the link between them.
        $this->assertNotNull($groups[1]->fresh());
        $this->assertNotNull($users[1]->fresh());
    }

    /**
     * @test
     */
    public function it_aborts_and_removes_nothing_when_confirmation_is_declined()
    {
        $connector = new FakeConnector();
        $connector->userGroups = [['group_id' => 1, 'name' => 'GroupA']];
        $connector->users = [
            ['user_id' => 1, 'display_name' => 'Alice', 'real_name' => '', 'email' => 'alice@example.com', 'active' => 1, 'admin' => 0],
        ];
        $connector->userGroupMap = [['user_id' => 1, 'group_id' => 1, 'status' => 1]];

        $groups = $this->groupsCommand()->runStep($connector);
        $users = $this->usersCommand()->runStep($connector);
        $this->userGroupsCommand()->runStep($connector);

        $tester = $this->resetCommandTester();
        $tester->setInputs(['n']);
        $tester->execute([]);

        $this->assertStringContainsString('Aborted', $tester->getDisplay());
        $this->assertContains($users[1]->id, $groups[1]->fresh()->users()->pluck('id')->all());
    }
}
