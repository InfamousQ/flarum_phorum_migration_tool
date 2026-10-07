<?php

namespace InfamousQ\FlarumPhorumMigrationTool\Log;

use Psr\Log\AbstractLogger;
use Psr\Log\LogLevel;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Writes log records to the console. Warnings and worse (e.g. a Phorum message
 * skipped because its author is unknown) are always shown, on stderr when there
 * is one; anything less severe only with -v. The context is printed after the
 * message, since it carries the ids needed to find the record in question.
 */
class ConsoleLogger extends AbstractLogger {

	const ALWAYS_SHOWN_LEVELS = [
		LogLevel::EMERGENCY,
		LogLevel::ALERT,
		LogLevel::CRITICAL,
		LogLevel::ERROR,
		LogLevel::WARNING,
	];

	/** @var OutputInterface */
	protected $output;

	public function __construct(OutputInterface $output) {
		$this->output = $output;
	}

	public function log($level, $message, array $context = []) {
		$line = "{$level} - {$message}";
		if (!empty($context)) {
			$line .= ' ' . json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
		}

		$output = $this->output;
		$verbosity = OutputInterface::VERBOSITY_VERBOSE;
		if (in_array($level, self::ALWAYS_SHOWN_LEVELS, true)) {
			$verbosity = OutputInterface::VERBOSITY_NORMAL;
			if ($output instanceof ConsoleOutputInterface) {
				$output = $output->getErrorOutput();
			}
		}

		// Raw, so that "<...>" in a message is not taken for console formatting
		$output->writeln($line, OutputInterface::OUTPUT_RAW | $verbosity);
	}

}
