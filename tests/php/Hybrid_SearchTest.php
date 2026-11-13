<?php
namespace Soderlind\Plugin\LoupeSemanticSearch\Tests;

use Soderlind\Plugin\LoupeSemanticSearch\Hybrid_Search;
use Soderlind\Plugin\LoupeSemanticSearch\Retriever;

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

	public function test_front_end_skips_field_sorts_and_disabled_toggle(): void {
		$hybrid = $this->hybrid( [], 0 );
		$sorted = [ [ 'field' => 'post_date', 'direction' => 'desc' ] ];
		$this->assertSame( self::KEYWORD, $hybrid->filter_order_results( self::KEYWORD, 'fruit', [ 'post' ], $sorted ) );

		$this->settings( [ 'provider' => 'openai', 'model' => 'small', 'hybrid_frontend' => false ] );
		$this->assertSame( self::KEYWORD, $hybrid->filter_order_results( self::KEYWORD, 'fruit', [ 'post' ], [] ) );
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

	public function test_rest_skips_on_opt_out_and_on_non_relevance_sort(): void {
		$hybrid = $this->hybrid( [], 0 );
		$base   = [ 'post_types' => [ 'post' ], 'filter' => '', 'payload' => [] ];

		$this->assertSame( self::KEYWORD, $hybrid->filter_advanced_hits( self::KEYWORD, 'fruit', $base + [ 'sort' => [ 'post_date:desc' ] ] ) );
		$this->assertSame( self::KEYWORD, $hybrid->filter_advanced_hits( self::KEYWORD, 'fruit', [ 'sort' => [], 'payload' => [ 'semantic' => false ] ] + $base ) );
		$this->assertSame( self::KEYWORD, $hybrid->filter_advanced_hits( self::KEYWORD, 'fruit', $base + [ 'sort' => [ '_relevance:asc' ] ] ) );
	}

	public function test_rest_exclusion_query_reranks_only(): void {
		$context = [ 'post_types' => [ 'post' ], 'filter' => '', 'sort' => [], 'payload' => [], 'limit' => 10 ];
		$hits    = $this->hybrid( [ self::match( 7, 0.9 ), self::match( 2, 0.8 ) ] )->filter_advanced_hits( self::KEYWORD, 'fruit -cake', $context );
		$this->assertSame( [ 2, 1 ], array_column( $hits, 'id' ) );
	}
}
