<?php

namespace InfamousQ\FlarumPhorumMigrationTool\Phorum;

class Connector {

	/** @var \PDO $pdo */
	protected $pdo;

	/** @var string $table_prefix Prefix added to Phorum table names */
	protected $table_prefix;

	public function __construct($host, $db_name, $user = '', $password = '', $prefix = '') {
		$this->pdo = static::buildPDOInstance( $host, $db_name, $user, $password);
		$this->table_prefix = $prefix;
		// MySQL table prefixes must have _ character to separate prefix and name
		if (!empty($this->table_prefix) && substr($this->table_prefix, -1) !== '_') {
			$this->table_prefix .= '_';
		}
	}

	/**
	 * Created PDO instance for given parameters
	 * @var string $host
	 * @var string $db_name
	 * @var string $user
	 * @var string $password
	 * @return \PDO
	 */
	protected static function buildPDOInstance($host, $db_name, $user, $password) {
		$phorum_pdo = new \PDO("mysql:host={$host};dbname={$db_name};charset=UTF8", $user, $password);
		$phorum_pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
		return $phorum_pdo;
	}

	public function getUserGroups() {
		try {
			$p_groups_query = "SELECT group_id, name FROM {$this->table_prefix}groups ORDER BY group_id";
			return $this->pdo->query($p_groups_query);
		} catch (\PDOException $pdo_exception) {
			throw new ConnectorException('Could not query user groups from Phorum', 1,  $pdo_exception);
		}

	}

	public function getUsers() {
		try {
			// message_count is counted from the messages table rather than taken from
			// users.posts, which is a cached counter Phorum doesn't always keep in sync.
			$p_users_query = "SELECT u.user_id, u.display_name, u.real_name, u.email, u.active, u.admin,"
				. " (SELECT COUNT(*) FROM {$this->table_prefix}messages m WHERE m.user_id = u.user_id) AS message_count"
				. " FROM {$this->table_prefix}users u ORDER BY u.user_id";
			return $this->pdo->query($p_users_query);
		} catch (\PDOException $pdo_exception) {
			throw new ConnectorException('Could not query users from Phorum', 1, $pdo_exception);
		}
	}

	public function getUserToUserGroupMap() {
		try {
			$p_user_group_query = "SELECT user_id, group_id, status FROM {$this->table_prefix}user_group_xref ORDER BY user_id, group_id";
			return $this->pdo->query($p_user_group_query);
		} catch (\PDOException $pdo_exception) {
			throw new ConnectorException('Could not query users from Phorum', 1, $pdo_exception);
		}
	}

	public function getForums() {
		try {
			$p_forum_query = "SELECT forum_id, name, description, parent_id, display_order, pub_perms, reg_perms FROM {$this->table_prefix}forums WHERE active = 1 ORDER BY forum_id";
			return $this->pdo->query($p_forum_query);
		} catch (\PDOException $pdo_exception) {
			throw new ConnectorException('Could not query forums from Phorum', 1, $pdo_exception);
		}
	}

	/**
	 * Per-group forum permissions. `permission` is a bitmask of Phorum's
	 * PHORUM_USER_ALLOW_* constants, same as forums.pub_perms/reg_perms.
	 */
	public function getForumGroupPermissions() {
		try {
			$p_forum_group_query = "SELECT forum_id, group_id, permission FROM {$this->table_prefix}forum_group_xref ORDER BY forum_id, group_id";
			return $this->pdo->query($p_forum_group_query);
		} catch (\PDOException $pdo_exception) {
			throw new ConnectorException('Could not query forum group permissions from Phorum', 1, $pdo_exception);
		}
	}

	public function getThreadStartingMessages($limit = null) {
		try {
			$p_thread_starting_query = "SELECT forum_id, thread, user_id, subject, status, sort, closed FROM {$this->table_prefix}messages WHERE parent_id = 0 ORDER BY datestamp";
			if (null !== $limit && is_integer($limit)) {
				$p_thread_starting_query .= " LIMIT {$limit}";
			}
			return $this->pdo->query($p_thread_starting_query);
		} catch (\PDOException $pdo_exception) {
			throw new ConnectorException('Could not query thread starting messages from Phorum', 1, $pdo_exception);
		}
	}

	/**
	 * Fetch every reply/thread message across all threads in a single query,
	 * ordered so that callers can bucket rows by `thread` and rely on
	 * message order within each bucket for first/last post detection.
	 *
	 * @return \PDOStatement
	 */
	public function getAllThreadMessages() {
		try {
			$p_thread_messages_query = "SELECT thread, message_id, user_id, body, status, datestamp FROM {$this->table_prefix}messages ORDER BY thread, datestamp, message_id";
			return $this->pdo->query($p_thread_messages_query);
		} catch (\PDOException $pdo_exception) {
			throw new ConnectorException('Could not query thread messages from Phorum', 1, $pdo_exception);
		}
	}
}

class ConnectorException extends \Exception {}