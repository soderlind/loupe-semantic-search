<?php
namespace Soderlind\Plugin\LoupeSemanticSearch\Tests;

use PHPUnit\Framework\MockObject\MockObject;
use Soderlind\Plugin\LoupeSemanticSearch\Ai_Adapter;
use Soderlind\Plugin\LoupeSemanticSearch\Plugin;

class PluginTest extends TestCase {

	/** @var Ai_Adapter&MockObject */
	private $ai;

	private Plugin $plugin;

	protected function setUp(): void {
		parent::setUp();
		$this->ai = $this->createMock( Ai_Adapter::class );

		$class        = new \ReflectionClass( Plugin::class );
		$this->plugin = $class->newInstanceWithoutConstructor();
		$class->getProperty( 'ai' )->setValue( $this->plugin, $this->ai );

		$this->settings( [ 'provider' => 'openai', 'model' => 'text-embedding-3-large' ] );
	}

	private function pending_change( string $provider, string $model ): void {
		$this->options[ Plugin::TARGET_CHANGE_OPTION ] = [ 'provider' => $provider, 'model' => $model ];
	}

	public function test_prunes_old_model_no_other_consumer_uses(): void {
		$this->pending_change( 'openai', 'text-embedding-3-small' );
		$this->ai->method( 'is_registered' )->willReturn( true );
		$this->ai->method( 'other_consumer_uses' )->with( 'openai', 'text-embedding-3-small' )->willReturn( false );
		$this->ai->expects( $this->once() )->method( 'start_backfill' );
		$this->ai->expects( $this->once() )->method( 'prune' )->with( 'openai', 'text-embedding-3-small' );

		$this->plugin->apply_target_change();

		$this->assertArrayNotHasKey( Plugin::TARGET_CHANGE_OPTION, $this->options );
	}

	public function test_keeps_old_model_another_consumer_uses(): void {
		$this->pending_change( 'openai', 'text-embedding-3-small' );
		$this->ai->method( 'is_registered' )->willReturn( true );
		$this->ai->method( 'other_consumer_uses' )->willReturn( true );
		$this->ai->expects( $this->once() )->method( 'start_backfill' );
		$this->ai->expects( $this->never() )->method( 'prune' );

		$this->plugin->apply_target_change();
	}

	public function test_dimensions_only_change_does_not_prune(): void {
		$this->pending_change( 'openai', 'text-embedding-3-large' );
		$this->ai->method( 'is_registered' )->willReturn( true );
		$this->ai->expects( $this->once() )->method( 'start_backfill' );
		$this->ai->expects( $this->never() )->method( 'prune' );

		$this->plugin->apply_target_change();
	}

	public function test_waits_until_the_consumer_is_registered(): void {
		$this->pending_change( 'openai', 'text-embedding-3-small' );
		$this->ai->method( 'is_registered' )->willReturn( false );
		$this->ai->expects( $this->never() )->method( 'start_backfill' );
		$this->ai->expects( $this->never() )->method( 'prune' );

		$this->plugin->apply_target_change();

		$this->assertArrayHasKey( Plugin::TARGET_CHANGE_OPTION, $this->options );
	}
}
