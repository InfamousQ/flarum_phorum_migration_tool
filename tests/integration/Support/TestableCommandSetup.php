<?php

namespace InfamousQ\FlarumPhorumMigrationTool\Tests\integration\Support;

use Psr\Log\NullLogger;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

/**
 * Populates the protected $input/$output/$logger properties normally set up
 * by Command::run()/execute(), so tests can call a Testable*Command's exposed
 * step method(s) directly instead of going through a real console invocation.
 */
trait TestableCommandSetup
{
    /** @var BufferedOutput */
    public $bufferedOutput;

    protected function setUpTestable(): void
    {
        $this->input = new ArrayInput([]);
        $this->bufferedOutput = new BufferedOutput();
        $this->output = $this->bufferedOutput;
        $this->setLogger(new NullLogger());
    }
}
