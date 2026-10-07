<?php

namespace InfamousQ\FlarumPhorumMigrationTool\Preflight;

use Flarum\User\User;
use InfamousQ\FlarumPhorumMigrationTool\Console\AbstractPhorumMigrateCommand;
use InfamousQ\FlarumPhorumMigrationTool\Model\PhorumMapping;
use InfamousQ\FlarumPhorumMigrationTool\Phorum\Connector;
use InfamousQ\FlarumPhorumMigrationTool\Support\Username;

/**
 * Finds every Phorum user (and the guest placeholder) for whom the users import
 * would find both its username and the "_migrated" variant already taken, which
 * would otherwise stop the migration at that user.
 *
 * Replays importUsers() without writing anything: users already mapped or skipped
 * there are skipped here too, a user merged by email into an existing account
 * frees that account's old username, and usernames given out earlier in the run
 * count as taken for later users.
 */
class UsernameCheck implements PreflightCheck {

	/** @var array Lowercased username => owner key, or null once a rename freed it */
	protected $claimed = [];

	public function run(Connector $connector) : array {
		$this->claimed = [];
		/** @var string[] $created_by_email Lowercased email => owner key of a user this run creates */
		$created_by_email = [];
		/** @var string[] $current_names Owner key => username it has after this run so far */
		$current_names = [];
		$problems = [];

		foreach ($connector->getUsers() as $p_user_row) {
			$p_user_id = (int) $p_user_row['user_id'];
			if ($this->isMappedToLiveUser($p_user_id) || !AbstractPhorumMigrateCommand::shouldImportPhorumUser($p_user_row)) {
				continue;
			}

			$email = (string) ($p_user_row['email'] ?? '');
			$owner = $created_by_email[strtolower($email)] ?? null;
			if (null === $owner) {
				$existing_user = User::where('email', $email)->first();
				if (null !== $existing_user) {
					$owner = "flarum:{$existing_user->id}";
					$current_names[$owner] = $current_names[$owner] ?? $existing_user->username;
				}
			}

			$desired = Username::fromPhorumName((string) ($p_user_row['display_name'] ?? ''), $p_user_id);
			$username = $this->firstFreeCandidate($desired, $owner ?? "phorum:{$p_user_id}");
			if (null === $username) {
				$problems[] = $this->describeProblem("Phorum user {$p_user_id} (\"{$p_user_row['display_name']}\")", $desired);
				continue;
			}

			if (null === $owner) {
				$owner = "phorum:{$p_user_id}";
				$created_by_email[strtolower($email)] = $owner;
			} else {
				$this->claimed[strtolower($current_names[$owner])] = null;
			}
			$this->claimed[strtolower($username)] = $owner;
			$current_names[$owner] = $username;
		}

		if ($this->needsGuestUser($connector)) {
			$desired = AbstractPhorumMigrateCommand::GUEST_USERNAME;
			if (null === $this->firstFreeCandidate($desired, 'guest')) {
				$problems[] = $this->describeProblem('The placeholder author for Phorum guest messages', $desired);
			}
		}

		return $problems;
	}

	protected function isMappedToLiveUser(int $p_user_id) : bool {
		$user_id = PhorumMapping::getFlarumIdForPhorumId(PhorumMapping::DATA_TYPE_USER, $p_user_id);

		return null !== $user_id && User::where('id', $user_id)->exists();
	}

	protected function needsGuestUser(Connector $connector) : bool {
		return !$this->isMappedToLiveUser(AbstractPhorumMigrateCommand::PHORUM_GUEST_USER_ID)
			&& !User::where('email', AbstractPhorumMigrateCommand::GUEST_EMAIL)->exists()
			&& $connector->hasGuestMessages();
	}

	protected function firstFreeCandidate(string $desired, string $owner) : ?string {
		foreach (Username::candidates($desired) as $candidate) {
			if (!$this->isTaken($candidate, $owner)) {
				return $candidate;
			}
		}

		return null;
	}

	protected function isTaken(string $username, string $owner) : bool {
		$key = strtolower($username);
		if (array_key_exists($key, $this->claimed)) {
			return null !== $this->claimed[$key] && $owner !== $this->claimed[$key];
		}

		$user = User::where('username', $username)->first();

		return null !== $user && $owner !== "flarum:{$user->id}";
	}

	protected function describeProblem(string $who, string $desired) : string {
		[$username, $migrated_username] = Username::candidates($desired);

		return "{$who}: usernames '{$username}' and '{$migrated_username}' are both taken. Rename one of the clashing Flarum or Phorum users and run again.";
	}
}
