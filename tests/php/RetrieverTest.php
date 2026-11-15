<?php
namespace Soderlind\Plugin\LoupeSemanticSearch\Tests;

use Brain\Monkey\Functions;
use Soderlind\Plugin\LoupeSearch\WP_Loupe_Partitions;
use Soderlind\Plugin\LoupeSearch\WP_Loupe_Utils;
use Soderlind\Plugin\LoupeSemanticSearch\Passage_Builder;
use Soderlind\Plugin\LoupeSemanticSearch\Query_Embedder;
use Soderlind\Plugin\LoupeSemanticSearch\Retriever;
use Soderlind\Plugin\LoupeSemanticSearch\Vector_Store;

require_once __DIR__ . '/stubs/WP_Loupe_Utils.php';
require_once __DIR__ . '/stubs/WP_Loupe_Partitions.php';

class RetrieverTest extends TestCase {

	private function retriever( ?Vector_Store $store = null ): Retriever {
		$embedder = $this->createStub( Query_Embedder::class );
		$embedder->method( 'embed' )->willReturn( [ 0.1, 0.2 ] );

		$match = static fn ( int $id, float $score ): array => [
			'post_id'     => $id,
			'post_type'   => 'post',
			'chunk_index' => 0,
			'score'       => $score,
			'hash'        => 'h' . $id,
		];
		if ( null === $store ) {
			$store = $this->createStub( Vector_Store::class );
			$store->method( 'search' )->willReturn( [ $match( 1, 0.9 ), $match( 2, 0.8 ), $match( 3, 0.7 ) ] );
		}

		return new Retriever( $embedder, $store, $this->createStub( Passage_Builder::class ) );
	}

	protected function setUp(): void {
		parent::setUp();
		$this->settings( [ 'provider' => 'openai', 'model' => 'text-embedding-3-small' ] );
		Functions\when( 'loupe_search_is_post_indexable' )->alias( static fn ( $id ) => 3 !== $id );
		WP_Loupe_Utils::$private_ids = [ 2 ];
	}

	protected function tearDown(): void {
		WP_Loupe_Utils::$private_ids         = [];
		WP_Loupe_Partitions::$searched        = [];
		WP_Loupe_Partitions::$post_partitions = [];
		parent::tearDown();
	}

	private function ids( array $matches ): array {
		return array_column( $matches, 'post_id' );
	}

	public function test_public_only_drops_posts_the_public_cannot_view(): void {
		$this->assertSame( [ 1 ], $this->ids( $this->retriever()->search( 'q', 10, [ 'post' ], true ) ) );
	}

	private function stub_get_post(): void {
		Functions\when( 'get_post' )->alias(
			static function ( $id ) {
				$post            = new \WP_Post();
				$post->ID        = (int) $id;
				$post->post_type = 'post';
				return $post;
			}
		);
	}

	public function test_drops_posts_outside_the_partitions_searched(): void {
		$this->stub_get_post();
		// E.g. Loupe Search Multilingual: the visitor reads German, post 1 is English.
		WP_Loupe_Partitions::$searched        = [ 'post' => [ 'de' ] ];
		WP_Loupe_Partitions::$post_partitions = [ 1 => 'en', 2 => 'de' ];

		$this->assertSame( [ 2 ], $this->ids( $this->retriever()->search( 'q', 10, [ 'post' ], false ) ) );
	}

	public function test_asks_the_store_for_more_until_enough_matches_are_in_scope(): void {
		$this->stub_get_post();
		// Twenty English posts rank above the only German one.
		$ranked = [];
		for ( $id = 10; $id <= 30; $id++ ) {
			$ranked[] = [ 'post_id' => $id, 'post_type' => 'post', 'chunk_index' => 0, 'score' => 1 - $id / 100, 'hash' => 'h' ];
		}
		$store = $this->createMock( Vector_Store::class );
		$store->expects( $this->exactly( 2 ) )->method( 'search' )
			->willReturnCallback( static fn ( $vector, $target, int $k ): array => array_slice( $ranked, 0, $k ) );
		WP_Loupe_Partitions::$searched        = [ 'post' => [ 'de' ] ];
		WP_Loupe_Partitions::$post_partitions = array_fill_keys( range( 10, 29 ), 'en' ) + [ 30 => 'de' ];

		$this->assertSame( [ 30 ], $this->ids( $this->retriever( $store )->search( 'q', 1, [ 'post' ], false ) ) );
	}

	public function test_skips_post_types_the_search_excludes(): void {
		WP_Loupe_Partitions::$searched = [ 'post' => [] ];
		$store                         = $this->createMock( Vector_Store::class );
		$store->expects( $this->once() )->method( 'search' )
			->with( $this->anything(), $this->anything(), $this->anything(), [ 'page' ] )
			->willReturn( [ [ 'post_id' => 1, 'post_type' => 'post', 'chunk_index' => 0, 'score' => 0.9, 'hash' => 'h' ] ] );

		$this->assertSame( [], $this->retriever( $store )->search( 'q', 10, [ 'post', 'page' ], false ) );
	}

	public function test_without_public_only_keeps_private_posts(): void {
		$this->assertSame( [ 1, 2 ], $this->ids( $this->retriever()->search( 'q', 10, [ 'post' ], false ) ) );
	}

	public function test_passages_are_public_only_by_default(): void {
		Functions\when( 'get_post' )->justReturn( null );
		$retriever = $this->getMockBuilder( Retriever::class )
			->disableOriginalConstructor()
			->onlyMethods( [ 'search' ] )
			->getMock();
		$retriever->expects( $this->once() )
			->method( 'search' )
			->with( 'q', 5, [ 'post' ], true )
			->willReturn( [] );

		$retriever->passages( 'q', 5, [ 'post' ] );
	}
}
