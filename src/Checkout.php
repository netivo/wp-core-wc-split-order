<?php
/**
 * Created by Netivo for wp-core-wc-split-order
 * User: manveru
 * Date: 6.02.2026
 * Time: 15:45
 *
 */

namespace Netivo\Module\WooCommerce\SplitOrder;

use JetBrains\PhpStorm\NoReturn;
use WC_Order;

if ( ! defined( 'ABSPATH' ) ) {
	header( 'HTTP/1.0 403 Forbidden' );
	exit;
}

/**
 * Class Checkout
 * Handles custom WooCommerce functionality related to splitting orders, modifying shipping options,
 * updating order details, and responding to user actions in the checkout process.
 *
 * Utilizes WooCommerce hooks, filters, and actions to implement the following functionality:
 * - Allow users to select a "split shipping" option during checkout.
 * - Update shipping costs and labels if split shipping is selected.
 * - Save and process the split shipping option during and after the order is created.
 * - Modify the display of order details including order numbers, totals, and items.
 * - Handle AJAX updates to the split shipping option during checkout.
 * - Customize actions on the "Thank You" page and order details table.
 * - Conditionally split orders based on predefined rules.
 */
class Checkout {
	public function __construct() {
		add_action( 'woocommerce_review_order_before_shipping', array( $this, 'add_split_shipping_option_in_table' ) );
		add_filter( 'woocommerce_package_rates', array( $this, 'update_shipping_costs' ), 100, 2 );
		add_filter( 'woocommerce_cart_shipping_method_full_label', array(
			$this,
			'modify_double_delivery_label'
		), 100, 2 );

		add_action( 'woocommerce_checkout_order_created', array( $this, 'save_split_shipping_option' ) );
		add_action( 'woocommerce_payment_complete', array( $this, 'split_order_after_payment' ), 10, 1 );
		add_action( 'woocommerce_checkout_order_processed', array( $this, 'split_order_after_checkout' ), 10, 1 );

		add_action( 'wp_ajax_update_split_shipping', array( $this, 'update_split_shipping_ajax' ) );
		add_action( 'wp_ajax_nopriv_update_split_shipping', array( $this, 'update_split_shipping_ajax' ) );

		add_filter( 'woocommerce_order_number', array( $this, 'modify_order_number' ), 99, 2 );
		add_filter( 'woocommerce_get_formatted_order_total', array( $this, 'modify_order_total' ), 99, 2 );

		add_filter( 'woocommerce_get_order_item_totals', array( $this, 'modify_order_item_totals' ), 10, 2 );

		remove_action( 'woocommerce_thankyou', 'woocommerce_order_details_table', 10 );

		add_action( 'woocommerce_thankyou', array( $this, 'order_details_table' ), 10 );

		add_action( 'woocommerce_order_details_before_order_table', array(
			$this,
			'before_order_details_table'
		), 10, 1 );
	}

	/**
	 * Adds an option in the shipping table to allow splitting the order into multiple shipments.
	 * The method checks if the order can be split and, if so, renders a checkbox in the shipping totals section.
	 *
	 * @return void
	 */
	public function add_split_shipping_option_in_table(): void {
		if ( ! $this->can_order_be_split() ) {
			return;
		}

		$split_shipping = WC()->session->get( 'split_shipping', false );
		?>
        <tr class="woocommerce-shipping-totals-shipping-split">
            <th colspan="2">
                <label>
                    <input type="checkbox"
                           name="split_shipping"
                           id="split_shipping"
                           value="1"
						<?php checked( $split_shipping, true ); ?>
                    />
					<?php esc_html_e( 'Podzielić zamówienie?', 'netivo' ); ?>
                </label>
            </th>
        </tr>
		<?php
	}

	/**
	 * Updates the shipping costs for the given rates when the "split shipping" option is enabled.
	 *
	 * This method checks the session for the "split_shipping" option, and if enabled, doubles
	 * the shipping costs, updates the corresponding taxes, and modifies the label to indicate
	 * the doubled shipping cost.
	 *
	 * @param array $rates An array of shipping rates. Each rate contains cost, taxes, and label information.
	 * @param mixed $package A package object or array representing the shipping package details.
	 *
	 * @return array The updated array of shipping rates after applying modifications.
	 */
	public function update_shipping_costs( array $rates, $package ): array {
		// Sprawdź, czy opcja podziału jest wybrana
		$split_shipping = WC()->session->get( 'split_shipping', false );

		if ( $split_shipping && $this->can_order_be_split() && Module::duplicate_delivery_cost() ) {
			// Podwojenie kosztów wysyłki
			foreach ( $rates as $rate_id => $rate ) {
				// Podwój koszt
				$rates[ $rate_id ]->cost *= 2;

				// Aktualizuj wyświetlaną cenę
				$taxes = array();
				foreach ( $rates[ $rate_id ]->taxes as $tax_id => $tax ) {
					$taxes[ $tax_id ] = $tax * 2;
				}
				$rates[ $rate_id ]->taxes = $taxes;

				// Aktualizacja etykiety
				$rates[ $rate_id ]->label .= ' ' . __( '(podwójna dostawa)', 'netivo' );
			}
		}

		return $rates;
	}

	/**
	 * Modifies the delivery label to format it correctly and append a "double delivery" indicator if applicable.
	 *
	 * This method checks if the label includes an indication of double delivery. It cleans and separates
	 * the method name and price, reformats the label accordingly, and appends a "double delivery" message
	 * when applicable.
	 *
	 * @param string $label The original delivery label potentially containing a "double delivery" tag.
	 * @param mixed $method The delivery method used; this value can hold additional contextual information.
	 *
	 * @return string The updated delivery label formatted with or without the "double delivery" indicator.
	 */
	public function modify_double_delivery_label( string $label, $method ): string {

		$has_double_delivery = strpos( $label, ' (podwójna dostawa)' ) !== false;
		$clean_label         = str_replace( ' (podwójna dostawa)', '', $label );

		$parts       = explode( ':', $clean_label, 2 );
		$method_name = isset( $parts[0] ) ? trim( $parts[0] ) : $clean_label;
		$price       = isset( $parts[1] ) ? trim( $parts[1] ) : '';

		$output = $method_name . ': ' . $price;

		if ( $has_double_delivery ) {
			$output .= '<span class="double-delivery"> </br> (podwójna dostawa) </span>';
		}

		return $output;
	}

	/**
	 * Saves the "split shipping" option for a given order based on the user's input.
	 *
	 * This method checks the request data to determine if the "split shipping" option was selected.
	 * It updates the order's metadata to indicate whether the option is enabled or disabled and
	 * saves the changes to the order.
	 *
	 * @param WC_Order $order The order object to which the "split shipping" option metadata will be saved.
	 *
	 * @return void This method does not return a value.
	 */
	public function save_split_shipping_option( $order ): void {
		if ( isset( $_POST['split_shipping'] ) && $_POST['split_shipping'] == 1 ) {
			$order->update_meta_data( '_order_to_split', 'yes' );
		} else {
			$order->update_meta_data( '_order_to_split', 'no' );
		}
		$order->save();
	}

	/**
	 * Splits an order into multiple sub-orders after payment is completed.
	 *
	 * This method processes the specified order by dividing it into smaller sub-orders
	 * based on predefined criteria. It ensures that the splitting logic is applied
	 * only after the payment for the original order has been successfully made.
	 *
	 * @param int $order_id The ID of the order to be split.
	 *
	 * @return void This method does not return a value.
	 */
	public function split_order_after_payment( int $order_id ): void {
		$this->split_order( $order_id );
	}

	/**
	 * Splits the order after checkout based on specific payment methods.
	 *
	 * This method checks the payment method of the given order. If the payment method is "bacs,"
	 * it triggers the order splitting process and assigns an appropriate order status based on
	 * a configurable filter.
	 *
	 * @param int $order_id The ID of the order to be processed and potentially split.
	 *
	 * @return void No return value as the method processes the order directly.
	 */
	public function split_order_after_checkout( int $order_id ): void {
		$order = wc_get_order( $order_id );
		if ( $order ) {
			if ( $order->get_payment_method() === 'bacs' ) {
				$this->split_order( $order, apply_filters( 'woocommerce_bacs_process_payment_order_status', \Automattic\WooCommerce\Enums\OrderStatus::ON_HOLD, $order ) );
			}
		}
	}

	/**
	 * Handles the AJAX request to update the "split shipping" option and refresh cart fragments.
	 *
	 * This method processes the `split_shipping` value from the `$_POST` request, updates the session
	 * with the specified value, clears the shipping cache to force recalculation, and recalculates the
	 * shipping costs and cart totals. It then refreshes the checkout order review fragment and
	 * sends the updated fragments as a JSON response.
	 *
	 * @return void This method does not return a value. It outputs a JSON response and terminates execution.
	 */
	#[NoReturn]
	public function update_split_shipping_ajax(): void {
		if ( isset( $_POST['split_shipping'] ) ) {
			$split_shipping = $_POST['split_shipping'] === 'true' || $_POST['split_shipping'] === true;

			// Ustaw wartość w sesji
			WC()->session->set( 'split_shipping', $split_shipping );

			// Wyczyść pamięć podręczną wysyłki, aby wymusić jej ponowne obliczenie
			WC()->session->set( 'shipping_for_package_0', null );

			// Wymuszenie przeliczenia kosztów wysyłki
			WC()->cart->calculate_shipping();
			WC()->cart->calculate_totals();
		}

		// Odśwież fragmenty koszyka
		ob_start();
		wc_get_template( 'checkout/review-order.php' );
		$woocommerce_checkout_review_order = ob_get_clean();

		$data = array(
			'fragments' => array(
				'.woocommerce-checkout-review-order-table' => $woocommerce_checkout_review_order
			),
			'refresh'   => true
		);

		wp_send_json( $data );
		wp_die();
	}

	/**
	 * Modifies the order number during the checkout process by appending an additional ID if a split order is detected.
	 *
	 * This method checks if the current request is during checkout and retrieves the split order associated with the
	 * provided order. If a split order exists, its ID is appended to the original order number.
	 *
	 * @param string $order_number The original order number to be potentially modified.
	 * @param WC_Order $order The WooCommerce order object associated with the order number.
	 *
	 * @return string The modified or original order number, with a split order ID appended if applicable.
	 */
	public function modify_order_number( string $order_number, WC_Order $order ): string {
		if ( is_checkout() ) {
			$split_order = $this->get_split_order( $order );
			if ( ! empty( $split_order ) ) {
				return $order_number . ', ' . $split_order->get_id();
			}
		}

		return $order_number;
	}

	/**
	 * Modifies the order total during the checkout process when a split order exists.
	 *
	 * This method checks if the current context is the checkout and whether a split order
	 * is associated with the given order. If a split order exists, it calculates the new
	 * total by combining the totals of the main order and the split order, and formats
	 * the result as a price.
	 *
	 * @param string $total The current formatted total amount for the order.
	 * @param WC_Order $order The WooCommerce order object for which the total is being modified.
	 *
	 * @return string The modified formatted total amount, or the original total if no split order exists.
	 */
	public function modify_order_total( string $total, WC_Order $order ): string {
		if ( is_checkout() ) {
			$split_order = $this->get_split_order( $order );
			if ( ! empty( $split_order ) ) {
				$new_total = $order->get_total() + $split_order->get_total();

				return wc_price( $new_total );
			}
		}

		return $total;
	}

	/**
	 * Displays the order details table for a specified order ID. If the order has a split order associated with it,
	 * the details table for the split order will also be displayed.
	 *
	 * @param int $order_id The ID of the order for which the details table should be generated.
	 *
	 * @return void
	 */
	public function order_details_table( int $order_id ): void {
		if ( ! $order_id ) {
			return;
		}

		$order = wc_get_order( $order_id );

		if ( ! $order ) {
			return;
		}

		$split_order = $this->get_split_order( $order );

		woocommerce_order_details_table( $order_id );

		if ( ! empty( $split_order ) ) {
			woocommerce_order_details_table( $split_order->get_id() );
		}
	}

	/**
	 * Modifies the order item totals array for an order. If the current context is the checkout,
	 * and the order has a split order associated with it, the order total value is updated.
	 *
	 * @param array $totals An associative array of order item totals, including labels and values.
	 * @param WC_Order $order The WooCommerce order object for which the totals are being processed.
	 *
	 * @return array The modified array of order item totals.
	 */
	public function modify_order_item_totals( array $totals, WC_Order $order ): array {
		if ( is_checkout() ) {
			$split_order = $this->get_split_order( $order );
			if ( ! empty( $split_order ) ) {
				$totals['order_total']['value'] = wc_price( $order->get_total() );
			}
		}

		return $totals;
	}

	/**
	 * Executes actions before displaying the order details table. If the order is marked as split during checkout,
	 * it removes the custom order number filter and displays a custom order title.
	 *
	 * @param WC_Order $order The WooCommerce order object associated with the current order.
	 *
	 * @return void
	 */
	public function before_order_details_table( WC_Order $order ): void {
		if ( is_checkout() ) {
			if ( $order->get_meta( '_order_split' ) === 'yes' ) {
				remove_filter( 'woocommerce_order_number', array( $this, 'modify_order_number' ), 99 );
				?>
                <h2 class="woocommerce-order-details__title"><?php echo wp_kses_post( sprintf( __( 'Zamówienie %s', 'netivo' ), $order->get_order_number() ) ); ?></h2>
				<?php
			}
		}
	}

	/**
	 * Determines whether an order can be split based on the current module configuration
	 * and order context.
	 *
	 * The method checks the configuration for when to split orders ('always', 'b2b_only',
	 * or 'client_only') and evaluates the context, such as whether it is a B2B or client-specific
	 * scenario. It also ensures there are eligible products to split in the order.
	 *
	 * @return bool True if the order can be split, false otherwise.
	 */
	protected function can_order_be_split(): bool {
		if ( Module::when_to_split_order() == 'always' ) {
			return ! empty( $this->get_products_to_split() );
		}
		if ( Module::when_to_split_order() == 'b2b_only' ) {
			if ( class_exists( '\Netivo\Module\WooCommerce\B2B\Module' ) ) {
				if ( \Netivo\Module\WooCommerce\B2B\Module::is_b2b_context() ) {
					return ! empty( $this->get_products_to_split() );
				}
			}
		}
		if ( Module::when_to_split_order() == 'client_only' ) {
			if ( class_exists( '\Netivo\Module\WooCommerce\B2B\Module' ) ) {
				if ( ! \Netivo\Module\WooCommerce\B2B\Module::is_b2b_context() ) {
					return ! empty( $this->get_products_to_split() );
				}
			} else {
				return ! empty( $this->get_products_to_split() );
			}
		}

		return false;
	}

	/**
	 * Retrieves a list of products that need to be split based on their stock status or quantity.
	 * If no order is supplied, it evaluates the cart items. Otherwise, it evaluates the order items.
	 *
	 * @param WC_Order|null $order An optional WooCommerce order object. If null, the method evaluates the cart instead of an order.
	 *
	 * @return array An array of products or order items that need to be split due to stock issues.
	 *               For cart evaluation, the array contains cart item data.
	 *               For order evaluation, the array contains order item data indexed by their IDs.
	 */
	protected function get_products_to_split( ?WC_Order $order = null ): array {
		if ( $order === null ) {
			global $products_to_split;
			if ( $products_to_split !== null ) {
				return $products_to_split;
			}
			if ( WC()->cart ) {
				$products_to_split = array();
				foreach ( WC()->cart->get_cart() as $cart_item ) {
					$c_product = wc_get_product( $cart_item['product_id'] );
					if ( empty( $c_product ) ) {
						continue;
					}
					if ( $c_product->get_manage_stock() ) {
						if ( $c_product->get_stock_quantity() <= (int) $cart_item['quantity'] ) {
							$products_to_split[] = $cart_item;
						}
					} else {
						if ( $c_product->get_stock_status() !== 'instock' ) {
							$products_to_split[] = $cart_item;
						}
					}
				}
			}

			return $products_to_split ?? array();
		} else {
			$to_split = array();
			foreach ( $order->get_items() as $order_item ) {
				$c_product = wc_get_product( $order_item['product_id'] );
				if ( empty( $c_product ) ) {
					continue;
				}
				if ( $c_product->get_manage_stock() ) {
					if ( $c_product->get_stock_quantity() <= 0 ) {
						$to_split[ $order_item->get_id() ] = $order_item;
					}
				} else {
					if ( $c_product->get_stock_status() !== 'instock' ) {
						$to_split[ $order_item->get_id() ] = $order_item;
					}
				}
			}

			return $to_split;
		}
	}

	/**
	 * Splits an order into two separate orders based on product stock availability or conditions.
	 * The original order is updated, and a new order is created with split items.
	 *
	 * @param WC_Order|int $order The WooCommerce order object or its ID to be split.
	 * @param string|null $order_status Optional. The status to assign to the created split order.
	 *
	 * @return int The ID of the newly created split order, or:
	 *             -1 if the order couldn't be retrieved,
	 *             -2 if the order was already split,
	 *             0 if no products required splitting.
	 */
	protected function split_order( WC_Order|int $order, ?string $order_status = null ): int {
		if ( ! is_a( $order, WC_Order::class ) ) {
			$order = wc_get_order( $order );
		}

		if ( empty( $order ) ) {
			return - 1;
		}

		if ( $order->get_meta( '_order_split' ) === 'yes' ) {
			return - 2;
		}

		if ( $order->get_meta( '_order_to_split' ) !== 'yes' ) {
			return 0;
		}

		$products_to_split = $this->get_products_to_split( $order );
		if ( empty( $products_to_split ) ) {
			return 0;
		}

		$split_order = $this->clone_order( $order, $order_status );

		if ( Module::duplicate_delivery_cost() ) {
			foreach ( $order->get_shipping_methods() as $shipping_item ) {
				$half_total = $shipping_item->get_total() / 2;
				$taxes      = $shipping_item->get_taxes();
				$half_taxes = array();
				if ( ! empty( $taxes['total'] ) ) {
					foreach ( $taxes['total'] as $tax_id => $tax_amount ) {
						$half_taxes[ $tax_id ] = $tax_amount / 2;
					}
				}

				// Update original order shipping item
				$shipping_item->set_total( $half_total );
				$shipping_item->set_taxes( array( 'total' => $half_taxes ) );
				$shipping_item->save();

				// Add to split order
				$item = new \WC_Order_Item_Shipping();
				$item->set_method_id( $shipping_item->get_method_id() );
				$item->set_method_title( $shipping_item->get_method_title() );
				$item->set_total( $half_total );
				$item->set_taxes( array( 'total' => $half_taxes ) );
				$split_order->add_item( $item );
			}
		}


		foreach ( $products_to_split as $line_id => $pts ) {
			$item_id = $split_order->add_product(
				wc_get_product( $pts->get_product_id() ),
				$pts->get_quantity(),
				array(
					'variation' => $pts->get_variation_id() ? $pts->get_variation_data() : array(),
					'totals'    => array(
						'subtotal'     => $pts->get_subtotal(),
						'subtotal_tax' => $pts->get_subtotal_tax(),
						'total'        => $pts->get_total(),
						'tax'          => $pts->get_total_tax(),
					)
				)
			);

			if ( $item_id ) {
				$new_item = $split_order->get_item( $item_id );
				// Kopiowanie meta danych pozycji, jeśli istnieją
				foreach ( $pts->get_meta_data() as $meta ) {
					$new_item->add_meta_data( $meta->key, $meta->value, true );
				}
				$new_item->save();
				$order->remove_item( $line_id );
			}
		}

		$order->calculate_totals();
		$split_order->calculate_totals();

		$order->update_meta_data( '_order_to_split', 'no' );

		$order->save();
		$split_order->save();

		return $split_order->get_id();
	}

	/**
	 * Clones an existing WooCommerce order to create a new order with similar properties and metadata.
	 * Optionally allows setting a custom order status for the newly created order.
	 *
	 * @param WC_Order $order The original WooCommerce order object to be cloned.
	 * @param string|null $order_status Optional. The desired status for the new order. If null, the status from the original order is used.
	 *
	 * @return WC_Order The newly created WooCommerce order object.
	 */
	protected function clone_order( WC_Order $order, ?string $order_status = null ): WC_Order {
		if ( ! is_a( $order, 'WC_Order' ) ) {
			$order = wc_get_order( $order );
		}

		$new_order = wc_create_order( array(
			'customer_id' => $order->get_customer_id(),
		) );

		$new_order->set_props( array(
			'status'               => ( ! empty( $order_status ) ) ? $order_status : $order->get_status(),
			'billing_first_name'   => $order->get_billing_first_name(),
			'billing_last_name'    => $order->get_billing_last_name(),
			'billing_company'      => $order->get_billing_company(),
			'billing_address_1'    => $order->get_billing_address_1(),
			'billing_address_2'    => $order->get_billing_address_2(),
			'billing_city'         => $order->get_billing_city(),
			'billing_state'        => $order->get_billing_state(),
			'billing_postcode'     => $order->get_billing_postcode(),
			'billing_country'      => $order->get_billing_country(),
			'billing_email'        => $order->get_billing_email(),
			'billing_phone'        => $order->get_billing_phone(),
			'shipping_first_name'  => $order->get_shipping_first_name(),
			'shipping_last_name'   => $order->get_shipping_last_name(),
			'shipping_company'     => $order->get_shipping_company(),
			'shipping_address_1'   => $order->get_shipping_address_1(),
			'shipping_address_2'   => $order->get_shipping_address_2(),
			'shipping_city'        => $order->get_shipping_city(),
			'shipping_state'       => $order->get_shipping_state(),
			'shipping_postcode'    => $order->get_shipping_postcode(),
			'shipping_country'     => $order->get_shipping_country(),
			'shipping_phone'       => $order->get_shipping_phone(),
			'payment_method'       => $order->get_payment_method(),
			'payment_method_title' => $order->get_payment_method_title(),
			'customer_note'        => $order->get_customer_note(),
		) );

		// Kopiowanie meta danych zamówienia
		$new_order->update_meta_data( '_order_split', 'yes' );
		$new_order->update_meta_data( '_split_from_order', $order->get_id() );

		if ( $order->get_meta( '_nip' ) ) {
			$new_order->update_meta_data( '_nip', $order->get_meta( '_nip' ) );
		}

		$order->update_meta_data( '_order_split', 'yes' );
		$order->update_meta_data( '_split_to_order', $new_order->get_id() );

		return $new_order;
	}

	/**
	 * Retrieves the secondary order associated with a split order process, if it exists.
	 * The method checks whether the given order is marked as split and attempts to fetch the linked secondary order.
	 *
	 * @param WC_Order $order A WooCommerce order object to check for split order metadata.
	 *
	 * @return WC_Order|null The secondary split order object if it exists, or null if no split order is found.
	 */
	protected function get_split_order( WC_Order $order ): ?WC_Order {
		global $second_split_order;
		if ( $order->get_meta( '_order_split' ) === 'yes' ) {
			$split_id = $order->get_meta( '_split_to_order' );
			if ( ! empty( $split_id ) ) {
				if ( ! empty( $second_split_order ) ) {
					return $second_split_order;
				}
				$second_split_order = wc_get_order( $split_id );
				if ( ! empty( $second_split_order ) ) {
					return $second_split_order;
				}
			}
		}

		return null;
	}
}