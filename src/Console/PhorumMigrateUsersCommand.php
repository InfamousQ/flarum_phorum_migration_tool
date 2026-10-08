<?php

namespace InfamousQ\FlarumPhorumMigrationTool\Console;

use InfamousQ\FlarumPhorumMigrationTool\Preflight\DuplicateEmailCheck;

/**
 * Step 2 of the migration pipeline: import Phorum users as Flarum users (either
 * a brand new account, or matched to a pre-existing Flarum account by email).
 * Safe to re-run - users already linked via phorum_mapping are left untouched,
 * so a rename/permission change made inside Flarum is not clobbered. Only
 * Phorum users with no existing mapping are (re-)created/matched. This is the
 * step to re-run before phorum:migrate:discussions/:posts to pick up authors
 * of newly-imported content that weren't present on an earlier run.
 */
class PhorumMigrateUsersCommand extends AbstractPhorumMigrateCommand {

	protected function configure() {
		$this
			->setName('phorum:migrate:users')
			->setDescription('Migrate step 2/6: import Phorum users as Flarum users');
	}

	protected function preflightChecks() : array {
		return array_merge(parent::preflightChecks(), [new DuplicateEmailCheck()]);
	}

	protected function fire() {
		$this->setUpLogger();
		$connector = $this->buildConnector();
		if (!$this->runPreflightChecks($connector)) {
			return 1;
		}

		$users = $this->importUsers($connector);

		$this->output->writeln(sprintf('Done. %d Phorum user(s) mapped to Flarum users.', count($users)));
	}
}
