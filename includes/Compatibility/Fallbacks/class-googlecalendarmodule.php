<?php
/**
 * Free fallback for Google Calendar module.
 *
 * @package LiteCal
 */

namespace LiteCal\Compatibility\Fallbacks;

// phpcs:disable Squiz.Commenting -- Lightweight fallback stubs intentionally keep concise method declarations.

class GoogleCalendarModule {

	public static function init() {
	}

	public static function oauth_start() {
		wp_die( esc_html__( 'Google Calendar está disponible en Agenda Lite Pro.', 'agenda-lite' ) );
	}

	public static function oauth_callback() {
		wp_die( esc_html__( 'Google Calendar está disponible en Agenda Lite Pro.', 'agenda-lite' ) );
	}

	public static function oauth_disconnect() {
		wp_safe_redirect( admin_url( 'admin.php?page=litecal-integrations' ) );
		exit;
	}

	public static function sync_booking( $booking_id, $status ) {
	}

	public static function get_redirect_uri() {
		return admin_url( 'admin-post.php?action=litecal_google_oauth_callback' );
	}

	public static function list_calendars() {
		return array();
	}

	public static function get_busy_ranges_for_date( $date, $employee_id = 0 ) {
		return array();
	}
}
