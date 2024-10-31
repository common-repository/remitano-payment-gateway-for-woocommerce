<?php
/**
 * Remitano Payment Gateway Main class
 *
 * @author   Remitano
 * @version  1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

class WC_Gateway_Remitano extends WC_Payment_Gateway {
	public function __construct() {
		$this->id = 'remitano';
		$this->icon = 'https://remitano.com/imgs/payment-btn-short-purple.png';
		$this->has_fields = false;
		$this->method_title = 'Remitano Payment Gateway';
		$this->method_description = 'Redirect customer to Remitano.com to pay with cryptocurrency.';

		$this->supports = array(
			'products'
		);

		$this->init_form_fields();
		$this->init_settings();
		$this->title = $this->get_option( 'title' );
		$this->description = $this->get_option( 'description' );
		$this->enabled = $this->get_option( 'enabled' );
		$this->test_mode = 'yes' === $this->get_option( 'test_mode' );
		$this->api_secret = $this->test_mode ? $this->get_option( 'test_api_secret' ) : $this->get_option( 'api_secret' );
		$this->api_key = $this->test_mode ? $this->get_option( 'test_api_key' ) : $this->get_option( 'api_key' );
		$this->thank_you_message = $this->get_option( 'thank_you_message' );

		// This action hook saves the settings
		add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array( $this, 'process_admin_options' ) );

		// You can also register a webhook here
		add_action( 'woocommerce_api_wc_gateway_remitano_callback', array( $this, 'handle_callback' ));
		add_action( 'woocommerce_api_wc_gateway_remitano_redirect', array( $this, 'handle_redirect' ));

		if ( ! $this->is_valid_for_use() ) {
			$this->enabled = 'no';
		}

		if ( 'yes' === $this->enabled ) {
			add_filter( 'woocommerce_thankyou_order_received_text', array( $this, 'order_received_text' ), 10, 2 );
		}
	}

	public function is_valid_for_use() {
		return in_array(
			get_woocommerce_currency(),
			apply_filters(
				'woocommerce_remitano_supported_currencies',
				array("AED", "ARS", "AUD", "BND", "BOB", "BRL", "BYN", "CAD", "CDF", "CFA", "CHF", "CNY", "COP",
					  "DKK", "DZD", "EUR", "GBP", "GHS", "HKD", "IDR", "ILS", "INR", "JPY", "KES", "KRW", "LAK",
					  "MMK", "MXN", "MYR", "NAD", "NGN", "NOK", "NPR", "NZD", "OMR", "PEN", "PHP", "PKR", "PLN",
					  "QAR", "RUB", "RWF", "SEK", "SGD", "THB", "TRY", "TWD", "TZS", "UAH", "UGX", "USD", "VES",
					  "VND", "XAF", "ZAR", "ZMW", "USDT")
			),
			true
		);
	}

	public function admin_options() {
		if ( $this->is_valid_for_use() ) {
			parent::admin_options();
		} else {
			$currency = get_woocommerce_currency();
			?>
			<div class="inline error">
				<p>
					<strong><?php esc_html_e( 'Gateway disabled', 'woocommerce' ); ?></strong>: <?php esc_html_e( 'Your currency is not currently supported, please contact Remitano for support.', 'woocommerce' ); ?>
				</p>
				<p>
					<strong><?php esc_html_e('Currency', 'woocommerce');?></strong>: <?php esc_html_e( $currency );?>
				</p>
			</div>
			<?php
		}
	}

	public function init_form_fields() {
		$currency = get_woocommerce_currency();
		$is_usd = $currency == 'USDT' || $currency == 'USD';
		$this->form_fields = array(
			'enabled' => array(
				'title'       => 'Enable/Disable',
				'label'       => 'Enable Remitano Payment Gateway',
				'type'        => 'checkbox',
				'description' => !$is_usd ? "Your are using $currency currency, we will automatically convert the order's total to equivalent amount of USDT in Remitano payment page. <a target='_blank' href='https://developers.remitano.com/docs/payment-gateway/quick-start#fiat-currencies'>More info here</a>" : "",
				'default'     => 'no'
			),
			'title' => array(
				'title'       => 'Title',
				'type'        => 'text',
				'description' => 'This controls the title which the user sees during checkout.',
				'default'     => 'Pay with Remitano',
				'desc_tip'    => true,
			),
			'description' => array(
				'title'       => 'Description',
				'type'        => 'textarea',
				'description' => 'This controls the description which the user sees during checkout.',
				'default'     => 'Redirect to Remitano to pay with cryptocurrency or buy cryptocurrency and pay within 5 minutes.',
			),
			'thank_you_message' => array(
				'title'       => 'Order received thanks message',
				'type'        => 'textarea',
				'description' => 'This show the thank you message after customer has paid successfully',
				'default'     => 'Thank you for your payment with Remitano. Your transaction has been completed, and a receipt for your purchase has been emailed to you. Log into your Remitano account to view transaction details.',
			),
			'test_mode' => array(
				'title'       => 'Test mode',
				'label'       => 'Enable Test Mode',
				'type'        => 'checkbox',
				'description' => 'Place the payment gateway in test mode using test API keys.',
				'default'     => 'yes',
				'desc_tip'    => true,
			),
			'test_api_key' => array(
				'title'       => 'Test API Key',
				'type'        => 'text'
			),
			'test_api_secret' => array(
				'title'       => 'Test API Secret',
				'type'        => 'text',
			),
			'api_key' => array(
				'title'       => 'Live API Key',
				'type'        => 'text'
			),
			'api_secret' => array(
				'title'       => 'Live API Secret',
				'type'        => 'text'
			)
		);
	}

	public function process_payment( $order_id ) {
		$order = wc_get_order( $order_id );
		$payment_id = get_post_meta( $order_id, 'remitano_payment_id', true );

		if ( $payment_id ) {
			$charge = $this->get_merchant_charge( $payment_id );
			if ( !is_wp_error($charge) ) {
				$data = json_decode( $charge['body'] );
				$order_data = $data->payload;
				$charge_order_id = $order_data->order_id;

				if ( $order_id == $charge_order_id ) {
					$payment_url = $data->remitano_payment_url;

					if ( $data->status == 'completed' ) {
						return array(
							'result' => 'success',
							'redirect' => $this->get_return_url( $order ),
						);
					} elseif ( $data->status != 'cancelled' ) {
						return array(
							'result' => 'success',
							'redirect' => $payment_url,
						);
					}
				}
			}
		}

		$build_url = $this->build_payment_url( $order_id );

		if ($build_url['success']) {
			return array(
				'result'   => 'success',
				'redirect' => $build_url['payment_url'],
			);
		} else {
			$order->update_status( 'failed', __( 'Payment error:', 'woocommerce' ) . $this->get_option( 'error_message' ) );
			wc_add_notice( $build_url['message'] , 'error' );
			return array(
				'result'   => 'failure',
				'redirect' => WC()->cart->get_checkout_url()
			);
		}
	}

	public function build_payment_url( $order_id ) {
		$order = wc_get_order( $order_id );

		$total = $order->get_total();
		$urlparts = wp_parse_url( site_url() );
		$domain   = $urlparts['host'];
		$name = get_bloginfo( 'name' );

		if ( empty( $name ) ) {
			$name = $domain;
		}

		$submission_data = array(
			'cancelled_or_completed_callback_url' => WC()->api_request_url( 'WC_Gateway_Remitano_Callback' ),
			'cancelled_or_completed_redirect_url' => WC()->api_request_url( 'WC_Gateway_Remitano_Redirect'),
			'payload' => array(
				'order_id' => $order_id
			),
			'description' => "Order #$order_id from $name",
		);
		$currency = get_woocommerce_currency();

		if ($currency == 'USDT') {
			$submission_data['coin_currency'] = 'usdt';
			$submission_data['coin_amount'] = (float) $total;
		} else {
			$submission_data['fiat_currency'] = $currency;
			$submission_data['fiat_amount'] = (float) $total;
		}

		$body  = json_encode( $submission_data );

		$method = 'POST';
		$target = '/api/v1/merchant/merchant_charges';
		$url = $this->get_api_base_url() . $target;

		$args = array(
			'method' => $method,
			'blocking' => true,
			'headers' => $this->build_header( $method, $target, $body),
			'body' => $body,
		);

		$response = wp_remote_post( $url, $args );
		if ( is_wp_error( $response ) ) {
			return array(
				'success' => false,
				'message' => $response->get_error_message(),
			);
		} else {
			$response_code = $response['response']['code'];
			$data = json_decode( $response['body'] );

			if ( $response_code >= 300 ) {
				return array(
					'success' => false,
					'message' => $data->error
				);
			}

			$payment_ref = $data->ref;
			$payment_id = $data->id;
			$payment_url = $data->remitano_payment_url;

			add_post_meta( $order_id, 'remitano_payment_ref', $payment_ref);
			add_post_meta( $order_id, 'remitano_payment_id', $payment_id);
			add_post_meta( $order_id, 'remitano_payment_url', $payment_url);
		}

		return array(
			'success' => true,
			'payment_url' => $payment_url,
		);
	}

	public function get_date( $timestamp = null ) {
		$timestamp = empty($timestamp) ? time() : $timestamp;
		return gmdate("D, d M Y H:i:s", $timestamp)." GMT";
	}

	public function build_header( $method, $target, $body ) {
		$content_type = 'application/json';
		$md5 = $this->calculate_md5($body);
		$date = $this->get_date();
		$canonical_string = $this->get_canonical_string($method, $content_type, $md5, $target, $date);
		$signature = $this->get_hmac_signature($canonical_string);
		return array(
			'date' => $date,
			'content-type' => $content_type,
			'accept' => $content_type,
			'content-md5' => $md5,
			'authorization' => "APIAuth {$this->api_key}:$signature"
		);
	}

	public function calculate_md5( $body ) {
		return base64_encode( md5( $body, true ) );
	}

	public function get_canonical_string( $method, $type, $md5, $target, $date ) {
		return join(",", array( $method, $type, $md5, $target, $date ));
	}

	public function get_hmac_signature( $canonical_string ) {
		return trim( base64_encode( hash_hmac( 'sha1', $canonical_string, $this->api_secret, true ) ) );
	}

	public function get_api_base_url() {
		if ( getenv( 'REMITANO_API_HOST' ) ) {
			return getenv( 'REMITANO_API_HOST' );
		}
		return $this->test_mode ? constant( 'REMI_SANDBOX_API_HOST' ) : constant( 'REMI_API_HOST' );
	}

	public function get_merchant_charge( $charge_id ) {
		$method = 'GET';
		$target = "/api/v1/merchant/merchant_charges/$charge_id";
		$url = $this->get_api_base_url() . $target;
		$body = "";

		$args = array(
			'method' => $method,
			'blocking' => true,
			'headers' => $this->build_header( $method, $target, $body),
			'body' => $body,
		);

		return wp_remote_get( $url, $args );
	}

	public function handle_redirect() {
		$this->process_response( $_GET['remitano_id'], true );
	}

	public function handle_callback() {
		$this->process_response( $_GET['remitano_id'], false );
	}

	public function process_response( $remitano_id, $is_redirect ) {
		$response = $this->get_merchant_charge( $remitano_id );

		if ( is_wp_error( $response ) ) {
			print_r($response);
		} else {
			$response_code = $response['response']['code'];
			$data = json_decode( $response['body'] );

			$payment_status = $data->status;
			$payment_ref = $data->ref;
			$payment_id = $data->id;
			$payment_url = $data->remitano_payment_url;
			$order_data = $data->payload;
			$payer_name = $data->payer_name;
		}

		$order = wc_get_order( $order_data->order_id );
		$return_url = $this->get_return_url($order);
		$cancelled_url = $order->get_cancel_order_url_raw();

		if ($payment_status == 'completed') {
			if ( !$order->is_paid() ) {
				add_post_meta( $order_data->order_id, 'remitano_payer_name', $payer_name );
				$order->payment_complete($payment_ref);
				if ( $is_redirect) {
					if ( isset( WC()->cart ) ) {
						WC()->cart->empty_cart();
					}
					return wp_redirect($return_url);
				}
			}

			return wp_redirect($return_url);
		} elseif ($payment_status == 'cancelled') {
 			$order->update_status('cancelled', 'Cancelled due to payment cancel');
			if ( $is_redirect ) {
				return wp_redirect(WC()->cart->get_checkout_url());
			}
		} else {
			print_r($response);
		}
	}

	public function order_received_text( $text, $order ) {
		if ( $order && $this->id === $order->get_payment_method() ) {
			return esc_html__($this->thank_you_message);
		}

		return $text;
	}

}
