<?php

namespace InfamousQ\FlarumPhorumMigrationTool\Console;

use InfamousQ\FlarumPhorumMigrationTool\Model\PhorumMapping;
use Throwable;

/**
 * Undoes phorum:migrate:discussions: deletes every Flarum discussion phorum:migrate:discussions
 * created, along with every post inside it (and that post's own phorum_mapping bookkeeping,
 * since posts belong to a different migration step than discussions).
 */
class PhorumResetDiscussionsCommand extends AbstractPhorumResetCommand {

	protected function configure() {
		$this
			->setName('phorum:reset:discussions')
			->setDescription('Undo phorum:migrate:discussions: delete every Flarum discussion it created, and every post inside it');
		$this->addForceOption();
	}

	protected function fire() {
		if (0 === $this->mappingCountForType(PhorumMapping::DATA_TYPE_DISCUSSION)) {
			$this->output->writeln('No migrated discussions found - nothing to reset.');
			return 0;
		}

		if (!$this->input->getOption('force') && !$this->confirm(
			'This will permanently delete every Flarum discussion created by phorum:migrate:discussions, '.
			'along with every post inside it.'
		)) {
			$this->output->writeln('Aborted, nothing was deleted.');
			return 0;
		}

		$counts = ['discussions' => 0, 'posts' => 0];

		try {
			$this->db->transaction(function () use (&$counts) {
				$this->deleteDiscussions($counts);
			});
		} catch (Throwable $e) {
			$this->error('Reset failed, transaction rolled back - no data was deleted: '.$e->getMessage());
			return 1;
		}

		$this->output->writeln("Discussions deleted: {$counts['discussions']}");
		$this->output->writeln("Posts deleted: {$counts['posts']}");

		return 0;
	}
}
