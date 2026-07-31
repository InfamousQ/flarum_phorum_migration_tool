<?php

namespace InfamousQ\FlarumPhorumMigrationTool\Console;

use InfamousQ\FlarumPhorumMigrationTool\Phorum\Connector;
use InfamousQ\FlarumPhorumMigrationTool\Model\PhorumMapping;
use InfamousQ\FlarumPhorumMigrationTool\Log\ConsoleLogger;
use Flarum\Console\AbstractCommand;
use Flarum\Discussion\Discussion;
use Flarum\Group\Group;
use Flarum\Settings\SettingsRepositoryInterface;
use Flarum\Tags\Tag;
use Flarum\User\User;
use InfamousQ\FlarumPhorumMigrationTool\Model\HistoricCommentPost;
use Carbon\Carbon;
use Illuminate\Support\Str;
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerAwareTrait;
use Psr\Log\NullLogger;

class PhorumMigrateCommand extends AbstractCommand implements LoggerAwareInterface {

	use LoggerAwareTrait;

	/** @var SettingsRepositoryInterface */
	protected $settings;

	public function __construct(SettingsRepositoryInterface $settings) {
		$this->settings = $settings;
		parent::__construct();
	}

	protected function configure() {
		$this
			->setName('phorum:migrate')
			->setDescription('Migrate data from existing Phorum installation');
	}

	protected function fire() {
		$this->setLogger(new NullLogger());
		if ($this->output->isVerbose()) {
			$this->setLogger(new ConsoleLogger());
		}

		$phorum_db_host = $this->settings->get('infamousq-phorum-migration-tool.phorum_db_host');
		$phorum_db_name = $this->settings->get('infamousq-phorum-migration-tool.phorum_db_name');
		$phorum_db_username = $this->settings->get('infamousq-phorum-migration-tool.phorum_db_username');
		$phorum_db_password = $this->settings->get('infamousq-phorum-migration-tool.phorum_db_password');
		$phorum_db_prefix = $this->settings->get('infamousq-phorum-migration-tool.phorum_db_prefix', '');

		$connector = new Connector(
			$phorum_db_host,
			$phorum_db_name,
			$phorum_db_username,
			$phorum_db_password,
			$phorum_db_prefix
		);

		$p_user_groups = $this->importUserGroups($connector);
		$p_users = $this->importUsers($connector);
		$this->importUserGroupMapping($connector, $p_user_groups, $p_users);
		unset($p_user_groups);
		$p_forums = $this->importPhorumForumsAsTags($connector);
		$p_discussions = $this->importPhorumMessagesAsDiscussions($connector, $p_users, $p_forums);
		unset($p_forums);
		$this->importPhorumMessages($connector, $p_discussions, $p_users);
	}

	protected function importUserGroups(Connector $connector) : array { 
		// Import all user groups
		$p_groups = $connector->getUserGroups();
		/** @var Group[] $p_groups_created */
		$p_groups_created = [];
		foreach ($p_groups as $p_group_row) {
			$existing = false;
			$phorum_user_group_id = $p_group_row['group_id'];
			$user_group_id = PhorumMapping::getFlarumIdForPhorumId(PhorumMapping::DATA_TYPE_USER_GROUP, $phorum_user_group_id);
			$user_group = Group::find($user_group_id);
			if (null === $user_group_id) {
				$user_group = Group::build($p_group_row['name'], $p_group_row['name']);
			}
			$user_group->name_singular = $p_group_row['name'];
			$user_group->name_plural = $p_group_row['name'];

			$user_group->saveOrFail();
			$this->output->writeln("Phorum user group {$p_group_row['group_id']} - Generated Flarum group {$user_group->id}");
			$user_group->refresh();
			$p_groups_created[$p_group_row['group_id']] = $user_group;
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
			$existing = false;
			$phorum_user_id = $p_user_row['user_id'];
			$user_id = PhorumMapping::getFlarumIdForPhorumId(PhorumMapping::DATA_TYPE_USER, $phorum_user_id);
			if (null == $user_id) {
				// No existing mapped user, see if we have user with same email already
				$user = User::where(['email' => $p_user_row['email']])->first();
				if (null === $user) {
					$user = User::register($p_user_row['display_name'], $p_user_row['email'], 'test');
				} else {
					$existing = true; // This use previously existed already!
					$user->rename($p_user_row['display_name']);
					$user->changeEmail($p_user_row['email']);
				}
			} else {
				$user = User::find($user_id);
				$user->rename($p_user_row['display_name']);
				$user->changeEmail($p_user_row['email']);
			}

			$user->saveOrFail();
			$this->output->writeln("Phorum user {$p_user_row['user_id']} - Generated Flarum user {$user->id}");
			$user->refresh();
			$users_created[$p_user_row['user_id']] = $user;
			PhorumMapping::setFlarumIdForPhorumId(PhorumMapping::DATA_TYPE_USER, $phorum_user_id, $user->id, $existing);
		}

		return $users_created;
	}

	/**
	 * Undocumented function
	 *
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

	protected function importPhorumForumsAsTags(Connector $connector) : array {
		$p_forums = $connector->getForums();
		$tags = [];
		foreach ($p_forums as $p_forum) {
			$p_forum_id = (int) $p_forum['forum_id'] ?? 0;
			$p_forum_name = $p_forum['name'] ?? '';
			$p_forum_description = $p_forum['description'] ?? '';
			$p_forum_position = $p_forum['display_order'] ?? null;
			$tag_id = PhorumMapping::getFlarumIdForPhorumId(PhorumMapping::DATA_TYPE_TAG, $p_forum_id);
			$existing = false;
			if (null === $tag_id) {
				$tag = Tag::build($p_forum_name, $p_forum_name, $p_forum_description, '#888', null, true);
				$tag->position = $p_forum_position;
				$tag->save();
				$tag->refresh();
			} else {
				$existing = true;
				$tag = Tag::find($tag_id);
			}
			PhorumMapping::setFlarumIdForPhorumId(PhorumMapping::DATA_TYPE_TAG, $p_forum_id, $tag->id, $existing);
			$tags[$p_forum_id] = $tag;
		}
		return $tags;
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
	 * @param User[] $users . Key is Phorum user id
	 * @param Tag[] $tags . Key is Phorum forum id
	 * @return int[] Discussion id keyed by Phorum thread id
	 */
	protected function importPhorumMessagesAsDiscussions(Connector $connector, $users, array $tags) : array {
		// First message is thread starter in Flarum
		$p_thread_starting_messages = $connector->getThreadStartingMessages();
		$discussion_mapping = PhorumMapping::where('phorum_data_type', PhorumMapping::DATA_TYPE_DISCUSSION)
			->pluck('flarum_id', 'phorum_id')
			->all();
		$default_locale = $this->settings->get('default_locale', 'en');

		$discussions = [];
		/** @var array $pending_discussions List of ['thread_id' => , 'tag_id' => , 'row' => []] awaiting bulk insert */
		$pending_discussions = [];
		foreach ($p_thread_starting_messages as $p_msg) {
			$p_forum_id = (int) $p_msg['forum_id'] ?? 0;
			$p_thread_id = $p_msg['thread'] ?? null;
			$p_user_id = (int) $p_msg['user_id'] ?? null;
			$p_subject = (string) $p_msg['subject'] ?? '';
			/**
			 * Phorum's message's status can be either..
			 * 2 = PHORUM_STATUS_APPROVED
			 * -1 = PHORUM_STATUS_HOLD
			 * -2 = PHORUM_STATUS_HIDDEN
			 */
			$p_status_int = (int) $p_msg['status'] ?? 2;
			// Phorum thread is sticky if it's starting message is marked to have own special sort value
			$p_message_is_sticky = $p_msg['sort'] == 1;
			// Phorum thread is locked if it's starting message is marked to have 'closed' attribute
			$p_message_is_locked = $p_msg['closed'] == 1;
			$author_user = $users[$p_user_id] ?? null;
			if (null === $author_user) {
				// TODO: Create tmp user
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
		}

		foreach (array_chunk($pending_discussions, 500) as $chunk) {
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
			PhorumMapping::insert($mapping_rows);
		}

		return $discussions;
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
	 * Import every reply for every thread. Messages are fetched from Phorum
	 * in a single bulk query and grouped by thread in memory, and the
	 * Phorum-id-to-Flarum-id mapping table is preloaded/batch-inserted once,
	 * instead of once per message, to avoid tens of thousands of per-post
	 * round trips.
	 *
	 * @param Connector $connector
	 * @param array $p_discussions Discussion id keyed by Phorum thread id
	 * @param User[] $users
	 */
	protected function importPhorumMessages(Connector $connector, array $p_discussions, array $users) {
		$p_messages_by_thread = [];
		foreach ($connector->getAllThreadMessages() as $p_msg) {
			$p_messages_by_thread[$p_msg['thread']][] = $p_msg;
		}

		$message_id_to_post_id = PhorumMapping::where('phorum_data_type', PhorumMapping::DATA_TYPE_MESSAGE)
			->pluck('flarum_id', 'phorum_id')
			->all();

		$new_mappings = [];
		foreach ($p_discussions as $p_thread_id => $discussion_id) {
			$this->importPhorumMessageForThread(
				$p_thread_id,
				$discussion_id,
				$users,
				$p_messages_by_thread[$p_thread_id] ?? [],
				$message_id_to_post_id,
				$new_mappings
			);
		}

		foreach (array_chunk($new_mappings, 500) as $chunk) {
			PhorumMapping::insert($chunk);
		}
	}

	/**
	 * @param int $phorum_thread_id
	 * @param int $discussion_id
	 * @param User[] $users
	 * @param array $p_thread_messages Messages belonging to this thread, in order
	 * @param array $message_id_to_post_id Phorum message id => Flarum post id, shared across threads
	 * @param array $new_mappings Rows to bulk-insert into phorum_mapping, shared across threads
	 */
	protected function importPhorumMessageForThread(int $phorum_thread_id, $discussion_id, array $users, array $p_thread_messages, array &$message_id_to_post_id, array &$new_mappings) {
		$this->output->writeln("Reading messages for Phorum thread {$phorum_thread_id}");
		/** @var Post[] $posts */
		$posts = [];
		$discussion = Discussion::find($discussion_id);
		foreach ($p_thread_messages as $p_msg) {
			$p_message_id = $p_msg['message_id'] ?? null;
			$p_user_id = $p_msg['user_id'] ?? null;
			$p_body = $p_msg['body'] ?? '';
			$p_created = $p_msg['datestamp'] ?? null;
			$p_is_closed = $p_msg['closed'] == 1;

			$author_user = $users[$p_user_id] ?? null;
			if (null === $author_user) {
				// TODO: Create tmp user
				$this->logger->critical('Unknown Phorum user id', ['user_id' => $p_user_id]);
				continue;
			}

			$post_id = $message_id_to_post_id[$p_message_id] ?? null;
			/** @var \Flarum\Post\CommentPost $post */
			if (null === $post_id) {
				$post = HistoricCommentPost::replyAtTime($discussion->id, $p_body, $author_user->id, '127.0.0.1', $p_created);
				$post->save();
				$message_id_to_post_id[$p_message_id] = $post->id;
				$new_mappings[] = [
					'phorum_data_type' => PhorumMapping::DATA_TYPE_MESSAGE,
					'phorum_id' => (int) $p_message_id,
					'flarum_id' => (int) $post->id,
					'existing' => false,
				];
			} else {
				$post = $discussion->posts->find($post_id);
				if (null === $post) {
					// Post found but it is not in expected Discussion. Log error and skip
					$this->logger->critical('Post linked to wrong Discussion', ['post id' => $post_id, 'discussion id' => $discussion->id]);
					continue;
				}
			}

			if ($p_is_closed) {
				$post
					->hide()
					->save();
			}
			$posts[] = $post;
		}

		if (!empty($posts)) {
			$first_post = reset($posts);
			$discussion->setFirstPost($first_post);
			$last_post = end($posts);
			$discussion->setLastPost($last_post);
			$discussion->comment_count = count($posts);
			$discussion->participant_count = count(array_unique(array_map(function ($post) {
				return $post->user_id;
			}, $posts)));
			$discussion->save();
		}

		return $posts;
	}
}