<?php

namespace InfamousQ\FlarumPhorumMigrationTool\Model;

use Flarum\Database\AbstractModel;
use Illuminate\Support\Facades\Schema;

/**
 * @property int $phorum_data_type
 * @property int $phorum_id
 * @property int $flarum_id
 * @property boolean $existing
 */
class PhorumMapping extends AbstractModel {
	const DATA_TYPE_USER_GROUP = 1;
	const DATA_TYPE_USER = 2;
	const DATA_TYPE_DISCUSSION = 3;
	const DATA_TYPE_MESSAGE = 4;
	const DATA_TYPE_TAG = 5;

	protected $table = 'phorum_mapping';
	protected $fillable = ['phorum_data_type', 'phorum_id', 'existing'];

	public static function getMappingForPhorumId(int $data_type, int $phorum_id) {
		$condition = [
			'phorum_data_type' => (int) $data_type,
			'phorum_id' => (int) $phorum_id,
		];
		$found_model = PhorumMapping::where($condition)
			->first();

		return $found_model;
	}

	public static function getFlarumIdForPhorumId(int $data_type, int $phorum_id) {
		$found_model = static::getMappingForPhorumId($data_type, $phorum_id);
		if (null === $found_model) {
			return null;
		}
		return $found_model->flarum_id;
	}

	public static function setFlarumIdForPhorumId(int $data_type, int $phorum_id, int $flarum_id, bool $is_existing = false) {
		$condition = [
			'phorum_data_type' => (int) $data_type,
			'phorum_id' => (int) $phorum_id,
		];
		$model = static::firstOrCreate($condition);
		$model->flarum_id = (int) $flarum_id;
		$model->existing = $is_existing;
		$model->save();
	}

	public static function doesMappingTableHaveData() : bool{
		return static::all()->count() > 0;
	}
}