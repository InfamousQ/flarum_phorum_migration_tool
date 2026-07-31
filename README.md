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

```
composer require infamousq/flarum-migration-tool
```

After extension is downloaded, activate required extensions `Sticky`, `Lock` and `Tags` and this extension `Phorum migration tool`. This can be done via Flarum admin UI or by following commands:

```
php flarum extension:enable flarum-lock
php flarum extension:enable flarum-sticky
php flarum extension:enable flarum-tags
php flarum extension:enable infamousq-phorum-migration-tool
```

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

TODO!

# Testing

This extension uses [flarum/testing](https://github.com/flarum/testing) with PHPUnit for unit and integration tests, under `tests/`.

No local PHP/Composer install is required — a `docker-compose.yml` and `docker/php.Dockerfile` are provided:

```
# install dependencies
docker compose run --rm composer install

# unit tests (no database needed)
docker compose run --rm php composer test:unit

# integration tests (spins up a MariaDB service)
docker compose run --rm php composer test:setup
docker compose run --rm php composer test:integration

# or both suites at once
docker compose run --rm php composer test
```

If you have PHP and Composer installed locally instead, the same `composer test`, `composer test:unit`, `composer test:integration` and `composer test:setup` scripts work directly. (If your Docker install doesn't have the `docker compose` plugin, use the standalone `docker-compose` binary instead — same commands.)

## Continuous integration

CI (`.github/workflows/backend.yml`) uses Flarum's official reusable workflow ([docs](https://docs.flarum.org/extend/github-actions/)), which runs the same `composer install` / `composer test:setup` / `composer test` scripts across a PHP/database matrix. `php_versions` is pinned to `["8.2", "8.3"]` there because `composer.lock` currently requires PHP 8.2+ (via `laminas/laminas-diactoros` ^3.x and `laminas/laminas-httphandlerrunner` ^2.x).