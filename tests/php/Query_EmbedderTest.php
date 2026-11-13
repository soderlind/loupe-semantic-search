<?php
namespace Soderlind\Plugin\LoupeSemanticSearch\Tests;

use Soderlind\Plugin\LoupeSemanticSearch\Ai_Adapter;
use Soderlind\Plugin\LoupeSemanticSearch\Query_Embedder;
use Soderlind\Plugin\LoupeSemanticSearch\Rate_Limiter;

class Query_EmbedderTest extends TestCase {

	private const TARGET = [ 'provider' => 'openai', 'model' => 'small', 'dimensions' => null ];

	public function test_normalize_strips_loupe_operators(): void {
		$this->assertSame( 'red apple pie', Query_Embedder::normalize( '"Red  Apple" OR pie -cake' ) );
		$this->assertSame( 'self-hosted', Query_Embedder::normalize( 'self-hosted' ) );
	}

	public function test_has_exclusion(): void {
		$this->assertTrue( Query_Embedder::has_exclusion( 'pie -cake' ) );
		$this->assertTrue( Query_Embedder::has_exclusion( '-cake pie' ) );
		$this->assertFalse( Query_Embedder::has_exclusion( 'self-hosted pie' ) );
		$this->assertFalse( Query_Embedder::has_exclusion( 'pie - cake' ) );
	}

	public function test_second_identical_query_uses_the_cache_and_no_rate_limit(): void {
		$ai = $this->createMock( Ai_Adapter::class );
		$ai->expects( $this->once() )->method( 'embed' )->with( 'apple pie' )->willReturn( [ 0.1, 0.2 ] );
		$limiter = $this->createMock( Rate_Limiter::class );
		$limiter->expects( $this->once() )->method( 'acquire' )->willReturn( true );

		$embedder = new Query_Embedder( $ai, $limiter );
		$this->assertSame( [ 0.1, 0.2 ], $embedder->embed( 'Apple pie', self::TARGET ) );
		$this->assertSame( [ 0.1, 0.2 ], $embedder->embed( '"apple" pie', self::TARGET ) );
	}

	public function test_rate_limited_query_is_not_sent(): void {
		$ai = $this->createMock( Ai_Adapter::class );
		$ai->expects( $this->never() )->method( 'embed' );
		$limiter = $this->createStub( Rate_Limiter::class );
		$limiter->method( 'acquire' )->willReturn( false );

		$result = ( new Query_Embedder( $ai, $limiter ) )->embed( 'apple', self::TARGET );
		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'loupe_semantic_rate_limited', $result->get_error_code() );
	}

	public function test_failures_are_not_cached(): void {
		$ai = $this->createMock( Ai_Adapter::class );
		$ai->expects( $this->exactly( 2 ) )->method( 'embed' )->willReturn( new \WP_Error( 'down' ) );
		$limiter = $this->createStub( Rate_Limiter::class );
		$limiter->method( 'acquire' )->willReturn( true );

		$embedder = new Query_Embedder( $ai, $limiter );
		$embedder->embed( 'apple', self::TARGET );
		$embedder->embed( 'apple', self::TARGET );
		$this->assertSame( [], $this->transients );
	}
}
