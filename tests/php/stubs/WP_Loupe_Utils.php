<?php
/**
 * Test double for Loupe Search's utilities, which are static and cannot be stubbed by Brain Monkey.
 */

namespace Soderlind\Plugin\LoupeSearch;

class WP_Loupe_Utils {

	/** @var list<int> Post IDs the public may not view. */
	public static array $private_ids = [];

	public static function is_publicly_viewable_post( int $post_id ): bool {
		return ! in_array( $post_id, self::$private_ids, true );
	}
}
