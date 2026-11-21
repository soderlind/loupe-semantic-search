<?php
/**
 * Hybrid (keyword + semantic) ranking for Loupe Search.
 *
 * @package Soderlind\Plugin\LoupeSemanticSearch
 */

namespace Soderlind\Plugin\LoupeSemanticSearch;

defined( 'ABSPATH' ) || exit;

/**
 * Fuses Loupe's keyword hits with semantic matches through two Loupe filters:
 * `loupe_search_order_results` (front-end search and the loupe-search/search ability)
 * and `loupe_search_advanced_hits` (the REST search endpoint).
 *
 * Any failure (no model, provider down, rate limited) leaves the keyword hits as they are.
 */
class Hybrid_Search {

	/**
	 * Constructor.
	 *
	 * @param Retriever $retriever Semantic retriever.
	 */
	public function __construct( private Retriever $retriever ) {}

	/**
	 * Hook into Loupe Search.
	 */
	public function register(): void {
		add_filter( 'loupe_search_order_results', [ $this, 'filter_order_results' ], 20, 4 );
		add_filter( 'loupe_search_advanced_hits', [ $this, 'filter_advanced_hits' ], 20, 3 );
	}

	/**
	 * Front-end search and the loupe-search/search ability.
	 *
	 * @param mixed        $hits       Keyword hits.
	 * @param string       $query      Search query.
	 * @param list<string> $post_types Post types searched.
	 * @param array        $sort       Field sort; empty for relevance.
	 * @return mixed
	 */
	public function filter_order_results( $hits, $query = '', $post_types = [], $sort = [] ) {
		if ( ! is_array( $hits ) || ! Settings::get( 'hybrid_frontend' ) || self::already_hybrid( $hits ) ) {
			return $hits;
		}
		$post_types = ! empty( $post_types ) ? array_values( (array) $post_types ) : array_values( array_unique( array_column( $hits, 'post_type' ) ) );
		$fused      = $this->hybridize( $hits, (string) $query, $post_types, null );

		$specs = [];
		foreach ( is_array( $sort ) ? $sort : [] as $entry ) {
			if ( is_array( $entry ) && ! empty( $entry['field'] ) ) {
				$specs[] = [ (string) $entry['field'], (string) ( $entry['direction'] ?? '' ) ];
			}
		}
		return $fused === $hits ? $hits : self::sort_by_fields( $fused, $specs );
	}

	/**
	 * REST search endpoint.
	 *
	 * @param mixed  $hits    Keyword hits, sorted.
	 * @param string $query   Search query.
	 * @param array  $context Context from Loupe Search.
	 * @return mixed
	 */
	public function filter_advanced_hits( $hits, $query = '', $context = [] ) {
		if ( ! is_array( $hits ) || ! Settings::get( 'hybrid_rest' ) || self::already_hybrid( $hits ) ) {
			return $hits;
		}
		$context = is_array( $context ) ? $context : [];
		$sort    = array_map( 'strval', (array) ( $context['sort'] ?? [] ) );
		if ( false === ( $context['payload']['semantic'] ?? null ) || ! self::is_fusable_sort( $sort ) ) {
			return $hits;
		}
		$matches_filter = isset( $context['matches_filter'] ) && is_callable( $context['matches_filter'] ) ? $context['matches_filter'] : null;
		if ( '' === (string) ( $context['filter'] ?? '' ) ) {
			$matches_filter = null;
		}
		$fused = $this->hybridize( $hits, (string) $query, array_values( (array) ( $context['post_types'] ?? [] ) ), $matches_filter, (int) ( $context['limit'] ?? 0 ) );
		if ( $fused === $hits || self::is_relevance_sort( $sort ) ) {
			return $fused;
		}

		$specs = [];
		foreach ( $sort as $entry ) {
			$colon   = strrpos( $entry, ':' );
			$specs[] = false === $colon ? [ $entry, '' ] : [ substr( $entry, 0, $colon ), substr( $entry, $colon + 1 ) ];
		}
		return self::sort_by_fields( $fused, $specs );
	}

	/**
	 * Hybrid ranking regardless of the surface toggles (used by the loupe-semantic/search ability).
	 *
	 * @param list<array<string,mixed>> $hits       Keyword hits.
	 * @param string                    $query      Search query.
	 * @param list<string>              $post_types Post types searched.
	 * @return list<array<string,mixed>>
	 */
	public function apply( array $hits, string $query, array $post_types ): array {
		return self::already_hybrid( $hits ) ? $hits : $this->hybridize( $hits, $query, $post_types, null );
	}

	/**
	 * Whether Loupe sort strings keep relevance order.
	 *
	 * @param list<string> $sort Loupe sort strings.
	 */
	public static function is_relevance_sort( array $sort ): bool {
		foreach ( $sort as $entry ) {
			if ( '_relevance:desc' !== $entry ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * Whether hybrid results can honour the Loupe sort: relevance or field sorts, but not
	 * relevance ascending or distance (semantic-only hits have no coordinates).
	 *
	 * @param list<string> $sort Loupe sort strings.
	 */
	public static function is_fusable_sort( array $sort ): bool {
		foreach ( $sort as $entry ) {
			if ( str_starts_with( $entry, '_geoPoint(' ) || ( str_starts_with( $entry, '_relevance:' ) && '_relevance:desc' !== $entry ) ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * Order fused hits by fields, so a field sort shows the same results as relevance.
	 * Semantic-only hits get their field values from the post, as Loupe Search indexes them.
	 *
	 * @param list<array<string,mixed>>  $hits  Fused hits, best first.
	 * @param list<array{string,string}> $specs Field/direction pairs; `_relevance` means fused score.
	 * @return list<array<string,mixed>>
	 */
	public static function sort_by_fields( array $hits, array $specs ): array {
		$specs = array_values( array_filter( $specs, static fn ( $s ) => '' !== $s[0] ) );
		if ( empty( $specs ) ) {
			return $hits;
		}

		foreach ( $hits as &$hit ) {
			foreach ( $specs as [ $field ] ) {
				if ( '_relevance' !== $field && ! array_key_exists( $field, $hit ) ) {
					$hit[ $field ] = self::post_field( (int) $hit['id'], $field );
				}
			}
		}
		unset( $hit );

		$rank = array_flip( array_keys( $hits ) );
		uksort(
			$hits,
			static function ( $ka, $kb ) use ( $hits, $specs, $rank ): int {
				foreach ( $specs as [ $field, $direction ] ) {
					$key = '_relevance' === $field ? '_score' : $field;
					$a   = $hits[ $ka ][ $key ] ?? null;
					$b   = $hits[ $kb ][ $key ] ?? null;
					$cmp = self::compare_values( $a, $b );
					if ( null === $cmp ) {
						// Missing values sort last in either direction.
						return ( null === $a || '' === $a ) ? 1 : -1;
					}
					if ( 0 !== $cmp ) {
						$asc = '_relevance' === $field ? false : 'asc' === strtolower( $direction );
						return $asc ? $cmp : -$cmp;
					}
				}
				return $rank[ $ka ] <=> $rank[ $kb ];
			}
		);
		return array_values( $hits );
	}

	/**
	 * Compare two field values; null when exactly one is missing.
	 *
	 * @param mixed $a Value.
	 * @param mixed $b Value.
	 */
	private static function compare_values( $a, $b ): ?int {
		$a = ( '' === $a ) ? null : $a;
		$b = ( '' === $b ) ? null : $b;
		if ( null === $a && null === $b ) {
			return 0;
		}
		if ( null === $a || null === $b ) {
			return null;
		}
		if ( is_numeric( $a ) && is_numeric( $b ) ) {
			return (float) $a <=> (float) $b;
		}
		if ( is_scalar( $a ) && is_scalar( $b ) ) {
			return strnatcasecmp( (string) $a, (string) $b );
		}
		return 0;
	}

	/**
	 * A post's value for a Loupe field, as Loupe Search indexes it
	 * (`WP_Loupe_Indexer::prepare_document()`): a post property passed through
	 * `loupe_search_field_{$field}`, else post meta; then normalized.
	 *
	 * @param int    $post_id Post ID.
	 * @param string $field   Loupe field name.
	 * @return mixed
	 */
	private static function post_field( int $post_id, string $field ) {
		$post = get_post( $post_id );
		if ( ! $post instanceof \WP_Post ) {
			return null;
		}
		if ( property_exists( $post, $field ) ) {
			$value = apply_filters( "loupe_search_field_{$field}", $post->{$field} );
		} else {
			$value = get_post_meta( $post_id, $field, true );
		}
		return self::normalize_field_value( $value );
	}

	/**
	 * Normalize a field value like Loupe Search's indexer: empty values and
	 * whitespace-only strings become null, strings are trimmed.
	 *
	 * @param mixed $value Raw value.
	 * @return mixed
	 */
	private static function normalize_field_value( $value ) {
		if ( null === $value || '' === $value || [] === $value || false === $value ) {
			return null;
		}
		if ( is_numeric( $value ) ) {
			return $value;
		}
		if ( is_string( $value ) ) {
			$value = trim( $value );
			return '' === $value ? null : $value;
		}
		return $value;
	}

	/**
	 * Fuse keyword hits with semantic matches.
	 *
	 * @param list<array<string,mixed>> $hits           Keyword hits, best first.
	 * @param list<array<string,mixed>> $semantic       Semantic matches (post_id, post_type, score), best first.
	 * @param float                     $w_keyword      Keyword weight.
	 * @param float                     $w_semantic     Semantic weight.
	 * @param bool                      $rerank_only    Only reorder keyword hits; add none.
	 * @return list<array<string,mixed>>
	 */
	public static function merge( array $hits, array $semantic, float $w_keyword, float $w_semantic, bool $rerank_only ): array {
		$by_id = [];
		foreach ( $hits as $hit ) {
			if ( is_array( $hit ) && isset( $hit['id'] ) && ! isset( $by_id[ (int) $hit['id'] ] ) ) {
				$by_id[ (int) $hit['id'] ] = $hit;
			}
		}

		$similarity = [];
		$types      = [];
		foreach ( $semantic as $match ) {
			$id = (int) $match['post_id'];
			if ( $rerank_only && ! isset( $by_id[ $id ] ) ) {
				continue;
			}
			$similarity[ $id ] ??= (float) $match['score'];
			$types[ $id ]      ??= (string) $match['post_type'];
		}

		$merged = [];
		foreach ( Fusion::rrf( array_keys( $by_id ), array_keys( $similarity ), $w_keyword, $w_semantic ) as $id => $score ) {
			$hit = $by_id[ $id ] ?? [ 'id' => $id, 'post_type' => $types[ $id ] ];
			if ( isset( $hit['_score'] ) ) {
				$hit['_keywordScore'] = $hit['_score'];
			}
			$hit['_score'] = $score;
			if ( isset( $similarity[ $id ] ) ) {
				$hit['_semantic'] = $similarity[ $id ];
			}
			$hit['_hybrid'] = true;
			$merged[]       = $hit;
		}
		return $merged;
	}

	/**
	 * Run semantic retrieval and merge, falling back to the keyword hits on any error.
	 *
	 * @param list<array<string,mixed>> $hits           Keyword hits.
	 * @param string                    $query          Search query.
	 * @param list<string>              $post_types     Post types searched.
	 * @param callable|null             $matches_filter Narrows semantic-only IDs to the request's filter.
	 * @param int                       $limit          Hits needed by the caller.
	 * @return list<array<string,mixed>>
	 */
	private function hybridize( array $hits, string $query, array $post_types, ?callable $matches_filter, int $limit = 0 ): array {
		if ( '' === Query_Embedder::normalize( $query ) || empty( $post_types ) || ! Settings::has_model() ) {
			return $hits;
		}

		/**
		 * Filters how many semantic matches are fused with the keyword hits.
		 *
		 * @param int    $count Default 50 (at least the number of hits requested).
		 * @param string $query Search query.
		 */
		$count    = max( 1, $limit, (int) apply_filters( 'loupe_semantic_candidates', 50, $query ) );
		$semantic = $this->retriever->search( $query, $count, $post_types );
		if ( is_wp_error( $semantic ) || empty( $semantic ) ) {
			return $hits;
		}

		$rerank_only = Query_Embedder::has_exclusion( $query );
		if ( ! $rerank_only && null !== $matches_filter ) {
			$keyword_ids = array_flip( array_map( 'intval', array_column( $hits, 'id' ) ) );
			$extra_ids   = array_values( array_diff( array_map( static fn ( $m ) => (int) $m['post_id'], $semantic ), array_keys( $keyword_ids ) ) );
			try {
				$passing = array_flip( array_map( 'intval', (array) $matches_filter( $extra_ids ) ) );
			} catch ( \Throwable $e ) {
				return $hits;
			}
			$semantic = array_values(
				array_filter(
					$semantic,
					static fn ( $m ) => isset( $keyword_ids[ (int) $m['post_id'] ] ) || isset( $passing[ (int) $m['post_id'] ] )
				)
			);
		}

		return self::merge( $hits, $semantic, (float) Settings::get( 'weight_keyword' ), (float) Settings::get( 'weight_semantic' ), $rerank_only );
	}

	/**
	 * Whether the hits were already fused (filters can run twice for one request).
	 *
	 * @param array $hits Hits.
	 */
	private static function already_hybrid( array $hits ): bool {
		$first = reset( $hits );
		return is_array( $first ) && ! empty( $first['_hybrid'] );
	}
}
