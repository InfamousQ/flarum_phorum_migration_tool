<?php

namespace InfamousQ\FlarumPhorumMigrationTool\Tests\integration\Console;

use Flarum\Settings\SettingsRepositoryInterface;
use Flarum\Tags\Tag;
use InfamousQ\FlarumPhorumMigrationTool\Model\PhorumMapping;
use InfamousQ\FlarumPhorumMigrationTool\Tests\integration\Support\FakeConnector;
use InfamousQ\FlarumPhorumMigrationTool\Tests\integration\Support\TestablePhorumMigrateTagsCommand;
use InfamousQ\FlarumPhorumMigrationTool\Tests\integration\TestCase;

class PhorumMigrateTagsCommandTest extends TestCase
{
    protected function command(): TestablePhorumMigrateTagsCommand
    {
        return new TestablePhorumMigrateTagsCommand(
            $this->app()->getContainer()->make(SettingsRepositoryInterface::class)
        );
    }

    /**
     * @test
     */
    public function it_creates_a_visible_tag_for_each_active_phorum_forum()
    {
        $connector = new FakeConnector();
        $connector->forums = [
            ['forum_id' => 10, 'name' => 'Phorum General', 'description' => 'Phorum general discussion', 'parent_id' => 0, 'display_order' => 1, 'pub_perms' => 1, 'reg_perms' => 15],
        ];

        $tags = $this->command()->runStep($connector);

        $this->assertSame('Phorum General', $tags[10]->name);
        $this->assertSame('phorum-general', $tags[10]->slug);
        $this->assertFalse((bool) $tags[10]->is_hidden);
        $this->assertSame($tags[10]->id, PhorumMapping::getFlarumIdForPhorumId(PhorumMapping::DATA_TYPE_TAG, 10));
    }

    /**
     * @test
     */
    public function it_does_not_overwrite_an_already_mapped_tag_when_re_run_as_a_separate_command_instance()
    {
        $connector = new FakeConnector();
        $connector->forums = [
            ['forum_id' => 10, 'name' => 'Phorum General', 'description' => '', 'parent_id' => 0, 'display_order' => 1, 'pub_perms' => 1, 'reg_perms' => 15],
        ];
        $first = $this->command()->runStep($connector);

        // Simulate the tag being renamed/hidden inside Flarum after the first
        // migrate:tags run.
        $first[10]->name = 'General Discussion';
        $first[10]->is_hidden = true;
        $first[10]->save();

        $second = $this->command()->runStep($connector);

        $this->assertSame($first[10]->id, $second[10]->id);
        $this->assertSame(1, Tag::query()->where('id', $first[10]->id)->count());
        $reloaded = Tag::find($first[10]->id);
        $this->assertSame('General Discussion', $reloaded->name);
        $this->assertTrue((bool) $reloaded->is_hidden);
    }

    /**
     * @test
     */
    public function it_recreates_a_mapping_whose_flarum_tag_was_deleted()
    {
        $connector = new FakeConnector();
        $connector->forums = [
            ['forum_id' => 10, 'name' => 'Phorum General', 'description' => '', 'parent_id' => 0, 'display_order' => 1, 'pub_perms' => 1, 'reg_perms' => 15],
        ];
        $first = $this->command()->runStep($connector);
        $deletedId = $first[10]->id;
        $first[10]->delete();

        $second = $this->command()->runStep($connector);

        $this->assertNotSame($deletedId, $second[10]->id);
        $this->assertSame($second[10]->id, PhorumMapping::getFlarumIdForPhorumId(PhorumMapping::DATA_TYPE_TAG, 10));
    }

    /**
     * @test
     */
    public function it_gives_forums_with_the_same_name_unique_url_safe_slugs()
    {
        $connector = new FakeConnector();
        $connector->forums = [
            ['forum_id' => 10, 'name' => 'Yleinen / Äänestys', 'description' => '', 'parent_id' => 0, 'display_order' => 1, 'pub_perms' => 1, 'reg_perms' => 15],
            ['forum_id' => 11, 'name' => 'Yleinen / Äänestys', 'description' => '', 'parent_id' => 0, 'display_order' => 2, 'pub_perms' => 1, 'reg_perms' => 15],
            ['forum_id' => 12, 'name' => 'Yleinen / Äänestys', 'description' => '', 'parent_id' => 0, 'display_order' => 3, 'pub_perms' => 1, 'reg_perms' => 15],
        ];

        $tags = $this->command()->runStep($connector);

        $this->assertSame('yleinen-aanestys', $tags[10]->slug);
        $this->assertSame('yleinen-aanestys-2', $tags[11]->slug);
        $this->assertSame('yleinen-aanestys-3', $tags[12]->slug);
    }

    /**
     * @test
     */
    public function it_falls_back_to_the_forum_id_when_the_name_has_no_sluggable_characters()
    {
        $connector = new FakeConnector();
        $connector->forums = [
            ['forum_id' => 10, 'name' => '???', 'description' => '', 'parent_id' => 0, 'display_order' => 1, 'pub_perms' => 1, 'reg_perms' => 15],
        ];

        $tags = $this->command()->runStep($connector);

        $this->assertSame('forum-10', $tags[10]->slug);
    }
}
