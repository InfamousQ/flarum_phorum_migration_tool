<?php

namespace InfamousQ\FlarumPhorumMigrationTool\Tests\integration\Console;

use Flarum\Group\Group;
use Flarum\Settings\SettingsRepositoryInterface;
use InfamousQ\FlarumPhorumMigrationTool\Model\PhorumMapping;
use InfamousQ\FlarumPhorumMigrationTool\Tests\integration\Support\FakeConnector;
use InfamousQ\FlarumPhorumMigrationTool\Tests\integration\Support\TestablePhorumMigrateGroupsCommand;
use InfamousQ\FlarumPhorumMigrationTool\Tests\integration\TestCase;

class PhorumMigrateGroupsCommandTest extends TestCase
{
    protected function command(): TestablePhorumMigrateGroupsCommand
    {
        return new TestablePhorumMigrateGroupsCommand(
            $this->app()->getContainer()->make(SettingsRepositoryInterface::class)
        );
    }

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

        $groups = $this->command()->runStep($connector);

        $this->assertCount(2, $groups);
        $this->assertSame('Admins', $groups[1]->name_singular);
        $this->assertSame('Members', $groups[2]->name_singular);
        $this->assertSame($groups[1]->id, PhorumMapping::getFlarumIdForPhorumId(PhorumMapping::DATA_TYPE_USER_GROUP, 1));
    }

    /**
     * @test
     */
    public function it_does_not_overwrite_an_already_mapped_group_when_re_run_as_a_separate_command_instance()
    {
        $connector = new FakeConnector();
        $connector->userGroups = [['group_id' => 1, 'name' => 'Admins']];
        $first = $this->command()->runStep($connector);

        // Simulate the group being renamed inside Flarum's own admin UI after the
        // first migrate:groups run.
        $first[1]->rename('Administrators', 'Administrators');
        $first[1]->save();

        // A fresh command instance stands in for a second, separate `phorum:migrate:groups`
        // invocation (e.g. a later CLI run in a new process). Phorum's own data hasn't
        // changed, but the Flarum-side name has - it must survive the re-run.
        $connector->userGroups = [['group_id' => 1, 'name' => 'Admins']];
        $second = $this->command()->runStep($connector);

        $this->assertSame($first[1]->id, $second[1]->id);
        $this->assertSame(1, Group::query()->where('id', $first[1]->id)->count());
        $this->assertSame('Administrators', Group::find($first[1]->id)->name_singular);
    }

    /**
     * @test
     */
    public function it_recreates_a_mapping_whose_flarum_group_was_deleted()
    {
        $connector = new FakeConnector();
        $connector->userGroups = [['group_id' => 1, 'name' => 'Admins']];
        $first = $this->command()->runStep($connector);
        $deletedId = $first[1]->id;
        $first[1]->delete();

        $second = $this->command()->runStep($connector);

        $this->assertNotSame($deletedId, $second[1]->id);
        $this->assertSame($second[1]->id, PhorumMapping::getFlarumIdForPhorumId(PhorumMapping::DATA_TYPE_USER_GROUP, 1));
    }
}
