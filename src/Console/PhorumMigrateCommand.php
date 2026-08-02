<?php

namespace InfamousQ\FlarumPhorumMigrationTool\Console;

/**
 * Runs the full Phorum-to-Flarum migration pipeline in one shot: groups, users,
 * user-to-group assignment, tags, discussions, then posts, in that order. Each
 * step is also available as its own standalone `phorum:migrate:*` command (see
 * AbstractPhorumMigrateCommand), for re-running a single step later - e.g. just
 * `phorum:migrate:posts` to pick up new posts without touching groups/tags that
 * have since been hand-edited inside Flarum.
 */
class PhorumMigrateCommand extends AbstractPhorumMigrateCommand {

	protected function configure() {
		$this
			->setName('phorum:migrate')
			->setDescription('Migrate data from existing Phorum installation (runs every step - see also the individual phorum:migrate:* commands)');
	}

	protected function fire() {
		$this->setUpLogger();
		$connector = $this->buildConnector();

		$p_user_groups = $this->importUserGroups($connector);
		$p_users = $this->importUsers($connector);
		$this->importUserGroupMapping($connector, $p_user_groups, $p_users);
		unset($p_user_groups);
		$p_forums = $this->importPhorumForumsAsTags($connector);
		$p_discussions = $this->importPhorumMessagesAsDiscussions($connector, $p_users, $p_forums);
		unset($p_forums);
		$this->importPhorumMessages($connector, $p_discussions, $p_users);
	}
}
