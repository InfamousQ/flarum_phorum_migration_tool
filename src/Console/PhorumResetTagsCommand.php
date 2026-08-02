<?php

namespace InfamousQ\FlarumPhorumMigrationTool\Console;

use InfamousQ\FlarumPhorumMigrationTool\Model\PhorumMapping;
use Throwable;

/**
 * Undoes phorum:migrate:tags: deletes every Flarum tag phorum:migrate:tags created.
 * discussion_tag rows are cleaned up via DB-level cascade - a discussion that only had a
 * deleted tag becomes untagged, it is NOT deleted.
 */
class PhorumResetTagsCommand extends AbstractPhorumResetCommand {

	protected function configure() {
		$this
			->setName('phorum:reset:tags')
			->setDescription('Undo phorum:migrate:tags: delete every Flarum tag it created');
		$this->addForceOption();
	}

	protected function fire() {
		if (0 === $this->mappingCountForType(PhorumMapping::DATA_TYPE_TAG)) {
			$this->output->writeln('No migrated tags found - nothing to reset.');
			return 0;
		}

		if (!$this->input->getOption('force') && !$this->confirm(
			'This will permanently delete every Flarum tag created by phorum:migrate:tags. '.
			'Discussions that only had a deleted tag will become untagged - they are NOT deleted themselves.'
		)) {
			$this->output->writeln('Aborted, nothing was deleted.');
			return 0;
		}

		$counts = ['tags' => 0];

		try {
			$this->db->transaction(function () use (&$counts) {
				$this->deleteTags($counts);
			});
		} catch (Throwable $e) {
			$this->error('Reset failed, transaction rolled back - no data was deleted: '.$e->getMessage());
			return 1;
		}

		$this->output->writeln("Tags deleted: {$counts['tags']}");

		return 0;
	}
}
