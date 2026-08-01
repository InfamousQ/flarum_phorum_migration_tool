<?php

use Flarum\Extend\Console;
use Flarum\Extend\Frontend;
use Flarum\Extend\Locales;
use InfamousQ\FlarumPhorumMigrationTool\Console\PhorumMigrateCommand;
use InfamousQ\FlarumPhorumMigrationTool\Console\PhorumMigrateGroupsCommand;
use InfamousQ\FlarumPhorumMigrationTool\Console\PhorumMigrateUsersCommand;
use InfamousQ\FlarumPhorumMigrationTool\Console\PhorumMigrateUserGroupsCommand;
use InfamousQ\FlarumPhorumMigrationTool\Console\PhorumMigrateTagsCommand;
use InfamousQ\FlarumPhorumMigrationTool\Console\PhorumMigrateDiscussionsCommand;
use InfamousQ\FlarumPhorumMigrationTool\Console\PhorumMigratePostsCommand;
use InfamousQ\FlarumPhorumMigrationTool\Console\PhorumResetCommand;
use InfamousQ\FlarumPhorumMigrationTool\Console\PhorumResetGroupsCommand;
use InfamousQ\FlarumPhorumMigrationTool\Console\PhorumResetUsersCommand;
use InfamousQ\FlarumPhorumMigrationTool\Console\PhorumResetUserGroupsCommand;
use InfamousQ\FlarumPhorumMigrationTool\Console\PhorumResetTagsCommand;
use InfamousQ\FlarumPhorumMigrationTool\Console\PhorumResetDiscussionsCommand;
use InfamousQ\FlarumPhorumMigrationTool\Console\PhorumResetPostsCommand;
use InfamousQ\FlarumPhorumMigrationTool\Console\PhorumViewCommand;

return [
	(new Console())->command(PhorumMigrateCommand::class),
	(new Console())->command(PhorumMigrateGroupsCommand::class),
	(new Console())->command(PhorumMigrateUsersCommand::class),
	(new Console())->command(PhorumMigrateUserGroupsCommand::class),
	(new Console())->command(PhorumMigrateTagsCommand::class),
	(new Console())->command(PhorumMigrateDiscussionsCommand::class),
	(new Console())->command(PhorumMigratePostsCommand::class),
	(new Console())->command(PhorumViewCommand::class),
	(new Console())->command(PhorumResetCommand::class),
	(new Console())->command(PhorumResetGroupsCommand::class),
	(new Console())->command(PhorumResetUsersCommand::class),
	(new Console())->command(PhorumResetUserGroupsCommand::class),
	(new Console())->command(PhorumResetTagsCommand::class),
	(new Console())->command(PhorumResetDiscussionsCommand::class),
	(new Console())->command(PhorumResetPostsCommand::class),
	(new Frontend('admin'))
		->js(__DIR__.'/js/dist/admin.js'),
	(new Locales(__DIR__.'/locale')),
];
