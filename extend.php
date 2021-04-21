<?php

use Flarum\Extend\Console;
use InfamousQ\FlarumPhorumMigrationTool\Console\PhorumMigrateCommand;
use InfamousQ\FlarumPhorumMigrationTool\Console\PhorumViewCommand;

return [
	(new Console())->command(PhorumMigrateCommand::class),
	(new Console())->command(PhorumViewCommand::class),
];
