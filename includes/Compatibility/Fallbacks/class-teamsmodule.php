<?php
/**
 * Free fallback for Teams module.
 *
 * @package LiteCal
 */

namespace LiteCal\Compatibility\Fallbacks;

// phpcs:disable Squiz.Commenting -- Lightweight fallback stubs intentionally keep concise method declarations.

class TeamsModule {

	public static function init() {
	}

	public static function create_meeting( $booking, $event ) {
		return new \WP_Error( 'litecal_pro_required', __( 'Microsoft Teams está disponible en Agenda Lite Pro.', 'agenda-lite' ) );
	}

	public static function update_meeting( $meeting_id, $booking, $event ) {
		return new \WP_Error( 'litecal_pro_required', __( 'Microsoft Teams está disponible en Agenda Lite Pro.', 'agenda-lite' ) );
	}

	public static function delete_meeting( $meeting_id ) {
		return true;
	}
}
