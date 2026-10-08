<?php

namespace InfamousQ\FlarumPhorumMigrationTool\Tests\integration\Preflight;

use InfamousQ\FlarumPhorumMigrationTool\Preflight\DuplicateEmailCheck;
use InfamousQ\FlarumPhorumMigrationTool\Tests\integration\Support\FakeConnector;
use InfamousQ\FlarumPhorumMigrationTool\Tests\integration\TestCase;

class DuplicateEmailCheckTest extends TestCase
{
    protected function phorumUser(int $id, string $email, int $active = 1, int $message_count = 1): array
    {
        return ['user_id' => $id, 'display_name' => "user{$id}", 'real_name' => '', 'email' => $email, 'active' => $active, 'admin' => 0, 'message_count' => $message_count];
    }

    /**
     * @test
     */
    public function it_passes_when_every_email_is_unique()
    {
        $connector = new FakeConnector();
        $connector->users = [
            $this->phorumUser(1, 'one@example.com'),
            $this->phorumUser(2, 'two@example.com'),
        ];

        $this->assertSame([], (new DuplicateEmailCheck())->run($connector));
    }

    /**
     * @test
     */
    public function it_reports_users_sharing_an_email_ignoring_case()
    {
        $connector = new FakeConnector();
        $connector->users = [
            $this->phorumUser(1, 'shared@example.com'),
            $this->phorumUser(2, 'unique@example.com'),
            $this->phorumUser(3, 'Shared@Example.com'),
        ];

        $problems = (new DuplicateEmailCheck())->run($connector);

        $this->assertCount(1, $problems);
        $this->assertStringContainsString('Phorum users 1, 3', $problems[0]);
        $this->assertStringContainsString("'shared@example.com'", $problems[0]);
    }

    /**
     * @test
     */
    public function it_reports_users_with_an_empty_email()
    {
        $connector = new FakeConnector();
        $connector->users = [
            $this->phorumUser(1, ''),
            $this->phorumUser(2, ''),
        ];

        $problems = (new DuplicateEmailCheck())->run($connector);

        $this->assertCount(1, $problems);
        $this->assertStringContainsString('(empty)', $problems[0]);
    }

    /**
     * @test
     */
    public function it_ignores_users_the_import_skips()
    {
        $connector = new FakeConnector();
        $connector->users = [
            $this->phorumUser(1, 'shared@example.com'),
            // Inactive with no messages: never imported
            $this->phorumUser(2, 'shared@example.com', 0, 0),
        ];

        $this->assertSame([], (new DuplicateEmailCheck())->run($connector));
    }
}
