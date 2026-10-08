<?php

namespace InfamousQ\FlarumPhorumMigrationTool\Preflight;

use InfamousQ\FlarumPhorumMigrationTool\Console\AbstractPhorumMigrateCommand;
use InfamousQ\FlarumPhorumMigrationTool\Phorum\Connector;

/**
 * Finds Phorum users that share an email address. The users import matches users
 * to Flarum accounts by email, so every Phorum user after the first with the same
 * email would be merged into the first one's account and rename it. Phorum itself
 * doesn't allow shared emails, so this is a fault in the Phorum data to fix there.
 *
 * Users the import skips (inactive with no messages) are left out. Emails are
 * compared case-insensitively, as Flarum's database does.
 */
class DuplicateEmailCheck implements PreflightCheck {

	public function run(Connector $connector) : array {
		/** @var array $user_ids_by_email Lowercased email => Phorum user ids */
		$user_ids_by_email = [];
		foreach ($connector->getUsers() as $p_user_row) {
			if (!AbstractPhorumMigrateCommand::shouldImportPhorumUser($p_user_row)) {
				continue;
			}
			$email = strtolower((string) ($p_user_row['email'] ?? ''));
			$user_ids_by_email[$email][] = (int) $p_user_row['user_id'];
		}

		$problems = [];
		foreach ($user_ids_by_email as $email => $user_ids) {
			if (count($user_ids) < 2) {
				continue;
			}
			$shown_email = '' === $email ? '(empty)' : "'{$email}'";
			$problems[] = 'Phorum users ' . implode(', ', $user_ids) . " share the email address {$shown_email}. "
				. 'Give each of them their own email address in Phorum and run again.';
		}

		return $problems;
	}
}
