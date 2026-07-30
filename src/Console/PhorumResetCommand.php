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
use Throwable;

/**
 * Dev/test convenience command: undoes a `phorum:migrate` run by deleting everything in
 * Flarum that the migration tool itself created, using the `phorum_mapping` bookkeeping
 * table as the source of truth for "what did we create". This lets a developer iterate on
 * `phorum:migrate` locally (fix a config mistake, re-run, repeat) without having to restore
 * a database snapshot every time.
 *
 * This is intentionally a blunt full reset, not a surgical one - acceptable for a local dev/
 * test tool, but it is destructive. It deliberately does NOT delete Flarum users that were
 * merely *matched* to a pre-existing account by email during migration (see importUsers() in
 * PhorumMigrateCommand - those are recorded in phorum_mapping with `existing = true`); only
 * users the migration actually created (`existing = false`) are removed. Flarum core itself
 * also refuses to delete the root admin (user id 1) as a last-resort safety net.
 */
class PhorumResetCommand extends AbstractCommand
{
	/** @var ConnectionInterface */
	protected $db;

	public function __construct(ConnectionInterface $db) {
		$this->db = $db;
		parent::__construct();
	}

	protected function configure() {
		$this
			->setName('phorum:reset')
			->setDescription('Delete everything created by a previous phorum:migrate run (dev/test convenience only), so it can be re-run from a clean slate')
			->addOption(
				'force',
				'f',
				InputOption::VALUE_NONE,
				'Skip the "are you sure?" confirmation prompt'
			);
	}

	protected function fire() {
		if (!PhorumMapping::doesMappingTableHaveData()) {
			$this->output->writeln('phorum_mapping table is empty - nothing to reset.');
			return 0;
		}

		if (!$this->input->getOption('force') && !$this->confirm()) {
			$this->output->writeln('Aborted, nothing was deleted.');
			return 0;
		}

		$counts = [
			'posts' => 0,
			'discussions' => 0,
			'tags' => 0,
			'groups' => 0,
			'users' => 0,
			'users_skipped_existing' => 0,
		];

		try {
			$this->db->transaction(function () use (&$counts) {
				$this->deletePosts($counts);
				$this->deleteDiscussions($counts);
				$this->deleteTags($counts);
				$this->deleteGroups($counts);
				$this->deleteUsers($counts);

				// Clear the whole bookkeeping table (not just rows for entities we actually
				// deleted) so a subsequent phorum:migrate run starts completely fresh, rather
				// than thinking any of this data is already migrated. This is safe even for
				// the DATA_TYPE_USER rows we chose not to delete the underlying user for:
				// phorum:migrate re-matches users by email on a fresh run, so it will find
				// and re-link the same pre-existing Flarum account again.
				PhorumMapping::query()->delete();
			});
		} catch (Throwable $e) {
			$this->error('Reset failed, transaction rolled back - no data was deleted: '.$e->getMessage());
			return 1;
		}

		$this->output->writeln('Phorum migration reset complete:');
		$this->output->writeln("  Posts deleted: {$counts['posts']}");
		$this->output->writeln("  Discussions deleted: {$counts['discussions']}");
		$this->output->writeln("  Tags deleted: {$counts['tags']}");
		$this->output->writeln("  Groups deleted: {$counts['groups']}");
		$this->output->writeln("  Users deleted: {$counts['users']}");
		$this->output->writeln("  Users skipped (pre-existing Flarum account matched by email, not deleted): {$counts['users_skipped_existing']}");
		$this->output->writeln('the next phorum:migrate run will start from a clean slate.');

		return 0;
	}

	protected function confirm() : bool {
		/** @var QuestionHelper $questionHelper */
		$questionHelper = $this->getHelperSet()->get('question');
		$question = new ConfirmationQuestion(
			'This will permanently delete all discussions, posts, tags and groups created by '.
			"the Phorum migration, plus any Flarum users it created (pre-existing Flarum users \n".
			'that were merely matched by email are left untouched). Are you sure? [y/N] ',
			false
		);

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
			});
	}

	/**
	 * Delete discussions (Phorum threads). Deleting the Discussion model row cascades to any
	 * remaining posts at the DB level (posts.discussion_id has ON DELETE CASCADE), but we
	 * also explicitly delete any post still attached first (belt-and-suspenders in case a
	 * post is missing its own phorum_mapping row for some reason) so Post's model events
	 * still get a chance to fire.
	 */
	protected function deleteDiscussions(array &$counts) {
		PhorumMapping::where('phorum_data_type', PhorumMapping::DATA_TYPE_DISCUSSION)
			->get()
			->each(function (PhorumMapping $mapping) use (&$counts) {
				$discussion = Discussion::find($mapping->flarum_id);
				if (null === $discussion) {
					return;
				}

				Post::where('discussion_id', $discussion->id)->get()->each(function (Post $post) use (&$counts) {
					$post->delete();
					$counts['posts']++;
				});

				$discussion->delete();
				$counts['discussions']++;
			});
	}

	/**
	 * Delete tags (Phorum forums). discussion_tag rows are cleaned up via DB-level cascade.
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
			});
	}

	/**
	 * Delete groups (Phorum user groups). group_user/group_permission rows are cleaned up via
	 * DB-level cascade. Skips Flarum's own built-in groups (Administrator/Guest/Member/
	 * Moderator) defensively - the migration tool always creates fresh groups via Group::build()
	 * so a mapping should never legitimately point at one of these, but this guards against a
	 * corrupted mapping table taking down a built-in group.
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
				if (in_array((int) $mapping->flarum_id, $builtInGroupIds, true)) {
					return;
				}

				$group = Group::find($mapping->flarum_id);
				if (null !== $group) {
					$group->delete();
					$counts['groups']++;
				}
			});
	}

	/**
	 * Delete users, but ONLY the ones the migration actually created (phorum_mapping.existing
	 * === false). Rows where `existing` is true were matched to a pre-existing Flarum account
	 * by email in importUsers() (see PhorumMigrateCommand) - that account existed before the
	 * migration ran and must not be deleted here, even though the migration may have renamed
	 * it / changed its email. Flarum core itself additionally refuses to delete user id 1 (the
	 * root admin) as a last-resort safety net.
	 */
	protected function deleteUsers(array &$counts) {
		PhorumMapping::where('phorum_data_type', PhorumMapping::DATA_TYPE_USER)
			->get()
			->each(function (PhorumMapping $mapping) use (&$counts) {
				if ($mapping->existing) {
					$counts['users_skipped_existing']++;
					return;
				}

				$user = User::find($mapping->flarum_id);
				if (null !== $user) {
					$user->delete();
					$counts['users']++;
				}
			});
	}
}
