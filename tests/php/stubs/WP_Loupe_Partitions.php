<?php
/**
 * Test double for Loupe Search's static partition API (1.6.0+).
 */

namespace Soderlind\Plugin\LoupeSearch;

class WP_Loupe_Partitions {

	/** @var array<string,list<string>> Post type => partitions searched. Unlisted types are unpartitioned and fully searched. */
	public static array $searched = [];

	/** @var array<int,string> Post ID => partition. */
	public static array $post_partitions = [];

	public static function for_query( string $post_type ): array {
		return self::$searched[ $post_type ] ?? [ '' ];
	}

	public static function for_post( \WP_Post $post ): string {
		return self::$post_partitions[ $post->ID ] ?? '';
	}
}
