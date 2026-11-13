<?php
namespace Soderlind\Plugin\LoupeSemanticSearch\Tests;

use PHPUnit\Framework\TestCase;
use Soderlind\Plugin\LoupeSemanticSearch\Fusion;

class FusionTest extends TestCase {

	public function test_post_in_both_rankings_beats_posts_in_one(): void {
		$fused = Fusion::rrf( [ 1, 2, 3 ], [ 9, 3 ] );

		$this->assertSame( 3, array_key_first( $fused ), 'Rank 3 + rank 2 outscores a single rank 1.' );
		$this->assertEqualsWithDelta( 1 / 63 + 1 / 62, $fused[3], 1e-12 );
		$this->assertSame( [ 3, 1, 9, 2 ], array_keys( $fused ) );
	}

	public function test_equal_scores_keep_keyword_order_first(): void {
		$this->assertSame( [ 1, 2 ], array_keys( Fusion::rrf( [ 1 ], [ 2 ] ) ) );
	}

	public function test_weights_shift_the_balance(): void {
		$this->assertSame( [ 2, 1 ], array_keys( Fusion::rrf( [ 1 ], [ 2 ], 1.0, 2.0 ) ) );
		$this->assertSame( [ 1 ], array_keys( array_filter( Fusion::rrf( [ 1 ], [ 2 ], 1.0, 0.0 ) ) ) );
	}

	public function test_duplicate_ids_count_once_at_their_best_rank(): void {
		$fused = Fusion::rrf( [ 5, 5, 6 ], [] );
		$this->assertEqualsWithDelta( 1 / 61, $fused[5], 1e-12 );
		$this->assertEqualsWithDelta( 1 / 62, $fused[6], 1e-12 );
	}
}
