<?php
/**
 * Shared utility helpers used across plugin modules.
 *
 * @package LiteCal
 */

namespace LiteCal\Core;

// phpcs:disable Squiz.Commenting,WordPress.NamingConventions.ValidVariableName -- Project style preference: keep concise docs and legacy variable names without functional impact.

// phpcs:disable WordPress.PHP.YodaConditions.NotYoda,Universal.Operators.DisallowShortTernary.Found -- Project style preference; no functional impact.

// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Uses trusted $wpdb->prefix table names for internal plugin tables.
// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Internal read from plugin tables.

class Helpers {

	public static function site_timezone_name() {
		$timezone = trim( (string) get_option( 'timezone_string' ) );
		if ( $timezone !== '' ) {
			return $timezone;
		}
		$fallback = trim( (string) wp_timezone_string() );
		if ( $fallback !== '' && strtoupper( $fallback ) !== 'UTC' ) {
			return $fallback;
		}
		return 'UTC';
	}

	public static function resolve_schedule_timezone_name( $timezone = '' ) {
		$timezone      = trim( (string) $timezone );
		$site_timezone = self::site_timezone_name();
		if ( $timezone === '' ) {
			return $site_timezone;
		}
		if ( strtoupper( $timezone ) === 'UTC' && $site_timezone !== '' && strtoupper( $site_timezone ) !== 'UTC' ) {
			return $site_timezone;
		}
		return $timezone;
	}

	public static function option( $key, $default = null ) {
		$options = get_option( 'litecal_settings', array() );
		return $options[ $key ] ?? $default;
	}

	public static function admin_url( $tab ) {
		return admin_url( 'admin.php?page=litecal-' . $tab );
	}

	public static function esc( $value ) {
		return esc_html( $value );
	}

	public static function time_off_ranges( $employees ) {
		global $wpdb;
		$table        = $wpdb->prefix . 'litecal_time_off';
		$ranges       = array();
		$employee_ids = array_map(
			static function ( $e ) {
				return (int) $e->id;
			},
			$employees ?: array()
		);
		$rows         = $wpdb->get_results( "SELECT scope, scope_id, start_date, end_date FROM {$table}" );
		foreach ( $rows as $row ) {
			if ( $row->scope === 'global' ) {
				$ranges[] = array(
					'start' => $row->start_date,
					'end'   => $row->end_date,
				);
			}
			if ( $row->scope === 'employee' && in_array( (int) $row->scope_id, $employee_ids, true ) ) {
				$ranges[] = array(
					'start'       => $row->start_date,
					'end'         => $row->end_date,
					'employee_id' => (int) $row->scope_id,
				);
			}
		}
		return $ranges;
	}

	public static function avatar_url( $url, $size = 'thumbnail' ) {
		$url = esc_url_raw( (string) $url );
		if ( $url === '' ) {
			return '';
		}
		if ( ! function_exists( 'attachment_url_to_postid' ) || ! function_exists( 'wp_get_attachment_image_url' ) ) {
			return $url;
		}
		$attachment_id = attachment_url_to_postid( $url );
		if ( $attachment_id <= 0 ) {
			return $url;
		}
		$sized = wp_get_attachment_image_url( $attachment_id, $size );
		return $sized ? esc_url_raw( $sized ) : $url;
	}
}
