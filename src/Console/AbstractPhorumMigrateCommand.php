<?php

namespace InfamousQ\FlarumPhorumMigrationTool\Console;

use InfamousQ\FlarumPhorumMigrationTool\Bbcode\PhorumBbcodeCompatibility;
use InfamousQ\FlarumPhorumMigrationTool\Phorum\Connector;
use InfamousQ\FlarumPhorumMigrationTool\Model\PhorumMapping;
use InfamousQ\FlarumPhorumMigrationTool\Log\ConsoleLogger;
use InfamousQ\FlarumPhorumMigrationTool\Preflight\PreflightCheck;
use InfamousQ\FlarumPhorumMigrationTool\Preflight\UsernameCheck;
use InfamousQ\FlarumPhorumMigrationTool\Support\Username;
use Flarum\Console\AbstractCommand;
use Flarum\Discussion\Discussion;
use Flarum\Group\Group;
use Flarum\Group\Permission;
use Flarum\Post\Post;
use Flarum\Settings\SettingsRepositoryInterface;
use Flarum\Tags\Tag;
use Flarum\User\User;
use InfamousQ\FlarumPhorumMigrationTool\Model\HistoricCommentPost;
use Carbon\Carbon;
use Illuminate\Hashing\BcryptHasher;
use Illuminate\Support\Str;
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerAwareTrait;

/**
 * Shared plumbing for `phorum:migrate` (the full-pipeline command) and the six
 * `phorum:migrate:*` step commands: Phorum DB connection/logger setup, every
 * import* step of the pipeline, and load*Map() helpers that rebuild a step's
 * prerequisite Phorum-id-to-Flarum-model maps from the `phorum_mapping`
 * bookkeeping table. The load*Map() helpers exist because each step command is
 * its own CLI invocation/process - unlike `phorum:migrate`, which can just pass
 * a previous step's return value directly into the next, a standalone step has
 * no in-memory access to an earlier step's output and must reconstruct it from
 * what was persisted.
 */
abstract class AbstractPhorumMigrateCommand extends AbstractCommand implements LoggerAwareInterface {

	use LoggerAwareTrait;

	/** Phorum's PHORUM_USER_ALLOW_* permission bits (include/api/user.php) */
	const PHORUM_ALLOW_READ = 1;
	const PHORUM_ALLOW_REPLY = 2;
	const PHORUM_ALLOW_NEW_TOPIC = 8;
	/** Members can read, reply and start threads: the only forum a Flarum tag can model without being restricted */
	const PHORUM_ALLOW_OPEN = self::PHORUM_ALLOW_READ | self::PHORUM_ALLOW_REPLY | self::PHORUM_ALLOW_NEW_TOPIC;

	/** Phorum's PHORUM_SORT_* values for a thread starting message (include/constants.php). Both become sticky discussions. */
	const PHORUM_SORT_ANNOUNCEMENT = 0;
	const PHORUM_SORT_STICKY = 1;
	const PHORUM_SORT_DEFAULT = 2;

	/** Phorum's PHORUM_USER_ACTIVE (include/api/user.php). Anything else is inactive or pending. */
	const PHORUM_USER_ACTIVE = 1;

	/** flarum/suspend's representation of an indefinite suspension (see its suspensionHelper) */
	const SUSPENDED_INDEFINITELY_UNTIL = '2038-01-01 00:00:00';
	const INACTIVE_SUSPEND_REASON = 'Deactivated in Phorum';

	/** flarum/suspend's notification types (UserSuspendedBlueprint/UserUnsuspendedBlueprint::getType()) */
	const SUSPEND_NOTIFICATION_TYPES = ['userSuspended', 'userUnsuspended'];

	/**
	 * Phorum stores messages posted by guests (and by users deleted since) with
	 * user_id 0. They are attributed to a single placeholder Flarum user, mapped
	 * under this Phorum id like any other migrated user.
	 */
	const PHORUM_GUEST_USER_ID = 0;
	const GUEST_USERNAME = 'Guest';
	/** .invalid is reserved (RFC 2606), so no password reset mail can ever be delivered */
	const GUEST_EMAIL = 'phorum-guest@phorum-migration.invalid';
	const GUEST_SUSPEND_REASON = 'Placeholder author for Phorum guest messages';

	/** @var SettingsRepositoryInterface */
	protected $settings;

	public function __construct(SettingsRepositoryInterface $settings) {
		$this->settings = $settings;
		parent::__construct();
	}

	protected function setUpLogger() {
		$this->setLogger(new ConsoleLogger($this->output));
	}

	/**
	 * @return PreflightCheck[] Checks run before a migration command writes anything
	 */
	protected function preflightChecks() : array {
		return [new UsernameCheck()];
	}

	/**
	 * Run every pre-flight check and print the problems they find.
	 *
	 * @return bool False when any check found a problem, so the migration must not start
	 */
	protected function runPreflightChecks(Connector $connector) : bool {
		$problems = [];
		foreach ($this->preflightChecks() as $check) {
			array_push($problems, ...$check->run($connector));
		}
		if (empty($problems)) {
			return true;
		}

		$this->output->writeln('<error>Pre-flight checks failed, nothing was migrated:</error>');
		foreach ($problems as $problem) {
			$this->output->writeln("  - {$problem}");
		}

		return false;
	}

	protected function buildConnector() : Connector {
		$phorum_db_host = $this->settings->get('infamousq-phorum-migration-tool.phorum_db_host');
		$phorum_db_name = $this->settings->get('infamousq-phorum-migration-tool.phorum_db_name');
		$phorum_db_username = $this->settings->get('infamousq-phorum-migration-tool.phorum_db_username');
		$phorum_db_password = $this->settings->get('infamousq-phorum-migration-tool.phorum_db_password');
		$phorum_db_prefix = $this->settings->get('infamousq-phorum-migration-tool.phorum_db_prefix', '');

		return new Connector(
			$phorum_db_host,
			$phorum_db_name,
			$phorum_db_username,
			$phorum_db_password,
			$phorum_db_prefix
		);
	}

	/**
	 * Rebuild a Phorum-id => Flarum-model map from the phorum_mapping bookkeeping
	 * table. Used by standalone step commands to load a prior step's output.
	 * Mapping rows whose Flarum model no longer exists (e.g. deleted since) are
	 * silently skipped.
	 *
	 * @param int $data_type One of the PhorumMapping::DATA_TYPE_* constants
	 * @param class-string $model_class
	 * @return array Keyed by phorum_id
	 */
	protected function loadModelMap(int $data_type, string $model_class) : array {
		$flarum_ids = PhorumMapping::where('phorum_data_type', $data_type)->pluck('flarum_id', 'phorum_id')->all();

		$models = [];
		// Chunked to stay well below MySQL's limit of 65535 placeholders per statement
		foreach (array_chunk(array_unique(array_filter($flarum_ids)), 10000) as $chunk) {
			foreach ($model_class::whereIn('id', $chunk)->get() as $model) {
				$models[$model->id] = $model;
			}
		}

		$map = [];
		foreach ($flarum_ids as $phorum_id => $flarum_id) {
			if (isset($models[$flarum_id])) {
				$map[$phorum_id] = $models[$flarum_id];
			}
		}

		return $map;
	}

	/** @return Group[] Keyed by Phorum group id */
	protected function loadGroupMap() : array {
		return $this->loadModelMap(PhorumMapping::DATA_TYPE_USER_GROUP, Group::class);
	}

	/** @return User[] Keyed by Phorum user id */
	protected function loadUserMap() : array {
		return $this->loadModelMap(PhorumMapping::DATA_TYPE_USER, User::class);
	}

	/** @return Tag[] Keyed by Phorum forum id */
	protected function loadTagMap() : array {
		return $this->loadModelMap(PhorumMapping::DATA_TYPE_TAG, Tag::class);
	}

	/** @return int[] Discussion id keyed by Phorum thread id */
	protected function loadDiscussionMap() : array {
		return $this->loadLiveIdMap(PhorumMapping::DATA_TYPE_DISCUSSION, 'discussions');
	}

	/**
	 * Phorum id => Flarum id map for one data type, leaving out mapping rows whose
	 * Flarum record no longer exists (e.g. deleted inside Flarum since), so those
	 * Phorum records are treated as not yet migrated.
	 *
	 * @param int $data_type One of the PhorumMapping::DATA_TYPE_* constants
	 * @param string $flarum_table Table the mapped Flarum ids point into
	 * @return int[] Keyed by phorum_id
	 */
	protected function loadLiveIdMap(int $data_type, string $flarum_table) : array {
		return PhorumMapping::query()
			->join($flarum_table, "{$flarum_table}.id", '=', 'phorum_mapping.flarum_id')
			->where('phorum_mapping.phorum_data_type', $data_type)
			->pluck('phorum_mapping.flarum_id', 'phorum_mapping.phorum_id')
			->all();
	}

	protected function importUserGroups(Connector $connector) : array {
		// Import all user groups
		$p_groups = $connector->getUserGroups();
		/** @var Group[] $p_groups_created */
		$p_groups_created = [];
		foreach ($p_groups as $p_group_row) {
			$phorum_user_group_id = $p_group_row['group_id'];
			$user_group_id = PhorumMapping::getFlarumIdForPhorumId(PhorumMapping::DATA_TYPE_USER_GROUP, $phorum_user_group_id);
			$user_group = null !== $user_group_id ? Group::find($user_group_id) : null;
			if (null !== $user_group) {
				// Already mapped from a previous run - reuse as-is. Do not reset the
				// name, since it may have been edited inside Flarum since then.
				$p_groups_created[$phorum_user_group_id] = $user_group;
				continue;
			}

			$user_group = Group::build($p_group_row['name'], $p_group_row['name']);
			$user_group->saveOrFail();
			$this->output->writeln("Phorum user group {$p_group_row['group_id']} - Generated Flarum group {$user_group->id}");
			$user_group->refresh();
			$p_groups_created[$phorum_user_group_id] = $user_group;
			PhorumMapping::setFlarumIdForPhorumId(PhorumMapping::DATA_TYPE_USER_GROUP, $phorum_user_group_id, $user_group->id);
		}

		return $p_groups_created;
	}

	protected function importUsers(Connector $connector) : array {
		// Import all users
		$p_users = $connector->getUsers();
		/** @var User[] $users_created */
		$users_created = [];
		foreach ($p_users as $p_user_row) {
			$phorum_user_id = $p_user_row['user_id'];
			$user_id = PhorumMapping::getFlarumIdForPhorumId(PhorumMapping::DATA_TYPE_USER, $phorum_user_id);
			$mapped_user = null !== $user_id ? User::find($user_id) : null;
			if (null !== $mapped_user) {
				// Already mapped from a previous run - reuse as-is. Do not rename/re-email,
				// since the account may have been edited inside Flarum since then.
				$users_created[$phorum_user_id] = $mapped_user;
				continue;
			}

			if (!self::shouldImportPhorumUser($p_user_row)) {
				$this->output->writeln("Phorum user {$phorum_user_id} - Skipped, inactive with no messages");
				continue;
			}
			$p_is_active = self::isActivePhorumUser($p_user_row);
			$desired_username = Username::fromPhorumName((string) ($p_user_row['display_name'] ?? ''), (int) $phorum_user_id);

			$existing = false;
			// No existing mapped user, see if we have user with same email already
			$user = User::where(['email' => $p_user_row['email']])->first();
			if (null === $user) {
				$username = $this->resolveUsername($desired_username, null);
				// Phorum's password hashes aren't carried over - users regain access via "forgot password"
				$user = $this->registerWithUnusablePassword($username, $p_user_row['email']);
				if ($p_is_active) {
					// Phorum only activates an account once it has passed sign-up
					// verification. Without this Flarum treats the user as a guest
					// (no Member or group permissions) until they confirm again.
					$user->is_email_confirmed = true;
				} else {
					// Inactive in Phorum but has messages: keep the account so the
					// messages keep their author, but block it from doing anything.
					$this->suspendIndefinitely($user, self::INACTIVE_SUSPEND_REASON);
				}
			} else {
				// Merged into an account that already existed in Flarum: its confirmation
				// and suspension state are left untouched. Confirming it here would hand
				// whoever registered it a verified account carrying the Phorum identity.
				$existing = true; // This use previously existed already!
				$username = $this->resolveUsername($desired_username, $user->id);
				$user->rename($username);
				$user->changeEmail($p_user_row['email']);
			}

			$user->saveOrFail();
			$this->output->writeln("Phorum user {$p_user_row['user_id']} - Generated Flarum user {$user->id}");
			$user->refresh();
			$users_created[$phorum_user_id] = $user;
			PhorumMapping::setFlarumIdForPhorumId(PhorumMapping::DATA_TYPE_USER, $phorum_user_id, $user->id, $existing);
		}

		return $users_created;
	}

	/**
	 * User::register(), but with a random password nobody is ever told, so the account
	 * can only be signed in to after a password reset.
	 *
	 * The password is hashed with bcrypt's lowest cost instead of Flarum's default,
	 * which takes ~60x as long and dominated the users step (~60 ms per user). The
	 * cost only slows down guessing of weak, human-chosen passwords; 40 random
	 * characters can't be guessed at any cost. The hash is still an ordinary bcrypt
	 * hash, unique per user, and is replaced at the default cost on a password reset.
	 */
	protected function registerWithUnusablePassword(string $username, string $email) : User {
		// An empty password skips User's hashing mutator, the hash is set right after
		$user = User::register($username, $email, '');
		$user->setRawAttributes(['password' => (new BcryptHasher(['rounds' => 4]))->make(Str::random(40))] + $user->getAttributes());

		return $user;
	}

	/**
	 * Deactivated or never-confirmed accounts that never posted are not imported: in
	 * practice almost always spam sign-ups, and nothing references them. Fails closed
	 * on a missing `active` (treated as inactive), but open on a missing message count
	 * (messages assumed to exist) so no authorship is dropped.
	 */
	public static function shouldImportPhorumUser(array $p_user_row) : bool {
		$p_has_messages = !isset($p_user_row['message_count']) || (int) $p_user_row['message_count'] > 0;

		return self::isActivePhorumUser($p_user_row) || $p_has_messages;
	}

	protected static function isActivePhorumUser(array $p_user_row) : bool {
		return (int) ($p_user_row['active'] ?? 0) === self::PHORUM_USER_ACTIVE;
	}

	/**
	 * Suspend a not-yet-saved user indefinitely. Setting the columns directly sends no
	 * notification (flarum/suspend only notifies when a suspension is changed through
	 * Flarum's user-edit flow). Also opts the user out of suspend/unsuspend emails, so a
	 * later change to this suspension in the admin panel doesn't email anyone. Flarum
	 * checks this preference, not is_email_confirmed.
	 */
	protected function suspendIndefinitely(User $user, string $reason) {
		$user->suspended_until = Carbon::parse(self::SUSPENDED_INDEFINITELY_UNTIL);
		$user->suspend_reason = $reason;
		foreach (self::SUSPEND_NOTIFICATION_TYPES as $type) {
			$user->setPreference(User::getNotificationPreferenceKey($type, 'email'), false);
		}
	}

	/**
	 * Flarum author for a Phorum message: the mapped user, or for Phorum's guest
	 * user id the placeholder guest user (created on first use and added to $users).
	 *
	 * @param mixed $p_user_id
	 * @param User[] $users Keyed by Phorum user id
	 * @return User|null Null when the Phorum user isn't mapped
	 */
	protected function resolveAuthor($p_user_id, array &$users) : ?User {
		if (!is_numeric($p_user_id)) {
			return null;
		}
		if (isset($users[$p_user_id])) {
			return $users[$p_user_id];
		}
		if (self::PHORUM_GUEST_USER_ID !== (int) $p_user_id) {
			return null;
		}

		$users[self::PHORUM_GUEST_USER_ID] = $this->getOrCreateGuestUser();
		return $users[self::PHORUM_GUEST_USER_ID];
	}

	/**
	 * The placeholder author for Phorum guest messages. Nobody can use the account:
	 * its random password is never shown, its email is unconfirmed and on the reserved
	 * .invalid domain (so "forgot password" can't reach anyone), it belongs to no
	 * group, and it is suspended indefinitely.
	 */
	protected function getOrCreateGuestUser() : User {
		$user_id = PhorumMapping::getFlarumIdForPhorumId(PhorumMapping::DATA_TYPE_USER, self::PHORUM_GUEST_USER_ID);
		$user = null !== $user_id ? User::find($user_id) : null;
		if (null === $user) {
			// Mapping row lost but the account from an earlier run survived
			$user = User::where('email', self::GUEST_EMAIL)->first();
		}
		if (null === $user) {
			$user = $this->registerWithUnusablePassword($this->resolveUsername(self::GUEST_USERNAME, null), self::GUEST_EMAIL);
			$this->suspendIndefinitely($user, self::GUEST_SUSPEND_REASON);
			$user->saveOrFail();
			$this->output->writeln("Phorum guest messages - Generated placeholder Flarum user {$user->id}");
			$user->refresh();
		}
		PhorumMapping::setFlarumIdForPhorumId(PhorumMapping::DATA_TYPE_USER, self::PHORUM_GUEST_USER_ID, $user->id);

		return $user;
	}

	/**
	 * Resolve the Flarum username to use for a Phorum user, avoiding
	 * `users_username_unique` violations against an *unrelated* Flarum user
	 * (i.e. one matched by neither Phorum id mapping nor email, so a plain
	 * insert/rename would otherwise collide).
	 *
	 * If the desired username is already taken by a different user, "_migrated"
	 * is appended. If that is taken too, migration cannot proceed for this user;
	 * UsernameCheck reports such users before the migration starts.
	 *
	 * @param string $desired_username A valid Flarum username, see Username::fromPhorumName()
	 * @param int|null $excluding_user_id Id of the Flarum user this Phorum user
	 *   is being merged into/updating, if any - its own current username must
	 *   not count as a collision against itself.
	 * @return string
	 */
	protected function resolveUsername(string $desired_username, ?int $excluding_user_id) : string {
		foreach (Username::candidates($desired_username) as $candidate) {
			if (!$this->usernameTakenByAnotherUser($candidate, $excluding_user_id)) {
				return $candidate;
			}
		}

		[, $migrated_username] = Username::candidates($desired_username);
		throw new \RuntimeException("Cannot migrate Phorum user '{$desired_username}': both '{$desired_username}' and '{$migrated_username}' are already taken by different Flarum users.");
	}

	protected function usernameTakenByAnotherUser(string $username, ?int $excluding_user_id) : bool {
		$query = User::where('username', $username);
		if (null !== $excluding_user_id) {
			$query->where('id', '!=', $excluding_user_id);
		}

		return $query->exists();
	}

	/**
	 * @param Connector $connector
	 * @param Group[] $phorum_user_group_map
	 * @param User[] $phorum_user_map
	 * @return void
	 */
	protected function importUserGroupMapping(Connector $connector, array $phorum_user_group_map, array $phorum_user_map) {
		// Import all user to user group mappings
		$p_user_to_user_group_map = $connector->getUserToUserGroupMap();
		foreach ($p_user_to_user_group_map as $phorum_user_group_map_row) {
			$phorum_user_id = (int) $phorum_user_group_map_row['user_id'];
			$phorum_group_id = (int) $phorum_user_group_map_row['group_id'];
			$status = (int) $phorum_user_group_map_row['status'];

			/*
			 Status can be following:
			 	-1 = Suspended
				 0 = unapproved
				 1 = approved
				 2 = moderator
			*/
			if ($status < 1 || $status > 2) {
				// Status is not of our concern, skip connecting user with user group
				continue;
			}


			$flarum_user = $phorum_user_map[$phorum_user_id] ?? null;
			if (null === $flarum_user) {
				// User not found
				$this->output->writeln("Mapping - Unknown user id {$phorum_user_id}");
				continue;
			}
			$flarum_group = $phorum_user_group_map[$phorum_group_id] ?? null;
			if (null === $flarum_group) {
				// User group not found
				$this->output->writeln("Mapping - Unknown user group id {$phorum_group_id}");
				continue;
			}

			if (!$flarum_group->users()->find($flarum_user->id)) {
				$flarum_group->users()->save($flarum_user);
			}
		}
	}

	/**
	 * Phorum forums become tags. Only a forum guests can read and members can read,
	 * reply to and start threads in becomes an unrestricted tag. Any other forum
	 * becomes a restricted tag, since Flarum can only limit replying or starting
	 * discussions per tag on a restricted one: e.g. a read-only announcements forum,
	 * or one guests could not read (no PHORUM_USER_ALLOW_READ in pub_perms). See
	 * grantRestrictedTagPermissions() for what each group may do there.
	 * Only applied when the tag is first created; an already-mapped tag's
	 * restriction/permissions are left as-is, as they may have been edited in Flarum.
	 */
	protected function importPhorumForumsAsTags(Connector $connector) : array {
		$p_forums = $connector->getForums();
		$p_forum_group_perms = $this->loadForumGroupPermissions($connector);
		$tags = [];
		foreach ($p_forums as $p_forum) {
			$p_forum_id = (int) ($p_forum['forum_id'] ?? 0);
			$p_forum_name = $p_forum['name'] ?? '';
			$p_forum_description = $p_forum['description'] ?? '';
			$p_forum_position = $p_forum['display_order'] ?? null;
			// Fail closed: a forum with unknown permissions is treated as non-public.
			$p_forum_is_public = ((int) ($p_forum['pub_perms'] ?? 0) & self::PHORUM_ALLOW_READ) !== 0;
			$p_reg_perms = (int) ($p_forum['reg_perms'] ?? 0);
			$p_forum_is_open = $p_forum_is_public && ($p_reg_perms & self::PHORUM_ALLOW_OPEN) === self::PHORUM_ALLOW_OPEN;
			$tag_id = PhorumMapping::getFlarumIdForPhorumId(PhorumMapping::DATA_TYPE_TAG, $p_forum_id);
			$tag = null !== $tag_id ? Tag::find($tag_id) : null;
			$existing = null !== $tag;
			if (null === $tag) {
				$tag = Tag::build($p_forum_name, $this->resolveTagSlug($p_forum_name, $p_forum_id), $p_forum_description, '#888', null, false);
				$tag->position = $p_forum_position;
				$tag->is_restricted = !$p_forum_is_open;
				$tag->save();
				$tag->refresh();
				if (!$p_forum_is_open) {
					$this->grantRestrictedTagPermissions(
						$tag,
						$p_forum_is_public,
						$p_reg_perms,
						$p_forum_group_perms[$p_forum_id] ?? []
					);
				}
			}
			PhorumMapping::setFlarumIdForPhorumId(PhorumMapping::DATA_TYPE_TAG, $p_forum_id, $tag->id, $existing);
			$tags[$p_forum_id] = $tag;
		}
		return $tags;
	}

	/**
	 * URL-safe, unique tag slug for a Phorum forum name. Falls back to "forum-<id>"
	 * when the name has nothing sluggable in it, and appends "-2", "-3", ... when the
	 * slug is already taken (e.g. two Phorum forums with the same name), since
	 * tags.slug is unique.
	 */
	protected function resolveTagSlug(string $p_forum_name, int $p_forum_id) : string {
		$base_slug = Str::slug($p_forum_name, '-', $this->settings->get('default_locale', 'en'));
		if ('' === $base_slug) {
			$base_slug = "forum-{$p_forum_id}";
		}

		$slug = $base_slug;
		for ($suffix = 2; Tag::where('slug', $slug)->exists(); $suffix++) {
			$slug = "{$base_slug}-{$suffix}";
		}

		return $slug;
	}

	/**
	 * Phorum's per-group forum permissions, translated to Flarum group ids via the
	 * groups step's mapping. Rows for unmapped groups are skipped.
	 *
	 * @return array Phorum forum id => [Flarum group id => Phorum permission bitmask]
	 */
	protected function loadForumGroupPermissions(Connector $connector) : array {
		$group_map = $this->loadGroupMap();
		$perms = [];
		foreach ($connector->getForumGroupPermissions() as $row) {
			$group = $group_map[$row['group_id']] ?? null;
			if (null === $group) {
				continue;
			}
			$perms[(int) $row['forum_id']][$group->id] = (int) $row['permission'];
		}

		return $perms;
	}

	/**
	 * Grant tag-scoped permissions on a restricted tag: viewing to Guests if guests
	 * could read the forum, to Members per the forum's reg_perms, and to each mapped
	 * group per its forum_group_xref bitmask. Flarum gives Guests' permissions to
	 * everyone, so a public forum stays readable by all. Guests never get posting
	 * permissions, Flarum doesn't support guest posting.
	 * Phorum's moderation bits are not translated - grant those by hand in Flarum.
	 *
	 * @param Tag $tag
	 * @param bool $guests_can_read Whether the forum's pub_perms allowed reading
	 * @param int $reg_perms Phorum bitmask for registered users
	 * @param array $group_perms Flarum group id => Phorum bitmask
	 */
	protected function grantRestrictedTagPermissions(Tag $tag, bool $guests_can_read, int $reg_perms, array $group_perms) {
		$group_perms[Group::MEMBER_ID] = ($group_perms[Group::MEMBER_ID] ?? 0) | $reg_perms;

		$rows = [];
		if ($guests_can_read) {
			$rows[] = ['group_id' => Group::GUEST_ID, 'permission' => "tag{$tag->id}.viewForum"];
		}
		foreach ($group_perms as $group_id => $phorum_perms) {
			if (!($phorum_perms & self::PHORUM_ALLOW_READ)) {
				continue;
			}
			$abilities = ['viewForum'];
			if ($phorum_perms & self::PHORUM_ALLOW_REPLY) {
				array_push($abilities, 'discussion.reply', 'discussion.replyWithoutApproval', 'discussion.likePosts');
			}
			if ($phorum_perms & self::PHORUM_ALLOW_NEW_TOPIC) {
				array_push($abilities, 'startDiscussion', 'discussion.startWithoutApproval');
			}
			foreach ($abilities as $ability) {
				$rows[] = ['group_id' => $group_id, 'permission' => "tag{$tag->id}.{$ability}"];
			}
		}

		if (!empty($rows)) {
			Permission::query()->insertOrIgnore($rows);
		}
	}

	/**
	 * Find thread starting messages from Phorum and create a Discussion for each.
	 *
	 * The Phorum-id-to-Flarum-id mapping is preloaded once instead of queried per
	 * thread, and new discussions/their tag pivot rows/their mapping rows are
	 * collected while looping and bulk-inserted afterwards, instead of each thread
	 * doing its own insert+refresh+pivot-save+mapping-write round trips.
	 *
	 * @param Connector $connector
	 * @param User[] $users . Key is Phorum user id. The guest placeholder is added on first use.
	 * @param Tag[] $tags . Key is Phorum forum id
	 * @return int[] Discussion id keyed by Phorum thread id
	 */
	protected function importPhorumMessagesAsDiscussions(Connector $connector, array &$users, array $tags) : array {
		// First message is thread starter in Flarum
		$p_thread_starting_messages = $connector->getThreadStartingMessages();
		$discussion_mapping = $this->loadLiveIdMap(PhorumMapping::DATA_TYPE_DISCUSSION, 'discussions');
		$default_locale = $this->settings->get('default_locale', 'en');

		$discussions = [];
		/** @var array $pending_discussions List of ['thread_id' => , 'tag_id' => , 'row' => []] awaiting bulk insert */
		$pending_discussions = [];
		/** @var User[] $new_discussion_authors Keyed by Flarum user id */
		$new_discussion_authors = [];
		foreach ($p_thread_starting_messages as $p_msg) {
			$p_forum_id = (int) ($p_msg['forum_id'] ?? 0);
			$p_thread_id = $p_msg['thread'] ?? null;
			$p_user_id = $p_msg['user_id'] ?? null;
			$p_subject = (string) ($p_msg['subject'] ?? '');
			/**
			 * Phorum's message's status can be either..
			 * 2 = PHORUM_STATUS_APPROVED
			 * -1 = PHORUM_STATUS_HOLD
			 * -2 = PHORUM_STATUS_HIDDEN
			 */
			$p_status_int = (int) ($p_msg['status'] ?? 2);
			// Phorum keeps announcements and sticky threads above the rest, Flarum only has sticky
			$p_sort = (int) ($p_msg['sort'] ?? self::PHORUM_SORT_DEFAULT);
			$p_message_is_sticky = in_array($p_sort, [self::PHORUM_SORT_ANNOUNCEMENT, self::PHORUM_SORT_STICKY], true);
			// Phorum thread is locked if it's starting message is marked to have 'closed' attribute
			$p_message_is_locked = $p_msg['closed'] == 1;
			$author_user = $this->resolveAuthor($p_user_id, $users);
			if (null === $author_user) {
				$this->logger->critical('Unknown Phorum user id', ['user_id' => $p_user_id]);
				continue;
			}
			$tag = $tags[$p_forum_id] ?? null;
			if (null === $tag) {
				$this->logger->critical('Unknown Phorum forum id', ['forum_id' => $p_forum_id]);
				continue;
			}

			$discussion_id = $discussion_mapping[$p_thread_id] ?? null;
			if (null !== $discussion_id) {
				// Found, no need to touch anything
				$this->output->writeln("Discussion - found old discussion");
				$discussions[$p_thread_id] = $discussion_id;
				continue;
			}

			// Not found, queue for creation. Mirrors what Discussion::rename() /
			// ->hide() would set, since bulk-inserted rows skip those mutators.
			// Note: discussion creation timestamp is left unset here, same as
			// before - it is set for real when the thread's first post is imported.
			$row = [
				'title' => $p_subject,
				'slug' => Str::slug($p_subject, '-', $default_locale),
				'is_sticky' => $p_message_is_sticky,
				'is_locked' => $p_message_is_locked,
				'user_id' => $author_user->id,
				// If Phorum message is not approved, set the discussion as hidden.
				// Every row must carry the same columns for the bulk insert below.
				'hidden_at' => $p_status_int < 2 ? Carbon::now() : null,
			];

			$pending_discussions[] = [
				'thread_id' => $p_thread_id,
				'tag_id' => $tag->id,
				'row' => $row,
			];
			$new_discussion_authors[$author_user->id] = $author_user;
		}

		$connection = PhorumMapping::query()->getConnection();
		foreach (array_chunk($pending_discussions, 500) as $chunk) {
			// One transaction per chunk, so discussions are never left without their
			// mapping rows (which a re-run would then create a second time)
			$connection->transaction(function () use ($chunk, &$discussions) {
				$this->insertPendingDiscussions($chunk, $discussions);
			});
		}

		// Bulk inserts bypass the events that keep users.discussion_count in sync
		foreach ($new_discussion_authors as $author_user) {
			$author_user->refreshDiscussionCount()->save();
		}
		$this->refreshTagCounters();

		return $discussions;
	}

	/**
	 * @param array $chunk List of ['thread_id' => , 'tag_id' => , 'row' => []]
	 * @param int[] $discussions Discussion id keyed by Phorum thread id, new ones are added
	 */
	protected function insertPendingDiscussions(array $chunk, array &$discussions) {
		$first_id = $this->bulkInsertAndGetFirstId('discussions', array_column($chunk, 'row'));

		$pivot_rows = [];
		$mapping_rows = [];
		foreach ($chunk as $i => $pending) {
			$discussion_id = $first_id + $i;
			$discussions[$pending['thread_id']] = $discussion_id;
			$pivot_rows[] = ['discussion_id' => $discussion_id, 'tag_id' => $pending['tag_id']];
			$mapping_rows[] = [
				'phorum_data_type' => PhorumMapping::DATA_TYPE_DISCUSSION,
				'phorum_id' => (int) $pending['thread_id'],
				'flarum_id' => $discussion_id,
				'existing' => false,
			];
			$this->output->writeln("Discussion - created new discussion");
		}

		PhorumMapping::query()->getConnection()->table('discussion_tag')->insert($pivot_rows);
		// Drop stale rows left by threads whose discussion was deleted in Flarum
		PhorumMapping::where('phorum_data_type', PhorumMapping::DATA_TYPE_DISCUSSION)
			->whereIn('phorum_id', array_column($mapping_rows, 'phorum_id'))
			->delete();
		PhorumMapping::insert($mapping_rows);
	}

	/**
	 * Bulk-insert rows sharing the same columns in a single statement and return the
	 * auto-increment id of the first inserted row. For a single multi-row INSERT,
	 * MySQL/MariaDB assign consecutive auto-increment ids in the listed row order, so
	 * callers can derive every other row's id as $firstId + its offset in $rows.
	 * Relies on nothing else writing to the table concurrently, which holds true for
	 * this single-threaded, one-off migration command.
	 *
	 * @param string $table Table name without the connection's configured prefix
	 * @param array $rows
	 * @return int
	 */
	protected function bulkInsertAndGetFirstId(string $table, array $rows) : int {
		$connection = PhorumMapping::query()->getConnection();
		$connection->table($table)->insert($rows);

		return (int) $connection->getPdo()->lastInsertId();
	}

	/**
	 * Import every reply for every thread. Messages are streamed from Phorum in
	 * a single query and handled one thread at a time, so only one thread's
	 * messages are held in memory. The Phorum-id-to-Flarum-id mapping table is
	 * preloaded once and batch-inserted per thread, instead of once per message,
	 * to avoid tens of thousands of per-post round trips. Threads with nothing
	 * left to do are skipped, which keeps re-runs cheap.
	 *
	 * @param Connector $connector
	 * @param array $p_discussions Discussion id keyed by Phorum thread id
	 * @param User[] $users
	 */
	protected function importPhorumMessages(Connector $connector, array $p_discussions, array $users) {
		$message_id_to_post_id = $this->loadLiveIdMap(PhorumMapping::DATA_TYPE_MESSAGE, 'posts');
		$hidden_post_ids = array_flip(Post::query()->whereNotNull('hidden_at')->pluck('id')->all());

		foreach ($this->groupMessagesByThread($connector->getAllThreadMessages()) as $p_thread_id => $p_thread_messages) {
			$discussion_id = $p_discussions[$p_thread_id] ?? null;
			if (null === $discussion_id || !$this->threadNeedsImport($p_thread_messages, $message_id_to_post_id, $hidden_post_ids)) {
				continue;
			}
			$this->importPhorumMessageForThread(
				$p_thread_id,
				$discussion_id,
				$users,
				$p_thread_messages,
				$message_id_to_post_id
			);
		}

		$this->refreshUserPostCounts();
		// The last posted discussion depends on the posts imported above
		$this->refreshTagCounters();
	}

	/**
	 * Bulk-inserted discussions and directly saved posts also bypass the events
	 * flarum/tags uses to keep tags.discussion_count and the last posted discussion
	 * in sync. Recompute both for every migrated tag, counting what flarum/tags
	 * counts: discussions that are neither private nor hidden.
	 */
	protected function refreshTagCounters() {
		foreach ($this->loadTagMap() as $tag) {
			$tag->discussion_count = $tag->discussions()
				->where('is_private', false)
				->whereNull('hidden_at')
				->count();
			$tag->refreshLastPostedDiscussion();
			$tag->save();
		}
	}

	/**
	 * Group messages ordered by thread (see Connector::getAllThreadMessages()) into
	 * one batch per thread, reading no further ahead than the thread at hand.
	 *
	 * @param iterable $p_messages
	 * @return \Generator Phorum thread id => that thread's messages, in order
	 */
	protected function groupMessagesByThread(iterable $p_messages) : \Generator {
		$p_thread_id = null;
		$p_thread_messages = [];
		foreach ($p_messages as $p_msg) {
			if (!empty($p_thread_messages) && $p_msg['thread'] != $p_thread_id) {
				yield $p_thread_id => $p_thread_messages;
				$p_thread_messages = [];
			}
			$p_thread_id = $p_msg['thread'];
			$p_thread_messages[] = $p_msg;
		}
		if (!empty($p_thread_messages)) {
			yield $p_thread_id => $p_thread_messages;
		}
	}

	/**
	 * Whether importPhorumMessageForThread() would change anything for this thread:
	 * a message has no post yet, or a message that isn't approved in Phorum has a
	 * post that isn't hidden yet.
	 *
	 * @param array $p_thread_messages
	 * @param int[] $message_id_to_post_id Phorum message id => Flarum post id
	 * @param array $hidden_post_ids Flarum post id => anything, for every hidden post
	 */
	protected function threadNeedsImport(array $p_thread_messages, array $message_id_to_post_id, array $hidden_post_ids) : bool {
		foreach ($p_thread_messages as $p_msg) {
			$post_id = $message_id_to_post_id[$p_msg['message_id'] ?? null] ?? null;
			if (null === $post_id) {
				return true;
			}
			if ((int) ($p_msg['status'] ?? 2) < 2 && !isset($hidden_post_ids[$post_id])) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Bulk ->save() inserts of posts/discussions bypass Flarum's command bus,
	 * so the Posted/Started events that normally keep users.discussion_count
	 * and users.comment_count in sync never fire. Recompute both counters for
	 * every migrated user using core's own definition of them.
	 *
	 * Also moves each user's join date back to their first post, since a user
	 * created by the migration otherwise "joined" years after writing their posts.
	 * A join date already earlier than the first post is kept.
	 *
	 * Done as two set-based UPDATEs over every user in phorum_mapping (the guest
	 * placeholder included) rather than per user, so it takes the same few queries
	 * however many users were migrated, and needs no list of ids that could hit
	 * MySQL's limit of 65535 placeholders per statement.
	 */
	protected function refreshUserPostCounts() {
		$connection = PhorumMapping::query()->getConnection();
		$prefix = $connection->getTablePrefix();
		$migrated_user_ids = "SELECT DISTINCT flarum_id FROM {$prefix}phorum_mapping WHERE phorum_data_type = ?";

		// Same counter definitions as User::refreshCommentCount() / refreshDiscussionCount()
		$connection->update(
			"UPDATE {$prefix}users AS u"
			. " JOIN ({$migrated_user_ids}) AS m ON m.flarum_id = u.id"
			. " SET u.comment_count = (SELECT COUNT(*) FROM {$prefix}posts AS p WHERE p.user_id = u.id AND p.type = 'comment' AND p.is_private = 0),"
			. " u.discussion_count = (SELECT COUNT(*) FROM {$prefix}discussions AS d WHERE d.user_id = u.id AND d.is_private = 0)",
			[PhorumMapping::DATA_TYPE_USER]
		);

		$connection->update(
			"UPDATE {$prefix}users AS u"
			. " JOIN (SELECT p.user_id, MIN(p.created_at) AS first_posted_at FROM {$prefix}posts AS p"
			. " JOIN ({$migrated_user_ids}) AS m ON m.flarum_id = p.user_id GROUP BY p.user_id) AS fp ON fp.user_id = u.id"
			. " SET u.joined_at = fp.first_posted_at"
			. " WHERE u.joined_at IS NULL OR fp.first_posted_at < u.joined_at",
			[PhorumMapping::DATA_TYPE_USER]
		);
	}

	/**
	 * Import one thread's messages in a single transaction, together with their
	 * phorum_mapping rows, so a failure part way through a run never leaves posts
	 * without a mapping row (which a re-run would then import a second time).
	 *
	 * @param int $phorum_thread_id
	 * @param int $discussion_id
	 * @param User[] $users Keyed by Phorum user id. The guest placeholder is added on first use.
	 * @param array $p_thread_messages Messages belonging to this thread, in order
	 * @param array $message_id_to_post_id Phorum message id => Flarum post id, shared across threads
	 * @return Post[]
	 */
	protected function importPhorumMessageForThread(int $phorum_thread_id, $discussion_id, array &$users, array $p_thread_messages, array &$message_id_to_post_id) {
		$this->output->writeln("Reading messages for Phorum thread {$phorum_thread_id}");
		$discussion = Discussion::find($discussion_id);
		if (null === $discussion) {
			$this->logger->critical('Mapped Discussion not found', ['thread id' => $phorum_thread_id, 'discussion id' => $discussion_id]);
			return [];
		}

		return PhorumMapping::query()->getConnection()->transaction(function () use ($discussion, &$users, $p_thread_messages, &$message_id_to_post_id) {
			/** @var Post[] $posts */
			$posts = [];
			/** @var array $pending_posts List of ['post' => unsaved HistoricCommentPost, 'message_id' => ] awaiting bulk insert */
			$pending_posts = [];
			foreach ($p_thread_messages as $p_msg) {
				$p_message_id = $p_msg['message_id'] ?? null;
				$p_user_id = $p_msg['user_id'] ?? null;
				$p_body = PhorumBbcodeCompatibility::transform($p_msg['body'] ?? '');
				$p_created = $p_msg['datestamp'] ?? null;
				// Anything not PHORUM_STATUS_APPROVED (2) - i.e. on hold (-1) or hidden by a
				// moderator (-2) - must not become publicly visible in Flarum. Same rule as
				// thread starting messages in importPhorumMessagesAsDiscussions().
				// Note: Phorum's `closed` column means the thread is locked, not hidden.
				$p_is_hidden = (int) ($p_msg['status'] ?? 2) < 2;

				$author_user = $this->resolveAuthor($p_user_id, $users);
				if (null === $author_user) {
					$this->logger->critical('Unknown Phorum user id', ['user_id' => $p_user_id]);
					continue;
				}

				$post_id = $message_id_to_post_id[$p_message_id] ?? null;
				/** @var \Flarum\Post\CommentPost $post */
				if (null === $post_id) {
					// Only built here, inserted together with the rest of the thread's new posts below
					$post = HistoricCommentPost::replyAtTime($discussion->id, $p_body, $author_user->id, '127.0.0.1', $p_created, $author_user);
					if ($p_is_hidden) {
						$post->hide();
					}
					$pending_posts[] = ['post' => $post, 'message_id' => (int) $p_message_id];
				} else {
					$post = $discussion->posts->find($post_id);
					if (null === $post) {
						// Post found but it is not in expected Discussion. Log error and skip
						$this->logger->critical('Post linked to wrong Discussion', ['post id' => $post_id, 'discussion id' => $discussion->id]);
						continue;
					}
					if ($p_is_hidden) {
						$post
							->hide()
							->save();
					}
				}

				$posts[] = $post;
			}

			$this->insertPendingPosts($discussion->id, $pending_posts, $message_id_to_post_id);

			if (!empty($posts)) {
				$discussion->setFirstPost(reset($posts));
				// Core's own counter definitions, which leave out hidden posts
				$discussion->refreshLastPost();
				if (null === $discussion->last_post_id) {
					// Every post is hidden. Core keeps the last post it had in that case,
					// so point it at the newest post rather than leaving it empty.
					$discussion->setLastPost(end($posts));
				}
				$discussion->refreshCommentCount();
				$discussion->refreshParticipantCount();
				$discussion->save();
			}

			return $posts;
		});
	}

	/**
	 * Bulk-insert one thread's new posts, instead of saving each through Eloquent.
	 * Saving a post runs Post's `created` hook, which reloads the post, its author and
	 * its discussion: three extra queries per post that made up most of this step's
	 * time. Like the bulk-inserted discussions, this bypasses Post's model events;
	 * Flarum's Posted event already wasn't dispatched for migrated posts.
	 *
	 * Does what that `creating` hook would: numbers the posts after the discussion's
	 * current last post. Like bulkInsertAndGetFirstId(), this relies on nothing else
	 * posting to the discussion meanwhile; posts' unique (discussion_id, number) index
	 * makes the transaction fail rather than misnumber if something does.
	 *
	 * @param int $discussion_id
	 * @param array $pending_posts List of ['post' => unsaved HistoricCommentPost, 'message_id' => ], in order.
	 *   Each post gets its id and number, and is marked as existing.
	 * @param int[] $message_id_to_post_id Phorum message id => Flarum post id, new ones are added
	 */
	protected function insertPendingPosts(int $discussion_id, array $pending_posts, array &$message_id_to_post_id) {
		if (empty($pending_posts)) {
			return;
		}

		$connection = PhorumMapping::query()->getConnection();
		$number = (int) $connection->table('posts')->where('discussion_id', $discussion_id)->max('number');
		// Chunked so a long thread's bodies don't make one statement exceed max_allowed_packet
		foreach (array_chunk($pending_posts, 200) as $chunk) {
			$rows = [];
			foreach ($chunk as $pending) {
				/** @var HistoricCommentPost $post */
				$post = $pending['post'];
				$post->number = ++$number;
				$attributes = $post->getAttributes();
				// Every row must carry the same columns for the bulk insert
				$rows[] = [
					'discussion_id' => $attributes['discussion_id'],
					'number' => $attributes['number'],
					'created_at' => $attributes['created_at'],
					'user_id' => $attributes['user_id'],
					'type' => $attributes['type'],
					'content' => $attributes['content'],
					'ip_address' => $attributes['ip_address'],
					'hidden_at' => $attributes['hidden_at'] ?? null,
					'hidden_user_id' => $attributes['hidden_user_id'] ?? null,
				];
			}

			$first_id = $this->bulkInsertAndGetFirstId('posts', $rows);
			$mapping_rows = [];
			foreach ($chunk as $i => $pending) {
				$post = $pending['post'];
				$post->id = $first_id + $i;
				$post->exists = true;
				$post->syncOriginal();
				$message_id_to_post_id[$pending['message_id']] = $post->id;
				$mapping_rows[] = [
					'phorum_data_type' => PhorumMapping::DATA_TYPE_MESSAGE,
					'phorum_id' => $pending['message_id'],
					'flarum_id' => $post->id,
					'existing' => false,
				];
			}

			// Drop stale rows left by messages whose post was deleted in Flarum
			PhorumMapping::where('phorum_data_type', PhorumMapping::DATA_TYPE_MESSAGE)
				->whereIn('phorum_id', array_column($mapping_rows, 'phorum_id'))
				->delete();
			PhorumMapping::insert($mapping_rows);
		}
	}
}
