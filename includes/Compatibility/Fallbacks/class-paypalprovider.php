<?php
/**
 * Free fallback for PayPal payments.
 *
 * @package LiteCal
 */

namespace LiteCal\Compatibility\Fallbacks;

// phpcs:disable Squiz.Commenting -- Lightweight fallback stubs intentionally keep concise method declarations.

class PayPalProvider extends ProviderFallbackBase {

	public function create_payment( array $booking, array $event ): array {
		return array( 'error' => $this->not_available_message() );
	}

	public function verify_webhook( array $payload, array $headers = array() ): bool {
		return false;
	}

	public function refund( string $payment_id, float $amount ): bool {
		return false;
	}

	public function capture_order( string $order_id ) {
		return new \WP_Error( 'litecal_pro_required', $this->not_available_message() );
	}
}
