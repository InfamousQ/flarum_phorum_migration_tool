<?php

namespace InfamousQ\FlarumPhorumMigrationTool\Tests\integration\Preflight;

use Flarum\Settings\SettingsRepositoryInterface;
use Illuminate\Database\ConnectionInterface;
use InfamousQ\FlarumPhorumMigrationTool\Model\PhorumMapping;
use InfamousQ\FlarumPhorumMigrationTool\Preflight\AutoIncrementCheck;
use InfamousQ\FlarumPhorumMigrationTool\Tests\integration\Support\FakeConnector;
use InfamousQ\FlarumPhorumMigrationTool\Tests\integration\Support\TestablePhorumMigrateDiscussionsCommand;
use InfamousQ\FlarumPhorumMigrationTool\Tests\integration\Support\TestablePhorumMigrateUsersCommand;
use InfamousQ\FlarumPhorumMigrationTool\Tests\integration\TestCase;

/**
 * Changes auto_increment_increment for the test connection's session only, the
 * same value the check reads, and puts it back after each test.
 */
class AutoIncrementCheckTest extends TestCase
{
    protected function db(): ConnectionInterface
    {
        return PhorumMapping::query()->getConnection();
    }

    protected function setIncrement(int $increment): void
    {
        $this->db()->statement("SET SESSION auto_increment_increment = {$increment}");
    }

    protected function tearDown(): void
    {
        $this->setIncrement(1);

        parent::tearDown();
    }

    /**
     * @test
     */
    public function it_passes_when_ids_increase_in_steps_of_1()
    {
        $this->setIncrement(1);

        $this->assertSame([], (new AutoIncrementCheck($this->db()))->run(new FakeConnector()));
    }

    /**
     * @test
     */
    public function it_reports_a_larger_step()
    {
        $this->setIncrement(3);

        $problems = (new AutoIncrementCheck($this->db()))->run(new FakeConnector());

        $this->assertCount(1, $problems);
        $this->assertStringContainsString('auto_increment_increment = 3', $problems[0]);
    }

    /**
     * @test
     */
    public function only_steps_that_bulk_insert_discussions_run_it()
    {
        $this->setIncrement(2);
        $settings = $this->app()->getContainer()->make(SettingsRepositoryInterface::class);

        $discussionsCommand = new TestablePhorumMigrateDiscussionsCommand($settings);
        $this->assertFalse($discussionsCommand->runPreflight(new FakeConnector()));
        $this->assertStringContainsString('auto_increment_increment = 2', $discussionsCommand->bufferedOutput->fetch());

        $this->assertTrue((new TestablePhorumMigrateUsersCommand($settings))->runPreflight(new FakeConnector()));
    }
}
