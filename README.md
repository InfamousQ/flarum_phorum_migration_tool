# flarum-phorum-migration-tool

`flarum-phorum-migration-tool` is Flarum extension for migrating Phorum forum content to Flarum forum.

Following are migrated:
* User groups
* Users
	* Note: passwords are not migrated
* Phorum forums
	* Migrated as first-level tags in Flarum
* Discussion thread
* Forum messages

# Installing

In Flarum installation directory, run shell command:

`composer require infamousq/flarum-migration-tool`

After extension is downloaded, go to Flarum admin UI. Activate the extension.

Go to extension admin UI page. Fill in Phorum database information.

Security tip: Create new database user that has read-only access to Phorum content. This ensures that migration does not accidentally edit Phorum installation's data!

# How to migrate

In Flarum installation directory, run shell command:

`php flarum phorum:migrate`

Migration takes a while.

## Steps after migration

* Edit user groups, mark those that you wish to hide from public
* Mark tags as hidden in Flarum admin UI
* Migrated users can use password reset tool to update their passwords.

# Undoing migration

Warning: Any migrated content is deleted when undoing migration! This includes user groups and users!