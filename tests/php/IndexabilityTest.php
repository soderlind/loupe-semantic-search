<?php
namespace Soderlind\Plugin\LoupeSemanticSearch\Tests;

use Brain\Monkey\Functions;
use Soderlind\Plugin\LoupeSemanticSearch\Ai_Adapter;
use Soderlind\Plugin\LoupeSemanticSearch\Indexability;

class IndexabilityTest extends TestCase {

	private function indexability( bool $ours, bool $others ): Indexability {
		$ai = $this->createStub( Ai_Adapter::class );
		$ai->method( 'covers' )->willReturn( $ours );
		$ai->method( 'other_consumer_covers' )->willReturn( $others );
		return new Indexability( $ai );
	}

	protected function setUp(): void {
		parent::setUp();
		Functions\when( 'get_post_type' )->justReturn( 'post' );
		Functions\when( 'loupe_search_is_post_indexable' )->justReturn( false );
	}

	public function test_vetoes_posts_loupe_would_not_index(): void {
		$this->assertFalse( $this->indexability( true, false )->filter( true, 'post', 5 ) );
	}

	public function test_leaves_shared_post_types_to_other_consumers(): void {
		$this->assertTrue( $this->indexability( true, true )->filter( true, 'post', 5 ) );
	}

	public function test_ignores_other_object_types_and_post_types(): void {
		$this->assertTrue( $this->indexability( true, false )->filter( true, 'term', 5 ) );
		$this->assertTrue( $this->indexability( false, false )->filter( true, 'post', 5 ) );
	}
}
