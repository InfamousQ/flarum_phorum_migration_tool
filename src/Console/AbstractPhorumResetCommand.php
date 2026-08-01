<?php

namespace InfamousQ\FlarumPhorumMigrationTool\Console;

use Flarum\Console\AbstractCommand;
use Flarum\Discussion\Discussion;
use Flarum\Group\Group;
use Flarum\Post\Post;
use Flarum\Tags\Tag;
use Flarum\User\User;
use Illuminate\Database\ConnectionInterface;
use InfamousQ\FlarumPhorumMigrationTool\Model\PhorumMapping;
use Symfony\Component\Console\Helper\QuestionHelper;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Question\ConfirmationQuestion;

/**
 * Shared plumbing for `phorum:reset` (the full-pipeline reset) and the six
 * `phorum:reset:*` step commands: the `--force` option, the "are you sure?"
 * confirmation prompt, and every delete* step of the pipeline.
 *
 * Each delete* method is self-contained - it deletes the underlying Flarum
 * records for its own type AND the phorum_mapping rows that describe them,
 * rather than relying on a single blanket wipe of the whole bookkeeping table
 * at the end of a run (which only made sense when reset was all-or-nothing).
 * deleteDiscussions() additionally cleans up the DATA_TYPE_MESSAGE mapping
 * rows for any posts it cascades away, since those belong to a different type
 * than the one it's nominally responsible for.
 *
 * There is deliberately no dedicated delete step for the user-to-group
 * *assignment* migration step (phorum:migrate:user-groups) - unlike every
 * other step, it doesn't create its own trackable row (no phorum_mapping
 * data type for "this user was added to this group"), so detachUserGroupAssignments()
 * takes the best-effort approach of removing every group_user row between a
 * migrated user and a migrated group. This cannot distinguish a membership the
 * migration added from one added by hand afterward between two migrated
 * entities - callers must warn about that before invoking it.
 */
abstract class AbstractPhorumResetCommand extends AbstractCommand {

	/** @var ConnectionInterface */
	protected $db;

	public function __construct(ConnectionInterface $db) {
		$this->db = $db;
		parent::__construct();
	}

	protected function addForceOption() {
		$this->addOption(
			'force',
			'f',
			InputOption::VALUE_NONE,
			'Skip the "are you sure?" confirmation prompt'
		);
	}

	protected function mappingCountForType(int $data_type) : int {
		return PhorumMapping::where('phorum_data_type', $data_type)->count();
	}

	protected function confirm(string $warning) : bool {
		/** @var QuestionHelper $questionHelper */
		$questionHelper = $this->getHelperSet()->get('question');
		$question = new ConfirmationQuestion($warning." Are you sure? [y/N] ", false);

		return (bool) $questionHelper->ask($this->input, $this->output, $question);
	}

	/**
	 * Delete posts (Phorum messages) individually via the Eloquent model (not a bulk query
	 * delete) so Post's own model events fire (notification cleanup etc.), same as how
	 * PhorumMigrateCommand creates them one at a time.
	 */
	protected function deletePosts(array &$counts) {
		PhorumMapping::where('phorum_data_type', PhorumMapping::DATA_TYPE_MESSAGE)
			->get()
			->each(function (PhorumMapping $mapping) use (&$counts) {
				$post = Post::find($mapping->flarum_id);
				if (null !== $post) {
					$post->delete();
					$counts['posts']++;
				}
				$mapping->delete();
			});
	}

	/**
	 * Delete discussions (Phorum threads). Deleting the Discussion model row cascades to any
	 * remaining posts at the DB level (posts.discussion_id has ON DELETE CASCADE), but we
	 * also explicitly delete any post still attached first (belt-and-suspenders in case a
	 * post is missing its own phorum_mapping row for some reason) so Post's model events
	 * still get a chance to fire - and so their own DATA_TYPE_MESSAGE mapping rows (which
	 * belong to a different step than this one) get cleaned up too, in case this method is
	 * invoked standalone (phorum:reset:discussions) without deletePosts() having run first.
	 */
	protected function deleteDiscussions(array &$counts) {
		PhorumMapping::where('phorum_data_type', PhorumMapping::DATA_TYPE_DISCUSSION)
			->get()
			->each(function (PhorumMapping $mapping) use (&$counts) {
				$discussion = Discussion::find($mapping->flarum_id);
				if (null === $discussion) {
					$mapping->delete();
					return;
				}

				$postIds = Post::where('discussion_id', $discussion->id)->pluck('id')->all();
				Post::where('discussion_id', $discussion->id)->get()->each(function (Post $post) use (&$counts) {
					$post->delete();
					$counts['posts']++;
				});
				if (!empty($postIds)) {
					PhorumMapping::where('phorum_data_type', PhorumMapping::DATA_TYPE_MESSAGE)
						->whereIn('flarum_id', $postIds)
						->delete();
				}

				$discussion->delete();
				$counts['discussions']++;
				$mapping->delete();
			});
	}

	/**
	 * Delete tags (Phorum forums). discussion_tag rows are cleaned up via DB-level cascade -
	 * any discussion that only had this tag becomes untagged, not deleted.
	 */
	protected function deleteTags(array &$counts) {
		PhorumMapping::where('phorum_data_type', PhorumMapping::DATA_TYPE_TAG)
			->get()
			->each(function (PhorumMapping $mapping) use (&$counts) {
				$tag = Tag::find($mapping->flarum_id);
				if (null !== $tag) {
					$tag->delete();
					$counts['tags']++;
				}
				$mapping->delete();
			});
	}

	/**
	 * Delete groups (Phorum user groups). group_user/group_permission rows are cleaned up via
	 * DB-level cascade - this removes membership for every user in the group, migrated or not,
	 * since deleting the group itself is blunt by nature. Skips Flarum's own built-in groups
	 * (Administrator/Guest/Member/Moderator) defensively - the migration tool always creates
	 * fresh groups via Group::build() so a mapping should never legitimately point at one of
	 * these, but this guards against a corrupted mapping table taking down a built-in group.
	 */
	protected function deleteGroups(array &$counts) {
		$builtInGroupIds = [
			Group::ADMINISTRATOR_ID,
			Group::GUEST_ID,
			Group::MEMBER_ID,
			Group::MODERATOR_ID,
		];

		PhorumMapping::where('phorum_data_type', PhorumMapping::DATA_TYPE_USER_GROUP)
			->get()
			->each(function (PhorumMapping $mapping) use (&$counts, $builtInGroupIds) {
				if (!in_array((int) $mapping->flarum_id, $builtInGroupIds, true)) {
					$group = Group::find($mapping->flarum_id);
					if (null !== $group) {
						$group->delete();
						$counts['groups']++;
					}
				}
				$mapping->delete();
			});
	}

	/**
	 * Delete users, but ONLY the ones the migration actually created (phorum_mapping.existing
	 * === false). Rows where `existing` is true were matched to a pre-existing Flarum account
	 * by email in importUsers() - that account existed before the migration ran and must not be
	 * deleted here, even though the migration may have renamed it / changed its email. Flarum
	 * core itself additionally refuses to delete user id 1 (the root admin) as a last-resort
	 * safety net.
	 *
	 * Deleting a user does NOT delete their posts/discussions - posts.user_id and
	 * discussions.user_id are ON DELETE SET NULL, so any surviving content they authored is
	 * silently anonymized (shows as having no author) rather than removed. Callers should warn
	 * about this before invoking this method standalone.
	 */
	protected function deleteUsers(array &$counts) {
		PhorumMapping::where('phorum_data_type', PhorumMapping::DATA_TYPE_USER)
			->get()
			->each(function (PhorumMapping $mapping) use (&$counts) {
				if ($mapping->existing) {
					$counts['users_skipped_existing']++;
				} else {
					$user = User::find($mapping->flarum_id);
					if (null !== $user) {
						$user->delete();
						$counts['users']++;
					}
				}
				$mapping->delete();
			});
	}

	/**
	 * Best-effort undo of phorum:migrate:user-groups: removes every group_user row between a
	 * migrated user and a migrated group. There is no dedicated phorum_mapping data type for
	 * "this user was added to this group" (importUserGroupMapping() only ever adds a missing
	 * membership, it never creates its own trackable row), so this cannot distinguish a
	 * membership the migration added from one added by hand afterward between two otherwise-
	 * migrated entities - it removes all of them indiscriminately. Groups/users themselves are
	 * left untouched.
	 */
	protected function detachUserGroupAssignments(array &$counts) {
		$userIds = PhorumMapping::where('phorum_data_type', PhorumMapping::DATA_TYPE_USER)->pluck('flarum_id')->all();
		$groupIds = PhorumMapping::where('phorum_data_type', PhorumMapping::DATA_TYPE_USER_GROUP)->pluck('flarum_id')->all();

		if (empty($userIds) || empty($groupIds)) {
			return;
		}

		$counts['user_group_memberships'] = $this->db->table('group_user')
			->whereIn('user_id', $userIds)
			->whereIn('group_id', $groupIds)
			->delete();
	}

	/**
	 * @return int Number of group_user rows between a migrated user and a migrated group,
	 *   i.e. how many memberships detachUserGroupAssignments() would remove.
	 */
	protected function countUserGroupMemberships() : int {
		$userIds = PhorumMapping::where('phorum_data_type', PhorumMapping::DATA_TYPE_USER)->pluck('flarum_id')->all();
		$groupIds = PhorumMapping::where('phorum_data_type', PhorumMapping::DATA_TYPE_USER_GROUP)->pluck('flarum_id')->all();

		if (empty($userIds) || empty($groupIds)) {
			return 0;
		}

		return $this->db->table('group_user')
			->whereIn('user_id', $userIds)
			->whereIn('group_id', $groupIds)
			->count();
	}
}
