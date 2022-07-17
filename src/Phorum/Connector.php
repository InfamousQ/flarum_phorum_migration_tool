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
			$p_users_query = "SELECT user_id, display_name, real_name, email, active, admin FROM {$this->table_prefix}users ORDER BY user_id";
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
			$p_forum_query = "SELECT forum_id, name, description, parent_id, display_order FROM {$this->table_prefix}forums WHERE active = 1 ORDER BY forum_id";
			return $this->pdo->query($p_forum_query);
		} catch (\PDOException $pdo_exception) {
			throw new ConnectorException('Could not query forums from Phorum', 1, $pdo_exception);
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
	 * Undocumented function
	 *
	 * @param integer $thread_id
	 * @return \PDOStatement
	 */
	public function getThreadMessages(int $thread_id) {
		try {
			$p_thread_messages_query = "SELECT message_id, user_id, body, closed, datestamp FROM {$this->table_prefix}messages WHERE thread = {$thread_id}";
			return $this->pdo->query($p_thread_messages_query);
		} catch (\PDOException $pdo_exception) {
			throw new ConnectorException('Could not query thread starting messages from Phorum', 1, $pdo_exception);
		}
	}
}

class ConnectorException extends \Exception {}