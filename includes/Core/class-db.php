<?php
/**
 * Database schema management for Agenda Lite.
 *
 * @package LiteCal
 */

namespace LiteCal\Core;

// phpcs:disable Squiz.Commenting,WordPress.NamingConventions.ValidVariableName -- Project style preference: keep concise docs and legacy variable names without functional impact.

// phpcs:disable WordPress.PHP.YodaConditions.NotYoda,Universal.Operators.DisallowShortTernary.Found -- Project style preference; no functional impact.

// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Uses trusted $wpdb->prefix table names and static schema SQL statements.
// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Installer/seed operations run during activation and use internal plugin tables.

class DB {

	public static function activate() {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset = $wpdb->get_charset_collate();
		$prefix  = $wpdb->prefix . 'litecal_';

		$sql   = array();
		$sql[] = "CREATE TABLE {$prefix}events (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            title VARCHAR(190) NOT NULL,
            slug VARCHAR(190) NOT NULL,
            description TEXT NULL,
            duration INT NOT NULL DEFAULT 30,
            buffer_before INT NOT NULL DEFAULT 0,
            buffer_after INT NOT NULL DEFAULT 0,
            location VARCHAR(50) NOT NULL DEFAULT 'custom',
            location_details TEXT NULL,
            capacity INT NOT NULL DEFAULT 1,
            status VARCHAR(20) NOT NULL DEFAULT 'active',
            require_payment TINYINT(1) NOT NULL DEFAULT 0,
            price DECIMAL(10,2) NOT NULL DEFAULT 0,
            currency VARCHAR(10) NOT NULL DEFAULT 'USD',
            custom_fields LONGTEXT NULL,
            options LONGTEXT NULL,
            availability_override TINYINT(1) NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY slug (slug)
        ) $charset;";

		$sql[] = "CREATE TABLE {$prefix}employees (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            wp_user_id BIGINT UNSIGNED NULL,
            name VARCHAR(190) NOT NULL,
            title VARCHAR(190) NULL,
            email VARCHAR(190) NOT NULL,
            phone VARCHAR(50) NULL,
            avatar_url TEXT NULL,
            avatar_custom TINYINT(1) NOT NULL DEFAULT 0,
            schedule_id VARCHAR(190) NULL,
            timezone VARCHAR(60) NOT NULL DEFAULT 'UTC',
            status VARCHAR(20) NOT NULL DEFAULT 'active',
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            KEY wp_user_id (wp_user_id)
        ) $charset;";

		$sql[] = "CREATE TABLE {$prefix}event_employees (
            event_id BIGINT UNSIGNED NOT NULL,
            employee_id BIGINT UNSIGNED NOT NULL,
            sort_order INT NOT NULL DEFAULT 0,
            PRIMARY KEY (event_id, employee_id)
        ) $charset;";

		$sql[] = "CREATE TABLE {$prefix}availability (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            scope VARCHAR(20) NOT NULL DEFAULT 'global',
            scope_id BIGINT UNSIGNED NULL,
            day_of_week TINYINT NOT NULL,
            start_time TIME NOT NULL,
            end_time TIME NOT NULL,
            created_at DATETIME NOT NULL,
            PRIMARY KEY (id)
        ) $charset;";

		$sql[] = "CREATE TABLE {$prefix}time_off (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            scope VARCHAR(20) NOT NULL DEFAULT 'global',
            scope_id BIGINT UNSIGNED NULL,
            start_date DATE NOT NULL,
            end_date DATE NOT NULL,
            reason VARCHAR(190) NULL,
            PRIMARY KEY (id)
        ) $charset;";

		$sql[] = "CREATE TABLE {$prefix}bookings (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            event_id BIGINT UNSIGNED NOT NULL,
            employee_id BIGINT UNSIGNED NULL,
            start_datetime DATETIME NOT NULL,
            end_datetime DATETIME NOT NULL,
            name VARCHAR(190) NOT NULL,
            email VARCHAR(190) NOT NULL,
            phone VARCHAR(50) NULL,
            company VARCHAR(190) NULL,
            message TEXT NULL,
            guests LONGTEXT NULL,
            custom_fields LONGTEXT NULL,
            status VARCHAR(20) NOT NULL DEFAULT 'pending',
            payment_status VARCHAR(20) NOT NULL DEFAULT 'unpaid',
            payment_provider VARCHAR(50) NULL,
            payment_reference VARCHAR(190) NULL,
            payment_amount DECIMAL(10,2) NOT NULL DEFAULT 0,
            payment_currency VARCHAR(10) NOT NULL DEFAULT 'CLP',
            payment_error TEXT NULL,
            payment_voided TINYINT(1) NOT NULL DEFAULT 0,
            payment_pending_at DATETIME NULL,
            payment_received_at DATETIME NULL,
            booking_token_hash VARCHAR(255) NULL,
            booking_token_created_at DATETIME NULL,
            snapshot LONGTEXT NULL,
            snapshot_history LONGTEXT NULL,
            snapshot_modified TINYINT(1) NOT NULL DEFAULT 0,
            snapshot_modified_at DATETIME NULL,
            snapshot_modified_by BIGINT UNSIGNED NULL,
            video_provider VARCHAR(50) NULL,
            video_meeting_id VARCHAR(190) NULL,
            calendar_provider VARCHAR(50) NULL,
            calendar_event_id VARCHAR(190) NULL,
            calendar_meet_link TEXT NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            KEY event_id (event_id),
            KEY employee_id (employee_id),
            KEY status (status),
            KEY payment_status (payment_status),
            KEY start_datetime (start_datetime),
            KEY created_at (created_at),
            KEY payment_provider (payment_provider),
            KEY payment_reference (payment_reference),
            KEY status_start (status, start_datetime),
            KEY payment_status_created (payment_status, created_at),
            KEY employee_status_start (employee_id, status, start_datetime),
            KEY payment_received_at (payment_received_at),
            KEY payment_status_received (payment_status, payment_received_at)
        ) $charset;";

		foreach ( $sql as $statement ) {
			dbDelta( $statement );
		}

		self::seed_demo();
		self::ensure_settings_defaults();
		flush_rewrite_rules();
	}

	public static function maybe_migrate() {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$charset = $wpdb->get_charset_collate();
		$prefix  = $wpdb->prefix . 'litecal_';
		$sql     = "CREATE TABLE {$prefix}bookings (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            event_id BIGINT UNSIGNED NOT NULL,
            employee_id BIGINT UNSIGNED NULL,
            start_datetime DATETIME NOT NULL,
            end_datetime DATETIME NOT NULL,
            name VARCHAR(190) NOT NULL,
            email VARCHAR(190) NOT NULL,
            phone VARCHAR(50) NULL,
            company VARCHAR(190) NULL,
            message TEXT NULL,
            guests LONGTEXT NULL,
            custom_fields LONGTEXT NULL,
            status VARCHAR(20) NOT NULL DEFAULT 'pending',
            payment_status VARCHAR(20) NOT NULL DEFAULT 'unpaid',
            payment_provider VARCHAR(50) NULL,
            payment_reference VARCHAR(190) NULL,
            payment_amount DECIMAL(10,2) NOT NULL DEFAULT 0,
            payment_currency VARCHAR(10) NOT NULL DEFAULT 'CLP',
            payment_error TEXT NULL,
            payment_voided TINYINT(1) NOT NULL DEFAULT 0,
            payment_pending_at DATETIME NULL,
            payment_received_at DATETIME NULL,
            booking_token_hash VARCHAR(255) NULL,
            booking_token_created_at DATETIME NULL,
            snapshot LONGTEXT NULL,
            snapshot_history LONGTEXT NULL,
            snapshot_modified TINYINT(1) NOT NULL DEFAULT 0,
            snapshot_modified_at DATETIME NULL,
            snapshot_modified_by BIGINT UNSIGNED NULL,
            video_provider VARCHAR(50) NULL,
            video_meeting_id VARCHAR(190) NULL,
            calendar_provider VARCHAR(50) NULL,
            calendar_event_id VARCHAR(190) NULL,
            calendar_meet_link TEXT NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            KEY event_id (event_id),
            KEY employee_id (employee_id),
            KEY status (status),
            KEY payment_status (payment_status),
            KEY start_datetime (start_datetime),
            KEY created_at (created_at),
            KEY payment_provider (payment_provider),
            KEY payment_reference (payment_reference),
            KEY status_start (status, start_datetime),
            KEY payment_status_created (payment_status, created_at),
            KEY employee_status_start (employee_id, status, start_datetime),
            KEY payment_received_at (payment_received_at),
            KEY payment_status_received (payment_status, payment_received_at)
        ) $charset;";
		dbDelta( $sql );

		$employees_sql = "CREATE TABLE {$prefix}employees (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            wp_user_id BIGINT UNSIGNED NULL,
            name VARCHAR(190) NOT NULL,
            title VARCHAR(190) NULL,
            email VARCHAR(190) NOT NULL,
            phone VARCHAR(50) NULL,
            avatar_url TEXT NULL,
            avatar_custom TINYINT(1) NOT NULL DEFAULT 0,
            schedule_id VARCHAR(190) NULL,
            timezone VARCHAR(60) NOT NULL DEFAULT 'UTC',
            status VARCHAR(20) NOT NULL DEFAULT 'active',
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            KEY wp_user_id (wp_user_id)
        ) $charset;";
		dbDelta( $employees_sql );

		if ( class_exists( '\LiteCal\Core\Employees' ) ) {
			\LiteCal\Core\Employees::sync_booking_manager_users( true );
		}

		self::ensure_settings_defaults();
	}

	public static function deactivate() {
		flush_rewrite_rules();
	}

	private static function seed_demo() {
		global $wpdb;
		$events_table       = $wpdb->prefix . 'litecal_events';
		$relation_table     = $wpdb->prefix . 'litecal_event_employees';
		$availability_table = $wpdb->prefix . 'litecal_availability';

		$exists = $wpdb->get_var( "SELECT COUNT(*) FROM {$events_table}" );
		if ( (int) $exists > 0 ) {
			return;
		}

		$now = current_time( 'mysql' );

		$wpdb->insert(
			$events_table,
			array(
				'title'            => 'Reunión de 30 min',
				'slug'             => 'reunion-30-min',
				'description'      => 'Reunión breve para alinear objetivos.',
				'duration'         => 30,
				'buffer_before'    => 0,
				'buffer_after'     => 0,
				'location'         => 'google_meet',
				'location_details' => '',
				'capacity'         => 1,
				'status'           => 'draft',
				'require_payment'  => 0,
				'price'            => 0,
				'currency'         => 'USD',
				'custom_fields'    => wp_json_encode( array() ),
				'created_at'       => $now,
				'updated_at'       => $now,
			)
		);
		$event_id = (int) $wpdb->insert_id;

		if ( $event_id > 0 && class_exists( '\LiteCal\Core\Employees' ) ) {
			\LiteCal\Core\Employees::sync_booking_manager_users( true );
			$employees = \LiteCal\Core\Employees::all_booking_managers( true );
			if ( ! empty( $employees[0]->id ) ) {
				$wpdb->insert(
					$relation_table,
					array(
						'event_id'    => $event_id,
						'employee_id' => (int) $employees[0]->id,
						'sort_order'  => 0,
					)
				);
				$wpdb->update(
					$events_table,
					array(
						'status'     => 'active',
						'updated_at' => $now,
					),
					array( 'id' => $event_id )
				);
			}
		}

		// Default global availability Mon-Fri 9-17
		for ( $i = 1; $i <= 5; $i++ ) {
			$wpdb->insert(
				$availability_table,
				array(
					'scope'       => 'global',
					'scope_id'    => null,
					'day_of_week' => $i,
					'start_time'  => '09:00:00',
					'end_time'    => '17:00:00',
					'created_at'  => $now,
				)
			);
		}

		$schedules = get_option( 'litecal_schedules', array() );
		if ( ! $schedules ) {
			$schedules = array(
				'default' => array(
					'id'       => 'default',
					'name'     => 'Horas laborales',
					'timezone' => \LiteCal\Core\Helpers::site_timezone_name(),
					'days'     => array(
						1 => array(
							array(
								'start' => '09:00',
								'end'   => '17:00',
							),
						),
						2 => array(
							array(
								'start' => '09:00',
								'end'   => '17:00',
							),
						),
						3 => array(
							array(
								'start' => '09:00',
								'end'   => '17:00',
							),
						),
						4 => array(
							array(
								'start' => '09:00',
								'end'   => '17:00',
							),
						),
						5 => array(
							array(
								'start' => '09:00',
								'end'   => '17:00',
							),
						),
					),
				),
			);
			update_option( 'litecal_schedules', $schedules );
			update_option( 'litecal_default_schedule', 'default' );
		}
	}

	private static function ensure_settings_defaults() {
		$settings = get_option( 'litecal_settings', array() );
		if ( ! is_array( $settings ) ) {
			$settings = array();
		}
		$changed = false;
		if ( empty( $settings['currency'] ) || ! in_array( strtoupper( (string) $settings['currency'] ), array( 'USD', 'CLP' ), true ) ) {
			$settings['currency'] = 'USD';
			$changed              = true;
		}
		if ( empty( $settings['time_format'] ) || ! in_array( (string) $settings['time_format'], array( '12h', '24h' ), true ) ) {
			$settings['time_format'] = '12h';
			$changed                 = true;
		}
		if ( $changed ) {
			update_option( 'litecal_settings', $settings );
		}
	}
}
