<?php
/**
 * Created by Netivo for wp-core-wc-split-order
 * User: manveru
 * Date: 13.02.2026
 * Time: 12:13
 *
 */

namespace Netivo\Module\WooCommerce\SplitOrder\Admin;

if ( ! defined( 'ABSPATH' ) ) {
	header( 'HTTP/1.0 403 Forbidden' );
	exit;
}

/**
 * Handles operations related to WooCommerce split orders.
 *
 * This class provides functionality to display metadata for orders that are part of a split process
 * within the WooCommerce admin interface. It adds information about related orders (source or destination)
 * when viewing order details.
 */
class Order {

	public function __construct() {
		add_action( 'woocommerce_admin_order_data_after_payment_info', array( $this, 'add_split_order_info' ), 10, 1 );
	}

	/**
	 * Adds split order information to the order view in WooCommerce.
	 *
	 * This method checks if the given order is part of a split order process. If so,
	 * it retrieves the associated order (either the source or destination order),
	 * determines the type of relationship, and displays the relevant metadata.
	 *
	 * @param \WC_Order $order The WooCommerce order object to check and process.
	 *
	 * @return void
	 */
	public function add_split_order_info( \WC_Order $order ): void {
		$is_split_order = $order->get_meta( '_order_split' );
		if ( $is_split_order !== 'yes' ) {
			return;
		}

		$dest_order = $order->get_meta( '_split_to_order' );
		$type       = 'source';
		if ( empty( $dest_order ) ) {
			$dest_order = $order->get_meta( '_split_from_order' );
			$type       = 'dest';
			if ( empty( $dest_order ) ) {
				return;
			}
		}

		$dest_order = wc_get_order( $dest_order );
		if ( empty( $dest_order ) ) {
			return;
		}

		?>
        <div class="order_data_header">
            <p class="woocommerce-order-data__meta order_number">
				<?php if ( $type === 'source' ) : ?>
					<?php echo wp_kses_post( sprintf( __( 'Część zamówienia została wydzielona do zamówienia: <a href="%s" target="_blank">%s</a>', 'netivo' ), $dest_order->get_edit_order_url(), $dest_order->get_order_number() ) ); ?>
				<?php else : ?>
					<?php echo wp_kses_post( sprintf( __( 'Zamówienie wydzielone z zamówienia: <a href="%s" target="_blank">%s</a>', 'netivo' ), $dest_order->get_edit_order_url(), $dest_order->get_order_number() ) ); ?>
				<?php endif; ?>
            </p>
        </div>
		<?php
	}
}