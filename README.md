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
composer require infamousq/flarum-phorum-migration-tool
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

Each step can also be run on its own: `phorum:migrate:groups`, `phorum:migrate:users`, `phorum:migrate:user-groups`, `phorum:migrate:tags`, `phorum:migrate:discussions` and `phorum:migrate:posts`, in that order.

## Pre-flight checks

Before `phorum:migrate`, `phorum:migrate:users`, `phorum:migrate:discussions` or `phorum:migrate:posts` writes anything, it runs pre-flight checks against the Phorum data and stops with a list of every problem found. Fix them and run the command again. Currently checked:

* Usernames: a Phorum user whose username and `<username>_migrated` are both already taken in Flarum (or by another Phorum user in the same run). Rename one of the clashing users.

## How data is converted

* Usernames: Phorum display names are converted to valid Flarum usernames (letters, digits, `_` and `-`, 3 to 30 characters), e.g. `Matti Meikäläinen` becomes `Matti_Meikalainen`. A name that is taken gets `_migrated` appended.
* Users matched by email: a Phorum user whose email already belongs to a Flarum account is merged into that account, and **that Flarum account is renamed to the Phorum user's username**. Resetting the migration does not restore the old username.
* Join date: a user's join date is set to the date of their first migrated post, when that is earlier.
* Guest messages: messages from Phorum guests and deleted users are attributed to a single, suspended `Guest` user.
* Forum permissions: a forum everyone can read and members can fully post in becomes a normal tag. Any other forum, e.g. a read-only announcements forum or a members-only forum, becomes a restricted tag with view, reply and start-discussion permissions per group as in Phorum. Moderator permissions are not migrated.

## Steps after migration

* Edit user groups, mark those that you wish to hide from public
* Mark tags as hidden in Flarum admin UI
* Migrated users can use password reset tool to update their passwords.

# Undoing migration

Warning: Undoing migration permanently deletes migrated content! This includes user groups and users!

To delete everything the migration created, run:

`php flarum phorum:reset`

The command asks for confirmation first; pass `--force` to skip it. Flarum users that already existed before migration and were matched to Phorum users by email are not deleted.

**Warning:** resetting discussions (`phorum:reset` or `phorum:reset:discussions`) deletes every post inside a migrated discussion, including replies written in Flarum after the migration.

Individual steps can be undone with `phorum:reset:posts`, `phorum:reset:discussions`, `phorum:reset:tags`, `phorum:reset:user-groups`, `phorum:reset:groups` and `phorum:reset:users`. Note that deleting users does not delete their posts or discussions; those are left without an author, so reset posts and discussions first.

Uninstalling or purging this extension does not undo migration. It only removes the extension's own bookkeeping table, and all migrated content stays in Flarum. Run `phorum:reset` before uninstalling if you want the migrated content removed, because the reset commands rely on that table.

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