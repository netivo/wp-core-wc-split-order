<?php

declare( strict_types=1 );

namespace Netivo\Module\WooCommerce\SplitOrder\Tests;

use Netivo\Module\WooCommerce\SplitOrder\Checkout;
use PHPUnit\Framework\TestCase;
use Brain\Monkey;

final class CheckoutTest extends TestCase {
	protected function setUp(): void {
		Monkey\setUp();
		$GLOBALS['wp_actions']         = [];
		$GLOBALS['wp_filters']         = [];
		$GLOBALS['wp_removed_actions'] = [];
	}

	protected function tearDown(): void {
		Monkey\tearDown();
	}

	public function test_constructor_registers_expected_hooks_and_filters(): void {
		new Checkout();

		$hooks   = array_column( $GLOBALS['wp_actions'], 'hook' );
		$filters = array_column( $GLOBALS['wp_filters'], 'hook' );

		$this->assertContains( 'woocommerce_review_order_before_shipping', $hooks );
		$this->assertContains( 'woocommerce_checkout_order_created', $hooks );
		$this->assertContains( 'woocommerce_payment_complete', $hooks );
		$this->assertContains( 'woocommerce_checkout_order_processed', $hooks );
		$this->assertContains( 'wp_ajax_update_split_shipping', $hooks );
		$this->assertContains( 'wp_ajax_nopriv_update_split_shipping', $hooks );
		$this->assertContains( 'woocommerce_thankyou', $hooks );
		$this->assertContains( 'woocommerce_order_details_before_order_table', $hooks );

		$this->assertContains( 'woocommerce_package_rates', $filters );
		$this->assertContains( 'woocommerce_cart_shipping_method_full_label', $filters );
		$this->assertContains( 'woocommerce_order_number', $filters );
		$this->assertContains( 'woocommerce_get_formatted_order_total', $filters );
		$this->assertContains( 'woocommerce_get_order_item_totals', $filters );

		// Removed default thankyou table
		$removedHooks     = array_column( $GLOBALS['wp_removed_actions'], 'hook' );
		$removedCallables = array_column( $GLOBALS['wp_removed_actions'], 'callable' );
		$this->assertContains( 'woocommerce_thankyou', $removedHooks );
		$this->assertContains( 'woocommerce_order_details_table', $removedCallables );
	}

	public function test_modify_double_delivery_label_formats_correctly(): void {
		$checkout = new Checkout();

		$withDouble = 'Kurier: 10,00 zł (podwójna dostawa)';
		$out1       = $checkout->modify_double_delivery_label( $withDouble, null );
		$this->assertSame( 'Kurier: 10,00 zł<span class="double-delivery"> </br> (podwójna dostawa) </span>', $out1 );

		$plain = 'Kurier: 10,00 zł';
		$out2  = $checkout->modify_double_delivery_label( $plain, null );
		$this->assertSame( 'Kurier: 10,00 zł', $out2 );
	}
}
