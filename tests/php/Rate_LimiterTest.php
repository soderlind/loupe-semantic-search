<?php
namespace Soderlind\Plugin\LoupeSemanticSearch\Tests;

use Brain\Monkey\Functions;
use Soderlind\Plugin\LoupeSemanticSearch\Rate_Limiter;

class Rate_LimiterTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		$_SERVER['REMOTE_ADDR'] = '203.0.113.5';
		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\when( 'wp_unslash' )->returnArg();
	}

	public function test_per_ip_limit(): void {
		$this->settings( [ 'rate_limit' => 2, 'daily_cap' => 0 ] );
		$limiter = new Rate_Limiter();

		$this->assertTrue( $limiter->acquire() );
		$this->assertTrue( $limiter->acquire() );
		$this->assertFalse( $limiter->acquire() );
		$this->assertSame( 2, $limiter->used_today() );
	}

	public function test_administrators_skip_the_per_ip_limit_but_not_the_daily_cap(): void {
		Functions\when( 'current_user_can' )->justReturn( true );
		$this->settings( [ 'rate_limit' => 1, 'daily_cap' => 3 ] );
		$limiter = new Rate_Limiter();

		$this->assertTrue( $limiter->acquire() );
		$this->assertTrue( $limiter->acquire() );
		$this->assertTrue( $limiter->acquire() );
		$this->assertFalse( $limiter->acquire() );
	}

	public function test_daily_count_restarts_on_a_new_day(): void {
		$this->settings( [ 'rate_limit' => 0, 'daily_cap' => 1 ] );
		$this->options[ Rate_Limiter::DAILY_OPTION ] = [ 'date' => '2000-01-01', 'count' => 99 ];

		$this->assertTrue( ( new Rate_Limiter() )->acquire() );
		$this->assertSame( 1, $this->options[ Rate_Limiter::DAILY_OPTION ]['count'] );
	}
}
