<?php

namespace InfamousQ\FlarumPhorumMigrationTool\Preflight;

use Illuminate\Database\ConnectionInterface;
use InfamousQ\FlarumPhorumMigrationTool\Phorum\Connector;

/**
 * Discussions and posts are bulk-inserted, and each new row's id is derived from the
 * first inserted id plus its position in the batch (see bulkInsertAndGetFirstId()).
 * That only holds when the Flarum database hands out auto-increment ids in steps
 * of 1. Galera / MariaDB Cluster, and multi-primary replication setups, commonly
 * use a larger step, which would map Phorum threads and messages to the wrong
 * discussions and posts.
 */
class AutoIncrementCheck implements PreflightCheck {

	/** @var ConnectionInterface The Flarum database connection the bulk inserts go through */
	protected $db;

	public function __construct(ConnectionInterface $db) {
		$this->db = $db;
	}

	public function run(Connector $connector) : array {
		$increment = (int) $this->db->selectOne('SELECT @@auto_increment_increment AS increment')->increment;
		if (1 === $increment) {
			return [];
		}

		return ["The Flarum database has auto_increment_increment = {$increment}, but migrating discussions and posts requires 1. "
			. "Set it to 1 for the duration of the migration (on Galera, also turn wsrep_auto_increment_control off "
			. "and migrate against a single node) and run again."];
	}
}
