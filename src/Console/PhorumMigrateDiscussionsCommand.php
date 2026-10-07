<?php

namespace InfamousQ\FlarumPhorumMigrationTool\Console;

/**
 * Step 5 of the migration pipeline: create a Flarum discussion for each Phorum
 * thread's starting message. Requires phorum:migrate:users and
 * phorum:migrate:tags to have run at least once - it loads their output back
 * from the phorum_mapping bookkeeping table rather than Phorum data directly.
 * Safe to re-run - threads already linked via phorum_mapping are left
 * untouched, so a new run only creates discussions for threads that weren't
 * present (or whose author/forum wasn't yet mapped) on an earlier run.
 */
class PhorumMigrateDiscussionsCommand extends AbstractPhorumMigrateCommand {

	protected function configure() {
		$this
			->setName('phorum:migrate:discussions')
			->setDescription('Migrate step 5/6: create discussions from Phorum thread starting messages (requires phorum:migrate:users and phorum:migrate:tags to have run first)');
	}

	protected function fire() {
		$this->setUpLogger();

		$users = $this->loadUserMap();
		$tags = $this->loadTagMap();

		if (empty($users)) {
			$this->output->writeln('No mapped Flarum users found - run phorum:migrate:users first.');
			return 1;
		}
		if (empty($tags)) {
			$this->output->writeln('No mapped Flarum tags found - run phorum:migrate:tags first.');
			return 1;
		}

		$connector = $this->buildConnector();
		// The guest placeholder user may be created in this step
		if (!$this->runPreflightChecks($connector)) {
			return 1;
		}
		$discussions = $this->importPhorumMessagesAsDiscussions($connector, $users, $tags);

		$this->output->writeln(sprintf('Done. %d Phorum thread(s) mapped to Flarum discussions.', count($discussions)));
	}
}
