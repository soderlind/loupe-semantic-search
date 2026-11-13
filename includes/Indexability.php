<?php
/**
 * Keeps the ai plugin's embedding sync in line with Loupe Search.
 *
 * @package Soderlind\Plugin\LoupeSemanticSearch
 */

namespace Soderlind\Plugin\LoupeSemanticSearch;

defined( 'ABSPATH' ) || exit;

/**
 * Posts Loupe Search would not index (excluded, wrong status) are not embedded.
 *
 * Applies only to post types embedded for this add-on alone: when another consumer
 * also embeds a post type, its own rules win and excluded posts are instead dropped
 * at query time.
 */
class Indexability {

	/**
	 * Constructor.
	 *
	 * @param Ai_Adapter $ai AI plugin adapter.
	 */
	public function __construct( private Ai_Adapter $ai ) {}

	/**
	 * Hook into the ai plugin.
	 */
	public function register(): void {
		add_filter( 'wpai_embedding_sync_is_indexable', [ $this, 'filter' ], 10, 3 );
	}

	/**
	 * Filter callback.
	 *
	 * @param mixed  $indexable   Whether the ai plugin would embed the object.
	 * @param string $object_type Object type.
	 * @param int    $object_id   Object ID.
	 * @return mixed
	 */
	public function filter( $indexable, $object_type = '', $object_id = 0 ) {
		if ( ! $indexable || 'post' !== $object_type ) {
			return $indexable;
		}
		$subtype = get_post_type( (int) $object_id );
		if ( ! $subtype || ! $this->ai->covers( $subtype ) || $this->ai->other_consumer_covers( $subtype ) ) {
			return $indexable;
		}
		return \loupe_search_is_post_indexable( (int) $object_id );
	}
}
