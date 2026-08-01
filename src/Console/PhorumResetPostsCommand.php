<?php

namespace InfamousQ\FlarumPhorumMigrationTool\Console;

use InfamousQ\FlarumPhorumMigrationTool\Model\PhorumMapping;
use Throwable;

/**
 * Undoes phorum:migrate:posts: deletes every Flarum post phorum:migrate:posts created.
 *
 * discussions.first_post_id/last_post_id are ON DELETE SET NULL, and comment_count is a
 * plain column with no DB-level recalculation - so after this runs, affected discussions will
 * show no first/last post and a stale comment count until phorum:migrate:posts is re-run.
 * This is the intended pair for "redo just the posts": run this, then phorum:migrate:posts.
 */
class PhorumResetPostsCommand extends AbstractPhorumResetCommand {

	protected function configure() {
		$this
			->setName('phorum:reset:posts')
			->setDescription('Undo phorum:migrate:posts: delete every Flarum post it created');
		$this->addForceOption();
	}

	protected function fire() {
		if (0 === $this->mappingCountForType(PhorumMapping::DATA_TYPE_MESSAGE)) {
			$this->output->writeln('No migrated posts found - nothing to reset.');
			return 0;
		}

		if (!$this->input->getOption('force') && !$this->confirm(
			'This will permanently delete every Flarum post created by phorum:migrate:posts. '.
			'Their discussions will be left with no first/last post and a stale comment count '.
			'until you re-run phorum:migrate:posts.'
		)) {
			$this->output->writeln('Aborted, nothing was deleted.');
			return 0;
		}

		$counts = ['posts' => 0];

		try {
			$this->db->transaction(function () use (&$counts) {
				$this->deletePosts($counts);
			});
		} catch (Throwable $e) {
			$this->error('Reset failed, transaction rolled back - no data was deleted: '.$e->getMessage());
			return 1;
		}

		$this->output->writeln("Posts deleted: {$counts['posts']}");

		return 0;
	}
}
