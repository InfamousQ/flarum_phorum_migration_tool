<?php

namespace InfamousQ\FlarumPhorumMigrationTool\Console;

use Flarum\Console\AbstractCommand;
use InfamousQ\FlarumPhorumMigrationTool\Phorum\Connector;
use InfamousQ\FlarumPhorumMigrationTool\Model\PhorumMapping;
use InfamousQ\FlarumPhorumMigrationTool\Log\ConsoleLogger;
use InfamousQ\FlarumPhorumMigrationTool\Models\PhorumUserGroup;
use Symfony\Component\Console\Input\InputArgument;
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerAwareTrait;
use Psr\Log\NullLogger;

class PhorumViewCommand extends AbstractCommand implements LoggerAwareInterface {

	use LoggerAwareTrait;

	protected function configure() {
		$this
			->setName('phorum:view')
			->setDescription('View existing phorum installation, used for debugging if connection is working')
			// Phorum connection arguments
			->addArgument('host', InputArgument::REQUIRED, 'In which server is Phorum\'s database located? Commonly localhost')
			->addArgument('db_name', InputArgument::REQUIRED, 'What is the name of database where Phorum data is saved?')
			->addArgument('username', InputArgument::REQUIRED, 'Which username can be used to access Phorum database?')
			->addArgument('password', InputArgument::REQUIRED, 'Which password can be used to access Phorum database?')
			->addArgument('prefix', InputArgument::OPTIONAL, 'What is the table prefix set for Phorum installation? Not required, default is no prefix.')
			;
	}

	protected function fire() {

		$this->setLogger(new NullLogger());
		if ($this->output->isVerbose()) {
			$this->setLogger(new ConsoleLogger());
		}

		$phorum_db_host = $this->input->getArgument('host');
		$phorum_db_username = $this->input->getArgument('username');
		$phorum_db_password = $this->input->getArgument('password');
		$phorum_db_name = $this->input->getArgument('db_name');
		$phorum_db_prefix = $this->input->getArgument('prefix');

		$this->logger->debug("host: {$phorum_db_host}\nuser: {$phorum_db_username}\npass: {$phorum_db_password}\nDB: {$phorum_db_name}\nPrefix: {$phorum_db_prefix}");

		$connector = new Connector(
			$phorum_db_host,
			$phorum_db_name,
			$phorum_db_username,
			$phorum_db_password,
			$phorum_db_prefix);

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