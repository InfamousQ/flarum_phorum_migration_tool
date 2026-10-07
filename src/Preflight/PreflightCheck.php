<?php

namespace InfamousQ\FlarumPhorumMigrationTool\Preflight;

use InfamousQ\FlarumPhorumMigrationTool\Phorum\Connector;

/**
 * A check run before a migration command writes anything. Each check looks for
 * Phorum data the migration would fail on part way through, so every problem can
 * be reported and fixed up front instead of one failed run at a time.
 */
interface PreflightCheck {

	/**
	 * @return string[] One human readable line per problem found, empty when all is well
	 */
	public function run(Connector $connector) : array;
}
