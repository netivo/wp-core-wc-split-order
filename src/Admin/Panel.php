<?php
/**
 * Created by Netivo for wp-core-wc-split-order
 * User: manveru
 * Date: 13.02.2026
 * Time: 12:12
 *
 */

namespace Netivo\Module\WooCommerce\SplitOrder\Admin;

if ( ! defined( 'ABSPATH' ) ) {
	header( 'HTTP/1.0 403 Forbidden' );
	exit;
}

/**
 * Represents a user interface panel.
 *
 * The Panel class is responsible for initializing its components or
 * dependencies when instantiated.
 */
class Panel {


	public function __construct() {
		new Order();
	}
}