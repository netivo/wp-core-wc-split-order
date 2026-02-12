<?php
// PHPUnit bootstrap for WordPress/WooCommerce-less unit tests

// Load Composer autoloader if available
$autoload = __DIR__ . '/../vendor/autoload.php';
if ( file_exists( $autoload ) ) {
	require_once $autoload;
}

// Minimal WordPress function stubs used by the module
if ( ! function_exists( 'get_template_directory' ) ) {
	function get_template_directory() {
		return $GLOBALS['wp_template_directory'] ?? dirname( __DIR__ );
	}
}

if ( ! function_exists( 'get_template_directory_uri' ) ) {
	function get_template_directory_uri() {
		return $GLOBALS['wp_template_directory_uri'] ?? 'http://example.com/theme';
	}
}

if ( ! function_exists( 'get_stylesheet_directory' ) ) {
	function get_stylesheet_directory() {
		return $GLOBALS['wp_stylesheet_directory'] ?? dirname( __DIR__ );
	}
}

if ( ! function_exists( 'is_checkout' ) ) {
	function is_checkout() {
		return ! empty( $GLOBALS['wp_is_checkout'] );
	}
}

if ( ! function_exists( 'is_cart' ) ) {
	function is_cart() {
		return ! empty( $GLOBALS['wp_is_cart'] );
	}
}

if ( ! function_exists( 'admin_url' ) ) {
	function admin_url( $path = '' ) {
		return rtrim( $GLOBALS['wp_admin_url'] ?? 'http://example.com/wp-admin', '/' ) . '/' . ltrim( $path, '/' );
	}
}

if ( ! function_exists( 'wp_create_nonce' ) ) {
	function wp_create_nonce( $action = - 1 ) {
		return 'nonce-' . (string) $action;
	}
}

if ( ! function_exists( 'wp_enqueue_script' ) ) {
	function wp_enqueue_script( $handle, $src = '', $deps = array(), $ver = false, $in_footer = false ) {
		$GLOBALS['wp_scripts'][] = array(
			'handle'    => $handle,
			'src'       => $src,
			'deps'      => $deps,
			'ver'       => $ver,
			'in_footer' => $in_footer,
		);
	}
}

if ( ! function_exists( 'wp_localize_script' ) ) {
	function wp_localize_script( $handle, $object_name, $l10n ) {
		$GLOBALS['wp_localize'][ $handle ] = array(
			'object_name' => $object_name,
			'l10n'        => $l10n,
		);
	}
}

if ( ! function_exists( 'add_action' ) ) {
	function add_action( $hook, $callable, $priority = 10, $accepted_args = 1 ) {
		$GLOBALS['wp_actions'][] = compact( 'hook', 'callable', 'priority', 'accepted_args' );
	}
}

if ( ! function_exists( 'add_filter' ) ) {
	function add_filter( $hook, $callable, $priority = 10, $accepted_args = 1 ) {
		$GLOBALS['wp_filters'][] = compact( 'hook', 'callable', 'priority', 'accepted_args' );
	}
}

if ( ! function_exists( 'remove_action' ) ) {
	function remove_action( $hook, $callable, $priority = 10 ) {
		$GLOBALS['wp_removed_actions'][] = compact( 'hook', 'callable', 'priority' );
	}
}

if ( ! function_exists( '__' ) ) {
	function __( $text, $domain = null ) {
		return $text;
	}
}

if ( ! function_exists( 'esc_html_e' ) ) {
	function esc_html_e( $text, $domain = null ) {
		echo htmlspecialchars( $text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' );
	}
}

if ( ! function_exists( 'checked' ) ) {
	function checked( $checked, $current = true ) {
		if ( $checked == $current ) {
			echo 'checked="checked"';
		}
	}
}
