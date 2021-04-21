<?php

namespace InfamousQ\FlarumPhorumMigrationTool\Log;

use Psr\Log\AbstractLogger;

class ConsoleLogger extends AbstractLogger {

	public function log($level, $message, array $context = []) {
		echo "{$level} - {$message}".PHP_EOL;
	}

}