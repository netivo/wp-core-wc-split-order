<?php

declare( strict_types=1 );

namespace Netivo\Module\WooCommerce\SplitOrder\Tests;

use Netivo\Module\WooCommerce\SplitOrder\Module;
use PHPUnit\Framework\TestCase;
use Brain\Monkey;
use ReflectionClass;

final class ModuleTest extends TestCase {
	protected function setUp(): void {
		Monkey\setUp();
		// Reset globals used by stubs
		$GLOBALS['wp_is_checkout']            = false;
		$GLOBALS['wp_is_cart']                = false;
		$GLOBALS['wp_scripts']                = [];
		$GLOBALS['wp_localize']               = [];
		$GLOBALS['wp_template_directory']     = null;
		$GLOBALS['wp_template_directory_uri'] = null;
		$GLOBALS['wp_stylesheet_directory']   = null;

		// Reset Module singleton via reflection to ensure clean state per test
		$ref  = new ReflectionClass( Module::class );
		$prop = $ref->getProperty( 'instance' );
		$prop->setValue( null, null );
	}

	protected function tearDown(): void {
		Monkey\tearDown();
	}

	public function test_get_instance_returns_singleton(): void {
		$a = Module::get_instance();
		$b = Module::get_instance();
		$this->assertSame( $a, $b );
	}

	public function test_init_config_defaults_when_no_file_present(): void {
		$module = Module::get_instance();
		$config = $module->get_config();
		$this->assertArrayHasKey( 'split_order', $config );
		$this->assertArrayHasKey( 'duplicate_delivery_cost', $config );
		$this->assertSame( 'always', $config['split_order'] );
		$this->assertTrue( $config['duplicate_delivery_cost'] );
	}

	public function test_get_module_path_points_to_project_root_of_module(): void {
		$path = Module::get_module_path();
		$this->assertNotEmpty( $path );
		$this->assertTrue( is_string( $path ) );
		$this->assertDirectoryExists( $path );
		// Expect that src dir is inside this path
		$this->assertDirectoryExists( $path . '/src' );
	}

	public function test_get_module_uri_is_mapped_from_template_dir(): void {
		$path = Module::get_module_path();
		$this->assertNotNull( $path );

		// Pretend theme directory is the parent of module path so str_replace works
		$themeDir                             = dirname( $path );
		$themeUri                             = 'http://example.com/theme';
		$GLOBALS['wp_template_directory']     = $themeDir;
		$GLOBALS['wp_template_directory_uri'] = $themeUri;

		$uri = Module::get_module_uri();
		$this->assertNotEmpty( $uri );
		$this->assertStringStartsWith( $themeUri, $uri );
		$this->assertStringEndsWith( '/' . basename( $path ), $uri );
	}

	public function test_enqueue_scripts_on_checkout_registers_assets_and_params(): void {
		$GLOBALS['wp_is_checkout'] = true;
		$GLOBALS['wp_scripts']     = [];
		$GLOBALS['wp_localize']    = [];

		Module::get_instance()->enqueue_scripts();

		$this->assertNotEmpty( $GLOBALS['wp_scripts'] );
		$script = $GLOBALS['wp_scripts'][0];
		$this->assertSame( 'nt-split-orders', $script['handle'] );
		$this->assertStringEndsWith( '/dist/split-orders.js', $script['src'] );
		$this->assertContains( 'jquery', $script['deps'] );
		$this->assertSame( '1.0.3', $script['ver'] );
		$this->assertTrue( $script['in_footer'] );

		$this->assertArrayHasKey( 'nt-split-orders', $GLOBALS['wp_localize'] );
		$l10n = $GLOBALS['wp_localize']['nt-split-orders'];
		$this->assertSame( 'nt_split_orders_params', $l10n['object_name'] );
		$this->assertArrayHasKey( 'ajax_url', $l10n['l10n'] );
		$this->assertArrayHasKey( 'nonce', $l10n['l10n'] );
		$this->assertStringEndsWith( '/admin-ajax.php', $l10n['l10n']['ajax_url'] );
		$this->assertSame( 'nonce-nt-split-orders', $l10n['l10n']['nonce'] );
	}
}
