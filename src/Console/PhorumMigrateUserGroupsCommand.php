<?php

namespace InfamousQ\FlarumPhorumMigrationTool\Console;

/**
 * Step 3 of the migration pipeline: assign migrated Flarum users to migrated
 * Flarum groups, based on each user's approved/moderator status in Phorum.
 * Requires phorum:migrate:groups and phorum:migrate:users to have run at least
 * once - it loads their output back from the phorum_mapping bookkeeping table
 * rather than Phorum data directly. Only adds missing memberships; never
 * removes a membership, so group changes made inside Flarum are left alone.
 */
class PhorumMigrateUserGroupsCommand extends AbstractPhorumMigrateCommand {

	protected function configure() {
		$this
			->setName('phorum:migrate:user-groups')
			->setDescription('Migrate step 3/6: assign migrated users to migrated groups (requires phorum:migrate:groups and phorum:migrate:users to have run first)');
	}

	protected function fire() {
		$this->setUpLogger();

		$groups = $this->loadGroupMap();
		$users = $this->loadUserMap();

		if (empty($groups)) {
			$this->output->writeln('No mapped Flarum groups found - run phorum:migrate:groups first.');
			return 1;
		}
		if (empty($users)) {
			$this->output->writeln('No mapped Flarum users found - run phorum:migrate:users first.');
			return 1;
		}

		$connector = $this->buildConnector();
		$this->importUserGroupMapping($connector, $groups, $users);

		$this->output->writeln('Done.');
	}
}
