<?php
/**
 * Shared helpers for free fallback providers.
 *
 * @package LiteCal
 */

namespace LiteCal\Compatibility\Fallbacks;

// phpcs:disable Squiz.Commenting -- Lightweight fallback stubs intentionally keep concise method declarations.

class ProviderFallbackBase {

	/**
	 * Returns a consistent message when a Pro-only integration is requested.
	 *
	 * @return string
	 */
	protected function not_available_message() {
		return __( 'Esta integración está disponible en Agenda Lite Pro.', 'agenda-lite' );
	}
}
