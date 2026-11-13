<?php
/**
 * Embedding model list cache.
 */

namespace Soderlind\Plugin\LoupeSemanticSearch\Tests;

use Brain\Monkey\Functions;
use PHPUnit\Framework\MockObject\MockObject;
use Soderlind\Plugin\LoupeSemanticSearch\Ai_Adapter;

final class Ai_AdapterTest extends TestCase {

	private const MODEL = [
		'provider'      => 'openai',
		'provider_name' => 'OpenAI',
		'model'         => 'text-embedding-3-small',
		'model_name'    => 'text-embedding-3-small',
	];

	protected function setUp(): void {
		parent::setUp();
		Functions\when( 'WordPress\AI\get_ai_connectors' )->justReturn( [] );
	}

	/**
	 * @param array<string,bool> $state Connector state.
	 */
	private function adapter( array $state, int $fetches, array $models = [ self::MODEL ] ): Ai_Adapter&MockObject {
		$ai = $this->getMockBuilder( Ai_Adapter::class )
			->onlyMethods( [ 'is_available', 'connector_state', 'fetch_embedding_models' ] )
			->getMock();
		$ai->method( 'is_available' )->willReturn( true );
		$ai->method( 'connector_state' )->willReturn( $state );
		$ai->expects( $this->exactly( $fetches ) )->method( 'fetch_embedding_models' )->willReturn( $models );
		return $ai;
	}

	public function test_second_call_uses_saved_list(): void {
		$ai = $this->adapter( [ 'openai' => true ], 1 );

		$this->assertSame( [ self::MODEL ], $ai->embedding_models() );
		$this->assertSame( [ self::MODEL ], $ai->embedding_models() );
	}

	public function test_empty_list_is_not_saved(): void {
		$ai = $this->adapter( [ 'openai' => false ], 2, [] );

		$this->assertSame( [], $ai->embedding_models() );
		$this->assertSame( [], $ai->embedding_models() );
		$this->assertSame( [], $this->transients );
	}

	public function test_refresh_asks_providers_again(): void {
		$ai = $this->adapter( [ 'openai' => true ], 2 );

		$ai->embedding_models();
		$ai->embedding_models( true );
		$ai->embedding_models();
	}

	public function test_connector_change_uses_new_key(): void {
		$this->adapter( [ 'openai' => true ], 1 )->embedding_models();
		$this->adapter( [ 'openai' => true, 'google' => true ], 1 )->embedding_models();

		$this->assertCount( 2, $this->transients );
	}
}
