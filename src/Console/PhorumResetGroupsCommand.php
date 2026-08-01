<?php

namespace InfamousQ\FlarumPhorumMigrationTool\Console;

use InfamousQ\FlarumPhorumMigrationTool\Model\PhorumMapping;
use Throwable;

/**
 * Undoes phorum:migrate:groups: deletes every Flarum group phorum:migrate:groups created.
 * Deleting a group cascades away ALL of its memberships at the DB level (group_user has
 * ON DELETE CASCADE), including any member who isn't a migrated user - there's no way to
 * delete a group but keep its membership list.
 */
class PhorumResetGroupsCommand extends AbstractPhorumResetCommand {

	protected function configure() {
		$this
			->setName('phorum:reset:groups')
			->setDescription('Undo phorum:migrate:groups: delete every Flarum group it created');
		$this->addForceOption();
	}

	protected function fire() {
		if (0 === $this->mappingCountForType(PhorumMapping::DATA_TYPE_USER_GROUP)) {
			$this->output->writeln('No migrated groups found - nothing to reset.');
			return 0;
		}

		if (!$this->input->getOption('force') && !$this->confirm(
			'This will permanently delete every Flarum group created by phorum:migrate:groups, '.
			'along with membership for everyone in those groups (not just migrated users).'
		)) {
			$this->output->writeln('Aborted, nothing was deleted.');
			return 0;
		}

		$counts = ['groups' => 0];

		try {
			$this->db->transaction(function () use (&$counts) {
				$this->deleteGroups($counts);
			});
		} catch (Throwable $e) {
			$this->error('Reset failed, transaction rolled back - no data was deleted: '.$e->getMessage());
			return 1;
		}

		$this->output->writeln("Groups deleted: {$counts['groups']}");

		return 0;
	}
}
