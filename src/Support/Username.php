<?php

namespace InfamousQ\FlarumPhorumMigrationTool\Support;

use Illuminate\Support\Str;

/**
 * Turns Phorum display names into usernames that pass Flarum's own UserValidator
 * rules: only letters, digits, "_" and "-", 3 to 30 characters. User::register()
 * and User::rename() don't validate, so anything else would be stored as-is and
 * break profile URLs, mentions and later profile edits.
 */
class Username {

	const MIN_LENGTH = 3;
	const MAX_LENGTH = 30;
	const MIGRATED_SUFFIX = '_migrated';

	/**
	 * "Matti Meikäläinen" becomes "Matti_Meikalainen". A name with nothing usable
	 * in it becomes "user_<id>", and one that is too short gets "_<id>" appended.
	 */
	public static function fromPhorumName(string $display_name, int $phorum_user_id) : string {
		$username = Str::ascii($display_name);
		$username = preg_replace('/[^a-z0-9_-]+/i', '_', $username);
		$username = preg_replace('/_{2,}/', '_', $username);
		$username = trim($username, '_-');
		$username = rtrim(substr($username, 0, self::MAX_LENGTH), '_-');

		if ('' === $username) {
			return "user_{$phorum_user_id}";
		}
		if (strlen($username) < self::MIN_LENGTH) {
			return "{$username}_{$phorum_user_id}";
		}

		return $username;
	}

	/**
	 * Usernames to try in order: the username itself, then its "_migrated" variant,
	 * shortened so that it still fits in MAX_LENGTH.
	 *
	 * @return string[]
	 */
	public static function candidates(string $username) : array {
		$base = substr($username, 0, self::MAX_LENGTH - strlen(self::MIGRATED_SUFFIX));

		return [$username, $base . self::MIGRATED_SUFFIX];
	}
}
