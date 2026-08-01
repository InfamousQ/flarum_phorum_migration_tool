<?php

namespace InfamousQ\FlarumPhorumMigrationTool\Tests\integration\Console;

use Flarum\Settings\SettingsRepositoryInterface;
use InfamousQ\FlarumPhorumMigrationTool\Tests\integration\Support\FakeConnector;
use InfamousQ\FlarumPhorumMigrationTool\Tests\integration\Support\TestablePhorumMigrateGroupsCommand;
use InfamousQ\FlarumPhorumMigrationTool\Tests\integration\Support\TestablePhorumMigrateUserGroupsCommand;
use InfamousQ\FlarumPhorumMigrationTool\Tests\integration\Support\TestablePhorumMigrateUsersCommand;
use InfamousQ\FlarumPhorumMigrationTool\Tests\integration\TestCase;

class PhorumMigrateUserGroupsCommandTest extends TestCase
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

    protected function command(): TestablePhorumMigrateUserGroupsCommand
    {
        return new TestablePhorumMigrateUserGroupsCommand(
            $this->app()->getContainer()->make(SettingsRepositoryInterface::class)
        );
    }

    /**
     * @test
     */
    public function it_attaches_users_to_groups_only_for_approved_or_moderator_status_when_run_as_a_separate_command_instance()
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

        // Steps 1 and 2 each run through their own, separate command instance -
        // exactly as they would as two separate `phorum:migrate:*` CLI invocations.
        $groups = $this->groupsCommand()->runStep($connector);
        $users = $this->usersCommand()->runStep($connector);

        // Step 3 is yet another fresh instance. It must rebuild the group/user
        // maps from phorum_mapping itself rather than receiving them directly,
        // since a real standalone invocation has no access to the return values
        // above.
        $this->command()->runStep($connector);

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

        $groups = $this->groupsCommand()->runStep($connector);
        $this->usersCommand()->runStep($connector);

        // Should not throw despite the unresolvable rows.
        $this->command()->runStep($connector);

        $this->assertSame([], $groups[1]->users()->pluck('id')->all());
    }

    /**
     * @test
     */
    public function it_loads_the_group_and_user_maps_from_phorum_mapping_rather_than_in_memory_state()
    {
        $connector = new FakeConnector();
        $connector->userGroups = [['group_id' => 1, 'name' => 'GroupA']];
        $connector->users = [
            ['user_id' => 1, 'display_name' => 'Alice', 'real_name' => '', 'email' => 'alice@example.com', 'active' => 1, 'admin' => 0],
        ];

        $this->groupsCommand()->runStep($connector);
        $this->usersCommand()->runStep($connector);

        $fresh = $this->command();
        $groupMap = $fresh->loadGroupMapPublic();
        $userMap = $fresh->loadUserMapPublic();

        $this->assertArrayHasKey(1, $groupMap);
        $this->assertArrayHasKey(1, $userMap);
        $this->assertSame('GroupA', $groupMap[1]->name_singular);
        $this->assertSame('Alice', $userMap[1]->username);
    }
}
