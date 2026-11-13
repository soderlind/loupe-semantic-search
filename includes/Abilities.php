<?php
/**
 * Abilities API registration.
 *
 * @package Soderlind\Plugin\LoupeSemanticSearch
 */

namespace Soderlind\Plugin\LoupeSemanticSearch;

use Soderlind\Plugin\LoupeSearch\WP_Loupe_DB;
use Soderlind\Plugin\LoupeSearch\WP_Loupe_Search_Engine;
use Soderlind\Plugin\LoupeSearch\WP_Loupe_Utils;

defined( 'ABSPATH' ) || exit;

/**
 * `loupe-semantic/retrieve` (RAG passages) and `loupe-semantic/search` (hybrid search).
 */
class Abilities {

	public const CATEGORY = 'loupe-semantic';

	/**
	 * Constructor.
	 *
	 * @param Retriever     $retriever Semantic retriever.
	 * @param Hybrid_Search $hybrid    Hybrid ranking.
	 */
	public function __construct( private Retriever $retriever, private Hybrid_Search $hybrid ) {}

	/**
	 * Hook into the Abilities API.
	 */
	public function register(): void {
		add_action( 'wp_abilities_api_categories_init', [ $this, 'register_category' ] );
		add_action( 'wp_abilities_api_init', [ $this, 'register_abilities' ] );
	}

	/**
	 * Register the ability category.
	 */
	public function register_category(): void {
		wp_register_ability_category(
			self::CATEGORY,
			[
				'label'       => __( 'Loupe Semantic Search', 'loupe-semantic-search' ),
				'description' => __( 'Semantic retrieval and hybrid search over Loupe Search content.', 'loupe-semantic-search' ),
			]
		);
	}

	/**
	 * Register the abilities.
	 */
	public function register_abilities(): void {
		$post_types = [
			'type'        => [ 'string', 'array' ],
			'items'       => [ 'type' => 'string' ],
			'description' => __( 'Post type or list of post types. Defaults to all public indexed post types.', 'loupe-semantic-search' ),
		];

		wp_register_ability(
			self::CATEGORY . '/retrieve',
			[
				'label'               => __( 'Retrieve Passages', 'loupe-semantic-search' ),
				'description'         => __( 'Find the passages of site content most relevant to a question, by meaning rather than exact words. Returns passage text with source title and URL, for answering with citations.', 'loupe-semantic-search' ),
				'category'            => self::CATEGORY,
				'input_schema'        => [
					'type'       => 'object',
					'required'   => [ 'query' ],
					'properties' => [
						'query'     => [
							'type'        => 'string',
							'description' => __( 'A question or description of the information needed.', 'loupe-semantic-search' ),
						],
						'limit'     => [
							'type'        => 'integer',
							'description' => __( 'Number of passages. Default: 5. Max: 20.', 'loupe-semantic-search' ),
							'default'     => 5,
							'minimum'     => 1,
							'maximum'     => 20,
						],
						'post_type' => $post_types,
					],
				],
				'output_schema'       => [
					'type'       => 'object',
					'properties' => [
						'passages' => [
							'type'  => 'array',
							'items' => [
								'type'       => 'object',
								'properties' => [
									'post_id'     => [ 'type' => 'integer' ],
									'title'       => [ 'type' => 'string' ],
									'url'         => [ 'type' => 'string' ],
									'post_type'   => [ 'type' => 'string' ],
									'post_date'   => [ 'type' => 'string' ],
									'chunk_index' => [ 'type' => 'integer' ],
									'text'        => [ 'type' => 'string', 'description' => __( 'Passage text.', 'loupe-semantic-search' ) ],
									'score'       => [ 'type' => 'number', 'description' => __( 'Cosine similarity, 0 to 1.', 'loupe-semantic-search' ) ],
									'stale'       => [ 'type' => 'boolean', 'description' => __( 'True when the post changed since it was embedded and the text is an excerpt.', 'loupe-semantic-search' ) ],
								],
							],
						],
						'model'    => [
							'type'       => 'object',
							'properties' => [
								'provider'   => [ 'type' => 'string' ],
								'model'      => [ 'type' => 'string' ],
								'dimensions' => [ 'type' => [ 'integer', 'null' ] ],
							],
						],
					],
				],
				'execute_callback'    => [ $this, 'execute_retrieve' ],
				'permission_callback' => [ $this, 'can_use' ],
				'meta'                => [
					'show_in_rest' => true,
					'annotations'  => [ 'readonly' => true, 'destructive' => false, 'idempotent' => true ],
				],
			]
		);

		wp_register_ability(
			self::CATEGORY . '/search',
			[
				'label'               => __( 'Hybrid Search', 'loupe-semantic-search' ),
				'description'         => __( 'Search site content by keywords and meaning combined. Same results format as loupe-search/search.', 'loupe-semantic-search' ),
				'category'            => self::CATEGORY,
				'input_schema'        => [
					'type'       => 'object',
					'required'   => [ 'query' ],
					'properties' => [
						'query'      => [ 'type' => 'string', 'description' => __( 'The search query.', 'loupe-semantic-search' ) ],
						'post_types' => [
							'type'        => 'array',
							'items'       => [ 'type' => 'string' ],
							'description' => __( 'Post types to search. Defaults to all public indexed post types.', 'loupe-semantic-search' ),
						],
						'per_page'   => [ 'type' => 'integer', 'default' => 10, 'minimum' => 1, 'maximum' => 100 ],
						'page'       => [ 'type' => 'integer', 'default' => 1, 'minimum' => 1 ],
					],
				],
				'output_schema'       => [
					'type'       => 'object',
					'properties' => [
						'hits'        => [
							'type'  => 'array',
							'items' => [
								'type'       => 'object',
								'properties' => [
									'id'        => [ 'type' => 'integer' ],
									'title'     => [ 'type' => 'string' ],
									'url'       => [ 'type' => 'string' ],
									'excerpt'   => [ 'type' => 'string' ],
									'post_type' => [ 'type' => 'string' ],
									'post_date' => [ 'type' => 'string' ],
								],
							],
						],
						'total_hits'  => [ 'type' => 'integer' ],
						'page'        => [ 'type' => 'integer' ],
						'total_pages' => [ 'type' => 'integer' ],
					],
				],
				'execute_callback'    => [ $this, 'execute_search' ],
				'permission_callback' => [ $this, 'can_use' ],
				'meta'                => [
					'show_in_rest' => true,
					'annotations'  => [ 'readonly' => true, 'destructive' => false, 'idempotent' => true ],
				],
			]
		);
	}

	/**
	 * Permission callback.
	 *
	 * @param mixed $input Ability input.
	 */
	public function can_use( $input = null ): bool {
		/**
		 * Filters who may run the loupe-semantic abilities. Each call may cost a provider request.
		 *
		 * @param bool  $allowed Default: the user can `read` (any logged-in user).
		 * @param mixed $input   Ability input.
		 */
		return (bool) apply_filters( 'loupe_semantic_ability_permission', current_user_can( 'read' ), $input );
	}

	/**
	 * Execute loupe-semantic/retrieve.
	 *
	 * @param array $input Validated input.
	 * @return array|\WP_Error
	 */
	public function execute_retrieve( array $input ) {
		$query = sanitize_text_field( (string) ( $input['query'] ?? '' ) );
		$limit = min( 20, max( 1, (int) ( $input['limit'] ?? 5 ) ) );
		$types = self::post_types( $input['post_type'] ?? [] );
		if ( '' === trim( $query ) ) {
			return new \WP_Error( 'loupe_semantic_empty_query', __( 'The query is empty.', 'loupe-semantic-search' ) );
		}
		if ( empty( $types ) ) {
			return [ 'passages' => [], 'model' => Settings::target() ];
		}
		return $this->retriever->passages( $query, $limit, $types, true );
	}

	/**
	 * Execute loupe-semantic/search.
	 *
	 * @param array $input Validated input.
	 * @return array
	 */
	public function execute_search( array $input ): array {
		$query    = sanitize_text_field( (string) ( $input['query'] ?? '' ) );
		$per_page = min( 100, max( 1, (int) ( $input['per_page'] ?? 10 ) ) );
		$page     = max( 1, (int) ( $input['page'] ?? 1 ) );
		$types    = self::post_types( $input['post_types'] ?? [] );
		$empty    = [ 'hits' => [], 'total_hits' => 0, 'page' => $page, 'total_pages' => 0 ];
		if ( empty( $types ) ) {
			return $empty;
		}

		$engine = new WP_Loupe_Search_Engine( $types, WP_Loupe_DB::get_instance() );
		$raw    = $this->hybrid->apply( (array) $engine->search( $query ), $query, $types );

		$visible = array_values(
			array_filter( $raw, static fn ( $hit ) => is_array( $hit ) && ! empty( $hit['id'] ) && WP_Loupe_Utils::is_publicly_viewable_post( (int) $hit['id'] ) )
		);

		$hits = [];
		foreach ( array_slice( $visible, ( $page - 1 ) * $per_page, $per_page ) as $hit ) {
			$post   = get_post( (int) $hit['id'] );
			$hits[] = [
				'id'        => $post->ID,
				'title'     => get_the_title( $post ),
				'url'       => (string) get_permalink( $post ),
				'excerpt'   => wp_strip_all_tags( get_the_excerpt( $post ) ),
				'post_type' => $post->post_type,
				'post_date' => (string) get_the_date( 'c', $post ),
			];
		}

		$total = count( $visible );
		return [
			'hits'        => $hits,
			'total_hits'  => $total,
			'page'        => $page,
			'total_pages' => (int) ceil( $total / $per_page ),
		];
	}

	/**
	 * Requested post types, limited to public indexed post types.
	 *
	 * @param mixed $requested String or list; empty for all.
	 * @return list<string>
	 */
	private static function post_types( $requested ): array {
		$public    = WP_Loupe_Utils::get_public_indexed_post_types();
		$requested = array_filter( array_map( 'sanitize_key', (array) $requested ) );
		return array_values( empty( $requested ) ? $public : array_intersect( $requested, $public ) );
	}
}
