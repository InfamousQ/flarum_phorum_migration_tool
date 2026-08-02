<?php

namespace InfamousQ\FlarumPhorumMigrationTool\Console;

use InfamousQ\FlarumPhorumMigrationTool\Model\PhorumMapping;
use Throwable;

/**
 * Dev/test convenience command: undoes a `phorum:migrate` run by deleting everything in
 * Flarum that the migration tool itself created, using the `phorum_mapping` bookkeeping
 * table as the source of truth for "what did we create". This lets a developer iterate on
 * `phorum:migrate` locally (fix a config mistake, re-run, repeat) without having to restore
 * a database snapshot every time.
 *
 * This is intentionally a blunt full reset, not a surgical one - acceptable for a local dev/
 * test tool, but it is destructive. It deliberately does NOT delete Flarum users that were
 * merely *matched* to a pre-existing account by email during migration (see importUsers() in
 * AbstractPhorumMigrateCommand - those are recorded in phorum_mapping with `existing = true`);
 * only users the migration actually created (`existing = false`) are removed. Flarum core
 * itself also refuses to delete the root admin (user id 1) as a last-resort safety net.
 *
 * See also the individual `phorum:reset:*` commands (groups/users/tags/discussions/posts/
 * user-groups) for resetting just one step's data, e.g. to redo only phorum:migrate:posts.
 */
class PhorumResetCommand extends AbstractPhorumResetCommand {

	protected function configure() {
		$this
			->setName('phorum:reset')
			->setDescription('Delete everything created by a previous phorum:migrate run (dev/test convenience only), so it can be re-run from a clean slate');
		$this->addForceOption();
	}

	protected function fire() {
		if (!PhorumMapping::doesMappingTableHaveData()) {
			$this->output->writeln('phorum_mapping table is empty - nothing to reset.');
			return 0;
		}

		if (!$this->input->getOption('force') && !$this->confirm(
			'This will permanently delete all discussions, posts, tags and groups created by '.
			"the Phorum migration, plus any Flarum users it created (pre-existing Flarum users \n".
			'that were merely matched by email are left untouched).'
		)) {
			$this->output->writeln('Aborted, nothing was deleted.');
			return 0;
		}

		$counts = [
			'posts' => 0,
			'discussions' => 0,
			'tags' => 0,
			'groups' => 0,
			'users' => 0,
			'users_skipped_existing' => 0,
		];

		try {
			$this->db->transaction(function () use (&$counts) {
				$this->deletePosts($counts);
				$this->deleteDiscussions($counts);
				$this->deleteTags($counts);
				$this->deleteGroups($counts);
				$this->deleteUsers($counts);
			});
		} catch (Throwable $e) {
			$this->error('Reset failed, transaction rolled back - no data was deleted: '.$e->getMessage());
			return 1;
		}

		$this->output->writeln('Phorum migration reset complete:');
		$this->output->writeln("  Posts deleted: {$counts['posts']}");
		$this->output->writeln("  Discussions deleted: {$counts['discussions']}");
		$this->output->writeln("  Tags deleted: {$counts['tags']}");
		$this->output->writeln("  Groups deleted: {$counts['groups']}");
		$this->output->writeln("  Users deleted: {$counts['users']}");
		$this->output->writeln("  Users skipped (pre-existing Flarum account matched by email, not deleted): {$counts['users_skipped_existing']}");
		$this->output->writeln('the next phorum:migrate run will start from a clean slate.');

		return 0;
	}
}
