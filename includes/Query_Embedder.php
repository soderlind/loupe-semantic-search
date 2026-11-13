<?php
/**
 * Query embeddings with caching, limits and a timeout.
 *
 * @package Soderlind\Plugin\LoupeSemanticSearch
 */

namespace Soderlind\Plugin\LoupeSemanticSearch;

defined( 'ABSPATH' ) || exit;

/**
 * Turns a search query into a vector.
 */
class Query_Embedder {

	/**
	 * Constructor.
	 *
	 * @param Ai_Adapter   $ai      AI plugin adapter.
	 * @param Rate_Limiter $limiter Rate limiter.
	 */
	public function __construct( private Ai_Adapter $ai, private Rate_Limiter $limiter ) {}

	/**
	 * Strip Loupe query operators (quotes, -exclusions, OR) and normalise whitespace and case.
	 *
	 * @param string $query Search query.
	 */
	public static function normalize( string $query ): string {
		$query = str_replace( [ '"', '“', '”' ], ' ', $query );
		$words = preg_split( '/\s+/u', trim( $query ) ) ?: [];
		$words = array_filter(
			$words,
			static fn ( string $word ): bool => '' !== $word && 'OR' !== $word && ! str_starts_with( $word, '-' )
		);
		return mb_strtolower( implode( ' ', $words ) );
	}

	/**
	 * Whether the query excludes terms with -term.
	 *
	 * @param string $query Search query.
	 */
	public static function has_exclusion( string $query ): bool {
		return 1 === preg_match( '/(^|\s)-[^\s-]/u', $query );
	}

	/**
	 * Embed a query.
	 *
	 * @param string                                                  $query  Search query.
	 * @param array{provider:string,model:string,dimensions:int|null} $target Embedding target.
	 * @return list<float>|\WP_Error
	 */
	public function embed( string $query, array $target ) {
		$text = self::normalize( $query );
		if ( '' === $text ) {
			return new \WP_Error( 'loupe_semantic_empty_query', __( 'The query is empty.', 'loupe-semantic-search' ) );
		}

		$key    = 'loupe_semantic_qv_' . md5( (string) wp_json_encode( [ $target, $text ] ) );
		$cached = get_transient( $key );
		if ( is_array( $cached ) && ! empty( $cached ) ) {
			return $cached;
		}

		if ( ! $this->limiter->acquire() ) {
			return new \WP_Error( 'loupe_semantic_rate_limited', __( 'The semantic search limit has been reached.', 'loupe-semantic-search' ) );
		}

		/**
		 * Filters the timeout, in seconds, for embedding a search query.
		 *
		 * @param float $timeout Timeout. Default 1.5.
		 */
		$timeout = max( 0.1, (float) apply_filters( 'loupe_semantic_query_timeout', 1.5 ) );
		$vector  = $this->ai->embed( $text, $target['provider'], $target['model'], $target['dimensions'], $timeout );
		if ( is_wp_error( $vector ) ) {
			return $vector;
		}

		/**
		 * Filters how long, in seconds, a query vector is cached.
		 *
		 * @param int $ttl Cache lifetime. Default 30 minutes.
		 */
		$ttl = (int) apply_filters( 'loupe_semantic_query_cache_ttl', 30 * MINUTE_IN_SECONDS );
		if ( $ttl > 0 ) {
			set_transient( $key, $vector, $ttl );
		}
		return $vector;
	}
}
