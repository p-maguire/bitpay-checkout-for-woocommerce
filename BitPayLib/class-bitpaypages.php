<?php

declare(strict_types=1);

namespace BitPayLib;

/**
 * Plugin Name: BitPay Checkout for WooCommerce
 * Plugin URI: https://www.bitpay.com
 * Version: 5.5.1
 * Author: BitPay
 * Author URI: mailto:integrations@bitpay.com?subject=BitPay Checkout for WooCommerce
 */
class BitPayPages {

	private BitPayPaymentSettings $bitpay_payment_settings;

	public function __construct( BitPayPaymentSettings $bitpay_payment_settings ) {
		$this->bitpay_payment_settings = $bitpay_payment_settings;
	}

	public function checkout_thank_you( int $order_id ): void {
		global $woocommerce;
		$order     = new \WC_Order( $order_id );
		$use_modal = $this->bitpay_payment_settings->should_use_modal();
		if ( ! $use_modal || $order->get_payment_method() !== 'bitpay_checkout_gateway' ) {
			return;
		}

		$restore_url = get_home_url() . '/wp-json/bitpay/cartfix/restore';

		$test_mode = false;
		$js_script = '../../js/bitpay.js';
		if ( $this->bitpay_payment_settings->get_bitpay_environment() === 'test' ) {
			$test_mode = true;
			$js_script = '../../js/testbitpay.js';
		}

		$invoice_id = $_COOKIE['bitpay-invoice-id'] ?? null; // phpcs:ignore

		wp_enqueue_script( 'remote-bitpay-js', plugins_url($js_script, __FILE__ ), null, BitPayPluginSetup::VERSION, false ); // phpcs:ignore
		wp_enqueue_script( 'bitpay_thank_you', plugins_url( '../../js/bitpay_thank_you.js', __FILE__ ), null, BitPayPluginSetup::VERSION, false ); // phpcs:ignore
		?>
		<script type="text/javascript">
			const testMode = '<?php echo esc_js( $test_mode ); ?>';
			const invoiceID = '<?php echo esc_js( $invoice_id ); ?>';
			const orderID = '<?php echo esc_js( $order_id ); ?>';
			const cartUrl = '<?php echo esc_js( wc_get_cart_url() ); ?>';
			const restoreUrl = '<?php echo esc_js( $restore_url ); ?>';
			showBitPayInvoice( testMode, invoiceID, orderID, cartUrl, restoreUrl );
		</script>
		<?php
	}
}
