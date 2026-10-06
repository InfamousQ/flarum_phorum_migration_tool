<?php

namespace InfamousQ\FlarumPhorumMigrationTool\Tests\integration\Console;

use Flarum\Discussion\Discussion;
use Flarum\Group\Group;
use Flarum\Post\Post;
use Flarum\Settings\SettingsRepositoryInterface;
use Flarum\Tags\Tag;
use Flarum\User\User;
use InfamousQ\FlarumPhorumMigrationTool\Model\PhorumMapping;
use InfamousQ\FlarumPhorumMigrationTool\Tests\integration\Support\FakeConnector;
use InfamousQ\FlarumPhorumMigrationTool\Tests\integration\Support\TestablePhorumMigrateCommand;
use InfamousQ\FlarumPhorumMigrationTool\Tests\integration\TestCase;
use Symfony\Component\Console\Tester\CommandTester;

class PhorumResetCommandTest extends TestCase
{
    protected function migrateCommand(): TestablePhorumMigrateCommand
    {
        return new TestablePhorumMigrateCommand(
            $this->app()->getContainer()->make(SettingsRepositoryInterface::class)
        );
    }

    protected function resetCommandTester(): CommandTester
    {
        return new CommandTester($this->console()->find('phorum:reset'));
    }

    protected function runFixtureMigration(): void
    {
        $connector = new FakeConnector();
        $connector->userGroups = [['group_id' => 1, 'name' => 'Members']];
        $connector->users = [
            ['user_id' => 1, 'display_name' => 'Alice', 'real_name' => '', 'email' => 'alice@example.com', 'active' => 1, 'admin' => 0],
        ];
        $connector->forums = [['forum_id' => 10, 'name' => 'Phorum General', 'description' => '', 'parent_id' => 0, 'display_order' => 1, 'pub_perms' => 1, 'reg_perms' => 15]];
        $connector->threadStartingMessages = [
            ['forum_id' => 10, 'thread' => 100, 'user_id' => 1, 'subject' => 'Thread', 'status' => 2, 'sort' => 0, 'closed' => 0],
        ];
        $connector->threadMessages[100] = [
            ['message_id' => 1000, 'user_id' => 1, 'body' => 'Post', 'datestamp' => 1600000000, 'status' => 2],
        ];

        $this->migrateCommand()->runFullMigration($connector);
    }

    /**
     * @test
     */
    public function it_reports_nothing_to_reset_when_mapping_table_is_empty()
    {
        $tester = $this->resetCommandTester();
        $tester->execute(['--force' => true]);

        $this->assertStringContainsString('nothing to reset', $tester->getDisplay());
        $this->assertSame(0, $tester->getStatusCode());
    }

    /**
     * @test
     */
    public function it_deletes_everything_created_by_the_migration_when_forced()
    {
        $this->runFixtureMigration();

        $tester = $this->resetCommandTester();
        $tester->execute(['--force' => true]);

        $this->assertSame(0, Post::query()->count());
        $this->assertSame(0, Discussion::query()->count());
        $this->assertSame(0, Tag::query()->where('name', 'Phorum General')->count());
        $this->assertSame(0, Group::query()->where('name_singular', 'Members')->count());
        $this->assertSame(0, User::query()->where('email', 'alice@example.com')->count());
        $this->assertSame(0, PhorumMapping::query()->count());
    }

    /**
     * @test
     */
    public function it_does_not_delete_a_pre_existing_user_matched_by_email()
    {
        $existing = User::register('PreExisting', 'shared@example.com', 'password');
        $existing->save();

        $connector = new FakeConnector();
        $connector->users = [
            ['user_id' => 1, 'display_name' => 'Alice', 'real_name' => '', 'email' => 'shared@example.com', 'active' => 1, 'admin' => 0],
        ];
        $this->migrateCommand()->runImportUsers($connector);

        $tester = $this->resetCommandTester();
        $tester->execute(['--force' => true]);

        $this->assertNotNull(User::find($existing->id));
        $this->assertStringContainsString('Users skipped (pre-existing', $tester->getDisplay());
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
    }

    /**
     * @test
     */
    public function it_aborts_and_deletes_nothing_when_confirmation_is_declined()
    {
        $this->runFixtureMigration();

        $tester = $this->resetCommandTester();
        $tester->setInputs(['n']);
        $tester->execute([]);

        $this->assertStringContainsString('Aborted', $tester->getDisplay());
        $this->assertGreaterThan(0, Discussion::query()->count());
        $this->assertGreaterThan(0, PhorumMapping::query()->count());
    }

    /**
     * @test
     */
    public function it_deletes_when_confirmation_is_accepted_interactively()
    {
        $this->runFixtureMigration();

        $tester = $this->resetCommandTester();
        $tester->setInputs(['y']);
        $tester->execute([]);

        $this->assertSame(0, Discussion::query()->count());
        $this->assertSame(0, PhorumMapping::query()->count());
    }
}
