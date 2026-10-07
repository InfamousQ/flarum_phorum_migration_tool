<?php

namespace InfamousQ\FlarumPhorumMigrationTool\Tests\unit;

use InfamousQ\FlarumPhorumMigrationTool\Support\Username;
use PHPUnit\Framework\TestCase;

class UsernameTest extends TestCase
{
    public function test_a_valid_name_is_kept_as_is()
    {
        $this->assertSame('Alice_B-2', Username::fromPhorumName('Alice_B-2', 1));
    }

    public function test_invalid_characters_become_single_underscores_and_accents_are_transliterated()
    {
        $this->assertSame('Matti_Meikalainen', Username::fromPhorumName('Matti  Meikäläinen', 1));
        $this->assertSame('j_doe_example_com', Username::fromPhorumName('j.doe@example.com', 1));
        $this->assertSame('Bob', Username::fromPhorumName(' <Bob> ', 1));
    }

    public function test_a_long_name_is_cut_to_thirty_characters()
    {
        $this->assertSame(str_repeat('a', 25) . '_bbbb', Username::fromPhorumName(str_repeat('a', 25) . ' ' . str_repeat('b', 10), 1));
        // No dangling "_" where the cut lands
        $this->assertSame(str_repeat('a', 29), Username::fromPhorumName(str_repeat('a', 29) . ' bbb', 1));
    }

    public function test_a_name_with_nothing_usable_falls_back_to_the_phorum_user_id()
    {
        $this->assertSame('user_42', Username::fromPhorumName('', 42));
        $this->assertSame('user_42', Username::fromPhorumName('???', 42));
    }

    public function test_a_too_short_name_gets_the_phorum_user_id_appended()
    {
        $this->assertSame('Jo_42', Username::fromPhorumName('Jo', 42));
    }

    public function test_the_migrated_candidate_still_fits_in_thirty_characters()
    {
        $username = str_repeat('a', 30);

        $this->assertSame([$username, str_repeat('a', 21) . '_migrated'], Username::candidates($username));
    }
}
