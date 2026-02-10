<?php
/**
 * Created by Netivo for wp-core-wc-split-order
 * User: manveru
 * Date: 6.02.2026
 * Time: 15:42
 *
 */

namespace Netivo\Module\WooCommerce\SplitOrder;

if ( ! defined( 'ABSPATH' ) ) {
	header( 'HTTP/1.0 403 Forbidden' );
	exit;
}

class Module {

	/**
	 * Holds the instance of the class or object, initialized to null.
	 */
	protected static ?self $instance = null;

	protected array $config = array();

	/**
	 * Retrieves the singleton instance of the class.
	 *
	 * @return self Returns the single instance of the class.
	 */
	public static function get_instance(): self {
		if ( empty( self::$instance ) ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Retrieves the configuration array by accessing the singleton instance.
	 *
	 * @return array The configuration array containing key-value pairs.
	 */
	public static function get_config_array(): array {
		return self::get_instance()->get_config();
	}

	public static function when_to_split_order(): string {
		return self::get_config_array()['split_order'];
	}

	public static function duplicate_delivery_cost(): bool {
		return self::get_config_array()['duplicate_delivery_cost'];
	}

	public static function get_module_path(): false|string|null {
		$file = realpath( __DIR__ . '/../' );
		if ( file_exists( $file ) ) {
			return $file;
		}

		return null;
	}

	protected function __construct() {
		$this->init_config();
		global $products_to_split;
		$products_to_split = null;

		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_scripts' ) );

		new Checkout();
	}

	public function enqueue_scripts() {
		if ( is_checkout() || is_cart() ) {

			wp_enqueue_script(
				'nt-split-orders',
				self::get_module_path() . 'dist/split-orders.js',
				array( 'jquery' ),
				'1.0.3',
				true
			);

			wp_localize_script(
				'nt-split-orders',
				'nt_split_orders_params',
				array(
					'ajax_url' => admin_url( 'admin-ajax.php' ),
					'nonce'    => wp_create_nonce( 'nt-split-orders' )
				)
			);
		}
	}


	public function init_config(): void {
		if ( file_exists( get_stylesheet_directory() . "/config/split-order.config.php" ) ) {
			$this->config = include get_stylesheet_directory() . "/config/split-order.config.php";
		}

		if ( ! array_key_exists( 'split_order', $this->config ) ) {
			$this->config['split_order'] = 'always';
		}
		if ( ! array_key_exists( 'duplicate_delivery_cost', $this->config ) ) {
			$this->config['duplicate_delivery_cost'] = true;
		}
	}

	public function get_config(): array {
		return $this->config;
	}

}