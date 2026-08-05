<?php

namespace InfamousQ\FlarumPhorumMigrationTool\Bbcode;

/**
 * Rewrites Phorum-specific BBCode that Flarum's `flarum/bbcode` extension
 * (backed by s9e's TextFormatter) either doesn't register at all, or
 * registers with an incompatible value syntax, into a form Flarum already
 * renders correctly. Applied to a post body once, at migration import time.
 *
 * See the migration tool's issue writeup on literal/unrendered BBCode for the
 * full investigation and the tag-by-tag rationale behind each transform.
 */
class PhorumBbcodeCompatibility {

	/**
	 * Phorum's phpBB-style [size=keyword] values, mapped to s9e's required
	 * numeric pixel value (8-36). 'medium' is Flarum's implicit default size,
	 * so it maps to null and the wrapping tag is stripped instead of written
	 * out as [size=16].
	 */
	private const SIZE_KEYWORD_TO_PX = [
		'x-small' => 8,
		'small' => 13,
		'medium' => null,
		'large' => 24,
		'x-large' => 32,
	];

	/**
	 * @param string $body Raw Phorum post body, unmodified
	 * @return string Body with Phorum-only BBCode rewritten into tags/syntax
	 *   Flarum's bbcode+markdown formatter pipeline already renders
	 */
	public static function transform(string $body) : string {
		$body = static::replaceHr($body);
		$body = static::replaceSizeKeywords($body);
		$body = static::stripSubSup($body);

		return $body;
	}

	/**
	 * [hr] has no equivalent among Flarum's registered bbcode tags. Replaced
	 * with a Markdown thematic break, which Flarum's markdown extension
	 * (a required dependency of this extension) renders as <hr>.
	 */
	private static function replaceHr(string $body) : string {
		return preg_replace('/\[hr\s*\/?\]/i', "\n\n---\n\n", $body);
	}

	/**
	 * [size=large] etc. use phpBB-style keyword values. s9e's [size] tag
	 * requires a numeric pixel value in the range 8-36, so keyword values
	 * fall through unrecognized and render as literal text. Remaps each
	 * keyword to a pixel value per SIZE_KEYWORD_TO_PX.
	 */
	private static function replaceSizeKeywords(string $body) : string {
		$keywords = implode('|', array_map('preg_quote', array_keys(static::SIZE_KEYWORD_TO_PX)));

		return preg_replace_callback(
			'/\[size=(' . $keywords . ')\](.*?)\[\/size\]/is',
			function (array $matches) {
				$px = static::SIZE_KEYWORD_TO_PX[strtolower($matches[1])];
				if (null === $px) {
					return $matches[2];
				}

				return "[size={$px}]{$matches[2]}[/size]";
			},
			$body
		);
	}

	/**
	 * [sub]/[sup] have no equivalent among Flarum's registered bbcode tags.
	 * Tags are stripped, keeping the inner text unformatted rather than
	 * left as literal, unrendered tags.
	 */
	private static function stripSubSup(string $body) : string {
		return preg_replace('/\[(sub|sup)\](.*?)\[\/\1\]/is', '$2', $body);
	}

}
