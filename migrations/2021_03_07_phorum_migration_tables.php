<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Builder;

return [
	'up' => function (Builder $schema) {
		$schema->create('phorum_mapping', function (Blueprint $table) {
			$table->bigIncrements('id');
			$table->unsignedInteger('phorum_data_type');
			$table->unsignedInteger('phorum_id');
			$table->unsignedInteger('flarum_id')->nullable();
			$table->boolean('existing')->default(false);
			$table->timestampsTz(0);
			$table->index(['phorum_data_type', 'phorum_id']);
		});
	},
	'down' => function (Builder $schema) {
		// Only drop the bookkeeping table. Migrated content must survive uninstalling
		// this extension (Flarum's purge runs this) - use phorum:reset to undo a migration.
		$schema->dropIfExists('phorum_mapping');
	},
];
