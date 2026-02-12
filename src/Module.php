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

	/**
	 * Stores the configuration settings as an empty array, which can be populated as needed.
	 */
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

	/**
	 * Determines the configured condition under which an order should be split.
	 *
	 * @return string The configuration value that specifies the criteria for splitting orders.
	 */
	public static function when_to_split_order(): string {
		return self::get_config_array()['split_order'];
	}

	/**
	 * Checks if the delivery cost duplication feature is enabled based on the configuration settings.
	 *
	 * @return bool Returns true if the duplicate delivery cost feature is enabled, false otherwise.
	 */
	public static function duplicate_delivery_cost(): bool {
		return self::get_config_array()['duplicate_delivery_cost'];
	}

	/**
	 * Retrieves the absolute path of the module directory if it exists.
	 *
	 * @return false|string|null Returns the absolute path as a string if the directory exists,
	 *                           false if the path cannot be resolved, or null if the path does not exist.
	 */
	public static function get_module_path(): false|string|null {
		$file = realpath( __DIR__ . '/../' );
		if ( file_exists( $file ) ) {
			return $file;
		}

		return null;
	}

	/**
	 * Retrieves the URI of the module.
	 *
	 * @return false|string|null The module URI if available, false on failure, or null if the module path is empty.
	 */
	public static function get_module_uri(): false|string|null {
		$path = Module::get_module_path();
		if ( ! empty( $path ) ) {
			$td   = get_template_directory();
			$turl = get_template_directory_uri();

			return str_replace( $td, $turl, $path );
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

	/**
	 * Enqueues the necessary scripts for split orders functionality on the checkout or cart page.
	 *
	 * This method loads a JavaScript file, localizes script parameters, and ensures the script is only
	 * enqueued on the appropriate pages.
	 *
	 * @return void
	 */
	public function enqueue_scripts(): void {
		if ( is_checkout() || is_cart() ) {

			wp_enqueue_script(
				'nt-split-orders',
				self::get_module_uri() . '/dist/split-orders.js',
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


	/**
	 * Initializes the configuration settings for the class.
	 * Loads the configuration file if it exists and sets default values
	 * for missing configuration keys.
	 *
	 * @return void
	 */
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

	/**
	 * Retrieves the configuration settings.
	 *
	 * @return array The configuration settings as an associative array.
	 */
	public function get_config(): array {
		return $this->config;
	}

}