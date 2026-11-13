<?php
namespace Soderlind\Plugin\LoupeSemanticSearch\Tests;

use Brain\Monkey\Functions;
use Soderlind\Plugin\LoupeSemanticSearch\Ai_Adapter;
use Soderlind\Plugin\LoupeSemanticSearch\Passage_Builder;

class Passage_BuilderTest extends TestCase {

	private function builder(): Passage_Builder {
		$ai = $this->createStub( Ai_Adapter::class );
		$ai->method( 'post_text' )->willReturn( "Title\n\nFirst part. Second part." );
		$ai->method( 'hash_text' )->willReturn( 'current' );
		$ai->method( 'chunks' )->willReturn( [ 'Title First part.', 'Second part.' ] );
		return new Passage_Builder( $ai );
	}

	public function test_returns_the_matched_chunk_when_the_post_is_unchanged(): void {
		$this->assertSame( [ 'text' => 'Second part.', 'stale' => false ], $this->builder()->build( 5, 1, 'current', null ) );
	}

	public function test_returns_a_stale_excerpt_when_the_post_changed(): void {
		Functions\when( 'wp_strip_all_tags' )->returnArg();
		$this->assertSame( [ 'text' => 'Title First part. Second part.', 'stale' => true ], $this->builder()->build( 5, 1, 'old', null ) );
	}
}
