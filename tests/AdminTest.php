<?php

declare( strict_types=1 );

namespace Netivo\Module\WooCommerce\SplitOrder\Tests;

use Netivo\Module\WooCommerce\SplitOrder\Admin\Order;
use Netivo\Module\WooCommerce\SplitOrder\Admin\Panel;
use PHPUnit\Framework\TestCase;
use Brain\Monkey;
use Mockery;

final class AdminTest extends TestCase {
	protected function setUp(): void {
		Monkey\setUp();
		$GLOBALS['wp_actions']    = [];
		$GLOBALS['wc_order_mock'] = null;
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		Mockery::close();
	}

	public function test_panel_initializes_order(): void {
		// Panel constructor should instantiate Order, which calls add_action
		new Panel();

		$this->assertCount( 1, $GLOBALS['wp_actions'] );
		$this->assertSame( 'woocommerce_admin_order_data_after_payment_info', $GLOBALS['wp_actions'][0]['hook'] );
		$this->assertInstanceOf( Order::class, $GLOBALS['wp_actions'][0]['callable'][0] );
	}

	public function test_order_add_split_order_info_skips_if_not_split(): void {
		$order = Mockery::mock( 'WC_Order' );
		$order->shouldReceive( 'get_meta' )->with( '_order_split' )->andReturn( 'no' );

		$admin_order = new Order();

		ob_start();
		$admin_order->add_split_order_info( $order );
		$output = ob_get_clean();

		$this->assertEmpty( $output );
	}

	public function test_order_add_split_order_info_source_order(): void {
		$order = Mockery::mock( 'WC_Order' );
		$order->shouldReceive( 'get_meta' )->with( '_order_split' )->andReturn( 'yes' );
		$order->shouldReceive( 'get_meta' )->with( '_split_to_order' )->andReturn( 123 );

		$dest_order = Mockery::mock( 'WC_Order' );
		$dest_order->shouldReceive( 'get_edit_order_url' )->andReturn( 'http://example.com/edit/123' );
		$dest_order->shouldReceive( 'get_order_number' )->andReturn( '123' );

		$GLOBALS['wc_order_mock'] = $dest_order;

		$admin_order = new Order();

		ob_start();
		$admin_order->add_split_order_info( $order );
		$output = ob_get_clean();

		$this->assertStringContainsString( 'order_data_header', $output );
		$this->assertStringContainsString( 'Część zamówienia została wydzielona do zamówienia', $output );
		$this->assertStringContainsString( 'href="http://example.com/edit/123"', $output );
		$this->assertStringContainsString( '>123</a>', $output );
	}

	public function test_order_add_split_order_info_destination_order(): void {
		$order = Mockery::mock( 'WC_Order' );
		$order->shouldReceive( 'get_meta' )->with( '_order_split' )->andReturn( 'yes' );
		$order->shouldReceive( 'get_meta' )->with( '_split_to_order' )->andReturn( '' );
		$order->shouldReceive( 'get_meta' )->with( '_split_from_order' )->andReturn( 456 );

		$source_order = Mockery::mock( 'WC_Order' );
		$source_order->shouldReceive( 'get_edit_order_url' )->andReturn( 'http://example.com/edit/456' );
		$source_order->shouldReceive( 'get_order_number' )->andReturn( '456' );

		$GLOBALS['wc_order_mock'] = $source_order;

		$admin_order = new Order();

		ob_start();
		$admin_order->add_split_order_info( $order );
		$output = ob_get_clean();

		$this->assertStringContainsString( 'order_data_header', $output );
		$this->assertStringContainsString( 'Zamówienie wydzielone z zamówienia', $output );
		$this->assertStringContainsString( 'href="http://example.com/edit/456"', $output );
		$this->assertStringContainsString( '>456</a>', $output );
	}
}
