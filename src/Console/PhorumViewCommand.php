<?php

namespace InfamousQ\FlarumPhorumMigrationTool\Console;

use Flarum\Console\AbstractCommand;
use Flarum\Settings\SettingsRepositoryInterface;
use InfamousQ\FlarumPhorumMigrationTool\Phorum\Connector;
use InfamousQ\FlarumPhorumMigrationTool\Model\PhorumMapping;
use InfamousQ\FlarumPhorumMigrationTool\Log\ConsoleLogger;
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerAwareTrait;
use Psr\Log\NullLogger;

class PhorumViewCommand extends AbstractCommand implements LoggerAwareInterface {

	use LoggerAwareTrait;

	/** @var SettingsRepositoryInterface */
	protected $settings;

	public function __construct(SettingsRepositoryInterface $settings) {
		$this->settings = $settings;
		parent::__construct();
	}

	protected function configure() {
		$this
			->setName('phorum:view')
			->setDescription('View existing phorum installation, used for debugging if connection is working');
	}

	protected function fire() {

		$this->setLogger(new NullLogger());
		if ($this->output->isVerbose()) {
			$this->setLogger(new ConsoleLogger());
		}

		$phorum_db_host = $this->settings->get('infamousq-phorum-migration-tool.phorum_db_host');
		$phorum_db_name = $this->settings->get('infamousq-phorum-migration-tool.phorum_db_name');
		$phorum_db_username = $this->settings->get('infamousq-phorum-migration-tool.phorum_db_username');
		$phorum_db_password = $this->settings->get('infamousq-phorum-migration-tool.phorum_db_password');
		$phorum_db_prefix = $this->settings->get('infamousq-phorum-migration-tool.phorum_db_prefix', '');

		$this->logger->debug("host: {$phorum_db_host}\nuser: {$phorum_db_username}\npass: {$phorum_db_password}\nDB: {$phorum_db_name}\nPrefix: {$phorum_db_prefix}");

		$connector = new Connector(
			$phorum_db_host,
			$phorum_db_name,
			$phorum_db_username,
			$phorum_db_password,
			$phorum_db_prefix
		);

		// Find all user groups
		$this->output->writeln("-----");
		$this->output->writeln("GROUPS");
		$p_groups = $connector->getUserGroups();
		foreach ($p_groups as $p_groups_row) {
			$phorum_user_group_id = $p_groups_row['group_id'];
			$existing_flarum_user_group_id = PhorumMapping::getFlarumIdForPhorumId(PhorumMapping::DATA_TYPE_USER_GROUP, $phorum_user_group_id);
			echo "{$p_groups_row['name']} - {$p_groups_row['group_id']} - {$existing_flarum_user_group_id}" . PHP_EOL;
		}

		// Find all users
		$this->output->writeln("-----");
		$this->output->writeln("USERS");
		$p_users = $connector->getUsers();
		foreach ($p_users as $p_users_row) {
			$phorum_user_id = $p_users_row['user_id'];
			$existing_flarum_user_id = PhorumMapping::getFlarumIdForPhorumId(PhorumMapping::DATA_TYPE_USER, $phorum_user_id);
			echo "{$p_users_row['display_name']} - {$p_users_row['user_id']} - {$existing_flarum_user_id}" . PHP_EOL;
		}

	}

}