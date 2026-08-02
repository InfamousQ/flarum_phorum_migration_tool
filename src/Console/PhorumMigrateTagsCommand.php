<?php

namespace InfamousQ\FlarumPhorumMigrationTool\Console;

/**
 * Step 4 of the migration pipeline: import Phorum forums as Flarum tags. Safe
 * to re-run - forums already linked via phorum_mapping are left untouched, so
 * tag edits (name, description, color, position, ...) made inside Flarum are
 * not clobbered. Only Phorum forums with no existing mapping get a new tag.
 */
class PhorumMigrateTagsCommand extends AbstractPhorumMigrateCommand {

	protected function configure() {
		$this
			->setName('phorum:migrate:tags')
			->setDescription('Migrate step 4/6: import Phorum forums as Flarum tags');
	}

	protected function fire() {
		$this->setUpLogger();
		$connector = $this->buildConnector();

		$tags = $this->importPhorumForumsAsTags($connector);

		$this->output->writeln(sprintf('Done. %d Phorum forum(s) mapped to Flarum tags.', count($tags)));
	}
}
