<?php

namespace InfamousQ\FlarumPhorumMigrationTool\Tests\integration\Model;

use InfamousQ\FlarumPhorumMigrationTool\Model\PhorumMapping;
use InfamousQ\FlarumPhorumMigrationTool\Tests\integration\TestCase;

class PhorumMappingTest extends TestCase
{
    /**
     * @test
     */
    public function mapping_table_starts_empty()
    {
        $this->assertFalse(PhorumMapping::doesMappingTableHaveData());
    }

    /**
     * @test
     */
    public function it_sets_and_retrieves_a_flarum_id_for_a_phorum_id()
    {
        PhorumMapping::setFlarumIdForPhorumId(PhorumMapping::DATA_TYPE_USER, 42, 7);

        $this->assertSame(7, PhorumMapping::getFlarumIdForPhorumId(PhorumMapping::DATA_TYPE_USER, 42));
        $this->assertTrue(PhorumMapping::doesMappingTableHaveData());
    }

    /**
     * @test
     */
    public function it_returns_null_when_no_mapping_exists()
    {
        $this->assertNull(PhorumMapping::getFlarumIdForPhorumId(PhorumMapping::DATA_TYPE_DISCUSSION, 999));
    }
}
