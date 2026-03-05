<?php
/**
 * Free fallback for Stripe payments.
 *
 * @package LiteCal
 */

namespace LiteCal\Compatibility\Fallbacks;

// phpcs:disable Squiz.Commenting -- Lightweight fallback stubs intentionally keep concise method declarations.

class StripeProvider extends ProviderFallbackBase {

	public function create_payment( array $booking, array $event ): array {
		return array( 'error' => $this->not_available_message() );
	}

	public function verify_webhook( array $payload, array $headers = array() ): bool {
		return false;
	}

	public function refund( string $payment_id, float $amount ): bool {
		return false;
	}

	public function parse_webhook_event( string $raw_body, array $headers ) {
		return new \WP_Error( 'litecal_pro_required', $this->not_available_message() );
	}

	public function get_checkout_session( string $session_id ) {
		return new \WP_Error( 'litecal_pro_required', $this->not_available_message() );
	}

	public function to_minor_unit( float $amount, string $currency ): int {
		$currency = strtoupper( (string) $currency );
		return in_array( $currency, array( 'JPY', 'CLP', 'KRW' ), true ) ? (int) round( $amount ) : (int) round( $amount * 100 );
	}

	public function from_minor_unit( int $minor_amount, string $currency ): float {
		$currency = strtoupper( (string) $currency );
		return in_array( $currency, array( 'JPY', 'CLP', 'KRW' ), true ) ? (float) $minor_amount : (float) ( $minor_amount / 100 );
	}

	public function get_active_publishable_key(): string {
		return '';
	}
}
