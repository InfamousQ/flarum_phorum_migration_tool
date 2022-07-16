import app from 'flarum/admin/app';

app.initializers.add('infamousq-flarum-phorum-migration-tool', () => {
	app.extensionData
		.for('infamousq-phorum-migration-tool')
		.registerSetting({
				setting: 'infamousq-phorum-migration-tool.phorum_db_host',
				label: app.translator.trans('infamousq-phorum-migration-tool.admin.db-host'),
				type: 'text',
			})
		.registerSetting({
				setting: 'infamousq-phorum-migration-tool.phorum_db_name',
				label: app.translator.trans('infamousq-phorum-migration-tool.admin.db-name'),
				type: 'text',
			})
		.registerSetting({
				setting: 'infamousq-phorum-migration-tool.phorum_db_username',
				label: app.translator.trans('infamousq-phorum-migration-tool.admin.db-username'),
				help: app.translator.trans('infamousq-phorum-migration-tool.admin.db-username-help'),
				type: 'text',
			})
		.registerSetting({
				setting: 'infamousq-phorum-migration-tool.phorum_db_password',
				label: app.translator.trans('infamousq-phorum-migration-tool.admin.db-password'),
				type: 'text',
			})
		.registerSetting({
				setting: 'infamousq-phorum-migration-tool.phorum_db_prefix',
				label: app.translator.trans('infamousq-phorum-migration-tool.admin.db-prefix'),
				help: app.translator.trans('infamousq-phorum-migration-tool.admin.db-prefix-help'),
				type: 'text',
			});
});