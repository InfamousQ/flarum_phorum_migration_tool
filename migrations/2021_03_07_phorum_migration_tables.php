<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Builder;
use InfamousQ\FlarumPhorumMigrationTool\Model\PhorumMapping;

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
		$schema->getConnection()->delete('DELETE FROM users WHERE id IN (SELECT flarum_id FROM phorum_mapping m WHERE m.phorum_data_type = ? AND existing IS FALSE)', [PhorumMapping::DATA_TYPE_USER]);
		$schema->getConnection()->delete('DELETE FROM groups WHERE id IN (SELECT flarum_id FROM phorum_mapping m WHERE m.phorum_data_type = ? AND existing IS FALSE) AND id > 4', [PhorumMapping::DATA_TYPE_USER_GROUP]);
		$schema->getConnection()->delete('DELETE FROM group_user WHERE group_id IN (SELECT flarum_id FROM phorum_mapping m WHERE m.phorum_data_type = ? AND existing IS FALSE)', [PhorumMapping::DATA_TYPE_USER_GROUP]);
		$schema->getConnection()->delete('DELETE FROM group_user WHERE user_id IN (SELECT flarum_id FROM phorum_mapping m WHERE m.phorum_data_type = ? AND existing IS FALSE)', [PhorumMapping::DATA_TYPE_USER]);
		$schema->getConnection()->delete('DELETE FROM posts WHERE id IN (SELECT flarum_id FROM phorum_mapping m WHERE m.phorum_data_type = ? AND existing IS FALSE)', [PhorumMapping::DATA_TYPE_MESSAGE]);
		$schema->getConnection()->delete('DELETE FROM discussions WHERE id IN (SELECT flarum_id FROM phorum_mapping m WHERE m.phorum_data_type = ? AND existing IS FALSE)', [PhorumMapping::DATA_TYPE_DISCUSSION]);
		$schema->getConnection()->delete('DELETE FROM tags WHERE id IN (SELECT flarum_id FROM phorum_mapping m WHERE m.phorum_data_type = ? AND existing IS FALSE)', [PhorumMapping::DATA_TYPE_TAG]);
		$schema->dropIfExists('phorum_mapping');
	},
];