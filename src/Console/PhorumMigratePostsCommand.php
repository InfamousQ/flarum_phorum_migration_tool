<?php

namespace InfamousQ\FlarumPhorumMigrationTool\Console;

/**
 * Step 6 of the migration pipeline: import every Phorum message as a Flarum
 * post. Requires phorum:migrate:users and phorum:migrate:discussions to have
 * run at least once - it loads their output back from the phorum_mapping
 * bookkeeping table rather than Phorum data directly. Safe to re-run - posts
 * already linked via phorum_mapping are left untouched, so a new run only
 * inserts posts that are missing. This is the step to re-run on its own to
 * pick up new posts once tags/groups/roles have been hand-tuned in Flarum.
 */
class PhorumMigratePostsCommand extends AbstractPhorumMigrateCommand {

	protected function configure() {
		$this
			->setName('phorum:migrate:posts')
			->setDescription('Migrate step 6/6: import Phorum messages as posts (requires phorum:migrate:users and phorum:migrate:discussions to have run first)');
	}

	protected function fire() {
		$this->setUpLogger();

		$users = $this->loadUserMap();
		$discussions = $this->loadDiscussionMap();

		if (empty($users)) {
			$this->output->writeln('No mapped Flarum users found - run phorum:migrate:users first.');
			return 1;
		}
		if (empty($discussions)) {
			$this->output->writeln('No mapped Flarum discussions found - run phorum:migrate:discussions first.');
			return 1;
		}

		$connector = $this->buildConnector();
		$this->importPhorumMessages($connector, $discussions, $users);

		$this->output->writeln('Done.');
	}
}
