<?php
/**
 * Registers class aliases for premium modules when the Pro plugin is inactive.
 *
 * @package LiteCal
 */

namespace LiteCal\Compatibility;

/**
 * Registers fallback aliases used by the Free plugin.
 *
 * @package LiteCal
 */
class PremiumFallback {

	/**
	 * Registers aliases so premium module class references resolve to free-safe fallbacks.
	 */
	public static function register_aliases() {
		$map = array(
			'\\LiteCal\\Modules\\Payments\\Flow\\FlowProvider'                   => array(
				'fallback' => '\\LiteCal\\Compatibility\\Fallbacks\\FlowProvider',
				'feature'  => 'payments_flow',
			),
			'\\LiteCal\\Modules\\Payments\\MercadoPago\\MercadoPagoProvider'     => array(
				'fallback' => '\\LiteCal\\Compatibility\\Fallbacks\\MercadoPagoProvider',
				'feature'  => 'payments_mercadopago',
			),
			'\\LiteCal\\Modules\\Payments\\WebpayPlus\\WebpayPlusProvider'       => array(
				'fallback' => '\\LiteCal\\Compatibility\\Fallbacks\\WebpayPlusProvider',
				'feature'  => 'payments_webpay',
			),
			'\\LiteCal\\Modules\\Payments\\PayPal\\PayPalProvider'               => array(
				'fallback' => '\\LiteCal\\Compatibility\\Fallbacks\\PayPalProvider',
				'feature'  => 'payments_paypal',
			),
			'\\LiteCal\\Modules\\Payments\\Stripe\\StripeProvider'               => array(
				'fallback' => '\\LiteCal\\Compatibility\\Fallbacks\\StripeProvider',
				'feature'  => 'payments_stripe',
			),
			'\\LiteCal\\Modules\\Calendar\\GoogleCalendar\\GoogleCalendarModule' => array(
				'fallback' => '\\LiteCal\\Compatibility\\Fallbacks\\GoogleCalendarModule',
				'feature'  => 'calendar_google',
			),
			'\\LiteCal\\Modules\\Video\\Zoom\\ZoomModule'                        => array(
				'fallback' => '\\LiteCal\\Compatibility\\Fallbacks\\ZoomModule',
				'feature'  => 'video_zoom',
			),
			'\\LiteCal\\Modules\\Video\\Teams\\TeamsModule'                      => array(
				'fallback' => '\\LiteCal\\Compatibility\\Fallbacks\\TeamsModule',
				'feature'  => 'video_teams',
			),
			'\\LiteCal\\Modules\\Video\\GoogleMeet\\GoogleMeetModule'            => array(
				'fallback' => '\\LiteCal\\Compatibility\\Fallbacks\\GoogleMeetModule',
				'feature'  => 'video_google_meet',
			),
		);

		foreach ( $map as $target => $config ) {
			$fallback = (string) ( $config['fallback'] ?? '' );
			$feature  = sanitize_key( (string) ( $config['feature'] ?? '' ) );
			if ( $feature !== '' && function_exists( 'litecal_pro_runtime_feature_enabled' ) && function_exists( 'litecal_pro_is_plugin_really_active' ) && litecal_pro_is_plugin_really_active() ) {
				try {
					if ( litecal_pro_runtime_feature_enabled( $feature ) ) {
						continue;
					}
				} catch ( \Throwable $e ) {
				}
			}
			if ( ! class_exists( $target, true ) && class_exists( $fallback, true ) ) {
				class_alias( $fallback, $target );
			}
		}
	}
}
