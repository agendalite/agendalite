<?php
/**
 * Booking persistence and lifecycle helpers.
 *
 * @package LiteCal
 */

namespace LiteCal\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// phpcs:disable Squiz.Commenting,WordPress.NamingConventions.ValidVariableName -- Project style preference: keep concise docs and legacy variable names without functional impact.

// phpcs:disable WordPress.PHP.YodaConditions.NotYoda,Universal.Operators.DisallowShortTernary.Found -- Project style preference; no functional impact.

// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Uses trusted $wpdb->prefix table names and prepares dynamic values where needed.

class Bookings {

	private static $has_pending_at_column    = null;
	private static $has_received_at_column   = null;
	private static $has_token_hash_column    = null;
	private static $has_token_created_column = null;
	private static $cache_version            = null;

	private static function cache_version() {
		if ( self::$cache_version !== null ) {
			return self::$cache_version;
		}
		$version = (int) get_option( 'litecal_bookings_cache_v', 1 );
		if ( $version <= 0 ) {
			$version = 1;
		}
		self::$cache_version = $version;
		return self::$cache_version;
	}

	public static function bump_cache_version() {
		$version             = self::cache_version() + 1;
		self::$cache_version = $version;
		update_option( 'litecal_bookings_cache_v', $version, false );
	}

	private static function has_pending_at_column() {
		if ( self::$has_pending_at_column !== null ) {
			return self::$has_pending_at_column;
		}
		global $wpdb;
		$table                       = $wpdb->prefix . 'litecal_bookings';
		$column                      = $wpdb->get_var( "SHOW COLUMNS FROM {$table} LIKE 'payment_pending_at'" );
		self::$has_pending_at_column = ! empty( $column );
		return self::$has_pending_at_column;
	}

	private static function has_received_at_column() {
		if ( self::$has_received_at_column !== null ) {
			return self::$has_received_at_column;
		}
		global $wpdb;
		$table                        = $wpdb->prefix . 'litecal_bookings';
		$column                       = $wpdb->get_var( "SHOW COLUMNS FROM {$table} LIKE 'payment_received_at'" );
		self::$has_received_at_column = ! empty( $column );
		return self::$has_received_at_column;
	}

	public static function has_token_hash_column() {
		if ( self::$has_token_hash_column !== null ) {
			return self::$has_token_hash_column;
		}
		global $wpdb;
		$table                       = $wpdb->prefix . 'litecal_bookings';
		$column                      = $wpdb->get_var( "SHOW COLUMNS FROM {$table} LIKE 'booking_token_hash'" );
		self::$has_token_hash_column = ! empty( $column );
		return self::$has_token_hash_column;
	}

	public static function has_token_created_column() {
		if ( self::$has_token_created_column !== null ) {
			return self::$has_token_created_column;
		}
		global $wpdb;
		$table                          = $wpdb->prefix . 'litecal_bookings';
		$column                         = $wpdb->get_var( "SHOW COLUMNS FROM {$table} LIKE 'booking_token_created_at'" );
		self::$has_token_created_column = ! empty( $column );
		return self::$has_token_created_column;
	}

	public static function store_token_hash( $id, $hash, $created_at = null ) {
		if ( ! self::has_token_hash_column() ) {
			return false;
		}
		global $wpdb;
		$table = $wpdb->prefix . 'litecal_bookings';
		$data  = array( 'booking_token_hash' => $hash );
		if ( self::has_token_created_column() && ! empty( $created_at ) ) {
			$data['booking_token_created_at'] = $created_at;
		}
		return $wpdb->update( $table, $data, array( 'id' => (int) $id ) );
	}

	public static function create( $data ) {
		global $wpdb;
		$table    = $wpdb->prefix . 'litecal_bookings';
		$now      = current_time( 'mysql' );
		$defaults = array(
			'event_id'             => 0,
			'employee_id'          => null,
			'start_datetime'       => '',
			'end_datetime'         => '',
			'name'                 => '',
			'email'                => '',
			'phone'                => '',
			'company'              => '',
			'message'              => '',
			'guests'               => wp_json_encode( array() ),
			'custom_fields'        => wp_json_encode( array() ),
			'status'               => 'pending',
			'payment_status'       => 'unpaid',
			'payment_provider'     => null,
			'payment_reference'    => null,
			'payment_amount'       => 0,
			'payment_currency'     => 'CLP',
			'payment_error'        => null,
			'payment_voided'       => 0,
			'payment_received_at'  => null,
			'snapshot'             => null,
			'snapshot_history'     => null,
			'snapshot_modified'    => 0,
			'snapshot_modified_at' => null,
			'snapshot_modified_by' => null,
			'video_provider'       => null,
			'video_meeting_id'     => null,
			'calendar_provider'    => null,
			'calendar_event_id'    => null,
			'calendar_meet_link'   => null,
			'created_at'           => $now,
			'updated_at'           => $now,
		);
		$data     = wp_parse_args( $data, $defaults );
		$wpdb->insert( $table, $data );
		if ( ! empty( $data['email'] ) ) {
			self::clear_customer_removed_email( (string) $data['email'] );
		}
		self::bump_cache_version();
		return $wpdb->insert_id;
	}

	public static function update_status( $id, $status ) {
		global $wpdb;
		$table   = $wpdb->prefix . 'litecal_bookings';
		$updated = $wpdb->update(
			$table,
			array(
				'status'     => $status,
				'updated_at' => current_time( 'mysql' ),
			),
			array( 'id' => $id )
		);
		if ( $updated !== false ) {
			self::bump_cache_version();
		}
		return $updated;
	}

	public static function update_payment( $id, array $data ) {
		global $wpdb;
		$table   = $wpdb->prefix . 'litecal_bookings';
		$current = self::get( $id );
		if (
			self::has_pending_at_column()
			&& isset( $data['payment_status'] )
			&& $data['payment_status'] === 'pending'
			&& ! isset( $data['payment_pending_at'] )
		) {
			if ( $current && empty( $current->payment_pending_at ) ) {
				$data['payment_pending_at'] = current_time( 'mysql' );
			} elseif ( $current && ! empty( $current->payment_pending_at ) ) {
				$data['payment_pending_at'] = $current->payment_pending_at;
			}
		}
		if ( self::has_received_at_column() && isset( $data['payment_status'] ) ) {
			$paid_statuses = array( 'paid', 'approved', 'completed', 'confirmed' );
			if ( in_array( strtolower( (string) $data['payment_status'] ), $paid_statuses, true ) ) {
				if ( ! isset( $data['payment_received_at'] ) ) {
					if ( $current && ! empty( $current->payment_received_at ) ) {
						$data['payment_received_at'] = $current->payment_received_at;
					} else {
						$data['payment_received_at'] = current_time( 'mysql' );
					}
				}
			}
		}
		$data['updated_at'] = current_time( 'mysql' );
		$updated            = $wpdb->update( $table, $data, array( 'id' => $id ) );
		if ( $updated !== false ) {
			self::bump_cache_version();
		}
		return $updated;
	}

	public static function update_calendar( $id, array $data ) {
		global $wpdb;
		$table              = $wpdb->prefix . 'litecal_bookings';
		$data['updated_at'] = current_time( 'mysql' );
		$updated            = $wpdb->update( $table, $data, array( 'id' => $id ) );
		if ( $updated !== false ) {
			self::bump_cache_version();
		}
		return $updated;
	}

	public static function all( $include_deleted = false ) {
		$cache_key = 'litecal_bookings_all_' . md5( (int) $include_deleted . '|' . self::cache_version() );
		$cached    = get_transient( $cache_key );
		if ( is_array( $cached ) ) {
			return $cached;
		}
		global $wpdb;
		$table = $wpdb->prefix . 'litecal_bookings';
		$where = $include_deleted ? '' : "WHERE status <> 'deleted'";
		$rows  = $wpdb->get_results( "SELECT * FROM {$table} {$where} ORDER BY created_at DESC" );
		set_transient( $cache_key, $rows, 30 );
		return $rows;
	}

	public static function normalize_email_key( $email ) {
		return strtolower( trim( sanitize_email( (string) $email ) ) );
	}

	private static function customer_trash_keys() {
		$stored = get_option( 'litecal_clients_trash', array() );
		if ( is_string( $stored ) ) {
			$stored = array_filter( explode( ',', $stored ) );
		}
		if ( ! is_array( $stored ) ) {
			$stored = array();
		}
		$normalized = array();
		foreach ( $stored as $email ) {
			$key = self::normalize_email_key( $email );
			if ( $key !== '' ) {
				$normalized[] = $key;
			}
		}
		return array_values( array_unique( $normalized ) );
	}

	private static function customer_removed_keys() {
		$stored = get_option( 'litecal_clients_removed', array() );
		if ( is_string( $stored ) ) {
			$stored = array_filter( explode( ',', $stored ) );
		}
		if ( ! is_array( $stored ) ) {
			$stored = array();
		}
		$normalized = array();
		foreach ( $stored as $email ) {
			$key = self::normalize_email_key( $email );
			if ( $key !== '' ) {
				$normalized[] = $key;
			}
		}
		return array_values( array_unique( $normalized ) );
	}

	public static function is_customer_removed_email( $email ) {
		$key = self::normalize_email_key( $email );
		if ( $key === '' ) {
			return false;
		}
		return in_array( $key, self::customer_removed_keys(), true );
	}

	public static function set_customer_removed_keys( array $emails ) {
		$normalized = array();
		foreach ( $emails as $email ) {
			$key = self::normalize_email_key( $email );
			if ( $key !== '' ) {
				$normalized[] = $key;
			}
		}
		update_option( 'litecal_clients_removed', array_values( array_unique( $normalized ) ), false );
		self::bump_cache_version();
	}

	public static function clear_customer_removed_email( $email ) {
		$key = self::normalize_email_key( $email );
		if ( $key === '' ) {
			return;
		}
		$current = self::customer_removed_keys();
		if ( empty( $current ) ) {
			return;
		}
		$next = array_values( array_diff( $current, array( $key ) ) );
		if ( $next === $current ) {
			return;
		}
		update_option( 'litecal_clients_removed', $next, false );
		self::bump_cache_version();
	}

	public static function search( $search, $page, $per_page, $only_payments = false, $status = '', $status_field = 'status', $include_deleted = false, $sort_by = 'id', $sort_dir = 'desc', $employee_id = 0 ) {
		$cache_args = array(
			'search'          => (string) $search,
			'page'            => (int) $page,
			'per_page'        => (int) $per_page,
			'only_payments'   => (int) ! empty( $only_payments ),
			'status'          => (string) $status,
			'status_field'    => (string) $status_field,
			'include_deleted' => (int) ! empty( $include_deleted ),
			'sort_by'         => (string) $sort_by,
			'sort_dir'        => (string) $sort_dir,
			'employee_id'     => (int) $employee_id,
			'v'               => self::cache_version(),
		);
		$cache_key  = 'litecal_bookings_search_' . md5( wp_json_encode( $cache_args ) );
		$cached     = get_transient( $cache_key );
		if ( is_array( $cached ) && isset( $cached['rows'] ) && isset( $cached['total'] ) ) {
			return array( $cached['rows'], (int) $cached['total'] );
		}
		global $wpdb;
		$table        = $wpdb->prefix . 'litecal_bookings';
		$events_table = $wpdb->prefix . 'litecal_events';
		$page         = max( 1, (int) $page );
		$per_page     = max( 1, (int) $per_page );
		$offset       = ( $page - 1 ) * $per_page;
		$where        = '1=1';
		$params       = array();
		if ( ! $include_deleted ) {
			$where .= " AND b.status <> 'deleted'";
		}
		if ( $only_payments ) {
			$where .= " AND (COALESCE(b.payment_voided, 0) = 0 OR b.payment_status IN ('cancelled','expired','rejected','failed')) AND (b.payment_provider IS NOT NULL OR b.payment_reference IS NOT NULL OR b.payment_status <> 'unpaid')";
		}
		if ( $status !== '' ) {
			$field = $status_field === 'payment_status' ? 'b.payment_status' : 'b.status';
			if ( $status === 'cancelled' ) {
				$where .= " AND ({$field} = 'cancelled' OR {$field} = 'canceled' OR {$field} = 'expired')";
			} else {
				$where   .= " AND {$field} = %s";
				$params[] = $status;
			}
		}
		if ( $search ) {
			$like     = '%' . $wpdb->esc_like( $search ) . '%';
			$where   .= ' AND (b.name LIKE %s OR b.email LIKE %s OR e.title LIKE %s OR b.payment_reference LIKE %s)';
			$params[] = $like;
			$params[] = $like;
			$params[] = $like;
			$params[] = $like;
		}
		if ( (int) $employee_id > 0 ) {
			$where   .= ' AND b.employee_id = %d';
			$params[] = (int) $employee_id;
		}
		$count_sql    = "SELECT COUNT(*)
                FROM {$table} b
                LEFT JOIN {$events_table} e ON b.event_id = e.id
                WHERE {$where}";
		$count_params = $params;
		$total        = $count_params
			? (int) $wpdb->get_var( $wpdb->prepare( $count_sql, $count_params ) )
			: (int) $wpdb->get_var( $count_sql );

		$sort_dir  = strtolower( (string) $sort_dir ) === 'asc' ? 'ASC' : 'DESC';
		$order_map = array(
			'id'                => 'b.id',
			'client'            => 'b.name',
			'email'             => 'b.email',
			'event'             => 'e.title',
			'attendee'          => 'b.employee_id',
			'start_datetime'    => 'b.start_datetime',
			'date'              => 'b.start_datetime',
			'time'              => 'b.start_datetime',
			'status'            => 'b.status',
			'payment_status'    => 'b.payment_status',
			'provider'          => 'b.payment_provider',
			'payment_provider'  => 'b.payment_provider',
			'amount'            => 'b.payment_amount',
			'payment_amount'    => 'b.payment_amount',
			'reference'         => 'b.payment_reference',
			'payment_reference' => 'b.payment_reference',
			'created_at'        => 'b.created_at',
			'updated_at'        => 'b.updated_at',
			'payment_date'      => "COALESCE(NULLIF(b.payment_received_at, '0000-00-00 00:00:00'), b.updated_at, b.start_datetime)",
		);
		$order_by  = $order_map[ $sort_by ] ?? 'b.id';

		$sql      = "SELECT b.*, e.title as event_title
                FROM {$table} b
                LEFT JOIN {$events_table} e ON b.event_id = e.id
                WHERE {$where}
                ORDER BY {$order_by} {$sort_dir}, b.id DESC
                LIMIT %d OFFSET %d";
		$params[] = $per_page;
		$params[] = $offset;
		$rows     = $wpdb->get_results( $wpdb->prepare( $sql, $params ) );
		set_transient(
			$cache_key,
			array(
				'rows'  => $rows,
				'total' => (int) $total,
			),
			30
		);
		return array( $rows, $total );
	}

	public static function customer_search( $search, $page, $per_page, $view = 'active', $sort_by = 'last_booking_at', $sort_dir = 'desc', $employee_id = 0, array $email_whitelist = array() ) {
		$view = $view === 'trash' ? 'trash' : 'active';
		$normalized_whitelist = array();
		foreach ( (array) $email_whitelist as $email_key ) {
			$key = self::normalize_email_key( $email_key );
			if ( $key !== '' ) {
				$normalized_whitelist[] = $key;
			}
		}
		$normalized_whitelist = array_values( array_unique( $normalized_whitelist ) );
		$cache_args = array(
			'search'      => (string) $search,
			'page'        => (int) $page,
			'per_page'    => (int) $per_page,
			'view'        => (string) $view,
			'sort_by'     => (string) $sort_by,
			'sort_dir'    => (string) $sort_dir,
			'employee_id' => (int) $employee_id,
			'whitelist'   => md5( wp_json_encode( $normalized_whitelist ) ),
			'v'           => self::cache_version(),
		);
		$cache_key  = 'litecal_customers_search_' . md5( wp_json_encode( $cache_args ) );
		$cached     = get_transient( $cache_key );
		if ( is_array( $cached ) && isset( $cached['rows'] ) && isset( $cached['total'] ) ) {
			return array( $cached['rows'], (int) $cached['total'] );
		}

		global $wpdb;
		$table     = $wpdb->prefix . 'litecal_bookings';
		$page      = max( 1, (int) $page );
		$per_page  = max( 1, (int) $per_page );
		$offset    = ( $page - 1 ) * $per_page;
		$where     = array( "TRIM(COALESCE(b.email, '')) <> ''" );
		$params    = array();
		$email_sql = 'LOWER(TRIM(b.email))';
		$trashed   = self::customer_trash_keys();
		$removed   = self::customer_removed_keys();

		if ( $view === 'trash' && empty( $trashed ) ) {
			return array( array(), 0 );
		}

		if ( ! empty( $trashed ) ) {
			$placeholders = implode( ',', array_fill( 0, count( $trashed ), '%s' ) );
			if ( $view === 'trash' ) {
				$where   [] = "{$email_sql} IN ({$placeholders})";
				$params   = array_merge( $params, $trashed );
			} else {
				$where   [] = "{$email_sql} NOT IN ({$placeholders})";
				$params   = array_merge( $params, $trashed );
			}
		}
		if ( ! empty( $removed ) ) {
			$removed_placeholders = implode( ',', array_fill( 0, count( $removed ), '%s' ) );
			$where[]              = "{$email_sql} NOT IN ({$removed_placeholders})";
			$params               = array_merge( $params, $removed );
		}
		if ( ! empty( $normalized_whitelist ) ) {
			$whitelist_placeholders = implode( ',', array_fill( 0, count( $normalized_whitelist ), '%s' ) );
			$where[]                = "{$email_sql} IN ({$whitelist_placeholders})";
			$params                 = array_merge( $params, $normalized_whitelist );
		}

		if ( (int) $employee_id > 0 ) {
			$where[]  = 'b.employee_id = %d';
			$params[] = (int) $employee_id;
		}

		if ( $search !== '' ) {
			$matching_where  = array( "TRIM(COALESCE(bs.email, '')) <> ''" );
			$matching_params = array();
			if ( (int) $employee_id > 0 ) {
				$matching_where[]  = 'bs.employee_id = %d';
				$matching_params[] = (int) $employee_id;
			}
			$like              = '%' . $wpdb->esc_like( $search ) . '%';
			$matching_where[]  = '(bs.name LIKE %s OR bs.email LIKE %s OR bs.phone LIKE %s OR bs.company LIKE %s)';
			$matching_params[] = $like;
			$matching_params[] = $like;
			$matching_params[] = $like;
			$matching_params[] = $like;
			$matching_sql      = "SELECT DISTINCT LOWER(TRIM(bs.email)) FROM {$table} bs WHERE " . implode( ' AND ', $matching_where );
			if ( ! empty( $matching_params ) ) {
				$matching_sql = $wpdb->prepare( $matching_sql, $matching_params );
			}
			$where[] = "{$email_sql} IN ({$matching_sql})";
		}

		$group_sql = "SELECT
				{$email_sql} AS email_key,
				MAX(b.id) AS latest_booking_id,
				COUNT(*) AS total_bookings,
				SUM(CASE WHEN b.status <> 'deleted' THEN 1 ELSE 0 END) AS active_bookings,
				SUM(CASE WHEN b.status = 'deleted' THEN 1 ELSE 0 END) AS deleted_bookings,
				MAX(COALESCE(NULLIF(b.start_datetime, '0000-00-00 00:00:00'), b.created_at)) AS last_booking_at
			FROM {$table} b
			WHERE " . implode( ' AND ', $where ) . "
			GROUP BY {$email_sql}";

		$count_sql = "SELECT COUNT(*) FROM ({$group_sql}) lc_customer_groups";
		$total     = ! empty( $params )
			? (int) $wpdb->get_var( $wpdb->prepare( $count_sql, $params ) )
			: (int) $wpdb->get_var( $count_sql );

		$sort_dir  = strtolower( (string) $sort_dir ) === 'asc' ? 'ASC' : 'DESC';
		$order_map = array(
			'client'          => 'lb.name',
			'name'            => 'lb.name',
			'email'           => 'g.email_key',
			'phone'           => 'lb.phone',
			'total_bookings'  => 'g.total_bookings',
			'active_bookings' => 'g.active_bookings',
			'last_booking_at' => 'g.last_booking_at',
		);
		$order_by  = $order_map[ $sort_by ] ?? 'g.last_booking_at';
		$latest_where = array( 'LOWER(TRIM(lb2.email)) = g.email_key' );
		if ( (int) $employee_id > 0 ) {
			$latest_where[] = 'lb2.employee_id = %d';
		}
		$latest_join = "SELECT lb2.id
			FROM {$table} lb2
			WHERE " . implode( ' AND ', $latest_where ) . "
			ORDER BY COALESCE(NULLIF(lb2.start_datetime, '0000-00-00 00:00:00'), lb2.created_at) DESC, lb2.id DESC
			LIMIT 1";

		$sql          = "SELECT
				g.*,
				lb.name AS latest_name,
				lb.email AS latest_email,
				lb.phone AS latest_phone,
				lb.company AS latest_company,
				lb.status AS latest_status,
				lb.snapshot AS latest_snapshot,
				lb.created_at AS latest_created_at
			FROM ({$group_sql}) g
			LEFT JOIN {$table} lb ON lb.id = ({$latest_join})
			ORDER BY {$order_by} {$sort_dir}, g.latest_booking_id DESC
			LIMIT %d OFFSET %d";
		$query_params = array_merge( $params, ( (int) $employee_id > 0 ? array( (int) $employee_id ) : array() ), array( $per_page, $offset ) );
		$rows         = $wpdb->get_results( $wpdb->prepare( $sql, $query_params ) );

		set_transient(
			$cache_key,
			array(
				'rows'  => $rows,
				'total' => (int) $total,
			),
			30
		);

		return array( $rows, $total );
	}

	public static function customer_history( $email, $employee_id = 0, $include_deleted = true ) {
		$email_key = self::normalize_email_key( $email );
		if ( $email_key === '' ) {
			return array();
		}
		$cache_args = array(
			'email'           => $email_key,
			'employee_id'     => (int) $employee_id,
			'include_deleted' => (int) $include_deleted,
			'v'               => self::cache_version(),
		);
		$cache_key  = 'litecal_customer_history_' . md5( wp_json_encode( $cache_args ) );
		$cached     = get_transient( $cache_key );
		if ( is_array( $cached ) ) {
			return $cached;
		}

		global $wpdb;
		$table        = $wpdb->prefix . 'litecal_bookings';
		$events_table = $wpdb->prefix . 'litecal_events';
		$where        = array( 'LOWER(TRIM(b.email)) = %s' );
		$params       = array( $email_key );
		if ( (int) $employee_id > 0 ) {
			$where[]  = 'b.employee_id = %d';
			$params[] = (int) $employee_id;
		}
		if ( ! $include_deleted ) {
			$where[] = "b.status <> 'deleted'";
		}

		$sql  = "SELECT b.*, e.title AS event_title
			FROM {$table} b
			LEFT JOIN {$events_table} e ON b.event_id = e.id
			WHERE " . implode( ' AND ', $where ) . "
			ORDER BY COALESCE(NULLIF(b.start_datetime, '0000-00-00 00:00:00'), b.created_at) DESC, b.id DESC";
		$rows = $wpdb->get_results( $wpdb->prepare( $sql, $params ) );
		set_transient( $cache_key, $rows, 30 );
		return $rows;
	}

	public static function customer_booking_ids_by_email( $email, $employee_id = 0, $include_deleted = true ) {
		$email_key = self::normalize_email_key( $email );
		if ( $email_key === '' ) {
			return array();
		}
		global $wpdb;
		$table  = $wpdb->prefix . 'litecal_bookings';
		$where  = array( 'LOWER(TRIM(email)) = %s' );
		$params = array( $email_key );
		if ( (int) $employee_id > 0 ) {
			$where[]  = 'employee_id = %d';
			$params[] = (int) $employee_id;
		}
		if ( ! $include_deleted ) {
			$where[] = "status <> 'deleted'";
		}
		$sql = "SELECT id FROM {$table} WHERE " . implode( ' AND ', $where ) . ' ORDER BY id ASC';
		return array_map( 'intval', (array) $wpdb->get_col( $wpdb->prepare( $sql, $params ) ) );
	}

	public static function customer_email_keys( $view = 'active', $employee_id = 0 ) {
		$view      = $view === 'trash' ? 'trash' : 'active';
		global $wpdb;
		$table     = $wpdb->prefix . 'litecal_bookings';
		$where     = array( "TRIM(COALESCE(b.email, '')) <> ''" );
		$params    = array();
		$email_sql = 'LOWER(TRIM(b.email))';
		$trashed   = self::customer_trash_keys();
		$removed   = self::customer_removed_keys();
		if ( (int) $employee_id > 0 ) {
			$where[]  = 'b.employee_id = %d';
			$params[] = (int) $employee_id;
		}
		$sql    = "SELECT {$email_sql} AS email_key
			FROM {$table} b
			WHERE " . implode( ' AND ', $where ) . "
			GROUP BY {$email_sql}";
		if ( ! empty( $params ) ) {
			$sql = $wpdb->prepare( $sql, $params );
		}
		$emails = array_values( array_filter( array_map( 'strval', (array) $wpdb->get_col( $sql ) ) ) );
		if ( ! empty( $removed ) ) {
			$emails = array_values( array_diff( $emails, $removed ) );
		}
		if ( $view === 'trash' ) {
			if ( empty( $trashed ) ) {
				return array();
			}
			return array_values( array_intersect( $emails, $trashed ) );
		}
		if ( empty( $trashed ) ) {
			return $emails;
		}
		return array_values( array_diff( $emails, $trashed ) );
	}

	public static function in_range( $start_datetime, $end_datetime, $include_deleted = false, $employee_id = 0 ) {
		$cache_key = 'litecal_bookings_range_' . md5( $start_datetime . '|' . $end_datetime . '|' . (int) $include_deleted . '|' . (int) $employee_id . '|' . self::cache_version() );
		$cached    = get_transient( $cache_key );
		if ( is_array( $cached ) ) {
			return $cached;
		}
		global $wpdb;
		$table        = $wpdb->prefix . 'litecal_bookings';
		$events_table = $wpdb->prefix . 'litecal_events';
		$where        = 'b.start_datetime >= %s AND b.start_datetime < %s';
		$params       = array( $start_datetime, $end_datetime );
		if ( ! $include_deleted ) {
			$where .= " AND b.status <> 'deleted'";
		}
		if ( (int) $employee_id > 0 ) {
			$where   .= ' AND b.employee_id = %d';
			$params[] = (int) $employee_id;
		}
		$sql  = "SELECT b.*, e.title as event_title
                FROM {$table} b
                LEFT JOIN {$events_table} e ON b.event_id = e.id
                WHERE {$where}
                ORDER BY b.start_datetime ASC, b.id DESC";
		$rows = $wpdb->get_results( $wpdb->prepare( $sql, $params ) );
		set_transient( $cache_key, $rows, 30 );
		return $rows;
	}

	public static function get( $id ) {
		global $wpdb;
		$table = $wpdb->prefix . 'litecal_bookings';
		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id ) );
	}

	public static function delete_ids( array $ids ) {
		global $wpdb;
		$table = $wpdb->prefix . 'litecal_bookings';
		$ids   = array_filter( array_map( 'intval', $ids ) );
		if ( ! $ids ) {
			return 0;
		}
		$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
		$query        = $wpdb->prepare( "UPDATE {$table} SET status = 'deleted', updated_at = %s WHERE id IN ({$placeholders})", array_merge( array( current_time( 'mysql' ) ), $ids ) );
		$result       = $wpdb->query( $query );
		if ( $result !== false ) {
			self::bump_cache_version();
		}
		return $result;
	}

	public static function delete_permanent( $id ) {
		global $wpdb;
		$table   = $wpdb->prefix . 'litecal_bookings';
		$deleted = $wpdb->delete( $table, array( 'id' => (int) $id ) );
		if ( $deleted !== false ) {
			self::bump_cache_version();
		}
		return $deleted;
	}

	public static function decode_snapshot( $booking ) {
		if ( empty( $booking ) ) {
			return array();
		}
		$raw = '';
		if ( is_object( $booking ) && property_exists( $booking, 'snapshot' ) ) {
			$raw = (string) $booking->snapshot;
		} elseif ( is_array( $booking ) && isset( $booking['snapshot'] ) ) {
			$raw = (string) $booking['snapshot'];
		}
		if ( $raw === '' ) {
			return array();
		}
		$data = json_decode( $raw, true );
		return is_array( $data ) ? $data : array();
	}

	public static function update_snapshot( $id, array $snapshot, ?array $history_entry = null ) {
		global $wpdb;
		$table   = $wpdb->prefix . 'litecal_bookings';
		$current = self::get( $id );
		$history = array();
		if ( $current && property_exists( $current, 'snapshot_history' ) && ! empty( $current->snapshot_history ) ) {
			$decoded = json_decode( $current->snapshot_history, true );
			if ( is_array( $decoded ) ) {
				$history = $decoded;
			}
		}
		if ( $history_entry ) {
			$history[] = $history_entry;
		}
		$updated = $wpdb->update(
			$table,
			array(
				'snapshot'             => wp_json_encode( $snapshot ),
				'snapshot_history'     => $history ? wp_json_encode( $history ) : null,
				'snapshot_modified'    => 1,
				'snapshot_modified_at' => current_time( 'mysql' ),
				'snapshot_modified_by' => get_current_user_id(),
				'updated_at'           => current_time( 'mysql' ),
			),
			array( 'id' => (int) $id )
		);
		if ( $updated !== false ) {
			self::bump_cache_version();
		}
		return $updated;
	}

	public static function cleanup_stale_payments( $minutes = 30 ) {
		global $wpdb;
		$table   = $wpdb->prefix . 'litecal_bookings';
		$minutes = (int) $minutes;
		if ( $minutes <= 0 ) {
			$minutes = 10;
		}
		$default_cutoff_ts   = (int) current_datetime()->getTimestamp() - ( $minutes * 60 );
		$default_cutoff      = wp_date( 'Y-m-d H:i:s', $default_cutoff_ts );
		$compare_column      = self::has_pending_at_column() ? 'IFNULL(payment_pending_at, created_at)' : 'created_at';
		$expired_default_ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT id FROM {$table}
                 WHERE payment_status = 'pending' AND payment_provider IS NOT NULL AND payment_provider NOT IN ('transfer','onsite') AND {$compare_column} < %s",
				$default_cutoff
			)
		);
		$updated_default     = $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$table}
                 SET payment_status = 'cancelled', payment_error = 'Pago cancelado por tiempo limite', payment_voided = 0, status = 'cancelled', updated_at = %s
                 WHERE payment_status = 'pending' AND payment_provider IS NOT NULL AND payment_provider NOT IN ('transfer','onsite') AND {$compare_column} < %s",
				current_time( 'mysql' ),
				$default_cutoff
			)
		);
		$updated_total       = max( 0, (int) $updated_default );
		if ( $updated_total > 0 ) {
			self::bump_cache_version();
			$expired_ids = array_values( array_unique( array_map( 'intval', $expired_default_ids ?: array() ) ) );
			if ( ! empty( $expired_ids ) ) {
				do_action( 'litecal_bookings_expired', $expired_ids );
			}
		}
	}

	public static function exists_overlap( $employee_id, $start, $end ) {
		global $wpdb;
		$table = $wpdb->prefix . 'litecal_bookings';
		$sql   = $wpdb->prepare(
			"SELECT COUNT(*) FROM {$table} WHERE employee_id = %d AND status IN ('pending','confirmed') AND ((start_datetime < %s AND end_datetime > %s) OR (start_datetime >= %s AND start_datetime < %s))",
			$employee_id,
			$end,
			$start,
			$start,
			$end
		);
		return (int) $wpdb->get_var( $sql ) > 0;
	}

	public static function by_date( $event_id, $employee_id, $date, $exclude_id = 0 ) {
		global $wpdb;
		$table  = $wpdb->prefix . 'litecal_bookings';
		$where  = 'DATE(start_datetime) = %s';
		$params = array( $date );
		if ( ! empty( $employee_id ) ) {
			$where   .= ' AND employee_id = %d';
			$params[] = $employee_id;
		} else {
			$where   .= ' AND event_id = %d';
			$params[] = $event_id;
		}
		if ( ! empty( $exclude_id ) ) {
			$where   .= ' AND id <> %d';
			$params[] = (int) $exclude_id;
		}
		$where .= " AND status <> 'deleted'";
		$sql    = $wpdb->prepare( "SELECT start_datetime, end_datetime, status, snapshot FROM {$table} WHERE {$where}", $params );
		return $wpdb->get_results( $sql );
	}

	public static function has_reschedule_overlap( $employee_id, $event_id, $start, $end, $exclude_id = 0 ) {
		global $wpdb;
		$table  = $wpdb->prefix . 'litecal_bookings';
		$where  = array();
		$params = array();

		if ( ! empty( $employee_id ) ) {
			$where[]  = '(employee_id = %d OR (event_id = %d AND (employee_id IS NULL OR employee_id = 0)))';
			$params[] = (int) $employee_id;
			$params[] = (int) $event_id;
		} else {
			$where[]  = 'event_id = %d';
			$params[] = (int) $event_id;
		}

		$where[]  = "status NOT IN ('cancelled','canceled','deleted','expired')";
		$where[]  = 'start_datetime < %s';
		$params[] = $end;
		$where[]  = 'end_datetime > %s';
		$params[] = $start;

		if ( ! empty( $exclude_id ) ) {
			$where[]  = 'id <> %d';
			$params[] = (int) $exclude_id;
		}

		$sql      = "SELECT COUNT(*) FROM {$table} WHERE " . implode( ' AND ', $where );
		$prepared = $wpdb->prepare( $sql, $params );
		return (int) $wpdb->get_var( $prepared ) > 0;
	}
}
