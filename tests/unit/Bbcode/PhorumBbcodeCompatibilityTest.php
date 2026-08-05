<?php

namespace InfamousQ\FlarumPhorumMigrationTool\Tests\unit\Bbcode;

use InfamousQ\FlarumPhorumMigrationTool\Bbcode\PhorumBbcodeCompatibility;
use PHPUnit\Framework\TestCase;

class PhorumBbcodeCompatibilityTest extends TestCase
{
    public function test_hr_is_replaced_with_markdown_thematic_break()
    {
        $this->assertSame(
            "before\n\n---\n\nafter",
            PhorumBbcodeCompatibility::transform('before[hr]after')
        );
    }

    public function test_hr_is_case_insensitive_and_tolerates_self_closing_syntax()
    {
        $this->assertSame(
            "before\n\n---\n\nafter",
            PhorumBbcodeCompatibility::transform('before[HR/]after')
        );
    }

    /**
     * @dataProvider sizeKeywordProvider
     */
    public function test_size_keywords_are_remapped_to_pixel_values(string $keyword, string $expected)
    {
        $this->assertSame(
            $expected,
            PhorumBbcodeCompatibility::transform("[size={$keyword}]text[/size]")
        );
    }

    public function sizeKeywordProvider() : array
    {
        return [
            'x-small' => ['x-small', '[size=8]text[/size]'],
            'small' => ['small', '[size=13]text[/size]'],
            'medium' => ['medium', 'text'],
            'large' => ['large', '[size=24]text[/size]'],
            'x-large' => ['x-large', '[size=32]text[/size]'],
        ];
    }

    public function test_size_keyword_is_remapped_when_nested_inside_other_tags()
    {
        $this->assertSame(
            '[size=32][b]"KELLO"[/b][/size]',
            PhorumBbcodeCompatibility::transform('[size=x-large][b]"KELLO"[/b][/size]')
        );
    }

    public function test_numeric_size_values_are_left_untouched()
    {
        $this->assertSame(
            '[size=20]text[/size]',
            PhorumBbcodeCompatibility::transform('[size=20]text[/size]')
        );
    }

    public function test_sub_and_sup_tags_are_stripped_but_text_is_kept()
    {
        $this->assertSame(
            'H2O and E=mc2',
            PhorumBbcodeCompatibility::transform('H[sub]2[/sub]O and E=mc[sup]2[/sup]')
        );
    }

    public function test_unrelated_bbcode_is_left_untouched()
    {
        $body = '[b]bold[/b] [url=https://example.com]link[/url] [quote=Name]hi[/quote]';

        $this->assertSame($body, PhorumBbcodeCompatibility::transform($body));
    }
}
