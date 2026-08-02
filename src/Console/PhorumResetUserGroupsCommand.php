<?php

namespace InfamousQ\FlarumPhorumMigrationTool\Console;

use Throwable;

/**
 * Undoes phorum:migrate:user-groups: removes group membership for every migrated user in
 * every migrated group. Groups and users themselves are left untouched - only the group_user
 * link is removed.
 *
 * Unlike every other reset step, there is no dedicated phorum_mapping bookkeeping for "this
 * user was added to this group" (phorum:migrate:user-groups only ever adds a missing
 * membership, it doesn't create its own trackable row). This command therefore cannot tell
 * a membership the migration added apart from one added by hand inside Flarum afterward
 * between two otherwise-migrated groups/users - it removes all of them indiscriminately.
 */
class PhorumResetUserGroupsCommand extends AbstractPhorumResetCommand {

	protected function configure() {
		$this
			->setName('phorum:reset:user-groups')
			->setDescription('Undo phorum:migrate:user-groups: remove membership between migrated users and migrated groups');
		$this->addForceOption();
	}

	protected function fire() {
		$membershipCount = $this->countUserGroupMemberships();
		if (0 === $membershipCount) {
			$this->output->writeln('No membership between migrated users and migrated groups found - nothing to reset.');
			return 0;
		}

		if (!$this->input->getOption('force') && !$this->confirm(
			"This will remove group membership for every migrated user in every migrated group ({$membershipCount} membership(s)).\n".
			'WARNING: since individual memberships are not tracked, this cannot tell a membership '.
			'phorum:migrate:user-groups added apart from one you added by hand afterward between '.
			'two migrated groups/users - it removes all of them indiscriminately. Groups and users themselves are not deleted.'
		)) {
			$this->output->writeln('Aborted, nothing was deleted.');
			return 0;
		}

		$counts = ['user_group_memberships' => 0];

		try {
			$this->db->transaction(function () use (&$counts) {
				$this->detachUserGroupAssignments($counts);
			});
		} catch (Throwable $e) {
			$this->error('Reset failed, transaction rolled back - no data was deleted: '.$e->getMessage());
			return 1;
		}

		$this->output->writeln("Group memberships removed: {$counts['user_group_memberships']}");

		return 0;
	}
}
