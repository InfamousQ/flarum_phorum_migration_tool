<?php

namespace InfamousQ\FlarumPhorumMigrationTool\Tests\unit;

use InfamousQ\FlarumPhorumMigrationTool\Model\PhorumMapping;
use PHPUnit\Framework\TestCase;

class PhorumMappingConstantsTest extends TestCase
{
    public function test_data_type_constants_are_unique()
    {
        $constants = [
            PhorumMapping::DATA_TYPE_USER_GROUP,
            PhorumMapping::DATA_TYPE_USER,
            PhorumMapping::DATA_TYPE_DISCUSSION,
            PhorumMapping::DATA_TYPE_MESSAGE,
            PhorumMapping::DATA_TYPE_TAG,
        ];

        $this->assertSame($constants, array_unique($constants));
    }
}
