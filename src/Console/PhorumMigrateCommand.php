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
		foreach ($p_discussions as $p_thread_id => $discussion_id) {
			$this->importPhorumMessageForThread($connector, $p_thread_id, $discussion_id, $p_users);
		}
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
			if ($status < 1 && $status > 2) {
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
	 * Find thread starting messages from Phorum and create Discussion for each
	 *
	 * @param Connector $connector
	 * @param User[] $users . Key is Phorum user id
	 * @param Tag[] $tags . Key is Phorum forum id
	 * @return Discussion[]
	 */
	protected function importPhorumMessagesAsDiscussions(Connector $connector, $users, array $tags) : array {
		// First message is thread starter in Flarum
		$p_thread_starting_messages = $connector->getThreadStartingMessages();
		$discussions = [];
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
			$discussion_id = PhorumMapping::getFlarumIdForPhorumId(PhorumMapping::DATA_TYPE_DISCUSSION, $p_thread_id);
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

			$existing = false;
			if (null === $discussion_id) {
				// Not found, create
				$discussion = new Discussion();
				$discussion->rename($p_subject);
				$discussion->setAttribute('is_sticky', $p_message_is_sticky);
				$discussion->setAttribute('is_locked', $p_message_is_locked);
				$discussion->setRelation('user', $author_user);
				// Note: discussion creation timestamp is edited when posts are added
				$discussion->save();
				$discussion->refresh();

				// If Phorum message is not approved, set the discussion as hidden
				if ($p_status_int < 2) {
					$discussion->hide();
				}

				$this->output->writeln("Discussion - created new discussion");

				$tag->discussions()->save($discussion);
			} else {
				// Found, no need to touch anything
				$existing = true;
				$discussion = Discussion::find($discussion_id);
				$this->output->writeln("Discussion - found old discussion");
			}

			PhorumMapping::setFlarumIdForPhorumId(PhorumMapping::DATA_TYPE_DISCUSSION, $p_thread_id, $discussion->id, $existing);
			$discussions[$p_thread_id] = $discussion->id;
		}

		return $discussions;
	}

	/**
	 * @param Connector $connector
	 * @param int $phorum_thread_id
	 * @param int $discussion_id
	 * @param User[] $users
	 * @param Tag[] $forums
	 */
	protected function importPhorumMessageForThread(Connector $connector, int $phorum_thread_id, $discussion_id, array $users) {
		// Find all messages that follow particular thread_id
		$p_thread_messages = $connector->getThreadMessages($phorum_thread_id);
		$this->output->writeln("Reading messages for Phorum thread {$phorum_thread_id}");
		/** @var Post[] $posts */
		$posts = [];
		$discussion = $discussion = Discussion::find($discussion_id);
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

			$post_id = PhorumMapping::getFlarumIdForPhorumId(PhorumMapping::DATA_TYPE_MESSAGE, $p_message_id);
			/** @var \Flarum\Post\CommentPost $post */
			if (null === $post_id) {
				$post = HistoricCommentPost::replyAtTime($discussion->id, $p_body, $author_user->id, '127.0.0.1', $p_created);
				$post->save();
			} else {
				$post = $discussion->posts->find($post_id);
				if (null === $post) {
					// Post found but it is not in expected Discussion. Log error and skip
					$this->logger->critical('Post linked to wrong Discussion', ['post id' => $post_id, 'discussion id' => $discussion->id]);
					continue;
				}
			}

			PhorumMapping::setFlarumIdForPhorumId(PhorumMapping::DATA_TYPE_MESSAGE, $p_message_id, $post->id);
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
			$discussion->save();
		}
		$discussion
			->refreshCommentCount()
			->refreshLastPost()
			->refreshParticipantCount()
			->save();

		return $posts;
	}
}