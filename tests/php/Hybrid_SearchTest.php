<?php
namespace Soderlind\Plugin\LoupeSemanticSearch\Tests;

use Soderlind\Plugin\LoupeSemanticSearch\Hybrid_Search;
use Soderlind\Plugin\LoupeSemanticSearch\Retriever;
use Brain\Monkey\Functions;

class Hybrid_SearchTest extends TestCase {

	private const KEYWORD = [
		[ 'id' => 1, 'post_type' => 'post', '_score' => 0.9 ],
		[ 'id' => 2, 'post_type' => 'post', '_score' => 0.5 ],
	];

	protected function setUp(): void {
		parent::setUp();
		$this->settings( [ 'provider' => 'openai', 'model' => 'small' ] );
	}

	private static function match( int $id, float $score, string $type = 'post' ): array {
		return [ 'post_id' => $id, 'post_type' => $type, 'chunk_index' => 0, 'score' => $score, 'hash' => 'h' ];
	}

	private function hybrid( $semantic, int $calls = 1 ): Hybrid_Search {
		$retriever = $this->createMock( Retriever::class );
		$retriever->expects( $this->exactly( $calls ) )->method( 'search' )->willReturn( $semantic );
		return new Hybrid_Search( $retriever );
	}

	public function test_merge_adds_semantic_hits_and_marks_all_hits(): void {
		$merged = Hybrid_Search::merge( self::KEYWORD, [ self::match( 2, 0.8 ), self::match( 7, 0.6, 'page' ) ], 1.0, 1.0, false );

		$this->assertSame( [ 2, 1, 7 ], array_column( $merged, 'id' ) );
		$this->assertSame( [ 'id' => 7, 'post_type' => 'page' ], array_intersect_key( $merged[2], [ 'id' => 0, 'post_type' => 0 ] ) );
		$this->assertSame( 0.8, $merged[0]['_semantic'] );
		$this->assertSame( 0.5, $merged[0]['_keywordScore'] );
		$this->assertArrayNotHasKey( '_semantic', $merged[1] );
		$this->assertTrue( $merged[2]['_hybrid'] );
	}

	public function test_merge_rerank_only_adds_nothing(): void {
		$merged = Hybrid_Search::merge( self::KEYWORD, [ self::match( 7, 0.9 ), self::match( 2, 0.8 ) ], 1.0, 1.0, true );
		$this->assertSame( [ 2, 1 ], array_column( $merged, 'id' ) );
	}

	public function test_front_end_merges_semantic_results(): void {
		$hits = $this->hybrid( [ self::match( 7, 0.9 ) ] )->filter_order_results( self::KEYWORD, 'fruit', [ 'post' ], [] );
		$this->assertSame( [ 1, 7, 2 ], array_column( $hits, 'id' ) );
	}

	private function stub_posts( array $dates ): void {
		Functions\when( 'get_post' )->alias(
			static function ( $id ) use ( $dates ) {
				if ( ! isset( $dates[ $id ] ) ) {
					return null;
				}
				$post            = new \WP_Post();
				$post->ID        = $id;
				$post->post_date = $dates[ $id ];
				return $post;
			}
		);
		Functions\when( 'get_post_meta' )->justReturn( '' );
	}

	public function test_front_end_field_sort_keeps_semantic_hits_and_sorts_them(): void {
		$this->stub_posts( [ 7 => '2024-06-01 00:00:00' ] );
		$keyword = [
			[ 'id' => 1, 'post_type' => 'post', '_score' => 0.9, 'post_date' => '2024-01-01 00:00:00' ],
			[ 'id' => 2, 'post_type' => 'post', '_score' => 0.5, 'post_date' => '2024-03-01 00:00:00' ],
		];
		$hybrid = $this->hybrid( [ self::match( 7, 0.9 ) ], 2 );

		$desc = $hybrid->filter_order_results( $keyword, 'fruit', [ 'post' ], [ [ 'field' => 'post_date', 'direction' => 'desc' ] ] );
		$this->assertSame( [ 7, 2, 1 ], array_column( $desc, 'id' ) );
		$this->assertSame( '2024-06-01 00:00:00', $desc[0]['post_date'] );

		$asc = $hybrid->filter_order_results( $keyword, 'fruit', [ 'post' ], [ [ 'field' => 'post_date', 'direction' => 'asc' ] ] );
		$this->assertSame( [ 1, 2, 7 ], array_column( $asc, 'id' ) );
	}

	public function test_front_end_disabled_toggle_keeps_keyword_hits(): void {
		$this->settings( [ 'provider' => 'openai', 'model' => 'small', 'hybrid_frontend' => false ] );
		$this->assertSame( self::KEYWORD, $this->hybrid( [], 0 )->filter_order_results( self::KEYWORD, 'fruit', [ 'post' ], [] ) );
	}

	public function test_sort_by_fields_puts_missing_values_last_in_both_directions(): void {
		$this->stub_posts( [] );
		$hits = [
			[ 'id' => 9, 'post_type' => 'post', 'post_title' => '' ],
			[ 'id' => 1, 'post_type' => 'post', 'post_title' => 'banana' ],
			[ 'id' => 2, 'post_type' => 'post', 'post_title' => 'Apple' ],
		];
		$this->assertSame( [ 2, 1, 9 ], array_column( Hybrid_Search::sort_by_fields( $hits, [ [ 'post_title', 'asc' ] ] ), 'id' ) );
		$this->assertSame( [ 1, 2, 9 ], array_column( Hybrid_Search::sort_by_fields( $hits, [ [ 'post_title', 'desc' ] ] ), 'id' ) );
	}

	public function test_no_model_or_empty_query_keeps_keyword_hits(): void {
		$hybrid = $this->hybrid( [], 0 );
		$this->assertSame( self::KEYWORD, $hybrid->filter_order_results( self::KEYWORD, '"" -x', [ 'post' ] ) );

		$this->settings( [] );
		$this->assertSame( self::KEYWORD, $hybrid->filter_order_results( self::KEYWORD, 'fruit', [ 'post' ] ) );
	}

	public function test_provider_error_keeps_keyword_hits(): void {
		$hits = $this->hybrid( new \WP_Error( 'down' ) )->filter_order_results( self::KEYWORD, 'fruit', [ 'post' ] );
		$this->assertSame( self::KEYWORD, $hits );
	}

	public function test_already_fused_hits_are_left_alone(): void {
		$fused = Hybrid_Search::merge( self::KEYWORD, [], 1.0, 1.0, false );
		$this->assertSame( $fused, $this->hybrid( [], 0 )->filter_order_results( $fused, 'fruit', [ 'post' ] ) );
	}

	public function test_rest_drops_semantic_only_hits_outside_the_filter(): void {
		$seen    = null;
		$context = [
			'post_types'     => [ 'post' ],
			'filter'         => "category = 'news'",
			'sort'           => [ '_relevance:desc' ],
			'payload'        => [],
			'limit'          => 10,
			'matches_filter' => function ( array $ids ) use ( &$seen ): array {
				$seen = $ids;
				return [ 8 ];
			},
		];

		$hits = $this->hybrid( [ self::match( 7, 0.9 ), self::match( 8, 0.8 ), self::match( 1, 0.7 ) ] )
			->filter_advanced_hits( self::KEYWORD, 'fruit', $context );

		$this->assertSame( [ 7, 8 ], $seen, 'Only semantic-only IDs are checked.' );
		$this->assertSame( [ 1, 8, 2 ], array_column( $hits, 'id' ) );
	}

	public function test_rest_skips_on_opt_out_relevance_asc_and_distance_sort(): void {
		$hybrid = $this->hybrid( [], 0 );
		$base   = [ 'post_types' => [ 'post' ], 'filter' => '', 'payload' => [] ];

		$this->assertSame( self::KEYWORD, $hybrid->filter_advanced_hits( self::KEYWORD, 'fruit', [ 'sort' => [], 'payload' => [ 'semantic' => false ] ] + $base ) );
		$this->assertSame( self::KEYWORD, $hybrid->filter_advanced_hits( self::KEYWORD, 'fruit', $base + [ 'sort' => [ '_relevance:asc' ] ] ) );
		$this->assertSame( self::KEYWORD, $hybrid->filter_advanced_hits( self::KEYWORD, 'fruit', $base + [ 'sort' => [ '_geoPoint(location, 59.9, 10.7):asc' ] ] ) );
	}

	public function test_rest_field_sort_returns_semantic_only_hits_sorted(): void {
		$this->stub_posts( [ 7 => '2024-06-01 00:00:00', 8 => '2023-01-01 00:00:00' ] );
		$context = [ 'post_types' => [ 'post' ], 'filter' => '', 'sort' => [ 'post_date:desc' ], 'payload' => [], 'limit' => 10 ];

		$hits = $this->hybrid( [ self::match( 8, 0.9 ), self::match( 7, 0.8 ) ] )->filter_advanced_hits( [], 'vacation', $context );

		$this->assertSame( [ 7, 8 ], array_column( $hits, 'id' ) );
	}

	public function test_rest_exclusion_query_reranks_only(): void {
		$context = [ 'post_types' => [ 'post' ], 'filter' => '', 'sort' => [], 'payload' => [], 'limit' => 10 ];
		$hits    = $this->hybrid( [ self::match( 7, 0.9 ), self::match( 2, 0.8 ) ] )->filter_advanced_hits( self::KEYWORD, 'fruit -cake', $context );
		$this->assertSame( [ 2, 1 ], array_column( $hits, 'id' ) );
	}
}
