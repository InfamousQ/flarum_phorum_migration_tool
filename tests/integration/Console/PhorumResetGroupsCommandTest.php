<?php

namespace InfamousQ\FlarumPhorumMigrationTool\Tests\integration\Console;

use Flarum\Group\Group;
use Flarum\Settings\SettingsRepositoryInterface;
use InfamousQ\FlarumPhorumMigrationTool\Model\PhorumMapping;
use InfamousQ\FlarumPhorumMigrationTool\Tests\integration\Support\FakeConnector;
use InfamousQ\FlarumPhorumMigrationTool\Tests\integration\Support\TestablePhorumMigrateGroupsCommand;
use InfamousQ\FlarumPhorumMigrationTool\Tests\integration\Support\TestablePhorumMigrateUsersCommand;
use InfamousQ\FlarumPhorumMigrationTool\Tests\integration\TestCase;
use Symfony\Component\Console\Tester\CommandTester;

class PhorumResetGroupsCommandTest extends TestCase
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

    protected function resetCommandTester(): CommandTester
    {
        return new CommandTester($this->console()->find('phorum:reset:groups'));
    }

    /**
     * @test
     */
    public function it_reports_nothing_to_reset_when_no_groups_are_mapped()
    {
        $tester = $this->resetCommandTester();
        $tester->execute(['--force' => true]);

        $this->assertStringContainsString('nothing to reset', $tester->getDisplay());
        $this->assertSame(0, $tester->getStatusCode());
    }

    /**
     * @test
     */
    public function it_deletes_migrated_groups_and_their_membership_when_forced()
    {
        $connector = new FakeConnector();
        $connector->userGroups = [['group_id' => 1, 'name' => 'GroupA']];
        $connector->users = [
            ['user_id' => 1, 'display_name' => 'Alice', 'real_name' => '', 'email' => 'alice@example.com', 'active' => 1, 'admin' => 0],
        ];
        $groups = $this->groupsCommand()->runStep($connector);
        $users = $this->usersCommand()->runStep($connector);
        $groups[1]->users()->save($users[1]);

        $tester = $this->resetCommandTester();
        $tester->execute(['--force' => true]);

        $this->assertNull(Group::find($groups[1]->id));
        $this->assertSame(0, PhorumMapping::where('phorum_data_type', PhorumMapping::DATA_TYPE_USER_GROUP)->count());
        // Membership is cascaded away at the DB level along with the group itself.
        $this->assertSame(0, $groups[1]->users()->count());
        $this->assertStringContainsString('Groups deleted: 1', $tester->getDisplay());
    }

    /**
     * @test
     */
    public function it_never_deletes_a_built_in_flarum_group_even_if_mapped()
    {
        PhorumMapping::setFlarumIdForPhorumId(PhorumMapping::DATA_TYPE_USER_GROUP, 999, Group::MEMBER_ID);

        $tester = $this->resetCommandTester();
        $tester->execute(['--force' => true]);

        $this->assertNotNull(Group::find(Group::MEMBER_ID));
        $this->assertSame(0, PhorumMapping::where('phorum_data_type', PhorumMapping::DATA_TYPE_USER_GROUP)->count());
    }

    /**
     * @test
     */
    public function it_aborts_and_deletes_nothing_when_confirmation_is_declined()
    {
        $connector = new FakeConnector();
        $connector->userGroups = [['group_id' => 1, 'name' => 'GroupA']];
        $groups = $this->groupsCommand()->runStep($connector);

        $tester = $this->resetCommandTester();
        $tester->setInputs(['n']);
        $tester->execute([]);

        $this->assertStringContainsString('Aborted', $tester->getDisplay());
        $this->assertNotNull(Group::find($groups[1]->id));
    }
}
