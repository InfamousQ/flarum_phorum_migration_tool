<?php

namespace InfamousQ\FlarumPhorumMigrationTool\Tests\unit\Log;

use InfamousQ\FlarumPhorumMigrationTool\Log\ConsoleLogger;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Output\OutputInterface;

class ConsoleLoggerTest extends TestCase
{
    public function test_the_context_is_printed_after_the_message()
    {
        $output = new BufferedOutput();

        (new ConsoleLogger($output))->critical('Unknown Phorum user id', ['user_id' => 999]);

        $this->assertSame('critical - Unknown Phorum user id {"user_id":999}'.PHP_EOL, $output->fetch());
    }

    public function test_warnings_and_worse_are_shown_without_verbose()
    {
        $output = new BufferedOutput(OutputInterface::VERBOSITY_NORMAL);
        $logger = new ConsoleLogger($output);

        $logger->warning('Shown warning');
        $logger->critical('Shown critical');
        $logger->info('Hidden info');
        $logger->debug('Hidden debug');

        $printed = $output->fetch();
        $this->assertStringContainsString('Shown warning', $printed);
        $this->assertStringContainsString('Shown critical', $printed);
        $this->assertStringNotContainsString('Hidden', $printed);
    }

    public function test_everything_is_shown_with_verbose()
    {
        $output = new BufferedOutput(OutputInterface::VERBOSITY_VERBOSE);
        $logger = new ConsoleLogger($output);

        $logger->info('Shown info');
        $logger->debug('Shown debug');

        $printed = $output->fetch();
        $this->assertStringContainsString('Shown info', $printed);
        $this->assertStringContainsString('Shown debug', $printed);
    }

    public function test_angle_brackets_in_a_message_are_printed_as_is()
    {
        $output = new BufferedOutput(OutputInterface::VERBOSITY_NORMAL, true);

        (new ConsoleLogger($output))->error('Subject <error> here');

        $this->assertSame('error - Subject <error> here'.PHP_EOL, $output->fetch());
    }
}
