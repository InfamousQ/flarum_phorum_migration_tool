<?php

namespace InfamousQ\FlarumPhorumMigrationTool\Console;

/**
 * Step 1 of the migration pipeline: import Phorum user groups as Flarum groups.
 * Safe to re-run - groups already linked via phorum_mapping are left untouched,
 * so renames/permission changes made inside Flarum are not clobbered. Only
 * Phorum groups with no existing mapping are (re-)created.
 */
class PhorumMigrateGroupsCommand extends AbstractPhorumMigrateCommand {

	protected function configure() {
		$this
			->setName('phorum:migrate:groups')
			->setDescription('Migrate step 1/6: import Phorum user groups as Flarum groups');
	}

	protected function fire() {
		$this->setUpLogger();
		$connector = $this->buildConnector();

		$groups = $this->importUserGroups($connector);

		$this->output->writeln(sprintf('Done. %d Phorum user group(s) mapped to Flarum groups.', count($groups)));
	}
}
