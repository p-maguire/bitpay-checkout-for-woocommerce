<?php

declare(strict_types=1);

namespace BitPayLib;

use BitPayLib\Exception\BitPayInvalidOrder;
use BitPaySDK\Model\Facade;
use BitPaySDK\Model\Invoice\Invoice;
use WC_Order;
use WP_REST_Request;

/**
 * Plugin Name: BitPay Checkout for WooCommerce
 * Plugin URI: https://www.bitpay.com
 * Description: BitPay Checkout Plugin
 * Version: 5.5.0
 * Author: BitPay
 * Author URI: mailto:integrations@bitpay.com?subject=BitPay Checkout for WooCommerce
 */
class BitPayIpnProcess {

	use WpDbHelper;

	private const FINAL_WC_ORDER_STATUSES = array( 'refunded', 'cancelled', 'failed' );

	private BitPayCheckoutTransactions $bitpay_checkout_transactions;
	private BitPayLogger $logger;
	private array $gateway_settings = array();
	private BitPayClientFactory $factory;

	public function __construct(
		BitPayCheckoutTransactions $bitpay_checkout_transactions,
		BitPayClientFactory $factory,
		BitPayLogger $logger
	) {
		$this->bitpay_checkout_transactions = $bitpay_checkout_transactions;
		$this->logger                       = $logger;
		$this->factory                      = $factory;
	}

	public function execute( WP_REST_Request $request ): void {
		$data                = $request->get_body();
		$data                = json_decode( $data, true, 512, JSON_THROW_ON_ERROR );
		$event               = $data['event'] ?? null;
		$data                = $data['data'] ?? null;
		$data['event']       = $event;
		$data['requestDate'] = date( 'Y-m-d H:i:s' );
		$invoice_id          = $data['id'] ?? null;

		$this->logger->execute( $data, 'INCOMING IPN', true );
		if ( ! $event || ! $data || ! $invoice_id ) {
			$this->logger->execute( 'Wrong IPN request', 'INCOMING IPN ERROR', false, true );
			return;
		}

		try {
			$bitpay_invoice = $this->factory->create()->getInvoice( $invoice_id, Facade::POS, false );
			do_action( 'bitpay_checkout_woocoomerce_after_get_invoice', $bitpay_invoice );
			$order = new WC_Order( $bitpay_invoice->getOrderId() );
			$this->validate_order( $order, $invoice_id );
			$this->process( $bitpay_invoice, $order, $event['name'] );
		} catch ( BitPayInvalidOrder $e ) {
			return; // do nothing.
		} catch ( \Exception $e ) {
			$this->logger->execute( $e->getMessage(), 'INCOMING IPN ERROR', false, true );
		}
	}

	private function validate_order( WC_Order $order, string $invoice_id ): void {
		do_action( 'bitpay_checkout_woocoomerce_validate_wc_order', $order, $invoice_id );

		if ( $order->get_payment_method() !== 'bitpay_checkout_gateway' ) {
			$message = 'Order id = ' . $order->get_id() . ', BitPay invoice id = ' . $invoice_id
				. '. Current payment method = ' . $order->get_payment_method();
			$this->logger->execute( $message, 'Ignore IPN', true );
			throw new BitPayInvalidOrder();
		}

		if ( $this->bitpay_checkout_transactions->count_transaction_id( $invoice_id ) !== 1 ) {
			$message = 'Order id = ' . $order->get_id() . ', BitPay invoice id = ' . $invoice_id
				. '. Wrong transaction id ' . $invoice_id;
			$this->logger->execute( $message, 'Ignore IPN', true );
			throw new BitPayInvalidOrder();
		}
	}

	private function process( Invoice $bitpay_invoice, WC_Order $order, string $event_name ): void {
		do_action( 'bitpay_checkout_woocoomerce_before_process', $bitpay_invoice, $order, $event_name );

		// do not process order when it is in a final status.
		if ( $this->has_final_status( $order ) ) {
			$message = 'Order ' . $order->get_id() . ' has final status ' . $order->get_status()
				. '. Order status from ' . $event_name . ' was not updated for event ' . $event_name;
			$order->add_order_note( $message );

			return;
		}

		do_action( 'bitpay_checkout_woocoomerce_process', $bitpay_invoice, $order, $event_name );

		switch ( $event_name ) {
			case 'invoice_completed':
				$this->process_completed( $bitpay_invoice, $order );
				break;
			case 'invoice_confirmed':
				$this->process_confirmed( $bitpay_invoice, $order );
				break;
			case 'invoice_paidInFull':
				$this->process_processing( $bitpay_invoice, $order );
				break;
			case 'invoice_declined':
				$this->process_declined( $bitpay_invoice, $order );
				break;
			case 'invoice_failedToConfirm':
				$this->process_failed( $bitpay_invoice, $order );
				break;
			case 'invoice_expired':
				$this->process_abandoned( $bitpay_invoice, $order );
				break;
			case 'invoice_refundComplete':
				$this->process_refunded( $bitpay_invoice, $order );
				break;
		}

		do_action( 'bitpay_checkout_woocoomerce_after_process', $bitpay_invoice, $order, $event_name );

		$this->bitpay_checkout_transactions->update_transaction_status( $bitpay_invoice );

		http_response_code( 200 );
	}

	private function get_gateway_settings(): array {
		if ( ! $this->gateway_settings ) {
			$this->gateway_settings = get_option( 'woocommerce_bitpay_checkout_gateway_settings' );
		}

		return $this->gateway_settings;
	}

	private function validate_bitpay_status_in_available_statuses( Invoice $bitpay_invoice, array $available_statuses ): void {
		$status = $bitpay_invoice->getStatus();
		if ( ! in_array( $status, $available_statuses, true ) ) {
			$message = 'Wrong BitPay status. Status: ' . $status . ' available statuses: '
				. print_r( $available_statuses, true ); // phpcs:ignore
			throw new \RuntimeException( esc_html( $message ) );
		}
	}

	private function get_wc_order_statuses(): array {
		return wc_get_order_statuses();
	}

	private function get_bitpay_dashboard_link( string $invoice_id ): string {
		$env = $this->get_gateway_settings()['bitpay_checkout_endpoint'];
		if ( 'production' === $env ) {
			return '//bitpay.com/dashboard/payments/' . $invoice_id;
		}

		if ( 'test' === $env ) {
			return '//test.bitpay.com/dashboard/payments/' . $invoice_id;
		}

		throw new \RuntimeException( 'Wrong BitPay Environment ' . esc_html( $env ) );
	}

	private function process_confirmed( Invoice $bitpay_invoice, WC_Order $order ): void {
		$this->validate_bitpay_status_in_available_statuses( $bitpay_invoice, array( 'confirmed', 'complete' ) );

		$invoice_id             = $bitpay_invoice->getId();
		$wordpress_order_status = $this->get_gateway_settings()['bitpay_checkout_order_process_confirmed_status'];
		if ( WcGatewayBitpay::IGNORE_STATUS_VALUE === $wordpress_order_status ) {
			$order->add_order_note(
				$this->get_start_order_note( $invoice_id ) . 'has changed to Confirmed. The order status has not been updated due to your settings.'
			);
			return;
		}

		$new_status = $this->get_wc_order_statuses()[ $wordpress_order_status ] ?? null;
		if ( ! $new_status ) {
			$new_status             = 'Processing';
			$wordpress_order_status = 'pending';
		}

		$order->add_order_note(
			$this->get_start_order_note( $invoice_id ) . ' has changed to ' . $new_status . '.'
		);
		if ( 'wc-completed' === $wordpress_order_status ) { // statuses with 'wc' prefix.
			$order->payment_complete();
			$order->add_order_note( 'Payment Completed' );
		} else {
			$order->update_status( $wordpress_order_status, __( 'BitPay payment ', 'woocommerce' ) );
		}
		$this->clear_cart();
	}

	private function process_completed( Invoice $bitpay_invoice, WC_Order $order ): void {
		$this->validate_bitpay_status_in_available_statuses( $bitpay_invoice, array( 'complete' ) );
		$wc_order_status = $order->get_status();

		$invoice_id             = $bitpay_invoice->getId();
		$wordpress_order_status = $this->get_gateway_settings()['bitpay_checkout_order_process_complete_status'];
		if ( WcGatewayBitpay::IGNORE_STATUS_VALUE === $wordpress_order_status ) {
			$order->add_order_note(
				$this->get_start_order_note( $invoice_id )
				. 'has changed to Complete. The order status has not been updated due to your settings.'
			);
			return;
		}

		if ( ! $this->should_process_completed_action( $wc_order_status, $wordpress_order_status ) ) {
			return;
		}

		$new_status = $this->get_wc_order_statuses()[ $wordpress_order_status ] ?? null;
		$new_status = apply_filters( 'bitpay_checkout_order_process_complete_status', $new_status, $wordpress_order_status );
		if ( ! $new_status ) {
			$new_status             = 'Processing';
			$wordpress_order_status = 'pending';
		}

		$order->add_order_note( $this->get_start_order_note( $invoice_id ) . 'has changed to ' . $new_status . '.' );
		if ( 'wc-completed' === $wordpress_order_status ) { // statuses with 'wc' prefix.
			$order->payment_complete();
			$order->add_order_note( 'Payment Completed' );
		} else {
			$order->update_status( $wordpress_order_status, __( 'BitPay payment ', 'woocommerce' ) );
		}

		$this->clear_cart();
		wc_reduce_stock_levels( $order->get_id() );
	}

	private function clear_cart(): void {
		$cart = WC()->cart;
		if ( ! $cart ) {
			return;
		}
		$cart->empty_cart();
	}

	private function process_failed( Invoice $bitpay_invoice, WC_Order $order ): void {
		$this->validate_bitpay_status_in_available_statuses( $bitpay_invoice, array( 'invalid' ) );

		$invoice_id = $bitpay_invoice->getId();
		$order->add_order_note(
			$this->get_start_order_note( $invoice_id )
			. 'has become invalid because of network congestion. Order will automatically update when the status changes.'
		);
		$order->update_status( 'failed', __( 'BitPay payment invalid', 'woocommerce' ) );
	}

	private function process_declined( Invoice $bitpay_invoice, WC_Order $order ): void {
		$this->validate_bitpay_status_in_available_statuses( $bitpay_invoice, array( 'declined' ) );

		$invoice_id = $bitpay_invoice->getId();
		$order->add_order_note( $this->get_start_order_note( $invoice_id ) . 'has been declined.' );
		$order->update_status( 'failed', __( 'BitPay payment invalid', 'woocommerce' ) );
	}

	private function get_start_order_note( string $invoice_id ): string {
		return 'BitPay Invoice ID: <a target = "_blank" href = "'
			. $this->get_bitpay_dashboard_link( $invoice_id ) . '">' . $invoice_id . '</a> ';
	}

	private function process_abandoned( Invoice $bitpay_invoice, WC_Order $order ): void {
		$this->validate_bitpay_status_in_available_statuses( $bitpay_invoice, array( 'expired' ) );
		$underpaid_amount       = $bitpay_invoice->getUnderpaidAmount();
		$wordpress_order_status = $this->get_gateway_settings()['bitpay_checkout_order_expired_status'];

		$invoice_id = $bitpay_invoice->getId();
		if ( $underpaid_amount ) {
			$this->process_refunded( $bitpay_invoice, $order );
			return;
		}

		$order_status = 'cancelled';
		$order->add_order_note( $this->get_start_order_note( $invoice_id ) . 'has expired.' );

		if ( 1 === (int) $wordpress_order_status ) {
			$order->update_status( $order_status, __( 'BitPay payment invalid', 'woocommerce' ) );
		}
	}

	private function process_refunded( Invoice $bitpay_invoice, WC_Order $order ): void {
		if ( ! $this->should_process_refund() ) {
			$order->add_order_note(
				$this->get_start_order_note( $bitpay_invoice->getId() )
				. 'has changed to Refunded. The order status has not been updated due to your settings.'
			);
			return;
		}

		$order->add_order_note( $this->get_start_order_note( $bitpay_invoice->getId() ) . 'has been refunded.' );
		$order->update_status( 'refunded', __( 'BitPay payment refunded', 'woocommerce' ) );
	}

	private function process_processing( Invoice $bitpay_invoice, WC_Order $order ): void {
		$this->validate_bitpay_status_in_available_statuses( $bitpay_invoice, array( 'paid', 'confirmed', 'complete' ) );
		$invoice_id = $bitpay_invoice->getId();
		$order->add_order_note( $this->get_start_order_note( $invoice_id ) . 'is paid and awaiting confirmation.' );

		$wordpress_order_status = $this->get_gateway_settings()['bitpay_checkout_order_process_paid_status'];
		if ( WcGatewayBitpay::IGNORE_STATUS_VALUE === $wordpress_order_status ) {
			$order->add_order_note(
				$this->get_start_order_note( $invoice_id )
				. 'is paid and awaiting confirmation. The order status has not been updated due to your settings.'
			);
			return;
		}

		$new_status = $this->get_wc_order_statuses()[ $wordpress_order_status ] ?? 'processing';
		$order->update_status( $new_status, __( 'BitPay payment processing', 'woocommerce' ) );
	}

	private function has_final_status( WC_Order $order ): bool {
		return \in_array( $order->get_status(), self::FINAL_WC_ORDER_STATUSES, true );
	}

	/**
	 * We don't want to change complete order to process.
	 *
	 * @param string      $wc_order_status WC order status.
	 * @param string|null $wordpress_order_status_from_settings status to event from BitPay settings.
	 * @return bool
	 */
	private function should_process_completed_action( string $wc_order_status, ?string $wordpress_order_status_from_settings ): bool {
		if ( 'completed' !== $wc_order_status ) {
			return true;
		}

		if ( 'pending' === $wordpress_order_status_from_settings || 'processing' === $wordpress_order_status_from_settings ) {
			return false;
		}

		return true;
	}

	private function should_process_refund(): bool {
		$should_process_refund_status = $this->get_wc_order_statuses()['bitpay_checkout_order_process_refund'] ?? '1';
		return '1' === $should_process_refund_status;
	}
}
