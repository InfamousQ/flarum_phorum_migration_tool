<?php

use Flarum\Extend\Console;
use Flarum\Extend\Frontend;
use Flarum\Extend\Locales;
use InfamousQ\FlarumPhorumMigrationTool\Console\PhorumMigrateCommand;
use InfamousQ\FlarumPhorumMigrationTool\Console\PhorumViewCommand;

return [
	(new Console())->command(PhorumMigrateCommand::class),
	(new Console())->command(PhorumViewCommand::class),
	(new Frontend('admin'))
		->js(__DIR__.'/js/dist/admin.js'),
	(new Locales(__DIR__.'/locale')),
];
