<?php
/**
 * Employee model and synchronization helpers.
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

class Employees {

	private static $schema_ready = false;
	private static $sync_done    = false;

	public static function booking_manager_role_slug() {
		if ( defined( 'LITECAL_BOOKING_MANAGER_ROLE' ) ) {
			return sanitize_key( (string) LITECAL_BOOKING_MANAGER_ROLE );
		}
		return 'litecal_booking_manager';
	}

	private static function ensure_schema() {
		if ( self::$schema_ready ) {
			return;
		}
		global $wpdb;
		$table          = $wpdb->prefix . 'litecal_employees';
		$has_wp_user_id = $wpdb->get_var( $wpdb->prepare( "SHOW COLUMNS FROM {$table} LIKE %s", 'wp_user_id' ) );
		if ( ! $has_wp_user_id ) {
			$wpdb->query( "ALTER TABLE {$table} ADD COLUMN wp_user_id BIGINT UNSIGNED NULL AFTER id" );
		}
		$has_index = $wpdb->get_var( $wpdb->prepare( "SHOW INDEX FROM {$table} WHERE Key_name = %s", 'wp_user_id' ) );
		if ( ! $has_index ) {
			$wpdb->query( "ALTER TABLE {$table} ADD KEY wp_user_id (wp_user_id)" );
		}
		$has_avatar_custom = $wpdb->get_var( $wpdb->prepare( "SHOW COLUMNS FROM {$table} LIKE %s", 'avatar_custom' ) );
		if ( ! $has_avatar_custom ) {
			$wpdb->query( "ALTER TABLE {$table} ADD COLUMN avatar_custom TINYINT(1) NOT NULL DEFAULT 0 AFTER avatar_url" );
		}
		self::$schema_ready = true;
	}

	public static function sync_booking_manager_users( $force = false ) {
		if ( self::$sync_done && ! $force ) {
			return;
		}
		$cache_key = 'litecal_staff_sync_tick';
		$last_sync = (int) get_transient( $cache_key );
		if ( ! $force && $last_sync > 0 && ( time() - $last_sync ) < 300 ) {
			self::$sync_done = true;
			return;
		}
		self::ensure_schema();
		if ( ! function_exists( 'get_users' ) ) {
			self::$sync_done = true;
			return;
		}

		global $wpdb;
		$table    = $wpdb->prefix . 'litecal_employees';
		$now      = current_time( 'mysql' );
		$timezone = \LiteCal\Core\Helpers::site_timezone_name();
		$role     = self::booking_manager_role_slug();
		$users    = get_users(
			array(
				'role__in' => array( $role, 'administrator' ),
				'orderby'  => 'display_name',
				'order'    => 'ASC',
				'fields'   => array( 'ID', 'display_name', 'user_email' ),
			)
		);

		$active_user_ids = array();
		foreach ( (array) $users as $user ) {
			$user_id = (int) ( $user->ID ?? 0 );
			if ( $user_id <= 0 ) {
				continue;
			}
			$active_user_ids[] = $user_id;
			$name              = trim( sanitize_text_field( (string) ( $user->display_name ?? '' ) ) );
			if ( $name === '' ) {
				/* translators: %d: WordPress user ID. */
				$name = sprintf( __( 'Usuario #%d', 'agenda-lite' ), $user_id );
			}
			$email              = sanitize_email( (string) ( $user->user_email ?? '' ) );
			$wp_avatar_url      = esc_url_raw( (string) get_avatar_url( $user_id, array( 'size' => 512 ) ) );
			$profile_avatar_url = esc_url_raw( (string) get_user_meta( $user_id, 'litecal_profile_avatar_url', true ) );

			$existing = $wpdb->get_row(
				$wpdb->prepare(
					"SELECT * FROM {$table} WHERE wp_user_id = %d LIMIT 1",
					$user_id
				)
			);
			if ( ! $existing && $email !== '' ) {
				$existing = $wpdb->get_row(
					$wpdb->prepare(
						"SELECT * FROM {$table} WHERE (wp_user_id IS NULL OR wp_user_id = 0) AND email = %s ORDER BY id ASC LIMIT 1",
						$email
					)
				);
			}

			$avatar_custom = $profile_avatar_url !== '' ? 1 : 0;
			$avatar_url    = $avatar_custom ? $profile_avatar_url : $wp_avatar_url;
			$profile_title = sanitize_text_field( (string) get_user_meta( $user_id, 'litecal_profile_title', true ) );
			if ( $profile_title === '' && $existing && ! empty( $existing->title ) ) {
				$profile_title = sanitize_text_field( (string) $existing->title );
				update_user_meta( $user_id, 'litecal_profile_title', $profile_title );
			}

			$row_data = array(
				'wp_user_id'    => $user_id,
				'name'          => $name,
				'title'         => $profile_title,
				'email'         => $email,
				'avatar_url'    => $avatar_url,
				'avatar_custom' => $avatar_custom,
				'status'        => 'active',
				'updated_at'    => $now,
			);

			if ( $existing && ! empty( $existing->id ) ) {
				$wpdb->update( $table, $row_data, array( 'id' => (int) $existing->id ) );
			} else {
				$wpdb->insert(
					$table,
					array_merge(
						$row_data,
						array(
							'phone'       => '',
							'schedule_id' => 'default',
							'timezone'    => $timezone,
							'created_at'  => $now,
						)
					)
				);
			}
		}

		if ( ! empty( $active_user_ids ) ) {
			$placeholders = implode( ',', array_fill( 0, count( $active_user_ids ), '%d' ) );
			$params       = array_merge( array( $now ), $active_user_ids );
			$sql          = $wpdb->prepare(
				"UPDATE {$table}
                 SET status = 'inactive', updated_at = %s
                 WHERE wp_user_id IS NOT NULL AND wp_user_id > 0
                   AND wp_user_id NOT IN ({$placeholders})",
				$params
			);
			if ( is_string( $sql ) && $sql !== '' ) {
				$wpdb->query( $sql );
			}
		} else {
			$wpdb->query(
				$wpdb->prepare(
					"UPDATE {$table}
                 SET status = 'inactive', updated_at = %s
                 WHERE wp_user_id IS NOT NULL AND wp_user_id > 0",
					$now
				)
			);
		}
		set_transient( $cache_key, time(), 300 );
		self::$sync_done = true;
	}

	private static function sanitize_payload( $data, $for_update = false ) {
		$clean          = array();
		$allowed_status = array( 'active', 'inactive' );
		foreach ( (array) $data as $key => $value ) {
			$key = sanitize_key( (string) $key );
			switch ( $key ) {
				case 'name':
				case 'title':
				case 'phone':
				case 'schedule_id':
					$clean[ $key ] = sanitize_text_field( (string) $value );
					break;
				case 'wp_user_id':
					$clean[ $key ] = max( 0, (int) $value );
					break;
				case 'email':
					$clean[ $key ] = sanitize_email( (string) $value );
					break;
				case 'avatar_url':
					$clean[ $key ] = esc_url_raw( (string) $value );
					break;
				case 'avatar_custom':
					$clean[ $key ] = ! empty( $value ) ? 1 : 0;
					break;
				case 'timezone':
					$timezone = sanitize_text_field( (string) $value );
					if ( $timezone === '' || ! in_array( $timezone, timezone_identifiers_list(), true ) ) {
						$timezone = 'UTC';
					}
					$clean[ $key ] = $timezone;
					break;
				case 'status':
					$status        = sanitize_key( (string) $value );
					$clean[ $key ] = in_array( $status, $allowed_status, true ) ? $status : 'inactive';
					break;
			}
		}
		if ( ! $for_update ) {
			$defaults = array(
				'wp_user_id'    => 0,
				'name'          => '',
				'title'         => '',
				'email'         => '',
				'phone'         => '',
				'avatar_url'    => '',
				'avatar_custom' => 0,
				'schedule_id'   => '',
				'timezone'      => 'UTC',
				'status'        => 'active',
			);
			$clean    = wp_parse_args( $clean, $defaults );
		}
		return $clean;
	}

	public static function all() {
		self::sync_booking_manager_users();
		global $wpdb;
		$table = $wpdb->prefix . 'litecal_employees';
		return $wpdb->get_results( "SELECT * FROM {$table} ORDER BY created_at DESC" );
	}

	public static function all_booking_managers( $active_only = true ) {
		self::sync_booking_manager_users();
		global $wpdb;
		$table = $wpdb->prefix . 'litecal_employees';
		$where = 'WHERE wp_user_id IS NOT NULL AND wp_user_id > 0';
		if ( $active_only ) {
			$where .= " AND status = 'active'";
		}
		$rows = $wpdb->get_results( "SELECT * FROM {$table} {$where} ORDER BY name ASC" );
		if ( empty( $rows ) ) {
			return array();
		}
		$booking_role = self::booking_manager_role_slug();
		$filtered     = array();
		foreach ( (array) $rows as $row ) {
			$user_id = (int) ( $row->wp_user_id ?? 0 );
			if ( $user_id <= 0 ) {
				continue;
			}
			$user = get_userdata( $user_id );
			if ( ! ( $user instanceof \WP_User ) ) {
				continue;
			}
			$roles = (array) $user->roles;
			if ( in_array( 'administrator', $roles, true ) || in_array( $booking_role, $roles, true ) ) {
				$filtered[] = $row;
			}
		}
		return $filtered;
	}

	public static function get( $id ) {
		self::sync_booking_manager_users();
		global $wpdb;
		$table = $wpdb->prefix . 'litecal_employees';
		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id ) );
	}

	public static function get_by_wp_user_id( $wp_user_id, $active_only = true ) {
		self::sync_booking_manager_users();
		global $wpdb;
		$table      = $wpdb->prefix . 'litecal_employees';
		$wp_user_id = (int) $wp_user_id;
		if ( $wp_user_id <= 0 ) {
			return null;
		}
		if ( $active_only ) {
			return $wpdb->get_row(
				$wpdb->prepare(
					"SELECT * FROM {$table} WHERE wp_user_id = %d AND status = 'active' LIMIT 1",
					$wp_user_id
				)
			);
		}
		return $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE wp_user_id = %d LIMIT 1",
				$wp_user_id
			)
		);
	}

	public static function create( $data ) {
		global $wpdb;
		$table              = $wpdb->prefix . 'litecal_employees';
		$now                = current_time( 'mysql' );
		$data               = self::sanitize_payload( $data, false );
		$data['created_at'] = $now;
		$data['updated_at'] = $now;
		$wpdb->insert( $table, $data );
		return $wpdb->insert_id;
	}

	public static function update( $id, $data ) {
		global $wpdb;
		$table = $wpdb->prefix . 'litecal_employees';
		$data  = self::sanitize_payload( $data, true );
		if ( empty( $data ) ) {
			return false;
		}
		$data['updated_at'] = current_time( 'mysql' );
		return $wpdb->update( $table, $data, array( 'id' => $id ) );
	}

	public static function delete( $id ) {
		global $wpdb;
		$table = $wpdb->prefix . 'litecal_employees';
		return $wpdb->delete( $table, array( 'id' => $id ) );
	}
}
