<?php
/**
 * Semantic retrieval.
 *
 * @package Soderlind\Plugin\LoupeSemanticSearch
 */

namespace Soderlind\Plugin\LoupeSemanticSearch;

defined( 'ABSPATH' ) || exit;

/**
 * Query → vector → nearest posts → passages.
 */
class Retriever {

	/**
	 * Constructor.
	 *
	 * @param Query_Embedder  $embedder Query embedder.
	 * @param Vector_Store    $store    Vector store.
	 * @param Passage_Builder $passages Passage builder.
	 */
	public function __construct(
		private Query_Embedder $embedder,
		private Vector_Store $store,
		private Passage_Builder $passages
	) {}

	/**
	 * The nearest posts to a query, best first, one entry per post.
	 *
	 * Every post passes Loupe Search's indexability rules, so excluded posts never
	 * surface even before the ai plugin has removed their vectors.
	 *
	 * @param string       $query       Search query.
	 * @param int          $limit       Maximum posts.
	 * @param list<string> $post_types  Post types to search.
	 * @param bool         $public_only Only posts the public can view.
	 * @return list<array{post_id:int,post_type:string,chunk_index:int,score:float,hash:string}>|\WP_Error
	 */
	public function search( string $query, int $limit, array $post_types, bool $public_only = false ) {
		if ( ! Settings::has_model() ) {
			return new \WP_Error( 'loupe_semantic_no_model', __( 'No embedding model is selected.', 'loupe-semantic-search' ) );
		}
		if ( $limit < 1 || empty( $post_types ) ) {
			return [];
		}

		$target = Settings::target();
		$vector = $this->embedder->embed( $query, $target );
		if ( is_wp_error( $vector ) ) {
			return $vector;
		}

		// Ask for more than needed: some matches are dropped by the checks below.
		$matches = $this->store->search( $vector, $target, $limit * 3 + 10, $post_types, (float) Settings::get( 'min_similarity' ) );
		$allowed = array_flip( $post_types );
		$scopes  = self::partition_scopes( $post_types );
		$out     = [];
		foreach ( $matches as $match ) {
			$id = (int) $match['post_id'];
			if ( ! isset( $allowed[ $match['post_type'] ] ) || ! \loupe_search_is_post_indexable( $id ) ) {
				continue;
			}
			if ( isset( $scopes[ $match['post_type'] ] ) && ! self::in_scope( $id, $scopes[ $match['post_type'] ] ) ) {
				continue;
			}
			if ( $public_only && ! \Soderlind\Plugin\LoupeSearch\WP_Loupe_Utils::is_publicly_viewable_post( $id ) ) {
				continue;
			}
			$out[] = $match;
			if ( count( $out ) >= $limit ) {
				break;
			}
		}
		return $out;
	}

	/**
	 * Partitions each partitioned post type's search covers, e.g. only the current
	 * language with Loupe Search Multilingual (Loupe Search 1.6.0+).
	 *
	 * @param list<string> $post_types Post types.
	 * @return array<string,list<string>> Post type => partitions searched; unpartitioned types are left out.
	 */
	private static function partition_scopes( array $post_types ): array {
		$partitions = \Soderlind\Plugin\LoupeSearch\WP_Loupe_Partitions::class;
		if ( ! class_exists( $partitions ) ) {
			return [];
		}
		$scopes = [];
		foreach ( $post_types as $post_type ) {
			if ( $partitions::is_partitioned( $post_type ) ) {
				$scopes[ $post_type ] = $partitions::for_query( $post_type );
			}
		}
		return $scopes;
	}

	/**
	 * Whether a post's partition is one of those searched, so semantic hits match keyword hits.
	 *
	 * @param int          $post_id  Post ID.
	 * @param list<string> $searched Partitions searched.
	 */
	private static function in_scope( int $post_id, array $searched ): bool {
		$post = get_post( $post_id );
		return $post instanceof \WP_Post
			&& in_array( \Soderlind\Plugin\LoupeSearch\WP_Loupe_Partitions::for_post( $post ), $searched, true );
	}

	/**
	 * Passages for RAG: the best chunk of each matching post with its text.
	 *
	 * @param string       $query       Search query.
	 * @param int          $limit       Maximum passages.
	 * @param list<string> $post_types  Post types to search.
	 * @param bool         $public_only Only posts the public can view.
	 * @return array{passages:list<array<string,mixed>>,model:array<string,mixed>}|\WP_Error
	 */
	public function passages( string $query, int $limit, array $post_types, bool $public_only = true ) {
		$matches = $this->search( $query, $limit, $post_types, $public_only );
		if ( is_wp_error( $matches ) ) {
			return $matches;
		}

		$target   = Settings::target();
		$passages = [];
		foreach ( $matches as $match ) {
			$post = get_post( $match['post_id'] );
			if ( ! $post instanceof \WP_Post ) {
				continue;
			}
			$passage    = $this->passages->build( $post->ID, (int) $match['chunk_index'], (string) $match['hash'], $target['dimensions'] );
			$passages[] = [
				'post_id'     => $post->ID,
				'title'       => get_the_title( $post ),
				'url'         => (string) get_permalink( $post ),
				'post_type'   => $post->post_type,
				'post_date'   => (string) get_the_date( 'c', $post ),
				'chunk_index' => (int) $match['chunk_index'],
				'text'        => $passage['text'],
				'score'       => round( (float) $match['score'], 4 ),
				'stale'       => $passage['stale'],
			];
		}

		return [
			'passages' => $passages,
			'model'    => $target,
		];
	}
}
