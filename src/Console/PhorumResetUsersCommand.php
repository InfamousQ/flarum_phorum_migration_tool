<?php

namespace InfamousQ\FlarumPhorumMigrationTool\Console;

use InfamousQ\FlarumPhorumMigrationTool\Model\PhorumMapping;
use Throwable;

/**
 * Undoes phorum:migrate:users: deletes every Flarum user phorum:migrate:users created.
 * Pre-existing Flarum accounts merely matched by email are left untouched.
 *
 * posts.user_id/discussions.user_id are ON DELETE SET NULL, not cascade - deleting a user
 * does not delete any posts/discussions they authored, it silently anonymizes them (author
 * becomes unknown). Reset posts/discussions first if you don't want that.
 */
class PhorumResetUsersCommand extends AbstractPhorumResetCommand {

	protected function configure() {
		$this
			->setName('phorum:reset:users')
			->setDescription('Undo phorum:migrate:users: delete every Flarum user it created');
		$this->addForceOption();
	}

	protected function fire() {
		if (0 === $this->mappingCountForType(PhorumMapping::DATA_TYPE_USER)) {
			$this->output->writeln('No migrated users found - nothing to reset.');
			return 0;
		}

		if (!$this->input->getOption('force') && !$this->confirm(
			'This will permanently delete every Flarum user created by phorum:migrate:users '.
			"(pre-existing accounts merely matched by email are left untouched).\n".
			'WARNING: any posts/discussions still authored by a deleted user are NOT deleted - '.
			'they are silently left with no author. Reset posts/discussions first if that is not what you want.'
		)) {
			$this->output->writeln('Aborted, nothing was deleted.');
			return 0;
		}

		$counts = ['users' => 0, 'users_skipped_existing' => 0];

		try {
			$this->db->transaction(function () use (&$counts) {
				$this->deleteUsers($counts);
			});
		} catch (Throwable $e) {
			$this->error('Reset failed, transaction rolled back - no data was deleted: '.$e->getMessage());
			return 1;
		}

		$this->output->writeln("Users deleted: {$counts['users']}");
		$this->output->writeln("Users skipped (pre-existing Flarum account matched by email, not deleted): {$counts['users_skipped_existing']}");

		return 0;
	}
}
