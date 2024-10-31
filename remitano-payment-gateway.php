<?php
/**
 * Plugin Name: Remitano Payment Gateway for WooCommerce
 * Plugin URI: https://remitano.com
 * Description: Remitano Payment Gateway
 * Version: 1.0.4
 * Author: remitanoofficial
 * Author URI: https://remitano.com
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}

$urlparts = wp_parse_url( site_url() );
$domain   = $urlparts['host'];

define( 'REMI_SHOP_DOMAIN', $domain );
define( 'REMI_API_HOST', 'https://api.remitano.com' );
define( 'REMI_SANDBOX_API_HOST', 'https://api.remidemo.com' );
define( 'REMI_CORE_HOST', 'https://remitano.com/' );
define( 'REMI_NAMESPACE', 'remitano/v1' );
define( 'REMI_PLUGIN_PATH', plugin_dir_path( __FILE__ ) );
define( 'REMI_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

if( !function_exists('is_plugin_active') ) {
	include_once( ABSPATH . 'wp-admin/includes/plugin.php' );
}

/**
 * Check if WooCommerce is active
 */
if ( !function_exists('is_plugin_active') || is_plugin_active( 'woocommerce/woocommerce.php') ) {

	add_action( 'plugins_loaded', 'remitano_init', 9 );
	function remitano_init() {
		if ( ! class_exists( 'WC_Gateway_Remitano'  ) ) {
			include REMI_PLUGIN_PATH . 'includes/class-wc-gateway-remitano.php';
		}
	}
	add_filter( 'woocommerce_payment_gateways', 'remi_add_gateway_class' );
	function remi_add_gateway_class( $gateways ) {
		$gateways[] = 'WC_Gateway_Remitano';
		return $gateways;
	}
	add_filter( 'woocommerce_currencies', 'remi_add_usdt_currency' );

	function remi_add_usdt_currency( $currencies ) {
		$currencies['USDT'] = __( 'USDT', 'woocommerce' );
		return $currencies;
	}

	add_filter('woocommerce_currency_symbol', 'remi_add_usdt_symbol', 10, 2);

	function remi_add_usdt_symbol( $currency_symbol, $currency ) {
		switch( $currency ) {
			case 'USDT': $currency_symbol = '₮'; break;
		}
		return $currency_symbol;
	}
}
