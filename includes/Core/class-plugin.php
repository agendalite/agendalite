<?php
/**
 * Main plugin bootstrap singleton.
 *
 * @package LiteCal
 */

namespace LiteCal\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// phpcs:disable Squiz.Commenting,WordPress.NamingConventions.ValidVariableName -- Project style preference: keep concise docs and legacy variable names without functional impact.

// phpcs:disable WordPress.PHP.YodaConditions.NotYoda,Universal.Operators.DisallowShortTernary.Found -- Project style preference; no functional impact.

use LiteCal\Admin\Admin;
use LiteCal\Public\BuilderIntegration;
use LiteCal\Public\PublicSite;
use LiteCal\Rest\Rest;

class Plugin {

	private static $instance;

	public static function get_instance() {
		if ( ! self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		DB::maybe_migrate();
		$this->init_modules();
		Admin::init();
		PublicSite::init();
		BuilderIntegration::init();
		Rest::init();
	}

	private function init_modules() {
		if ( function_exists( 'litecal_pro_feature_enabled' ) && litecal_pro_feature_enabled( 'calendar_google' ) ) {
			\LiteCal\Modules\Calendar\GoogleCalendar\GoogleCalendarModule::init();
		}
		if ( function_exists( 'litecal_pro_feature_enabled' ) && litecal_pro_feature_enabled( 'video_zoom' ) ) {
			\LiteCal\Modules\Video\Zoom\ZoomModule::init();
		}
		if ( function_exists( 'litecal_pro_feature_enabled' ) && litecal_pro_feature_enabled( 'video_teams' ) ) {
			\LiteCal\Modules\Video\Teams\TeamsModule::init();
		}
		add_filter(
			'cron_schedules',
			function ( $schedules ) {
				if ( ! isset( $schedules['litecal_five_minutes'] ) ) {
					$schedules['litecal_five_minutes'] = array(
						'interval' => 300,
						'display'  => __( 'Cada 5 minutos', 'agenda-lite' ),
					);
				}
				return $schedules;
			}
		);
		add_action(
			'litecal_cleanup_payments',
			function () {
				\LiteCal\Core\Bookings::cleanup_stale_payments( 10 );
				\LiteCal\Rest\Rest::process_event_reminders();
			}
		);
		add_action(
			'litecal_bookings_expired',
			function ( $ids ) {
				if ( ! is_array( $ids ) ) {
					return;
				}
				foreach ( $ids as $booking_id ) {
					$booking_id = (int) $booking_id;
					if ( $booking_id <= 0 ) {
						continue;
					}
					\LiteCal\Rest\Rest::notify_booking_status( $booking_id, 'payment_expired', true );
				}
			}
		);
		if ( ! wp_next_scheduled( 'litecal_cleanup_payments' ) ) {
			wp_schedule_event( time() + 300, 'litecal_five_minutes', 'litecal_cleanup_payments' );
		}
		do_action( 'litecal_register_modules' );
	}
}
