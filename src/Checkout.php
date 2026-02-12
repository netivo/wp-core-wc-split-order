<?php
/**
 * Created by Netivo for wp-core-wc-split-order
 * User: manveru
 * Date: 6.02.2026
 * Time: 15:45
 *
 */

namespace Netivo\Module\WooCommerce\SplitOrder;

use WC_Order;

if ( ! defined( 'ABSPATH' ) ) {
	header( 'HTTP/1.0 403 Forbidden' );
	exit;
}

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
	}

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

	public function update_shipping_costs( $rates, $package ) {
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

	public function modify_double_delivery_label( $label, $method ) {

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

	public function save_split_shipping_option( $order ): void {
		if ( isset( $_POST['split_shipping'] ) && $_POST['split_shipping'] == 1 ) {
			$order->update_meta_data( '_order_to_split', 'yes' );
		} else {
			$order->update_meta_data( '_order_to_split', 'no' );
		}
		$order->save();
	}

	public function split_order_after_payment( $order_id ): void {
		$this->split_order( $order_id );
	}

	public function split_order_after_checkout( $order_id ): void {
		$order = wc_get_order( $order_id );
		if ( $order ) {
			if ( $order->get_payment_method() === 'bacs' ) {
				$this->split_order( $order, apply_filters( 'woocommerce_bacs_process_payment_order_status', \Automattic\WooCommerce\Enums\OrderStatus::ON_HOLD, $order ) );
			}
		}
	}

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

	public function modify_order_number( $order_number, $order ): string {
		if ( is_checkout() ) {
			$split_order = $this->get_split_order( $order );
			if ( ! empty( $split_order ) ) {
				return $order_number . ', ' . $split_order->get_id();
			}
		}

		return $order_number;
	}

	public function modify_order_total( $total, $order ): string {
		if ( is_checkout() ) {
			$split_order = $this->get_split_order( $order );
			if ( ! empty( $split_order ) ) {
				$new_total = $order->get_total() + $split_order->get_total();

				return wc_price( $new_total );
			}
		}

		return $total;
	}

	public function order_details_table( $order_id ): void {
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

	public function modify_order_item_totals( $totals, $order ): array {
		if ( is_checkout() ) {
			$split_order = $this->get_split_order( $order );
			if ( ! empty( $split_order ) ) {
				$totals['order_total']['value'] = wc_price( $order->get_total() );
			}
		}

		return $totals;
	}

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

	protected function get_products_to_split( WC_Order $order = null ): array {
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
						if ( $c_product->get_stock_quantity() <= 0 ) {
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

	protected function split_order( WC_Order|int $order, $order_status = null ): int {
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

	protected function clone_order( $order, $order_status = null ): WC_Order {
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

	protected function get_split_order( $order ) {
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