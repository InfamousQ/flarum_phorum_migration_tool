<?php

namespace InfamousQ\FlarumPhorumMigrationTool\Tests\benchmark;

use Flarum\Settings\SettingsRepositoryInterface;
use InfamousQ\FlarumPhorumMigrationTool\Model\PhorumMapping;
use InfamousQ\FlarumPhorumMigrationTool\Tests\integration\Support\PhorumDatabase;
use InfamousQ\FlarumPhorumMigrationTool\Tests\integration\TestCase;

/**
 * Migrates a synthetic Phorum forum twice (a first run, then a re-run with
 * nothing new) and prints wall time, query count and peak memory per step.
 * Not part of `composer test`: run with `composer benchmark`. Size it with the
 * BENCH_USERS, BENCH_THREADS and BENCH_MESSAGES_PER_THREAD (average) env vars.
 *
 * Peak memory is measured above what was in use when the step started, so it
 * shows what the step itself allocates.
 */
class MigrationBenchmark extends TestCase
{
    /** @var PhorumDatabase */
    protected $phorum;

    protected function setUp(): void
    {
        parent::setUp();

        $this->phorum = new PhorumDatabase();
        $this->phorum->createTables();
    }

    protected function tearDown(): void
    {
        $this->phorum->dropTables();

        parent::tearDown();
    }

    protected function env(string $name, int $default): int
    {
        return (int) (getenv($name) ?: $default);
    }

    /**
     * @test
     */
    public function migrate_a_synthetic_forum()
    {
        $users = $this->env('BENCH_USERS', 1000);
        $threads = $this->env('BENCH_THREADS', 2000);
        $per_thread = $this->env('BENCH_MESSAGES_PER_THREAD', 10);
        $message_count = $this->seed($users, $threads, $per_thread);
        $this->report("Synthetic Phorum: {$users} users, {$threads} threads, {$message_count} messages");

        $settings = $this->app()->getContainer()->make(SettingsRepositoryInterface::class);
        $results = [];
        foreach (['first run', 're-run'] as $run) {
            gc_collect_cycles();
            (new BenchmarkMigrateCommand($settings))->runSteps($this->phorum->connector(), function (string $step, callable $fn) use ($run, &$results) {
                return $this->measure($run, $step, $fn, $results);
            });
        }
        $this->printResults($results);

        $this->assertSame($message_count, PhorumMapping::where('phorum_data_type', PhorumMapping::DATA_TYPE_MESSAGE)->count());
    }

    protected function measure(string $run, string $step, callable $fn, array &$results)
    {
        $queries = 0;
        $db = PhorumMapping::query()->getConnection();
        $counting = true;
        $db->listen(function () use (&$queries, &$counting) {
            if ($counting) {
                $queries++;
            }
        });

        $memory_before = memory_get_usage();
        memory_reset_peak_usage();
        $start = hrtime(true);
        $result = $fn();
        $seconds = (hrtime(true) - $start) / 1e9;
        $peak = memory_get_peak_usage() - $memory_before;
        // Listeners can't be removed, so this one stops counting instead
        $counting = false;

        $results[] = [$run, $step, $seconds, $queries, $peak];

        return $result;
    }

    protected function printResults(array $results): void
    {
        $this->report(sprintf('%-10s %-12s %10s %10s %12s', 'run', 'step', 'seconds', 'queries', 'peak MB'));
        $totals = [];
        foreach ($results as [$run, $step, $seconds, $queries, $peak]) {
            $this->report(sprintf('%-10s %-12s %10.2f %10d %12.1f', $run, $step, $seconds, $queries, $peak / 1048576));
            $totals[$run]['seconds'] = ($totals[$run]['seconds'] ?? 0) + $seconds;
            $totals[$run]['queries'] = ($totals[$run]['queries'] ?? 0) + $queries;
            $totals[$run]['peak'] = max($totals[$run]['peak'] ?? 0, $peak);
        }
        foreach ($totals as $run => $total) {
            $this->report(sprintf('%-10s %-12s %10.2f %10d %12.1f', $run, 'TOTAL', $total['seconds'], $total['queries'], $total['peak'] / 1048576));
        }
    }

    protected function report(string $line): void
    {
        fwrite(STDERR, $line.PHP_EOL);
    }

    /**
     * Deterministic synthetic data: a few groups and forums, users with group
     * memberships, and threads of varying length with BBCode bodies, the odd
     * guest message and the odd hidden message.
     *
     * @return int Number of messages created
     */
    protected function seed(int $user_count, int $thread_count, int $per_thread): int
    {
        mt_srand(1);

        $groups = [];
        for ($g = 1; $g <= 5; $g++) {
            $groups[] = ['group_id' => $g, 'name' => "Group {$g}"];
        }
        $this->phorum->insertMany('groups', $groups);

        $users = [];
        $xref = [];
        for ($u = 1; $u <= $user_count; $u++) {
            $users[] = ['user_id' => $u, 'display_name' => "User {$u}", 'real_name' => '', 'email' => "user{$u}@phorum.example.com", 'active' => 1, 'admin' => 0];
            $xref[] = ['user_id' => $u, 'group_id' => mt_rand(1, 5), 'status' => 1];
        }
        $this->phorum->insertMany('users', $users);
        $this->phorum->insertMany('user_group_xref', $xref);

        $forums = [];
        for ($f = 1; $f <= 10; $f++) {
            $forums[] = ['forum_id' => $f, 'name' => "Forum {$f}", 'description' => '', 'display_order' => $f, 'pub_perms' => 1, 'reg_perms' => 15];
        }
        $this->phorum->insertMany('forums', $forums);

        $messages = [];
        $message_id = 0;
        $datestamp = 1000000000;
        for ($t = 1; $t <= $thread_count; $t++) {
            $forum_id = mt_rand(1, 10);
            $thread_id = $message_id + 1;
            $length = mt_rand(1, 2 * $per_thread - 1);
            for ($m = 0; $m < $length; $m++) {
                $message_id++;
                $datestamp += mt_rand(60, 3600);
                $messages[] = [
                    'message_id' => $message_id,
                    'forum_id' => $forum_id,
                    'thread' => $thread_id,
                    'parent_id' => 0 === $m ? 0 : $thread_id,
                    'user_id' => 0 === mt_rand(0, 50) ? 0 : mt_rand(1, $user_count),
                    'subject' => 0 === $m ? "Thread {$t}" : "Re: Thread {$t}",
                    'body' => $this->body(),
                    'status' => 0 === mt_rand(0, 100) ? -2 : 2,
                    'sort' => 2,
                    'closed' => 0,
                    'datestamp' => $datestamp,
                ];
            }
            if (count($messages) >= 5000) {
                $this->phorum->insertMany('messages', $messages);
                $messages = [];
            }
        }
        $this->phorum->insertMany('messages', $messages);

        return $message_id;
    }

    protected function body(): string
    {
        $paragraphs = [];
        for ($p = mt_rand(1, 4); $p > 0; $p--) {
            $paragraphs[] = str_repeat('Lorem ipsum dolor sit amet, consectetur adipiscing elit. ', mt_rand(1, 6));
        }
        $extras = ['[b]bold[/b]', '[i]italic[/i]', '[url=https://example.com]a link[/url]', '[quote]quoted text[/quote]', '[size=large]big[/size]', '[hr]'];

        return implode("\n\n", $paragraphs).' '.$extras[mt_rand(0, count($extras) - 1)];
    }
}
