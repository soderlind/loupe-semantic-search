<?php
namespace Soderlind\Plugin\LoupeSemanticSearch\Tests;

use Soderlind\Plugin\LoupeSemanticSearch\Ai_Adapter;
use Soderlind\Plugin\LoupeSemanticSearch\Ai_Repository_Vector_Store;

class Ai_Repository_Vector_StoreTest extends TestCase {

	private const TARGET = [ 'provider' => 'p', 'model' => 'm', 'dimensions' => null ];

	private function store( array $rows ): Ai_Repository_Vector_Store {
		$ai = $this->createStub( Ai_Adapter::class );
		$ai->method( 'iterate_vectors' )->willReturn( $rows );
		return new Ai_Repository_Vector_Store( $ai );
	}

	private static function row( int $id, int $chunk, array $vector, string $type = 'post' ): array {
		return [ 'post_id' => $id, 'post_type' => $type, 'chunk_index' => $chunk, 'vector' => $vector, 'hash' => "h{$id}" ];
	}

	public function test_keeps_the_best_chunk_per_post_and_sorts_by_similarity(): void {
		$store = $this->store(
			[
				self::row( 1, 0, [ 0.0, 1.0 ] ),
				self::row( 1, 1, [ 1.0, 0.2 ] ),
				self::row( 2, 0, [ 1.0, 1.0 ] ),
				self::row( 3, 0, [ 1.0, 0.0 ], 'page' ),
				self::row( 4, 0, [ -1.0, 0.0 ] ),
				self::row( 5, 0, [ 1.0, 0.0, 0.0 ] ),
			]
		);

		$hits = $store->search( [ 2.0, 0.0 ], self::TARGET, 10, [ 'post' ], 0.35 );

		$this->assertSame( [ 1, 2 ], array_column( $hits, 'post_id' ), 'Page, dissimilar and wrong-dimension vectors are dropped.' );
		$this->assertSame( 1, $hits[0]['chunk_index'] );
		$this->assertEqualsWithDelta( 1 / sqrt( 1.04 ), $hits[0]['score'], 1e-9 );
	}

	public function test_limits_to_k(): void {
		$store = $this->store( [ self::row( 1, 0, [ 1.0 ] ), self::row( 2, 0, [ 1.0 ] ) ] );
		$this->assertCount( 1, $store->search( [ 1.0 ], self::TARGET, 1, [], 0.0 ) );
	}
}
