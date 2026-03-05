<?php
/**
 * Service/event model and related helpers.
 *
 * @package LiteCal
 */

namespace LiteCal\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// phpcs:disable Squiz.Commenting,WordPress.NamingConventions.ValidVariableName -- Project style preference: keep concise docs and legacy variable names without functional impact.

// phpcs:disable WordPress.PHP.YodaConditions.NotYoda,Universal.Operators.DisallowShortTernary.Found -- Project style preference; no functional impact.

// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Uses trusted $wpdb->prefix table names and prepares dynamic values where needed.

class Events {

	private const FREE_MAX_EMPLOYEES_PER_EVENT = 1;
	private static $relation_schema_ready = false;

	private static function is_free_plan() {
		if ( ! defined( 'LITECAL_IS_FREE' ) || ! LITECAL_IS_FREE ) {
			return false;
		}
		if ( function_exists( 'litecal_pro_is_active' ) && litecal_pro_is_active() ) {
			return false;
		}
		return true;
	}

	private static function sanitize_payload( $data, $for_update = false ) {
		$clean             = array();
		$allowed_locations = array( 'custom', 'presencial', 'google_meet', 'zoom', 'teams' );
		$allowed_status    = array( 'active', 'draft', 'inactive' );
		$allowed_currency  = array( 'CLP', 'USD', 'EUR', 'MXN', 'COP', 'PEN', 'ARS', 'BRL' );
		foreach ( (array) $data as $key => $value ) {
			$key = sanitize_key( (string) $key );
			switch ( $key ) {
				case 'title':
					$clean[ $key ] = sanitize_text_field( (string) $value );
					break;
				case 'slug':
					$clean[ $key ] = sanitize_title( (string) $value );
					break;
				case 'description':
					$clean[ $key ] = wp_kses_post( (string) $value );
					break;
				case 'duration':
					$clean[ $key ] = max( 5, min( 1440, (int) $value ) );
					break;
				case 'buffer_before':
				case 'buffer_after':
					$clean[ $key ] = max( 0, min( 1440, (int) $value ) );
					break;
				case 'location':
					$location      = sanitize_key( (string) $value );
					$clean[ $key ] = in_array( $location, $allowed_locations, true ) ? $location : 'custom';
					break;
				case 'location_details':
					$clean[ $key ] = sanitize_text_field( (string) $value );
					break;
				case 'capacity':
					$clean[ $key ] = max( 1, min( 9999, (int) $value ) );
					break;
				case 'status':
					$status        = sanitize_key( (string) $value );
					$clean[ $key ] = in_array( $status, $allowed_status, true ) ? $status : 'draft';
					break;
				case 'require_payment':
				case 'availability_override':
					$clean[ $key ] = ! empty( $value ) ? 1 : 0;
					break;
				case 'price':
					$clean[ $key ] = max( 0, (float) $value );
					break;
				case 'currency':
					$currency      = strtoupper( sanitize_text_field( (string) $value ) );
					$clean[ $key ] = in_array( $currency, $allowed_currency, true ) ? $currency : 'CLP';
					break;
				case 'custom_fields':
				case 'options':
					if ( is_array( $value ) ) {
						$clean[ $key ] = wp_json_encode( $value );
					} else {
						$decoded       = json_decode( (string) $value, true );
						$clean[ $key ] = wp_json_encode( is_array( $decoded ) ? $decoded : array() );
					}
					break;
			}
		}
		if ( ! $for_update ) {
			$defaults = array(
				'title'                 => '',
				'slug'                  => '',
				'description'           => '',
				'duration'              => 30,
				'buffer_before'         => 0,
				'buffer_after'          => 0,
				'location'              => 'custom',
				'location_details'      => '',
				'capacity'              => 1,
				'status'                => 'active',
				'require_payment'       => 0,
				'price'                 => 0,
				'currency'              => 'CLP',
				'custom_fields'         => wp_json_encode( array() ),
				'options'               => wp_json_encode( array() ),
				'availability_override' => 0,
			);
			$clean    = wp_parse_args( $clean, $defaults );
		}
		return $clean;
	}

	public static function get_by_slug( $slug ) {
		global $wpdb;
		$table = $wpdb->prefix . 'litecal_events';
		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE slug = %s", $slug ) );
	}

	public static function get( $id ) {
		global $wpdb;
		$table = $wpdb->prefix . 'litecal_events';
		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id ) );
	}

	public static function all() {
		global $wpdb;
		$table = $wpdb->prefix . 'litecal_events';
		return $wpdb->get_results( "SELECT * FROM {$table} ORDER BY created_at DESC" );
	}

	public static function create( $data ) {
		global $wpdb;
		$table              = $wpdb->prefix . 'litecal_events';
		$now                = current_time( 'mysql' );
		$data               = self::sanitize_payload( $data, false );
		$data['created_at'] = $now;
		$data['updated_at'] = $now;
		$wpdb->insert( $table, $data );
		return $wpdb->insert_id;
	}

	public static function update( $id, $data ) {
		global $wpdb;
		$table = $wpdb->prefix . 'litecal_events';
		$data  = self::sanitize_payload( $data, true );
		if ( empty( $data ) ) {
			return false;
		}
		$data['updated_at'] = current_time( 'mysql' );
		return $wpdb->update( $table, $data, array( 'id' => $id ) );
	}

	public static function delete( $id ) {
		global $wpdb;
		$table = $wpdb->prefix . 'litecal_events';
		return $wpdb->delete( $table, array( 'id' => $id ) );
	}

	public static function employees( $event_id ) {
		global $wpdb;
		self::ensure_relation_schema();
		$rel       = $wpdb->prefix . 'litecal_event_employees';
		$employees = $wpdb->prefix . 'litecal_employees';
		$rows      = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT e.* FROM {$employees} e INNER JOIN {$rel} r ON e.id = r.employee_id WHERE r.event_id = %d ORDER BY r.sort_order ASC, r.employee_id ASC",
				$event_id
			)
		);
		if ( self::is_free_plan() && count( (array) $rows ) > self::FREE_MAX_EMPLOYEES_PER_EVENT ) {
			$rows = array_slice( (array) $rows, 0, self::FREE_MAX_EMPLOYEES_PER_EVENT );
		}
		return $rows;
	}

	public static function set_employees( $event_id, $employee_ids ) {
		global $wpdb;
		self::ensure_relation_schema();
		$employee_ids = array_values( array_unique( array_filter( array_map( 'intval', (array) $employee_ids ) ) ) );
		if ( self::is_free_plan() && ! empty( $employee_ids ) ) {
			$employee_ids = array( (int) $employee_ids[0] );
		}
		$rel          = $wpdb->prefix . 'litecal_event_employees';
		$wpdb->delete( $rel, array( 'event_id' => $event_id ) );
		$sort_order = 0;
		foreach ( $employee_ids as $employee_id ) {
			if ( $employee_id <= 0 ) {
				continue;
			}
			$wpdb->insert(
				$rel,
				array(
					'event_id'    => $event_id,
					'employee_id' => $employee_id,
					'sort_order'  => $sort_order,
				)
			);
			++$sort_order;
		}
	}

	private static function ensure_relation_schema() {
		if ( self::$relation_schema_ready ) {
			return;
		}
		global $wpdb;
		$table    = $wpdb->prefix . 'litecal_event_employees';
		$has_sort = $wpdb->get_var(
			$wpdb->prepare(
				"SHOW COLUMNS FROM {$table} LIKE %s",
				'sort_order'
			)
		);
		if ( ! $has_sort ) {
			$wpdb->query( "ALTER TABLE {$table} ADD COLUMN sort_order INT NOT NULL DEFAULT 0 AFTER employee_id" );
			$wpdb->query( "UPDATE {$table} SET sort_order = 0 WHERE sort_order IS NULL" );
		}
		self::$relation_schema_ready = true;
	}
}
