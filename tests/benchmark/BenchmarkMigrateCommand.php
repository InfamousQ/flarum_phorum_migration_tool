<?php

namespace InfamousQ\FlarumPhorumMigrationTool\Tests\benchmark;

use InfamousQ\FlarumPhorumMigrationTool\Console\PhorumMigrateCommand;
use InfamousQ\FlarumPhorumMigrationTool\Phorum\Connector;
use Psr\Log\NullLogger;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\NullOutput;

/**
 * Runs phorum:migrate's steps one by one, so each can be measured on its own.
 * Output is discarded rather than buffered, which would otherwise count towards
 * the measured memory.
 */
class BenchmarkMigrateCommand extends PhorumMigrateCommand
{
    public function __construct($settings)
    {
        parent::__construct($settings);
        $this->input = new ArrayInput([]);
        $this->output = new NullOutput();
        $this->setLogger(new NullLogger());
    }

    /**
     * @param callable $measure function (string $step, callable $run) returning $run()'s result
     */
    public function runSteps(Connector $connector, callable $measure): void
    {
        $groups = $measure('groups', fn () => $this->importUserGroups($connector));
        $users = $measure('users', fn () => $this->importUsers($connector));
        $measure('user-groups', fn () => $this->importUserGroupMapping($connector, $groups, $users));
        $tags = $measure('tags', fn () => $this->importPhorumForumsAsTags($connector));
        $discussions = $measure('discussions', function () use ($connector, &$users, $tags) {
            return $this->importPhorumMessagesAsDiscussions($connector, $users, $tags);
        });
        $measure('posts', fn () => $this->importPhorumMessages($connector, $discussions, $users));
    }
}
