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

		$scopes     = array_filter( self::partition_scopes( $post_types ), static fn ( ?array $scope ): bool => [] !== $scope );
		$post_types = array_keys( $scopes );
		if ( empty( $post_types ) ) {
			return [];
		}

		$target = Settings::target();
		$vector = $this->embedder->embed( $query, $target );
		if ( is_wp_error( $vector ) ) {
			return $vector;
		}

		// Ask for more than needed, since some matches are dropped by the checks below,
		// and ask again for more until enough are kept or the store has no more.
		$k       = $limit * 3 + 10;
		$checked = 0;
		$out     = [];
		while ( true ) {
			$matches = $this->store->search( $vector, $target, $k, $post_types, (float) Settings::get( 'min_similarity' ) );
			foreach ( array_slice( $matches, $checked ) as $match ) {
				if ( $this->keep( $match, $scopes, $public_only ) ) {
					$out[ (int) $match['post_id'] ] = $match;
					if ( count( $out ) >= $limit ) {
						return array_values( $out );
					}
				}
			}
			if ( count( $matches ) < $k ) {
				return array_values( $out );
			}
			$checked = count( $matches );
			$k      *= 4;
		}
	}

	/**
	 * Whether a store match may be returned.
	 *
	 * @param array{post_id:int,post_type:string} $match       Store match.
	 * @param array<string,?list<string>>         $scopes      Post type => partitions searched, null for all.
	 * @param bool                                $public_only Only posts the public can view.
	 */
	private function keep( array $match, array $scopes, bool $public_only ): bool {
		$id = (int) $match['post_id'];
		if ( ! array_key_exists( $match['post_type'], $scopes ) || ! \loupe_search_is_post_indexable( $id ) ) {
			return false;
		}
		$scope = $scopes[ $match['post_type'] ];
		if ( null !== $scope && ! self::in_scope( $id, $scope ) ) {
			return false;
		}
		return ! $public_only || \Soderlind\Plugin\LoupeSearch\WP_Loupe_Utils::is_publicly_viewable_post( $id );
	}

	/**
	 * Partitions each post type's search covers, e.g. only the current language
	 * with Loupe Search Multilingual (Loupe Search 1.6.0+), so semantic hits match keyword hits.
	 *
	 * @param list<string> $post_types Post types.
	 * @return array<string,?list<string>> Post type => partitions searched; null when unrestricted,
	 *                                     an empty list when the search skips the type.
	 */
	private static function partition_scopes( array $post_types ): array {
		$partitions = \Soderlind\Plugin\LoupeSearch\WP_Loupe_Partitions::class;
		$scopes     = [];
		foreach ( $post_types as $post_type ) {
			$scope                = class_exists( $partitions ) ? $partitions::for_query( $post_type ) : [ '' ];
			$scopes[ $post_type ] = [ '' ] === $scope ? null : $scope;
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
