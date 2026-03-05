<?php
/**
 * Admin area screens and actions for Agenda Lite.
 *
 * @package LiteCal
 */

namespace LiteCal\Admin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// phpcs:disable Squiz.Commenting,WordPress.NamingConventions.ValidVariableName -- Project style preference: keep concise docs and legacy variable names without functional impact.

// phpcs:disable WordPress.PHP.YodaConditions.NotYoda,Universal.Operators.DisallowShortTernary.Found -- Project style preference; no functional impact.
// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Custom Agenda Lite tables via $wpdb->prefix.
// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Reads request vars for UI state and list filters.
// phpcs:disable WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- CSV exports use php://output lifecycle.

use LiteCal\Core\Events;
use LiteCal\Core\Employees;
use LiteCal\Core\Bookings;
use LiteCal\Core\Availability;
use LiteCal\Core\Helpers;
use LiteCal\Rest\Rest;

class Admin {

	private const MAX_GUESTS_PER_BOOKING = 10;
	private static $asset_mode_cache = null;
	private static $customer_abuse_status_runtime = array();

	private static function email_debug_enabled() {
		if ( defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG ) {
			return true;
		}
		$settings = get_option( 'litecal_settings', array() );
		return ! empty( $settings['debug'] );
	}

	private static function email_debug_log( $message, array $context = array() ) {
		if ( ! self::email_debug_enabled() ) {
			return;
		}
		$clean = array();
		foreach ( $context as $key => $value ) {
			if ( is_array( $value ) || is_object( $value ) ) {
				continue;
			}
			$k = sanitize_key( (string) $key );
			if ( $k === '' ) {
				continue;
			}
			$clean[ $k ] = sanitize_text_field( (string) $value );
		}
		$line = '[AgendaLite][Mail] ' . sanitize_text_field( (string) $message );
		if ( $clean ) {
			$line .= ' ' . wp_json_encode( $clean );
		}
			error_log( $line ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Debug logging is opt-in via plugin debug setting / WP_DEBUG_LOG.
	}

	private static function capability() {
		return 'manage_agendalite';
	}

	private static function booking_manager_role() {
		return defined( 'LITECAL_BOOKING_MANAGER_ROLE' )
			? sanitize_key( (string) LITECAL_BOOKING_MANAGER_ROLE )
			: 'litecal_booking_manager';
	}

	private static function is_booking_manager_user() {
		if ( ! is_user_logged_in() ) {
			return false;
		}
		$user = wp_get_current_user();
		if ( ! ( $user instanceof \WP_User ) ) {
			return false;
		}
		$role = self::booking_manager_role();
		return in_array( $role, (array) $user->roles, true );
	}

	private static function can_access_staff_panel() {
		return self::can_manage() || self::is_booking_manager_user();
	}

	private static function current_user_employee_id() {
		static $employee_id = null;
		if ( $employee_id !== null ) {
			return $employee_id;
		}
		$employee_id = 0;
		if ( self::can_manage() || ! self::is_booking_manager_user() ) {
			return $employee_id;
		}
		Employees::sync_booking_manager_users( true );
		$user_id = get_current_user_id();
		if ( $user_id <= 0 ) {
			return $employee_id;
		}
		$employee = Employees::get_by_wp_user_id( $user_id, false );
		if ( $employee && ! empty( $employee->id ) ) {
			$employee_id = (int) $employee->id;
		}
		return $employee_id;
	}

	public static function enforce_staff_access_scope() {
		if ( ! is_admin() || self::can_manage() || ! self::is_booking_manager_user() ) {
			return;
		}
		if ( wp_doing_ajax() ) {
			return;
		}
		$pagenow = isset( $GLOBALS['pagenow'] ) ? strtolower( trim( (string) $GLOBALS['pagenow'] ) ) : '';
		if ( in_array( $pagenow, array( 'admin-ajax.php', 'async-upload.php', 'admin-post.php' ), true ) ) {
			return;
		}
		$allowed_core_pages = array( 'index.php', 'upload.php', 'media-new.php', 'profile.php' );
		if ( in_array( $pagenow, $allowed_core_pages, true ) ) {
			return;
		}
		if ( $pagenow === 'user-edit.php' ) {
			$user_id = absint( wp_unslash( $_GET['user_id'] ?? 0 ) );
			if ( $user_id > 0 && $user_id === get_current_user_id() ) {
				return;
			}
		}
		if ( $pagenow === 'admin.php' ) {
			$page    = sanitize_key( (string) wp_unslash( $_GET['page'] ?? '' ) );
			$allowed = array( 'litecal-dashboard', 'litecal-bookings', 'litecal-clients' );
			if ( in_array( $page, $allowed, true ) ) {
				return;
			}
		}
		wp_safe_redirect( admin_url( 'index.php' ) );
		exit;
	}

	public static function limit_staff_admin_menu() {
		if ( ! is_admin() || self::can_manage() || ! self::is_booking_manager_user() ) {
			return;
		}
		global $menu, $submenu;
		$allowed_top = array(
			'index.php',
			'upload.php',
			'profile.php',
			'litecal-dashboard',
		);
		foreach ( (array) $menu as $index => $item ) {
			$slug = (string) ( $item[2] ?? '' );
			if ( $slug === '' ) {
				continue;
			}
			if ( strpos( $slug, 'separator' ) === 0 ) {
				continue;
			}
			if ( in_array( $slug, $allowed_top, true ) ) {
				continue;
			}
			unset( $menu[ $index ] );
		}
		if ( isset( $submenu['litecal-dashboard'] ) && is_array( $submenu['litecal-dashboard'] ) ) {
			$allowed_sub = array( 'litecal-dashboard', 'litecal-bookings', 'litecal-clients' );
			foreach ( $submenu['litecal-dashboard'] as $index => $item ) {
				$slug = (string) ( $item[2] ?? '' );
				if ( ! in_array( $slug, $allowed_sub, true ) ) {
					unset( $submenu['litecal-dashboard'][ $index ] );
				}
			}
		}
	}

	private static function can_manage() {
		$user          = wp_get_current_user();
		$is_admin_role = ( $user instanceof \WP_User ) && in_array( 'administrator', (array) $user->roles, true );
		if (
			self::is_booking_manager_user()
			&& ! is_super_admin()
			&& ! current_user_can( 'manage_options' )
			&& ! $is_admin_role
		) {
			return false;
		}
		return is_super_admin()
			|| current_user_can( 'manage_options' )
			|| $is_admin_role
			|| current_user_can( self::capability() );
	}

	private static function can_manage_events() {
		return self::can_manage() || current_user_can( 'edit_posts' );
	}

	private static function pro_feature_enabled( $feature ) {
		if ( ! function_exists( 'litecal_pro_feature_enabled' ) ) {
			return false;
		}
		return (bool) litecal_pro_feature_enabled( $feature );
	}

	private static function pro_upgrade_url() {
		if ( function_exists( 'litecal_pro_upgrade_url' ) ) {
			return (string) litecal_pro_upgrade_url();
		}
		return 'https://www.agendalite.com/';
	}

	private static function is_free_plan() {
		if ( ! defined( 'LITECAL_IS_FREE' ) || ! LITECAL_IS_FREE ) {
			return false;
		}
		if ( function_exists( 'litecal_pro_is_active' ) && litecal_pro_is_active() ) {
			return false;
		}
		return true;
	}

	private static function pro_badge_html( $label = '' ) {
		$label = trim( (string) $label );
		if ( $label === '' ) {
			$label = __( 'Pro', 'agenda-lite' );
		}
		return '<span class="lc-pro-badge"><i class="ri-vip-crown-line" aria-hidden="true"></i><span>' . esc_html( $label ) . '</span></span>';
	}

	private static function pro_upgrade_text_html( $text ) {
		$text = trim( (string) $text );
		if ( $text === '' ) {
			$text = __( 'Actualiza a Pro', 'agenda-lite' );
		}
		return '<span class="lc-pro-upgrade-text"><i class="ri-vip-crown-line" aria-hidden="true"></i><em>' . esc_html( $text ) . '</em></span>';
	}

	private static function pro_upgrade_disabled_button_html( $text, $classes = 'button' ) {
		$classes = trim( (string) $classes );
		if ( $classes === '' ) {
			$classes = 'button';
		}
		return '<a class="' . esc_attr( $classes . ' lc-pro-upgrade-btn' ) . '" href="' . esc_url( self::pro_upgrade_url() ) . '" target="_blank" rel="noopener noreferrer">' . self::pro_upgrade_text_html( $text ) . '</a>';
	}

	private static function pro_upgrade_link_html( $text = '' ) {
		$text = trim( (string) $text );
		if ( $text === '' ) {
			$text = __( 'Actualizar a Pro', 'agenda-lite' );
		}
		return '<a class="button lc-pro-upgrade-link" href="' . esc_url( self::pro_upgrade_url() ) . '" target="_blank" rel="noopener noreferrer">' . self::pro_upgrade_text_html( $text ) . '</a>';
	}

	private static function pro_lock_note_html( $message = '' ) {
		return '<div class="lc-pro-lock-note">' . self::pro_upgrade_text_html( __( 'Mejora a Pro para activar esta opción.', 'agenda-lite' ) ) . '<a class="lc-pro-inline-link" href="' . esc_url( self::pro_upgrade_url() ) . '" target="_blank" rel="noopener noreferrer">' . esc_html__( 'Ir a Pro', 'agenda-lite' ) . '</a></div>';
	}

	private static function pro_feature_hint_html( $message = '' ) {
		return '<p class="description lc-pro-feature-hint">' . self::pro_upgrade_text_html( __( 'Mejora a Pro para activar esta opción.', 'agenda-lite' ) ) . ' <a class="lc-pro-inline-link" href="' . esc_url( self::pro_upgrade_url() ) . '" target="_blank" rel="noopener noreferrer">' . esc_html__( 'Ir a Pro', 'agenda-lite' ) . '</a></p>';
	}

	private static function free_limit_reached( $resource ) {
		// Free no longer limits the number of services, professionals, or schedules.
		return false;
	}

	private static function apply_free_self_service_settings( array &$settings ) {
		$settings['manage_reschedule_enabled']      = 0;
		$settings['manage_cancel_free_enabled']     = 0;
		$settings['manage_cancel_paid_enabled']     = 0;
		$settings['manage_reschedule_cutoff_hours'] = 12;
		$settings['manage_cancel_cutoff_hours']     = 24;
		$settings['manage_max_reschedules']         = 0;
		$settings['manage_cooldown_minutes']        = 10;
		$settings['manage_grace_minutes']           = 15;
		$settings['manage_rate_limit_token']        = 20;
		$settings['manage_rate_limit_ip']           = 60;
		$settings['manage_allow_change_staff']      = 0;
		$settings['manage_abuse_limit']             = 3;
		$settings['manage_abuse_max_codes']         = 2;
		$settings['manage_abuse_period_hours']      = 24;
	}

	private static function apply_free_event_self_service_options( array &$options ) {
		$options['manage_use_global']              = 1;
		$options['manage_override']                = 1;
		$options['manage_reschedule_enabled']      = 0;
		$options['manage_cancel_free_enabled']     = 0;
		$options['manage_cancel_paid_enabled']     = 0;
		$options['manage_reschedule_cutoff_hours'] = 12;
		$options['manage_cancel_cutoff_hours']     = 24;
		$options['manage_max_reschedules']         = 0;
		$options['manage_cooldown_minutes']        = 10;
		$options['manage_grace_minutes']           = 15;
		$options['manage_allow_change_staff']      = 0;
	}

	private static function asset_mode() {
		if ( self::$asset_mode_cache !== null ) {
			return self::$asset_mode_cache;
		}
		$settings = get_option( 'litecal_settings', array() );
		$mode     = sanitize_key( (string) ( $settings['asset_mode'] ?? 'cdn_fallback' ) );
		if ( ! in_array( $mode, array( 'cdn_fallback', 'local_only' ), true ) ) {
			$mode = 'cdn_fallback';
		}
		self::$asset_mode_cache = $mode;
		return $mode;
	}

	private static function allow_cdn_fallback() {
		return self::asset_mode() !== 'local_only';
	}

	private static function role_choices() {
		$roles_obj    = function_exists( 'wp_roles' ) ? wp_roles() : null;
		$roles        = ( $roles_obj instanceof \WP_Roles ) ? (array) $roles_obj->roles : array();
		$choices      = array();
		$booking_role = self::booking_manager_role();
		foreach ( $roles as $slug => $def ) {
			if ( sanitize_key( (string) $slug ) === $booking_role ) {
				continue;
			}
			$choices[ sanitize_key( (string) $slug ) ] = translate_user_role( (string) ( $def['name'] ?? $slug ) );
		}
		return $choices;
	}

	private static function allowed_roles_from_settings( $settings ) {
		$choices = self::role_choices();
		$default = function_exists( 'litecal_default_role_caps' ) ? litecal_default_role_caps() : array( 'administrator', 'editor', 'shop_manager' );
		$saved   = isset( $settings['roles_allowed'] ) && is_array( $settings['roles_allowed'] ) ? $settings['roles_allowed'] : $default;
		$allowed = array_values(
			array_filter(
				array_map( 'sanitize_key', (array) $saved ),
				static function ( $slug ) use ( $choices ) {
					return $slug !== '' && isset( $choices[ $slug ] );
				}
			)
		);
		if ( ! in_array( 'administrator', $allowed, true ) ) {
			$allowed[] = 'administrator';
		}
		return array_values( array_unique( $allowed ) );
	}

	private static function enqueue_style_if_available( $handle, $url, $deps = array(), $ver = false, $media = 'all' ) {
		if ( ! $url ) {
			return false;
		}
		wp_enqueue_style( $handle, $url, $deps, $ver, $media );
		return true;
	}

	private static function enqueue_script_if_available( $handle, $url, $deps = array(), $ver = false, $in_footer = false ) {
		if ( ! $url ) {
			return false;
		}
		wp_enqueue_script( $handle, $url, $deps, $ver, $in_footer );
		return true;
	}

	private static function vendor_asset_url( $relative, $cdn_url, &$is_external = null ) {
		$relative   = ltrim( (string) $relative, '/' );
		$local_path = LITECAL_PATH . 'assets/vendor/' . $relative;
		if ( file_exists( $local_path ) ) {
			$is_external = false;
			return LITECAL_URL . 'assets/vendor/' . $relative;
		}
		$is_external = false;
		return '';
	}

	public static function init() {
		add_action( 'admin_menu', array( self::class, 'menu' ) );
		add_action( 'admin_menu', array( self::class, 'limit_staff_admin_menu' ), 9999 );
		add_action( 'admin_init', array( self::class, 'enforce_staff_access_scope' ), 1 );
		add_action( 'admin_head-nav-menus.php', array( self::class, 'register_services_nav_menu_metabox' ) );
		add_action( 'admin_footer-nav-menus.php', array( self::class, 'print_services_nav_menu_helper_script' ) );
		add_action( 'admin_enqueue_scripts', array( self::class, 'assets' ) );
		add_action( 'admin_head', array( self::class, 'hide_internal_submenu_items' ) );
		add_action( 'admin_post_litecal_save_event', array( self::class, 'save_event' ) );
		add_action( 'admin_post_litecal_save_event_order', array( self::class, 'save_event_order' ) );
		add_action( 'admin_post_litecal_save_employee_order', array( self::class, 'save_employee_order' ) );
		add_action( 'admin_post_litecal_save_schedule_order', array( self::class, 'save_schedule_order' ) );
		add_action( 'admin_post_litecal_save_event_settings', array( self::class, 'save_event_settings' ) );
		add_action( 'admin_post_litecal_delete_event', array( self::class, 'delete_event' ) );
		add_action( 'admin_post_litecal_duplicate_event', array( self::class, 'duplicate_event' ) );
		add_action( 'admin_post_litecal_toggle_event_status', array( self::class, 'toggle_event_status' ) );
		add_action( 'admin_post_litecal_save_employee', array( self::class, 'save_employee' ) );
		add_action( 'admin_post_litecal_toggle_employee_status', array( self::class, 'toggle_employee_status' ) );
		add_action( 'admin_post_litecal_delete_employee', array( self::class, 'delete_employee' ) );
		add_action( 'admin_post_litecal_update_booking', array( self::class, 'update_booking' ) );
		add_action( 'admin_post_litecal_update_booking_full', array( self::class, 'update_booking_full' ) );
		add_action( 'admin_post_litecal_bulk_update_bookings', array( self::class, 'bulk_update_bookings' ) );
		add_action( 'admin_post_litecal_bulk_update_clients', array( self::class, 'bulk_update_clients' ) );
		add_action( 'admin_post_litecal_empty_bookings_trash', array( self::class, 'empty_bookings_trash' ) );
		add_action( 'admin_post_litecal_empty_clients_trash', array( self::class, 'empty_clients_trash' ) );
		add_action( 'admin_post_litecal_export_bookings', array( self::class, 'export_bookings' ) );
		add_action( 'admin_post_litecal_export_clients', array( self::class, 'export_clients' ) );
		add_action( 'admin_post_litecal_export_payments', array( self::class, 'export_payments' ) );
		add_action( 'admin_post_litecal_export_analytics', array( self::class, 'export_analytics' ) );
		add_action( 'admin_post_litecal_delete_bookings', array( self::class, 'delete_bookings' ) );
		add_action( 'admin_post_litecal_delete_payments', array( self::class, 'delete_payments' ) );
		add_action( 'admin_post_litecal_save_settings', array( self::class, 'save_settings' ) );
		add_action( 'admin_post_litecal_save_schedule', array( self::class, 'save_schedule' ) );
		add_action( 'admin_post_litecal_new_schedule', array( self::class, 'new_schedule' ) );
		add_action( 'admin_post_litecal_delete_schedule', array( self::class, 'delete_schedule' ) );
		add_action( 'admin_post_litecal_set_default_schedule', array( self::class, 'set_default_schedule' ) );
		add_action( 'admin_post_litecal_save_time_off', array( self::class, 'save_time_off' ) );
		add_action( 'admin_post_litecal_save_appearance', array( self::class, 'save_appearance' ) );
		add_action( 'admin_post_litecal_test_smtp', array( self::class, 'test_smtp' ) );
		add_action( 'admin_post_litecal_toggle_template', array( self::class, 'toggle_template' ) );
		add_action( 'admin_post_litecal_delete_template', array( self::class, 'delete_template' ) );
		add_action( 'wp_ajax_litecal_update_booking_time', array( self::class, 'ajax_update_booking_time' ) );
		add_action( 'wp_ajax_litecal_validate_google_credentials', array( self::class, 'ajax_validate_google_credentials' ) );
		add_action( 'wp_ajax_litecal_calendar_bookings', array( self::class, 'ajax_calendar_bookings' ) );
		add_action( 'wp_ajax_litecal_grid_bookings', array( self::class, 'ajax_grid_bookings' ) );
		add_action( 'wp_ajax_litecal_grid_clients', array( self::class, 'ajax_grid_clients' ) );
		add_action( 'wp_ajax_litecal_customer_history', array( self::class, 'ajax_customer_history' ) );
		add_action( 'wp_ajax_litecal_customer_unlock_abuse', array( self::class, 'ajax_customer_unlock_abuse' ) );
		add_action( 'wp_ajax_litecal_customer_block_abuse', array( self::class, 'ajax_customer_block_abuse' ) );
		add_action( 'wp_ajax_litecal_grid_payments', array( self::class, 'ajax_grid_payments' ) );
		add_action( 'show_user_profile', array( self::class, 'profile_avatar_field' ) );
		add_action( 'edit_user_profile', array( self::class, 'profile_avatar_field' ) );
		add_action( 'show_user_profile', array( self::class, 'profile_title_field' ), 20 );
		add_action( 'edit_user_profile', array( self::class, 'profile_title_field' ), 20 );
		add_action( 'personal_options_update', array( self::class, 'save_profile_avatar_field' ) );
		add_action( 'edit_user_profile_update', array( self::class, 'save_profile_avatar_field' ) );
		add_action( 'admin_head', array( self::class, 'admin_menu_css' ) );
		add_filter( 'wp_mail_from', array( self::class, 'mail_from' ) );
		add_filter( 'wp_mail_from_name', array( self::class, 'mail_from_name' ) );
		add_filter( 'get_avatar_data', array( self::class, 'filter_wp_avatar_data' ), 10, 2 );
		add_filter( 'customize_nav_menu_available_item_types', array( self::class, 'customize_services_nav_menu_item_types' ) );
		add_filter( 'customize_nav_menu_available_items', array( self::class, 'customize_services_nav_menu_items' ), 10, 4 );
		add_action( 'phpmailer_init', array( self::class, 'configure_phpmailer' ), 5 );
		add_action( 'wp_mail_failed', array( self::class, 'mail_failed' ) );
	}

	private static function services_for_nav_menu() {
		$events = Events::all();
		if ( ! is_array( $events ) || empty( $events ) ) {
			return array();
		}

		$order       = get_option( 'litecal_event_order', array() );
		$order_index = array();
		foreach ( (array) $order as $position => $event_id ) {
			$event_id = (int) $event_id;
			if ( $event_id > 0 ) {
				$order_index[ $event_id ] = (int) $position;
			}
		}

		usort(
			$events,
			static function ( $left, $right ) use ( $order_index ) {
				$left_id     = (int) ( $left->id ?? 0 );
				$right_id    = (int) ( $right->id ?? 0 );
				$left_order  = $order_index[ $left_id ] ?? PHP_INT_MAX;
				$right_order = $order_index[ $right_id ] ?? PHP_INT_MAX;
				if ( $left_order !== $right_order ) {
					return $left_order <=> $right_order;
				}
				$left_title  = trim( (string) ( $left->title ?? '' ) );
				$right_title = trim( (string) ( $right->title ?? '' ) );
				return strcasecmp( $left_title, $right_title );
			}
		);

		$services = array();
		foreach ( $events as $event ) {
			$event_id = (int) ( $event->id ?? 0 );
			$slug     = sanitize_title( (string) ( $event->slug ?? '' ) );
			if ( $event_id <= 0 || $slug === '' ) {
				continue;
			}
			$title = trim( (string) ( $event->title ?? '' ) );
			if ( $title === '' ) {
				/* translators: %d: service ID. */
				$title = sprintf( __( 'Servicio #%d', 'agenda-lite' ), $event_id );
			}
			$status = sanitize_key( (string) ( $event->status ?? 'active' ) );
			if ( $status === 'draft' ) {
				$title .= ' (' . __( 'Borrador', 'agenda-lite' ) . ')';
			} elseif ( $status === 'inactive' ) {
				$title .= ' (' . __( 'Inactivo', 'agenda-lite' ) . ')';
			}
			$services[] = array(
				'id'    => $event_id,
				'title' => $title,
				'url'   => home_url( '/' . trim( $slug, '/' ) . '/' ),
			);
		}

		return $services;
	}

	public static function register_services_nav_menu_metabox() {
		if ( ! current_user_can( 'edit_theme_options' ) || ! self::can_manage_events() ) {
			return;
		}
		add_meta_box(
			'add-litecal-services',
			__( 'Servicios', 'agenda-lite' ),
			array( self::class, 'render_services_nav_menu_metabox' ),
			'nav-menus',
			'side',
			'default'
		);
	}

	public static function render_services_nav_menu_metabox() {
		global $nav_menu_selected_id;
		$services = self::services_for_nav_menu();
		?>
		<div id="posttype-litecal-services" class="posttypediv">
			<div id="tabs-panel-posttype-litecal-services-all" class="tabs-panel tabs-panel-active">
				<?php if ( empty( $services ) ) : ?>
					<p><?php esc_html_e( 'No hay servicios creados todavía.', 'agenda-lite' ); ?></p>
				<?php else : ?>
					<ul id="posttype-litecal-services-checklist" class="categorychecklist form-no-clear">
						<?php foreach ( $services as $index => $service ) : ?>
							<?php $menu_item_id = -1 * ( 100000 + (int) $index ); ?>
							<li>
								<label class="menu-item-title">
									<input type="checkbox" class="menu-item-checkbox" name="menu-item[<?php echo esc_attr( (string) $menu_item_id ); ?>][menu-item-object-id]" value="<?php echo esc_attr( (string) $service['id'] ); ?>" />
									<?php echo esc_html( (string) $service['title'] ); ?>
								</label>
								<input type="hidden" name="menu-item[<?php echo esc_attr( (string) $menu_item_id ); ?>][menu-item-type]" value="custom" />
								<input type="hidden" name="menu-item[<?php echo esc_attr( (string) $menu_item_id ); ?>][menu-item-object]" value="custom" />
								<input type="hidden" name="menu-item[<?php echo esc_attr( (string) $menu_item_id ); ?>][menu-item-db-id]" value="0" />
								<input type="hidden" name="menu-item[<?php echo esc_attr( (string) $menu_item_id ); ?>][menu-item-parent-id]" value="0" />
								<input type="hidden" name="menu-item[<?php echo esc_attr( (string) $menu_item_id ); ?>][menu-item-position]" value="0" />
								<input type="hidden" name="menu-item[<?php echo esc_attr( (string) $menu_item_id ); ?>][menu-item-title]" value="<?php echo esc_attr( (string) $service['title'] ); ?>" />
								<input type="hidden" name="menu-item[<?php echo esc_attr( (string) $menu_item_id ); ?>][menu-item-url]" value="<?php echo esc_url( (string) $service['url'] ); ?>" />
								<input type="hidden" name="menu-item[<?php echo esc_attr( (string) $menu_item_id ); ?>][menu-item-classes]" value="" />
								<input type="hidden" name="menu-item[<?php echo esc_attr( (string) $menu_item_id ); ?>][menu-item-xfn]" value="" />
							</li>
						<?php endforeach; ?>
					</ul>
				<?php endif; ?>
			</div>
			<p class="button-controls wp-clearfix">
				<span class="list-controls hide-if-no-js">
					<input type="checkbox" id="posttype-litecal-services-tab-select-all" class="select-all" />
					<label for="posttype-litecal-services-tab-select-all"><?php esc_html_e( 'Seleccionar todo', 'agenda-lite' ); ?></label>
				</span>
				<span class="add-to-menu">
					<input type="submit" <?php wp_nav_menu_disabled_check( (int) $nav_menu_selected_id ); ?> class="button submit-add-to-menu right" value="<?php esc_attr_e( 'Añadir al menú', 'agenda-lite' ); ?>" name="add-post-type-menu-item" id="submit-posttype-litecal-services" />
					<span class="spinner"></span>
				</span>
			</p>
		</div>
		<?php
	}

	public static function print_services_nav_menu_helper_script() {
		if ( ! current_user_can( 'edit_theme_options' ) ) {
			return;
		}
		?>
		<script>
		(function () {
			var box = document.getElementById('posttype-litecal-services');
			if (!box) return;
			var selectAll = box.querySelector('#posttype-litecal-services-tab-select-all');
			var submit = box.querySelector('#submit-posttype-litecal-services');
			var items = Array.prototype.slice.call(box.querySelectorAll('.menu-item-checkbox'));
			if (!items.length) return;

			var sync = function () {
			var checkedCount = 0;
			for (var i = 0; i < items.length; i += 1) {
				if (items[i].checked) checkedCount += 1;
			}
			if (submit) {
				submit.disabled = checkedCount <= 0;
			}
			if (selectAll) {
				selectAll.checked = checkedCount > 0 && checkedCount === items.length;
			}
			};

			if (selectAll) {
			selectAll.addEventListener('change', function () {
				var nextState = !!selectAll.checked;
				for (var i = 0; i < items.length; i += 1) {
				items[i].checked = nextState;
				}
				sync();
			});
			}

			for (var i = 0; i < items.length; i += 1) {
			items[i].addEventListener('change', sync);
			}
			sync();
		})();
		</script>
		<?php
	}

	public static function customize_services_nav_menu_item_types( $item_types ) {
		if ( ! current_user_can( 'edit_theme_options' ) || ! self::can_manage_events() ) {
			return $item_types;
		}

		$item_types[] = array(
			'title'  => __( 'Servicios', 'agenda-lite' ),
			'type'   => 'custom',
			'object' => 'litecal_service',
		);

		return $item_types;
	}

	public static function customize_services_nav_menu_items( $items, $type, $object, $page ) {
		if ( $type !== 'custom' || $object !== 'litecal_service' ) {
			return $items;
		}
		if ( ! current_user_can( 'edit_theme_options' ) || ! self::can_manage_events() ) {
			return $items;
		}

		$services = self::services_for_nav_menu();
		if ( empty( $services ) ) {
			return $items;
		}

		$page     = max( 1, (int) $page );
		$per_page = 20;
		$offset   = ( $page - 1 ) * $per_page;
		$slice    = array_slice( $services, $offset, $per_page );
		$items    = array();

		foreach ( $slice as $service ) {
			$service_id = (int) ( $service['id'] ?? 0 );
			if ( $service_id <= 0 ) {
				continue;
			}
			$items[] = array(
				'id'          => 'litecal-service-' . $service_id,
				'title'       => (string) ( $service['title'] ?? '' ),
				'type'        => 'custom',
				'type_label'  => __( 'Servicio', 'agenda-lite' ),
				'object'      => 'custom',
				'object_id'   => 0,
				'url'         => esc_url_raw( (string) ( $service['url'] ?? '' ) ),
				'target'      => '',
				'attr_title'  => '',
				'description' => '',
				'classes'     => array(),
				'xfn'         => '',
			);
		}

		return $items;
	}

	public static function configure_phpmailer( $phpmailer ) {
		$settings = get_option( 'litecal_settings', array() );
		$host     = trim( (string) ( $settings['smtp_host'] ?? '' ) );
		if ( $host === '' ) {
			return;
		}
		$port = (int) ( $settings['smtp_port'] ?? 0 );
		$user = trim( (string) ( $settings['smtp_user'] ?? '' ) );
		$pass = (string) ( $settings['smtp_pass'] ?? '' );
		$enc  = sanitize_key( (string) ( $settings['smtp_encryption'] ?? 'none' ) );

		$phpmailer->isSMTP();
		$phpmailer->Host     = $host;
		$phpmailer->SMTPAuth = ( $user !== '' );
		$phpmailer->Username = $user;
		$phpmailer->Password = $pass;
		if ( $port > 0 ) {
			$phpmailer->Port = $port;
		}
		if ( in_array( $enc, array( 'ssl', 'tls' ), true ) ) {
			$phpmailer->SMTPSecure = $enc;
		} else {
			$phpmailer->SMTPSecure  = '';
			$phpmailer->SMTPAutoTLS = true;
		}
	}

	public static function mail_failed( $wp_error ) {
		if ( ! ( $wp_error instanceof \WP_Error ) ) {
			return;
		}
		$data = $wp_error->get_error_data();
		self::email_debug_log(
			'wp_mail_failed',
			array(
				'code'    => (string) $wp_error->get_error_code(),
				'message' => (string) $wp_error->get_error_message(),
				'to'      => is_array( $data['to'] ?? null ) ? implode( ',', array_map( 'sanitize_email', (array) $data['to'] ) ) : sanitize_email( (string) ( $data['to'] ?? '' ) ),
				'subject' => sanitize_text_field( (string) ( $data['subject'] ?? '' ) ),
			)
		);
	}

	public static function profile_avatar_field( $user ) {
		if ( ! ( $user instanceof \WP_User ) || ! current_user_can( 'edit_user', $user->ID ) ) {
			return;
		}
		$custom_avatar     = esc_url( (string) get_user_meta( $user->ID, 'litecal_profile_avatar_url', true ) );
		$gravatar_fallback = esc_url(
			(string) get_avatar_url(
				(string) $user->user_email,
				array(
					'size'                         => 256,
					'litecal_ignore_custom_avatar' => 1,
				)
			)
		);
		?>
		<input type="hidden" id="litecal_profile_avatar_url" name="litecal_profile_avatar_url" value="<?php echo esc_attr( $custom_avatar ); ?>" />
		<script>
		(function(){
			var input = document.getElementById('litecal_profile_avatar_url');
			var row = document.querySelector('tr.user-profile-picture');
			var cell = row ? row.querySelector('td') : null;
			var corePreview = cell ? cell.querySelector('img.avatar') : null;
			if (!input || !cell || !corePreview || document.getElementById('litecal-profile-avatar-tools')) return;

			var avatarImages = Array.prototype.slice.call(document.querySelectorAll('img.avatar'));
			var avatarOriginals = avatarImages.map(function(img){
			return {
				img: img,
				src: img.getAttribute('src') || '',
				srcset: img.getAttribute('srcset') || '',
				sizes: img.getAttribute('sizes') || ''
			};
			});

			var applyAvatar = function(url){
			avatarOriginals.forEach(function(item){
				if (!item || !item.img) return;
				if (url) {
				item.img.setAttribute('src', url);
				item.img.removeAttribute('srcset');
				item.img.removeAttribute('sizes');
				} else {
				if (item.src) {
					item.img.setAttribute('src', item.src);
				}
				if (item.srcset) {
					item.img.setAttribute('srcset', item.srcset);
				} else {
					item.img.removeAttribute('srcset');
				}
				if (item.sizes) {
					item.img.setAttribute('sizes', item.sizes);
				} else {
					item.img.removeAttribute('sizes');
				}
				}
			});
			};

			if (input.value) applyAvatar(input.value);
			var gravatarFallback = <?php echo wp_json_encode( $gravatar_fallback ); ?>;

			var tools = document.createElement('p');
			tools.className = 'description';
			tools.id = 'litecal-profile-avatar-tools';

			var pickLink = document.createElement('a');
			pickLink.href = '#';
			pickLink.id = 'litecal-profile-avatar-pick';
			pickLink.textContent = 'Elegir imagen desde la biblioteca';

			var separator = document.createTextNode(' · ');

			var removeLink = document.createElement('a');
			removeLink.href = '#';
			removeLink.id = 'litecal-profile-avatar-remove';
			removeLink.textContent = 'Quitar imagen personalizada';
			removeLink.style.display = input.value ? '' : 'none';

			tools.appendChild(pickLink);
			tools.appendChild(separator);
			tools.appendChild(removeLink);

			var gravatarDescription = cell.querySelector('p.description');
			if (gravatarDescription) {
			cell.insertBefore(tools, gravatarDescription);
			} else {
			cell.appendChild(tools);
			}

			var openPicker = function(e){
			e.preventDefault();
			if (typeof wp === 'undefined' || !wp.media) {
				window.alert('No se pudo abrir la biblioteca de medios.');
				return;
			}
			if (!openPicker.frame) {
				openPicker.frame = wp.media({ title: 'Seleccionar foto', button: { text: 'Usar imagen' }, multiple: false });
				openPicker.frame.on('select', function(){
				var attachment = openPicker.frame.state().get('selection').first().toJSON();
				if (!attachment || !attachment.url) return;
				input.value = attachment.url;
				applyAvatar(attachment.url);
				removeLink.style.display = '';
				});
			}
			openPicker.frame.open();
			};
			pickLink.addEventListener('click', openPicker);
			removeLink.addEventListener('click', function(e){
			e.preventDefault();
			input.value = '';
			applyAvatar(gravatarFallback || '');
			removeLink.style.display = 'none';
			});
		})();
		</script>
		<?php
	}

	public static function filter_wp_avatar_data( $args, $id_or_email ) {
		if ( ! empty( $args['litecal_ignore_custom_avatar'] ) ) {
			return $args;
		}
		$user_id = self::avatar_user_id_from_mixed( $id_or_email );
		if ( $user_id <= 0 ) {
			return $args;
		}
		$custom_avatar = esc_url_raw( (string) get_user_meta( $user_id, 'litecal_profile_avatar_url', true ) );
		if ( $custom_avatar === '' ) {
			return $args;
		}
		$args['url']          = $custom_avatar;
		$args['found_avatar'] = true;
		return $args;
	}

	private static function avatar_user_id_from_mixed( $id_or_email ) {
		if ( $id_or_email instanceof \WP_User ) {
			return (int) $id_or_email->ID;
		}
		if ( $id_or_email instanceof \WP_Post ) {
			return (int) $id_or_email->post_author;
		}
		if ( $id_or_email instanceof \WP_Comment ) {
			if ( ! empty( $id_or_email->user_id ) ) {
				return (int) $id_or_email->user_id;
			}
			$by_email = get_user_by( 'email', (string) $id_or_email->comment_author_email );
			return $by_email instanceof \WP_User ? (int) $by_email->ID : 0;
		}
		if ( is_numeric( $id_or_email ) ) {
			return (int) $id_or_email;
		}
		if ( is_string( $id_or_email ) && is_email( $id_or_email ) ) {
			$by_email = get_user_by( 'email', $id_or_email );
			return $by_email instanceof \WP_User ? (int) $by_email->ID : 0;
		}
		return 0;
	}

	public static function save_profile_avatar_field( $user_id ) {
		if ( ! current_user_can( 'edit_user', $user_id ) ) {
			return;
		}
		$nonce = sanitize_text_field( (string) wp_unslash( $_POST['_wpnonce'] ?? '' ) );
		if ( $nonce === '' || ! wp_verify_nonce( $nonce, 'update-user_' . (int) $user_id ) ) {
			return;
		}
		$profile_title = sanitize_text_field( (string) wp_unslash( $_POST['litecal_profile_title'] ?? '' ) );
		if ( $profile_title !== '' ) {
			update_user_meta( $user_id, 'litecal_profile_title', $profile_title );
		} else {
			delete_user_meta( $user_id, 'litecal_profile_title' );
		}
		$avatar_url = esc_url_raw( (string) wp_unslash( $_POST['litecal_profile_avatar_url'] ?? '' ) );
		if ( $avatar_url !== '' ) {
			update_user_meta( $user_id, 'litecal_profile_avatar_url', $avatar_url );
		} else {
			delete_user_meta( $user_id, 'litecal_profile_avatar_url' );
		}
		Employees::sync_booking_manager_users( true );
	}

	public static function profile_title_field( $user ) {
		if ( ! ( $user instanceof \WP_User ) || ! current_user_can( 'edit_user', $user->ID ) ) {
			return;
		}
		$title = sanitize_text_field( (string) get_user_meta( $user->ID, 'litecal_profile_title', true ) );
		if ( $title === '' ) {
			$employee = Employees::get_by_wp_user_id( (int) $user->ID, false );
			if ( $employee && ! empty( $employee->title ) ) {
				$title = sanitize_text_field( (string) $employee->title );
			}
		}
		?>
		<table class="form-table" id="litecal-profile-title-table">
			<tbody>
				<tr class="user-litecal-title-wrap">
					<th><label for="litecal_profile_title"><?php esc_html_e( 'Cargo o slogan (Agenda Lite)', 'agenda-lite' ); ?></label></th>
					<td>
						<input type="text" name="litecal_profile_title" id="litecal_profile_title" class="regular-text" value="<?php echo esc_attr( $title ); ?>" />
						<p class="description"><?php esc_html_e( 'Este texto se mostrará en Agenda Lite para este profesional.', 'agenda-lite' ); ?></p>
					</td>
				</tr>
			</tbody>
		</table>
		<script>
		(function(){
			var table = document.getElementById('litecal-profile-title-table');
			var row = table ? table.querySelector('tr.user-litecal-title-wrap') : null;
			var displayNameRow = document.querySelector('tr.user-display-name-wrap');
			if (!row || !displayNameRow || !displayNameRow.parentNode) return;
			displayNameRow.parentNode.insertBefore(row, displayNameRow.nextSibling);
			if (table && table.parentNode) {
			table.parentNode.removeChild(table);
			}
		})();
		</script>
		<?php
	}

	public static function menu() {
		if ( function_exists( 'litecal_sync_caps' ) ) {
			litecal_sync_caps();
		}
		$cap           = current_user_can( 'manage_options' ) ? 'manage_options' : self::capability();
		$staff_limited = ! self::can_manage() && self::is_booking_manager_user();
		if ( isset( $_GET['page'] ) && sanitize_key( (string) $_GET['page'] ) === 'litecal-bookings' && self::can_manage() ) {
			self::mark_paid_notifications_seen();
		}
		if ( $staff_limited ) {
			add_menu_page( __( 'Agenda Lite', 'agenda-lite' ), __( 'Agenda Lite', 'agenda-lite' ), 'read', 'litecal-dashboard', array( self::class, 'dashboard' ), LITECAL_URL . 'assets/admin/ico.svg', 3 );
			add_submenu_page( 'litecal-dashboard', __( 'Mi Calendario', 'agenda-lite' ), __( 'Mi Calendario', 'agenda-lite' ), 'read', 'litecal-dashboard', array( self::class, 'dashboard' ) );
			add_submenu_page( 'litecal-dashboard', __( 'Mis Reservas', 'agenda-lite' ), __( 'Mis Reservas', 'agenda-lite' ), 'read', 'litecal-bookings', array( self::class, 'bookings' ) );
			add_submenu_page( 'litecal-dashboard', __( 'Mis Clientes', 'agenda-lite' ), __( 'Mis Clientes', 'agenda-lite' ), 'read', 'litecal-clients', array( self::class, 'clients' ) );
			return;
		}
		$unread_paid    = self::unread_paid_notifications_count();
		$bookings_label = __( 'Reservas', 'agenda-lite' );
		if ( $unread_paid > 0 ) {
			$count           = (string) min( 99, $unread_paid );
			$bookings_label .= ' <span class="awaiting-mod count-' . esc_attr( $count ) . '"><span class="pending-count">' . esc_html( $count ) . '</span></span>';
		}
		add_menu_page( __( 'Agenda Lite', 'agenda-lite' ), __( 'Agenda Lite', 'agenda-lite' ), $cap, 'litecal-dashboard', array( self::class, 'dashboard' ), LITECAL_URL . 'assets/admin/ico.svg', 3 );
		add_submenu_page( 'litecal-dashboard', __( 'Calendario', 'agenda-lite' ), __( 'Calendario', 'agenda-lite' ), $cap, 'litecal-dashboard', array( self::class, 'dashboard' ) );
		add_submenu_page( 'litecal-dashboard', __( 'Servicios', 'agenda-lite' ), __( 'Servicios', 'agenda-lite' ), $cap, 'litecal-events', array( self::class, 'events' ) );
		add_submenu_page( 'litecal-dashboard', __( 'Editar servicio', 'agenda-lite' ), __( 'Editar servicio', 'agenda-lite' ), $cap, 'litecal-event', array( self::class, 'event_detail' ) );
		add_submenu_page( 'litecal-dashboard', __( 'Profesionales', 'agenda-lite' ), __( 'Profesionales', 'agenda-lite' ), $cap, 'litecal-employees', array( self::class, 'employees' ) );
		add_submenu_page( 'litecal-dashboard', __( 'Disponibilidad', 'agenda-lite' ), __( 'Disponibilidad', 'agenda-lite' ), $cap, 'litecal-availability', array( self::class, 'availability' ) );
		add_submenu_page( 'litecal-dashboard', __( 'Reservas', 'agenda-lite' ), $bookings_label, $cap, 'litecal-bookings', array( self::class, 'bookings' ) );
		add_submenu_page( 'litecal-dashboard', __( 'Clientes', 'agenda-lite' ), __( 'Clientes', 'agenda-lite' ), $cap, 'litecal-clients', array( self::class, 'clients' ) );
		add_submenu_page( 'litecal-dashboard', __( 'Pagos', 'agenda-lite' ), __( 'Pagos', 'agenda-lite' ), $cap, 'litecal-payments', array( self::class, 'payments' ) );
		remove_submenu_page( 'litecal-dashboard', 'litecal-payments' );
		add_submenu_page( 'litecal-dashboard', __( 'Analítica', 'agenda-lite' ), __( 'Analítica', 'agenda-lite' ), $cap, 'litecal-analytics', array( self::class, 'analytics' ) );
		add_submenu_page( 'litecal-dashboard', __( 'Integraciones', 'agenda-lite' ), __( 'Integraciones', 'agenda-lite' ), $cap, 'litecal-integrations', array( self::class, 'integrations' ) );
		add_submenu_page( 'litecal-dashboard', __( 'Ajustes', 'agenda-lite' ), __( 'Ajustes', 'agenda-lite' ), $cap, 'litecal-settings', array( self::class, 'settings' ) );
		$pro_active = function_exists( 'litecal_pro_is_active' ) && litecal_pro_is_active();
		$menu_title = $pro_active
			? '<span class="lc-pro-menu-label is-active"><i class="ri-vip-crown-line" aria-hidden="true"></i><span>' . esc_html__( 'Pro activado', 'agenda-lite' ) . '</span></span>'
			: '<span class="lc-pro-menu-label"><i class="ri-vip-crown-line" aria-hidden="true"></i><span>' . esc_html__( 'Upgrade to Pro', 'agenda-lite' ) . '</span></span>';
		add_submenu_page(
			'litecal-dashboard',
			$pro_active ? __( 'Pro activado', 'agenda-lite' ) : __( 'Upgrade to Pro', 'agenda-lite' ),
			$menu_title,
			$cap,
			'litecal-upgrade',
			array( self::class, 'upgrade' )
		);
	}

	public static function hide_internal_submenu_items() {
		if ( ! is_admin() ) {
			return;
		}
		echo '<style>#toplevel_page_litecal-dashboard .wp-submenu a[href="admin.php?page=litecal-event"]{display:none !important;}</style>';
	}

	public static function assets( $hook ) {
		$hook = (string) $hook;
		$menu_remixicon_url = self::vendor_asset_url( 'remixicon/remixicon.css', '' );
		if ( self::enqueue_style_if_available( 'litecal-remixicon-menu', $menu_remixicon_url, array(), '4.9.1' ) ) {
			wp_add_inline_style(
				'litecal-remixicon-menu',
				'.lc-pro-menu-label{display:inline-flex;align-items:center;gap:6px}.lc-pro-menu-label i{font-size:14px;line-height:1;min-width:14px}.lc-pro-menu-label.is-active i{color:#c07a00}#adminmenu .wp-submenu .lc-pro-menu-label{display:inline-flex !important;align-items:center;gap:6px}#adminmenu .wp-submenu .lc-pro-menu-label i{font-size:14px;line-height:1;min-width:14px;display:inline-block;vertical-align:middle}#adminmenu .wp-submenu .lc-pro-menu-label span{display:inline-block;vertical-align:middle}'
			);
		}
		if ( in_array( (string) $hook, array( 'profile.php', 'user-edit.php' ), true ) ) {
			wp_enqueue_media();
			return;
		}
		if ( empty( $hook ) || strpos( $hook, 'litecal' ) === false ) {
			return;
		}
		$admin_css_ver = file_exists( LITECAL_PATH . 'assets/admin/admin.css' ) ? (string) filemtime( LITECAL_PATH . 'assets/admin/admin.css' ) : LITECAL_VERSION;
		$admin_js_ver  = file_exists( LITECAL_PATH . 'assets/admin/admin.js' ) ? (string) filemtime( LITECAL_PATH . 'assets/admin/admin.js' ) : LITECAL_VERSION;

		wp_enqueue_style( 'litecal-admin', LITECAL_URL . 'assets/admin/admin.css', array(), $admin_css_ver );
		$remixicon_url = self::vendor_asset_url( 'remixicon/remixicon.css', '' );
		$notyf_css_url = self::vendor_asset_url( 'notyf/notyf.min.css', '' );
		self::enqueue_style_if_available( 'litecal-remixicon', $remixicon_url, array(), '4.9.1' );
		self::enqueue_style_if_available( 'litecal-notyf', $notyf_css_url, array(), '3.10.0' );
		wp_enqueue_script( 'jquery' );
		$notyf_js_url = self::vendor_asset_url( 'notyf/notyf.min.js', '' );
		$admin_deps   = array( 'wp-api', 'jquery' );
		if ( self::enqueue_script_if_available( 'litecal-notyf', $notyf_js_url, array(), '3.10.0', true ) ) {
			$admin_deps[] = 'litecal-notyf';
		}
		$is_table_screen     = strpos( $hook, 'litecal-bookings' ) !== false || strpos( $hook, 'litecal-payments' ) !== false || strpos( $hook, 'litecal-clients' ) !== false;
		$is_calendar_screen  = strpos( $hook, 'litecal-dashboard' ) !== false;
		$is_analytics_screen = strpos( $hook, 'litecal-analytics' ) !== false;
		$is_event_screen     = strpos( $hook, 'litecal-event' ) !== false;
		$needs_filepond      = strpos( $hook, 'litecal-employees' ) !== false || strpos( $hook, 'litecal-settings' ) !== false;
		$needs_color_picker  = strpos( $hook, 'litecal-settings' ) !== false || strpos( $hook, 'litecal-appearance' ) !== false;
		$needs_media_picker  = $needs_filepond || $is_event_screen;
		if ( $needs_color_picker ) {
			$pickr_css = self::vendor_asset_url( 'pickr/nano.min.css', '' );
			$pickr_js  = self::vendor_asset_url( 'pickr/pickr.min.js', '' );
			self::enqueue_style_if_available( 'litecal-pickr', $pickr_css, array(), '1.9.0' );
			if ( self::enqueue_script_if_available( 'litecal-pickr', $pickr_js, array(), '1.9.0', true ) ) {
				$admin_deps[] = 'litecal-pickr';
				$color_picker_js = LITECAL_URL . 'assets/admin/color-picker.js';
				$color_picker_ver = file_exists( LITECAL_PATH . 'assets/admin/color-picker.js' ) ? (string) filemtime( LITECAL_PATH . 'assets/admin/color-picker.js' ) : LITECAL_VERSION;
				wp_enqueue_script( 'litecal-admin-color-picker', $color_picker_js, array( 'litecal-pickr' ), $color_picker_ver, true );
			}
		}
		if ( $is_table_screen ) {
			$grid_css = self::vendor_asset_url( 'gridjs/mermaid.min.css', '' );
			$grid_js  = self::vendor_asset_url( 'gridjs/gridjs.umd.js', '' );
			self::enqueue_style_if_available( 'litecal-gridjs', $grid_css, array(), '6.0.6' );
			if ( self::enqueue_script_if_available( 'litecal-gridjs', $grid_js, array(), '6.0.6', true ) ) {
				$admin_deps[] = 'litecal-gridjs';
			}
		}
		if ( $is_analytics_screen ) {
			$chart_js = self::vendor_asset_url( 'chartjs/chart.umd.min.js', '' );
			if ( self::enqueue_script_if_available( 'litecal-chartjs', $chart_js, array(), '4.4.6', true ) ) {
				$admin_deps[] = 'litecal-chartjs';
			}
		}
		if ( $is_calendar_screen ) {
			$iti_css_url = self::vendor_asset_url( 'intl-tel-input/intlTelInput.css', '' );
			$iti_js_url  = self::vendor_asset_url( 'intl-tel-input/intlTelInput.min.js', '' );
			self::enqueue_style_if_available( 'litecal-intl-tel-input', $iti_css_url, array(), '17.0.12' );
			if ( self::enqueue_script_if_available( 'litecal-intl-tel-input', $iti_js_url, array(), '17.0.12', true ) ) {
				$admin_deps[] = 'litecal-intl-tel-input';
			}
		}
		if ( $needs_filepond ) {
			$pond_css         = self::vendor_asset_url( 'filepond/filepond.min.css', '' );
			$pond_js          = self::vendor_asset_url( 'filepond/filepond.min.js', '' );
			$pond_preview_css = self::vendor_asset_url( 'filepond/filepond-plugin-image-preview.min.css', '' );
			$pond_preview_js  = self::vendor_asset_url( 'filepond/filepond-plugin-image-preview.min.js', '' );
			$filepond_loaded  = self::enqueue_style_if_available( 'litecal-filepond', $pond_css, array(), '4.31.4' );
			self::enqueue_style_if_available( 'litecal-filepond-image-preview', $pond_preview_css, $filepond_loaded ? array( 'litecal-filepond' ) : array(), '4.6.12' );
			$filepond_script_loaded = self::enqueue_script_if_available( 'litecal-filepond', $pond_js, array(), '4.31.4', true );
			if ( self::enqueue_script_if_available( 'litecal-filepond-image-preview', $pond_preview_js, $filepond_script_loaded ? array( 'litecal-filepond' ) : array(), '4.6.12', true ) ) {
				$admin_deps[] = 'litecal-filepond-image-preview';
			} elseif ( $filepond_script_loaded ) {
				$admin_deps[] = 'litecal-filepond';
			}
		}
		wp_enqueue_script( 'litecal-admin', LITECAL_URL . 'assets/admin/admin.js', $admin_deps, $admin_js_ver, true );
		if ( $needs_media_picker ) {
			wp_enqueue_media();
		}

		if ( strpos( $hook, 'litecal-dashboard' ) !== false ) {
			wp_localize_script(
				'litecal-admin',
				'litecalAdmin',
				array(
					'bookings'      => array(),
					'updateUrl'     => admin_url( 'admin-post.php' ),
					'nonce'         => wp_create_nonce( 'litecal_update_booking' ),
					'ajaxUrl'       => admin_url( 'admin-ajax.php' ),
					'calendarNonce' => wp_create_nonce( 'litecal_calendar_bookings' ),
					'timeFormat'    => self::time_format(),
				)
			);
		}

		if ( strpos( $hook, 'litecal-event' ) !== false ) {
			$schedules = get_option( 'litecal_schedules', array() );
			$summaries = array();
			$previews  = array();
			foreach ( $schedules as $sid => $schedule ) {
				$summaries[ $sid ] = self::schedule_summary( $schedule );
				$previews[ $sid ]  = self::schedule_preview_list( $schedule );
			}
			wp_localize_script( 'litecal-admin', 'litecalSchedules', $summaries );
			wp_localize_script( 'litecal-admin', 'litecalSchedulePreviews', $previews );
		}
		if ( $is_analytics_screen ) {
			wp_localize_script(
				'litecal-admin',
				'litecalAnalytics',
				array(
					'restBase' => esc_url_raw( rest_url( 'litecal/v1/analytics' ) ),
					'nonce'    => wp_create_nonce( 'wp_rest' ),
					'labels'   => array(
						'view'    => __( 'Ver reserva', 'agenda-lite' ),
						'loading' => __( 'Cargando...', 'agenda-lite' ),
						'empty'   => __( 'Sin resultados', 'agenda-lite' ),
						'error'   => __( 'Error al cargar', 'agenda-lite' ),
					),
				)
			);
		}
	}

	private static function shell_start( $active ) {
		$appearance  = get_option( 'litecal_appearance', array() );
		$accent      = $appearance['accent'] ?? '#083a53';
		$accent_text = $appearance['accent_text'] ?? '#ffffff';
		echo '<div class="wrap litecal-wrap">';
		echo '<style>:root{--lc-accent:' . esc_attr( $accent ) . ';--lc-accent-text:' . esc_attr( $accent_text ) . ';}</style>';
		self::render_notice();
	}

	private static function status_label( $status, $type ) {
		$status = $status ?: '';
		if ( $type === 'payment' ) {
			$map = array(
				'paid'      => __( 'Aprobado', 'agenda-lite' ),
				'pending'   => __( 'Pendiente', 'agenda-lite' ),
				'expired'   => __( 'Cancelado', 'agenda-lite' ),
				'cancelled' => __( 'Cancelado', 'agenda-lite' ),
				'canceled'  => __( 'Cancelado', 'agenda-lite' ),
				'failed'    => __( 'Rechazado', 'agenda-lite' ),
				'rejected'  => __( 'Rechazado', 'agenda-lite' ),
				'unpaid'    => __( 'Pendiente', 'agenda-lite' ),
			);
			return $map[ $status ] ?? __( 'Pendiente', 'agenda-lite' );
		}
		$map = array(
			'pending'     => __( 'Pendiente', 'agenda-lite' ),
			'confirmed'   => __( 'Confirmado', 'agenda-lite' ),
			'cancelled'   => __( 'Cancelado', 'agenda-lite' ),
			'canceled'    => __( 'Cancelado', 'agenda-lite' ),
			'deleted'     => __( 'Eliminada', 'agenda-lite' ),
			'expired'     => __( 'Cancelada', 'agenda-lite' ),
			'rescheduled' => __( 'Reagendado', 'agenda-lite' ),
		);
		return $map[ $status ] ?? $status;
	}

	private static function status_class( $status, $type ) {
		$status = $status ?: '';
		if ( $type === 'payment' ) {
			$map = array(
				'paid'      => 'is-success',
				'pending'   => 'is-warning',
				'expired'   => 'is-danger',
				'cancelled' => 'is-danger',
				'canceled'  => 'is-danger',
				'failed'    => 'is-danger',
				'rejected'  => 'is-danger',
				'unpaid'    => 'is-warning',
			);
			return $map[ $status ] ?? 'is-muted';
		}
		$map = array(
			'pending'     => 'is-warning',
			'confirmed'   => 'is-success',
			'cancelled'   => 'is-danger',
			'canceled'    => 'is-danger',
			'expired'     => 'is-danger',
			'rescheduled' => 'is-muted',
		);
		return $map[ $status ] ?? 'is-muted';
	}

	private static function booking_status_dot_class( $status ) {
		$status = sanitize_key( (string) $status );
		$map    = array(
			'pending'     => 'is-pending',
			'confirmed'   => 'is-confirmed',
			'cancelled'   => 'is-cancelled',
			'canceled'    => 'is-cancelled',
			'expired'     => 'is-cancelled',
			'rescheduled' => 'is-rescheduled',
			'deleted'     => 'is-deleted',
		);
		return $map[ $status ] ?? 'is-pending';
	}

	private static function truncate_text( $value, $max = 15 ) {
		$value = (string) $value;
		if ( $value === '' || $max < 1 ) {
			return $value;
		}
		if ( function_exists( 'mb_strlen' ) && function_exists( 'mb_substr' ) ) {
			return mb_strlen( $value, 'UTF-8' ) > $max ? mb_substr( $value, 0, $max, 'UTF-8' ) . '...' : $value;
		}
		return strlen( $value ) > $max ? substr( $value, 0, $max ) . '...' : $value;
	}

	private static function booking_has_payment_context( $booking ) {
		return ! empty( $booking->payment_provider )
			|| ! empty( $booking->payment_reference )
			|| ( (float) ( $booking->payment_amount ?? 0 ) > 0 );
	}

	private static function csv_safe( $value ) {
		$value   = (string) $value;
		$trimmed = ltrim( $value );
		if ( $trimmed !== '' && preg_match( '/^[=\+\-@]/', $trimmed ) ) {
			return "'" . $value;
		}
		return $value;
	}

	private static function payment_provider_meta( $provider ) {
		$provider = strtolower( trim( (string) $provider ) );
		$map      = array(
			'flow'        => array(
				'label' => 'Flow',
				'logo'  => LITECAL_URL . 'assets/logos/flow.svg',
			),
			'mp'          => array(
				'label' => 'MercadoPago',
				'logo'  => LITECAL_URL . 'assets/logos/mercadopago.svg',
			),
			'mercadopago' => array(
				'label' => 'MercadoPago',
				'logo'  => LITECAL_URL . 'assets/logos/mercadopago.svg',
			),
			'webpay'      => array(
				'label' => 'Webpay Plus',
				'logo'  => LITECAL_URL . 'assets/logos/webpayplus.svg',
			),
			'paypal'      => array(
				'label' => 'PayPal',
				'logo'  => LITECAL_URL . 'assets/logos/paypal.svg',
			),
			'stripe'      => array(
				'label' => 'Stripe',
				'logo'  => LITECAL_URL . 'assets/logos/stripe.svg',
			),
			'transfer'    => array(
				'label' => 'Transferencia bancaria',
				'logo'  => LITECAL_URL . 'assets/logos/banco.svg',
			),
			'onsite'      => array(
				'label' => __( 'Pago presencial', 'agenda-lite' ),
				'logo'  => '',
			),
		);
		return $map[ $provider ] ?? array(
			'label' => ucfirst( $provider ),
			'logo'  => '',
		);
	}

	private static function paypal_webhook_url() {
		return home_url( '/wp-json/litecal/v1/payments/webhook?provider=paypal' );
	}

	private static function stripe_webhook_url() {
		return home_url( '/wp-json/litecal/v1/payments/stripe/webhook' );
	}

	private static function extract_meta_pixel_id( $value ) {
		$raw = trim( (string) $value );
		if ( $raw === '' ) {
			return '';
		}
		if ( (bool) preg_match( '/^[0-9]{6,20}$/', $raw ) ) {
			return $raw;
		}
		$decoded  = html_entity_decode( $raw, ENT_QUOTES, 'UTF-8' );
		$patterns = array(
			"/fbq\\s*\\(\\s*['\"]init['\"]\\s*,\\s*['\"]?([0-9]{6,20})['\"]?\\s*\\)/i",
			'/[?&]id=([0-9]{6,20})(?:&|$)/i',
		);
		foreach ( $patterns as $pattern ) {
			if ( (bool) preg_match( $pattern, $decoded, $m ) && ! empty( $m[1] ) ) {
				return (string) $m[1];
			}
		}
		return '';
	}

	private static function paypal_detect_webhook_id( $client_id, $secret, $sandbox ) {
		$client_id = trim( (string) $client_id );
		$secret    = trim( (string) $secret );
		if ( $client_id === '' || $secret === '' ) {
			return '';
		}

		$base_url = $sandbox ? 'https://api-m.sandbox.paypal.com' : 'https://api-m.paypal.com';
		$auth     = wp_remote_post(
			$base_url . '/v1/oauth2/token',
			array(
				'headers' => array(
					'Authorization' => 'Basic ' . base64_encode( $client_id . ':' . $secret ),
					'Content-Type'  => 'application/x-www-form-urlencoded',
					'Accept'        => 'application/json',
				),
				'body'    => 'grant_type=client_credentials',
				'timeout' => 20,
			)
		);
		if ( is_wp_error( $auth ) ) {
			return '';
		}
		$auth_code = (int) wp_remote_retrieve_response_code( $auth );
		if ( $auth_code >= 300 ) {
			return '';
		}
		$auth_data = json_decode( wp_remote_retrieve_body( $auth ), true );
		$token     = trim( (string) ( $auth_data['access_token'] ?? '' ) );
		if ( $token === '' ) {
			return '';
		}

		$response = wp_remote_get(
			$base_url . '/v1/notifications/webhooks',
			array(
				'headers' => array(
					'Authorization' => 'Bearer ' . $token,
					'Accept'        => 'application/json',
				),
				'timeout' => 20,
			)
		);
		if ( is_wp_error( $response ) ) {
			return '';
		}
		$code = (int) wp_remote_retrieve_response_code( $response );
		if ( $code >= 300 ) {
			return '';
		}
		$body  = json_decode( wp_remote_retrieve_body( $response ), true );
		$items = $body['webhooks'] ?? array();
		if ( ! is_array( $items ) ) {
			return '';
		}
		$target_url = untrailingslashit( self::paypal_webhook_url() );
		foreach ( $items as $item ) {
			$url = untrailingslashit( (string) ( $item['url'] ?? '' ) );
			if ( $url !== '' && $url === $target_url ) {
				return trim( (string) ( $item['id'] ?? '' ) );
			}
		}
		return '';
	}

	private static function shell_end() {
		echo '</div>';
	}

	private static function unread_paid_notifications_count() {
		global $wpdb;
		$table   = $wpdb->prefix . 'litecal_bookings';
		$seen_at = (string) get_option( 'litecal_paid_notifications_seen_at', '' );
		if ( $seen_at !== '' ) {
			// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name comes from trusted $wpdb->prefix and value placeholder is prepared.
			$sql = $wpdb->prepare(
				"SELECT COUNT(*) FROM {$table}
	                 WHERE status <> 'deleted'
	                   AND payment_status = 'paid'
                   AND COALESCE(NULLIF(payment_received_at, '0000-00-00 00:00:00'), updated_at, created_at) > %s",
				$seen_at
			);
		} else {
			$sql = "SELECT COUNT(*) FROM {$table} WHERE status <> 'deleted' AND payment_status = 'paid'";
		}
		$count = (int) $wpdb->get_var( $sql );
		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return max( 0, $count );
	}

	private static function mark_paid_notifications_seen() {
		update_option( 'litecal_paid_notifications_seen_at', current_time( 'mysql' ), false );
	}

	private static function time_format() {
		$settings = get_option( 'litecal_settings', array() );
		$format   = $settings['time_format'] ?? '12h';
		return in_array( $format, array( '12h', '24h' ), true ) ? $format : '12h';
	}

	private static function format_datetime( $datetime ) {
		if ( empty( $datetime ) ) {
			return '-';
		}
		$format = self::time_format() === '24h' ? 'Y-m-d H:i' : 'Y-m-d g:ia';
		try {
			$tz = wp_timezone();
			$dt = new \DateTime( $datetime, $tz );
			return $dt->format( $format );
		} catch ( \Exception $e ) {
			$ts = strtotime( $datetime );
			if ( ! $ts ) {
				return $datetime;
			}
			return wp_date( $format, $ts );
		}
	}

	private static function format_date_short( $datetime ) {
		if ( empty( $datetime ) ) {
			return '-';
		}
		try {
			$tz    = wp_timezone();
			$dt    = new \DateTime( $datetime, $tz );
			$label = wp_date( 'd M', $dt->getTimestamp(), $tz );
			$label = str_replace( '.', '', $label );
			return function_exists( 'mb_strtolower' ) ? mb_strtolower( $label, 'UTF-8' ) : strtolower( $label );
		} catch ( \Exception $e ) {
			$ts = strtotime( $datetime );
			if ( ! $ts ) {
				return '-';
			}
			$label = wp_date( 'd M', $ts );
			$label = str_replace( '.', '', $label );
			return function_exists( 'mb_strtolower' ) ? mb_strtolower( $label, 'UTF-8' ) : strtolower( $label );
		}
	}

	private static function format_time_short( $datetime ) {
		if ( empty( $datetime ) ) {
			return '-';
		}
		$format = self::time_format() === '24h' ? 'H:i' : 'g:ia';
		try {
			$tz = wp_timezone();
			$dt = new \DateTime( $datetime, $tz );
			return $dt->format( $format );
		} catch ( \Exception $e ) {
			$ts = strtotime( $datetime );
			return $ts ? wp_date( $format, $ts ) : '-';
		}
	}

	private static function time_now_in_tz( $tz ) {
		$format = self::time_format() === '24h' ? 'H:i' : 'g:ia';
		if ( $tz ) {
			try {
				$dt = new \DateTime( 'now', new \DateTimeZone( $tz ) );
				return $dt->format( $format );
			} catch ( \Exception $e ) {
				unset( $e );
			}
		}
		$offset = get_option( 'gmt_offset', 0 );
		if ( $offset ) {
			$seconds   = (int) round( $offset * HOUR_IN_SECONDS );
			$sign      = $seconds >= 0 ? '+' : '-';
			$seconds   = abs( $seconds );
			$hours     = floor( $seconds / 3600 );
			$mins      = floor( ( $seconds % 3600 ) / 60 );
			$tz_offset = sprintf( '%s%02d:%02d', $sign, $hours, $mins );
			try {
				$dt = new \DateTime( 'now', new \DateTimeZone( $tz_offset ) );
				return $dt->format( $format );
			} catch ( \Exception $e ) {
				unset( $e );
			}
		}
		return wp_date( $format );
	}

	private static function flash_notice( $message, $type = 'success' ) {
		$message = is_string( $message ) ? sanitize_text_field( $message ) : '';
		set_transient(
			'litecal_notice',
			array(
				'message' => $message,
				'type'    => $type,
			),
			30
		);
	}

	private static function render_notice() {
		$notice = get_transient( 'litecal_notice' );
		if ( ! $notice ) {
			return;
		}
		delete_transient( 'litecal_notice' );
		$type = $notice['type'] === 'error' ? 'error' : 'success';
		echo '<div class="lc-admin-notice-payload" data-lc-admin-notice="' . esc_attr( $type ) . '" data-lc-admin-notice-message="' . esc_attr( $notice['message'] ) . '" hidden></div>';
	}

	private static function schedule_summary( $schedule ) {
		$days = $schedule['days'] ?? array();
		if ( empty( $days ) ) {
			return __( 'Sin horarios definidos', 'agenda-lite' );
		}
		$start       = null;
		$end         = null;
		$active_days = array();
		foreach ( $days as $day => $ranges ) {
			if ( ! empty( $ranges[0]['start'] ) && ! empty( $ranges[0]['end'] ) ) {
				$active_days[] = (int) $day;
				if ( ! $start ) {
					$start = $ranges[0]['start'];
					$end   = $ranges[0]['end'];
				}
			}
		}
		$labels = array(
			1 => __( 'lun', 'agenda-lite' ),
			2 => __( 'mar', 'agenda-lite' ),
			3 => __( 'mie', 'agenda-lite' ),
			4 => __( 'jue', 'agenda-lite' ),
			5 => __( 'vie', 'agenda-lite' ),
			6 => __( 'sab', 'agenda-lite' ),
			7 => __( 'dom', 'agenda-lite' ),
		);
		$first  = $active_days ? $labels[ min( $active_days ) ] : __( 'lun', 'agenda-lite' );
		$last   = $active_days ? $labels[ max( $active_days ) ] : __( 'dom', 'agenda-lite' );
		return $first . ' - ' . $last . ', ' . $start . ' - ' . $end;
	}

	private static function schedule_has_assigned_hours( $schedule ) {
		$days = (array) ( $schedule['days'] ?? array() );
		foreach ( $days as $ranges ) {
			if ( ! is_array( $ranges ) ) {
				continue;
			}
			foreach ( $ranges as $range ) {
				$start = trim( (string) ( $range['start'] ?? '' ) );
				$end   = trim( (string) ( $range['end'] ?? '' ) );
				if ( $start !== '' && $end !== '' ) {
					return true;
				}
			}
		}
		return false;
	}

	private static function schedule_preview_list( $schedule ) {
		$days   = $schedule['days'] ?? array();
		$labels = array(
			1 => __( 'lunes', 'agenda-lite' ),
			2 => __( 'martes', 'agenda-lite' ),
			3 => __( 'miercoles', 'agenda-lite' ),
			4 => __( 'jueves', 'agenda-lite' ),
			5 => __( 'viernes', 'agenda-lite' ),
			6 => __( 'sabado', 'agenda-lite' ),
			7 => __( 'domingo', 'agenda-lite' ),
		);
		$html   = '';
		foreach ( $labels as $day => $label ) {
			$range = $days[ $day ][0] ?? null;
			if ( $range && ! empty( $range['start'] ) && ! empty( $range['end'] ) ) {
				$html .= '<div class="lc-schedule-preview-item"><span class="lc-day-name">' . esc_html( $label ) . '</span><span>' . esc_html( $range['start'] ) . ' - ' . esc_html( $range['end'] ) . '</span></div>';
			} else {
				$html .= '<div class="lc-schedule-preview-item is-off"><span class="lc-day-name">' . esc_html( $label ) . '</span><span>' . esc_html__( 'Indisponible', 'agenda-lite' ) . '</span></div>';
			}
		}
		return $html;
	}

	private static function avatar_color( $name ) {
		$hash = abs( crc32( $name ?: 'team' ) );
		return 'lc-avatar-color-' . ( $hash % 8 );
	}

	public static function dashboard() {
		if ( ! self::can_access_staff_panel() ) {
			wp_die( esc_html__( 'Unauthorized', 'agenda-lite' ) );
		}
		self::shell_start( 'dashboard' );
		$scope_employee_id = self::current_user_employee_id();
		$employees         = Employees::all_booking_managers( true );
		if ( $scope_employee_id > 0 ) {
			$employees = array_values(
				array_filter(
					(array) $employees,
					static function ( $employee ) use ( $scope_employee_id ) {
						return (int) ( $employee->id ?? 0 ) === $scope_employee_id;
					}
				)
			);
		}
		$events = Events::all();
		if ( $scope_employee_id > 0 ) {
			$events = array_values(
				array_filter(
					(array) $events,
					static function ( $event ) use ( $scope_employee_id ) {
						$ids = array_map( 'intval', wp_list_pluck( Events::employees( (int) ( $event->id ?? 0 ) ), 'id' ) );
						return in_array( $scope_employee_id, $ids, true );
					}
				)
			);
		}
		$staff_scoped = $scope_employee_id > 0 && ! self::can_manage();
		echo '<div class="lc-admin">';
		echo '<h1>' . esc_html__( 'Calendario', 'agenda-lite' ) . '</h1>';
		echo '<div class="lc-panel lc-calendar-app" data-lc-calendar-app>';
		echo '<section class="lc-cal-main">';
		echo '<div class="lc-cal-toolbar">';
		echo '<div class="lc-cal-nav">';
		echo '<button type="button" class="lc-cal-nav-btn" data-lc-cal-prev aria-label="' . esc_attr__( 'Anterior', 'agenda-lite' ) . '"><i class="ri-arrow-left-line"></i></button>';
		echo '<button type="button" class="lc-cal-nav-btn" data-lc-cal-next aria-label="' . esc_attr__( 'Siguiente', 'agenda-lite' ) . '"><i class="ri-arrow-right-line"></i></button>';
		echo '<span class="lc-cal-range" data-lc-cal-range></span>';
		echo '</div>';
		echo '<div class="lc-cal-views" data-lc-cal-views>';
		echo '<button type="button" class="is-active" data-lc-cal-view="month">' . esc_html__( 'Mes', 'agenda-lite' ) . '</button>';
		echo '<button type="button" data-lc-cal-view="week">' . esc_html__( 'Semana', 'agenda-lite' ) . '</button>';
		echo '<button type="button" data-lc-cal-view="day">' . esc_html__( 'Día', 'agenda-lite' ) . '</button>';
		echo '</div>';
		echo '<div class="lc-cal-drag-host" data-lc-cal-drag-host hidden>';
		echo '<span class="lc-cal-drag-label">' . esc_html__( 'Reagendar:', 'agenda-lite' ) . '</span>';
		echo '<button type="button" class="lc-cal-drag-chip" draggable="true" data-lc-cal-drag-chip></button>';
		echo '<button type="button" class="lc-cal-drag-cancel" data-lc-cal-drag-cancel>' . esc_html__( 'Cancelar', 'agenda-lite' ) . '</button>';
		echo '</div>';
		echo '</div>';

		echo '<div class="lc-cal-filters lc-cal-filters--inline">';
		echo '<div class="lc-cal-filter-item"><label>' . esc_html__( 'Estado', 'agenda-lite' ) . '</label>';
		echo '<select data-lc-cal-filter-status>';
		echo '<option value="">' . esc_html__( 'Todos', 'agenda-lite' ) . '</option>';
		foreach ( array( 'pending', 'confirmed', 'cancelled', 'rescheduled' ) as $status ) {
			echo '<option value="' . esc_attr( $status ) . '">' . esc_html( self::status_label( $status, 'booking' ) ) . '</option>';
		}
		echo '</select></div>';
		echo '<div class="lc-cal-filter-item"><label>' . esc_html__( 'Profesional', 'agenda-lite' ) . '</label>';
		echo '<select data-lc-cal-filter-employee>';
		if ( ! $staff_scoped ) {
			echo '<option value="">' . esc_html__( 'Todos (global)', 'agenda-lite' ) . '</option>';
		}
		foreach ( $employees as $employee ) {
			echo '<option value="' . esc_attr( (int) $employee->id ) . '"' . selected( $staff_scoped && (int) $employee->id === $scope_employee_id, true, false ) . '>' . esc_html( $employee->name ) . '</option>';
		}
		echo '</select></div>';
		echo '<div class="lc-cal-filter-item"><label>' . esc_html__( 'Servicio', 'agenda-lite' ) . '</label>';
		echo '<select data-lc-cal-filter-event>';
		echo '<option value="">' . esc_html__( 'Todos', 'agenda-lite' ) . '</option>';
		foreach ( $events as $event ) {
			echo '<option value="' . esc_attr( (int) $event->id ) . '">' . esc_html( $event->title ) . '</option>';
		}
		echo '</select></div>';
		echo '</div>';

		echo '<div class="lc-cal-stage" data-lc-cal-stage></div>';

		echo '</section>';

		echo '<div class="lc-cal-modal" data-lc-cal-modal hidden>';
		echo '<section class="lc-cal-detail-view" data-lc-cal-detail-view>';
		echo '<div class="lc-cal-detail-head">';
		echo '<strong>' . esc_html__( 'Detalle de reserva', 'agenda-lite' ) . '</strong>';
		echo '<button type="button" class="lc-cal-modal-close" data-lc-cal-modal-close aria-label="' . esc_attr__( 'Cerrar', 'agenda-lite' ) . '"><i class="ri-close-line"></i></button>';
		echo '</div>';
		echo '<div class="lc-cal-detail-scroll">';
		echo '<div class="lc-calendar-booking-detail" data-lc-booking-detail></div>';
		echo '<form method="post" class="lc-calendar-form" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( 'litecal_update_booking_full' );
		echo '<input type="hidden" name="action" value="litecal_update_booking_full" />';
		echo '<input type="hidden" name="id" value="" data-lc-booking-id />';
		echo '<label>' . esc_html__( 'Estado', 'agenda-lite' ) . '</label>';
		echo '<select name="status" data-lc-booking-status>';
		foreach ( array( 'pending', 'confirmed', 'cancelled' ) as $status ) {
			echo '<option value="' . esc_attr( $status ) . '">' . esc_html( self::status_label( $status, 'booking' ) ) . '</option>';
		}
		echo '</select>';
		echo '<input type="hidden" name="start_datetime" data-lc-booking-start />';
		echo '<input type="hidden" name="end_datetime" data-lc-booking-end />';
		echo '<label>' . esc_html__( 'Nombre', 'agenda-lite' ) . '</label><input name="name" data-lc-booking-name />';
		echo '<label>' . esc_html__( 'Email', 'agenda-lite' ) . '</label><input name="email" data-lc-booking-email />';
		echo '<label>' . esc_html__( 'Teléfono', 'agenda-lite' ) . '</label>';
		echo '<input name="phone" class="lc-phone-input lc-phone-field" data-lc-booking-phone type="tel" />';
		echo '<label>' . esc_html__( 'Nota interna', 'agenda-lite' ) . '</label><textarea name="message" data-lc-booking-message></textarea>';
		echo '<label>' . esc_html__( 'Profesional', 'agenda-lite' ) . '</label>';
		echo '<div class="lc-select" data-lc-select data-lc-select-name="employee_id">';
		echo '<input type="hidden" name="employee_id" value="" data-lc-select-input data-lc-booking-employee />';
		echo '<button type="button" class="lc-select-trigger" data-lc-select-trigger>';
		echo '<span class="lc-select-icon"><i class="ri-user-line"></i></span>';
		echo '<span class="lc-select-text">' . esc_html__( 'Sin asignar', 'agenda-lite' ) . '</span>';
		echo '<i class="ri-arrow-down-s-line"></i>';
		echo '</button>';
		echo '<div class="lc-select-menu">';
		if ( ! $staff_scoped ) {
			echo '<button type="button" class="lc-select-option" data-lc-select-option data-value="">';
			echo '<span class="lc-select-option-icon"><i class="ri-user-line"></i></span>';
			echo '<span class="lc-select-option-text">' . esc_html__( 'Sin asignar', 'agenda-lite' ) . '</span>';
			echo '<small>-</small>';
			echo '</button>';
		}
		foreach ( $employees as $employee ) {
			$parts       = preg_split( '/\s+/', trim( (string) $employee->name ) );
			$initials    = strtoupper( substr( $parts[0] ?? '', 0, 1 ) . substr( $parts[1] ?? '', 0, 1 ) );
			$color_class = self::avatar_color( (string) $employee->name );
			echo '<button type="button" class="lc-select-option" data-lc-select-option data-value="' . esc_attr( $employee->id ) . '">';
			echo '<span class="lc-select-option-icon">';
			if ( ! empty( $employee->avatar_url ) ) {
				echo '<img src="' . esc_url( $employee->avatar_url ) . '" alt="' . esc_attr( $employee->name ) . '" />';
			} else {
				echo '<span class="lc-avatar lc-avatar-sm lc-avatar-badge ' . esc_attr( $color_class ) . '">' . esc_html( $initials ?: '•' ) . '</span>';
			}
			echo '</span>';
			echo '<span class="lc-select-option-text">' . esc_html( $employee->name ) . '</span>';
			echo '<small>' . esc_html( $employee->email ?: '-' ) . '</small>';
			echo '</button>';
		}
		echo '</div>';
		echo '</div>';
		echo '<div class="lc-calendar-form-actions"><button class="button button-primary">' . esc_html__( 'Actualizar', 'agenda-lite' ) . '</button></div>';
		echo '</form>';
		echo '</div>';
		echo '</section>';
		echo '</div>';
		echo '<div class="lc-cal-slot-modal" data-lc-cal-slot-modal hidden>';
		echo '<section class="lc-cal-slot-picker">';
		echo '<div class="lc-cal-slot-head">';
		echo '<strong>' . esc_html__( 'Selecciona nueva hora', 'agenda-lite' ) . '</strong>';
		echo '<button type="button" class="lc-cal-modal-close" data-lc-cal-slot-close aria-label="' . esc_attr__( 'Cerrar', 'agenda-lite' ) . '"><i class="ri-close-line"></i></button>';
		echo '</div>';
		echo '<div class="lc-cal-slot-sub" data-lc-cal-slot-date></div>';
		echo '<div class="lc-cal-slot-list" data-lc-cal-slot-list></div>';
		echo '<div class="lc-cal-slot-actions">';
		echo '<button type="button" class="button" data-lc-cal-slot-cancel>' . esc_html__( 'Cancelar', 'agenda-lite' ) . '</button>';
		echo '<button type="button" class="button button-primary" data-lc-cal-slot-apply disabled>' . esc_html__( 'Confirmar horario', 'agenda-lite' ) . '</button>';
		echo '</div>';
		echo '</section>';
		echo '</div>';
		echo '<div class="lc-cal-popover" data-lc-cal-popover hidden></div>';
		echo '</div>';
		echo '</div>';
		self::shell_end();
	}

	public static function events() {
		if ( ! self::can_manage_events() ) {
			wp_die( esc_html__( 'Unauthorized', 'agenda-lite' ) );
		}
		self::refresh_all_event_statuses();
		$events                = self::ordered_events( (array) Events::all() );
		$service_limit_reached = self::free_limit_reached( 'services' );
		self::shell_start( 'events' );
		echo '<div class="lc-admin">';
		echo '<div class="lc-header-row">';
		echo '<div><h1>Servicios</h1><p class="description">Crea servicios para que tus clientes reserven en tu calendario.</p></div>';
		echo '<div class="lc-header-actions">';
		if ( $service_limit_reached ) {
			echo wp_kses_post( self::pro_upgrade_disabled_button_html( __( 'Actualiza a Pro para desbloquear funciones premium', 'agenda-lite' ), 'button button-primary' ) );
		} else {
			echo '<a class="button button-primary" href="' . esc_url( Helpers::admin_url( 'event' ) . '&event_id=new' ) . '">+ Nuevo</a>';
		}
		echo '</div>';
		echo '</div>';

		echo '<div class="lc-panel lc-event-list">';
		echo '<div class="lc-event-list-header"><span>Servicios</span><small>' . esc_html__( 'Arrastra y suelta para definir el orden público de los servicios.', 'agenda-lite' ) . '</small></div>';
		echo '<div data-lc-service-order-list>';
		$location_labels = array(
			'google_meet' => array(
				'label' => 'Google Meet',
				'logo'  => LITECAL_URL . 'assets/logos/googlemeet.svg',
				'icon'  => 'ri-video-chat-line',
			),
			'zoom'        => array(
				'label' => 'Zoom',
				'logo'  => LITECAL_URL . 'assets/logos/zoom.svg',
				'icon'  => 'ri-video-on-line',
			),
			'teams'       => array(
				'label' => 'Microsoft Teams',
				'logo'  => LITECAL_URL . 'assets/logos/teams.svg',
				'icon'  => 'ri-microsoft-line',
			),
			'presencial'  => array(
				'label' => 'Presencial',
				'icon'  => 'ri-map-pin-5-line',
			),
			'custom'      => array(
				'label' => 'Otro',
				'icon'  => 'ri-links-line',
			),
		);
		foreach ( $events as $event ) {
			$edit_url            = Helpers::admin_url( 'event' ) . '&event_id=' . $event->id . '&tab=config';
			$employees           = Events::employees( $event->id );
			$assigned_names      = array_values(
				array_filter(
					array_map(
						static function ( $employee ) {
							return trim( (string) ( $employee->name ?? '' ) );
						},
						(array) $employees
					)
				)
			);
			$assigned_name       = $assigned_names[0] ?? '';
			$assigned_extra      = array_slice( $assigned_names, 1 );
			$integrations        = get_option( 'litecal_integrations', array() );
			$google_oauth        = get_option( 'litecal_google_oauth', array() );
			$google_meet_allowed = ! empty( $integrations['google_calendar'] ) && ( ! empty( $google_oauth['access_token'] ) || ! empty( $google_oauth['refresh_token'] ) );
			$location_key        = $event->location ?: 'custom';
			if ( $location_key === 'google_meet' && ! $google_meet_allowed ) {
				$location_key = 'presencial';
			}
			$location_item = $location_labels[ $location_key ] ?? array(
				'label' => 'Otro',
				'icon'  => 'ri-links-line',
			);
			$desc          = trim( wp_strip_all_tags( $event->description ?: '' ) );
			$desc          = preg_replace( '/\s+/', ' ', $desc );
			if ( function_exists( 'mb_strimwidth' ) ) {
				$desc = mb_strimwidth( $desc, 0, 150, '…', 'UTF-8' );
			} elseif ( strlen( $desc ) > 150 ) {
				$desc = substr( $desc, 0, 150 ) . '…';
			}
			$event_options = json_decode( $event->options ?: '[]', true );
			if ( ! is_array( $event_options ) ) {
				$event_options = array();
			}
			$selected_providers = $event_options['payment_providers'] ?? array();
			if ( ! is_array( $selected_providers ) ) {
				$selected_providers = array_filter( array_map( 'trim', explode( ',', (string) $selected_providers ) ) );
			}
			$selected_providers     = array_values( array_unique( array_filter( array_map( 'sanitize_key', $selected_providers ) ) ) );
			$price_mode             = sanitize_key( (string) ( $event_options['price_mode'] ?? 'free' ) );
			$event_currency         = strtoupper( (string) ( $event->currency ?: ( get_option( 'litecal_settings', array() )['currency'] ?? 'USD' ) ) );
			$available_provider_map = array();
			foreach ( self::payment_methods_for_event( $event_currency, array(), null ) as $method ) {
				$available_provider_map[ $method['key'] ] = array(
					'label' => $method['label'] ?? strtoupper( (string) $method['key'] ),
					'logo'  => $method['logo'] ?? '',
				);
			}
			echo '<div class="lc-event-item is-draggable" data-lc-service-row data-lc-service-id="' . esc_attr( (int) $event->id ) . '" draggable="true">';
			echo '<div class="lc-event-info">';
			echo '<div class="lc-event-title-row">';
			echo '<span class="lc-drag-handle" title="' . esc_attr__( 'Arrastrar para ordenar', 'agenda-lite' ) . '">⋮⋮</span>';
			$status_class = $event->status === 'active' ? 'is-active' : 'is-inactive';
			echo '<span class="lc-event-dot ' . esc_attr( $status_class ) . '"><span class="dot"></span></span>';
			echo '<span class="lc-event-title">' . esc_html( $event->title ) . '</span>';
			echo '<div class="lc-event-title-tags">';
			$event_url = trailingslashit( home_url() ) . ltrim( (string) $event->slug, '/' );
			echo '<a class="lc-event-tag lc-event-tag--link" href="' . esc_url( $event_url ) . '" target="_blank" rel="noopener">';
			echo '<i class="ri-link"></i>';
			echo '<span>' . esc_html( $event->slug ) . '</span>';
			echo '</a>';
			echo '</div>';
			echo '</div>';
			if ( ! empty( $assigned_name ) ) {
				echo '<div class="lc-event-owner">';
				echo esc_html__( 'Asignado a', 'agenda-lite' ) . ' ' . esc_html( $assigned_name );
				if ( ! empty( $assigned_extra ) ) {
					$extra_count   = count( $assigned_extra );
					$extra_text    = '+' . $extra_count . ' ' . esc_html( _n( 'más', 'más', $extra_count, 'agenda-lite' ) );
					$extra_tooltip = implode( ', ', $assigned_extra );
					echo ' <button type="button" class="lc-event-owner-more lc-tooltip-fallback" data-lc-tooltip-content="' . esc_attr( $extra_tooltip ) . '" title="' . esc_attr( $extra_tooltip ) . '">' . esc_html( $extra_text ) . '</button>';
				}
				echo '</div>';
			}
			if ( ! empty( $desc ) ) {
				echo '<div class="lc-event-sub">' . esc_html( $desc ) . '</div>';
			}
			echo '<div class="lc-event-tags">';
			echo '<span class="lc-event-tag"><i class="ri-time-line"></i>' . esc_html( $event->duration ) . ' min</span>';
			if ( $price_mode === 'onsite' ) {
				echo '<span class="lc-event-tag lc-event-tag--payment">';
				echo '<i class="ri-hand-coin-line"></i>';
				echo '<span>' . esc_html__( 'Pago presencial', 'agenda-lite' ) . '</span>';
				echo '</span>';
			} elseif ( $price_mode !== 'free' && ! empty( $selected_providers ) ) {
				foreach ( $selected_providers as $provider ) {
					$meta = $available_provider_map[ $provider ] ?? null;
					if ( ! $meta ) {
						continue;
					}
					echo '<span class="lc-event-tag lc-event-tag--payment">';
					if ( ! empty( $meta['logo'] ) ) {
						echo '<img src="' . esc_url( $meta['logo'] ) . '" alt="' . esc_attr( $meta['label'] ) . '" />';
					}
					echo '<span>' . esc_html( $meta['label'] ) . '</span>';
					echo '</span>';
				}
			}
			echo '<span class="lc-event-tag lc-event-tag--location">';
			if ( ! empty( $location_item['logo'] ) ) {
				echo '<img src="' . esc_url( $location_item['logo'] ) . '" alt="' . esc_attr( $location_item['label'] ) . '" />';
			} else {
				echo '<i class="' . esc_attr( $location_item['icon'] ) . '"></i>';
			}
			echo '<span>' . esc_html( $location_item['label'] ) . '</span>';
			echo '</span>';
			echo '</div>';
			echo '</div>';
			echo '<div class="lc-event-actions">';
			echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="display:inline;">';
			wp_nonce_field( 'litecal_toggle_event_status' );
			echo '<input type="hidden" name="action" value="litecal_toggle_event_status" />';
			echo '<input type="hidden" name="id" value="' . esc_attr( $event->id ) . '" />';
			echo '<label class="lc-toggle-row lc-toggle-row-left lc-event-toggle">';
			echo '<input type="hidden" name="status" value="inactive" />';
			echo '<span class="lc-switch"><input type="checkbox" name="status" value="active" ' . checked( 'active', $event->status, false ) . ' data-lc-auto-submit><span></span></span>';
			echo '<span>' . ( $event->status === 'active' ? 'Activo' : 'Inactivo' ) . '</span>';
			echo '</label>';
			echo '</form>';
			echo '<a class="button" href="' . esc_url( $edit_url ) . '">Editar</a>';
			echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="display:inline;">';
			wp_nonce_field( 'litecal_duplicate_event' );
			echo '<input type="hidden" name="action" value="litecal_duplicate_event" />';
			echo '<input type="hidden" name="id" value="' . esc_attr( $event->id ) . '" />';
			echo '<button class="button">Duplicar</button>';
			echo '</form>';
			echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="display:inline;" data-lc-confirm-delete>';
			wp_nonce_field( 'litecal_delete_event' );
			echo '<input type="hidden" name="action" value="litecal_delete_event" />';
			echo '<input type="hidden" name="id" value="' . esc_attr( $event->id ) . '" />';
			echo '<button class="button button-link-delete">Eliminar</button>';
			echo '</form>';
			echo '</div>';
			echo '</div>';
		}
		if ( ! $events ) {
			echo '<p class="description">' . esc_html__( 'Sin servicios aún.', 'agenda-lite' ) . '</p>';
		}
		echo '</div>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" data-lc-service-order-form>';
		wp_nonce_field( 'litecal_save_event_order' );
		echo '<input type="hidden" name="action" value="litecal_save_event_order" />';
		echo '<input type="hidden" name="ids" value="" data-lc-service-order-ids />';
		echo '</form>';
		echo '</div>';
		echo '</div>';
		echo '</div>';
		self::shell_end();
	}

	private static function ordered_events( array $events ) {
		if ( empty( $events ) ) {
			return array();
		}
		$order    = get_option( 'litecal_event_order', array() );
		$order    = array_values(
			array_unique(
				array_filter(
					array_map( 'intval', (array) $order ),
					static function ( $id ) {
						return $id > 0;
					}
				)
			)
		);
		$position = array_flip( $order );
		usort(
			$events,
			static function ( $a, $b ) use ( $position ) {
				$a_id  = (int) ( $a->id ?? 0 );
				$b_id  = (int) ( $b->id ?? 0 );
				$a_pos = array_key_exists( $a_id, $position ) ? (int) $position[ $a_id ] : PHP_INT_MAX;
				$b_pos = array_key_exists( $b_id, $position ) ? (int) $position[ $b_id ] : PHP_INT_MAX;
				if ( $a_pos !== $b_pos ) {
					return $a_pos <=> $b_pos;
				}
				$a_created = (string) ( $a->created_at ?? '' );
				$b_created = (string) ( $b->created_at ?? '' );
				if ( $a_created !== $b_created ) {
					return strcmp( $b_created, $a_created );
				}
				return $b_id <=> $a_id;
			}
		);
		return $events;
	}

	private static function normalize_event_order( array $ordered_ids = array() ) {
		$events = (array) Events::all();
		if ( empty( $events ) ) {
			return array();
		}
		$valid_ids = array();
		foreach ( $events as $event ) {
			$id = (int) ( $event->id ?? 0 );
			if ( $id > 0 ) {
				$valid_ids[ $id ] = true;
			}
		}

		$result = array();
		foreach ( $ordered_ids as $id ) {
			$id = (int) $id;
			if ( $id <= 0 || ! isset( $valid_ids[ $id ] ) ) {
				continue;
			}
			$result[ $id ] = true;
		}
		foreach ( self::ordered_events( $events ) as $event ) {
			$id = (int) ( $event->id ?? 0 );
			if ( $id > 0 ) {
				$result[ $id ] = true;
			}
		}
		return array_keys( $result );
	}

	public static function save_event_order() {
		if ( ! self::can_manage_events() ) {
			wp_die( esc_html__( 'Unauthorized', 'agenda-lite' ) );
		}
		check_admin_referer( 'litecal_save_event_order' );
		$raw_ids     = sanitize_text_field( trim( (string) wp_unslash( $_POST['ids'] ?? '' ) ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Input sanitized on this line.
		$ordered_ids = array();
		if ( $raw_ids !== '' ) {
			$ordered_ids = array_values(
				array_filter(
					array_map( 'intval', explode( ',', $raw_ids ) ),
					static function ( $id ) {
						return $id > 0;
					}
				)
			);
		}
		$normalized = self::normalize_event_order( $ordered_ids );
		update_option( 'litecal_event_order', $normalized, false );
		self::flash_notice( esc_html__( 'Orden de servicios actualizado.', 'agenda-lite' ), 'success' );
		wp_safe_redirect( Helpers::admin_url( 'events' ) );
		exit;
	}

	private static function ordered_employees( array $employees ) {
		if ( empty( $employees ) ) {
			return array();
		}
		$order    = get_option( 'litecal_employee_order', array() );
		$order    = array_values(
			array_unique(
				array_filter(
					array_map( 'intval', (array) $order ),
					static function ( $id ) {
						return $id > 0;
					}
				)
			)
		);
		$position = array_flip( $order );
		usort(
			$employees,
			static function ( $a, $b ) use ( $position ) {
				$a_id  = (int) ( $a->id ?? 0 );
				$b_id  = (int) ( $b->id ?? 0 );
				$a_pos = array_key_exists( $a_id, $position ) ? (int) $position[ $a_id ] : PHP_INT_MAX;
				$b_pos = array_key_exists( $b_id, $position ) ? (int) $position[ $b_id ] : PHP_INT_MAX;
				if ( $a_pos !== $b_pos ) {
					return $a_pos <=> $b_pos;
				}
				$a_name = trim( (string) ( $a->name ?? '' ) );
				$b_name = trim( (string) ( $b->name ?? '' ) );
				return strcasecmp( $a_name, $b_name );
			}
		);
		return $employees;
	}

	private static function normalize_employee_order( array $ordered_ids = array() ) {
		$employees = (array) Employees::all_booking_managers( true );
		if ( empty( $employees ) ) {
			return array();
		}
		$valid_ids = array();
		foreach ( $employees as $employee ) {
			$id = (int) ( $employee->id ?? 0 );
			if ( $id > 0 ) {
				$valid_ids[ $id ] = true;
			}
		}
		$result = array();
		foreach ( $ordered_ids as $id ) {
			$id = (int) $id;
			if ( $id <= 0 || ! isset( $valid_ids[ $id ] ) ) {
				continue;
			}
			$result[ $id ] = true;
		}
		foreach ( self::ordered_employees( $employees ) as $employee ) {
			$id = (int) ( $employee->id ?? 0 );
			if ( $id > 0 ) {
				$result[ $id ] = true;
			}
		}
		return array_keys( $result );
	}

	public static function save_employee_order() {
		if ( ! self::can_manage() ) {
			wp_die( esc_html__( 'Unauthorized', 'agenda-lite' ) );
		}
		check_admin_referer( 'litecal_save_employee_order' );
		$raw_ids     = sanitize_text_field( trim( (string) wp_unslash( $_POST['ids'] ?? '' ) ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Input sanitized on this line.
		$ordered_ids = array();
		if ( $raw_ids !== '' ) {
			$ordered_ids = array_values(
				array_filter(
					array_map( 'intval', explode( ',', $raw_ids ) ),
					static function ( $id ) {
						return $id > 0;
					}
				)
			);
		}
		$normalized = self::normalize_employee_order( $ordered_ids );
		update_option( 'litecal_employee_order', $normalized, false );
		self::flash_notice( esc_html__( 'Orden de profesionales actualizado.', 'agenda-lite' ), 'success' );
		wp_safe_redirect( Helpers::admin_url( 'employees' ) );
		exit;
	}

	private static function ordered_schedules( array $schedules ) {
		if ( empty( $schedules ) ) {
			return array();
		}
		$order    = get_option( 'litecal_schedule_order', array() );
		$order    = array_values(
			array_unique(
				array_filter(
					array_map( 'sanitize_text_field', (array) $order ),
					static function ( $id ) {
						return $id !== '';
					}
				)
			)
		);
		$position = array_flip( $order );
		uksort(
			$schedules,
			static function ( $a, $b ) use ( $position, $schedules ) {
				$a_key     = (string) $a;
				$b_key     = (string) $b;
				$a_pos_key = sanitize_text_field( $a_key );
				$b_pos_key = sanitize_text_field( $b_key );
				$a_pos     = array_key_exists( $a_pos_key, $position ) ? (int) $position[ $a_pos_key ] : PHP_INT_MAX;
				$b_pos     = array_key_exists( $b_pos_key, $position ) ? (int) $position[ $b_pos_key ] : PHP_INT_MAX;
				if ( $a_pos !== $b_pos ) {
					return $a_pos <=> $b_pos;
				}
				$a_name = trim( (string) ( $schedules[ $a_key ]['name'] ?? $a_key ) );
				$b_name = trim( (string) ( $schedules[ $b_key ]['name'] ?? $b_key ) );
				return strcasecmp( $a_name, $b_name );
			}
		);
		return $schedules;
	}

	private static function normalize_schedule_order( array $ordered_ids = array() ) {
		$schedules = self::ordered_schedules( (array) get_option( 'litecal_schedules', array() ) );
		if ( empty( $schedules ) ) {
			return array();
		}
		$valid_keys = array();
		foreach ( array_keys( $schedules ) as $sid ) {
			$sid = sanitize_text_field( (string) $sid );
			if ( $sid !== '' ) {
				$valid_keys[ $sid ] = true;
			}
		}
		$result = array();
		foreach ( $ordered_ids as $sid ) {
			$sid = sanitize_text_field( (string) $sid );
			if ( $sid === '' || ! isset( $valid_keys[ $sid ] ) ) {
				continue;
			}
			$result[ $sid ] = true;
		}
		foreach ( array_keys( $schedules ) as $sid ) {
			$sid = sanitize_text_field( (string) $sid );
			if ( $sid !== '' ) {
				$result[ $sid ] = true;
			}
		}
		return array_keys( $result );
	}

	public static function save_schedule_order() {
		if ( ! self::can_manage() ) {
			wp_die( esc_html__( 'Unauthorized', 'agenda-lite' ) );
		}
		check_admin_referer( 'litecal_save_schedule_order' );
		$raw_ids     = sanitize_text_field( trim( (string) wp_unslash( $_POST['ids'] ?? '' ) ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Input sanitized on this line.
		$ordered_ids = array();
		if ( $raw_ids !== '' ) {
			$ordered_ids = array_values(
				array_filter(
					array_map( 'sanitize_text_field', explode( ',', $raw_ids ) ),
					static function ( $id ) {
						return $id !== '';
					}
				)
			);
		}
		$normalized = self::normalize_schedule_order( $ordered_ids );
		update_option( 'litecal_schedule_order', $normalized, false );
		self::flash_notice( esc_html__( 'Orden de horarios actualizado.', 'agenda-lite' ), 'success' );
		wp_safe_redirect( Helpers::admin_url( 'availability' ) );
		exit;
	}

	public static function toggle_event_status() {
		if ( ! self::can_manage_events() ) {
			wp_die( esc_html__( 'Unauthorized', 'agenda-lite' ) );
		}
		check_admin_referer( 'litecal_toggle_event_status' );
		$id = absint( wp_unslash( $_POST['id'] ?? 0 ) );
		if ( $id ) {
			$status = ( isset( $_POST['status'] ) && $_POST['status'] === 'active' ) ? 'active' : 'draft';
			if ( $status === 'active' && ! self::event_has_active_employee( $id ) ) {
				Events::update( $id, array( 'status' => 'draft' ) );
				self::flash_notice( esc_html__( 'Para activar el servicio debes asignar un profesional activo.', 'agenda-lite' ), 'error' );
			} else {
				Events::update( $id, array( 'status' => $status ) );
			}
		}
		wp_safe_redirect( Helpers::admin_url( 'events' ) );
		exit;
	}

	public static function event_detail() {
		if ( ! self::can_manage_events() ) {
			wp_die( esc_html__( 'Unauthorized', 'agenda-lite' ) );
		}
		$event_id = sanitize_text_field( wp_unslash( $_GET['event_id'] ?? '' ) );
		if ( $event_id === 'new' ) {
			$new_id           = Events::create(
				array(
					'title'    => 'Nuevo servicio',
					'slug'     => 'nuevo-servicio-' . wp_rand( 100, 999 ),
					'status'   => 'draft',
					'location' => 'presencial',
				)
			);
			$event_id         = $new_id;
			$_GET['event_id'] = $new_id;
			$_GET['tab']      = 'config';
		}

		$event = Events::get( (int) $event_id );
		if ( ! $event ) {
			self::shell_start( 'events' );
			echo '<div class="lc-admin">';
			echo '<h1>' . esc_html__( 'Servicio', 'agenda-lite' ) . '</h1>';
			echo '<div class="lc-card">';
			echo '<p class="description">' . esc_html__( 'Selecciona un servicio desde Servicios para editarlo.', 'agenda-lite' ) . '</p>';
			echo '<a class="button" href="' . esc_url( Helpers::admin_url( 'events' ) ) . '">' . esc_html__( 'Volver a Servicios', 'agenda-lite' ) . '</a>';
			echo '</div>';
			echo '</div>';
			self::shell_end();
			return;
		}
		$tab                = sanitize_text_field( wp_unslash( $_GET['tab'] ?? 'config' ) );
		if ( $tab === 'abuse_security' ) {
			$tab = 'self_service';
		}
		$employees          = Employees::all_booking_managers( true );
		$selected_employees = array_map( 'intval', wp_list_pluck( Events::employees( $event->id ), 'id' ) );
		$options            = json_decode( $event->options ?: '[]', true );
		$custom_fields      = json_decode( $event->custom_fields ?: '[]', true );
		$integrations       = get_option( 'litecal_integrations', array() );

		self::shell_start( 'events' );
		echo '<div class="lc-admin lc-event-detail">';
		echo '<div class="lc-detail-header">';
		echo '<div class="lc-detail-title"><a href="' . esc_url( Helpers::admin_url( 'events' ) ) . '">←</a> ' . esc_html( $event->title ) . '</div>';
		echo '<div class="lc-detail-actions">';
		echo '<label class="lc-toggle-row lc-toggle-row-left">';
		echo '<input type="hidden" name="status_toggle" form="lc-event-form" value="0" />';
		echo '<span class="lc-switch"><input type="checkbox" name="status_toggle" form="lc-event-form" value="1" ' . checked( 'active', $event->status, false ) . '><span></span></span>';
		echo '<span>' . ( $event->status === 'active' ? esc_html__( 'Servicio Activo', 'agenda-lite' ) : esc_html__( 'Servicio desactivado', 'agenda-lite' ) ) . '</span>';
		echo '</label>';
		echo '<button class="button button-primary" type="submit" form="lc-event-form">' . esc_html__( 'Guardar', 'agenda-lite' ) . '</button>';
		echo '<a class="button" href="' . esc_url( Helpers::admin_url( 'events' ) ) . '">' . esc_html__( 'Cancelar', 'agenda-lite' ) . '</a>';
		echo '</div>';
		echo '</div>';

		echo '<div class="lc-detail-layout">';
		echo '<aside class="lc-subnav">';
		$tabs = array(
			'config'       => array(
				'label' => __( 'Configuración del servicio', 'agenda-lite' ),
				/* translators: %d: service duration in minutes. */
				'desc'  => sprintf( esc_html__( '%d minutos', 'agenda-lite' ), (int) $event->duration ),
				'icon'  => 'ri-settings-3-line',
			),
			'availability' => array(
				'label' => __( 'Disponibilidad', 'agenda-lite' ),
				'desc'  => __( 'Horas laborales', 'agenda-lite' ),
				'icon'  => 'ri-time-line',
			),
			'limits'       => array(
				'label' => __( 'Límites', 'agenda-lite' ),
				'desc'  => __( 'Con qué frecuencia se puede reservar', 'agenda-lite' ),
				'icon'  => 'ri-shield-check-line',
			),
			'self_service' => array(
				'label' => __( 'Autogestión', 'agenda-lite' ),
				'desc'  => __( 'Reglas de reagendar y cancelar', 'agenda-lite' ),
				'icon'  => 'ri-user-settings-line',
			),
			'advanced'     => array(
				'label' => __( 'Campos personalizados', 'agenda-lite' ),
				'desc'  => __( 'Preguntas de la reserva', 'agenda-lite' ),
				'icon'  => 'ri-list-check-2',
			),
			'extras'       => array(
				'label' => __( 'Extras', 'agenda-lite' ),
				'desc'  => __( 'Servicios adicionales', 'agenda-lite' ),
				'icon'  => 'ri-add-circle-line',
			),
			'code'         => array(
				'label' => __( 'Código', 'agenda-lite' ),
				'desc'  => __( 'Iframe', 'agenda-lite' ),
				'icon'  => 'ri-code-s-slash-line',
			),
		);
		foreach ( $tabs as $key => $item ) {
			$active = $tab === $key ? 'is-active' : '';
			$url    = Helpers::admin_url( 'event' ) . '&event_id=' . $event->id . '&tab=' . $key;
			echo '<a class="lc-subnav-item ' . esc_attr( $active ) . '" href="' . esc_url( $url ) . '">';
			echo '<span class="lc-subnav-title">';
			if ( ! empty( $item['icon'] ) ) {
				echo '<i class="' . esc_attr( $item['icon'] ) . ' lc-subnav-icon"></i>';
			}
			echo esc_html( $item['label'] ) . '</span>';
			echo '<small>' . esc_html( $item['desc'] ) . '</small>';
			echo '</a>';
		}
		echo '</aside>';

		echo '<div class="lc-detail-content">';
		echo '<form id="lc-event-form" method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( 'litecal_save_event_settings' );
		echo '<input type="hidden" name="action" value="litecal_save_event_settings" />';
		echo '<input type="hidden" name="id" value="' . esc_attr( $event->id ) . '" />';
		echo '<input type="hidden" name="tab" value="' . esc_attr( $tab ) . '" />';

		if ( $tab === 'config' ) {
			$settings             = get_option( 'litecal_settings', array() );
			$currency             = $settings['currency'] ?? 'USD';
			$price_mode           = $options['price_mode'] ?? 'free';
			$partial_percent      = (int) ( $options['partial_percent'] ?? 30 );
			$partial_fixed_amount = $options['partial_fixed_amount'] ?? '';
			if ( ! in_array( $partial_percent, array( 10, 20, 30, 40, 50, 60, 70, 80, 90 ), true ) ) {
				$partial_percent = 30;
			}
			$currency_meta = self::currency_meta( $currency );

			echo '<div class="lc-card" id="lc-location-section">';
			echo '<label>' . esc_html__( 'Título', 'agenda-lite' ) . '</label><input name="title" value="' . esc_attr( $event->title ) . '" />';
			echo '<label>' . esc_html__( 'Descripción', 'agenda-lite' ) . '</label>';
			echo '<div class="lc-richtext" data-lc-richtext>';
			echo '<div class="lc-richtext-toolbar">';
			echo '<button type="button" class="button" data-lc-rt-action="bold"><strong>B</strong></button>';
			echo '<button type="button" class="button" data-lc-rt-action="italic"><em>I</em></button>';
			echo '<button type="button" class="button" data-lc-rt-action="link">' . esc_html__( 'Link', 'agenda-lite' ) . '</button>';
			echo '</div>';
			echo '<div class="lc-richtext-editor" contenteditable="true" data-lc-rt-editor>' . wp_kses(
				$event->description ?? '',
				array(
					'a'      => array(
						'href'   => true,
						'target' => true,
						'rel'    => true,
					),
					'strong' => array(),
					'b'      => array(),
					'em'     => array(),
					'i'      => array(),
				)
			) . '</div>';
			echo '<textarea name="description" data-lc-rt-input style="display:none;">' . esc_textarea( $event->description ?? '' ) . '</textarea>';
			echo '</div>';
			echo '<p class="description">' . esc_html__( 'Puedes usar negritas, cursivas y enlaces.', 'agenda-lite' ) . '</p>';
			$base_url = trailingslashit( home_url() );
			echo '<label>' . esc_html__( 'URL', 'agenda-lite' ) . '</label>';
			echo '<div class="lc-url-input">';
			echo '<span class="lc-url-prefix">' . esc_html( $base_url ) . '</span>';
			echo '<input class="lc-url-slug" name="slug" value="' . esc_attr( $event->slug ) . '" placeholder="slug-del-servicio" />';
			echo '</div>';
			echo '</div>';

			echo '<div class="lc-card">';
			echo '<label>' . esc_html__( 'Duración', 'agenda-lite' ) . '</label><select name="duration" class="lc-full-select lc-duration-select">';
			for ( $i = 15; $i <= 240; $i += 5 ) {
				echo '<option value="' . esc_attr( $i ) . '" ' . selected( (int) $event->duration, $i, false ) . '>' . esc_html( $i ) . ' ' . esc_html__( 'min', 'agenda-lite' ) . '</option>';
			}
			echo '</select>';
			echo '</div>';

			$allow_guests     = ! empty( $options['allow_guests'] );
			$max_guests_value = (int) ( $options['max_guests'] ?? 1 );
			if ( $max_guests_value <= 0 ) {
				$max_guests_value = 1;
			}
			$max_guests_value = min( self::MAX_GUESTS_PER_BOOKING, $max_guests_value );
			$guest_settings_locked = self::is_free_plan();
			if ( $guest_settings_locked ) {
				$allow_guests     = false;
				$max_guests_value = 1;
			}
			echo '<div class="lc-card' . ( $guest_settings_locked ? ' lc-pro-lock' : '' ) . '" id="lc-guests-section" data-lc-guests-settings>';
			echo '<label>' . esc_html__( 'Permitir invitados', 'agenda-lite' ) . '</label>';
			echo '<label class="lc-toggle-row lc-toggle-row-left">';
			echo '<input type="hidden" name="allow_guests" value="0" />';
			echo '<span class="lc-switch"><input type="checkbox" name="allow_guests" value="1" data-lc-allow-guests ' . ( $guest_settings_locked ? 'disabled aria-disabled="true" ' : '' ) . checked( true, $allow_guests, false ) . '><span></span></span>';
			echo '<span>' . ( $allow_guests ? esc_html__( 'Sí', 'agenda-lite' ) : esc_html__( 'No', 'agenda-lite' ) ) . '</span>';
			echo '</label>';
			$guests_limit_style = $allow_guests ? '' : ' style="display:none;"';
			echo '<div class="lc-guests-limit-wrap" data-lc-guests-limit-wrap' . wp_kses_data( $guests_limit_style ) . '>';
			echo '<label>' . esc_html__( 'Cantidad máxima de invitados', 'agenda-lite' ) . '</label>';
			echo '<div class="lc-input-suffix"><input type="number" min="1" max="' . esc_attr( self::MAX_GUESTS_PER_BOOKING ) . '" name="max_guests" value="' . esc_attr( $max_guests_value ) . '"' . ( $guest_settings_locked ? ' disabled aria-disabled="true"' : '' ) . ' /><span>' . esc_html__( 'Invitados', 'agenda-lite' ) . '</span></div>';
			echo '<small class="lc-help">' . esc_html__( 'Máximo 10 invitados por reserva.', 'agenda-lite' ) . '</small>';
			echo '</div>';
			if ( $guest_settings_locked ) {
				echo wp_kses_post( self::pro_lock_note_html( __( 'Los invitados están disponibles en Agenda Lite Pro.', 'agenda-lite' ) ) );
			}
			echo '</div>';

			echo '<div class="lc-card" id="lc-payments-section">';
			echo '<label>' . esc_html__( 'Medios de pago', 'agenda-lite' ) . '</label>';
			echo '<label>' . esc_html__( 'Precio de la reserva', 'agenda-lite' ) . '</label>';
			echo '<select name="price_mode" data-lc-price-mode class="lc-full-select">';
			$price_modes = array(
				'free'            => __( 'Gratis', 'agenda-lite' ),
				'total'           => __( 'Precio total', 'agenda-lite' ),
				'partial_percent' => __( 'Pago parcial según un porcentaje', 'agenda-lite' ),
				'partial_fixed'   => __( 'Pago parcial según un monto fijado', 'agenda-lite' ),
				'onsite'          => __( 'Pago presencial', 'agenda-lite' ),
			);
			foreach ( $price_modes as $key => $label ) {
				echo '<option value="' . esc_attr( $key ) . '" ' . selected( $price_mode, $key, false ) . '>' . esc_html( $label ) . '</option>';
			}
			echo '</select>';

			echo '<div class="lc-payment-price" data-lc-price-wrap>';
			$price_regular = $options['price_regular'] ?? ( $event->price ?? 0 );
			$price_sale    = $options['price_sale'] ?? '';
			echo '<label>' . esc_html__( 'Precio normal', 'agenda-lite' ) . '</label>';
			echo '<div class="lc-price-input lc-price-input-row">';
			echo '<span class="lc-price-prefix">' . esc_html( strtoupper( $currency ) ) . '</span>';
			echo '<input name="price_regular" value="' . esc_attr( self::format_money( $price_regular, $currency ) ) . '" placeholder="' . esc_attr( $currency_meta['example'] ) . '" />';
			echo '</div>';
			echo '<label>' . esc_html__( 'Precio de oferta (opcional)', 'agenda-lite' ) . '</label>';
			echo '<div class="lc-price-input lc-price-input-row">';
			echo '<span class="lc-price-prefix">' . esc_html( strtoupper( $currency ) ) . '</span>';
			echo '<input name="price_sale" value="' . esc_attr( $price_sale !== '' ? self::format_money( $price_sale, $currency ) : '' ) . '" placeholder="' . esc_attr( $currency_meta['example'] ) . '" />';
			echo '</div>';
			echo '</div>';

			echo '<div class="lc-payment-partial" data-lc-partial-wrap>';
			echo '<label>' . esc_html__( 'Porcentaje a cobrar', 'agenda-lite' ) . '</label>';
			echo '<select name="partial_percent" class="lc-full-select">';
			for ( $i = 10; $i <= 90; $i += 10 ) {
				echo '<option value="' . esc_attr( $i ) . '" ' . selected( $partial_percent, $i, false ) . '>' . esc_html( $i ) . '%</option>';
			}
			echo '</select>';
			echo '</div>';
			echo '<div class="lc-payment-fixed" data-lc-fixed-wrap>';
			echo '<label>' . esc_html__( 'Monto fijo a cobrar', 'agenda-lite' ) . '</label>';
			echo '<div class="lc-price-input lc-price-input-row">';
			echo '<span class="lc-price-prefix">' . esc_html( strtoupper( $currency ) ) . '</span>';
			echo '<input name="partial_fixed_amount" value="' . esc_attr( $partial_fixed_amount !== '' ? self::format_money( $partial_fixed_amount, $currency ) : '' ) . '" placeholder="' . esc_attr( $currency_meta['example'] ) . '" />';
			echo '</div>';
			echo '</div>';
			echo '<div class="lc-payment-onsite" data-lc-onsite-wrap>';
			echo '<p class="description">' . esc_html__( 'La reserva se confirma sin pagar online. El cliente pagará de forma presencial al momento de la cita.', 'agenda-lite' ) . '</p>';
			echo '</div>';

			$active_payments = self::payment_methods_for_event( $currency, array(), null );
			echo '<div class="lc-payments-wrap" data-lc-payments-wrap>';
			if ( $active_payments ) {
				$selected_payments = $options['payment_providers'] ?? array();
				if ( ! is_array( $selected_payments ) ) {
					$selected_payments = array_filter( array_map( 'trim', explode( ',', (string) $selected_payments ) ) );
				}
				$active_payment_keys = array_column( $active_payments, 'key' );
				$selected_payments   = array_values( array_intersect( $selected_payments, $active_payment_keys ) );
				if ( ! $selected_payments ) {
					$selected_payments = $active_payment_keys;
				}

				$payment_map = array();
				foreach ( $active_payments as $payment ) {
					$payment_map[ $payment['key'] ] = $payment;
				}
				$ordered_payments = array();
				foreach ( $selected_payments as $pid ) {
					if ( isset( $payment_map[ $pid ] ) ) {
						$ordered_payments[] = $payment_map[ $pid ];
						unset( $payment_map[ $pid ] );
					}
				}
				foreach ( $payment_map as $payment ) {
					$ordered_payments[] = $payment;
				}

				echo '<div class="lc-event-payments-list" data-lc-event-payments-list>';
				foreach ( $ordered_payments as $payment ) {
					$enabled = in_array( $payment['key'], $selected_payments, true );
					echo '<div class="lc-event-payment-row is-draggable" data-lc-payment-key="' . esc_attr( $payment['key'] ) . '" draggable="true">';
					echo '<div>';
					echo '<div class="lc-event-payment-title">';
					echo '<span class="lc-drag-handle">⋮⋮</span>';
					if ( ! empty( $payment['logo'] ) ) {
						echo '<img class="lc-event-payment-logo" src="' . esc_url( $payment['logo'] ) . '" alt="' . esc_attr( $payment['label'] ) . '" />';
					} else {
						echo '<i class="ri-bank-card-line"></i>';
					}
					echo '<span>' . esc_html( $payment['label'] ) . '</span>';
					echo '</div>';
					if ( ! empty( $payment['desc'] ) ) {
						echo '<div class="lc-event-payment-sub">' . esc_html( $payment['desc'] ) . '</div>';
					}
					echo '</div>';
					echo '<div class="lc-event-payment-actions">';
					echo '<label class="lc-toggle-row lc-toggle-row-left">';
					echo '<span class="lc-switch"><input type="checkbox" name="payment_providers[]" value="' . esc_attr( $payment['key'] ) . '" data-lc-payment-toggle ' . checked( $enabled, true, false ) . '><span></span></span>';
					echo '<span data-lc-payment-status>' . ( $enabled ? esc_html__( 'Mostrar', 'agenda-lite' ) : esc_html__( 'Oculto', 'agenda-lite' ) ) . '</span>';
					echo '</label>';
					echo '</div>';
					echo '</div>';
				}
				echo '</div>';
			} else {
				echo '<div class="lc-empty-state"><div>' . esc_html__( 'No hay medios de pago asignados.', 'agenda-lite' ) . '</div></div>';
			}
			echo '<div class="lc-section-footer">';
			echo '<a class="button" href="' . esc_url( Helpers::admin_url( 'integrations' ) ) . '">' . esc_html__( 'Gestionar integraciones', 'agenda-lite' ) . '</a>';
			echo '<span>' . esc_html__( 'Activa tus medios de pago desde Integraciones.', 'agenda-lite' ) . '</span>';
			echo '</div>';
			echo '</div>';
			echo '</div>';

			echo '<div class="lc-card" id="lc-location-section">';
			echo '<label>' . esc_html__( 'Ubicación', 'agenda-lite' ) . '</label>';
			$integrations        = get_option( 'litecal_integrations', array() );
			$google_oauth        = get_option( 'litecal_google_oauth', array() );
			$google_meet_allowed = ! empty( $integrations['google_calendar'] ) && ( ! empty( $google_oauth['access_token'] ) || ! empty( $google_oauth['refresh_token'] ) );
			$locations           = array(
				'presencial' => array(
					'label' => __( 'Presencial', 'agenda-lite' ),
					'icon'  => 'ri-map-pin-5-line',
				),
			);
			if ( $google_meet_allowed ) {
				$locations['google_meet'] = array(
					'label' => __( 'Google Meet', 'agenda-lite' ),
					'logo'  => LITECAL_URL . 'assets/logos/googlemeet.svg',
				);
			}
			if ( ! empty( $integrations['zoom'] ) ) {
				$locations['zoom'] = array(
					'label' => __( 'Zoom', 'agenda-lite' ),
					'logo'  => LITECAL_URL . 'assets/logos/zoom.svg',
				);
			}
			$selected_location = $event->location ?: 'presencial';
			if ( 'onsite' === $price_mode ) {
				$selected_location = 'presencial';
			}
			if ( ! isset( $locations[ $selected_location ] ) ) {
				$selected_location = 'presencial';
			}
			$selected_location_item = $locations[ $selected_location ] ?? reset( $locations );
			echo '<div class="lc-select" data-lc-select data-lc-select-name="location">';
			echo '<input type="hidden" name="location" value="' . esc_attr( $selected_location ) . '" data-lc-select-input data-lc-location-input />';
			echo '<button type="button" class="lc-select-trigger" data-lc-select-trigger>';
			echo '<span class="lc-select-icon">';
			if ( ! empty( $selected_location_item['logo'] ) ) {
				echo '<img src="' . esc_url( $selected_location_item['logo'] ) . '" alt="' . esc_attr( $selected_location_item['label'] ) . '" />';
			} else {
				echo '<i class="' . esc_attr( $selected_location_item['icon'] ) . '"></i>';
			}
			echo '</span>';
			echo '<span class="lc-select-text">' . esc_html( $selected_location_item['label'] ) . '</span>';
			echo '<i class="ri-arrow-down-s-line"></i>';
			echo '</button>';
			echo '<div class="lc-select-menu">';
			foreach ( $locations as $key => $item ) {
				$is_disabled = ( 'onsite' === $price_mode && 'presencial' !== $key );
				echo '<button type="button" class="lc-select-option' . ( $is_disabled ? ' is-disabled' : '' ) . '" data-lc-select-option data-value="' . esc_attr( $key ) . '"' . ( $is_disabled ? ' data-lc-onsite-disabled="1" aria-disabled="true"' : '' ) . '>';
				echo '<span class="lc-select-option-icon">';
				if ( ! empty( $item['logo'] ) ) {
					echo '<img src="' . esc_url( $item['logo'] ) . '" alt="' . esc_attr( $item['label'] ) . '"/>';
				} else {
					echo '<i class="' . esc_attr( $item['icon'] ) . '"></i>';
				}
				echo '</span>';
				echo '<span class="lc-select-option-text">' . esc_html( $item['label'] ) . '</span>';
				echo '</button>';
			}
			echo '</div>';
			echo '</div>';
			$show_location_details = in_array( $selected_location, array( 'presencial' ), true );
			$location_hidden       = $show_location_details ? '' : ' is-hidden';
			echo '<div class="lc-location-details-wrap' . esc_attr( $location_hidden ) . '">';
			echo '<label class="lc-location-details" data-lc-location-details-label>' . esc_html__( 'Dirección', 'agenda-lite' ) . '</label><input class="lc-location-details" name="location_details" data-lc-location-details-input value="' . esc_attr( $event->location_details ?? '' ) . '" />';
			echo '</div>';
			echo '<div class="lc-section-footer">';
			echo '<span>' . esc_html__( 'Define dónde se realizará este servicio.', 'agenda-lite' ) . '</span>';
			echo '</div>';
			echo '</div>';
			echo '<div class="lc-card" id="lc-team-section">';
			echo '<label>' . esc_html__( 'Profesionales', 'agenda-lite' ) . '</label>';
			if ( $employees ) {
				$multiple_team_locked = self::is_free_plan();
				$employee_ids       = array_map(
					static function ( $item ) {
						return (int) ( $item->id ?? 0 );
					},
					$employees
				);
				$selected_employees = array_values( array_intersect( $selected_employees, $employee_ids ) );
				if ( empty( $selected_employees ) && ! empty( $employees[0]->id ) ) {
					$selected_employees = array( (int) $employees[0]->id );
				}
				if ( $multiple_team_locked && ! empty( $selected_employees ) ) {
					$selected_employees = array( (int) $selected_employees[0] );
				}
				$selected_order_map = array_flip( $selected_employees );
				usort(
					$employees,
					static function ( $left, $right ) use ( $selected_order_map ) {
						$left_id      = (int) ( $left->id ?? 0 );
						$right_id     = (int) ( $right->id ?? 0 );
						$left_order   = $selected_order_map[ $left_id ] ?? PHP_INT_MAX;
						$right_order  = $selected_order_map[ $right_id ] ?? PHP_INT_MAX;
						$left_active  = array_key_exists( $left_id, $selected_order_map );
						$right_active = array_key_exists( $right_id, $selected_order_map );
						if ( $left_active && $right_active ) {
							return $left_order <=> $right_order;
						}
						if ( $left_active !== $right_active ) {
							return $left_active ? -1 : 1;
						}
						return strcasecmp( (string) ( $left->name ?? '' ), (string) ( $right->name ?? '' ) );
					}
				);
				echo '<input type="hidden" name="employee_order" value="' . esc_attr( implode( ',', $selected_employees ) ) . '" data-lc-team-order-input />';
				echo '<div class="lc-event-payments-list lc-event-team-list" data-lc-event-team-list data-lc-team-max="' . esc_attr( $multiple_team_locked ? 1 : 0 ) . '">';
				foreach ( $employees as $employee ) {
					$parts       = preg_split( '/\s+/', trim( $employee->name ) );
					$initials    = strtoupper( substr( $parts[0] ?? '', 0, 1 ) . substr( $parts[1] ?? '', 0, 1 ) );
					$color_class = self::avatar_color( $employee->name );
					$is_selected = in_array( (int) $employee->id, $selected_employees, true );
					$row_classes = 'lc-event-payment-row lc-event-team-row';
					if ( ! $multiple_team_locked ) {
						$row_classes .= ' is-draggable';
					}
					echo '<div class="' . esc_attr( $row_classes ) . '" data-lc-team-id="' . esc_attr( (int) $employee->id ) . '"' . ( $multiple_team_locked ? '' : ' draggable="true"' ) . '>';
					echo '<div>';
					echo '<div class="lc-event-payment-title">';
					echo '<span class="lc-drag-handle">⋮⋮</span>';
					echo '<span class="lc-event-team-avatar">';
					if ( ! empty( $employee->avatar_url ) ) {
						echo '<img src="' . esc_url( $employee->avatar_url ) . '" alt="' . esc_attr( $employee->name ) . '" />';
					} else {
						echo '<span class="lc-avatar lc-avatar-sm lc-avatar-badge ' . esc_attr( $color_class ) . '">' . esc_html( $initials ?: '•' ) . '</span>';
					}
					echo '</span>';
					echo '<span>' . esc_html( $employee->name ) . '</span>';
					echo '</div>';
					echo '<div class="lc-event-payment-sub">' . esc_html( $employee->email ) . '</div>';
					echo '</div>';
					echo '<div class="lc-event-payment-actions">';
					echo '<label class="lc-toggle-row lc-toggle-row-left">';
					echo '<span class="lc-switch"><input type="checkbox" name="employees[]" value="' . esc_attr( (int) $employee->id ) . '" data-lc-team-toggle ' . checked( $is_selected, true, false ) . '><span></span></span>';
					echo '<span data-lc-team-status>' . ( $is_selected ? esc_html__( 'Asignado', 'agenda-lite' ) : esc_html__( 'No asignado', 'agenda-lite' ) ) . '</span>';
					echo '</label>';
					echo '</div>';
					echo '</div>';
				}
				echo '</div>';
				if ( $multiple_team_locked ) {
					echo wp_kses_post( self::pro_feature_hint_html( __( 'Asignar múltiples profesionales por servicio está disponible en Agenda Lite Pro.', 'agenda-lite' ) ) );
				}
			} else {
				echo '<div class="lc-empty-state"><div>' . esc_html__( 'No hay usuarios con rol Gestor de reservas o Administrador.', 'agenda-lite' ) . '</div></div>';
			}
			echo '<div class="lc-section-footer">';
			echo '<a class="button" href="' . esc_url( Helpers::admin_url( 'employees' ) ) . '">' . esc_html__( 'Gestionar profesionales', 'agenda-lite' ) . '</a>';
			echo '<span>' . esc_html__( 'Selecciona quién atenderá este servicio.', 'agenda-lite' ) . '</span>';
			echo '</div>';
			echo '</div>';

			echo '<div class="lc-card" id="lc-custom-fields-section">';
			echo '<label>' . esc_html__( 'Campos personalizados', 'agenda-lite' ) . '</label>';
			$advanced_url = Helpers::admin_url( 'event' ) . '&event_id=' . $event->id . '&tab=advanced';
			if ( empty( $custom_fields ) ) {
				echo '<div class="lc-empty-state">';
				echo '<div>' . esc_html__( 'No hay campos personalizados.', 'agenda-lite' ) . '</div>';
				echo '</div>';
			} else {
				echo '<div class="lc-compact-list">';
				foreach ( $custom_fields as $field ) {
					if ( isset( $field['enabled'] ) && ! $field['enabled'] ) {
						continue;
					}
					$label    = $field['label'] ?? $field['key'];
					$required = ! empty( $field['required'] ) ? __( 'Requerido', 'agenda-lite' ) : __( 'Opcional', 'agenda-lite' );
					echo '<div class="lc-compact-row">';
					echo '<div><strong>' . esc_html( $label ) . '</strong><div class="description">' . esc_html( $required ) . '</div></div>';
					echo '</div>';
				}
				echo '</div>';
			}
			echo '<div class="lc-section-footer">';
			echo '<a class="button" href="' . esc_url( $advanced_url ) . '">' . esc_html__( 'Editar campos', 'agenda-lite' ) . '</a>';
			echo '<span>' . esc_html__( 'Configura preguntas personalizadas para tu servicio.', 'agenda-lite' ) . '</span>';
			echo '</div>';
			echo '</div>';
		}

		if ( $tab === 'code' ) {
			$event_url = trailingslashit( home_url( $event->slug ) );
			$iframe    = '<iframe src="' . esc_url( $event_url ) . '" width="100%" height="800" loading="lazy"></iframe>';
			echo '<div class="lc-card lc-code-card">';
			echo '<h3 class="lc-card-title">' . esc_html__( 'Código', 'agenda-lite' ) . '</h3>';
			echo '<p class="description">' . esc_html__( 'Copia y pega este código en tu sitio para mostrar el servicio.', 'agenda-lite' ) . '</p>';
			echo '<div class="lc-code-grid">';
			echo '<div class="lc-code-block">';
			echo '<label>' . esc_html__( 'Iframe', 'agenda-lite' ) . '</label>';
			echo '<p class="description">' . esc_html__( 'Copia y pega este código en HTML.', 'agenda-lite' ) . '</p>';
			echo '<textarea readonly rows="4">' . esc_textarea( $iframe ) . '</textarea>';
			echo '</div>';
			echo '</div>';
			echo '</div>';
		}

		if ( $tab === 'availability' ) {
			echo '<div class="lc-card">';
			$schedules         = self::ordered_schedules( (array) get_option( 'litecal_schedules', array() ) );
			$default_id        = get_option( 'litecal_default_schedule', 'default' );
			$options           = json_decode( $event->options ?: '[]', true );
			$selected_schedule = $options['schedule_id'] ?? $default_id;
			$selected_timezone = \LiteCal\Core\Helpers::site_timezone_name();
			echo '<div class="lc-availability-card">';
			echo '<div class="lc-availability-head">';
			echo '<div>';
			echo '<div class="lc-availability-title">' . esc_html__( 'Disponibilidad', 'agenda-lite' ) . '</div>';
			echo '<div class="lc-availability-sub">' . esc_html__( 'Horas laborales', 'agenda-lite' ) . '</div>';
			echo '</div>';
			echo '</div>';
			echo '<div class="lc-availability-select">';
			echo '<select name="schedule_id" class="lc-compact-select lc-availability-select-input" data-lc-schedule-select>';
			foreach ( $schedules as $sid => $schedule ) {
				$name = (string) ( $schedule['name'] ?? '' );
				if ( (string) $sid === (string) $default_id ) {
					$name .= ' (' . __( 'Predeterminado', 'agenda-lite' ) . ')';
				}
				echo '<option value="' . esc_attr( $sid ) . '" ' . selected( (string) $selected_schedule, (string) $sid, false ) . '>' . esc_html( $name ) . '</option>';
			}
			echo '</select>';
			echo '</div>';
			echo '<div class="lc-schedule-preview-list" data-lc-schedule-preview>';
			if ( ! empty( $schedules[ $selected_schedule ] ) ) {
				echo wp_kses_post( self::schedule_preview_list( $schedules[ $selected_schedule ] ) );
			}
			echo '</div>';
			echo '<div class="lc-availability-footer">';
			echo '<span><i class="ri-global-line"></i> ' . esc_html( $selected_timezone ) . ' · ' . esc_html( self::time_now_in_tz( $selected_timezone ) ) . '</span>';
			echo '<a class="button" href="' . esc_url( Helpers::admin_url( 'availability' ) ) . '">' . esc_html__( 'Editar disponibilidad', 'agenda-lite' ) . '</a>';
			echo '</div>';
			echo '</div>';
			echo '</div>';
		}

		if ( $tab === 'limits' ) {
			$gap_minutes = isset( $options['gap_between_bookings'] )
				? max( 0, (int) $options['gap_between_bookings'] )
				: max( 0, (int) $event->buffer_before, (int) $event->buffer_after );
			echo '<div class="lc-card">';
			echo '<label>' . esc_html__( 'Tiempo entre reservas', 'agenda-lite' ) . '</label>';
			echo '<p class="description">' . esc_html__( 'Minutos que se dejan entre una reserva y otra. Ej: termina 11:00 → siguiente desde 11:15.', 'agenda-lite' ) . '</p>';
			echo '<div class="lc-input-suffix"><input type="number" min="0" step="1" name="gap_between_bookings" value="' . esc_attr( $gap_minutes ) . '" /><span>' . esc_html__( 'Minutos', 'agenda-lite' ) . '</span></div>';
			echo '</div>';

			echo '<div class="lc-card">';
			echo '<label>' . esc_html__( 'Anticipación mínima para reservar', 'agenda-lite' ) . '</label>';
			echo '<p class="description">' . esc_html__( 'Evita reservas “para ya”. Define cuántas horas antes debe reservar el cliente como mínimo.', 'agenda-lite' ) . '</p>';
			echo '<div class="lc-input-suffix"><input type="number" name="notice_hours" value="' . esc_attr( $options['notice_hours'] ?? 2 ) . '" /><span>' . esc_html__( 'Horas', 'agenda-lite' ) . '</span></div>';
			echo '</div>';

			$booking_limits_locked = self::is_free_plan();
			echo '<div class="lc-card' . ( $booking_limits_locked ? ' lc-pro-lock' : '' ) . '">';
			echo '<label>' . esc_html__( 'Límite por día', 'agenda-lite' ) . '</label>';
			echo '<p class="description">' . esc_html__( 'Número máximo de reservas permitidas por día para este servicio.', 'agenda-lite' ) . '</p>';
			if ( $booking_limits_locked ) {
				echo wp_kses_post( self::pro_feature_hint_html( __( 'Disponible en Agenda Lite Pro.', 'agenda-lite' ) ) );
			}
			echo '<input type="number" name="limit_per_day" value="' . esc_attr( $options['limit_per_day'] ?? 0 ) . '"' . ( $booking_limits_locked ? ' disabled aria-disabled="true"' : '' ) . ' />';
			echo '</div>';

			echo '<div class="lc-card' . ( $booking_limits_locked ? ' lc-pro-lock' : '' ) . '">';
			echo '<label>' . esc_html__( 'Máx reservas futuras (días)', 'agenda-lite' ) . '</label>';
			echo '<p class="description">' . esc_html__( 'Cuántos días hacia adelante se pueden reservar desde hoy. Los días fuera del rango se deshabilitan.', 'agenda-lite' ) . '</p>';
			if ( $booking_limits_locked ) {
				echo wp_kses_post( self::pro_feature_hint_html( __( 'Disponible en Agenda Lite Pro.', 'agenda-lite' ) ) );
			}
			echo '<div class="lc-input-suffix"><input type="number" name="future_days" value="' . esc_attr( $options['future_days'] ?? 30 ) . '"' . ( $booking_limits_locked ? ' disabled aria-disabled="true"' : '' ) . ' /><span>' . esc_html__( 'Días', 'agenda-lite' ) . '</span></div>';
			echo '</div>';

		}

		if ( $tab === 'self_service' ) {
			$self_service_locked = self::is_free_plan();
			if ( $self_service_locked ) {
				$locked_options = is_array( $options ) ? $options : array();
				self::apply_free_event_self_service_options( $locked_options );
				$options = $locked_options;
			}
			$use_global_policies = array_key_exists( 'manage_use_global', $options )
				? ! empty( $options['manage_use_global'] )
				: ( ! array_key_exists( 'manage_override', $options ) || ! empty( $options['manage_override'] ) );
			if ( $self_service_locked ) {
				// In Free keep section expanded and visible for upsell clarity.
				$use_global_policies = false;
			}
			$manage_reschedule_enabled  = ! array_key_exists( 'manage_reschedule_enabled', $options ) || ! empty( $options['manage_reschedule_enabled'] );
			$manage_cancel_free_enabled = ! array_key_exists( 'manage_cancel_free_enabled', $options ) || ! empty( $options['manage_cancel_free_enabled'] );
			$manage_cancel_paid_enabled = ! empty( $options['manage_cancel_paid_enabled'] );
			echo '<div class="' . ( $self_service_locked ? 'lc-pro-lock' : '' ) . '">';
			echo '<div class="lc-card">';
			echo '<div class="lc-inline-title"><strong>' . esc_html__( 'Autogestión del cliente', 'agenda-lite' ) . '</strong></div>';
			echo '<p class="description">' . esc_html__( 'Configura cómo el cliente puede reagendar o cancelar desde el enlace del correo.', 'agenda-lite' ) . '</p>';
			echo '<label class="lc-toggle-row lc-toggle-row-left">';
			echo '<input type="hidden" name="manage_override" value="0" />';
			echo '<span class="lc-switch"><input type="checkbox" name="manage_override" value="1" data-lc-event-manage-override ' . ( $self_service_locked ? 'disabled aria-disabled="true" ' : '' ) . checked( true, $use_global_policies, false ) . '><span></span></span>';
			echo '<span>' . esc_html__( 'Usar políticas propias generales (Ajustes > Autogestión)', 'agenda-lite' ) . '</span>';
			echo '</label>';
			if ( $self_service_locked ) {
				echo wp_kses_post( self::pro_feature_hint_html( __( 'Disponible en Agenda Lite Pro.', 'agenda-lite' ) ) );
			}
			echo '</div>';

			$manage_cards_hidden = ( ! $self_service_locked && $use_global_policies );
			echo '<div data-lc-event-manage-cards' . ( $manage_cards_hidden ? ' style="display:none;"' : '' ) . '>';

			echo '<div class="lc-card">';
			echo '<div class="lc-inline-title"><strong>' . esc_html__( 'Permitir que el cliente reagende su reserva', 'agenda-lite' ) . '</strong></div>';
			echo '<p class="description">' . esc_html__( 'Activa esta opción para que el cliente pueda cambiar la fecha u hora desde el enlace “Reagendar” que recibe por correo. Si la desactivas, el cliente no podrá reagendar por su cuenta y deberá contactarte para cualquier cambio.', 'agenda-lite' ) . '</p>';
			echo '<label class="lc-check-row">';
			echo '<input type="hidden" name="manage_reschedule_enabled" value="0" />';
			echo '<input type="checkbox" name="manage_reschedule_enabled" value="1" ' . ( $self_service_locked ? 'disabled aria-disabled="true" ' : '' ) . checked( true, $manage_reschedule_enabled, false ) . ' />';
			echo '<span>' . esc_html__( 'Activar opción de reagendar por cliente', 'agenda-lite' ) . '</span>';
			echo '</label>';
			if ( $self_service_locked ) {
				echo wp_kses_post( self::pro_feature_hint_html( __( 'Disponible en Agenda Lite Pro.', 'agenda-lite' ) ) );
			}
			echo '</div>';

			echo '<div class="lc-card">';
			echo '<div class="lc-inline-title"><strong>' . esc_html__( 'Permitir cancelación del cliente en reservas gratis', 'agenda-lite' ) . '</strong></div>';
			echo '<p class="description">' . esc_html__( 'Si está activo, el cliente podrá cancelar reservas sin pago desde el enlace “Cancelar” del correo. Si lo desactivas, la cancelación solo podrá realizarla el administrador o soporte.', 'agenda-lite' ) . '</p>';
			echo '<label class="lc-check-row">';
			echo '<input type="hidden" name="manage_cancel_free_enabled" value="0" />';
			echo '<input type="checkbox" name="manage_cancel_free_enabled" value="1" ' . ( $self_service_locked ? 'disabled aria-disabled="true" ' : '' ) . checked( true, $manage_cancel_free_enabled, false ) . ' />';
			echo '<span>' . esc_html__( 'Activar cancelación en reservas gratis', 'agenda-lite' ) . '</span>';
			echo '</label>';
			if ( $self_service_locked ) {
				echo wp_kses_post( self::pro_feature_hint_html( __( 'Disponible en Agenda Lite Pro.', 'agenda-lite' ) ) );
			}
			echo '</div>';

			echo '<div class="lc-card">';
			echo '<div class="lc-inline-title"><strong>' . esc_html__( 'Permitir cancelación del cliente en reservas pagadas', 'agenda-lite' ) . '</strong></div>';
			echo '<p class="description">' . esc_html__( 'Si está activo, el cliente podrá cancelar reservas pagadas desde el enlace del correo. Esta opción solo controla el permiso de cancelación; la devolución de dinero o crédito se gestiona según tu política de pagos.', 'agenda-lite' ) . '</p>';
			echo '<label class="lc-check-row">';
			echo '<input type="hidden" name="manage_cancel_paid_enabled" value="0" />';
			echo '<input type="checkbox" name="manage_cancel_paid_enabled" value="1" ' . ( $self_service_locked ? 'disabled aria-disabled="true" ' : '' ) . checked( true, $manage_cancel_paid_enabled, false ) . ' />';
			echo '<span>' . esc_html__( 'Activar cancelación en reservas pagadas', 'agenda-lite' ) . '</span>';
			echo '</label>';
			if ( $self_service_locked ) {
				echo wp_kses_post( self::pro_feature_hint_html( __( 'Disponible en Agenda Lite Pro.', 'agenda-lite' ) ) );
			}
			echo '</div>';

			echo '<div class="lc-card">';
			echo '<div class="lc-inline-title"><strong>' . esc_html__( 'Cutoff para reagendar (horas)', 'agenda-lite' ) . '</strong></div>';
			echo '<p class="description">' . esc_html__( 'Define con cuánta anticipación mínima el cliente puede reagendar. Por ejemplo, si ingresas 12, el cliente podrá reagendar solo hasta 12 horas antes de la cita; dentro de ese plazo, el enlace se bloqueará para evitar cambios de último minuto.', 'agenda-lite' ) . '</p>';
			if ( $self_service_locked ) {
				echo wp_kses_post( self::pro_feature_hint_html( __( 'Disponible en Agenda Lite Pro.', 'agenda-lite' ) ) );
			}
			echo '<div class="lc-input-suffix"><input type="number" min="0" max="720" name="manage_reschedule_cutoff_hours" value="' . esc_attr( (int) ( $options['manage_reschedule_cutoff_hours'] ?? 12 ) ) . '"' . ( $self_service_locked ? ' disabled aria-disabled="true"' : '' ) . ' /><span>' . esc_html__( 'Horas', 'agenda-lite' ) . '</span></div>';
			echo '</div>';

			echo '<div class="lc-card">';
			echo '<div class="lc-inline-title"><strong>' . esc_html__( 'Cutoff para cancelar (horas)', 'agenda-lite' ) . '</strong></div>';
			echo '<p class="description">' . esc_html__( 'Define con cuánta anticipación mínima el cliente puede cancelar. Por ejemplo, si ingresas 24, el cliente podrá cancelar solo hasta 24 horas antes de la cita; luego el enlace quedará bloqueado y deberá contactarte.', 'agenda-lite' ) . '</p>';
			if ( $self_service_locked ) {
				echo wp_kses_post( self::pro_feature_hint_html( __( 'Disponible en Agenda Lite Pro.', 'agenda-lite' ) ) );
			}
			echo '<div class="lc-input-suffix"><input type="number" min="0" max="720" name="manage_cancel_cutoff_hours" value="' . esc_attr( (int) ( $options['manage_cancel_cutoff_hours'] ?? 24 ) ) . '"' . ( $self_service_locked ? ' disabled aria-disabled="true"' : '' ) . ' /><span>' . esc_html__( 'Horas', 'agenda-lite' ) . '</span></div>';
			echo '</div>';

			echo '<div class="lc-card">';
			echo '<div class="lc-inline-title"><strong>' . esc_html__( 'Máximo de reagendamientos (cambios)', 'agenda-lite' ) . '</strong></div>';
			echo '<p class="description">' . esc_html__( 'Indica cuántas veces se permite reagendar una misma reserva. Por ejemplo, si ingresas 1, el cliente podrá reagendar solo una vez; al intentar un segundo cambio, el sistema lo bloqueará automáticamente.', 'agenda-lite' ) . '</p>';
			if ( $self_service_locked ) {
				echo wp_kses_post( self::pro_feature_hint_html( __( 'Disponible en Agenda Lite Pro.', 'agenda-lite' ) ) );
			}
			echo '<div class="lc-input-suffix"><input type="number" min="0" max="20" name="manage_max_reschedules" value="' . esc_attr( (int) ( $options['manage_max_reschedules'] ?? 1 ) ) . '"' . ( $self_service_locked ? ' disabled aria-disabled="true"' : '' ) . ' /><span>' . esc_html__( 'Cambios', 'agenda-lite' ) . '</span></div>';
			echo '</div>';

			echo '<div class="lc-card">';
			echo '<div class="lc-inline-title"><strong>' . esc_html__( 'Cooldown entre cambios (minutos)', 'agenda-lite' ) . '</strong></div>';
			echo '<p class="description">' . esc_html__( 'Tiempo de espera obligatorio entre un reagendamiento y el siguiente, para evitar que el cliente esté probando horarios sin parar. Por ejemplo, con 10 minutos, después de reagendar deberá esperar 10 minutos antes de poder volver a intentar otro cambio (si aún tiene cambios disponibles).', 'agenda-lite' ) . '</p>';
			if ( $self_service_locked ) {
				echo wp_kses_post( self::pro_feature_hint_html( __( 'Disponible en Agenda Lite Pro.', 'agenda-lite' ) ) );
			}
			echo '<div class="lc-input-suffix"><input type="number" min="0" max="240" name="manage_cooldown_minutes" value="' . esc_attr( (int) ( $options['manage_cooldown_minutes'] ?? 10 ) ) . '"' . ( $self_service_locked ? ' disabled aria-disabled="true"' : '' ) . ' /><span>' . esc_html__( 'Min', 'agenda-lite' ) . '</span></div>';
			echo '</div>';

			echo '<div class="lc-card">';
			echo '<div class="lc-inline-title"><strong>' . esc_html__( 'Período de gracia (minutos)', 'agenda-lite' ) . '</strong></div>';
			echo '<p class="description">' . esc_html__( 'Ventana corta justo después de reservar para corregir errores (por ejemplo, si eligió mal el horario). Durante este período, el cliente podrá reagendar o cancelar aunque esté cerca del cutoff, y al finalizar se aplicarán las reglas normales.', 'agenda-lite' ) . '</p>';
			if ( $self_service_locked ) {
				echo wp_kses_post( self::pro_feature_hint_html( __( 'Disponible en Agenda Lite Pro.', 'agenda-lite' ) ) );
			}
			echo '<div class="lc-input-suffix"><input type="number" min="0" max="240" name="manage_grace_minutes" value="' . esc_attr( (int) ( $options['manage_grace_minutes'] ?? 15 ) ) . '"' . ( $self_service_locked ? ' disabled aria-disabled="true"' : '' ) . ' /><span>' . esc_html__( 'Min', 'agenda-lite' ) . '</span></div>';
			echo '</div>';

			echo '<div class="lc-card">';
			echo '<div class="lc-inline-title"><strong>' . esc_html__( 'Permitir cambio de profesional al reagendar', 'agenda-lite' ) . '</strong></div>';
			echo '<p class="description">' . esc_html__( 'Si está activo, al reagendar el cliente podrá elegir otro profesional disponible dentro del mismo servicio. Si lo desactivas, el cliente solo podrá reagendar manteniendo el mismo profesional para asegurar continuidad.', 'agenda-lite' ) . '</p>';
			echo '<label class="lc-check-row">';
			echo '<input type="hidden" name="manage_allow_change_staff" value="0" />';
			echo '<input type="checkbox" name="manage_allow_change_staff" value="1" ' . ( $self_service_locked ? 'disabled aria-disabled="true" ' : '' ) . checked( ! empty( $options['manage_allow_change_staff'] ), true, false ) . ' />';
			echo '<span>' . esc_html__( 'Activar cambio de profesional', 'agenda-lite' ) . '</span>';
			echo '</label>';
			if ( $self_service_locked ) {
				echo wp_kses_post( self::pro_feature_hint_html( __( 'Disponible en Agenda Lite Pro.', 'agenda-lite' ) ) );
			}
			echo '</div>';

			echo '</div>';
			echo '</div>';
		}

		if ( $tab === 'advanced' ) {
			$file_fields_locked = self::is_free_plan();
			echo '<div class="lc-card">';
			echo '<div class="lc-availability-title">' . esc_html__( 'Preguntas sobre la reserva', 'agenda-lite' ) . '</div>';
			echo '<div class="lc-availability-sub">' . esc_html__( 'Personaliza las preguntas que se hacen en la página de reservas.', 'agenda-lite' ) . '</div>';
			echo '<textarea name="custom_fields_json" data-lc-custom-fields-json style="display:none;">' . esc_textarea( $event->custom_fields ?: '[]' ) . '</textarea>';
			echo '<div class="lc-questions" data-lc-questions-list>';
			echo '<div class="lc-question-row is-locked">';
			echo '<div>';
			echo '<div class="lc-question-title">' . esc_html__( 'Nombre', 'agenda-lite' ) . '</div>';
			echo '<div class="lc-question-sub">' . esc_html__( 'Campo obligatorio del sistema', 'agenda-lite' ) . '</div>';
			echo '</div>';
			echo '<div class="lc-question-actions"><span class="lc-locked"><i class="ri-lock-2-line"></i> ' . esc_html__( 'Bloqueado', 'agenda-lite' ) . '</span></div>';
			echo '</div>';
			echo '<div class="lc-question-row is-locked">';
			echo '<div>';
			echo '<div class="lc-question-title">' . esc_html__( 'Apellido', 'agenda-lite' ) . '</div>';
			echo '<div class="lc-question-sub">' . esc_html__( 'Campo obligatorio del sistema', 'agenda-lite' ) . '</div>';
			echo '</div>';
			echo '<div class="lc-question-actions"><span class="lc-locked"><i class="ri-lock-2-line"></i> ' . esc_html__( 'Bloqueado', 'agenda-lite' ) . '</span></div>';
			echo '</div>';
			echo '<div class="lc-question-row is-locked">';
			echo '<div>';
			echo '<div class="lc-question-title">' . esc_html__( 'Email', 'agenda-lite' ) . '</div>';
			echo '<div class="lc-question-sub">' . esc_html__( 'Campo obligatorio del sistema', 'agenda-lite' ) . '</div>';
			echo '</div>';
			echo '<div class="lc-question-actions"><span class="lc-locked"><i class="ri-lock-2-line"></i> ' . esc_html__( 'Bloqueado', 'agenda-lite' ) . '</span></div>';
			echo '</div>';
			echo '<div class="lc-question-row is-locked">';
			echo '<div>';
			echo '<div class="lc-question-title">' . esc_html__( 'Teléfono', 'agenda-lite' ) . '</div>';
			echo '<div class="lc-question-sub">' . esc_html__( 'Campo obligatorio del sistema', 'agenda-lite' ) . '</div>';
			echo '</div>';
			echo '<div class="lc-question-actions"><span class="lc-locked"><i class="ri-lock-2-line"></i> ' . esc_html__( 'Bloqueado', 'agenda-lite' ) . '</span></div>';
			echo '</div>';
			if ( ! empty( $custom_fields ) ) {
				foreach ( $custom_fields as $field ) {
					$key        = $field['key'] ?? '';
					$label      = $field['label'] ?? $key;
					$type       = $field['type'] ?? 'short_text';
					$required   = ! empty( $field['required'] );
					$enabled    = ! isset( $field['enabled'] ) || $field['enabled'];
					$type_label = 'Short Text';
					if ( $type === 'long_text' ) {
						$type_label = 'Long Text';
					} elseif ( $type === 'select' ) {
						$type_label = 'Select';
					} elseif ( $type === 'multiselect' ) {
						$type_label = 'MultiSelect';
					} elseif ( $type === 'checkbox_group' ) {
						$type_label = 'Checkbox Group';
					} elseif ( $type === 'radio_group' ) {
						$type_label = 'Radio Group';
					} elseif ( $type === 'checkbox' ) {
						$type_label = 'Checkbox';
					} elseif ( $type === 'url' ) {
						$type_label = 'URL';
					} elseif ( $type === 'number' ) {
						$type_label = 'Number';
					} elseif ( $type === 'address' ) {
						$type_label = 'Address';
					} elseif ( $type === 'multiple_emails' ) {
						$type_label = 'Multiple Emails';
					} elseif ( $type === 'email' ) {
						$type_label = 'Email';
					} elseif ( $type === 'phone' ) {
						$type_label = 'Phone';
					} elseif ( $type === 'file' ) {
						$type_label = 'Adjuntar archivo';
					}
					$file_row_locked = $file_fields_locked && $type === 'file';
					$row_classes     = 'lc-question-row is-draggable' . ( $file_row_locked ? ' lc-pro-lock' : '' );
					echo '<div class="' . esc_attr( $row_classes ) . '" data-lc-field-key="' . esc_attr( $key ) . '"' . ( $file_row_locked ? '' : ' draggable="true"' ) . '>';
					echo '<div>';
					echo '<div class="lc-question-title"><span class="lc-drag-handle">⋮⋮</span>' . esc_html( $label ) . '</div>';
					if ( $file_row_locked ) {
						echo '<div class="lc-question-sub"><em>' . esc_html__( 'Actualiza a Pro para activar está función', 'agenda-lite' ) . '</em></div>';
					} else {
						echo '<div class="lc-question-sub">' . esc_html( $type_label ) . '</div>';
					}
					echo '</div>';
					echo '<div class="lc-question-actions">';
					if ( $file_row_locked ) {
						echo wp_kses_post( self::pro_upgrade_link_html( __( 'Actualiza a Pro para activar está función', 'agenda-lite' ) ) );
					} else {
						echo '<label class="lc-toggle-row lc-toggle-row-left">';
						echo '<span class="lc-switch"><input type="checkbox" data-lc-field-toggle ' . checked( true, $enabled, false ) . '><span></span></span>';
						echo '<span data-lc-field-status>' . ( $enabled ? esc_html__( 'Mostrar', 'agenda-lite' ) : esc_html__( 'Oculto', 'agenda-lite' ) ) . '</span>';
						echo '</label>';
						echo '<button type="button" class="button" data-lc-field-edit>' . esc_html__( 'Editar', 'agenda-lite' ) . '</button>';
						echo '<button type="button" class="button button-link-delete" data-lc-field-delete>' . esc_html__( 'Eliminar', 'agenda-lite' ) . '</button>';
					}
					echo '</div>';
					echo '</div>';
				}
			} else {
				echo '<div class="lc-empty-row">' . esc_html__( 'No hay campos personalizados.', 'agenda-lite' ) . '</div>';
			}
			echo '</div>';
			echo '<div class="lc-section-footer">';
			echo '<button type="button" class="button" data-lc-add-question>' . esc_html__( '+ Agregar una pregunta', 'agenda-lite' ) . '</button>';
			echo '<span>' . esc_html__( 'Define qué información debe entregar el cliente.', 'agenda-lite' ) . '</span>';
			echo '</div>';
			echo '<div class="lc-question-modal" data-lc-question-modal hidden>';
			echo '<div class="lc-question-modal-backdrop" data-lc-question-close></div>';
			echo '<div class="lc-question-modal-card">';
			echo '<div class="lc-question-modal-title">' . esc_html__( 'Agregar una pregunta', 'agenda-lite' ) . '</div>';
			echo '<label>' . esc_html__( 'Tipo de entrada', 'agenda-lite' ) . '</label>';
			echo '<select data-lc-question-type class="lc-compact-select">';
			echo '<option value="short_text">Short Text</option>';
			echo '<option value="long_text">Long Text</option>';
			echo '<option value="select">Select</option>';
			echo '<option value="multiselect">MultiSelect</option>';
			echo '<option value="checkbox_group">Checkbox Group</option>';
			echo '<option value="radio_group">Radio Group</option>';
			echo '<option value="checkbox">Checkbox</option>';
			echo '<option value="url">URL</option>';
			echo '<option value="number">Number</option>';
			echo '<option value="address">Address</option>';
			echo '<option value="multiple_emails">Multiple Emails</option>';
			echo '<option value="email">Email</option>';
			echo '<option value="phone">Phone</option>';
			if ( $file_fields_locked ) {
				echo '<option value="file" disabled>' . esc_html__( 'Adjuntar archivos (Actualiza a Pro para activar está función)', 'agenda-lite' ) . '</option>';
			} else {
				echo '<option value="file">' . esc_html__( 'Adjuntar archivo', 'agenda-lite' ) . '</option>';
			}
			echo '</select>';
			echo '<label>' . esc_html__( 'Identificador', 'agenda-lite' ) . '</label><input data-lc-question-key placeholder="' . esc_attr__( 'identificador', 'agenda-lite' ) . '" />';
			echo '<small class="lc-help">' . esc_html__( 'Si lo dejas vacío, se genera desde la etiqueta.', 'agenda-lite' ) . '</small>';
			echo '<label>' . esc_html__( 'Etiqueta', 'agenda-lite' ) . '</label><input data-lc-question-label placeholder="' . esc_attr__( 'Etiqueta', 'agenda-lite' ) . '" />';
			echo '<label data-lc-question-options-label>' . esc_html__( 'Opciones', 'agenda-lite' ) . '</label>';
			echo '<div class="lc-question-options" data-lc-question-options-wrap>';
			echo '<div class="lc-question-options-list" data-lc-question-options-list></div>';
			echo '<button type="button" class="button" data-lc-question-options-add>' . esc_html__( '+ Agregar opción', 'agenda-lite' ) . '</button>';
			echo '</div>';
			echo '<div class="lc-question-file" data-lc-question-file style="display:none;">';
			echo '<label>' . esc_html__( 'Tipos de archivo permitidos', 'agenda-lite' ) . '</label>';
			echo '<div class="lc-file-types">';
			echo '<label class="lc-checkbox"><input type="checkbox" value="pdf" data-lc-file-type /> PDF</label>';
			echo '<label class="lc-checkbox"><input type="checkbox" value="images" data-lc-file-type /> ' . esc_html__( 'Imágenes (JPG, PNG, WEBP)', 'agenda-lite' ) . '</label>';
			echo '<label class="lc-checkbox"><input type="checkbox" value="docs" data-lc-file-type /> ' . esc_html__( 'Documentos (DOC, DOCX)', 'agenda-lite' ) . '</label>';
			echo '<label class="lc-checkbox"><input type="checkbox" value="excel" data-lc-file-type /> ' . esc_html__( 'Excel (XLS, XLSX)', 'agenda-lite' ) . '</label>';
			echo '<label class="lc-checkbox"><input type="checkbox" value="zip" data-lc-file-type /> ' . esc_html__( 'Comprimidos (ZIP)', 'agenda-lite' ) . '</label>';
			echo '<label class="lc-checkbox"><input type="checkbox" value="other" data-lc-file-type /> ' . esc_html__( 'Otros', 'agenda-lite' ) . '</label>';
			echo '</div>';
			echo '<label>' . esc_html__( 'Tipos personalizados (extensiones o MIME)', 'agenda-lite' ) . '</label>';
			echo '<input data-lc-file-custom placeholder="' . esc_attr__( 'pdf,jpg,png o application/pdf', 'agenda-lite' ) . '" />';
			echo '<label>' . esc_html__( 'Tamaño máximo', 'agenda-lite' ) . '</label>';
			echo '<div class="lc-input-suffix"><input type="number" min="1" data-lc-file-max value="5" /><span>MB</span></div>';
			echo '<label>' . esc_html__( 'Cantidad de archivos', 'agenda-lite' ) . '</label>';
			echo '<div class="lc-input-suffix"><input type="number" min="1" data-lc-file-count value="1" /><span>' . esc_html__( 'archivos', 'agenda-lite' ) . '</span></div>';
			echo '<label>' . esc_html__( 'Texto de ayuda (opcional)', 'agenda-lite' ) . '</label>';
			echo '<input data-lc-file-help placeholder="' . esc_attr__( 'Adjunta tu comprobante o documento', 'agenda-lite' ) . '" />';
			echo '</div>';
			echo '<div class="lc-question-error" data-lc-question-error hidden>' . esc_html__( 'Debes agregar al menos una opción.', 'agenda-lite' ) . '</div>';
			echo '<label class="lc-checkbox"><input type="checkbox" data-lc-question-required /> ' . esc_html__( 'Hacer que este campo sea obligatorio', 'agenda-lite' ) . '</label>';
			echo '<div class="lc-question-modal-actions">';
			echo '<button type="button" class="button" data-lc-question-close>' . esc_html__( 'Cancelar', 'agenda-lite' ) . '</button>';
			echo '<button type="button" class="button button-primary" data-lc-question-save>' . esc_html__( 'Guardar', 'agenda-lite' ) . '</button>';
			echo '</div>';
			echo '</div>';
			echo '</div>';
			echo '</div>';
		}

		if ( $tab === 'extras' ) {
			$settings       = get_option( 'litecal_settings', array() );
			$currency       = $event->currency ?: ( $settings['currency'] ?? 'CLP' );
			$extras_enabled = self::event_extras_enabled();
			$extras_config  = self::event_extras_config( $options, $currency, true );
			$hours_config   = $extras_config['hours'] ?? array();
			$hours_enabled  = ! empty( $hours_config['enabled'] );

			if ( ! $extras_enabled ) {
				echo '<div class="lc-card lc-pro-lock">';
				echo '<div class="lc-inline-title"><strong>' . esc_html__( 'Extras', 'agenda-lite' ) . '</strong></div>';
				echo '<p class="description">' . esc_html__( 'Configura servicios adicionales, horas extra y suma automática al total de la reserva.', 'agenda-lite' ) . '</p>';
				echo wp_kses_post( self::pro_lock_note_html( __( 'Esta función está incluida en Agenda Lite Pro.', 'agenda-lite' ) ) );
				echo '</div>';
			} else {
				echo '<div class="lc-card" data-lc-extras-root data-lc-extras-currency="' . esc_attr( $currency ) . '">';
				echo '<div class="lc-inline-title"><strong>' . esc_html__( 'Servicios extra', 'agenda-lite' ) . '</strong></div>';
				echo '<p class="description">' . esc_html__( 'Añade opciones con nombre, precio e imagen opcional. El cliente podrá seleccionarlas en el frontend.', 'agenda-lite' ) . '</p>';
				echo '<textarea name="extras_items_json" data-lc-extras-json style="display:none;">' . esc_textarea( wp_json_encode( $extras_config['items'] ) ) . '</textarea>';
				echo '<div class="lc-event-payments-list" data-lc-extras-list></div>';
				echo '<div class="lc-section-footer">';
				echo '<button type="button" class="button" data-lc-extra-add><i class="ri-add-line"></i> ' . esc_html__( 'Añadir extra', 'agenda-lite' ) . '</button>';
				echo '<span>' . esc_html__( 'Los precios se suman al total antes de confirmar la reserva.', 'agenda-lite' ) . '</span>';
				echo '</div>';
				echo '</div>';

				echo '<div class="lc-card" data-lc-extra-hours-root>';
				echo '<div class="lc-inline-title"><strong>' . esc_html__( 'Horas extra', 'agenda-lite' ) . '</strong></div>';
				echo '<p class="description">' . esc_html__( 'Define bloques de tiempo adicional con costo automático por bloque.', 'agenda-lite' ) . '</p>';
				echo '<label class="lc-toggle-row lc-toggle-row-left">';
				echo '<input type="hidden" name="extras_hours_enabled" value="0" />';
				echo '<span class="lc-switch"><input type="checkbox" name="extras_hours_enabled" value="1" data-lc-extras-hours-toggle ' . checked( true, $hours_enabled, false ) . '><span></span></span>';
				echo '<span>' . esc_html__( 'Activar horas extra', 'agenda-lite' ) . '</span>';
				echo '</label>';
				echo '<div data-lc-extras-hours-fields class="lc-extra-hours-fields' . ( $hours_enabled ? '' : ' is-hidden' ) . '">';
				echo '<div class="lc-extra-hours-grid">';
				echo '<label class="lc-extra-hours-field"><span>' . esc_html__( 'Nombre visible', 'agenda-lite' ) . '</span>';
				echo '<input name="extras_hours_label" value="' . esc_attr( $hours_config['label'] ?? __( 'Horas extra', 'agenda-lite' ) ) . '" /></label>';
				echo '<label class="lc-extra-hours-field"><span>' . esc_html__( 'Intervalo por bloque', 'agenda-lite' ) . '</span>';
				echo '<div class="lc-input-suffix"><input type="number" min="5" max="240" step="5" name="extras_hours_interval_minutes" value="' . esc_attr( (int) ( $hours_config['interval_minutes'] ?? 15 ) ) . '" /><span>' . esc_html__( 'Minutos', 'agenda-lite' ) . '</span></div></label>';
				echo '<label class="lc-extra-hours-field"><span>' . esc_html__( 'Precio por bloque', 'agenda-lite' ) . '</span>';
				echo '<div class="lc-price-input lc-price-input-row">';
				echo '<span class="lc-price-prefix">' . esc_html( strtoupper( $currency ) ) . '</span>';
				echo '<input name="extras_hours_price_per_interval" value="' . esc_attr( self::format_money( (float) ( $hours_config['price_per_interval'] ?? 0 ), $currency ) ) . '" placeholder="' . esc_attr( self::currency_meta( $currency )['example'] ) . '" />';
				echo '</div></label>';
				echo '<label class="lc-extra-hours-field"><span>' . esc_html__( 'Máximo de bloques', 'agenda-lite' ) . '</span>';
				echo '<div class="lc-input-suffix"><input type="number" min="1" max="96" step="1" name="extras_hours_max_units" value="' . esc_attr( (int) ( $hours_config['max_units'] ?? 8 ) ) . '" /><span>' . esc_html__( 'Bloques', 'agenda-lite' ) . '</span></div></label>';
				echo '<label class="lc-extra-hours-field"><span>' . esc_html__( 'Tipo de selector en frontend', 'agenda-lite' ) . '</span>';
				echo '<select name="extras_hours_selector" class="lc-full-select">';
				echo '<option value="select" ' . selected( 'select', (string) ( $hours_config['selector'] ?? 'select' ), false ) . '>' . esc_html__( 'Menú desplegable', 'agenda-lite' ) . '</option>';
				echo '<option value="stepper" ' . selected( 'stepper', (string) ( $hours_config['selector'] ?? 'select' ), false ) . '>' . esc_html__( 'Selector de cantidad', 'agenda-lite' ) . '</option>';
				echo '</select></label>';
				echo '</div>';
				echo '</div>';
				echo '</div>';
			}
		}

		echo '</form>';
		echo '</div>';
		echo '</div>';
		echo '</div>';
		self::shell_end();
	}

	public static function employees() {
		if ( ! self::can_access_staff_panel() ) {
			wp_die( esc_html__( 'Unauthorized', 'agenda-lite' ) );
		}
		self::refresh_all_event_statuses();
		Employees::sync_booking_manager_users( true );
		$employees         = Employees::all_booking_managers( true );
		$scope_employee_id = self::current_user_employee_id();
		$staff_scoped      = $scope_employee_id > 0 && ! self::can_manage();
		if ( $staff_scoped ) {
			$employees = array_values(
				array_filter(
					(array) $employees,
					static function ( $employee ) use ( $scope_employee_id ) {
						return (int) ( $employee->id ?? 0 ) === $scope_employee_id;
					}
				)
			);
		}
		$employees = self::ordered_employees( (array) $employees );
		$editing   = null;
		$show_form = false;
		if ( ! empty( $_GET['edit'] ) && $_GET['edit'] !== 'new' ) {
			$candidate = Employees::get( (int) $_GET['edit'] );
			if ( $candidate && (int) ( $candidate->wp_user_id ?? 0 ) > 0 && ( ! $staff_scoped || (int) $candidate->id === $scope_employee_id ) ) {
				$editing   = $candidate;
				$show_form = true;
			}
		}

		self::shell_start( 'employees' );
		echo '<div class="lc-admin">';
		echo '<div class="lc-header-row">';
		echo '<div><h1>' . esc_html__( 'Profesionales', 'agenda-lite' ) . '</h1><p class="description">' . esc_html__( 'Este listado se sincroniza automáticamente con Usuarios de WordPress.', 'agenda-lite' ) . '</p></div>';
		echo '<div class="lc-header-actions">';
		$professional_limit_reached = self::free_limit_reached( 'professionals' );
		if ( $show_form && $editing ) {
			echo '<a class="button" href="' . esc_url( Helpers::admin_url( 'employees' ) ) . '">' . esc_html__( 'Volver al listado', 'agenda-lite' ) . '</a>';
		} elseif ( ! $staff_scoped ) {
				$users_url       = admin_url( 'users.php' );
				$create_user_url = admin_url( 'user-new.php' );
				echo '<a class="button" href="' . esc_url( $users_url ) . '">' . esc_html__( 'Ver usuarios', 'agenda-lite' ) . '</a>';
			if ( self::is_free_plan() && $professional_limit_reached ) {
				echo wp_kses_post( self::pro_upgrade_disabled_button_html( __( 'Actualiza a Pro para desbloquear funciones premium', 'agenda-lite' ), 'button button-primary' ) );
			} else {
				echo '<a class="button button-primary" href="' . esc_url( $create_user_url ) . '">' . esc_html__( '+ Nuevo usuario', 'agenda-lite' ) . '</a>';
			}
		}
		echo '</div>';
		echo '</div>';

		if ( $show_form && $editing ) {
			$wp_user_id       = (int) ( $editing->wp_user_id ?? 0 );
			$wp_user          = $wp_user_id > 0 ? get_userdata( $wp_user_id ) : null;
			$readonly_name    = $wp_user ? (string) $wp_user->display_name : (string) $editing->name;
			$readonly_email   = $wp_user ? (string) $wp_user->user_email : (string) $editing->email;
			$vacations_locked = self::is_free_plan();

			echo '<div class="lc-detail-header">';
			echo '<div class="lc-detail-title">' . esc_html__( 'Vacaciones', 'agenda-lite' ) . '</div>';
			echo '<div class="lc-detail-actions lc-employee-form-actions">';
			if ( $wp_user_id > 0 ) {
				echo '<a class="button" href="' . esc_url( add_query_arg( array( 'user_id' => $wp_user_id ), admin_url( 'user-edit.php' ) ) ) . '">' . esc_html__( 'Editar usuario', 'agenda-lite' ) . '</a>';
			}
			echo '<a class="button" href="' . esc_url( Helpers::admin_url( 'employees' ) ) . '">' . esc_html__( 'Volver al listado', 'agenda-lite' ) . '</a>';
			echo '</div>';
			echo '</div>';
			echo '<div class="lc-panel lc-employee-timeoff' . ( $vacations_locked ? ' lc-pro-lock' : '' ) . '">';
			echo '<p class="description"><strong>' . esc_html( $readonly_name ) . '</strong> · ' . esc_html( $readonly_email ) . '</p>';
			if ( $vacations_locked ) {
				echo wp_kses_post( self::pro_upgrade_disabled_button_html( __( 'Actualiza a Pro para gestionar vacaciones', 'agenda-lite' ) ) );
			} else {
				echo '<h3>' . esc_html__( 'Feriados / Vacaciones', 'agenda-lite' ) . '</h3>';
				$timeoff_ranges = array();
				global $wpdb;
				$table = $wpdb->prefix . 'litecal_time_off';
					// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name comes from trusted $wpdb->prefix.
					$rows = $wpdb->get_results(
						$wpdb->prepare(
							"SELECT id, start_date, end_date, reason FROM {$table} WHERE scope = 'employee' AND scope_id = %d ORDER BY id DESC",
							(int) $editing->id
						)
					);
					// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$seen_timeoff_ranges = array();
				foreach ( $rows as $row ) {
					$range_key = (string) $row->start_date . '|' . (string) $row->end_date;
					if ( isset( $seen_timeoff_ranges[ $range_key ] ) ) {
						continue;
					}
					$seen_timeoff_ranges[ $range_key ] = true;
					$reason = trim( (string) ( $row->reason ?? '' ) );
					$type   = stripos( $reason, 'Feriado' ) === 0 ? 'feriado' : 'vacaciones';
					$timeoff_ranges[] = array(
						'start' => $row->start_date,
						'end'   => $row->end_date,
						'type'  => $type,
					);
				}
				$timeoff_json = wp_json_encode( $timeoff_ranges );
				echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
				wp_nonce_field( 'litecal_save_time_off' );
				echo '<input type="hidden" name="action" value="litecal_save_time_off" />';
				echo '<input type="hidden" name="scope" value="employee" />';
				echo '<input type="hidden" name="scope_employee_id" value="' . esc_attr( (int) $editing->id ) . '" />';
				echo '<input type="hidden" name="start_date" data-lc-range-start />';
				echo '<input type="hidden" name="end_date" data-lc-range-end />';
				echo '<input type="hidden" name="action_mode" data-lc-range-mode value="activate" />';
				echo '<label>Tipo</label>';
				echo '<select name="reason_type" data-lc-range-type>';
				echo '<option value="feriado">Feriado</option>';
				echo '<option value="vacaciones">Vacaciones</option>';
				echo '</select>';
				echo '<label>Motivo (opcional)</label><input name="reason" data-lc-range-reason />';
				echo '<div class="lc-range-calendar" data-lc-range-calendar data-lc-timeoff-ranges=\'' . esc_attr( $timeoff_json ) . '\'>';
				echo '<div class="lc-cal-nav lc-range-nav">';
				echo '<button type="button" class="lc-cal-nav-btn" data-lc-range-prev aria-label="' . esc_attr__( 'Anterior', 'agenda-lite' ) . '"><i class="ri-arrow-left-line"></i></button>';
				echo '<button type="button" class="lc-cal-nav-btn" data-lc-range-next aria-label="' . esc_attr__( 'Siguiente', 'agenda-lite' ) . '"><i class="ri-arrow-right-line"></i></button>';
				echo '<span class="lc-cal-range lc-range-label" data-lc-range-label></span>';
				echo '</div>';
				echo '<div class="lc-calendar-grid lc-range-grid" data-lc-range-grid></div>';
				echo '</div>';
				echo '<div class="lc-range-message" data-lc-range-message>Selecciona un rango de fechas.</div>';
				echo '<div class="lc-timeoff-actions">';
				echo '<button class="lc-btn lc-next" type="submit" data-lc-range-toggle disabled>Activar Feriados / Vacaciones</button>';
				echo '</div>';
				echo '</form>';
			}
			echo '</div>';
		} else {
			echo '<div class="lc-panel lc-event-list lc-list-shell">';
			echo '<div class="lc-event-list-header"><span>' . esc_html__( 'Profesionales sincronizados', 'agenda-lite' ) . '</span><small>' . esc_html__( 'Arrastra y suelta para reordenar el listado.', 'agenda-lite' ) . '</small></div>';
			$active_timeoff = array();
			global $wpdb;
			$table = $wpdb->prefix . 'litecal_time_off';
			$today = current_time( 'Y-m-d' );
			// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name comes from trusted $wpdb->prefix and values are prepared.
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT id, scope_id, start_date, end_date, reason
					FROM {$table}
					WHERE scope = 'employee'
					  AND end_date >= %s
					ORDER BY
					  CASE
						WHEN start_date <= %s AND end_date >= %s THEN 0
						ELSE 1
					  END ASC,
					  start_date ASC,
					  id DESC",
					$today,
					$today,
					$today
				)
			);
			// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			foreach ( $rows as $row ) {
				$scope_id = (int) ( $row->scope_id ?? 0 );
				if ( $scope_id <= 0 || isset( $active_timeoff[ $scope_id ] ) ) {
					continue;
				}
				$start_date = (string) ( $row->start_date ?? '' );
				$end_date   = (string) ( $row->end_date ?? '' );
				if ( $start_date === '' || $end_date === '' || $end_date < $today ) {
					continue;
				}
				$reason       = trim( (string) ( $row->reason ?? '' ) );
				$is_feriado   = stripos( $reason, 'Feriado' ) === 0;
				$reason_extra = $is_feriado
					? trim( preg_replace( '/^Feriado:?\s*/i', '', $reason ) )
					: trim( preg_replace( '/^Vacaciones:?\s*/i', '', $reason ) );
				if ( $reason_extra === 'Feriado' || $reason_extra === 'Vacaciones' ) {
					$reason_extra = '';
				}
				$active_timeoff[ $scope_id ] = array(
					'type'         => $is_feriado ? 'feriado' : 'vacaciones',
					'type_label'   => $is_feriado ? __( 'Feriado', 'agenda-lite' ) : __( 'Vacaciones', 'agenda-lite' ),
					'reason'       => $reason,
					'reason_extra' => $reason_extra,
					'start_date'   => $start_date,
					'end_date'     => $end_date,
				);
			}

			if ( empty( $employees ) ) {
				echo '<div class="lc-empty-state"><div>' . esc_html__( 'No hay usuarios con rol Gestor de reservas o Administrador.', 'agenda-lite' ) . '</div></div>';
			} else {
				$can_drag = count( $employees ) > 1;
				echo '<div data-lc-employee-order-list>';
				foreach ( $employees as $employee ) {
					$parts           = preg_split( '/\s+/', trim( (string) $employee->name ) );
					$initials        = strtoupper( substr( $parts[0] ?? '', 0, 1 ) . substr( $parts[1] ?? '', 0, 1 ) );
					$color_class     = self::avatar_color( (string) $employee->name );
					$user_id         = (int) ( $employee->wp_user_id ?? 0 );
					$edit_user_url   = $user_id > 0 ? add_query_arg( array( 'user_id' => $user_id ), admin_url( 'user-edit.php' ) ) : '';
					$edit_member_url = Helpers::admin_url( 'employees' ) . '&edit=' . (int) $employee->id;
					$wp_user         = $user_id > 0 ? get_userdata( $user_id ) : null;
					$is_admin        = $wp_user && in_array( 'administrator', (array) ( $wp_user->roles ?? array() ), true );
					$role_label      = $is_admin ? __( 'Administrador', 'agenda-lite' ) : __( 'Gestor de reservas', 'agenda-lite' );
					$status_text     = __( 'Disponible', 'agenda-lite' );
					$status_class    = 'is-active';
					$timeoff_label   = '';
					$reason_extra    = '';
					if ( isset( $active_timeoff[ $employee->id ] ) ) {
						$active_data   = (array) $active_timeoff[ $employee->id ];
						$status_text   = __( 'No disponible', 'agenda-lite' );
						$status_class  = 'is-inactive';
						$timeoff_label = (string) ( $active_data['type_label'] ?? '' );
						$reason_extra  = (string) ( $active_data['reason_extra'] ?? '' );
					}
					$row_classes = 'lc-event-item lc-staff-list-item';
					if ( $can_drag ) {
						$row_classes .= ' is-draggable';
					}
					echo '<div class="' . esc_attr( $row_classes ) . '" data-lc-employee-row data-lc-employee-id="' . esc_attr( (int) $employee->id ) . '"' . ( $can_drag ? ' draggable="true"' : '' ) . '>';
					echo '<div class="lc-event-info">';
					echo '<div class="lc-event-title-row">';
					if ( $can_drag ) {
						echo '<span class="lc-drag-handle" title="' . esc_attr__( 'Arrastrar para ordenar', 'agenda-lite' ) . '">⋮⋮</span>';
					}
					if ( ! empty( $employee->avatar_url ) ) {
						echo '<span class="lc-employee-avatar lc-staff-list-avatar"><img src="' . esc_url( $employee->avatar_url ) . '" alt="' . esc_attr( $employee->name ) . '" /></span>';
					} else {
						echo '<span class="lc-employee-avatar lc-staff-list-avatar lc-avatar-badge ' . esc_attr( $color_class ) . '">' . esc_html( $initials ?: '•' ) . '</span>';
					}
					echo '<div class="lc-staff-list-head"><span class="lc-event-dot ' . esc_attr( $status_class ) . '"><span class="dot"></span></span><span class="lc-event-title-stack"><span class="lc-event-title">' . esc_html( $employee->name ) . '</span>';
					if ( ! empty( $employee->email ) ) {
						echo '<span class="lc-event-inline-sub">' . esc_html( $employee->email ) . '</span>';
					}
					echo '</span></div>';
					echo '<span class="lc-event-tag">' . esc_html( $status_text ) . '</span>';
					echo '<span class="lc-event-tag">' . esc_html( $role_label ) . '</span>';
					if ( $timeoff_label !== '' ) {
						echo '<span class="lc-event-tag">' . esc_html( $timeoff_label ) . '</span>';
					}
					if ( $reason_extra !== '' ) {
						echo '<span class="lc-event-tag">' . esc_html( $reason_extra ) . '</span>';
					}
					echo '</div>';
					echo '</div>';
					echo '<div class="lc-event-actions lc-event-actions--stack">';
					if ( self::is_free_plan() ) {
						echo wp_kses_post( self::pro_upgrade_disabled_button_html( __( 'Actualiza a Pro para gestionar vacaciones', 'agenda-lite' ) ) );
					} else {
						echo '<a class="button" href="' . esc_url( $edit_member_url ) . '">' . esc_html__( 'Vacaciones', 'agenda-lite' ) . '</a>';
					}
					if ( $edit_user_url !== '' ) {
						echo '<a class="button" href="' . esc_url( $edit_user_url ) . '">' . esc_html__( 'Editar usuario', 'agenda-lite' ) . '</a>';
					}
					echo '</div>';
					echo '</div>';
				}
				echo '</div>';
				if ( $can_drag ) {
					echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" data-lc-employee-order-form>';
					wp_nonce_field( 'litecal_save_employee_order' );
					echo '<input type="hidden" name="action" value="litecal_save_employee_order" />';
					echo '<input type="hidden" name="ids" value="" data-lc-employee-order-ids />';
					echo '</form>';
				}
			}
			echo '</div>';
		}

		echo '</div>';
		self::shell_end();
	}

	private static function customer_name_parts_from_booking( $booking ) {
		$snapshot   = array();
		$raw        = '';
		if ( is_object( $booking ) && property_exists( $booking, 'latest_snapshot' ) ) {
			$raw = (string) $booking->latest_snapshot;
		} elseif ( is_array( $booking ) && isset( $booking['latest_snapshot'] ) ) {
			$raw = (string) $booking['latest_snapshot'];
		} else {
			$snapshot = Bookings::decode_snapshot( $booking );
		}
		if ( $raw !== '' ) {
			$decoded = json_decode( $raw, true );
			if ( is_array( $decoded ) ) {
				$snapshot = $decoded;
			}
		}
		$first_name = trim( (string) ( $snapshot['booking']['first_name'] ?? '' ) );
		$last_name  = trim( (string) ( $snapshot['booking']['last_name'] ?? '' ) );
		if ( $first_name !== '' || $last_name !== '' ) {
			return array(
				'first_name' => $first_name,
				'last_name'  => $last_name,
			);
		}
		$name = trim( (string) ( $booking->latest_name ?? $booking->name ?? '' ) );
		if ( $name === '' ) {
			return array(
				'first_name' => '',
				'last_name'  => '',
			);
		}
		$parts = preg_split( '/\s+/', $name );
		if ( ! is_array( $parts ) || empty( $parts ) ) {
			return array(
				'first_name' => $name,
				'last_name'  => '',
			);
		}
		$first_name = (string) array_shift( $parts );
		$last_name  = trim( implode( ' ', $parts ) );
		return array(
			'first_name' => $first_name,
			'last_name'  => $last_name,
		);
	}

	private static function customer_history_button_html( $email_key, $count ) {
		$ajax_url = add_query_arg(
			array(
				'action' => 'litecal_customer_history',
				'nonce'  => wp_create_nonce( 'litecal_customer_history' ),
				'email'  => (string) $email_key,
			),
			admin_url( 'admin-ajax.php' )
		);
		$count    = max( 0, (int) $count );
		$label    = sprintf(
			/* translators: %d: customer bookings count. */
			_n( '%d reserva', '%d reservas', $count, 'agenda-lite' ),
			$count
		);
		return '<button type="button" class="button button-link lc-customer-history-btn" data-lc-customer-history="' . esc_attr( $ajax_url ) . '">' . esc_html( $label ) . '</button>';
	}

	private static function customer_abuse_status_meta( $email_key, $include_history = false ) {
		$email_key = Bookings::normalize_email_key( $email_key );
		if ( $email_key === '' ) {
			return array(
				'key'    => 'normal',
				'label'  => __( 'Normal', 'agenda-lite' ),
				'class'  => 'is-success',
				'status' => array(),
			);
		}
		$cache_key = $email_key . '|' . ( ! empty( $include_history ) ? '1' : '0' );
		if ( isset( self::$customer_abuse_status_runtime[ $cache_key ] ) && is_array( self::$customer_abuse_status_runtime[ $cache_key ] ) ) {
			$status = self::$customer_abuse_status_runtime[ $cache_key ];
		} else {
			$status = Rest::booking_abuse_status_for_email( $email_key, $include_history );
			self::$customer_abuse_status_runtime[ $cache_key ] = is_array( $status ) ? $status : array();
		}
		if ( ! empty( $status['manual_blocked'] ) ) {
			return array(
				'key'    => 'blocked_manual',
				'label'  => __( 'Bloqueado manual', 'agenda-lite' ),
				'class'  => 'is-danger',
				'status' => $status,
			);
		}
		if ( ! empty( $status['is_blocked'] ) ) {
			return array(
				'key'    => 'blocked_preventive',
				'label'  => __( 'Bloqueo temporal', 'agenda-lite' ),
				'class'  => 'is-warning',
				'status' => $status,
			);
		}
		return array(
			'key'    => 'normal',
			'label'  => __( 'Normal', 'agenda-lite' ),
			'class'  => 'is-success',
			'status' => $status,
		);
	}

	private static function customer_email_keys_by_abuse_filter( $filter, $scope_employee_id = 0 ) {
		$filter            = sanitize_key( (string) $filter );
		$scope_employee_id = max( 0, (int) $scope_employee_id );
		if ( ! in_array( $filter, array( 'blocked_any', 'blocked_preventive', 'blocked_manual' ), true ) ) {
			return array();
		}
		$emails  = Bookings::customer_email_keys( 'active', $scope_employee_id );
		$result  = array();
		foreach ( $emails as $email_key ) {
			$meta = self::customer_abuse_status_meta( $email_key, true );
			$key  = sanitize_key( (string) ( $meta['key'] ?? 'normal' ) );
			$hit  = false;
			if ( $filter === 'blocked_any' ) {
				$hit = in_array( $key, array( 'blocked_preventive', 'blocked_manual' ), true );
			} elseif ( $filter === 'blocked_preventive' ) {
				$hit = $key === 'blocked_preventive';
			} elseif ( $filter === 'blocked_manual' ) {
				$hit = $key === 'blocked_manual';
			}
			if ( $hit ) {
				$result[] = Bookings::normalize_email_key( $email_key );
			}
		}
		return array_values( array_unique( array_filter( $result ) ) );
	}

	private static function grid_customer_row( $customer, $trash_view = false ) {
		$email_key        = (string) ( $customer->email_key ?? '' );
		$parts            = self::customer_name_parts_from_booking( $customer );
		$first_name       = $parts['first_name'];
		$last_name        = $parts['last_name'];
		$email            = (string) ( $customer->latest_email ?? $email_key );
		$phone            = (string) ( $customer->latest_phone ?? '' );
		$total_bookings   = max( 0, (int) ( $customer->total_bookings ?? 0 ) );
		$last_booking_at  = (string) ( $customer->last_booking_at ?? '' );
		$last_date_label  = $last_booking_at ? self::format_date_short( $last_booking_at ) : '-';
		$last_time_label  = $last_booking_at ? self::format_time_short( $last_booking_at ) : '-';
		$last_order       = $last_booking_at ? substr( $last_booking_at, 0, 19 ) : '';
		$abuse_meta       = self::customer_abuse_status_meta( $email_key, true );
		$last_booking_col = '<span class="lc-date-cell" data-order="' . esc_attr( $last_order ) . '">' .
			esc_html( $last_date_label ) .
			'<small class="lc-subtime-cell">' . esc_html( $last_time_label ) . '</small>' .
			'</span>';

		$staff_limited = ! self::can_manage() && self::is_booking_manager_user();
		$delete_cell   = '';
		if ( $staff_limited ) {
			$delete_cell = '';
		} elseif ( $trash_view ) {
			$restore_nonce = wp_create_nonce( 'litecal_bulk_update_clients' );
			$delete_nonce  = wp_create_nonce( 'litecal_bulk_update_clients' );
			$restore_form  = '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="lc-inline-form">'
				. '<input type="hidden" name="_wpnonce" value="' . esc_attr( $restore_nonce ) . '">'
				. '<input type="hidden" name="action" value="litecal_bulk_update_clients">'
				. '<input type="hidden" name="redirect" value="clients">'
				. '<input type="hidden" name="redirect_view" value="trash">'
				. '<input type="hidden" name="ids" value="' . esc_attr( $email_key ) . '">'
				. '<input type="hidden" name="bulk_status" value="restore">'
				. '<button class="button lc-icon-btn lc-icon-btn-restore" type="submit" aria-label="' . esc_attr__( 'Restaurar cliente', 'agenda-lite' ) . '" title="' . esc_attr__( 'Restaurar cliente', 'agenda-lite' ) . '"><i class="ri-restart-line"></i></button>'
				. '</form>';
			$delete_form   = '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="lc-inline-form" data-lc-confirm-delete data-lc-confirm-title="' . esc_attr__( '¿Eliminar permanentemente este cliente?', 'agenda-lite' ) . '" data-lc-confirm-text="' . esc_attr__( 'El cliente se quitará del historial de clientes, pero sus reservas permanecerán en el sistema marcadas como cliente eliminado. Esta acción no se puede deshacer.', 'agenda-lite' ) . '" data-lc-confirm-yes="' . esc_attr__( 'Eliminar permanentemente', 'agenda-lite' ) . '" data-lc-confirm-class="button button-link-delete">'
				. '<input type="hidden" name="_wpnonce" value="' . esc_attr( $delete_nonce ) . '">'
				. '<input type="hidden" name="action" value="litecal_bulk_update_clients">'
				. '<input type="hidden" name="redirect" value="clients">'
				. '<input type="hidden" name="redirect_view" value="trash">'
				. '<input type="hidden" name="ids" value="' . esc_attr( $email_key ) . '">'
				. '<input type="hidden" name="bulk_status" value="delete_permanent">'
				. '<button class="button lc-icon-btn lc-icon-btn-delete" type="submit" aria-label="' . esc_attr__( 'Eliminar permanentemente', 'agenda-lite' ) . '" title="' . esc_attr__( 'Eliminar permanentemente', 'agenda-lite' ) . '"><i class="ri-delete-bin-line"></i></button>'
				. '</form>';
			$delete_cell   = '<div class="lc-inline-actions">' . $restore_form . $delete_form . '</div>';
		} else {
			$delete_cell = '<button class="button button-link-delete lc-icon-btn lc-icon-btn-delete" type="button" data-lc-delete-id="' . esc_attr( $email_key ) . '" aria-label="' . esc_attr__( 'Mover cliente a papelera', 'agenda-lite' ) . '"><i class="ri-delete-bin-line"></i></button>';
		}

		return array(
			'id_raw'         => $email_key,
			'first_name'     => '<span class="lc-ellipsis">' . esc_html( $first_name !== '' ? $first_name : '—' ) . '</span>',
			'last_name'      => '<span class="lc-ellipsis">' . esc_html( $last_name !== '' ? $last_name : '—' ) . '</span>',
			'email'          => '<span class="lc-ellipsis">' . esc_html( $email !== '' ? $email : '—' ) . '</span>',
			'phone'          => '<span class="lc-ellipsis">' . esc_html( $phone !== '' ? $phone : '—' ) . '</span>',
			'abuse_status'   => '<div class="lc-cell-center"><span class="lc-badge ' . esc_attr( (string) ( $abuse_meta['class'] ?? 'is-muted' ) ) . '">' . esc_html( (string) ( $abuse_meta['label'] ?? __( 'Normal', 'agenda-lite' ) ) ) . '</span></div>',
			'total_bookings' => '<div class="lc-cell-center">' . self::customer_history_button_html( $email_key, $total_bookings ) . '</div>',
			'last_booking'   => $last_booking_col,
			'delete'         => $delete_cell !== '' ? '<div class="lc-cell-center">' . $delete_cell . '</div>' : '',
		);
	}

	private static function customer_history_html( $email_key, $scope_employee_id = 0 ) {
		$email_key = Bookings::normalize_email_key( $email_key );
		if ( $email_key === '' ) {
			return array(
				'title' => __( 'Historial del cliente', 'agenda-lite' ),
				'html'  => '<div class="lc-empty-state"><div>' . esc_html__( 'Cliente no encontrado.', 'agenda-lite' ) . '</div></div>',
			);
		}

		$rows = Bookings::customer_history( $email_key, $scope_employee_id, true );
		if ( empty( $rows ) ) {
			return array(
				'title' => __( 'Historial del cliente', 'agenda-lite' ),
				'html'  => '<div class="lc-empty-state"><div>' . esc_html__( 'No hay historial para este cliente.', 'agenda-lite' ) . '</div></div>',
			);
		}

		$latest         = $rows[0];
		$parts          = self::customer_name_parts_from_booking( $latest );
		$display_name   = trim( $parts['first_name'] . ' ' . $parts['last_name'] );
		$display_name   = $display_name !== '' ? $display_name : trim( (string) ( $latest->name ?? $latest->email ?? $email_key ) );
		$display_phone  = trim( (string) ( $latest->phone ?? '' ) );
		$history_markup = '';
		$abuse_meta     = self::customer_abuse_status_meta( $email_key, true );
		$abuse_status   = is_array( $abuse_meta['status'] ?? null ) ? $abuse_meta['status'] : array();
		$is_manual_blocked = ! empty( $abuse_status['manual_blocked'] );
		$abuse_key         = sanitize_key( (string) ( $abuse_meta['key'] ?? 'normal' ) );
		$show_unlock       = in_array( $abuse_key, array( 'blocked_preventive', 'blocked_manual' ), true ) || $is_manual_blocked;
		$unlock_url     = add_query_arg(
			array(
				'action' => 'litecal_customer_unlock_abuse',
				'nonce'  => wp_create_nonce( 'litecal_customer_unlock_abuse' ),
				'email'  => (string) $email_key,
			),
			admin_url( 'admin-ajax.php' )
		);
		$block_url = add_query_arg(
			array(
				'action' => 'litecal_customer_block_abuse',
				'nonce'  => wp_create_nonce( 'litecal_customer_block_abuse' ),
				'email'  => (string) $email_key,
				'block'  => 1,
			),
			admin_url( 'admin-ajax.php' )
		);

		foreach ( $rows as $booking ) {
			$snapshot      = Bookings::decode_snapshot( $booking );
			$event_exists  = Events::get( (int) ( $booking->event_id ?? 0 ) );
			$event_title   = trim( (string) ( $snapshot['event']['title'] ?? $booking->event_title ?? '' ) );
			$event_title   = $event_title !== '' ? $event_title : __( 'Servicio eliminado', 'agenda-lite' );
			$status_raw    = sanitize_key( (string) ( $booking->status ?? '' ) );
			$status_label  = self::status_label( $status_raw, 'booking' );
			$status_dot    = self::booking_status_dot_class( $status_raw );
			$booking_time  = (string) ( $booking->start_datetime ?? $booking->created_at ?? '' );
			$date_label    = $booking_time ? self::format_date_short( $booking_time ) : '—';
			$time_label    = $booking_time ? self::format_time_short( $booking_time ) : '—';
			$booking_date  = $booking_time ? substr( $booking_time, 0, 10 ) : '';
			$is_deleted    = $status_raw === 'deleted';
			$client_deleted = self::is_customer_removed_email_key( $booking->email ?? '' );
			$item_classes  = 'lc-cal-popover-list-item lc-customer-history-link';
			$line_classes  = 'lc-cal-line';
			$title_classes = 'lc-cal-line-title';
			$meta_notes    = array( $date_label . ' · ' . $status_label );
			if ( $client_deleted ) {
				$meta_notes[] = __( 'Cliente eliminado', 'agenda-lite' );
			}
			if ( ! $event_exists ) {
				$meta_notes[] = __( 'Servicio eliminado del sistema', 'agenda-lite' );
			}
			if ( $is_deleted ) {
				$item_classes  .= ' is-disabled';
				$line_classes  .= ' is-deleted';
				$title_classes .= ' is-deleted';
				$meta_notes[]   = __( 'Reserva eliminada', 'agenda-lite' );
			}

			$line_html = '<span class="' . esc_attr( $line_classes ) . '">'
				. '<span class="lc-cal-status-dot ' . esc_attr( $status_dot ) . '"></span>'
				. '<span class="lc-cal-line-time">' . esc_html( $time_label ) . '</span>'
				. '<span class="' . esc_attr( $title_classes ) . '">' . esc_html( $event_title ) . '</span>'
				. '</span>';

			if ( ! empty( $meta_notes ) ) {
				$line_html .= '<span class="lc-customer-history-link-notes">' . esc_html( implode( ' · ', $meta_notes ) ) . '</span>';
			}

			if ( $is_deleted || empty( $booking_date ) ) {
				$history_markup .= '<span class="' . esc_attr( $item_classes ) . '" aria-disabled="true">' . $line_html . '</span>';
				continue;
			}

			$calendar_url = add_query_arg(
				array(
					'page'          => 'litecal-dashboard',
					'booking_id'    => (int) ( $booking->id ?? 0 ),
					'calendar_date' => $booking_date,
				),
				admin_url( 'admin.php' )
			);

			$history_markup .= '<a class="' . esc_attr( $item_classes ) . '" href="' . esc_url( $calendar_url ) . '" data-lc-customer-booking-link>' . $line_html . '</a>';
		}

		$actions_html = '';
		$actions_html .= '<div class="lc-customer-history-actions">';
		if ( ! $is_manual_blocked ) {
			$actions_html .= '<button type="button" class="button button-link-delete lc-customer-history-block-btn" data-lc-customer-block="' . esc_attr( $block_url ) . '">' . esc_html__( 'Bloquear cliente (manual)', 'agenda-lite' ) . '</button>';
		}
		if ( $show_unlock ) {
			$actions_html .= '<button type="button" class="button lc-customer-history-unlock-btn" data-lc-customer-unlock="' . esc_attr( $unlock_url ) . '">' . esc_html__( 'Desbloquear cliente', 'agenda-lite' ) . '</button>';
		}
		$actions_html .= '<small>' . esc_html__( 'Bloqueo manual activo. El cliente no podrá crear reservas hasta ser desbloqueado.', 'agenda-lite' ) . '</small>';
		$actions_html .= '</div>';

		$summary = '<div class="lc-customer-history-summary">'
			. '<div class="lc-customer-history-summary-head">'
			. '<div class="lc-customer-history-summary-name-wrap">'
			. '<div class="lc-customer-history-summary-name">' . esc_html( $display_name ) . '</div>'
			. '<div class="lc-customer-history-summary-count">' . esc_html(
				sprintf(
					/* translators: %d: bookings count. */
					_n( '%d reserva registrada', '%d reservas registradas', count( $rows ), 'agenda-lite' ),
					count( $rows )
				)
			) . '</div>'
			. '</div>'
			. '</div>'
			. '<div class="lc-customer-history-summary-meta">'
			. '<span><i class="ri-mail-line" aria-hidden="true"></i> ' . esc_html( $email_key ) . '</span>'
			. '<span><i class="ri-phone-line" aria-hidden="true"></i> ' . esc_html( $display_phone !== '' ? $display_phone : '—' ) . '</span>'
			. '<span><i class="ri-shield-check-line" aria-hidden="true"></i> <span class="lc-badge ' . esc_attr( (string) ( $abuse_meta['class'] ?? 'is-muted' ) ) . '">' . esc_html( (string) ( $abuse_meta['label'] ?? __( 'Normal', 'agenda-lite' ) ) ) . '</span></span>'
			. '</div>'
			. $actions_html
			. '</div>';

		return array(
			'title' => __( 'Historial del cliente', 'agenda-lite' ),
			'html'  => $summary . '<div class="lc-cal-popover-list lc-customer-history-list">' . $history_markup . '</div>',
		);
	}

	private static function normalize_customer_email_keys( $ids ) {
		if ( is_string( $ids ) ) {
			$ids = array_filter( explode( ',', $ids ) );
		} elseif ( ! is_array( $ids ) ) {
			$ids = array( $ids );
		}
		$normalized = array();
		foreach ( (array) $ids as $email ) {
			$key = Bookings::normalize_email_key( $email );
			if ( $key !== '' ) {
				$normalized[] = $key;
			}
		}
		return array_values( array_unique( $normalized ) );
	}

	private static function customer_trash_email_keys() {
		return self::normalize_customer_email_keys( get_option( 'litecal_clients_trash', array() ) );
	}

	private static function store_customer_trash_email_keys( array $emails ) {
		update_option( 'litecal_clients_trash', self::normalize_customer_email_keys( $emails ), false );
		Bookings::bump_cache_version();
	}

	private static function add_customer_emails_to_trash( array $emails ) {
		$current = self::customer_trash_email_keys();
		self::store_customer_trash_email_keys( array_merge( $current, $emails ) );
	}

	private static function remove_customer_emails_from_trash( array $emails ) {
		$current = self::customer_trash_email_keys();
		if ( empty( $current ) ) {
			return;
		}
		self::store_customer_trash_email_keys( array_values( array_diff( $current, self::normalize_customer_email_keys( $emails ) ) ) );
	}

	private static function customer_removed_email_keys() {
		return self::normalize_customer_email_keys( get_option( 'litecal_clients_removed', array() ) );
	}

	private static function store_customer_removed_email_keys( array $emails ) {
		Bookings::set_customer_removed_keys( self::normalize_customer_email_keys( $emails ) );
	}

	private static function add_customer_emails_to_removed( array $emails ) {
		$current = self::customer_removed_email_keys();
		self::store_customer_removed_email_keys( array_merge( $current, $emails ) );
	}

	private static function is_customer_removed_email_key( $email_key ) {
		return in_array( Bookings::normalize_email_key( $email_key ), self::customer_removed_email_keys(), true );
	}

	private static function filter_owned_customer_email_keys( array $emails ) {
		$scope_employee_id = self::current_user_employee_id();
		$owned             = array();
		foreach ( $emails as $email ) {
			$ids = Bookings::customer_booking_ids_by_email( $email, $scope_employee_id, true );
			if ( ! empty( $ids ) ) {
				$owned[] = Bookings::normalize_email_key( $email );
			}
		}
		return array_values( array_unique( $owned ) );
	}

	private static function trash_customer_email_keys( array $emails, $scope_employee_id = 0 ) {
		$emails = self::normalize_customer_email_keys( $emails );
		if ( empty( $emails ) ) {
			return array();
		}
		self::add_customer_emails_to_trash( $emails );
		return $emails;
	}

	private static function restore_customer_email_keys( array $emails, $scope_employee_id = 0 ) {
		$emails = self::normalize_customer_email_keys( $emails );
		if ( empty( $emails ) ) {
			return array();
		}
		self::remove_customer_emails_from_trash( $emails );
		return $emails;
	}

	private static function delete_customer_email_keys_permanently( array $emails, $scope_employee_id = 0 ) {
		$emails = self::normalize_customer_email_keys( $emails );
		if ( empty( $emails ) ) {
			return array();
		}
		self::add_customer_emails_to_removed( $emails );
		self::remove_customer_emails_from_trash( $emails );
		return $emails;
	}

	public static function clients() {
		if ( ! self::can_access_staff_panel() ) {
			wp_die( esc_html__( 'Unauthorized', 'agenda-lite' ) );
		}
		$staff_limited = ! self::can_manage() && self::is_booking_manager_user();
		$view          = sanitize_key( (string) ( $_GET['view'] ?? '' ) );
		$is_trash_view = $view === 'trash';
		$search        = sanitize_text_field( wp_unslash( $_GET['s'] ?? '' ) );
		$status_filter = sanitize_key( (string) ( $_GET['status'] ?? '' ) );
		if ( ! in_array( $status_filter, array( '', 'blocked_any', 'blocked_preventive', 'blocked_manual' ), true ) ) {
			$status_filter = '';
		}
		if ( $is_trash_view ) {
			$status_filter = '';
		}
		$per_page      = absint( wp_unslash( $_GET['per_page'] ?? 10 ) );
		if ( ! in_array( $per_page, array( 10, 25, 50 ), true ) ) {
			$per_page = 10;
		}
		self::shell_start( 'clients' );
		echo '<div class="lc-admin">';
		echo '<div class="lc-header-row">';
		echo '<div><h1>' . esc_html__( 'Clientes', 'agenda-lite' ) . '</h1><p class="description">' . esc_html__( 'Consulta historial, estado de bloqueo y actividad de cada cliente en un solo lugar.', 'agenda-lite' ) . '</p></div>';
		echo '<div class="lc-header-actions"></div>';
		echo '</div>';
		echo '<div class="lc-panel">';
		echo '<div class="lc-table-toolbar">';
		echo '<div class="lc-table-left">';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="lc-table-bulk" data-lc-bulk-form>';
		wp_nonce_field( 'litecal_bulk_update_clients' );
		echo '<input type="hidden" name="action" value="litecal_bulk_update_clients" />';
		echo '<input type="hidden" name="ids" value="" data-lc-bulk-ids />';
		echo '<input type="hidden" name="redirect" value="clients" />';
		echo '<input type="hidden" name="redirect_view" value="' . esc_attr( $is_trash_view ? 'trash' : '' ) . '" />';
		echo '<select name="bulk_status" class="lc-bookings-select lc-bookings-select--bulk lc-select-compact">';
		echo '<option value="">' . esc_html__( 'Acciones en lote', 'agenda-lite' ) . '</option>';
		if ( $is_trash_view ) {
			if ( ! $staff_limited ) {
				echo '<option value="restore">' . esc_html__( 'Restaurar seleccionados', 'agenda-lite' ) . '</option>';
				echo '<option value="delete_permanent">' . esc_html__( 'Eliminar permanentemente', 'agenda-lite' ) . '</option>';
			}
		} elseif ( ! $staff_limited ) {
			echo '<option value="delete">' . esc_html__( 'Mover seleccionados a papelera', 'agenda-lite' ) . '</option>';
		}
		echo '</select>';
		echo '<button class="button">' . esc_html__( 'Aplicar', 'agenda-lite' ) . '</button>';
		echo '</form>';
		echo '<input type="text" class="lc-grid-search" data-lc-grid-search placeholder="' . esc_attr__( 'Buscar por nombre, apellido, correo o teléfono', 'agenda-lite' ) . '" value="' . esc_attr( $search ) . '" />';
		if ( $is_trash_view ) {
			echo '<input type="hidden" value="" data-lc-grid-status />';
		} else {
			echo '<select class="lc-select-compact" data-lc-grid-status>';
			echo '<option value="" ' . selected( $status_filter, '', false ) . '>' . esc_html__( 'Todos los clientes', 'agenda-lite' ) . '</option>';
			echo '<option value="blocked_any" ' . selected( $status_filter, 'blocked_any', false ) . '>' . esc_html__( 'Con cualquier bloqueo', 'agenda-lite' ) . '</option>';
			echo '<option value="blocked_preventive" ' . selected( $status_filter, 'blocked_preventive', false ) . '>' . esc_html__( 'Con bloqueo temporal', 'agenda-lite' ) . '</option>';
			echo '<option value="blocked_manual" ' . selected( $status_filter, 'blocked_manual', false ) . '>' . esc_html__( 'Con bloqueo manual', 'agenda-lite' ) . '</option>';
			echo '</select>';
		}
		echo '<select class="lc-select-compact" data-lc-grid-page-size>';
		foreach ( array( 10, 25, 50, 100 ) as $opt ) {
			echo '<option value="' . esc_attr( $opt ) . '" ' . selected( (int) $per_page, (int) $opt, false ) . '>' . esc_html( $opt ) . '</option>';
		}
		echo '</select>';
		echo '</div>';
		echo '<div class="lc-table-actions">';
		if ( $is_trash_view ) {
			echo '<a class="button" href="' . esc_url( Helpers::admin_url( 'clients' ) ) . '">' . esc_html__( 'Volver a clientes', 'agenda-lite' ) . '</a>';
			if ( ! $staff_limited ) {
				echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" data-lc-confirm-delete data-lc-confirm-title="' . esc_attr__( '¿Vaciar papelera de clientes?', 'agenda-lite' ) . '" data-lc-confirm-text="' . esc_attr__( 'Los clientes en papelera se eliminarán permanentemente del historial de clientes, pero sus reservas se conservarán en el sistema.', 'agenda-lite' ) . '" data-lc-confirm-yes="' . esc_attr__( 'Vaciar papelera', 'agenda-lite' ) . '" data-lc-confirm-class="button button-link-delete">';
				wp_nonce_field( 'litecal_empty_clients_trash' );
				echo '<input type="hidden" name="action" value="litecal_empty_clients_trash" />';
				echo '<button type="submit" class="button button-link-delete">' . esc_html__( 'Vaciar papelera', 'agenda-lite' ) . '</button>';
				echo '</form>';
			}
		} else {
			echo '<a class="button" href="' . esc_url(
				add_query_arg(
					array(
						'page' => 'litecal-clients',
						'view' => 'trash',
					),
					admin_url( 'admin.php' )
				)
			) . '">' . esc_html__( 'Ver papelera', 'agenda-lite' ) . '</a>';
		}
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( 'litecal_export_clients' );
		echo '<input type="hidden" name="action" value="litecal_export_clients" />';
		if ( $is_trash_view ) {
			echo '<input type="hidden" name="view" value="trash" />';
		}
		echo '<input type="hidden" name="status" value="' . esc_attr( $status_filter ) . '" data-lc-grid-export-status />';
		echo '<button class="button">' . esc_html__( 'Exportar CSV', 'agenda-lite' ) . '</button>';
		echo '</form>';
		echo '</div>';
		echo '</div>';
		echo '<div class="lc-gridjs-wrap">';
		echo '<div class="lc-gridjs" data-lc-gridjs="customers" data-lc-grid-view="' . esc_attr( $is_trash_view ? 'trash' : 'active' ) . '" data-lc-grid-staff-limited="' . ( $staff_limited ? '1' : '0' ) . '" data-lc-grid-url="' . esc_url( admin_url( 'admin-ajax.php' ) ) . '" data-lc-grid-action="litecal_grid_clients" data-lc-grid-nonce="' . esc_attr( wp_create_nonce( 'litecal_grid_clients' ) ) . '" data-lc-grid-page-size="' . esc_attr( $per_page ) . '"></div>';
		echo '</div>';
		if ( ! $staff_limited ) {
			echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" data-lc-delete-form>';
			wp_nonce_field( 'litecal_bulk_update_clients' );
			echo '<input type="hidden" name="action" value="litecal_bulk_update_clients" />';
			echo '<input type="hidden" name="redirect" value="clients" />';
			echo '<input type="hidden" name="redirect_view" value="' . esc_attr( $is_trash_view ? 'trash' : '' ) . '" />';
			echo '<input type="hidden" name="bulk_status" value="delete" />';
			echo '<input type="hidden" name="ids" value="" data-lc-delete-ids />';
			echo '</form>';
		}
		echo '<div class="lc-modal" data-lc-modal hidden>';
		echo '<div class="lc-modal-backdrop" data-lc-modal-close></div>';
		echo '<div class="lc-modal-card">';
		echo '<div class="lc-modal-header"><strong data-lc-modal-title>' . esc_html__( 'Historial del cliente', 'agenda-lite' ) . '</strong><button type="button" data-lc-modal-close>×</button></div>';
		echo '<div class="lc-modal-body" data-lc-modal-body></div>';
		echo '</div>';
		echo '</div>';
		echo '</div>';
		echo '</div>';
		self::shell_end();
	}

	public static function bookings() {
		if ( ! self::can_access_staff_panel() ) {
			wp_die( esc_html__( 'Unauthorized', 'agenda-lite' ) );
		}
		if ( self::can_manage() ) {
			self::mark_paid_notifications_seen();
		}
		$staff_limited = ! self::can_manage() && self::is_booking_manager_user();
		$view          = sanitize_key( (string) ( $_GET['view'] ?? '' ) );
		$is_trash_view = $view === 'trash';
		Bookings::cleanup_stale_payments( 10 );
		$search        = sanitize_text_field( wp_unslash( $_GET['s'] ?? '' ) );
		$status_filter = sanitize_text_field( wp_unslash( $_GET['status'] ?? '' ) );
		if ( $is_trash_view && $status_filter === '' ) {
			$status_filter = 'deleted';
		}
		if ( $status_filter === 'expired' ) {
			$status_filter = 'cancelled';
		}
		$per_page = absint( wp_unslash( $_GET['per_page'] ?? 10 ) );
		if ( ! in_array( $per_page, array( 10, 25, 50 ), true ) ) {
			$per_page = 10;
		}
		self::shell_start( 'bookings' );
		echo '<div class="lc-admin">';
		echo '<div class="lc-header-row">';
		echo '<div><h1>' . esc_html__( 'Reservas', 'agenda-lite' ) . '</h1><p class="description">' . esc_html__( 'Gestiona, confirma, cancela o reagenda reservas y exporta la información que necesites.', 'agenda-lite' ) . '</p></div>';
		echo '<div class="lc-header-actions"></div>';
		echo '</div>';
		echo '<div class="lc-panel">';
		echo '<div class="lc-table-toolbar">';
		echo '<div class="lc-table-left">';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="lc-table-bulk" data-lc-bulk-form>';
		wp_nonce_field( 'litecal_bulk_update_bookings' );
		echo '<input type="hidden" name="action" value="litecal_bulk_update_bookings" />';
		echo '<input type="hidden" name="ids" value="" data-lc-bulk-ids />';
		echo '<input type="hidden" name="redirect_view" value="' . esc_attr( $is_trash_view ? 'trash' : '' ) . '" />';
		echo '<select name="bulk_status" class="lc-bookings-select lc-bookings-select--bulk lc-select-compact">';
		echo '<option value="">' . esc_html__( 'Acciones en lote', 'agenda-lite' ) . '</option>';
		if ( $is_trash_view ) {
			if ( ! $staff_limited ) {
				echo '<option value="restore">' . esc_html__( 'Restaurar seleccionados', 'agenda-lite' ) . '</option>';
				echo '<option value="delete_permanent">' . esc_html__( 'Eliminar permanentemente', 'agenda-lite' ) . '</option>';
			}
		} else {
			foreach ( array( 'pending', 'confirmed', 'cancelled' ) as $status ) {
				/* translators: %s: booking status label. */
				echo '<option value="' . esc_attr( $status ) . '">' . sprintf( esc_html__( 'Cambiar reserva a %s', 'agenda-lite' ), esc_html( self::status_label( $status, 'booking' ) ) ) . '</option>';
			}
			if ( ! $staff_limited ) {
				echo '<option value="payment_approved">' . esc_html__( 'Marcar pago como aprobado', 'agenda-lite' ) . '</option>';
				echo '<option value="payment_pending">' . esc_html__( 'Marcar pago como pendiente', 'agenda-lite' ) . '</option>';
				echo '<option value="payment_rejected">' . esc_html__( 'Marcar pago como rechazado', 'agenda-lite' ) . '</option>';
			}
			if ( ! $staff_limited ) {
				echo '<option value="delete">' . esc_html__( 'Mover seleccionados a papelera', 'agenda-lite' ) . '</option>';
			}
		}
		echo '</select>';
		echo '<button class="button">' . esc_html__( 'Aplicar', 'agenda-lite' ) . '</button>';
		echo '</form>';
		if ( $is_trash_view ) {
			echo '<input type="hidden" value="deleted" data-lc-grid-status />';
		} else {
			echo '<label class="screen-reader-text" for="lc-bookings-status-filter">' . esc_html__( 'Filtrar por estado', 'agenda-lite' ) . '</label>';
			echo '<select id="lc-bookings-status-filter" name="status" class="lc-bookings-select lc-bookings-select--filter lc-select-compact" data-lc-grid-status>';
			$booking_statuses = array(
				''            => 'Todos los estados',
				'pending'     => 'Pendiente',
				'confirmed'   => 'Confirmado',
				'cancelled'   => 'Cancelado',
				'rescheduled' => 'Reagendado',
			);
			foreach ( $booking_statuses as $key => $label ) {
				echo '<option value="' . esc_attr( $key ) . '" ' . selected( $status_filter, $key, false ) . '>' . esc_html( $label ) . '</option>';
			}
			echo '</select>';
		}
		echo '<input type="text" class="lc-grid-search" data-lc-grid-search placeholder="' . esc_attr__( 'Buscar por cliente, email, servicio o referencia', 'agenda-lite' ) . '" value="' . esc_attr( $search ) . '" />';
		echo '<select class="lc-select-compact" data-lc-grid-page-size>';
		foreach ( array( 10, 25, 50, 100 ) as $opt ) {
			echo '<option value="' . esc_attr( $opt ) . '" ' . selected( (int) $per_page, (int) $opt, false ) . '>' . esc_html( $opt ) . '</option>';
		}
		echo '</select>';
		echo '</div>';
		echo '<div class="lc-table-actions">';
		if ( $is_trash_view ) {
			echo '<a class="button" href="' . esc_url( Helpers::admin_url( 'bookings' ) ) . '">' . esc_html__( 'Volver a reservas', 'agenda-lite' ) . '</a>';
			if ( ! $staff_limited ) {
				echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" data-lc-confirm-delete data-lc-confirm-title="' . esc_attr__( '¿Vaciar papelera?', 'agenda-lite' ) . '" data-lc-confirm-text="' . esc_attr__( 'Se eliminarán permanentemente todas las reservas en papelera. Esta acción no se puede deshacer.', 'agenda-lite' ) . '" data-lc-confirm-yes="' . esc_attr__( 'Vaciar papelera', 'agenda-lite' ) . '" data-lc-confirm-class="button button-link-delete">';
				wp_nonce_field( 'litecal_empty_bookings_trash' );
				echo '<input type="hidden" name="action" value="litecal_empty_bookings_trash" />';
				echo '<button type="submit" class="button button-link-delete">' . esc_html__( 'Vaciar papelera', 'agenda-lite' ) . '</button>';
				echo '</form>';
			}
		} else {
			echo '<a class="button" href="' . esc_url(
				add_query_arg(
					array(
						'page' => 'litecal-bookings',
						'view' => 'trash',
					),
					admin_url( 'admin.php' )
				)
			) . '">' . esc_html__( 'Ver papelera', 'agenda-lite' ) . '</a>';
		}
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( 'litecal_export_bookings' );
		echo '<input type="hidden" name="action" value="litecal_export_bookings" />';
		if ( $is_trash_view ) {
			echo '<input type="hidden" name="include_deleted" value="1" />';
		}
		echo '<button class="button">' . esc_html__( 'Exportar CSV', 'agenda-lite' ) . '</button>';
		echo '</form>';
		echo '</div>';
		echo '</div>';
		echo '<div class="lc-gridjs-wrap">';
		echo '<div class="lc-gridjs" data-lc-gridjs="bookings" data-lc-grid-view="' . esc_attr( $is_trash_view ? 'trash' : 'active' ) . '" data-lc-grid-staff-limited="' . ( $staff_limited ? '1' : '0' ) . '" data-lc-grid-url="' . esc_url( admin_url( 'admin-ajax.php' ) ) . '" data-lc-grid-action="litecal_grid_bookings" data-lc-grid-nonce="' . esc_attr( wp_create_nonce( 'litecal_grid_bookings' ) ) . '" data-lc-grid-page-size="' . esc_attr( $per_page ) . '"></div>';
		echo '</div>';
		if ( ! $staff_limited ) {
			echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" data-lc-delete-form>';
			wp_nonce_field( 'litecal_delete_bookings' );
			echo '<input type="hidden" name="action" value="litecal_delete_bookings" />';
			echo '<input type="hidden" name="redirect" value="bookings" />';
			echo '<input type="hidden" name="redirect_view" value="' . esc_attr( $is_trash_view ? 'trash' : '' ) . '" />';
			echo '<input type="hidden" name="ids" value="" data-lc-delete-ids />';
			echo '</form>';
		}
		echo '<div class="lc-modal" data-lc-modal hidden>';
		echo '<div class="lc-modal-backdrop" data-lc-modal-close></div>';
		echo '<div class="lc-modal-card">';
		echo '<div class="lc-modal-header"><strong data-lc-modal-title>' . esc_html__( 'Detalle de la reserva', 'agenda-lite' ) . '</strong><button type="button" data-lc-modal-close>×</button></div>';
		echo '<div class="lc-modal-body" data-lc-modal-body></div>';
		echo '</div>';
		echo '</div>';
		echo '</div>';
		echo '</div>';
		self::shell_end();
	}

	public static function payments() {
		wp_safe_redirect( Helpers::admin_url( 'bookings' ) );
		exit;
	}

	public static function analytics() {
		self::shell_start( 'analytics' );
		$events    = Events::all();
		$employees = Employees::all_booking_managers( true );
		echo '<div class="lc-admin lc-analytics-page" data-lc-analytics-root>';
		echo '<div class="lc-header-row">';
		echo '<div><h1>' . esc_html__( 'Analítica', 'agenda-lite' ) . '</h1><p class="description">' . esc_html__( 'Visualiza métricas clave de reservas e ingresos con filtros por período, servicio y profesional.', 'agenda-lite' ) . '</p></div>';
		echo '<div class="lc-header-actions"></div>';
		echo '</div>';

		echo '<div class="lc-card">';
		echo '<div class="lc-table-toolbar lc-analytics-filters">';
		echo '<div class="lc-table-left">';
		echo '<select class="lc-select-compact" data-lc-an-filter="preset">';
		echo '<option value="today">' . esc_html__( 'Hoy', 'agenda-lite' ) . '</option>';
		echo '<option value="last7">' . esc_html__( 'Últimos 7 días', 'agenda-lite' ) . '</option>';
		echo '<option value="last30">' . esc_html__( 'Últimos 30 días', 'agenda-lite' ) . '</option>';
		echo '<option value="this_month" selected>' . esc_html__( 'Este mes', 'agenda-lite' ) . '</option>';
		echo '<option value="this_year">' . esc_html__( 'Este año', 'agenda-lite' ) . '</option>';
		echo '<option value="prev_month">' . esc_html__( 'Mes anterior', 'agenda-lite' ) . '</option>';
		echo '<option value="custom">' . esc_html__( 'Personalizado', 'agenda-lite' ) . '</option>';
		echo '</select>';
		echo '<div class="lc-an-custom-range" data-lc-an-custom-range hidden>';
		echo '<input type="date" class="lc-select-compact" data-lc-an-filter="date_from" />';
		echo '<input type="date" class="lc-select-compact" data-lc-an-filter="date_to" />';
		echo '</div>';
		echo '<select class="lc-select-compact" data-lc-an-filter="group_by">';
		echo '<option value="day">' . esc_html__( 'Día', 'agenda-lite' ) . '</option>';
		echo '<option value="week">' . esc_html__( 'Semana', 'agenda-lite' ) . '</option>';
		echo '<option value="month">' . esc_html__( 'Mes', 'agenda-lite' ) . '</option>';
		echo '<option value="year">' . esc_html__( 'Año', 'agenda-lite' ) . '</option>';
		echo '</select>';
		echo '<select class="lc-select-compact" data-lc-an-filter="event_id">';
		echo '<option value="0">' . esc_html__( 'Servicio: todos', 'agenda-lite' ) . '</option>';
		foreach ( (array) $events as $event ) {
			echo '<option value="' . esc_attr( (int) $event->id ) . '">' . esc_html( $event->title ) . '</option>';
		}
		echo '</select>';
		echo '<select class="lc-select-compact" data-lc-an-filter="employee_id">';
		echo '<option value="0">' . esc_html__( 'Profesional: todos', 'agenda-lite' ) . '</option>';
		foreach ( (array) $employees as $employee ) {
			echo '<option value="' . esc_attr( (int) $employee->id ) . '">' . esc_html( $employee->name ) . '</option>';
		}
		echo '</select>';
		echo '</div>';
		echo '<div class="lc-table-actions">';
		echo '<button type="button" class="button" data-lc-an-refresh>' . esc_html__( 'Actualizar ahora', 'agenda-lite' ) . '</button>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" data-lc-an-export-form>';
		wp_nonce_field( 'litecal_export_analytics' );
		echo '<input type="hidden" name="action" value="litecal_export_analytics" />';
		foreach ( array( 'date_from', 'date_to', 'group_by', 'event_id', 'employee_id', 'booking_status', 'payment_status', 'payment_provider' ) as $field ) {
			echo '<input type="hidden" name="' . esc_attr( $field ) . '" value="" data-lc-an-export="' . esc_attr( $field ) . '" />';
		}
		echo '<button type="submit" class="button">' . esc_html__( 'Exportar CSV', 'agenda-lite' ) . '</button>';
		echo '</form>';
		echo '</div>';
		echo '</div>';
		echo '</div>';

		echo '<div class="lc-analytics-kpi-grid">';
		$bookings_base_url        = Helpers::admin_url( 'bookings' );
		$bookings_confirmed_url   = add_query_arg( array( 'status' => 'confirmed' ), $bookings_base_url );
		$bookings_rescheduled_url = add_query_arg( array( 'status' => 'rescheduled' ), $bookings_base_url );
		$bookings_pending_url     = add_query_arg( array( 'status' => 'pending' ), $bookings_base_url );
		$bookings_cancelled_url   = add_query_arg( array( 'status' => 'cancelled' ), $bookings_base_url );
		$trash_url                = add_query_arg(
			array(
				'page' => 'litecal-bookings',
				'view' => 'trash',
			),
			admin_url( 'admin.php' )
		);
		$kpis                     = array(
			array(
				'key'   => 'total',
				'label' => __( 'Reservas totales', 'agenda-lite' ),
				'url'   => $bookings_base_url,
			),
			array(
				'key'   => 'confirmed',
				'label' => __( 'Confirmadas', 'agenda-lite' ),
				'url'   => $bookings_confirmed_url,
			),
			array(
				'key'   => 'rescheduled',
				'label' => __( 'Reagendadas', 'agenda-lite' ),
				'url'   => $bookings_rescheduled_url,
			),
			array(
				'key'   => 'pending',
				'label' => __( 'Pendientes', 'agenda-lite' ),
				'url'   => $bookings_pending_url,
			),
			array(
				'key'   => 'cancelled',
				'label' => __( 'Canceladas', 'agenda-lite' ),
				'url'   => $bookings_cancelled_url,
			),
			array(
				'key'   => 'trashed',
				'label' => __( 'En papelera', 'agenda-lite' ),
				'url'   => $trash_url,
			),
			array(
				'key'   => 'revenue',
				'label' => __( 'Ingresos', 'agenda-lite' ),
			),
			array(
				'key'   => 'ticket_avg',
				'label' => __( 'Ticket promedio', 'agenda-lite' ),
			),
		);
		foreach ( $kpis as $kpi ) {
			$url = isset( $kpi['url'] ) ? (string) $kpi['url'] : '';
			if ( $url !== '' ) {
				echo '<a class="lc-card lc-analytics-kpi-card lc-analytics-kpi-card--link" href="' . esc_url( $url ) . '">';
			} else {
				echo '<div class="lc-card lc-analytics-kpi-card">';
			}
			echo '<div class="lc-analytics-kpi-label">' . esc_html( $kpi['label'] ) . '</div>';
			echo '<div class="lc-analytics-kpi-value" data-lc-an-kpi="' . esc_attr( $kpi['key'] ) . '">0</div>';
			echo $url !== '' ? '</a>' : '</div>';
		}
		echo '</div>';

		echo '<div class="lc-analytics-chart-grid">';
		echo '<div class="lc-card">';
		echo '<div class="lc-analytics-chart-title">' . esc_html__( 'Reservas por fecha de cita', 'agenda-lite' ) . '</div>';
		echo '<div class="lc-analytics-chart-wrap">';
		echo '<div class="lc-analytics-chart-state" data-lc-an-chart-state="bookings"></div>';
		echo '<canvas class="lc-analytics-chart" data-lc-an-chart="bookings"></canvas>';
		echo '</div>';
		echo '</div>';
		echo '<div class="lc-card">';
		echo '<div class="lc-analytics-chart-title">' . esc_html__( 'Ingresos por fecha de pago', 'agenda-lite' ) . '</div>';
		echo '<div class="lc-analytics-chart-wrap">';
		echo '<div class="lc-analytics-chart-state" data-lc-an-chart-state="revenue"></div>';
		echo '<canvas class="lc-analytics-chart" data-lc-an-chart="revenue"></canvas>';
		echo '</div>';
		echo '</div>';
		echo '</div>';

		echo '</div>';
		self::shell_end();
	}

	public static function availability() {
		$schedules              = self::ordered_schedules( (array) get_option( 'litecal_schedules', array() ) );
		$default_id             = get_option( 'litecal_default_schedule', 'default' );
		$edit_id                = sanitize_text_field( wp_unslash( $_GET['schedule_id'] ?? '' ) );
		$schedule_limit_reached = self::free_limit_reached( 'schedules' );
		self::shell_start( 'availability' );
		echo '<div class="lc-admin">';
		echo '<div class="lc-header-row">';
		echo '<div><h1>' . esc_html__( 'Disponibilidad', 'agenda-lite' ) . '</h1><p class="description">' . esc_html__( 'Configura horarios de atención y feriados globales.', 'agenda-lite' ) . '</p></div>';
		echo '<div class="lc-header-actions">';
		if ( ! $edit_id ) {
			if ( $schedule_limit_reached ) {
				echo wp_kses_post( self::pro_upgrade_disabled_button_html( __( 'Actualiza a Pro para desbloquear funciones premium', 'agenda-lite' ), 'button button-primary' ) );
			} else {
				echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="margin:0;">';
				wp_nonce_field( 'litecal_new_schedule' );
				echo '<input type="hidden" name="action" value="litecal_new_schedule" />';
				echo '<button class="button button-primary">' . esc_html__( '+ Nuevo horario', 'agenda-lite' ) . '</button>';
				echo '</form>';
			}
		}
		echo '</div>';
		echo '</div>';

		if ( $edit_id && isset( $schedules[ $edit_id ] ) ) {
			$schedule        = $schedules[ $edit_id ];
			$schedule_breaks = is_array( $schedule['breaks'] ?? null ) ? $schedule['breaks'] : array();
			$schedule_bounds = is_array( $schedule['bounds'] ?? null ) ? $schedule['bounds'] : array();
			echo '<div class="lc-detail-header">';
			echo '<div class="lc-detail-title">' . esc_html( $schedule['name'] ) . '</div>';
			echo '<div class="lc-detail-actions">';
			echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="display:inline;">';
			wp_nonce_field( 'litecal_set_default_schedule' );
			echo '<input type="hidden" name="action" value="litecal_set_default_schedule" />';
			echo '<input type="hidden" name="schedule_id" value="' . esc_attr( $edit_id ) . '" />';
			echo '<label class="lc-toggle-row lc-toggle-row-left">';
			$is_default = $default_id === $edit_id;
			echo '<span class="lc-switch"><input type="checkbox" name="is_default" value="1" ' . checked( true, $is_default, false ) . ' ' . ( $is_default ? 'disabled' : 'data-lc-auto-submit' ) . '><span></span></span>';
			echo '<span>' . esc_html__( 'Predeterminado', 'agenda-lite' ) . '</span>';
			echo '</label>';
			echo '</form>';
			echo '<button class="button button-primary" type="submit" form="lc-schedule-form">' . esc_html__( 'Guardar', 'agenda-lite' ) . '</button>';
			echo '<a class="button" href="' . esc_url( Helpers::admin_url( 'availability' ) ) . '">' . esc_html__( 'Cancelar', 'agenda-lite' ) . '</a>';
			echo '</div>';
			echo '</div>';
			echo '<p class="description">' . esc_html__( 'Define los días y horarios en los que aceptarás reservas.', 'agenda-lite' ) . '</p>';

			echo '<form id="lc-schedule-form" method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
			wp_nonce_field( 'litecal_save_schedule' );
			echo '<input type="hidden" name="action" value="litecal_save_schedule" />';
			echo '<input type="hidden" name="schedule_id" value="' . esc_attr( $edit_id ) . '" />';
			echo '<div class="lc-panel lc-availability-side lc-availability-side-top">';
			echo '<label>' . esc_html__( 'Nombre del horario', 'agenda-lite' ) . '</label><input name="schedule_name" value="' . esc_attr( $schedule['name'] ) . '" />';
			echo '<p class="description">' . esc_html__( 'La zona horaria del horario se administra desde Ajustes > General.', 'agenda-lite' ) . '</p>';
			echo '</div>';
			echo '<div class="lc-availability-grid">';
			echo '<div class="lc-panel lc-availability-days">';
			$time_options = array();
			for ( $h = 0; $h < 24; $h++ ) {
				foreach ( array( 0, 30 ) as $m ) {
					$value          = sprintf( '%02d:%02d', $h, $m );
					$display        = $value;
					if ( self::time_format() !== '24h' ) {
						$time_obj = \DateTimeImmutable::createFromFormat( 'H:i', $value, wp_timezone() );
						if ( $time_obj instanceof \DateTimeImmutable ) {
							$display = $time_obj->format( 'g:ia' );
						}
					}
					$time_options[] = array(
						'value' => $value,
						'label' => $display,
					);
				}
			}
			$days = array(
				__( 'Lunes', 'agenda-lite' ),
				__( 'Martes', 'agenda-lite' ),
				__( 'Miércoles', 'agenda-lite' ),
				__( 'Jueves', 'agenda-lite' ),
				__( 'Viernes', 'agenda-lite' ),
				__( 'Sábado', 'agenda-lite' ),
				__( 'Domingo', 'agenda-lite' ),
			);
			foreach ( $days as $index => $label ) {
				$day_num       = $index + 1;
				$day_ranges    = $schedule['days'][ $day_num ] ?? array();
				$main_start    = '';
				$main_end      = '';
				$break_start   = '';
				$break_end     = '';
				$break_enabled = false;
				if ( ! empty( $schedule_bounds[ $day_num ] ) && is_array( $schedule_bounds[ $day_num ] ) ) {
					$main_start = sanitize_text_field( (string) ( $schedule_bounds[ $day_num ]['start'] ?? '' ) );
					$main_end   = sanitize_text_field( (string) ( $schedule_bounds[ $day_num ]['end'] ?? '' ) );
				}
				if ( ! empty( $schedule_breaks[ $day_num ] ) && is_array( $schedule_breaks[ $day_num ] ) ) {
					$break_start   = sanitize_text_field( (string) ( $schedule_breaks[ $day_num ]['start'] ?? '' ) );
					$break_end     = sanitize_text_field( (string) ( $schedule_breaks[ $day_num ]['end'] ?? '' ) );
					$break_enabled = $break_start !== '' && $break_end !== '';
				}
				if ( ! empty( $day_ranges ) && is_array( $day_ranges ) ) {
					$first = $day_ranges[0] ?? array();
					$last  = end( $day_ranges );
					if ( $main_start === '' ) {
						$main_start = sanitize_text_field( (string) ( $first['start'] ?? '' ) );
					}
					if ( $main_end === '' ) {
						$main_end = sanitize_text_field( (string) ( ( $last['end'] ?? ( $first['end'] ?? '' ) ) ) );
					}
					if ( ! $break_enabled && count( $day_ranges ) >= 2 ) {
						$second       = $day_ranges[1] ?? array();
						$second_start = sanitize_text_field( (string) ( $second['start'] ?? '' ) );
						$last_end     = sanitize_text_field( (string) ( ( $last['end'] ?? $main_end ) ) );
						if ( $main_end !== '' && $second_start !== '' && $main_end < $second_start ) {
							$break_start   = $main_end;
							$break_end     = $second_start;
							$break_enabled = true;
						}
						if ( $last_end !== '' ) {
							$main_end = $last_end;
						}
					}
				}
				$enabled = $main_start !== '' && $main_end !== '';
				echo '<div class="lc-day-row" data-lc-day-row>';
				echo '<input type="hidden" name="day_enabled[' . esc_attr( $day_num ) . ']" value="0" />';
				echo '<label class="lc-switch"><input type="checkbox" name="day_enabled[' . esc_attr( $day_num ) . ']" value="1" ' . checked( true, $enabled, false ) . ' data-lc-day-toggle><span></span></label>';
				echo '<div class="lc-day-label"><label>' . esc_html( $label ) . '</label></div>';
				echo '<div class="lc-day-times-wrap" data-lc-day-times>';
				echo '<div class="lc-day-inline">';
				echo '<select class="lc-time-select" name="start[' . esc_attr( $day_num ) . ']" data-lc-day-start>';
				foreach ( $time_options as $opt ) {
					echo '<option value="' . esc_attr( $opt['value'] ) . '" ' . selected( (string) $main_start, (string) $opt['value'], false ) . '>' . esc_html( $opt['label'] ) . '</option>';
				}
				echo '</select>';
				echo '<span class="lc-day-sep">—</span>';
				echo '<select class="lc-time-select" name="end[' . esc_attr( $day_num ) . ']" data-lc-day-end>';
				foreach ( $time_options as $opt ) {
					echo '<option value="' . esc_attr( $opt['value'] ) . '" ' . selected( (string) $main_end, (string) $opt['value'], false ) . '>' . esc_html( $opt['label'] ) . '</option>';
				}
				echo '</select>';
				echo '</div>';
				echo '<div class="lc-day-break">';
				echo '<input type="hidden" name="break_enabled[' . esc_attr( $day_num ) . ']" value="' . esc_attr( $break_enabled ? '1' : '0' ) . '" data-lc-break-enabled />';
				echo '<button type="button" class="button lc-day-break-btn" data-lc-break-toggle>' . esc_html( $break_enabled ? __( 'Quitar descanso', 'agenda-lite' ) : __( 'Agregar descanso', 'agenda-lite' ) ) . '</button>';
				echo '<div class="lc-day-inline lc-day-break-fields ' . ( $break_enabled ? '' : 'is-hidden' ) . '" data-lc-break-fields>';
				echo '<select class="lc-time-select" name="break_start[' . esc_attr( $day_num ) . ']" data-lc-break-start' . ( $break_enabled ? '' : ' disabled' ) . '>';
				foreach ( $time_options as $opt ) {
					echo '<option value="' . esc_attr( $opt['value'] ) . '" ' . selected( (string) $break_start, (string) $opt['value'], false ) . '>' . esc_html( $opt['label'] ) . '</option>';
				}
				echo '</select>';
				echo '<span class="lc-day-sep">—</span>';
				echo '<select class="lc-time-select" name="break_end[' . esc_attr( $day_num ) . ']" data-lc-break-end' . ( $break_enabled ? '' : ' disabled' ) . '>';
				foreach ( $time_options as $opt ) {
					echo '<option value="' . esc_attr( $opt['value'] ) . '" ' . selected( (string) $break_end, (string) $opt['value'], false ) . '>' . esc_html( $opt['label'] ) . '</option>';
				}
				echo '</select>';
				echo '</div>';
				echo '</div>';
				echo '</div>';
				echo '</div>';
			}
			echo '</div>';
			echo '</div>';
			echo '</form>';
		} else {
			echo '<div class="lc-panel lc-event-list lc-list-shell">';
			echo '<div class="lc-event-list-header"><span>' . esc_html__( 'Horarios', 'agenda-lite' ) . '</span><small>' . esc_html__( 'Arrastra y suelta para reordenar los horarios.', 'agenda-lite' ) . '</small></div>';
			$can_drag = count( $schedules ) > 1;
			echo '<div data-lc-schedule-order-list>';
			foreach ( $schedules as $sid => $schedule ) {
				$summary            = self::schedule_summary( $schedule );
				$is_default         = $sid === $default_id;
				$has_assigned_hours = self::schedule_has_assigned_hours( $schedule );
				$wp_tz              = get_option( 'timezone_string' );
				$offset             = get_option( 'gmt_offset', 0 );
				$tz_label           = $wp_tz;
				if ( ! $wp_tz ) {
					$sign     = $offset >= 0 ? '+' : '-';
					$abs      = abs( $offset );
					$hours    = floor( $abs );
					$mins     = ( $abs - $hours ) * 60;
					$tz_label = sprintf( 'UTC%s%02d:%02d', $sign, $hours, $mins );
				}
				$row_classes = 'lc-event-item lc-schedule-list-item';
				if ( $can_drag ) {
					$row_classes .= ' is-draggable';
				}
				echo '<div class="' . esc_attr( $row_classes ) . '" data-lc-schedule-row data-lc-schedule-id="' . esc_attr( $sid ) . '"' . ( $can_drag ? ' draggable="true"' : '' ) . '>';
				echo '<div class="lc-event-info">';
				echo '<div class="lc-event-title-row">';
				if ( $can_drag ) {
					echo '<span class="lc-drag-handle" title="' . esc_attr__( 'Arrastrar para ordenar', 'agenda-lite' ) . '">⋮⋮</span>';
				}
				echo '<span class="lc-event-dot ' . ( $has_assigned_hours ? 'is-active' : 'is-inactive' ) . '"><span class="dot"></span></span>';
				echo '<span class="lc-event-title-stack"><span class="lc-event-title">' . esc_html( $schedule['name'] ) . '</span><span class="lc-event-inline-sub">' . esc_html( $summary ) . '</span></span>';
				echo '<span class="lc-event-tag">' . esc_html( $is_default ? __( 'Activo', 'agenda-lite' ) : __( 'Disponible', 'agenda-lite' ) ) . '</span>';
				if ( $is_default ) {
					echo '<span class="lc-event-tag">' . esc_html__( 'Predeterminado', 'agenda-lite' ) . '</span>';
				}
				echo '</div>';
				echo '</div>';
				echo '<div class="lc-event-actions lc-event-actions--stack">';
				echo '<a class="button" href="' . esc_url( Helpers::admin_url( 'availability' ) . '&schedule_id=' . $sid ) . '">' . esc_html__( 'Editar', 'agenda-lite' ) . '</a>';
				echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="margin:0;">';
				wp_nonce_field( 'litecal_delete_schedule' );
				echo '<input type="hidden" name="action" value="litecal_delete_schedule" />';
				echo '<input type="hidden" name="schedule_id" value="' . esc_attr( $sid ) . '" />';
				echo '<button class="button button-link-delete">' . esc_html__( 'Eliminar', 'agenda-lite' ) . '</button>';
				echo '</form>';
				echo '</div>';
				echo '</div>';
			}
			echo '</div>';
			if ( $can_drag ) {
				echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" data-lc-schedule-order-form>';
				wp_nonce_field( 'litecal_save_schedule_order' );
				echo '<input type="hidden" name="action" value="litecal_save_schedule_order" />';
				echo '<input type="hidden" name="ids" value="" data-lc-schedule-order-ids />';
				echo '</form>';
			}
			echo '</div>';
		}

		echo '</div>';
		self::shell_end();
	}

	public static function integrations() {
		$options = get_option( 'litecal_integrations', array() );
		self::shell_start( 'integrations' );
		echo '<div class="lc-admin">';
		echo '<h1>' . esc_html__( 'Integraciones', 'agenda-lite' ) . '</h1>';
		echo '<p class="description">' . esc_html__( 'Conecta Agenda Lite con tus servicios de pago, video y calendario. Activa solo lo que uses, configura tus credenciales y ofrece una experiencia de reservas más fluida para tus clientes.', 'agenda-lite' ) . '</p>';
		echo '<div class="lc-panel lc-integrations-shell">';

		$integrations = array(
			array(
				'key'      => 'recaptcha',
				'label'    => __( 'reCAPTCHA v3', 'agenda-lite' ),
				'desc'     => __( 'Protege los formularios con reCAPTCHA v3. Activa la integración y agrega las llaves para validar el envío de reservas.', 'agenda-lite' ),
				'category' => __( 'Seguridad', 'agenda-lite' ),
				'logo'     => LITECAL_URL . 'assets/logos/recaptchav3.svg',
				'active'   => ! empty( $options['recaptcha_enabled'] ),
				'config'   => function () use ( $options ) {
					echo '<label class="lc-toggle-row lc-toggle-row-left">';
					echo '<span class="lc-switch"><input type="checkbox" name="recaptcha_enabled" value="1" ' . checked( 1, $options['recaptcha_enabled'] ?? 0, false ) . '><span></span></span>';
					echo '<span>' . esc_html__( 'Activar integración', 'agenda-lite' ) . '</span>';
					echo '</label>';
					echo '<label>' . esc_html__( 'Site Key', 'agenda-lite' ) . '</label><input name="recaptcha_site_key" value="' . esc_attr( $options['recaptcha_site_key'] ?? '' ) . '" />';
					echo '<label>' . esc_html__( 'Secret Key', 'agenda-lite' ) . '</label><input name="recaptcha_secret_key" value="' . esc_attr( $options['recaptcha_secret_key'] ?? '' ) . '" />';
					echo '<p class="description">' . esc_html__( 'Solo reCAPTCHA v3.', 'agenda-lite' ) . '</p>';
				},
			),
			array(
				'key'      => 'google_tag_manager',
				'label'    => __( 'Google Tag Manager', 'agenda-lite' ),
				'desc'     => __( 'Inserta tu contenedor de Google Tag Manager para medir conversiones y reservas.', 'agenda-lite' ),
				'category' => __( 'Marketing', 'agenda-lite' ),
				'logo'     => LITECAL_URL . 'assets/logos/googletagmanager.svg',
				'active'   => ! empty( $options['gtm_enabled'] ) && ! empty( $options['gtm_container_id'] ),
				'config'   => function () use ( $options ) {
					echo '<label class="lc-toggle-row lc-toggle-row-left">';
					echo '<span class="lc-switch"><input type="checkbox" name="gtm_enabled" value="1" ' . checked( 1, $options['gtm_enabled'] ?? 0, false ) . '><span></span></span>';
					echo '<span>' . esc_html__( 'Activar integración', 'agenda-lite' ) . '</span>';
					echo '</label>';
					echo '<label>' . esc_html__( 'ID del contenedor', 'agenda-lite' ) . '</label>';
					echo '<input name="gtm_container_id" value="' . esc_attr( $options['gtm_container_id'] ?? '' ) . '" placeholder="GTM-XXXXXXX" />';
					echo '<p class="description">' . esc_html__( 'Formato esperado: GTM-XXXXXXX. Se cargará en páginas públicas de Agenda Lite.', 'agenda-lite' ) . '</p>';
				},
			),
			array(
				'key'      => 'google_analytics',
				'label'    => __( 'Google Analytics', 'agenda-lite' ),
				'desc'     => __( 'Conecta tu Measurement ID para medir visitas y conversiones en las páginas públicas de Agenda Lite.', 'agenda-lite' ),
				'category' => __( 'Marketing', 'agenda-lite' ),
				'logo'     => LITECAL_URL . 'assets/logos/googleanalytics.svg',
				'active'   => ! empty( $options['ga_enabled'] ) && ! empty( $options['ga_measurement_id'] ),
				'config'   => function () use ( $options ) {
					echo '<label class="lc-toggle-row lc-toggle-row-left">';
					echo '<span class="lc-switch"><input type="checkbox" name="ga_enabled" value="1" ' . checked( 1, $options['ga_enabled'] ?? 0, false ) . '><span></span></span>';
					echo '<span>' . esc_html__( 'Activar integración', 'agenda-lite' ) . '</span>';
					echo '</label>';
					echo '<label>' . esc_html__( 'Measurement ID', 'agenda-lite' ) . '</label>';
					echo '<input name="ga_measurement_id" value="' . esc_attr( $options['ga_measurement_id'] ?? '' ) . '" placeholder="G-XXXXXXXXXX" />';
					echo '<p class="description">' . esc_html__( 'Formato esperado: G-XXXXXXXXXX.', 'agenda-lite' ) . '</p>';
				},
			),
			array(
				'key'      => 'google_ads',
				'label'    => __( 'Google Ads', 'agenda-lite' ),
				'desc'     => __( 'Conecta tu Tag ID de Google Ads para medir conversiones publicitarias en Agenda Lite.', 'agenda-lite' ),
				'category' => __( 'Marketing', 'agenda-lite' ),
				'logo'     => LITECAL_URL . 'assets/logos/googleads.svg',
				'active'   => ! empty( $options['gads_enabled'] ) && ! empty( $options['gads_tag_id'] ),
				'config'   => function () use ( $options ) {
					echo '<label class="lc-toggle-row lc-toggle-row-left">';
					echo '<span class="lc-switch"><input type="checkbox" name="gads_enabled" value="1" ' . checked( 1, $options['gads_enabled'] ?? 0, false ) . '><span></span></span>';
					echo '<span>' . esc_html__( 'Activar integración', 'agenda-lite' ) . '</span>';
					echo '</label>';
					echo '<label>' . esc_html__( 'Tag ID', 'agenda-lite' ) . '</label>';
					echo '<input name="gads_tag_id" value="' . esc_attr( $options['gads_tag_id'] ?? '' ) . '" placeholder="AW-123456789" />';
					echo '<p class="description">' . esc_html__( 'Formato esperado: AW-123456789.', 'agenda-lite' ) . '</p>';
				},
			),
			array(
				'key'      => 'meta_pixel',
				'label'    => __( 'Meta Pixel', 'agenda-lite' ),
				'desc'     => __( 'Conecta tu Pixel de Meta para medir reservas y conversiones.', 'agenda-lite' ),
				'category' => __( 'Marketing', 'agenda-lite' ),
				'logo'     => LITECAL_URL . 'assets/logos/meta.svg',
				'active'   => ! empty( $options['meta_pixel_enabled'] ) && ! empty( $options['meta_pixel_id'] ),
				'config'   => function () use ( $options ) {
					$meta_value = trim( (string) ( $options['meta_pixel_input'] ?? ( $options['meta_pixel_id'] ?? '' ) ) );
					echo '<label class="lc-toggle-row lc-toggle-row-left">';
					echo '<span class="lc-switch"><input type="checkbox" name="meta_pixel_enabled" value="1" ' . checked( 1, $options['meta_pixel_enabled'] ?? 0, false ) . '><span></span></span>';
					echo '<span>' . esc_html__( 'Activar integración', 'agenda-lite' ) . '</span>';
					echo '</label>';
					echo '<label>' . esc_html__( 'Código o Pixel ID', 'agenda-lite' ) . '</label>';
					echo '<textarea name="meta_pixel_input" rows="4" placeholder="1541170397675880 o pega el código completo de Meta Pixel">' . esc_textarea( $meta_value ) . '</textarea>';
					echo '<p class="description">' . esc_html__( 'Puedes pegar solo el Pixel ID o el bloque completo de Meta Pixel. Agenda Lite extrae el ID automáticamente.', 'agenda-lite' ) . '</p>';
				},
			),
			array(
				'key'      => 'payments_flow',
				'label'    => __( 'Flow', 'agenda-lite' ),
				'desc'     => __( 'Recibe pagos con Flow en modo pruebas o producción. Configura tus llaves, activa la integración y cobra reservas con una experiencia rápida y confiable para tus clientes.', 'agenda-lite' ),
				'category' => __( 'Pagos', 'agenda-lite' ),
				'logo'     => LITECAL_URL . 'assets/logos/flow.svg',
				'active'   => ! empty( $options['payments_flow'] ),
				'config'   => function () use ( $options ) {
					echo '<label class="lc-toggle-row lc-toggle-row-left">';
					echo '<span class="lc-switch"><input type="checkbox" name="payments_flow" value="1" ' . checked( 1, $options['payments_flow'] ?? 0, false ) . '><span></span></span>';
					echo '<span>' . esc_html__( 'Activar integración', 'agenda-lite' ) . '</span>';
					echo '</label>';
					echo '<label>' . esc_html__( 'Modo', 'agenda-lite' ) . '</label>';
					echo '<select name="flow_sandbox">';
					echo '<option value="1" ' . selected( 1, $options['flow_sandbox'] ?? 0, false ) . '>' . esc_html__( 'Modo pruebas (sandbox)', 'agenda-lite' ) . '</option>';
					echo '<option value="0" ' . selected( 0, $options['flow_sandbox'] ?? 0, false ) . '>' . esc_html__( 'Modo producción (pagos reales)', 'agenda-lite' ) . '</option>';
					echo '</select>';
					echo '<label>API Key</label><input name="flow_api_key" value="' . esc_attr( $options['flow_api_key'] ?? '' ) . '" />';
					echo '<label>Secret Key</label><input name="flow_secret_key" value="' . esc_attr( $options['flow_secret_key'] ?? '' ) . '" />';
				},
			),
			array(
				'key'      => 'payments_mp',
				'label'    => __( 'MercadoPago', 'agenda-lite' ),
				'desc'     => __( 'Acepta pagos con MercadoPago de forma segura. Conecta tu cuenta, activa el medio de pago y habilita cobros en tus reservas desde Agenda Lite.', 'agenda-lite' ),
				'category' => __( 'Pagos', 'agenda-lite' ),
				'logo'     => LITECAL_URL . 'assets/logos/mercadopago.svg',
				'active'   => ! empty( $options['payments_mp'] ),
				'config'   => function () use ( $options ) {
					echo '<label class="lc-toggle-row lc-toggle-row-left">';
					echo '<span class="lc-switch"><input type="checkbox" name="payments_mp" value="1" ' . checked( 1, $options['payments_mp'] ?? 0, false ) . '><span></span></span>';
					echo '<span>' . esc_html__( 'Activar integración', 'agenda-lite' ) . '</span>';
					echo '</label>';
					echo '<label>' . esc_html__( 'Modo', 'agenda-lite' ) . '</label>';
					echo '<select name="mp_sandbox">';
					echo '<option value="1" ' . selected( 1, $options['mp_sandbox'] ?? 1, false ) . '>' . esc_html__( 'Modo pruebas (sandbox)', 'agenda-lite' ) . '</option>';
					echo '<option value="0" ' . selected( 0, $options['mp_sandbox'] ?? 1, false ) . '>' . esc_html__( 'Modo producción (pagos reales)', 'agenda-lite' ) . '</option>';
					echo '</select>';
					echo '<label>Access Token</label><input name="mp_access_token" value="' . esc_attr( $options['mp_access_token'] ?? '' ) . '" />';
					echo '<label>' . esc_html__( 'Webhook Secret', 'agenda-lite' ) . '</label><input name="mp_webhook_secret" value="' . esc_attr( $options['mp_webhook_secret'] ?? '' ) . '" />';
				},
			),
			array(
				'key'      => 'payments_webpay',
				'label'    => __( 'Webpay Plus', 'agenda-lite' ),
				'desc'     => __( 'Cobra con Webpay Plus y ofrece pagos locales a tus clientes. Activa la integración y usa este medio en tus servicios con un flujo de pago confiable.', 'agenda-lite' ),
				'category' => __( 'Pagos', 'agenda-lite' ),
				'logo'     => LITECAL_URL . 'assets/logos/webpayplus.svg',
				'active'   => ! empty( $options['payments_webpay'] ),
				'config'   => function () use ( $options ) {
					echo '<label class="lc-toggle-row lc-toggle-row-left">';
					echo '<span class="lc-switch"><input type="checkbox" name="payments_webpay" value="1" ' . checked( 1, $options['payments_webpay'] ?? 0, false ) . '><span></span></span>';
					echo '<span>' . esc_html__( 'Activar integración', 'agenda-lite' ) . '</span>';
					echo '</label>';
					echo '<label>' . esc_html__( 'Modo', 'agenda-lite' ) . '</label>';
					echo '<select name="webpay_sandbox">';
					echo '<option value="1" ' . selected( 1, $options['webpay_sandbox'] ?? 1, false ) . '>' . esc_html__( 'Modo pruebas (sandbox)', 'agenda-lite' ) . '</option>';
					echo '<option value="0" ' . selected( 0, $options['webpay_sandbox'] ?? 1, false ) . '>' . esc_html__( 'Modo producción (pagos reales)', 'agenda-lite' ) . '</option>';
					echo '</select>';
					echo '<label>' . esc_html__( 'Código de comercio', 'agenda-lite' ) . '</label><input name="webpay_commerce_code" value="' . esc_attr( $options['webpay_commerce_code'] ?? '' ) . '" />';
					echo '<label>API Key</label><input name="webpay_api_key" value="' . esc_attr( $options['webpay_api_key'] ?? '' ) . '" />';
				},
			),
			array(
				'key'      => 'payments_paypal',
				'label'    => __( 'PayPal', 'agenda-lite' ),
				'desc'     => __( 'Habilita pagos internacionales con PayPal. Conecta tu cuenta, activa la integración y permite que tus clientes paguen con tarjeta o saldo PayPal.', 'agenda-lite' ),
				'category' => __( 'Pagos', 'agenda-lite' ),
				'logo'     => LITECAL_URL . 'assets/logos/paypal.svg',
				'active'   => ! empty( $options['payments_paypal'] ),
				'config'   => function () use ( $options ) {
					echo '<label class="lc-toggle-row lc-toggle-row-left">';
					echo '<span class="lc-switch"><input type="checkbox" name="payments_paypal" value="1" ' . checked( 1, $options['payments_paypal'] ?? 0, false ) . '><span></span></span>';
					echo '<span>' . esc_html__( 'Activar integración', 'agenda-lite' ) . '</span>';
					echo '</label>';
					echo '<label>' . esc_html__( 'Modo', 'agenda-lite' ) . '</label>';
					echo '<select name="paypal_sandbox">';
					echo '<option value="1" ' . selected( 1, $options['paypal_sandbox'] ?? 1, false ) . '>' . esc_html__( 'Modo pruebas (sandbox)', 'agenda-lite' ) . '</option>';
					echo '<option value="0" ' . selected( 0, $options['paypal_sandbox'] ?? 1, false ) . '>' . esc_html__( 'Modo producción (pagos reales)', 'agenda-lite' ) . '</option>';
					echo '</select>';
					echo '<label>Client ID</label><input name="paypal_client_id" value="' . esc_attr( $options['paypal_client_id'] ?? '' ) . '" />';
					echo '<label>Secret</label><input name="paypal_secret" value="' . esc_attr( $options['paypal_secret'] ?? '' ) . '" />';
					echo '<label>' . esc_html__( 'Webhook ID', 'agenda-lite' ) . '</label><input value="' . esc_attr( $options['paypal_webhook_id'] ?? '' ) . '" placeholder="' . esc_attr__( 'Se detecta automáticamente', 'agenda-lite' ) . '" readonly />';
				},
			),
			array(
				'key'      => 'payments_stripe',
				'label'    => __( 'Stripe Checkout', 'agenda-lite' ),
				'desc'     => __( 'Cobros seguros con Stripe Checkout. La reserva se confirma solo por webhook firmado (no por URL de retorno).', 'agenda-lite' ),
				'category' => __( 'Pagos', 'agenda-lite' ),
				'logo'     => LITECAL_URL . 'assets/logos/stripe.svg',
				'active'   => ! empty( $options['payments_stripe'] ),
				'config'   => function () use ( $options ) {
					$stripe_mode = strtolower( trim( (string) ( $options['stripe_mode'] ?? 'test' ) ) );
					if ( $stripe_mode !== 'live' ) {
						$stripe_mode = 'test';
					}
					$test_style = $stripe_mode === 'test' ? '' : ' style="display:none;"';
					$live_style = $stripe_mode === 'live' ? '' : ' style="display:none;"';
					echo '<label class="lc-toggle-row lc-toggle-row-left">';
					echo '<span class="lc-switch"><input type="checkbox" name="payments_stripe" value="1" ' . checked( 1, $options['payments_stripe'] ?? 0, false ) . '><span></span></span>';
					echo '<span>' . esc_html__( 'Activar integración', 'agenda-lite' ) . '</span>';
					echo '</label>';
					echo '<label>' . esc_html__( 'Modo', 'agenda-lite' ) . '</label>';
					echo '<select name="stripe_mode">';
					echo '<option value="test" ' . selected( 'test', $stripe_mode, false ) . '>' . esc_html__( 'Pruebas (test)', 'agenda-lite' ) . '</option>';
					echo '<option value="live" ' . selected( 'live', $stripe_mode, false ) . '>' . esc_html__( 'Producción (live)', 'agenda-lite' ) . '</option>';
					echo '</select>';
					echo '<div data-lc-stripe-mode-field="test"' . wp_kses_data( $test_style ) . '>';
					echo '<label>' . esc_html__( 'Public key (test)', 'agenda-lite' ) . '</label><input name="stripe_public_key_test" value="' . esc_attr( $options['stripe_public_key_test'] ?? '' ) . '" placeholder="pk_test_..." />';
					echo '<label>' . esc_html__( 'Secret key (test)', 'agenda-lite' ) . '</label><input name="stripe_secret_key_test" value="' . esc_attr( $options['stripe_secret_key_test'] ?? '' ) . '" placeholder="sk_test_..." />';
					echo '<label>' . esc_html__( 'Webhook secret (test)', 'agenda-lite' ) . '</label><input name="stripe_webhook_secret_test" value="' . esc_attr( $options['stripe_webhook_secret_test'] ?? '' ) . '" placeholder="whsec_..." />';
					echo '</div>';
					echo '<div data-lc-stripe-mode-field="live"' . wp_kses_data( $live_style ) . '>';
					echo '<label>' . esc_html__( 'Public key (live)', 'agenda-lite' ) . '</label><input name="stripe_public_key_live" value="' . esc_attr( $options['stripe_public_key_live'] ?? '' ) . '" placeholder="pk_live_..." />';
					echo '<label>' . esc_html__( 'Secret key (live)', 'agenda-lite' ) . '</label><input name="stripe_secret_key_live" value="' . esc_attr( $options['stripe_secret_key_live'] ?? '' ) . '" placeholder="sk_live_..." />';
					echo '<label>' . esc_html__( 'Webhook secret (live)', 'agenda-lite' ) . '</label><input name="stripe_webhook_secret_live" value="' . esc_attr( $options['stripe_webhook_secret_live'] ?? '' ) . '" placeholder="whsec_..." />';
					echo '</div>';
					echo '<p class="description">' . esc_html__( 'Webhook Stripe:', 'agenda-lite' ) . ' <code>' . esc_html( self::stripe_webhook_url() ) . '</code></p>';
				},
			),
			array(
				'key'      => 'payments_transfer',
				'label'    => __( 'Transferencia bancaria', 'agenda-lite' ),
				'desc'     => __( 'Permite reservas con pago manual por transferencia. Las reservas quedan pendientes hasta validación manual del pago.', 'agenda-lite' ),
				'category' => __( 'Pagos', 'agenda-lite' ),
				'logo'     => LITECAL_URL . 'assets/logos/banco.svg',
				'active'   => ! empty( $options['payments_transfer'] ),
				'config'   => function () use ( $options ) {
					$transfer_active = ! empty( $options['payments_transfer'] );
					echo '<label class="lc-toggle-row lc-toggle-row-left">';
					echo '<span class="lc-switch"><input type="checkbox" name="payments_transfer" value="1" data-lc-transfer-toggle data-lc-initial-active="' . ( $transfer_active ? '1' : '0' ) . '" ' . checked( 1, $options['payments_transfer'] ?? 0, false ) . '><span></span></span>';
					echo '<span>' . esc_html__( 'Activar integración', 'agenda-lite' ) . '</span>';
					echo '</label>';
					echo '<label>' . esc_html__( 'Titular / Beneficiario', 'agenda-lite' ) . '</label><input name="transfer_holder" value="' . esc_attr( $options['transfer_holder'] ?? '' ) . '" />';
					echo '<label>' . esc_html__( 'Banco', 'agenda-lite' ) . '</label><input name="transfer_bank" value="' . esc_attr( $options['transfer_bank'] ?? '' ) . '" />';
					echo '<label>' . esc_html__( 'País', 'agenda-lite' ) . '</label><input name="transfer_country" value="' . esc_attr( $options['transfer_country'] ?? '' ) . '" />';
					echo '<label>' . esc_html__( 'Moneda', 'agenda-lite' ) . '</label><input name="transfer_currency" value="' . esc_attr( $options['transfer_currency'] ?? '' ) . '" placeholder="CLP / USD" />';
					echo '<label>' . esc_html__( 'N° de cuenta', 'agenda-lite' ) . '</label><input name="transfer_account_number" value="' . esc_attr( $options['transfer_account_number'] ?? '' ) . '" />';
					echo '<label>' . esc_html__( 'Tipo de cuenta', 'agenda-lite' ) . '</label><input name="transfer_account_type" value="' . esc_attr( $options['transfer_account_type'] ?? '' ) . '" placeholder="' . esc_attr__( 'Corriente / Ahorro', 'agenda-lite' ) . '" />';
						echo '<label>' . esc_html__( 'Correo de confirmación (opcional)', 'agenda-lite' ) . '</label><input name="transfer_confirmation_email" value="' . esc_attr( $options['transfer_confirmation_email'] ?? '' ) . '" />';
						echo '<label>' . esc_html__( 'Datos adicionales (IBAN / SWIFT / ABA / etc.)', 'agenda-lite' ) . '</label>';
						echo '<textarea name="transfer_extra_fields" rows="4" placeholder="' . esc_attr__( "Ejemplo:\nIBAN: ...\nSWIFT: ...", 'agenda-lite' ) . '">' . esc_textarea( $options['transfer_extra_fields'] ?? '' ) . '</textarea>';
						echo '<label>' . esc_html__( 'Instrucciones para el cliente', 'agenda-lite' ) . '</label>';
						echo '<textarea name="transfer_instructions" rows="4" placeholder="' . esc_attr__( 'Indica cómo reportar el pago y qué referencia usar.', 'agenda-lite' ) . '">' . esc_textarea( $options['transfer_instructions'] ?? '' ) . '</textarea>';
						echo '<p class="description">' . esc_html__( 'Las reservas por transferencia quedan en estado Pendiente hasta que confirmes el pago o canceles la reserva manualmente.', 'agenda-lite' ) . '</p>';
				},
			),
			array(
				'key'      => 'google_calendar',
				'label'    => __( 'Google Calendar', 'agenda-lite' ),
				'desc'     => __( 'Sincroniza tus reservas con Google Calendar para evitar conflictos. Mantén tu agenda actualizada automáticamente y recibe notificaciones en tu calendario.', 'agenda-lite' ),
				'category' => __( 'Calendario', 'agenda-lite' ),
				'logo'     => LITECAL_URL . 'assets/logos/googlecalendar.svg',
				'active'   => ! empty( $options['google_calendar'] ),
				'config'   => function () use ( $options ) {
					$oauth                = get_option( 'litecal_google_oauth', array() );
					$connected            = ! empty( $oauth['access_token'] ) || ! empty( $oauth['refresh_token'] );
					$redirect_uri         = \LiteCal\Modules\Calendar\GoogleCalendar\GoogleCalendarModule::get_redirect_uri();
					$google_client_id     = trim( (string) ( $options['google_client_id'] ?? '' ) );
					$google_client_secret = trim( (string) ( $options['google_client_secret'] ?? '' ) );
					$creds_valid          = (bool) preg_match( '/^\d+-[A-Za-z0-9\-]+\.apps\.googleusercontent\.com$/', $google_client_id )
						&& (bool) preg_match( '/^[A-Za-z0-9_-]{20,}$/', $google_client_secret );
					$status_class         = 'is-neutral';
					$status_text          = __( 'Ingresa Client ID y Client Secret para validar.', 'agenda-lite' );
					if ( $connected ) {
						$status_class = 'is-success';
						$status_text  = __( 'OK: Credenciales válidas. Conexión lista para Google Calendar.', 'agenda-lite' );
					} elseif ( $google_client_id !== '' || $google_client_secret !== '' ) {
						$status_class = $creds_valid ? 'is-neutral' : 'is-error';
						$status_text  = $creds_valid
							? __( 'Validando credenciales con Google...', 'agenda-lite' )
							: __( 'Error: Credenciales incorrectas. Revisa Client ID y Client Secret.', 'agenda-lite' );
					}
					echo '<label class="lc-toggle-row lc-toggle-row-left">';
					echo '<span class="lc-switch"><input type="checkbox" name="google_calendar" value="1" ' . checked( 1, $options['google_calendar'] ?? 0, false ) . '><span></span></span>';
					echo '<span>' . esc_html__( 'Activar integración', 'agenda-lite' ) . '</span>';
					echo '</label>';
					echo '<label>Client ID</label><input name="google_client_id" data-lc-google-client-id value="' . esc_attr( $google_client_id ) . '" />';
					echo '<label>Client Secret</label><input name="google_client_secret" data-lc-google-client-secret value="' . esc_attr( $google_client_secret ) . '" />';
					echo '<div class="lc-google-cred-status ' . esc_attr( $status_class ) . '" data-lc-google-cred-status data-lc-google-connected="' . ( $connected ? '1' : '0' ) . '" data-lc-google-ajax="' . esc_url( admin_url( 'admin-ajax.php' ) ) . '" data-lc-google-nonce="' . esc_attr( wp_create_nonce( 'litecal_google_validate_credentials' ) ) . '">' . esc_html( $status_text ) . '</div>';
					echo '<label>' . esc_html__( 'URI de redirección autorizada', 'agenda-lite' ) . '</label><input value="' . esc_attr( $redirect_uri ) . '" readonly />';
					if ( $connected ) {
						$google_mode = sanitize_key( (string) ( $options['google_calendar_mode'] ?? 'centralized' ) );
						if ( ! in_array( $google_mode, array( 'centralized', 'per_employee' ), true ) ) {
							$google_mode = 'centralized';
						}
						$selected_calendar = trim( (string) ( $options['google_calendar_id'] ?? 'primary' ) );
						if ( $selected_calendar === '' ) {
							$selected_calendar = 'primary';
						}
						$employee_calendar_map = $options['google_calendar_employee_ids'] ?? array();
						if ( is_string( $employee_calendar_map ) ) {
							$decoded_map           = json_decode( $employee_calendar_map, true );
							$employee_calendar_map = is_array( $decoded_map ) ? $decoded_map : array();
						}
						if ( ! is_array( $employee_calendar_map ) ) {
							$employee_calendar_map = array();
						}
						$calendars = \LiteCal\Modules\Calendar\GoogleCalendar\GoogleCalendarModule::list_calendars();
						$calendar_cache_map = array();
						foreach ( (array) get_option( 'litecal_google_calendar_cache', array() ) as $cached_calendar ) {
							$cached_id = trim( (string) ( $cached_calendar['id'] ?? '' ) );
							if ( $cached_id === '' ) {
								continue;
							}
							$calendar_cache_map[ $cached_id ] = trim( (string) ( $cached_calendar['summary'] ?? $cached_id ) );
						}
						if ( ! empty( $calendars ) ) {
							update_option( 'litecal_google_calendar_cache', $calendars, false );
						} else {
							$calendars = get_option( 'litecal_google_calendar_cache', array() );
							if ( ! is_array( $calendars ) ) {
								$calendars = array();
							}
						}
						$known_calendar_ids = array();
						foreach ( $calendars as $calendar_item ) {
							$calendar_item_id = (string) ( $calendar_item['id'] ?? '' );
							if ( $calendar_item_id !== '' ) {
								$known_calendar_ids[ $calendar_item_id ] = true;
							}
						}
						if ( $selected_calendar !== '' && ! isset( $known_calendar_ids[ $selected_calendar ] ) ) {
							$fallback_summary = $calendar_cache_map[ $selected_calendar ] ?? '';
							if ( $fallback_summary === '' && method_exists( '\LiteCal\Modules\Calendar\GoogleCalendar\GoogleCalendarModule', 'get_calendar_summary' ) ) {
								$fallback_summary = (string) \LiteCal\Modules\Calendar\GoogleCalendar\GoogleCalendarModule::get_calendar_summary( $selected_calendar );
							}
							$calendars[] = array(
								'id'      => $selected_calendar,
								'summary' => $fallback_summary !== '' ? $fallback_summary : $selected_calendar,
								'primary' => $selected_calendar === 'primary',
							);
							$known_calendar_ids[ $selected_calendar ] = true;
						}
						foreach ( $employee_calendar_map as $mapped_calendar_id ) {
							$mapped_calendar_id = trim( (string) $mapped_calendar_id );
							if ( $mapped_calendar_id === '' || isset( $known_calendar_ids[ $mapped_calendar_id ] ) ) {
								continue;
							}
							$fallback_summary = $calendar_cache_map[ $mapped_calendar_id ] ?? '';
							if ( $fallback_summary === '' && method_exists( '\LiteCal\Modules\Calendar\GoogleCalendar\GoogleCalendarModule', 'get_calendar_summary' ) ) {
								$fallback_summary = (string) \LiteCal\Modules\Calendar\GoogleCalendar\GoogleCalendarModule::get_calendar_summary( $mapped_calendar_id );
							}
							$calendars[] = array(
								'id'      => $mapped_calendar_id,
								'summary' => $fallback_summary !== '' ? $fallback_summary : $mapped_calendar_id,
								'primary' => false,
							);
							$known_calendar_ids[ $mapped_calendar_id ] = true;
						}
						$employees = \LiteCal\Core\Employees::all_booking_managers( true );
						if ( ! empty( $calendars ) ) {
							echo '<label>' . esc_html__( 'Modo de asignación de calendario', 'agenda-lite' ) . '</label>';
							echo '<select name="google_calendar_mode" data-lc-google-calendar-mode>';
							echo '<option value="centralized" ' . selected( $google_mode, 'centralized', false ) . '>' . esc_html__( 'Centralizado (un calendario para todo)', 'agenda-lite' ) . '</option>';
							echo '<option value="per_employee" ' . selected( $google_mode, 'per_employee', false ) . '>' . esc_html__( 'Independiente por profesional', 'agenda-lite' ) . '</option>';
							echo '</select>';

							echo '<div data-lc-google-calendar-central' . ( $google_mode === 'centralized' ? '' : ' style="display:none;"' ) . '>';
							echo '<label>' . esc_html__( 'Calendario central', 'agenda-lite' ) . '</label>';
							echo '<select name="google_calendar_id">';
							foreach ( $calendars as $calendar ) {
								$cal_id = (string) ( $calendar['id'] ?? '' );
								if ( $cal_id === '' ) {
									continue;
								}
								$cal_label = (string) ( $calendar['summary'] ?? $cal_id );
								if ( ! empty( $calendar['primary'] ) ) {
									$cal_label .= ' (' . __( 'Principal', 'agenda-lite' ) . ')';
								}
								echo '<option value="' . esc_attr( $cal_id ) . '" ' . selected( $selected_calendar, $cal_id, false ) . '>' . esc_html( $cal_label ) . '</option>';
							}
							echo '</select>';
							echo '</div>';

							echo '<div class="lc-google-calendar-per-employee" data-lc-google-calendar-per-employee' . ( $google_mode === 'per_employee' ? '' : ' style="display:none;"' ) . '>';
							echo '<label>' . esc_html__( 'Calendario por profesional', 'agenda-lite' ) . '</label>';
							if ( ! empty( $employees ) ) {
								echo '<div class="lc-google-calendar-per-employee-grid">';
								foreach ( $employees as $employee ) {
									$employee_id = (int) ( $employee->id ?? 0 );
									if ( $employee_id <= 0 ) {
										continue;
									}
									$selected_employee_calendar = trim( (string) ( $employee_calendar_map[ $employee_id ] ?? '' ) );
									echo '<div class="lc-google-calendar-employee-row">';
									echo '<div class="lc-google-calendar-employee-name">' . esc_html( (string) ( $employee->name ?? ( '#' . $employee_id ) ) ) . '</div>';
									echo '<select name="google_calendar_employee_ids[' . esc_attr( (string) $employee_id ) . ']">';
									echo '<option value="">' . esc_html__( 'Usar calendario central', 'agenda-lite' ) . '</option>';
									foreach ( $calendars as $calendar ) {
										$cal_id = (string) ( $calendar['id'] ?? '' );
										if ( $cal_id === '' ) {
											continue;
										}
										$cal_label = (string) ( $calendar['summary'] ?? $cal_id );
										if ( ! empty( $calendar['primary'] ) ) {
											$cal_label .= ' (' . __( 'Principal', 'agenda-lite' ) . ')';
										}
										echo '<option value="' . esc_attr( $cal_id ) . '" ' . selected( $selected_employee_calendar, $cal_id, false ) . '>' . esc_html( $cal_label ) . '</option>';
									}
									echo '</select>';
									echo '</div>';
								}
								echo '</div>';
							} else {
								echo '<p class="description">' . esc_html__( 'No hay usuarios con rol Gestor de reservas para asignar calendarios.', 'agenda-lite' ) . '</p>';
							}
							echo '</div>';
						}
					}
					echo '<div class="lc-timeoff-actions" style="margin-top:12px;">';
					if ( $connected ) {
						echo '<span class="lc-badge">' . esc_html__( 'Conectado', 'agenda-lite' ) . '</span> ';
						$disconnect_url = wp_nonce_url(
							admin_url( 'admin-post.php?action=litecal_google_oauth_disconnect' ),
							'litecal_google_oauth_disconnect'
						);
						echo '<a class="button" href="' . esc_url( $disconnect_url ) . '">' . esc_html__( 'Desconectar', 'agenda-lite' ) . '</a>';
					} else {
						echo '<button class="button button-primary lc-google-connect-btn" type="button" data-lc-google-connect-button data-lc-google-connect-action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" data-lc-google-connect-nonce="' . esc_attr( wp_create_nonce( 'litecal_google_oauth_start' ) ) . '" style="display:none;">';
						echo '<img src="' . esc_url( LITECAL_URL . 'assets/logos/google.svg' ) . '" alt="Google" />';
						echo '<span>' . esc_html__( 'Conectar con Google', 'agenda-lite' ) . '</span>';
						echo '</button>';
					}
					echo '</div>';
				},
			),
			array(
				'key'      => 'zoom',
				'label'    => __( 'Zoom', 'agenda-lite' ),
				'desc'     => __( 'Ofrece videollamadas por Zoom en tus servicios. Activa la integración y comparte enlaces automáticos para sesiones profesionales y rápidas.', 'agenda-lite' ),
				'category' => __( 'Video', 'agenda-lite' ),
				'logo'     => LITECAL_URL . 'assets/logos/zoom.svg',
				'active'   => ! empty( $options['zoom'] ),
				'config'   => function () use ( $options ) {
					echo '<label class="lc-toggle-row lc-toggle-row-left">';
					echo '<input type="hidden" name="zoom" value="0" />';
					echo '<span class="lc-switch"><input type="checkbox" name="zoom" value="1" ' . checked( 1, $options['zoom'] ?? 0, false ) . '><span></span></span>';
					echo '<span>' . esc_html__( 'Activar integración', 'agenda-lite' ) . '</span>';
					echo '</label>';
					echo '<label>' . esc_html__( 'Account ID', 'agenda-lite' ) . '</label><input name="zoom_account_id" value="' . esc_attr( $options['zoom_account_id'] ?? '' ) . '" />';
					echo '<label>Client ID</label><input name="zoom_client_id" value="' . esc_attr( $options['zoom_client_id'] ?? '' ) . '" />';
					echo '<label>Client Secret</label><input name="zoom_client_secret" value="' . esc_attr( $options['zoom_client_secret'] ?? '' ) . '" />';
					echo '<label>' . esc_html__( 'Usuario Zoom (opcional)', 'agenda-lite' ) . '</label><input name="zoom_user_id" value="' . esc_attr( $options['zoom_user_id'] ?? '' ) . '" placeholder="me / usuario@dominio.com" />';
					echo '<p class="description">' . esc_html__( 'Usa credenciales Server-to-Server OAuth. Si dejas usuario vacío, se usa "me".', 'agenda-lite' ) . '</p>';
				},
			),
		);

		$pro_map = array(
			'payments_flow'   => 'payments_flow',
			'payments_mp'     => 'payments_mp',
			'payments_webpay' => 'payments_webpay',
			'payments_paypal' => 'payments_paypal',
			'payments_stripe' => 'payments_stripe',
			'google_calendar' => 'calendar_google',
			'zoom'            => 'video_zoom',
			'teams'           => 'video_teams',
		);
		foreach ( $integrations as &$app_item ) {
			$feature_key = (string) ( $pro_map[ $app_item['key'] ] ?? '' );
			if ( $feature_key === '' ) {
				$app_item['is_pro']     = false;
				$app_item['pro_locked'] = false;
				continue;
			}
			$app_item['is_pro']      = true;
			$app_item['pro_feature'] = $feature_key;
			$app_item['pro_locked']  = ! self::pro_feature_enabled( $feature_key );
			if ( ! empty( $app_item['pro_locked'] ) ) {
				$app_item['active'] = false;
			}
		}
		unset( $app_item );

		$selected_integration = sanitize_key( wp_unslash( $_GET['integration'] ?? '' ) );
		$selected_app         = null;
		foreach ( $integrations as $app_item ) {
			if ( ! empty( $app_item['coming_soon'] ) ) {
				continue;
			}
			if ( $app_item['key'] === $selected_integration ) {
				$selected_app = $app_item;
				break;
			}
		}

		if ( is_array( $selected_app ) ) {
			echo '<div class="lc-panel lc-integration-sheet' . ( ! empty( $selected_app['pro_locked'] ) ? ' lc-pro-lock' : '' ) . '">';
			echo '<div class="lc-integration-sheet-header">';
			echo '<div class="lc-integration-sheet-title">';
			if ( ! empty( $selected_app['logo'] ) ) {
				echo '<img class="lc-integration-logo" src="' . esc_url( $selected_app['logo'] ) . '" alt="' . esc_attr( $selected_app['label'] ) . '" />';
			} else {
				echo '<span class="lc-integration-logo lc-integration-logo-icon"><i class="' . esc_attr( $selected_app['icon'] ?? 'ri-plug-line' ) . '"></i></span>';
			}
			echo '<div>';
			echo '<strong>' . esc_html( $selected_app['label'] ) . '</strong>';
			echo '<div class="lc-integration-desc">' . esc_html( $selected_app['desc'] ) . '</div>';
			echo '</div>';
			echo '</div>';
			echo '<a class="button" href="' . esc_url( Helpers::admin_url( 'integrations' ) ) . '">' . esc_html__( 'Volver al listado', 'agenda-lite' ) . '</a>';
			echo '</div>';
			echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="lc-integration-sheet-form">';
			wp_nonce_field( 'litecal_save_settings' );
			echo '<input type="hidden" name="action" value="litecal_save_settings" />';
			echo '<input type="hidden" name="tab" value="integrations" />';
			echo '<input type="hidden" name="integration_scope" value="' . esc_attr( $selected_app['key'] ) . '" />';
			echo '<div class="lc-integration-sheet-body">';
			if ( ! empty( $selected_app['pro_locked'] ) ) {
				echo wp_kses_post( self::pro_lock_note_html( __( 'Esta integración está incluida en Agenda Lite Pro y se desbloquea con una licencia válida.', 'agenda-lite' ) ) );
			} elseif ( ! empty( $selected_app['config'] ) && is_callable( $selected_app['config'] ) ) {
				call_user_func( $selected_app['config'] );
			}
			echo '</div>';
			echo '<div class="lc-integration-sheet-actions">';
			if ( ! empty( $selected_app['pro_locked'] ) ) {
				echo wp_kses_post( self::pro_upgrade_disabled_button_html( __( 'Actualiza a Pro para activar integración', 'agenda-lite' ) ) );
			} else {
				echo '<button type="submit" class="button button-primary">' . esc_html__( 'Guardar cambios', 'agenda-lite' ) . '</button>';
			}
			echo '</div>';
			echo '</form>';
			if ( ! empty( $selected_app['pro_locked'] ) ) {
				echo '<p class="description" style="margin-top:10px;">' . esc_html__( 'Esta integración es parte de Agenda Lite Pro. Instala y activa tu licencia para habilitarla.', 'agenda-lite' ) . '</p>';
			}
			echo '</div>';
		} else {
			$category_order = array(
				'pagos'      => 1,
				'marketing'  => 2,
				'seguridad'  => 3,
				'calendario' => 4,
				'video'      => 5,
			);
			usort(
				$integrations,
				static function ( $a, $b ) use ( $category_order ) {
					$cat_a  = sanitize_key( remove_accents( (string) ( $a['category'] ?? '' ) ) );
					$cat_b  = sanitize_key( remove_accents( (string) ( $b['category'] ?? '' ) ) );
					$rank_a = $category_order[ $cat_a ] ?? 99;
					$rank_b = $category_order[ $cat_b ] ?? 99;
					if ( $rank_a !== $rank_b ) {
						return $rank_a <=> $rank_b;
					}
					return strcasecmp( (string) ( $a['label'] ?? '' ), (string) ( $b['label'] ?? '' ) );
				}
			);
			echo '<div class="lc-integrations-grid">';
			foreach ( $integrations as $app ) {
				$active         = ! empty( $app['active'] );
				$is_coming_soon = ! empty( $app['coming_soon'] );
				$is_pro_locked  = ! empty( $app['pro_locked'] );
				echo '<div class="lc-integration-card ' . ( $active ? 'is-active' : '' ) . ( $is_coming_soon ? ' is-coming-soon' : '' ) . ( $is_pro_locked ? ' is-pro-locked lc-pro-lock' : '' ) . '">';
				echo '<div class="lc-integration-head">';
				if ( ! empty( $app['logo'] ) ) {
					echo '<img class="lc-integration-logo" src="' . esc_url( $app['logo'] ) . '" alt="' . esc_attr( $app['label'] ) . '" />';
				} else {
					echo '<span class="lc-integration-logo lc-integration-logo-icon"><i class="' . esc_attr( $app['icon'] ?? 'ri-plug-line' ) . '"></i></span>';
				}
				echo '<div>';
				echo '<div class="lc-integration-title">' . esc_html( $app['label'] ) . '</div>';
				echo '<div class="lc-integration-desc">' . esc_html( $app['desc'] ) . '</div>';
				echo '<div class="lc-integration-meta"><span>' . esc_html__( 'Autor: Agenda Lite', 'agenda-lite' ) . '</span><span class="lc-integration-tag lc-event-tag lc-event-tag--location">' . esc_html( $app['category'] ) . '</span>';
				if ( ! empty( $app['is_pro'] ) ) {
					echo '<span class="lc-integration-tag lc-event-tag lc-event-tag--location"><i class="ri-vip-crown-line" aria-hidden="true"></i> ' . esc_html__( 'Pro', 'agenda-lite' ) . '</span>';
				}
				echo '</div>';
				echo '</div>';
				$status_label = $is_coming_soon ? esc_html__( 'Próximamente', 'agenda-lite' ) : ( $active ? esc_html__( 'Activado', 'agenda-lite' ) : esc_html__( 'Desactivado', 'agenda-lite' ) );
				echo '<div class="lc-integration-status ' . esc_attr( ( $active ? 'is-active' : 'is-inactive' ) . ( $is_coming_soon ? ' is-coming-soon' : '' ) ) . '"><span class="dot"></span>' . esc_html( $status_label ) . '</div>';
				echo '</div>';
				echo '<div class="lc-integration-actions">';
				if ( $is_coming_soon ) {
					echo '<button class="button" type="button" disabled aria-disabled="true">' . esc_html__( 'Próximamente', 'agenda-lite' ) . '</button>';
				} elseif ( $is_pro_locked ) {
					echo wp_kses_post( self::pro_upgrade_disabled_button_html( __( 'Actualizar a Pro para activar integración', 'agenda-lite' ) ) );
				} else {
					$integration_url = Helpers::admin_url( 'integrations' ) . '&integration=' . rawurlencode( $app['key'] );
					echo '<a class="button" href="' . esc_url( $integration_url ) . '">' . esc_html__( 'Configurar', 'agenda-lite' ) . '</a>';
				}
				echo '</div>';
				echo '</div>';
			}
			echo '</div>';
		}

		echo '</div>';
		echo '</div>';
		self::shell_end();
	}

	public static function upgrade() {
		if ( class_exists( '\\LiteCalPro\\Licensing\\AdminPage' ) ) {
			\LiteCalPro\Licensing\AdminPage::render();
			return;
		}
		self::shell_start( 'upgrade' );
		echo '<div class="lc-admin">';
		echo '<h1>' . esc_html__( '👑 Upgrade to Pro', 'agenda-lite' ) . '</h1>';
		echo '<div class="lc-panel">';
		echo '<h2 style="margin:0 0 8px 0;">' . esc_html__( 'Beneficios de Agenda Lite Pro', 'agenda-lite' ) . '</h2>';
		echo '<p>' . esc_html__( 'Desbloquea el sistema completo y elimina todas las limitaciones del plan Free.', 'agenda-lite' ) . '</p>';
		echo '<ul style="list-style:disc;margin-left:20px;">';
		echo '<li><strong>' . esc_html__( 'Multimoneda', 'agenda-lite' ) . ':</strong> ' . esc_html__( 'Acepta y gestiona reservas en distintas monedas según tu configuración.', 'agenda-lite' ) . '</li>';
		echo '<li><strong>' . esc_html__( 'Pagos online nacionales', 'agenda-lite' ) . ':</strong> ' . esc_html__( 'Flow, Webpay Plus y Mercado Pago para cobros locales.', 'agenda-lite' ) . '</li>';
		echo '<li><strong>' . esc_html__( 'Pagos online internacionales', 'agenda-lite' ) . ':</strong> ' . esc_html__( 'PayPal y Stripe Checkout para cobrar a clientes de cualquier país.', 'agenda-lite' ) . '</li>';
		echo '<li><strong>' . esc_html__( 'Sincronización con Google Calendar', 'agenda-lite' ) . ':</strong> ' . esc_html__( 'Mantén tu agenda sincronizada automáticamente.', 'agenda-lite' ) . '</li>';
		echo '<li><strong>' . esc_html__( 'Sincronización con Google Meet', 'agenda-lite' ) . ':</strong> ' . esc_html__( 'Genera y gestiona reuniones en línea desde tus reservas.', 'agenda-lite' ) . '</li>';
		echo '<li><strong>' . esc_html__( 'Sincronización con Zoom', 'agenda-lite' ) . ':</strong> ' . esc_html__( 'Conecta tus reservas con sesiones de Zoom.', 'agenda-lite' ) . '</li>';
		echo '<li><strong>' . esc_html__( 'Múltiples profesionales por servicio', 'agenda-lite' ) . ':</strong> ' . esc_html__( 'Permite asignar más de un profesional por servicio.', 'agenda-lite' ) . '</li>';
		echo '<li><strong>' . esc_html__( 'Horas extras en la reserva', 'agenda-lite' ) . ':</strong> ' . esc_html__( 'Agrega tiempo adicional con costo extra dentro del flujo de reserva.', 'agenda-lite' ) . '</li>';
		echo '<li><strong>' . esc_html__( 'Adjuntar archivos en formularios', 'agenda-lite' ) . ':</strong> ' . esc_html__( 'Permite que tus clientes suban archivos durante la reserva.', 'agenda-lite' ) . '</li>';
		echo '<li><strong>' . esc_html__( 'Vacaciones y feriados', 'agenda-lite' ) . ':</strong> ' . esc_html__( 'Bloquea fechas no laborables con reglas avanzadas.', 'agenda-lite' ) . '</li>';
		echo '<li><strong>' . esc_html__( 'Permitir invitados en reservas', 'agenda-lite' ) . ':</strong> ' . esc_html__( 'Habilita que el cliente agregue invitados en cada cita.', 'agenda-lite' ) . '</li>';
		echo '<li><strong>' . esc_html__( 'Autogestión avanzada', 'agenda-lite' ) . ':</strong> ' . esc_html__( 'Reagendar y cancelar con reglas avanzadas de control.', 'agenda-lite' ) . '</li>';
		echo '<li><strong>' . esc_html__( 'Límites avanzados de agenda', 'agenda-lite' ) . ':</strong> ' . esc_html__( 'Define límites por día y reservas futuras para controlar tu operación.', 'agenda-lite' ) . '</li>';
		echo '<li><strong>' . esc_html__( 'Recordatorios avanzados por servicio', 'agenda-lite' ) . ':</strong> ' . esc_html__( 'Personaliza recordatorios según cada servicio.', 'agenda-lite' ) . '</li>';
		echo '<li><strong>' . esc_html__( 'Actualizaciones privadas + validación de licencia', 'agenda-lite' ) . ':</strong> ' . esc_html__( 'Recibe actualizaciones privadas y activa funciones Pro con tu licencia.', 'agenda-lite' ) . '</li>';
		echo '</ul>';
		echo '<p style="margin-top:16px;">';
		echo wp_kses_post( self::pro_upgrade_disabled_button_html( __( 'Actualiza a Pro para desbloquear funciones premium', 'agenda-lite' ), 'button button-primary' ) );
		echo '</p>';
		echo '</div>';
		echo '</div>';
		self::shell_end();
	}

	public static function settings() {
		$settings    = get_option( 'litecal_settings', array() );
		$from_name   = $settings['email_from_name'] ?? get_bloginfo( 'name' );
		$from_email  = $settings['email_from_email'] ?? get_option( 'admin_email' );
		$currency    = $settings['currency'] ?? 'USD';
		$time_format = $settings['time_format'] ?? '12h';
		$asset_mode  = sanitize_key( (string) ( $settings['asset_mode'] ?? 'cdn_fallback' ) );
		if ( ! in_array( $asset_mode, array( 'cdn_fallback', 'local_only' ), true ) ) {
			$asset_mode = 'cdn_fallback';
		}
		$trust_cloudflare_proxy         = ! empty( $settings['trust_cloudflare_proxy'] );
		$revoke_caps_on_deactivate      = ! empty( $settings['revoke_caps_on_deactivate'] );
		$seo_brand                      = $settings['seo_brand'] ?? get_bloginfo( 'name' );
		$seo_fallback_description       = $settings['seo_fallback_description'] ?? '';
		$seo_default_image              = $settings['seo_default_image'] ?? '';
		$smtp_host                      = $settings['smtp_host'] ?? '';
		$smtp_port                      = $settings['smtp_port'] ?? '';
		$smtp_user                      = $settings['smtp_user'] ?? '';
		$smtp_pass                      = $settings['smtp_pass'] ?? '';
		$smtp_encryption                = $settings['smtp_encryption'] ?? 'none';
		$reminder_first_hours           = max( 0, min( 720, (int) ( $settings['reminder_first_hours'] ?? 24 ) ) );
		$reminder_second_hours          = max( 0, min( 720, (int) ( $settings['reminder_second_hours'] ?? 12 ) ) );
		$reminder_third_hours           = max( 0, min( 720, (int) ( $settings['reminder_third_hours'] ?? 1 ) ) );
		$manage_reschedule_enabled      = ! array_key_exists( 'manage_reschedule_enabled', $settings ) || ! empty( $settings['manage_reschedule_enabled'] );
		$manage_cancel_free_enabled     = ! array_key_exists( 'manage_cancel_free_enabled', $settings ) || ! empty( $settings['manage_cancel_free_enabled'] );
		$manage_cancel_paid_enabled     = ! empty( $settings['manage_cancel_paid_enabled'] );
		$manage_reschedule_cutoff_hours = max( 0, min( 720, (int) ( $settings['manage_reschedule_cutoff_hours'] ?? 12 ) ) );
		$manage_cancel_cutoff_hours     = max( 0, min( 720, (int) ( $settings['manage_cancel_cutoff_hours'] ?? 24 ) ) );
		$manage_max_reschedules         = max( 0, min( 20, (int) ( $settings['manage_max_reschedules'] ?? 1 ) ) );
		$manage_cooldown_minutes        = max( 0, min( 240, (int) ( $settings['manage_cooldown_minutes'] ?? 10 ) ) );
		$manage_grace_minutes           = max( 0, min( 240, (int) ( $settings['manage_grace_minutes'] ?? 15 ) ) );
		$manage_rate_limit_token        = max( 1, min( 200, (int) ( $settings['manage_rate_limit_token'] ?? 20 ) ) );
		$manage_rate_limit_ip           = max( 1, min( 500, (int) ( $settings['manage_rate_limit_ip'] ?? 60 ) ) );
		$manage_allow_change_staff      = ! empty( $settings['manage_allow_change_staff'] );
		$manage_abuse_limit             = max( 0, min( 200, (int) ( $settings['manage_abuse_limit'] ?? 3 ) ) );
		$manage_abuse_max_codes         = max( 1, min( 20, (int) ( $settings['manage_abuse_max_codes'] ?? 2 ) ) );
		$manage_abuse_period_hours      = max( 1, min( 720, (int) ( $settings['manage_abuse_period_hours'] ?? 24 ) ) );
		$appearance                     = get_option( 'litecal_appearance', array() );
		$accent                         = $appearance['accent'] ?? '#083a53';
		$accent_text                    = $appearance['accent_text'] ?? '#ffffff';
		$templates                      = get_option( 'litecal_email_templates', array() );
		$tab                            = sanitize_text_field( wp_unslash( $_GET['tab'] ?? 'general' ) );
		if ( $tab === 'public_page' ) {
			$tab = 'seo';
		}
		if ( $tab === 'appearance' ) {
			$tab = 'general';
		}
		$template_edit = sanitize_text_field( wp_unslash( $_GET['template'] ?? '' ) );
		$audience      = sanitize_text_field( wp_unslash( $_GET['audience'] ?? 'client' ) );
		if ( ! in_array( $audience, array( 'client', 'team', 'guest' ), true ) ) {
			$audience = 'client';
		}

		self::shell_start( 'settings' );
		echo '<div class="lc-admin">';
		echo '<div class="lc-detail-header">';
		echo '<div><div class="lc-detail-title">' . esc_html__( 'Ajustes', 'agenda-lite' ) . '</div><p class="description lc-detail-description">' . esc_html__( 'Configura preferencias generales, seguridad, correos y personalización de Agenda Lite.', 'agenda-lite' ) . '</p></div>';
		echo '<div class="lc-detail-actions">';
		echo '<button class="button button-primary" type="submit" form="lc-settings-form">' . esc_html__( 'Guardar', 'agenda-lite' ) . '</button>';
		echo '<a class="button" href="' . esc_url( Helpers::admin_url( 'settings' ) ) . '">' . esc_html__( 'Cancelar', 'agenda-lite' ) . '</a>';
		echo '</div>';
		echo '</div>';
		echo '<div class="lc-detail-layout">';
		echo '<aside class="lc-subnav">';
		$tabs = array(
			'general'      => array(
				'label' => __( 'General', 'agenda-lite' ),
				'desc'  => __( 'Preferencias básicas', 'agenda-lite' ),
				'icon'  => 'ri-settings-3-line',
			),
			'seo'          => array(
				'label' => __( 'SEO', 'agenda-lite' ),
				'desc'  => __( 'Metadatos del plugin', 'agenda-lite' ),
				'icon'  => 'ri-search-eye-line',
			),
			'self_service' => array(
				'label' => __( 'Autogestión', 'agenda-lite' ),
				'desc'  => __( 'Políticas de reservas del cliente', 'agenda-lite' ),
				'icon'  => 'ri-calendar-check-line',
			),
			'abuse_security' => array(
				'label' => __( 'Seguridad antiabuso', 'agenda-lite' ),
				'desc'  => __( 'Límites y verificación', 'agenda-lite' ),
				'icon'  => 'ri-shield-user-line',
			),
			'smtp'         => array(
				'label' => __( 'SMTP', 'agenda-lite' ),
				'desc'  => __( 'Configura el envío de correos', 'agenda-lite' ),
				'icon'  => 'ri-mail-settings-line',
			),
			'templates'    => array(
				'label' => __( 'Plantillas de correos', 'agenda-lite' ),
				'desc'  => __( 'Mensajes para clientes y profesionales', 'agenda-lite' ),
				'icon'  => 'ri-draft-line',
			),
			'advanced'     => array(
				'label' => __( 'Avanzado', 'agenda-lite' ),
				'desc'  => __( 'Operación y seguridad técnica', 'agenda-lite' ),
				'icon'  => 'ri-tools-line',
			),
		);
		foreach ( $tabs as $key => $info ) {
			$active = $tab === $key ? 'is-active' : '';
			$url    = Helpers::admin_url( 'settings' ) . '&tab=' . $key;
			echo '<a class="lc-subnav-item ' . esc_attr( $active ) . '" href="' . esc_url( $url ) . '">';
			echo '<span class="lc-subnav-title"><i class="' . esc_attr( $info['icon'] ) . ' lc-subnav-icon"></i>' . esc_html( $info['label'] ) . '</span>';
			echo '<small>' . esc_html( $info['desc'] ) . '</small>';
			echo '</a>';
		}
		echo '</aside>';
		echo '<div>';
		echo '<form id="lc-settings-form" method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( 'litecal_save_settings' );
		echo '<input type="hidden" name="action" value="litecal_save_settings" />';
		echo '<input type="hidden" name="tab" value="' . esc_attr( $tab ) . '" />';

		if ( $tab === 'templates' ) {
			$legacy_template_keys = array(
				'created_client_subject',
				'created_client_body',
				'created_team_subject',
				'created_team_body',
				'created_admin_subject',
				'created_admin_body',
				'payment_failed_client_subject',
				'payment_failed_client_body',
				'payment_failed_team_subject',
				'payment_failed_team_body',
				'payment_failed_admin_subject',
				'payment_failed_admin_body',
			);
			$legacy_status_keys   = array( 'created_client', 'created_team', 'payment_failed_client', 'payment_failed_team' );
			$legacy_changed       = false;
			foreach ( $legacy_template_keys as $legacy_key ) {
				if ( isset( $templates[ $legacy_key ] ) ) {
					unset( $templates[ $legacy_key ] );
					$legacy_changed = true;
				}
			}
			if ( $legacy_changed ) {
				update_option( 'litecal_email_templates', $templates );
			}
			$tpl                   = $templates;
			$statuses              = get_option( 'litecal_email_template_status', array() );
			$legacy_status_changed = false;
			foreach ( $legacy_status_keys as $legacy_status_key ) {
				if ( isset( $statuses[ $legacy_status_key ] ) ) {
					unset( $statuses[ $legacy_status_key ] );
					$legacy_status_changed = true;
				}
			}
			if ( $legacy_status_changed ) {
				update_option( 'litecal_email_template_status', $statuses );
			}
			$sections                  = array(
				'confirmed'       => array(
					'label' => __( 'Reserva confirmada', 'agenda-lite' ),
					'desc'  => __( 'Notificación cuando se confirma una cita.', 'agenda-lite' ),
				),
				'cancelled'       => array(
					'label' => __( 'Reserva cancelada', 'agenda-lite' ),
					'desc'  => __( 'Aviso al cancelar una reserva.', 'agenda-lite' ),
				),
				'rescheduled'     => array(
					'label' => __( 'Reserva reagendada', 'agenda-lite' ),
					'desc'  => __( 'Aviso cuando cambia fecha u hora.', 'agenda-lite' ),
				),
				'updated'         => array(
					'label' => __( 'Reserva actualizada', 'agenda-lite' ),
					'desc'  => __( 'Aviso cuando cambia profesional u otros datos relevantes.', 'agenda-lite' ),
				),
				'payment_expired' => array(
					'label' => __( 'Pago expirado', 'agenda-lite' ),
					'desc'  => __( 'Aviso cuando vence el tiempo de pago.', 'agenda-lite' ),
				),
				'reminder'        => array(
					'label' => __( 'Recordatorios a servicios', 'agenda-lite' ),
					'desc'  => __( 'Envío automático antes de la cita.', 'agenda-lite' ),
				),
			);
			$reminder_templates_locked = self::is_free_plan();
			if ( $template_edit && isset( $sections[ $template_edit ] ) ) {
				$label                  = $sections[ $template_edit ]['label'];
				$template_is_pro_locked = $reminder_templates_locked && $template_edit === 'reminder';
				$get                    = function ( $key, $fallback ) use ( $tpl ) {
					$val = $tpl[ $key ] ?? '';
					return $val !== '' ? $val : $fallback;
				};
				if ( $template_is_pro_locked ) {
					echo wp_kses_post( self::pro_lock_note_html( __( 'Los correos recordatorios a servicios están disponibles en Agenda Lite Pro.', 'agenda-lite' ) ) );
					echo wp_kses_post( self::pro_upgrade_disabled_button_html( __( 'Actualiza a Pro para activar recordatorios', 'agenda-lite' ) ) );
				}
				echo '<div class="lc-card' . ( $template_is_pro_locked ? ' lc-pro-lock' : '' ) . '">';
				$audience_label = $audience === 'client'
					? __( 'Cliente', 'agenda-lite' )
					: ( $audience === 'team' ? __( 'Profesional', 'agenda-lite' ) : __( 'Invitado', 'agenda-lite' ) );
				echo '<div class="lc-inline-title"><strong>' . esc_html( $label ) . ' · ' . esc_html( $audience_label ) . '</strong></div>';
				$template_tokens = array(
					'{cliente}'      => __( 'Cliente', 'agenda-lite' ),
					'{staff_member}' => __( 'Profesional', 'agenda-lite' ),
					'{servicio}'     => __( 'Servicio', 'agenda-lite' ),
					'{fecha_humana}' => __( 'Fecha', 'agenda-lite' ),
					'{hora_humana}'  => __( 'Hora', 'agenda-lite' ),
					'{estado}'       => __( 'Estado reserva', 'agenda-lite' ),
					'{estado_pago}'  => __( 'Estado pago', 'agenda-lite' ),
					'{meet_link}'    => __( 'Enlace reunión', 'agenda-lite' ),
					'{organizacion}' => __( 'Organización', 'agenda-lite' ),
				);
				$template_builder_html = '<div class="lc-template-builder" data-lc-template-builder>';
				$template_builder_html .= '<div class="lc-template-builder-head">';
				$template_builder_html .= '<p class="description lc-template-builder-note">' . esc_html__( 'Arrastra las variables para ordenarlas o suéltalas sobre el asunto y el mensaje. También puedes hacer clic para insertarlas donde tengas el cursor.', 'agenda-lite' ) . '</p>';
				$template_builder_html .= '<div class="lc-template-token-palette" data-lc-template-palette>';
				foreach ( $template_tokens as $token => $token_label ) {
					$template_builder_html .= '<button type="button" class="lc-template-token" draggable="true" data-lc-template-token="' . esc_attr( $token ) . '"><span class="lc-template-token-code">' . esc_html( $token ) . '</span><span class="lc-template-token-label">' . esc_html( $token_label ) . '</span></button>';
				}
				$template_builder_html .= '</div>';
				$template_builder_html .= '</div>';
				$template_builder_html .= '</div>';
				if ( $template_edit === 'reminder' ) {
					echo '<div class="lc-row">';
					echo '<div><label>' . esc_html__( 'Primer recordatorio (horas antes)', 'agenda-lite' ) . '</label><input type="number" min="0" max="720" name="reminder_first_hours" value="' . esc_attr( $reminder_first_hours ) . '"' . ( $template_is_pro_locked ? ' disabled aria-disabled="true"' : '' ) . ' /></div>';
					echo '<div><label>' . esc_html__( 'Segundo recordatorio (horas antes)', 'agenda-lite' ) . '</label><input type="number" min="0" max="720" name="reminder_second_hours" value="' . esc_attr( $reminder_second_hours ) . '"' . ( $template_is_pro_locked ? ' disabled aria-disabled="true"' : '' ) . ' /></div>';
					echo '<div><label>' . esc_html__( 'Tercer recordatorio (horas antes)', 'agenda-lite' ) . '</label><input type="number" min="0" max="720" name="reminder_third_hours" value="' . esc_attr( $reminder_third_hours ) . '"' . ( $template_is_pro_locked ? ' disabled aria-disabled="true"' : '' ) . ' /></div>';
					echo '</div>';
					echo '<p class="description">' . esc_html__( 'Por defecto: 24, 12 y 1 hora antes. Usa 0 para desactivar un recordatorio.', 'agenda-lite' ) . '</p>';
				}
				$defaults = array(
					'confirmed'       => array(
						'client_subject' => __( '🎉 Reserva confirmada: {servicio} — {fecha_humana} {hora_humana}', 'agenda-lite' ),
						'client_body'    => 'Hola {cliente},

Te confirmamos que tu reserva para {servicio} quedó {estado}.

Detalles de tu reserva

Fecha: {fecha_humana}
Hora: {hora_humana}
{meet_link}

Importante
Te recomendamos estar listo(a) unos minutos antes de la hora indicada.

Saludos cordiales,
{organizacion}',
						'admin_subject'  => __( '🎉 Reserva confirmada: {servicio} — {fecha_humana} {hora_humana}', 'agenda-lite' ),
						'admin_body'     => 'Hola {staff_member},

Se confirmó una reserva para {servicio}.

Detalle de la reserva

Cliente: {cliente}
Fecha: {fecha_humana}
Hora: {hora_humana}
Estado: {estado}
{meet_link}

Queda lista para atención.

Saludos,
{organizacion}',
						'guest_subject'  => __( '🎉 Te han invitado a: {servicio} — {fecha_humana} {hora_humana}', 'agenda-lite' ),
						'guest_body'     => 'Hola,

{cliente} te invitó a una reserva de {servicio}.

Fecha: {fecha_humana}
Hora: {hora_humana}
{meet_link}

Nos vemos pronto.
Saludos,
{organizacion}',
					),
					'cancelled'       => array(
						'client_subject' => __( '❌ Reserva cancelada: {servicio} — {fecha_humana} {hora_humana}', 'agenda-lite' ),
						'client_body'    => 'Hola {cliente},

Tu reserva para {servicio} fue cancelada.

Si quieres una nueva fecha, puedes volver a reservar cuando quieras en nuestro sitio web.

Un saludo,
{organizacion}',
						'admin_subject'  => __( '❌ Reserva cancelada: {servicio} — {fecha_humana} {hora_humana}', 'agenda-lite' ),
						'admin_body'     => 'Hola {staff_member},

La reserva para {servicio} se marcó como cancelada.

Cliente: {cliente}
Fecha: {fecha_humana}
Hora: {hora_humana}

Saludos,
{organizacion}',
						'guest_subject'  => __( '❌ Invitación cancelada: {servicio} — {fecha_humana} {hora_humana}', 'agenda-lite' ),
						'guest_body'     => 'Hola,

La invitación de {servicio} fue cancelada.

Saludos,
{organizacion}',
					),
					'rescheduled'     => array(
						'client_subject' => __( '🎉 Reserva reagendada: {servicio} — {fecha_humana} {hora_humana}', 'agenda-lite' ),
						'client_body'    => 'Hola {cliente},

Tu reserva para {servicio} fue reagendada.

Nueva fecha: {fecha_humana}
Nueva hora: {hora_humana}

Importante
Te recomendamos estar listo(a) unos minutos antes de la hora indicada.

Un saludo,
{organizacion}',
						'admin_subject'  => __( '🎉 Reserva reagendada: {servicio} — {fecha_humana} {hora_humana}', 'agenda-lite' ),
						'admin_body'     => 'Hola {staff_member},

La reserva para {servicio} fue reagendada.

Cliente: {cliente}
Nueva fecha: {fecha_humana}
Nueva hora: {hora_humana}

Saludos,
{organizacion}',
						'guest_subject'  => __( '🎉 Invitación reagendada: {servicio} — {fecha_humana} {hora_humana}', 'agenda-lite' ),
						'guest_body'     => 'Hola,

La invitación de {servicio} fue reagendada.

Nueva fecha: {fecha_humana}
Nueva hora: {hora_humana}
{meet_link}

Saludos,
{organizacion}',
					),
					'updated'         => array(
						'client_subject' => __( '🎉 Reserva actualizada: {servicio} — {fecha_humana} {hora_humana}', 'agenda-lite' ),
						'client_body'    => 'Hola {cliente},

Tu reserva para {servicio} fue actualizada.

Fecha: {fecha_humana}
Hora: {hora_humana}
Estado: {estado}

Este correo confirma cambios relevantes en tu reserva, como profesional asignado u otros datos de atención.

Un saludo,
{organizacion}',
						'admin_subject'  => __( '🎉 Reserva actualizada: {servicio} — {fecha_humana} {hora_humana}', 'agenda-lite' ),
						'admin_body'     => 'Hola {staff_member},

Se actualizó una reserva para {servicio}.

Cliente: {cliente}
Fecha: {fecha_humana}
Hora: {hora_humana}
Estado: {estado}

Revisa los cambios en el detalle de la reserva.

Saludos,
{organizacion}',
						'guest_subject'  => __( '🎉 Te han invitado a: {servicio} — {fecha_humana} {hora_humana}', 'agenda-lite' ),
						'guest_body'     => 'Hola,

{cliente} te invitó a una reserva de {servicio}.

Fecha: {fecha_humana}
Hora: {hora_humana}
{meet_link}

Nos vemos pronto.
Saludos,
{organizacion}',
					),
					'payment_expired' => array(
						'client_subject' => __( '❌ Se agotó el tiempo para pagar tu reserva: {servicio} — {fecha_humana} {hora_humana}', 'agenda-lite' ),
						'client_body'    => 'Hola {cliente},

La orden de pago de tu reserva para {servicio} venció por tiempo de espera.

Fecha: {fecha_humana}
Hora: {hora_humana}
Estado del pago: {estado_pago}

Si quieres una nueva fecha, puedes volver a reservar cuando quieras en nuestro sitio web.

Un saludo,
{organizacion}',
						'admin_subject'  => __( '❌ Se agotó el tiempo para pagar tu reserva: {servicio} — {fecha_humana} {hora_humana}', 'agenda-lite' ),
						'admin_body'     => 'Hola {staff_member},

Una orden pendiente expiró para {servicio}.

Cliente: {cliente}
Fecha: {fecha_humana}
Hora: {hora_humana}
Estado del pago: {estado_pago}

Saludos,
{organizacion}',
						'guest_subject'  => __( '🎉 Te han invitado a: {servicio} — {fecha_humana} {hora_humana}', 'agenda-lite' ),
						'guest_body'     => 'Hola,

{cliente} te invitó a una reserva de {servicio}.

Fecha: {fecha_humana}
Hora: {hora_humana}
{meet_link}

Nos vemos pronto.
Saludos,
{organizacion}',
					),
					'reminder'        => array(
						'client_subject' => __( '⏰ Recordatorio: {servicio} — {fecha_humana} {hora_humana}', 'agenda-lite' ),
						'client_body'    => 'Hola {cliente},

Te recordamos tu reserva de {servicio}.

Fecha: {fecha_humana}
Hora: {hora_humana}
{meet_link}

Importante
Te recomendamos estar listo(a) unos minutos antes de la hora indicada.

Saludos,
{organizacion}',
						'admin_subject'  => __( '⏰ Recordatorio de atención: {servicio} — {fecha_humana} {hora_humana}', 'agenda-lite' ),
						'admin_body'     => 'Hola {staff_member},

Este es un recordatorio de tu atención programada para {servicio}.

Cliente: {cliente}
Fecha: {fecha_humana}
Hora: {hora_humana}
{meet_link}

Saludos,
{organizacion}',
						'guest_subject'  => __( '⏰ Recordatorio: {servicio} — {fecha_humana} {hora_humana}', 'agenda-lite' ),
						'guest_body'     => 'Hola,

Te recordamos la reserva de {servicio}.

Fecha: {fecha_humana}
Hora: {hora_humana}
{meet_link}

Saludos,
{organizacion}',
					),
				);
				$def      = $defaults[ $template_edit ];
				if ( $audience === 'client' ) {
					echo '<label>' . esc_html__( 'Asunto', 'agenda-lite' ) . '</label>';
					echo '<input name="templates[' . esc_attr( $template_edit ) . '][client_subject]" value="' . esc_attr( $get( $template_edit . '_client_subject', $def['client_subject'] ) ) . '" data-lc-template-field />';
					echo '<label>' . esc_html__( 'Mensaje', 'agenda-lite' ) . '</label>';
					echo '<textarea name="templates[' . esc_attr( $template_edit ) . '][client_body]" rows="6" data-lc-template-field>' . esc_textarea( $get( $template_edit . '_client_body', $def['client_body'] ) ) . '</textarea>';
				} elseif ( $audience === 'team' ) {
					$admin_subject = $get( $template_edit . '_team_subject', $get( $template_edit . '_admin_subject', $def['admin_subject'] ) );
					$admin_body    = $get( $template_edit . '_team_body', $get( $template_edit . '_admin_body', $def['admin_body'] ) );
					echo '<label>' . esc_html__( 'Asunto', 'agenda-lite' ) . '</label>';
					echo '<input name="templates[' . esc_attr( $template_edit ) . '][team_subject]" value="' . esc_attr( $admin_subject ) . '" data-lc-template-field />';
					echo '<label>' . esc_html__( 'Mensaje', 'agenda-lite' ) . '</label>';
					echo '<textarea name="templates[' . esc_attr( $template_edit ) . '][team_body]" rows="6" data-lc-template-field>' . esc_textarea( $admin_body ) . '</textarea>';
				} else {
					echo '<label>' . esc_html__( 'Asunto', 'agenda-lite' ) . '</label>';
					echo '<input name="templates[' . esc_attr( $template_edit ) . '][guest_subject]" value="' . esc_attr( $get( $template_edit . '_guest_subject', $def['guest_subject'] ) ) . '" data-lc-template-field />';
					echo '<label>' . esc_html__( 'Mensaje', 'agenda-lite' ) . '</label>';
					echo '<textarea name="templates[' . esc_attr( $template_edit ) . '][guest_body]" rows="6" data-lc-template-field>' . esc_textarea( $get( $template_edit . '_guest_body', $def['guest_body'] ) ) . '</textarea>';
				}
				echo $template_builder_html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Markup built from fixed internal tokens and escaped labels above.
				echo '</div>';
			} else {
				echo '<div class="lc-card lc-event-list">';
				echo '<div class="lc-event-list-header"><span>' . esc_html__( 'Plantillas de correo', 'agenda-lite' ) . '</span></div>';
				$groups                 = array(
					'client' => array(
						'label' => __( 'Cliente', 'agenda-lite' ),
						'icon'  => 'ri-user-line',
					),
					'team'   => array(
						'label' => __( 'Profesional', 'agenda-lite' ),
						'icon'  => 'ri-team-line',
					),
					'guest'  => array(
						'label' => __( 'Invitado', 'agenda-lite' ),
						'icon'  => 'ri-user-shared-line',
					),
				);
				$guest_allowed_sections = array( 'confirmed', 'cancelled', 'rescheduled', 'reminder' );
				foreach ( $groups as $group_key => $group ) {
					echo '<div class="lc-template-group">';
					echo '<div class="lc-template-group-title"><i class="' . esc_attr( $group['icon'] ) . '"></i>' . esc_html( $group['label'] ) . '</div>';
					foreach ( $sections as $key => $info ) {
						if ( $group_key === 'guest' && ! in_array( $key, $guest_allowed_sections, true ) ) {
							continue;
						}
						$status_key        = $key . '_' . $group_key;
						$is_active         = ! isset( $statuses[ $status_key ] ) || (int) $statuses[ $status_key ] === 1;
						$is_row_pro_locked = $reminder_templates_locked && $key === 'reminder';
						echo '<div class="lc-event-item' . ( $is_row_pro_locked ? ' lc-pro-lock' : '' ) . '">';
						echo '<div class="lc-event-info">';
						echo '<div class="lc-inline-title"><strong>' . esc_html( $info['label'] ) . '</strong></div>';
						echo '<div class="lc-event-sub">' . esc_html( $info['desc'] ) . '</div>';
						echo '</div>';
						echo '<div class="lc-event-actions">';
						if ( $is_row_pro_locked ) {
							echo wp_kses_post( self::pro_upgrade_disabled_button_html( __( 'Actualiza a Pro para activar recordatorios', 'agenda-lite' ) ) );
						} else {
							echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="display:inline;">';
							wp_nonce_field( 'litecal_toggle_template' );
							echo '<input type="hidden" name="action" value="litecal_toggle_template" />';
							echo '<input type="hidden" name="template" value="' . esc_attr( $status_key ) . '" />';
							echo '<label class="lc-toggle-row lc-toggle-row-left">';
							echo '<input type="hidden" name="active" value="0" />';
							echo '<span class="lc-switch"><input type="checkbox" name="active" value="1" ' . checked( true, $is_active, false ) . ' data-lc-auto-submit><span></span></span>';
							echo '<span>' . ( $is_active ? esc_html__( 'Activo', 'agenda-lite' ) : esc_html__( 'Inactivo', 'agenda-lite' ) ) . '</span>';
							echo '</label>';
							echo '</form>';
							echo '<a class="button" href="' . esc_url( Helpers::admin_url( 'settings' ) . '&tab=templates&template=' . $key . '&audience=' . $group_key ) . '">' . esc_html__( 'Editar', 'agenda-lite' ) . '</a>';
						}
						echo '</div>';
						echo '</div>';
					}
					echo '</div>';
				}
				echo '</div>';
			}
		} elseif ( $tab === 'appearance' ) {
			$show_powered_by = ! isset( $appearance['show_powered_by'] ) || ! empty( $appearance['show_powered_by'] );
			echo '<div class="lc-card">';
			echo '<div class="lc-inline-title"><strong>' . esc_html__( 'Apariencia', 'agenda-lite' ) . '</strong></div>';
			echo '<p class="description">' . esc_html__( 'Define los colores principales del calendario y los botones.', 'agenda-lite' ) . '</p>';
			echo '<label>' . esc_html__( 'Color principal', 'agenda-lite' ) . '</label>';
			echo '<div class="lc-color-row">';
			echo '<span class="lc-pickr-mount" data-lc-pickr-mount><button type="button" class="lc-pickr-trigger" data-lc-pickr-trigger aria-label="' . esc_attr__( 'Seleccionar color principal', 'agenda-lite' ) . '"></button><input class="lc-pickr-native" type="color" value="' . esc_attr( $accent ) . '" data-lc-pickr-native tabindex="-1" aria-hidden="true" /></span>';
			echo '<input class="lc-color-input lc-color-input--picker" type="text" name="accent" value="' . esc_attr( $accent ) . '" data-default-color="#083a53" data-lc-color-input />';
			echo '<span class="lc-color-value" data-lc-color-value>' . esc_html( $accent ) . '</span>';
			echo '</div>';
			echo '<label>' . esc_html__( 'Color de texto en botones', 'agenda-lite' ) . '</label>';
			echo '<div class="lc-color-row">';
			echo '<span class="lc-pickr-mount" data-lc-pickr-mount><button type="button" class="lc-pickr-trigger" data-lc-pickr-trigger aria-label="' . esc_attr__( 'Seleccionar color de texto en botones', 'agenda-lite' ) . '"></button><input class="lc-pickr-native" type="color" value="' . esc_attr( $accent_text ) . '" data-lc-pickr-native tabindex="-1" aria-hidden="true" /></span>';
			echo '<input class="lc-color-input lc-color-input--picker" type="text" name="accent_text" value="' . esc_attr( $accent_text ) . '" data-default-color="#ffffff" data-lc-color-input />';
			echo '<span class="lc-color-value" data-lc-color-value>' . esc_html( $accent_text ) . '</span>';
			echo '</div>';
			echo '<label class="lc-check-row">';
			echo '<input type="hidden" name="show_powered_by" value="0" />';
			echo '<input type="checkbox" name="show_powered_by" value="1" ' . checked( $show_powered_by, true, false ) . ' />';
			echo '<span>' . esc_html__( 'Mostrar “Desarrollado por” debajo del calendario', 'agenda-lite' ) . '</span>';
			echo '</label>';
			echo '<p class="description">' . esc_html__( 'Viene activo por defecto. Puedes desactivarlo si prefieres ocultar la marca en el frontend.', 'agenda-lite' ) . '</p>';
			echo '</div>';
		} elseif ( $tab === 'seo' ) {
			echo '<div class="lc-card">';
			echo '<div class="lc-inline-title"><strong>' . esc_html__( 'SEO', 'agenda-lite' ) . '</strong></div>';
			echo '<p class="description">' . esc_html__( 'SEO automático por servicio. Estos valores se usan como respaldo cuando falte información.', 'agenda-lite' ) . '</p>';
			echo '<label>' . esc_html__( 'Imagen SEO global (OG)', 'agenda-lite' ) . '</label>';
			echo '<input type="hidden" name="seo_default_image" value="' . esc_attr( $seo_default_image ) . '" data-lc-seo-image-input />';
			echo '<div class="lc-media-row lc-seo-media-row lc-seo-native-row">';
			echo '<div class="lc-media-preview lc-seo-media-preview lc-seo-native-preview" data-lc-seo-image-preview>';
			if ( ! empty( $seo_default_image ) ) {
				echo '<img src="' . esc_url( $seo_default_image ) . '" alt="' . esc_attr__( 'Imagen SEO', 'agenda-lite' ) . '" />';
			} else {
				echo '<span>' . esc_html__( 'Sin imagen', 'agenda-lite' ) . '</span>';
			}
			echo '</div>';
			echo '<div class="lc-seo-native-links">';
			echo '<a href="#" data-lc-seo-image-upload>' . esc_html__( 'Elegir imagen desde la biblioteca', 'agenda-lite' ) . '</a>';
			echo '<span aria-hidden="true">·</span>';
			echo '<a href="#" data-lc-seo-image-clear>' . esc_html__( 'Quitar imagen', 'agenda-lite' ) . '</a>';
			echo '</div>';
			echo '</div>';
			echo '<label>' . esc_html__( 'Título SEO base (marca/sitio)', 'agenda-lite' ) . '</label>';
			echo '<div class="lc-seo-input-wrap" data-lc-seo-length data-min="30" data-good-max="60" data-warn-max="70">';
			echo '<input name="seo_brand" value="' . esc_attr( $seo_brand ) . '" data-lc-seo-length-input />';
			echo '<div class="lc-seo-length-meter"><span data-lc-seo-length-fill></span></div>';
			echo '<div class="lc-seo-length-meta"><span data-lc-seo-length-status>' . esc_html__( 'Sin texto', 'agenda-lite' ) . '</span><strong data-lc-seo-length-count>0</strong></div>';
			echo '</div>';
			echo '<label>' . esc_html__( 'Descripción SEO global de respaldo', 'agenda-lite' ) . '</label>';
			echo '<div class="lc-seo-input-wrap" data-lc-seo-length data-min="120" data-good-max="155" data-warn-max="175">';
			echo '<textarea name="seo_fallback_description" rows="4" placeholder="' . esc_attr__( 'Describe brevemente tu servicio para usarlo como respaldo SEO.', 'agenda-lite' ) . '" data-lc-seo-length-input>' . esc_textarea( $seo_fallback_description ) . '</textarea>';
			echo '<div class="lc-seo-length-meter"><span data-lc-seo-length-fill></span></div>';
			echo '<div class="lc-seo-length-meta"><span data-lc-seo-length-status>' . esc_html__( 'Sin texto', 'agenda-lite' ) . '</span><strong data-lc-seo-length-count>0</strong></div>';
			echo '</div>';
			echo '<p class="description">' . esc_html__( 'Compatible con Yoast y RankMath: Agenda Lite no duplica título ni meta description cuando detecta esos plugins.', 'agenda-lite' ) . '</p>';
			echo '</div>';
		} elseif ( $tab === 'smtp' ) {
			echo '<div class="lc-card">';
			echo '<div class="lc-inline-title"><strong>' . esc_html__( 'SMTP', 'agenda-lite' ) . '</strong></div>';
			echo '<p class="description">' . esc_html__( 'Conecta tu servidor de correo para enviar notificaciones.', 'agenda-lite' ) . '</p>';
			echo '<label>' . esc_html__( 'Remitente emails', 'agenda-lite' ) . '</label>';
			echo '<input name="email_from_name" value="' . esc_attr( $from_name ) . '" />';
			echo '<label>' . esc_html__( 'Email remitente', 'agenda-lite' ) . '</label>';
			echo '<input name="email_from_email" value="' . esc_attr( $from_email ) . '" />';
			echo '<label>' . esc_html__( 'Host', 'agenda-lite' ) . '</label><input name="smtp_host" value="' . esc_attr( $smtp_host ) . '" />';
			echo '<label>' . esc_html__( 'Puerto', 'agenda-lite' ) . '</label><input name="smtp_port" value="' . esc_attr( $smtp_port ) . '" />';
			echo '<label>' . esc_html__( 'Usuario', 'agenda-lite' ) . '</label><input name="smtp_user" value="' . esc_attr( $smtp_user ) . '" />';
			echo '<label>' . esc_html__( 'Contraseña', 'agenda-lite' ) . '</label><input type="password" name="smtp_pass" value="' . esc_attr( $smtp_pass ) . '" />';
			echo '<label>' . esc_html__( 'Encriptación', 'agenda-lite' ) . '</label><select name="smtp_encryption">';
			$enc = array(
				'none' => __( 'Ninguna', 'agenda-lite' ),
				'ssl'  => 'SSL',
				'tls'  => 'TLS',
			);
			foreach ( $enc as $key => $label ) {
				echo '<option value="' . esc_attr( $key ) . '" ' . selected( (string) $smtp_encryption, (string) $key, false ) . '>' . esc_html( $label ) . '</option>';
			}
			echo '</select>';
			echo '</div>';
			echo '<div class="lc-card">';
			echo '<div class="lc-inline-title"><strong>' . esc_html__( 'Prueba de conexión', 'agenda-lite' ) . '</strong></div>';
			echo '<p class="description">' . esc_html__( 'Envía un correo de prueba usando los datos ingresados.', 'agenda-lite' ) . '</p>';
			echo '<label>' . esc_html__( 'Email de prueba', 'agenda-lite' ) . '</label><input name="smtp_test_email" value="' . esc_attr( $from_email ) . '" />';
			echo '<button class="button" type="submit" formaction="' . esc_url( admin_url( 'admin-post.php' ) ) . '" formmethod="post" form="lc-settings-form" name="action" value="litecal_test_smtp">' . esc_html__( 'Probar conexión', 'agenda-lite' ) . '</button>';
			echo '</div>';
		} elseif ( $tab === 'self_service' ) {
			$self_service_locked = self::is_free_plan();
			if ( $self_service_locked ) {
				$locked_settings = array(
					'manage_reschedule_enabled'      => $manage_reschedule_enabled ? 1 : 0,
					'manage_cancel_free_enabled'     => $manage_cancel_free_enabled ? 1 : 0,
					'manage_cancel_paid_enabled'     => $manage_cancel_paid_enabled ? 1 : 0,
					'manage_reschedule_cutoff_hours' => $manage_reschedule_cutoff_hours,
					'manage_cancel_cutoff_hours'     => $manage_cancel_cutoff_hours,
					'manage_max_reschedules'         => $manage_max_reschedules,
					'manage_cooldown_minutes'        => $manage_cooldown_minutes,
					'manage_grace_minutes'           => $manage_grace_minutes,
					'manage_rate_limit_token'        => $manage_rate_limit_token,
					'manage_rate_limit_ip'           => $manage_rate_limit_ip,
					'manage_allow_change_staff'      => $manage_allow_change_staff ? 1 : 0,
				);
				self::apply_free_self_service_settings( $locked_settings );
				$manage_reschedule_enabled      = ! empty( $locked_settings['manage_reschedule_enabled'] );
				$manage_cancel_free_enabled     = ! empty( $locked_settings['manage_cancel_free_enabled'] );
				$manage_cancel_paid_enabled     = ! empty( $locked_settings['manage_cancel_paid_enabled'] );
				$manage_reschedule_cutoff_hours = (int) $locked_settings['manage_reschedule_cutoff_hours'];
				$manage_cancel_cutoff_hours     = (int) $locked_settings['manage_cancel_cutoff_hours'];
				$manage_max_reschedules         = (int) $locked_settings['manage_max_reschedules'];
				$manage_cooldown_minutes        = (int) $locked_settings['manage_cooldown_minutes'];
				$manage_grace_minutes           = (int) $locked_settings['manage_grace_minutes'];
				$manage_rate_limit_token        = (int) $locked_settings['manage_rate_limit_token'];
				$manage_rate_limit_ip           = (int) $locked_settings['manage_rate_limit_ip'];
				$manage_allow_change_staff      = ! empty( $locked_settings['manage_allow_change_staff'] );
			}
			echo '<div class="' . ( $self_service_locked ? 'lc-pro-lock' : '' ) . '">';
			echo '<div class="lc-card">';
			echo '<div class="lc-inline-title"><strong>' . esc_html__( 'Permitir que el cliente reagende su reserva', 'agenda-lite' ) . '</strong></div>';
			echo '<p class="description">' . esc_html__( 'Activa esta opción para que el cliente pueda cambiar la fecha u hora desde el enlace “Reagendar” que recibe por correo. Si la desactivas, el cliente no podrá reagendar por su cuenta y deberá contactarte para cualquier cambio.', 'agenda-lite' ) . '</p>';
			echo '<label class="lc-check-row">';
			echo '<input type="hidden" name="manage_reschedule_enabled" value="0" />';
			echo '<input type="checkbox" name="manage_reschedule_enabled" value="1" ' . ( $self_service_locked ? 'disabled aria-disabled="true" ' : '' ) . checked( $manage_reschedule_enabled, true, false ) . ' />';
			echo '<span>' . esc_html__( 'Activar opción de reagendar por cliente', 'agenda-lite' ) . '</span>';
			echo '</label>';
			if ( $self_service_locked ) {
				echo wp_kses_post( self::pro_feature_hint_html( __( 'Disponible en Agenda Lite Pro.', 'agenda-lite' ) ) );
			}
			echo '</div>';

			echo '<div class="lc-card">';
			echo '<div class="lc-inline-title"><strong>' . esc_html__( 'Permitir cancelación del cliente en reservas gratis', 'agenda-lite' ) . '</strong></div>';
			echo '<p class="description">' . esc_html__( 'Si está activo, el cliente podrá cancelar reservas sin pago desde el enlace “Cancelar” del correo. Si lo desactivas, la cancelación solo podrá realizarla el administrador o soporte.', 'agenda-lite' ) . '</p>';
			echo '<label class="lc-check-row">';
			echo '<input type="hidden" name="manage_cancel_free_enabled" value="0" />';
			echo '<input type="checkbox" name="manage_cancel_free_enabled" value="1" ' . ( $self_service_locked ? 'disabled aria-disabled="true" ' : '' ) . checked( $manage_cancel_free_enabled, true, false ) . ' />';
			echo '<span>' . esc_html__( 'Activar cancelación en reservas gratis', 'agenda-lite' ) . '</span>';
			echo '</label>';
			if ( $self_service_locked ) {
				echo wp_kses_post( self::pro_feature_hint_html( __( 'Disponible en Agenda Lite Pro.', 'agenda-lite' ) ) );
			}
			echo '</div>';

			echo '<div class="lc-card">';
			echo '<div class="lc-inline-title"><strong>' . esc_html__( 'Permitir cancelación del cliente en reservas pagadas', 'agenda-lite' ) . '</strong></div>';
			echo '<p class="description">' . esc_html__( 'Si está activo, el cliente podrá cancelar reservas pagadas desde el enlace del correo. Esta opción solo controla el permiso de cancelación; la devolución de dinero o crédito se gestiona según tu política de pagos.', 'agenda-lite' ) . '</p>';
			echo '<label class="lc-check-row">';
			echo '<input type="hidden" name="manage_cancel_paid_enabled" value="0" />';
			echo '<input type="checkbox" name="manage_cancel_paid_enabled" value="1" ' . ( $self_service_locked ? 'disabled aria-disabled="true" ' : '' ) . checked( $manage_cancel_paid_enabled, true, false ) . ' />';
			echo '<span>' . esc_html__( 'Activar cancelación en reservas pagadas', 'agenda-lite' ) . '</span>';
			echo '</label>';
			if ( $self_service_locked ) {
				echo wp_kses_post( self::pro_feature_hint_html( __( 'Disponible en Agenda Lite Pro.', 'agenda-lite' ) ) );
			}
			echo '</div>';

			echo '<div class="lc-card">';
			echo '<div class="lc-inline-title"><strong>' . esc_html__( 'Cutoff para reagendar (horas)', 'agenda-lite' ) . '</strong></div>';
			echo '<p class="description">' . esc_html__( 'Define con cuánta anticipación mínima el cliente puede reagendar. Por ejemplo, si ingresas 12, el cliente podrá reagendar solo hasta 12 horas antes de la cita; dentro de ese plazo, el enlace se bloqueará para evitar cambios de último minuto.', 'agenda-lite' ) . '</p>';
			if ( $self_service_locked ) {
				echo wp_kses_post( self::pro_feature_hint_html( __( 'Disponible en Agenda Lite Pro.', 'agenda-lite' ) ) );
			}
			echo '<div class="lc-input-suffix"><input type="number" min="0" max="720" name="manage_reschedule_cutoff_hours" value="' . esc_attr( $manage_reschedule_cutoff_hours ) . '"' . ( $self_service_locked ? ' disabled aria-disabled="true"' : '' ) . ' /><span>' . esc_html__( 'Horas', 'agenda-lite' ) . '</span></div>';
			echo '</div>';

			echo '<div class="lc-card">';
			echo '<div class="lc-inline-title"><strong>' . esc_html__( 'Cutoff para cancelar (horas)', 'agenda-lite' ) . '</strong></div>';
			echo '<p class="description">' . esc_html__( 'Define con cuánta anticipación mínima el cliente puede cancelar. Por ejemplo, si ingresas 24, el cliente podrá cancelar solo hasta 24 horas antes de la cita; luego el enlace quedará bloqueado y deberá contactarte.', 'agenda-lite' ) . '</p>';
			if ( $self_service_locked ) {
				echo wp_kses_post( self::pro_feature_hint_html( __( 'Disponible en Agenda Lite Pro.', 'agenda-lite' ) ) );
			}
			echo '<div class="lc-input-suffix"><input type="number" min="0" max="720" name="manage_cancel_cutoff_hours" value="' . esc_attr( $manage_cancel_cutoff_hours ) . '"' . ( $self_service_locked ? ' disabled aria-disabled="true"' : '' ) . ' /><span>' . esc_html__( 'Horas', 'agenda-lite' ) . '</span></div>';
			echo '</div>';

			echo '<div class="lc-card">';
			echo '<div class="lc-inline-title"><strong>' . esc_html__( 'Máximo de reagendamientos (cambios)', 'agenda-lite' ) . '</strong></div>';
			echo '<p class="description">' . esc_html__( 'Indica cuántas veces se permite reagendar una misma reserva. Por ejemplo, si ingresas 1, el cliente podrá reagendar solo una vez; al intentar un segundo cambio, el sistema lo bloqueará automáticamente.', 'agenda-lite' ) . '</p>';
			if ( $self_service_locked ) {
				echo wp_kses_post( self::pro_feature_hint_html( __( 'Disponible en Agenda Lite Pro.', 'agenda-lite' ) ) );
			}
			echo '<div class="lc-input-suffix"><input type="number" min="0" max="20" name="manage_max_reschedules" value="' . esc_attr( $manage_max_reschedules ) . '"' . ( $self_service_locked ? ' disabled aria-disabled="true"' : '' ) . ' /><span>' . esc_html__( 'Cambios', 'agenda-lite' ) . '</span></div>';
			echo '</div>';

			echo '<div class="lc-card">';
			echo '<div class="lc-inline-title"><strong>' . esc_html__( 'Cooldown entre cambios (minutos)', 'agenda-lite' ) . '</strong></div>';
			echo '<p class="description">' . esc_html__( 'Tiempo de espera obligatorio entre un reagendamiento y el siguiente, para evitar que el cliente esté probando horarios sin parar. Por ejemplo, con 10 minutos, después de reagendar deberá esperar 10 minutos antes de poder volver a intentar otro cambio (si aún tiene cambios disponibles).', 'agenda-lite' ) . '</p>';
			if ( $self_service_locked ) {
				echo wp_kses_post( self::pro_feature_hint_html( __( 'Disponible en Agenda Lite Pro.', 'agenda-lite' ) ) );
			}
			echo '<div class="lc-input-suffix"><input type="number" min="0" max="240" name="manage_cooldown_minutes" value="' . esc_attr( $manage_cooldown_minutes ) . '"' . ( $self_service_locked ? ' disabled aria-disabled="true"' : '' ) . ' /><span>' . esc_html__( 'Min', 'agenda-lite' ) . '</span></div>';
			echo '</div>';

			echo '<div class="lc-card">';
			echo '<div class="lc-inline-title"><strong>' . esc_html__( 'Período de gracia (minutos)', 'agenda-lite' ) . '</strong></div>';
			echo '<p class="description">' . esc_html__( 'Ventana corta justo después de reservar para corregir errores (por ejemplo, si eligió mal el horario). Durante este período, el cliente podrá reagendar o cancelar aunque esté cerca del cutoff, y al finalizar se aplicarán las reglas normales.', 'agenda-lite' ) . '</p>';
			if ( $self_service_locked ) {
				echo wp_kses_post( self::pro_feature_hint_html( __( 'Disponible en Agenda Lite Pro.', 'agenda-lite' ) ) );
			}
			echo '<div class="lc-input-suffix"><input type="number" min="0" max="240" name="manage_grace_minutes" value="' . esc_attr( $manage_grace_minutes ) . '"' . ( $self_service_locked ? ' disabled aria-disabled="true"' : '' ) . ' /><span>' . esc_html__( 'Min', 'agenda-lite' ) . '</span></div>';
			echo '</div>';

			echo '<div class="lc-card">';
			echo '<div class="lc-inline-title"><strong>' . esc_html__( 'Límite por token / hora (solicitudes)', 'agenda-lite' ) . '</strong></div>';
			echo '<p class="description">' . esc_html__( 'Controla cuántas veces por hora se puede usar el enlace de gestión (reagendar/cancelar) de una reserva. Esto limita intentos excesivos o automatizados; considera que abrir o refrescar la página también puede contar como solicitud.', 'agenda-lite' ) . '</p>';
			if ( $self_service_locked ) {
				echo wp_kses_post( self::pro_feature_hint_html( __( 'Disponible en Agenda Lite Pro.', 'agenda-lite' ) ) );
			}
			echo '<div class="lc-input-suffix"><input type="number" min="1" max="200" name="manage_rate_limit_token" value="' . esc_attr( $manage_rate_limit_token ) . '"' . ( $self_service_locked ? ' disabled aria-disabled="true"' : '' ) . ' /><span>' . esc_html__( 'Req', 'agenda-lite' ) . '</span></div>';
			echo '</div>';

			echo '<div class="lc-card">';
			echo '<div class="lc-inline-title"><strong>' . esc_html__( 'Límite por IP / hora (solicitudes)', 'agenda-lite' ) . '</strong></div>';
			echo '<p class="description">' . esc_html__( 'Controla cuántas solicitudes por hora se permiten desde la misma dirección IP para estas acciones. Sirve para frenar bots o abusos, pero si lo pones muy bajo podría afectar a usuarios que comparten red (por ejemplo, en oficinas).', 'agenda-lite' ) . '</p>';
			if ( $self_service_locked ) {
				echo wp_kses_post( self::pro_feature_hint_html( __( 'Disponible en Agenda Lite Pro.', 'agenda-lite' ) ) );
			}
			echo '<div class="lc-input-suffix"><input type="number" min="1" max="500" name="manage_rate_limit_ip" value="' . esc_attr( $manage_rate_limit_ip ) . '"' . ( $self_service_locked ? ' disabled aria-disabled="true"' : '' ) . ' /><span>' . esc_html__( 'Req', 'agenda-lite' ) . '</span></div>';
			echo '</div>';

			echo '<div class="lc-card">';
			echo '<div class="lc-inline-title"><strong>' . esc_html__( 'Permitir cambio de profesional al reagendar', 'agenda-lite' ) . '</strong></div>';
			echo '<p class="description">' . esc_html__( 'Si está activo, al reagendar el cliente podrá elegir otro profesional disponible dentro del mismo servicio. Si lo desactivas, el cliente solo podrá reagendar manteniendo el mismo profesional para asegurar continuidad.', 'agenda-lite' ) . '</p>';
			echo '<label class="lc-check-row">';
			echo '<input type="hidden" name="manage_allow_change_staff" value="0" />';
			echo '<input type="checkbox" name="manage_allow_change_staff" value="1" ' . ( $self_service_locked ? 'disabled aria-disabled="true" ' : '' ) . checked( $manage_allow_change_staff, true, false ) . ' />';
			echo '<span>' . esc_html__( 'Activar cambio de profesional', 'agenda-lite' ) . '</span>';
			echo '</label>';
			if ( $self_service_locked ) {
				echo wp_kses_post( self::pro_feature_hint_html( __( 'Disponible en Agenda Lite Pro.', 'agenda-lite' ) ) );
			}
			echo '</div>';
			echo '</div>';
		} elseif ( $tab === 'abuse_security' ) {
			$abuse_locked = self::is_free_plan();
			echo '<div class="' . ( $abuse_locked ? 'lc-pro-lock' : '' ) . '">';
			echo '<div class="lc-card">';
			echo '<div class="lc-inline-title"><strong>' . esc_html__( 'Seguridad antiabuso', 'agenda-lite' ) . '</strong></div>';
			echo '<p class="description">' . esc_html__( 'Configura límites para reservas y el bloqueo automático cuando se detecta abuso.', 'agenda-lite' ) . '</p>';
			if ( $abuse_locked ) {
				echo wp_kses_post( self::pro_feature_hint_html( __( 'Disponible en Agenda Lite Pro.', 'agenda-lite' ) ) );
			}
			echo '<div class="lc-input-suffix"><input type="number" min="0" max="200" name="manage_abuse_limit" value="' . esc_attr( $manage_abuse_limit ) . '"' . ( $abuse_locked ? ' disabled aria-disabled="true"' : '' ) . ' /><span>' . esc_html__( 'Reservas', 'agenda-lite' ) . '</span></div>';
			echo '</div>';

			echo '<div class="lc-card">';
			echo '<div class="lc-inline-title"><strong>' . esc_html__( 'Máximo de códigos por período', 'agenda-lite' ) . '</strong></div>';
			echo '<p class="description">' . esc_html__( 'Cantidad máxima de códigos de verificación que puede recibir un cliente dentro del período definido.', 'agenda-lite' ) . '</p>';
			if ( $abuse_locked ) {
				echo wp_kses_post( self::pro_feature_hint_html( __( 'Disponible en Agenda Lite Pro.', 'agenda-lite' ) ) );
			}
			echo '<div class="lc-input-suffix"><input type="number" min="1" max="20" name="manage_abuse_max_codes" value="' . esc_attr( $manage_abuse_max_codes ) . '"' . ( $abuse_locked ? ' disabled aria-disabled="true"' : '' ) . ' /><span>' . esc_html__( 'Códigos', 'agenda-lite' ) . '</span></div>';
			echo '</div>';

			echo '<div class="lc-card">';
			echo '<div class="lc-inline-title"><strong>' . esc_html__( 'Período antiabuso (horas)', 'agenda-lite' ) . '</strong></div>';
			echo '<p class="description">' . esc_html__( 'Ventana de tiempo usada para contar reservas y códigos del control antiabuso.', 'agenda-lite' ) . '</p>';
			if ( $abuse_locked ) {
				echo wp_kses_post( self::pro_feature_hint_html( __( 'Disponible en Agenda Lite Pro.', 'agenda-lite' ) ) );
			}
			echo '<div class="lc-input-suffix"><input type="number" min="1" max="720" name="manage_abuse_period_hours" value="' . esc_attr( $manage_abuse_period_hours ) . '"' . ( $abuse_locked ? ' disabled aria-disabled="true"' : '' ) . ' /><span>' . esc_html__( 'Horas', 'agenda-lite' ) . '</span></div>';
			echo '</div>';
			echo '</div>';
		} elseif ( $tab === 'advanced' ) {
			echo '<div class="lc-card">';
			echo '<div class="lc-inline-title"><strong>' . esc_html__( 'Carga de librerías externas', 'agenda-lite' ) . '</strong></div>';
			echo '<p class="description">' . esc_html__( 'Controla cómo se cargan los assets externos del plugin. En modo offline se bloquea cualquier CDN.', 'agenda-lite' ) . '</p>';
			echo '<select name="asset_mode">';
			echo '<option value="cdn_fallback" ' . selected( $asset_mode, 'cdn_fallback', false ) . '>' . esc_html__( 'Preferir local + respaldo CDN', 'agenda-lite' ) . '</option>';
			echo '<option value="local_only" ' . selected( $asset_mode, 'local_only', false ) . '>' . esc_html__( 'Solo local (modo offline)', 'agenda-lite' ) . '</option>';
			echo '</select>';
			echo '</div>';

			echo '<div class="lc-card">';
			echo '<div class="lc-inline-title"><strong>' . esc_html__( 'Revocar permisos al desactivar el plugin', 'agenda-lite' ) . '</strong></div>';
			echo '<p class="description">' . esc_html__( 'Al desactivar el plugin se eliminan los permisos adicionales de Agenda Lite para los roles seleccionados.', 'agenda-lite' ) . '</p>';
			echo '<label class="lc-check-row">';
			echo '<input type="hidden" name="revoke_caps_on_deactivate" value="0" />';
			echo '<input type="checkbox" name="revoke_caps_on_deactivate" value="1" ' . checked( $revoke_caps_on_deactivate, true, false ) . ' />';
			echo '<span>' . esc_html__( 'Activar revocación automática', 'agenda-lite' ) . '</span>';
			echo '</label>';
			echo '</div>';

			echo '<div class="lc-card">';
			echo '<div class="lc-inline-title"><strong>' . esc_html__( 'Confiar en Cloudflare proxy para detectar IP real (CF-Connecting-IP)', 'agenda-lite' ) . '</strong></div>';
			echo '<p class="description">' . esc_html__( 'Activa esto solo si tu origen está protegido detrás de Cloudflare (o un proxy local confiable) y no es accesible de forma directa desde internet.', 'agenda-lite' ) . '</p>';
			echo '<label class="lc-check-row">';
			echo '<input type="hidden" name="trust_cloudflare_proxy" value="0" />';
			echo '<input type="checkbox" name="trust_cloudflare_proxy" value="1" ' . checked( $trust_cloudflare_proxy, true, false ) . ' />';
			echo '<span>' . esc_html__( 'Activar esta opción', 'agenda-lite' ) . '</span>';
			echo '</label>';
			echo '</div>';
		} else {
			echo '<div class="lc-card">';
			echo '<div class="lc-inline-title"><strong>' . esc_html__( 'Preferencias', 'agenda-lite' ) . '</strong></div>';
			echo '<label>' . esc_html__( 'Moneda global', 'agenda-lite' ) . '</label>';
			echo '<select name="currency" data-lc-currency-select>';
			$currencies = array(
				'USD' => 'USD (Dólar estadounidense)',
				'CLP' => 'CLP (Peso chileno)',
			);
			foreach ( $currencies as $code => $label ) {
				echo '<option value="' . esc_attr( $code ) . '" ' . selected( (string) $currency, (string) $code, false ) . '>' . esc_html( $label ) . '</option>';
			}
			echo '</select>';
			echo '<p class="description">' . esc_html__( 'Esta moneda se usa para los precios de reserva y pagos. Si cambias esta configuración, recuerda actualizar los precios en cada uno de tus servicios.', 'agenda-lite' ) . '</p>';
			$multicurrency_enabled = ! empty( $settings['multicurrency_enabled'] );
			if ( self::is_free_plan() ) {
				echo '<div class="lc-pro-lock">';
				echo '<label>' . esc_html__( '¿Activar multimoneda?', 'agenda-lite' ) . '</label>';
				echo '<select name="multicurrency_enabled" data-lc-multicurrency-toggle disabled aria-disabled="true">';
				echo '<option value="0"' . selected( $multicurrency_enabled, false, false ) . '>' . esc_html__( 'No', 'agenda-lite' ) . '</option>';
				echo '<option value="1"' . selected( $multicurrency_enabled, true, false ) . '>' . esc_html__( 'Sí', 'agenda-lite' ) . '</option>';
				echo '</select>';
				echo wp_kses_post( self::pro_feature_hint_html( __( 'La función de multimoneda está disponible en Agenda Lite Pro.', 'agenda-lite' ) ) );
				echo '</div>';
			} else {
				$multicurrency_provider_rates = self::multicurrency_provider_rates( $settings );
				$integrations                 = get_option( 'litecal_integrations', array() );
				$active_provider_keys         = self::active_payment_provider_keys( $integrations );
				$provider_defs                = array(
					'flow'   => array(
						'label' => __( 'Flow', 'agenda-lite' ),
						'logo'  => LITECAL_URL . 'assets/logos/flow.svg',
					),
					'webpay' => array(
						'label' => __( 'Webpay Plus', 'agenda-lite' ),
						'logo'  => LITECAL_URL . 'assets/logos/webpayplus.svg',
					),
					'mp'     => array(
						'label' => __( 'MercadoPago', 'agenda-lite' ),
						'logo'  => LITECAL_URL . 'assets/logos/mercadopago.svg',
					),
					'paypal' => array(
						'label' => __( 'PayPal', 'agenda-lite' ),
						'logo'  => LITECAL_URL . 'assets/logos/paypal.svg',
					),
				);
				$has_same_currency_provider   = false;
				$has_other_currency_provider  = false;
				foreach ( $active_provider_keys as $provider_key ) {
					if ( $provider_key === 'stripe' ) {
						continue;
					}
					$provider_currency = self::provider_base_currency( $provider_key );
					if ( $provider_currency === $currency ) {
						$has_same_currency_provider = true;
					} else {
						$has_other_currency_provider = true;
					}
				}
				$can_enable_multicurrency = $has_same_currency_provider && $has_other_currency_provider;
				echo '<label>' . esc_html__( '¿Activar multimoneda?', 'agenda-lite' ) . '</label>';
				echo '<select name="multicurrency_enabled" data-lc-multicurrency-toggle>';
				echo '<option value="0"' . selected( $multicurrency_enabled, false, false ) . '>' . esc_html__( 'No', 'agenda-lite' ) . '</option>';
				echo '<option value="1"' . selected( $multicurrency_enabled, true, false ) . '>' . esc_html__( 'Sí', 'agenda-lite' ) . '</option>';
				echo '</select>';
				$multicurrency_style = $multicurrency_enabled ? '' : ' style="display:none;"';
				echo '<div class="lc-multicurrency-panel"' . wp_kses_data( $multicurrency_style ) . ' data-lc-multicurrency-panel data-lc-mc-can-enable="' . esc_attr( $can_enable_multicurrency ? '1' : '0' ) . '">';
				$warning_style = $can_enable_multicurrency ? ' style="display:none;"' : '';
				echo '<div class="lc-multicurrency-warning" data-lc-mc-warning' . wp_kses_data( $warning_style ) . '>' . esc_html__( 'Necesitas al menos un medio de pago en CLP y uno en USD para activar multimoneda (por ejemplo Webpay Plus + PayPal).', 'agenda-lite' ) . '</div>';
				echo '<p class="description">' . esc_html__( 'Ingresa el valor de 1 USD en pesos chilenos (CLP). Ejemplo: si escribes 1000, significa que 1 USD equivale a 1.000 CLP. Este valor se usa para calcular montos en CLP cuando un precio o pago está en USD.', 'agenda-lite' ) . '</p>';
				foreach ( $provider_defs as $provider_key => $provider_meta ) {
					$provider_label    = $provider_meta['label'];
					$provider_currency = self::provider_base_currency( $provider_key );
					$is_active         = in_array( $provider_key, $active_provider_keys, true );
					$rate_value        = $multicurrency_provider_rates[ $provider_key ] ?? '';
					$row_style         = ( $provider_currency === $currency ) ? ' style="display:none;"' : '';
					echo '<div class="lc-multicurrency-row" data-lc-mc-row data-lc-mc-provider="' . esc_attr( $provider_key ) . '" data-lc-mc-provider-currency="' . esc_attr( $provider_currency ) . '" data-lc-mc-provider-active="' . esc_attr( $is_active ? '1' : '0' ) . '"' . wp_kses_data( $row_style ) . '>';
					echo '<div class="lc-multicurrency-head">';
					echo '<span class="lc-multicurrency-code">';
					if ( ! empty( $provider_meta['logo'] ) ) {
						echo '<img src="' . esc_url( $provider_meta['logo'] ) . '" alt="' . esc_attr( $provider_label ) . '">';
					}
					echo '<span>' . esc_html( $provider_label . ' · ' . $provider_currency ) . '</span>';
					echo '</span>';
					echo '<span class="lc-mc-provider-state">' . esc_html( $is_active ? __( 'Activo en Integraciones', 'agenda-lite' ) : __( 'Inactivo en Integraciones', 'agenda-lite' ) ) . '</span>';
					echo '</div>';
					echo '<div class="lc-multicurrency-rate">';
					echo '<label>' . esc_html__( 'Tasa de cambio', 'agenda-lite' ) . '</label>';
					echo '<div class="lc-input-suffix">';
					echo '<input type="number" min="0.000001" step="0.000001" name="multicurrency_provider_rate[' . esc_attr( $provider_key ) . ']" value="' . esc_attr( $rate_value ) . '" data-lc-mc-rate data-lc-mc-rate-provider="' . esc_attr( $provider_key ) . '" ' . disabled( $is_active, false, false ) . ' />';
					echo '</div>';
					echo '</div>';
					echo '</div>';
				}
				echo '</div>';
			}
			echo '</div>';

			echo '<div class="lc-card">';
			echo '<div class="lc-inline-title"><strong>' . esc_html__( 'Formato horario', 'agenda-lite' ) . '</strong></div>';
			echo '<p class="description">' . esc_html__( 'Define si la hora se muestra en 12h (am/pm) o en formato 24h en el frontend y backend.', 'agenda-lite' ) . '</p>';
			echo '<select name="time_format">';
			echo '<option value="12h" ' . selected( $time_format, '12h', false ) . '>' . esc_html__( '12 horas (am/pm)', 'agenda-lite' ) . '</option>';
			echo '<option value="24h" ' . selected( $time_format, '24h', false ) . '>' . esc_html__( '24 horas', 'agenda-lite' ) . '</option>';
			echo '</select>';
			echo '</div>';

			$tz       = wp_timezone_string();
			$offset   = get_option( 'gmt_offset', 0 );
			$tz_label = $tz;
			if ( ! $tz ) {
				$sign     = $offset >= 0 ? '+' : '-';
				$abs      = abs( $offset );
				$hours    = floor( $abs );
				$mins     = ( $abs - $hours ) * 60;
				$tz_label = sprintf( 'UTC%s%02d:%02d', $sign, $hours, $mins );
			}
			$time_now = self::time_now_in_tz( $tz );
			$note     = $tz ? '' : ' (configura la zona horaria en Ajustes > Generales de WordPress)';
			echo '<div class="lc-card">';
			echo '<div class="lc-inline-title"><strong>' . esc_html__( 'Zona horaria', 'agenda-lite' ) . '</strong></div>';
			echo '<div class="lc-readonly-row">';
			echo '<div>' . esc_html( $tz_label ) . '</div>';
			echo '<a class="button button-link" href="' . esc_url( admin_url( 'options-general.php' ) ) . '" target="_blank">Cambiar en WordPress</a>';
			echo '</div>';
			echo '<p class="description">Zona horaria del sitio: ' . esc_html( $tz_label ) . '. Hora actual: ' . esc_html( $time_now ) . '.' . esc_html( $note ) . '</p>';
			echo '</div>';

			$show_powered_by = ! isset( $appearance['show_powered_by'] ) || ! empty( $appearance['show_powered_by'] );
			echo '<div class="lc-card">';
			echo '<div class="lc-inline-title"><strong>' . esc_html__( 'Apariencia', 'agenda-lite' ) . '</strong></div>';
			echo '<p class="description">' . esc_html__( 'Define los colores del calendario en frontend.', 'agenda-lite' ) . '</p>';
			echo '<div class="lc-row">';
			echo '<div>';
			echo '<label>' . esc_html__( 'Color principal', 'agenda-lite' ) . '</label>';
			echo '<div class="lc-color-row">';
			echo '<span class="lc-pickr-mount" data-lc-pickr-mount><button type="button" class="lc-pickr-trigger" data-lc-pickr-trigger aria-label="' . esc_attr__( 'Seleccionar color principal', 'agenda-lite' ) . '"></button><input class="lc-pickr-native" type="color" value="' . esc_attr( $accent ) . '" data-lc-pickr-native tabindex="-1" aria-hidden="true" /></span>';
			echo '<input class="lc-color-input lc-color-input--picker" type="text" name="accent" value="' . esc_attr( $accent ) . '" data-default-color="#083a53" data-lc-color-input />';
			echo '<span class="lc-color-value" data-lc-color-value>' . esc_html( $accent ) . '</span>';
			echo '</div>';
			echo '</div>';
			echo '<div>';
			echo '<label>' . esc_html__( 'Color de texto en botones', 'agenda-lite' ) . '</label>';
			echo '<div class="lc-color-row">';
			echo '<span class="lc-pickr-mount" data-lc-pickr-mount><button type="button" class="lc-pickr-trigger" data-lc-pickr-trigger aria-label="' . esc_attr__( 'Seleccionar color de texto en botones', 'agenda-lite' ) . '"></button><input class="lc-pickr-native" type="color" value="' . esc_attr( $accent_text ) . '" data-lc-pickr-native tabindex="-1" aria-hidden="true" /></span>';
			echo '<input class="lc-color-input lc-color-input--picker" type="text" name="accent_text" value="' . esc_attr( $accent_text ) . '" data-default-color="#ffffff" data-lc-color-input />';
			echo '<span class="lc-color-value" data-lc-color-value>' . esc_html( $accent_text ) . '</span>';
			echo '</div>';
			echo '</div>';
			echo '</div>';
			echo '</div>';
			echo '<div class="lc-card">';
			echo '<div class="lc-inline-title"><strong>' . esc_html__( 'Marca', 'agenda-lite' ) . '</strong></div>';
			echo '<label class="lc-check-row">';
			echo '<input type="hidden" name="show_powered_by" value="0" />';
			echo '<input type="checkbox" name="show_powered_by" value="1" ' . checked( $show_powered_by, true, false ) . ' />';
			echo '<span>' . esc_html__( 'Mostrar “Desarrollado por” debajo del calendario', 'agenda-lite' ) . '</span>';
			echo '</label>';
			echo '<p class="description">' . esc_html__( 'Viene activo por defecto. Puedes desactivarlo si prefieres ocultar la marca en el frontend.', 'agenda-lite' ) . '</p>';
			echo '</div>';
		}

		echo '</form>';
		echo '</div>';
		echo '</div>';
		echo '</div>';
		self::shell_end();
	}

	public static function appearance() {
		$appearance      = get_option( 'litecal_appearance', array() );
		$accent          = $appearance['accent'] ?? '#083a53';
		$accent_text     = $appearance['accent_text'] ?? '#ffffff';
		$show_powered_by = ! isset( $appearance['show_powered_by'] ) || ! empty( $appearance['show_powered_by'] );

		self::shell_start( 'appearance' );
		echo '<div class="lc-admin">';
		echo '<h1>Apariencia</h1>';
		echo '<div class="lc-card">';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( 'litecal_save_appearance' );
		echo '<input type="hidden" name="action" value="litecal_save_appearance" />';
		echo '<label>Color principal</label>';
		echo '<div class="lc-color-row">';
		echo '<span class="lc-pickr-mount" data-lc-pickr-mount><button type="button" class="lc-pickr-trigger" data-lc-pickr-trigger aria-label="' . esc_attr__( 'Seleccionar color principal', 'agenda-lite' ) . '"></button><input class="lc-pickr-native" type="color" value="' . esc_attr( $accent ) . '" data-lc-pickr-native tabindex="-1" aria-hidden="true" /></span>';
		echo '<input class="lc-color-input lc-color-input--picker" type="text" name="accent" value="' . esc_attr( $accent ) . '" data-default-color="#083a53" data-lc-color-input />';
		echo '<span class="lc-color-value" data-lc-color-value>' . esc_html( $accent ) . '</span>';
		echo '</div>';
		echo '<label>Color de texto en botones</label>';
		echo '<div class="lc-color-row">';
		echo '<span class="lc-pickr-mount" data-lc-pickr-mount><button type="button" class="lc-pickr-trigger" data-lc-pickr-trigger aria-label="' . esc_attr__( 'Seleccionar color de texto en botones', 'agenda-lite' ) . '"></button><input class="lc-pickr-native" type="color" value="' . esc_attr( $accent_text ) . '" data-lc-pickr-native tabindex="-1" aria-hidden="true" /></span>';
		echo '<input class="lc-color-input lc-color-input--picker" type="text" name="accent_text" value="' . esc_attr( $accent_text ) . '" data-default-color="#ffffff" data-lc-color-input />';
		echo '<span class="lc-color-value" data-lc-color-value>' . esc_html( $accent_text ) . '</span>';
		echo '</div>';
		echo '<label class="lc-check-row">';
		echo '<input type="hidden" name="show_powered_by" value="0" />';
		echo '<input type="checkbox" name="show_powered_by" value="1" ' . checked( $show_powered_by, true, false ) . ' />';
		echo '<span>' . esc_html__( 'Mostrar “Desarrollado por” debajo del calendario', 'agenda-lite' ) . '</span>';
		echo '</label>';
		echo '<p class="description">' . esc_html__( 'Se muestra activo por defecto. Puedes ocultarlo si no quieres mostrar la marca en el frontend.', 'agenda-lite' ) . '</p>';
		echo '<button class="button button-primary">Guardar</button>';
		echo '</form>';
		echo '</div>';
		echo '</div>';
		self::shell_end();
	}

	public static function save_appearance() {
		if ( ! self::can_manage() ) {
			wp_die( esc_html__( 'Unauthorized', 'agenda-lite' ) );
		}
		check_admin_referer( 'litecal_save_appearance' );
		$accent          = sanitize_hex_color( wp_unslash( $_POST['accent'] ?? '#083a53' ) );
		$accent_text     = sanitize_hex_color( wp_unslash( $_POST['accent_text'] ?? '#ffffff' ) );
		$show_powered_by = ! empty( $_POST['show_powered_by'] ) ? 1 : 0;
		update_option(
			'litecal_appearance',
			array(
				'accent'          => $accent ?: '#083a53',
				'accent_text'     => $accent_text ?: '#ffffff',
				'show_powered_by' => $show_powered_by,
			)
		);
		self::flash_notice( esc_html__( 'Apariencia guardada.', 'agenda-lite' ), 'success' );
		wp_safe_redirect( Helpers::admin_url( 'settings' ) );
		exit;
	}

	public static function mail_from( $from ) {
		$settings = get_option( 'litecal_settings', array() );
		$email    = sanitize_email( $settings['email_from_email'] ?? '' );
		return $email ?: $from;
	}

	public static function mail_from_name( $name ) {
		$settings  = get_option( 'litecal_settings', array() );
		$from_name = sanitize_text_field( $settings['email_from_name'] ?? '' );
		return $from_name ?: $name;
	}

	public static function test_smtp() {
		if ( ! self::can_manage() ) {
			wp_die( esc_html__( 'Unauthorized', 'agenda-lite' ) );
		}
		check_admin_referer( 'litecal_save_settings' );
		$host       = sanitize_text_field( wp_unslash( $_POST['smtp_host'] ?? '' ) );
		$port       = sanitize_text_field( wp_unslash( $_POST['smtp_port'] ?? '' ) );
		$user       = sanitize_text_field( wp_unslash( $_POST['smtp_user'] ?? '' ) );
		$pass       = (string) wp_unslash( $_POST['smtp_pass'] ?? '' ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Password must be preserved in raw form.
		$enc        = sanitize_text_field( wp_unslash( $_POST['smtp_encryption'] ?? 'none' ) );
		$test_email = sanitize_email( wp_unslash( $_POST['smtp_test_email'] ?? get_option( 'admin_email' ) ) );

		add_action(
			'phpmailer_init',
			function ( $phpmailer ) use ( $host, $port, $user, $pass, $enc ) {
				$phpmailer->isSMTP();
				$phpmailer->Host     = $host;
				$phpmailer->SMTPAuth = ! empty( $user );
				$phpmailer->Username = $user;
				$phpmailer->Password = $pass;
				if ( ! empty( $port ) ) {
					$phpmailer->Port = (int) $port;
				}
				if ( $enc && $enc !== 'none' ) {
					$phpmailer->SMTPSecure = $enc;
				}
			}
		);

		$ok = wp_mail( $test_email, 'Agenda Lite - Prueba SMTP', 'Este es un correo de prueba de Agenda Lite.' );
		if ( $ok ) {
			self::flash_notice( esc_html__( 'Correo de prueba enviado correctamente.', 'agenda-lite' ), 'success' );
		} else {
			self::flash_notice( esc_html__( 'No se pudo enviar el correo de prueba. Revisa tus datos SMTP.', 'agenda-lite' ), 'error' );
		}
		$tab = sanitize_text_field( wp_unslash( $_POST['tab'] ?? 'smtp' ) );
		wp_safe_redirect( Helpers::admin_url( 'settings' ) . '&tab=' . $tab );
		exit;
	}

	public static function toggle_template() {
		if ( ! self::can_manage() ) {
			wp_die( esc_html__( 'Unauthorized', 'agenda-lite' ) );
		}
		check_admin_referer( 'litecal_toggle_template' );
		$template = sanitize_text_field( wp_unslash( $_POST['template'] ?? '' ) );
		if ( self::is_free_plan() && strpos( $template, 'reminder_' ) === 0 ) {
			$statuses              = get_option( 'litecal_email_template_status', array() );
			$statuses[ $template ] = 0;
			update_option( 'litecal_email_template_status', $statuses );
			self::flash_notice( esc_html__( 'Los correos de recordatorio están disponibles en Agenda Lite Pro.', 'agenda-lite' ), 'error' );
			wp_safe_redirect( Helpers::admin_url( 'settings' ) . '&tab=templates' );
			exit;
		}
		if ( $template ) {
			$statuses              = get_option( 'litecal_email_template_status', array() );
			$statuses[ $template ] = ! empty( $_POST['active'] ) ? 1 : 0;
			update_option( 'litecal_email_template_status', $statuses );
			self::flash_notice( esc_html__( 'Estado de plantilla actualizado.', 'agenda-lite' ), 'success' );
		}
		wp_safe_redirect( Helpers::admin_url( 'settings' ) . '&tab=templates' );
		exit;
	}

	public static function delete_template() {
		if ( ! self::can_manage() ) {
			wp_die( esc_html__( 'Unauthorized', 'agenda-lite' ) );
		}
		$nonce = sanitize_text_field( (string) wp_unslash( $_REQUEST['_wpnonce'] ?? '' ) );
		if ( $nonce === '' || ( ! wp_verify_nonce( $nonce, 'litecal_delete_template' ) && ! wp_verify_nonce( $nonce, 'litecal_toggle_template' ) ) ) {
			self::flash_notice( esc_html__( 'Solicitud no válida. Recarga la página e inténtalo nuevamente.', 'agenda-lite' ), 'error' );
			wp_safe_redirect( Helpers::admin_url( 'settings' ) . '&tab=templates' );
			exit;
		}
		self::flash_notice( esc_html__( 'Las plantillas son fijas. Puedes activarlas o desactivarlas con el interruptor.', 'agenda-lite' ), 'error' );
		wp_safe_redirect( Helpers::admin_url( 'settings' ) . '&tab=templates' );
		exit;
	}

	public static function save_event() {
		if ( ! self::can_manage_events() ) {
			wp_die( esc_html__( 'Unauthorized', 'agenda-lite' ) );
		}
		check_admin_referer( 'litecal_save_event' );

		$id    = ! empty( $_POST['id'] ) ? (int) $_POST['id'] : 0;
		$title = sanitize_text_field( wp_unslash( $_POST['title'] ?? '' ) );
		$slug  = sanitize_title( wp_unslash( $_POST['slug'] ?? '' ) );
		if ( empty( $slug ) && ! empty( $title ) ) {
			$slug = sanitize_title( $title );
		}
		$allowed_desc     = array(
			'a'      => array(
				'href'   => true,
				'target' => true,
				'rel'    => true,
			),
			'strong' => array(),
			'b'      => array(),
			'em'     => array(),
			'i'      => array(),
		);
		$location         = sanitize_text_field( wp_unslash( $_POST['location'] ?? 'custom' ) );
		$location_details = sanitize_text_field( wp_unslash( $_POST['location_details'] ?? '' ) );
		$price_mode       = sanitize_text_field( wp_unslash( $_POST['price_mode'] ?? 'free' ) );
		if ( $price_mode === 'onsite' ) {
			$location = 'presencial';
		}
		$virtual_location = self::is_virtual_location( $location );
		if ( $virtual_location ) {
			if ( preg_match( '/\bpresencial\b/ui', $title ) ) {
				self::flash_notice( esc_html__( 'El título no puede incluir "Presencial" cuando la ubicación es virtual.', 'agenda-lite' ), 'error' );
				wp_safe_redirect( Helpers::admin_url( 'events' ) );
				exit;
			}
			$location_details = '';
		} elseif ( $location === 'presencial' && trim( $location_details ) === '' ) {
			self::flash_notice( esc_html__( 'Debes ingresar la dirección cuando la ubicación es Presencial.', 'agenda-lite' ), 'error' );
			wp_safe_redirect( Helpers::admin_url( 'events' ) );
			exit;
		}

		$data = array(
			'title'            => $title,
			'slug'             => $slug,
			'description'      => wp_kses( wp_unslash( $_POST['description'] ?? '' ), $allowed_desc ),
			'duration'         => absint( wp_unslash( $_POST['duration'] ?? 30 ) ),
			'buffer_before'    => 0,
			'buffer_after'     => 0,
			'location'         => $location,
			'location_details' => $location_details,
			'capacity'         => absint( wp_unslash( $_POST['capacity'] ?? 1 ) ),
			'status'           => sanitize_text_field( wp_unslash( $_POST['status'] ?? 'active' ) ),
			'require_payment'  => ! empty( $_POST['require_payment'] ) ? 1 : 0,
			'price'            => floatval( wp_unslash( $_POST['price'] ?? 0 ) ),
			'currency'         => sanitize_text_field( wp_unslash( $_POST['currency'] ?? 'USD' ) ),
		);

		$fields = array();
		if ( ! empty( $_POST['field_phone'] ) ) {
			$fields[] = array(
				'key'      => 'phone',
				'label'    => __( 'Teléfono', 'agenda-lite' ),
				'required' => false,
			);
		}
		if ( ! empty( $_POST['field_company'] ) ) {
			$fields[] = array(
				'key'      => 'company',
				'label'    => 'Empresa',
				'required' => false,
			);
		}
		if ( ! empty( $_POST['field_message'] ) ) {
			$fields[] = array(
				'key'      => 'message',
				'label'    => 'Mensaje',
				'required' => false,
			);
		}
		$data['custom_fields'] = wp_json_encode( $fields );

		if ( ! empty( $data['slug'] ) ) {
			$existing = Events::get_by_slug( $data['slug'] );
			if ( $existing && (int) $existing->id !== $id ) {
				self::flash_notice( esc_html__( 'El slug ya existe. Usa otro.', 'agenda-lite' ), 'error' );
				wp_safe_redirect( Helpers::admin_url( 'events' ) );
				exit;
			}
		}

		if ( $id ) {
			Events::update( $id, $data );
		} else {
			$id = Events::create( $data );
		}

		$employees = array_values( array_filter( array_map( 'intval', (array) ( $_POST['employees'] ?? array() ) ) ) );
		if ( self::is_free_plan() && ! empty( $employees ) ) {
			$employees = array( (int) $employees[0] );
		}
		if ( empty( $employees ) ) {
			$available_employees = Employees::all_booking_managers( true );
			if ( ! empty( $available_employees[0]->id ) ) {
				$employees = array( (int) $available_employees[0]->id );
			}
		}
		if ( empty( $employees ) ) {
			self::flash_notice( esc_html__( 'Debes tener al menos un profesional activo para guardar el servicio.', 'agenda-lite' ), 'error' );
			wp_safe_redirect( Helpers::admin_url( 'events' ) );
			exit;
		}
		Events::set_employees( $id, $employees );

		self::flash_notice( esc_html__( 'Servicio guardado.', 'agenda-lite' ), 'success' );
		wp_safe_redirect( Helpers::admin_url( 'events' ) );
		exit;
	}

	public static function save_event_settings() {
		if ( ! self::can_manage_events() ) {
			wp_die( esc_html__( 'Unauthorized', 'agenda-lite' ) );
		}
		check_admin_referer( 'litecal_save_event_settings' );
		$allowed_desc = array(
			'a'      => array(
				'href'   => true,
				'target' => true,
				'rel'    => true,
			),
			'strong' => array(),
			'b'      => array(),
			'em'     => array(),
			'i'      => array(),
		);
		$id           = absint( wp_unslash( $_POST['id'] ?? 0 ) );
		$tab          = sanitize_text_field( wp_unslash( $_POST['tab'] ?? 'config' ) );
		if ( $tab === 'abuse_security' ) {
			$tab = 'self_service';
		}
		if ( ! $id ) {
			wp_safe_redirect( Helpers::admin_url( 'events' ) );
			exit;
		}

		$event = Events::get( $id );
		if ( ! $event ) {
			wp_safe_redirect( Helpers::admin_url( 'events' ) );
			exit;
		}

		$data = array(
			'title'                 => $event->title,
			'slug'                  => $event->slug,
			'description'           => $event->description,
			'duration'              => $event->duration,
			'buffer_before'         => 0,
			'buffer_after'          => 0,
			'location'              => $event->location,
			'location_details'      => $event->location_details,
			'availability_override' => $event->availability_override,
			'status'                => $event->status,
			'require_payment'       => $event->require_payment,
		);

		$options = json_decode( $event->options ?: '[]', true );
		if ( ! is_array( $options ) ) {
			$options = array();
		}
		if ( ! array_key_exists( 'gap_between_bookings', $options ) ) {
			$options['gap_between_bookings'] = max( 0, (int) $event->buffer_before, (int) $event->buffer_after );
		}

		if ( $tab === 'config' ) {
			$settings      = get_option( 'litecal_settings', array() );
			$currency      = $settings['currency'] ?? 'USD';
			$data['title'] = sanitize_text_field( wp_unslash( $_POST['title'] ?? $event->title ) );
			$data['slug']  = sanitize_title( wp_unslash( $_POST['slug'] ?? $event->slug ) );
			if ( empty( $data['slug'] ) && ! empty( $data['title'] ) ) {
				$data['slug'] = sanitize_title( $data['title'] );
			}
			$data['description']      = wp_kses( wp_unslash( $_POST['description'] ?? $event->description ), $allowed_desc );
			$data['duration']         = absint( wp_unslash( $_POST['duration'] ?? $event->duration ) );
			$data['location']         = sanitize_text_field( wp_unslash( $_POST['location'] ?? $event->location ) );
			$data['location_details'] = sanitize_text_field( wp_unslash( $_POST['location_details'] ?? $event->location_details ) );
			$is_virtual_location      = self::is_virtual_location( $data['location'] );
			if ( $is_virtual_location ) {
				if ( preg_match( '/\bpresencial\b/ui', $data['title'] ) ) {
					self::flash_notice( esc_html__( 'El título no puede incluir "Presencial" cuando la ubicación es virtual.', 'agenda-lite' ), 'error' );
					wp_safe_redirect( Helpers::admin_url( 'event' ) . '&event_id=' . $id . '&tab=' . $tab );
					exit;
				}
				$data['location_details'] = '';
			} elseif ( $data['location'] === 'presencial' && trim( (string) $data['location_details'] ) === '' ) {
				self::flash_notice( esc_html__( 'Debes ingresar la dirección cuando la ubicación es Presencial.', 'agenda-lite' ), 'error' );
				wp_safe_redirect( Helpers::admin_url( 'event' ) . '&event_id=' . $id . '&tab=' . $tab );
				exit;
			}
			$data['currency'] = $currency;
			$price_mode       = sanitize_text_field( wp_unslash( $_POST['price_mode'] ?? ( $options['price_mode'] ?? 'free' ) ) );
			if ( ! in_array( $price_mode, array( 'free', 'total', 'partial_percent', 'partial_fixed', 'onsite' ), true ) ) {
				$price_mode = 'free';
			}
			if ( 'onsite' === $price_mode ) {
				$data['location'] = 'presencial';
			}
			$data['require_payment'] = in_array( $price_mode, array( 'total', 'partial_percent', 'partial_fixed' ), true ) ? 1 : 0;
			$price_regular_raw       = sanitize_text_field( wp_unslash( $_POST['price_regular'] ?? '' ) );
			$price_sale_raw          = sanitize_text_field( wp_unslash( $_POST['price_sale'] ?? '' ) );
			$price_regular           = self::parse_money( $price_regular_raw, $currency );
			$price_sale              = self::parse_money( $price_sale_raw, $currency );
			if ( $price_sale_raw !== '' && $price_sale > $price_regular ) {
				self::flash_notice( esc_html__( 'El precio de oferta no puede ser mayor al precio normal.', 'agenda-lite' ), 'error' );
				wp_safe_redirect( Helpers::admin_url( 'event' ) . '&event_id=' . $id . '&tab=' . $tab );
				exit;
			}
			if ( $price_sale > 0 && $price_sale < $price_regular ) {
				$data['price']         = $price_sale;
				$options['price_sale'] = $price_sale;
			} else {
				$data['price']         = $price_regular;
				$options['price_sale'] = '';
			}
			$options['price_regular'] = $price_regular;
			$options['price_mode']    = $price_mode;
			$partial_percent          = absint( wp_unslash( $_POST['partial_percent'] ?? ( $options['partial_percent'] ?? 30 ) ) );
			if ( ! in_array( $partial_percent, array( 10, 20, 30, 40, 50, 60, 70, 80, 90 ), true ) ) {
				$partial_percent = 30;
			}
			$options['partial_percent']      = $partial_percent;
			$partial_fixed_amount            = self::parse_money( sanitize_text_field( wp_unslash( $_POST['partial_fixed_amount'] ?? '' ) ), $currency );
			$options['partial_fixed_amount'] = $partial_fixed_amount;
			$payment_providers               = wp_unslash( $_POST['payment_providers'] ?? array() ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Sanitized below using array_map( 'sanitize_text_field', ... ).
			if ( ! is_array( $payment_providers ) ) {
				$payment_providers = array_filter( array_map( 'trim', explode( ',', (string) $payment_providers ) ) );
			}
			$payment_providers            = array_values( array_unique( array_map( 'sanitize_text_field', $payment_providers ) ) );
			$available_method_keys        = array_map(
				function ( $method ) {
					return $method['key'];
				},
				self::payment_methods_for_event( $currency, array(), null )
			);
			$options['payment_providers'] = array_values(
				array_filter(
					$payment_providers,
					function ( $provider ) use ( $available_method_keys ) {
						return in_array( $provider, $available_method_keys, true );
					}
				)
			);

			$all_employees          = Employees::all_booking_managers( true );
			$selected_employees = array_map( 'intval', wp_unslash( $_POST['employees'] ?? array() ) );
			$selected_employees     = array_values( array_filter( $selected_employees ) );
			$employee_order_raw     = sanitize_text_field( wp_unslash( $_POST['employee_order'] ?? '' ) );
			$employee_order         = array_values(
				array_filter(
					array_map(
						'intval',
						array_map( 'trim', explode( ',', $employee_order_raw ) )
					)
				)
			);
			if ( ! empty( $employee_order ) && ! empty( $selected_employees ) ) {
				$selected_lookup = array_fill_keys( $selected_employees, true );
				$ordered_ids     = array();
				foreach ( $employee_order as $ordered_id ) {
					if ( isset( $selected_lookup[ $ordered_id ] ) ) {
						$ordered_ids[] = (int) $ordered_id;
						unset( $selected_lookup[ $ordered_id ] );
					}
				}
				foreach ( $selected_employees as $employee_id ) {
					if ( isset( $selected_lookup[ $employee_id ] ) ) {
						$ordered_ids[] = (int) $employee_id;
					}
				}
				$selected_employees = $ordered_ids;
			}
			if ( self::is_free_plan() && ! empty( $selected_employees ) ) {
				$selected_employees = array( (int) $selected_employees[0] );
			}
			if ( empty( $all_employees ) ) {
				self::flash_notice( esc_html__( 'Debes tener al menos un usuario con rol Gestor de reservas antes de guardar.', 'agenda-lite' ), 'error' );
				wp_safe_redirect( Helpers::admin_url( 'event' ) . '&event_id=' . $id . '&tab=' . $tab );
				exit;
			}
			if ( empty( $selected_employees ) ) {
				self::flash_notice( esc_html__( 'Debes seleccionar un profesional.', 'agenda-lite' ), 'error' );
				wp_safe_redirect( Helpers::admin_url( 'event' ) . '&event_id=' . $id . '&tab=' . $tab );
				exit;
			}
			if ( in_array( $price_mode, array( 'total', 'partial_percent', 'partial_fixed' ), true ) && empty( $options['payment_providers'] ) ) {
				self::flash_notice( esc_html__( 'Debes asignar un medio de pago para usar pagos.', 'agenda-lite' ), 'error' );
				wp_safe_redirect( Helpers::admin_url( 'event' ) . '&event_id=' . $id . '&tab=' . $tab );
				exit;
			}
		}

		if ( $tab === 'limits' ) {
			$data['buffer_before'] = 0;
			$data['buffer_after']  = 0;
		}

		if ( $tab === 'availability' ) {
			$data['availability_override'] = ! empty( $_POST['availability_override'] ) ? 1 : 0;
		}

		$manual_status = null;
		if ( isset( $_POST['status_toggle'] ) ) {
			$data['status'] = ! empty( $_POST['status_toggle'] ) ? 'active' : 'draft';
			$manual_status  = $data['status'];
		} else {
			$data['status'] = $event->status;
		}

		if ( $tab === 'advanced' ) {
				$raw = wp_unslash( $_POST['custom_fields_json'] ?? '' ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Raw JSON is decoded and each field is sanitized below.
			$decoded = json_decode( $raw, true );
			if ( is_array( $decoded ) ) {
				$clean = array();
				foreach ( $decoded as $field ) {
					if ( empty( $field['key'] ) || empty( $field['label'] ) ) {
						continue;
					}
					$field_options = array();
					if ( ! empty( $field['options'] ) && is_array( $field['options'] ) ) {
						foreach ( $field['options'] as $opt ) {
							$opt = trim( sanitize_text_field( $opt ) );
							if ( $opt !== '' ) {
								$field_options[] = $opt;
							}
						}
					}
					$field_type = sanitize_text_field( $field['type'] ?? 'short_text' );
					if ( self::is_free_plan() && $field_type === 'file' ) {
						continue;
					}
					$item = array(
						'key'      => sanitize_key( $field['key'] ),
						'label'    => sanitize_text_field( $field['label'] ),
						'type'     => $field_type,
						'required' => ! empty( $field['required'] ),
						'enabled'  => isset( $field['enabled'] ) ? (bool) $field['enabled'] : true,
						'options'  => $field_options,
					);
					if ( $item['type'] === 'file' ) {
						$allowed = $field['file_allowed'] ?? array();
						if ( ! is_array( $allowed ) ) {
							$allowed = array();
						}
						$item['file_allowed']   = array_values( array_unique( array_map( 'sanitize_text_field', $allowed ) ) );
						$item['file_custom']    = sanitize_text_field( $field['file_custom'] ?? '' );
						$item['file_max_mb']    = (float) ( $field['file_max_mb'] ?? 5 );
						$item['file_max_files'] = (int) ( $field['file_max_files'] ?? 1 );
						$item['help']           = sanitize_text_field( $field['help'] ?? '' );
					}
					$clean[] = $item;
				}
				$data['custom_fields'] = wp_json_encode( $clean );
			} else {
				$data['custom_fields'] = $event->custom_fields;
			}
		} else {
			$data['custom_fields'] = $event->custom_fields;
		}

		if ( $tab === 'limits' ) {
			$legacy_gap                      = max( 0, (int) $event->buffer_before, (int) $event->buffer_after );
			$options['gap_between_bookings'] = max(
				0,
				absint( wp_unslash( $_POST['gap_between_bookings'] ?? ( $options['gap_between_bookings'] ?? $legacy_gap ) ) )
			);
			$options['notice_hours']         = absint( wp_unslash( $_POST['notice_hours'] ?? ( $options['notice_hours'] ?? 2 ) ) );
			if ( self::is_free_plan() ) {
				$options['limit_per_day'] = (int) ( $options['limit_per_day'] ?? 0 );
				$options['future_days']   = (int) ( $options['future_days'] ?? 30 );
			} else {
					$options['limit_per_day'] = absint( wp_unslash( $_POST['limit_per_day'] ?? ( $options['limit_per_day'] ?? 0 ) ) );
					$options['future_days']   = absint( wp_unslash( $_POST['future_days'] ?? ( $options['future_days'] ?? 30 ) ) );
			}
		}

		if ( $tab === 'self_service' ) {
			if ( self::is_free_plan() ) {
				self::apply_free_event_self_service_options( $options );
			} else {
				$options['manage_use_global']                  = ! empty( $_POST['manage_override'] ) ? 1 : 0;
				$options['manage_override']                    = $options['manage_use_global'];
				$options['manage_reschedule_enabled']          = ! empty( $_POST['manage_reschedule_enabled'] ) ? 1 : 0;
				$options['manage_cancel_free_enabled']         = ! empty( $_POST['manage_cancel_free_enabled'] ) ? 1 : 0;
				$options['manage_cancel_paid_enabled']         = ! empty( $_POST['manage_cancel_paid_enabled'] ) ? 1 : 0;
					$options['manage_reschedule_cutoff_hours'] = max( 0, min( 720, absint( wp_unslash( $_POST['manage_reschedule_cutoff_hours'] ?? ( $options['manage_reschedule_cutoff_hours'] ?? 12 ) ) ) ) );
					$options['manage_cancel_cutoff_hours']     = max( 0, min( 720, absint( wp_unslash( $_POST['manage_cancel_cutoff_hours'] ?? ( $options['manage_cancel_cutoff_hours'] ?? 24 ) ) ) ) );
				$options['manage_max_reschedules']         = max( 0, min( 20, absint( wp_unslash( $_POST['manage_max_reschedules'] ?? ( $options['manage_max_reschedules'] ?? 1 ) ) ) ) );
				$options['manage_cooldown_minutes']        = max( 0, min( 240, absint( wp_unslash( $_POST['manage_cooldown_minutes'] ?? ( $options['manage_cooldown_minutes'] ?? 10 ) ) ) ) );
				$options['manage_grace_minutes']           = max( 0, min( 240, absint( wp_unslash( $_POST['manage_grace_minutes'] ?? ( $options['manage_grace_minutes'] ?? 15 ) ) ) ) );
				$options['manage_allow_change_staff']          = ! empty( $_POST['manage_allow_change_staff'] ) ? 1 : 0;
			}
		}
		if ( $tab === 'extras' ) {
			$settings = get_option( 'litecal_settings', array() );
			$currency = $event->currency ?: ( $settings['currency'] ?? 'CLP' );
			if ( ! self::event_extras_enabled() ) {
				self::flash_notice( esc_html__( 'Extras es una función de Agenda Lite Pro.', 'agenda-lite' ), 'error' );
			} else {
				$raw_items = wp_unslash( $_POST['extras_items_json'] ?? '' ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- JSON is decoded and sanitized field by field below.
				$decoded   = json_decode( (string) $raw_items, true );
				$clean     = array();
				if ( is_array( $decoded ) ) {
					foreach ( $decoded as $index => $item ) {
						if ( ! is_array( $item ) ) {
							continue;
						}
						$name = trim( sanitize_text_field( (string) ( $item['name'] ?? '' ) ) );
						if ( $name === '' ) {
							continue;
						}
						$raw_price = $item['price'] ?? 0;
						$price     = is_numeric( $raw_price )
							? (float) $raw_price
							: self::parse_money( sanitize_text_field( (string) $raw_price ), $currency );
						$price = max( 0, (float) $price );
						$extra_id = sanitize_key( (string) ( $item['id'] ?? '' ) );
						if ( $extra_id === '' ) {
							$extra_id = 'extra_' . substr( md5( $name . '|' . (int) $index ), 0, 10 );
						}
						$clean[] = array(
							'id'    => $extra_id,
							'name'  => $name,
							'price' => round( $price, 2 ),
							'image' => esc_url_raw( (string) ( $item['image'] ?? '' ) ),
						);
						if ( count( $clean ) >= 30 ) {
							break;
						}
					}
				}
				$interval_minutes = max( 5, min( 240, absint( wp_unslash( $_POST['extras_hours_interval_minutes'] ?? 15 ) ) ) );
				$hours_selector   = sanitize_key( (string) wp_unslash( $_POST['extras_hours_selector'] ?? 'select' ) );
				if ( ! in_array( $hours_selector, array( 'select', 'stepper' ), true ) ) {
					$hours_selector = 'select';
				}
				$options['extras_items'] = $clean;
				$options['extras_hours'] = array(
					'enabled'            => ! empty( $_POST['extras_hours_enabled'] ) ? 1 : 0,
					'label'              => sanitize_text_field( (string) wp_unslash( $_POST['extras_hours_label'] ?? __( 'Horas extra', 'agenda-lite' ) ) ),
					'interval_minutes'   => $interval_minutes,
					'price_per_interval' => round(
						max(
							0,
							self::parse_money(
								sanitize_text_field( (string) wp_unslash( $_POST['extras_hours_price_per_interval'] ?? '' ) ),
								$currency
							)
						),
						2
					),
					'max_units'          => max( 1, min( 96, absint( wp_unslash( $_POST['extras_hours_max_units'] ?? 8 ) ) ) ),
					'selector'           => $hours_selector,
				);
			}
		}
		if ( $tab === 'config' ) {
			if ( self::is_free_plan() ) {
				$options['allow_guests'] = 0;
				$options['max_guests']   = 0;
			} else {
				$options['allow_guests']   = ! empty( $_POST['allow_guests'] ) ? 1 : 0;
					$options['max_guests'] = $options['allow_guests']
						? max( 1, min( self::MAX_GUESTS_PER_BOOKING, absint( wp_unslash( $_POST['max_guests'] ?? ( $options['max_guests'] ?? 0 ) ) ) ) )
						: 0;
			}
		}
		if ( $tab === 'apps' ) {
			$options['app_flow']   = ! empty( $_POST['app_flow'] ) ? 1 : 0;
			$options['app_mp']     = ! empty( $_POST['app_mp'] ) ? 1 : 0;
			$options['app_webpay'] = ! empty( $_POST['app_webpay'] ) ? 1 : 0;
			$options['app_paypal'] = ! empty( $_POST['app_paypal'] ) ? 1 : 0;
			$options['app_gcal']   = ! empty( $_POST['app_gcal'] ) ? 1 : 0;
			$options['app_zoom']   = ! empty( $_POST['app_zoom'] ) ? 1 : 0;
			$options['app_teams']  = ! empty( $_POST['app_teams'] ) ? 1 : 0;
			$options['app_meet']   = ! empty( $_POST['app_meet'] ) ? 1 : 0;
		}
		if ( $tab === 'availability' ) {
			$options['schedule_id'] = sanitize_text_field( wp_unslash( $_POST['schedule_id'] ?? ( $options['schedule_id'] ?? 'default' ) ) );
			if ( self::is_free_plan() ) {
				$options['schedule_id'] = (string) get_option( 'litecal_default_schedule', 'default' );
			}
		}
		$data['options'] = wp_json_encode( $options );

		if ( $tab === 'config' && ! empty( $data['slug'] ) ) {
			$existing = Events::get_by_slug( $data['slug'] );
			if ( $existing && (int) $existing->id !== $id ) {
				self::flash_notice( esc_html__( 'El slug ya existe. Usa otro.', 'agenda-lite' ), 'error' );
				wp_safe_redirect( Helpers::admin_url( 'event' ) . '&event_id=' . $id . '&tab=' . $tab );
				exit;
			}
		}

		Events::update( $id, $data );

		if ( $tab === 'config' ) {
			Events::set_employees( $id, $selected_employees );
			if ( $manual_status === 'active' && ! self::event_has_active_employee( $id ) ) {
				Events::update( $id, array( 'status' => 'draft' ) );
				self::flash_notice( esc_html__( 'Para activar el servicio debes asignar un profesional activo.', 'agenda-lite' ), 'error' );
				wp_safe_redirect( Helpers::admin_url( 'event' ) . '&event_id=' . $id . '&tab=' . $tab );
				exit;
			}
			self::refresh_all_event_statuses();
		}

		if ( $tab === 'availability' && ! empty( $_POST['availability_override'] ) ) {
			global $wpdb;
			$table = $wpdb->prefix . 'litecal_availability';
			$wpdb->delete(
				$table,
				array(
					'scope'    => 'event',
					'scope_id' => $id,
				)
			);
			$now  = current_time( 'mysql' );
			$days = array_map( 'absint', (array) wp_unslash( $_POST['day'] ?? array() ) );
			foreach ( $days as $index => $day ) {
				$start = sanitize_text_field( wp_unslash( $_POST['start'][ $index ] ?? '' ) );
				$end   = sanitize_text_field( wp_unslash( $_POST['end'][ $index ] ?? '' ) );
				if ( ! $start || ! $end ) {
					continue;
				}
				$wpdb->insert(
					$table,
					array(
						'scope'       => 'event',
						'scope_id'    => $id,
						'day_of_week' => (int) $day,
						'start_time'  => $start . ':00',
						'end_time'    => $end . ':00',
						'created_at'  => $now,
					)
				);
			}
		}

		self::flash_notice( esc_html__( 'Cambios guardados.', 'agenda-lite' ), 'success' );
		wp_safe_redirect( Helpers::admin_url( 'event' ) . '&event_id=' . $id . '&tab=' . $tab );
		exit;
	}

	public static function delete_event() {
		if ( ! self::can_manage_events() ) {
			wp_die( esc_html__( 'Unauthorized', 'agenda-lite' ) );
		}
		check_admin_referer( 'litecal_delete_event' );
		$id = absint( wp_unslash( $_POST['id'] ?? 0 ) );
		if ( $id ) {
			Events::delete( $id );
		}
		wp_safe_redirect( Helpers::admin_url( 'events' ) );
		exit;
	}

	public static function duplicate_event() {
		if ( ! self::can_manage() ) {
			wp_die( esc_html__( 'Unauthorized', 'agenda-lite' ) );
		}
		check_admin_referer( 'litecal_duplicate_event' );
		$id = absint( wp_unslash( $_POST['id'] ?? 0 ) );
		if ( ! $id ) {
			wp_safe_redirect( Helpers::admin_url( 'events' ) );
			exit;
		}
		$event = Events::get( $id );
		if ( ! $event ) {
			wp_safe_redirect( Helpers::admin_url( 'events' ) );
			exit;
		}
		$data = (array) $event;
		unset( $data['id'] );
		$data['title']  = $event->title . ' (Copia)';
		$data['slug']   = $event->slug . '-copia-' . wp_rand( 100, 999 );
		$data['status'] = 'draft';
		$new_id         = Events::create( $data );
		$employees      = array_map( 'intval', wp_list_pluck( Events::employees( $id ), 'id' ) );
		Events::set_employees( $new_id, $employees );
		wp_safe_redirect( Helpers::admin_url( 'events' ) . '&edit=' . $new_id );
		exit;
	}

	public static function save_employee() {
		if ( ! self::can_access_staff_panel() ) {
			wp_die( esc_html__( 'Unauthorized', 'agenda-lite' ) );
		}
		check_admin_referer( 'litecal_save_employee' );
		$id = ! empty( $_POST['id'] ) ? (int) $_POST['id'] : 0;
		if ( $id <= 0 ) {
			self::flash_notice( esc_html__( 'Profesional inválido.', 'agenda-lite' ), 'error' );
			wp_safe_redirect( Helpers::admin_url( 'employees' ) );
			exit;
		}
		$employee = Employees::get( $id );
		if ( ! $employee || (int) ( $employee->wp_user_id ?? 0 ) <= 0 ) {
			self::flash_notice( esc_html__( 'Solo puedes editar profesionales sincronizados desde WordPress.', 'agenda-lite' ), 'error' );
			wp_safe_redirect( Helpers::admin_url( 'employees' ) );
			exit;
		}
		$scope_employee_id = self::current_user_employee_id();
		if ( $scope_employee_id > 0 && ! self::can_manage() && $scope_employee_id !== (int) $employee->id ) {
			wp_die( esc_html__( 'Unauthorized', 'agenda-lite' ) );
		}
		self::flash_notice( esc_html__( 'La foto y el cargo se editan desde el perfil de usuario de WordPress.', 'agenda-lite' ), 'success' );
		$target = (int) ( $employee->wp_user_id ?? 0 ) > 0
			? add_query_arg( array( 'user_id' => (int) $employee->wp_user_id ), admin_url( 'user-edit.php' ) )
			: Helpers::admin_url( 'employees' );
		wp_safe_redirect( $target );
		exit;
	}

	private static function ensure_employee_schema() {
		global $wpdb;
		$table = $wpdb->prefix . 'litecal_employees';
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name comes from trusted $wpdb->prefix.
		$has_title = $wpdb->get_var(
			$wpdb->prepare(
				"SHOW COLUMNS FROM {$table} LIKE %s",
				'title'
			)
		);
		if ( ! $has_title ) {
			$wpdb->query( "ALTER TABLE {$table} ADD COLUMN title VARCHAR(190) NULL AFTER name" );
		}
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	public static function toggle_employee_status() {
		if ( ! self::can_manage() ) {
			wp_die( esc_html__( 'Unauthorized', 'agenda-lite' ) );
		}
		check_admin_referer( 'litecal_toggle_employee_status' );
		self::flash_notice(
			esc_html__( 'El estado del profesional se controla con el rol Gestor de reservas en Usuarios de WordPress.', 'agenda-lite' ),
			'error'
		);
		wp_safe_redirect( Helpers::admin_url( 'employees' ) );
		exit;
	}

	public static function delete_employee() {
		if ( ! self::can_manage() ) {
			wp_die( esc_html__( 'Unauthorized', 'agenda-lite' ) );
		}
		check_admin_referer( 'litecal_delete_employee' );
		self::flash_notice(
			esc_html__( 'Para quitar un profesional, edita su rol de usuario en WordPress.', 'agenda-lite' ),
			'error'
		);
		wp_safe_redirect( Helpers::admin_url( 'employees' ) );
		exit;
	}

	public static function refresh_all_event_statuses() {
		global $wpdb;
		$events_table = $wpdb->prefix . 'litecal_events';
		$rel          = $wpdb->prefix . 'litecal_event_employees';
		$employees    = $wpdb->prefix . 'litecal_employees';

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table names come from trusted $wpdb->prefix.
		$all_event_ids = $wpdb->get_col( "SELECT id FROM {$events_table}" );
		if ( ! $all_event_ids ) {
			return;
		}
		$active_event_ids = $wpdb->get_col(
			"SELECT DISTINCT r.event_id
	             FROM {$rel} r
	             INNER JOIN {$employees} e ON e.id = r.employee_id
	             WHERE e.status = 'active' AND COALESCE(e.wp_user_id, 0) > 0"
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$active_map = array_fill_keys( array_map( 'intval', $active_event_ids ?: array() ), true );
		foreach ( $all_event_ids as $event_id ) {
			$event_id = (int) $event_id;
			if ( ! isset( $active_map[ $event_id ] ) ) {
				Events::update( $event_id, array( 'status' => 'draft' ) );
			}
		}
	}

	private static function event_has_active_employee( $event_id ) {
		global $wpdb;
		$rel       = $wpdb->prefix . 'litecal_event_employees';
		$employees = $wpdb->prefix . 'litecal_employees';
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table names come from trusted $wpdb->prefix.
		$count = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*)
	             FROM {$employees} e
             INNER JOIN {$rel} r ON e.id = r.employee_id
             WHERE r.event_id = %d
               AND e.status = 'active'
               AND COALESCE(e.wp_user_id, 0) > 0",
				$event_id
			)
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (int) $count > 0;
	}

	private static function can_access_booking( $booking ) {
		if ( self::can_manage() ) {
			return true;
		}
		$scope_employee_id = self::current_user_employee_id();
		if ( $scope_employee_id <= 0 ) {
			return false;
		}
		if ( ! $booking ) {
			return false;
		}
		return (int) ( $booking->employee_id ?? 0 ) === $scope_employee_id;
	}

	private static function filter_owned_booking_ids( array $ids ) {
		$ids = array_values( array_filter( array_map( 'intval', $ids ) ) );
		if ( empty( $ids ) ) {
			return array();
		}
		if ( self::can_manage() ) {
			return $ids;
		}
		$scope_employee_id = self::current_user_employee_id();
		if ( $scope_employee_id <= 0 ) {
			return array();
		}
		global $wpdb;
		$table        = $wpdb->prefix . 'litecal_bookings';
		$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
		$params       = array_merge( array( $scope_employee_id ), $ids );
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name comes from trusted $wpdb->prefix; dynamic IN placeholders are generated safely.
		$owned_ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT id FROM {$table} WHERE employee_id = %d AND id IN ({$placeholders})",
				$params
			)
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			return array_values( array_filter( array_map( 'intval', (array) $owned_ids ) ) );
	}

	public static function update_booking() {
		if ( ! self::can_access_staff_panel() ) {
			wp_die( esc_html__( 'Unauthorized', 'agenda-lite' ) );
		}
		check_admin_referer( 'litecal_update_booking' );
		$id     = absint( wp_unslash( $_POST['id'] ?? 0 ) );
		$status = sanitize_text_field( wp_unslash( $_POST['status'] ?? 'pending' ) );
		if ( $status === 'expired' ) {
			$status = 'cancelled';
		}
		if ( $id ) {
			$booking = Bookings::get( $id );
			if ( ! self::can_access_booking( $booking ) ) {
				wp_die( esc_html__( 'Unauthorized', 'agenda-lite' ) );
			}
			Bookings::update_status( $id, $status );
			Rest::notify_booking_status( $id, $status, true );
			\LiteCal\Modules\Calendar\GoogleCalendar\GoogleCalendarModule::sync_booking( $id, $status );
		}
		wp_safe_redirect( Helpers::admin_url( 'bookings' ) );
		exit;
	}

	public static function update_booking_full() {
		if ( ! self::can_access_staff_panel() ) {
			wp_die( esc_html__( 'Unauthorized', 'agenda-lite' ) );
		}
		check_admin_referer( 'litecal_update_booking_full' );
		$id = absint( wp_unslash( $_POST['id'] ?? 0 ) );
		if ( ! $id ) {
			wp_safe_redirect( Helpers::admin_url( 'dashboard' ) );
			exit;
		}
		global $wpdb;
		$table = $wpdb->prefix . 'litecal_bookings';
		$data  = array(
			'employee_id'    => ! empty( $_POST['employee_id'] ) ? absint( wp_unslash( $_POST['employee_id'] ) ) : null,
			'start_datetime' => sanitize_text_field( wp_unslash( $_POST['start_datetime'] ?? '' ) ),
			'end_datetime'   => sanitize_text_field( wp_unslash( $_POST['end_datetime'] ?? '' ) ),
			'name'           => sanitize_text_field( wp_unslash( $_POST['name'] ?? '' ) ),
			'email'          => sanitize_email( wp_unslash( $_POST['email'] ?? '' ) ),
			'phone'          => sanitize_text_field( wp_unslash( $_POST['phone'] ?? '' ) ),
			'message'        => sanitize_textarea_field( wp_unslash( $_POST['message'] ?? '' ) ),
			'status'         => sanitize_text_field( wp_unslash( $_POST['status'] ?? 'pending' ) ),
			'updated_at'     => current_time( 'mysql' ),
		);
		if ( $data['status'] === 'expired' ) {
			$data['status'] = 'cancelled';
		}
		$booking = Bookings::get( $id );
		if ( ! self::can_access_booking( $booking ) ) {
			wp_die( esc_html__( 'Unauthorized', 'agenda-lite' ) );
		}
		if ( ! self::can_manage() ) {
			$data['employee_id'] = (int) ( $booking->employee_id ?? 0 );
		}
		$wpdb->update( $table, $data, array( 'id' => $id ) );
		$notify_status = $data['status'];
		if ( $booking ) {
			$snapshot        = Bookings::decode_snapshot( $booking );
			$changes         = array();
			$relevant_update = false;
			if ( (int) $booking->employee_id !== (int) $data['employee_id'] ) {
				$changes['employee_id'] = array(
					'from' => (int) $booking->employee_id,
					'to'   => (int) $data['employee_id'],
				);
				$relevant_update        = true;
				if ( ! empty( $data['employee_id'] ) ) {
					$employee = Employees::get( (int) $data['employee_id'] );
					if ( $employee ) {
						$snapshot['employee'] = array(
							'id'         => (int) $employee->id,
							'name'       => $employee->name,
							'email'      => $employee->email,
							'title'      => $employee->title ?? '',
							'avatar_url' => $employee->avatar_url ?? '',
						);
					}
				} else {
					$snapshot['employee'] = null;
				}
			}
			if ( $booking->start_datetime !== $data['start_datetime'] ) {
				$changes['start_datetime']    = array(
					'from' => $booking->start_datetime,
					'to'   => $data['start_datetime'],
				);
				$snapshot['booking']['start'] = $data['start_datetime'];
				$relevant_update              = true;
			}
			if ( $booking->end_datetime !== $data['end_datetime'] ) {
				$changes['end_datetime']    = array(
					'from' => $booking->end_datetime,
					'to'   => $data['end_datetime'],
				);
				$snapshot['booking']['end'] = $data['end_datetime'];
				$relevant_update            = true;
			}
			if ( $booking->name !== $data['name'] ) {
				$changes['name']             = array(
					'from' => $booking->name,
					'to'   => $data['name'],
				);
				$snapshot['booking']['name'] = $data['name'];
				$parts                       = preg_split( '/\s+/', trim( (string) $data['name'] ) );
				if ( is_array( $parts ) && ! empty( $parts ) ) {
					$first_name                        = array_shift( $parts );
					$last_name                         = trim( implode( ' ', $parts ) );
					$snapshot['booking']['first_name'] = (string) $first_name;
					$snapshot['booking']['last_name']  = (string) $last_name;
				}
				$relevant_update = true;
			}
			if ( $booking->email !== $data['email'] ) {
				$changes['email']             = array(
					'from' => $booking->email,
					'to'   => $data['email'],
				);
				$snapshot['booking']['email'] = $data['email'];
				$relevant_update              = true;
			}
			if ( $booking->phone !== $data['phone'] ) {
				$changes['phone']             = array(
					'from' => $booking->phone,
					'to'   => $data['phone'],
				);
				$snapshot['booking']['phone'] = $data['phone'];
				$relevant_update              = true;
			}
			if ( $booking->message !== $data['message'] ) {
				$changes['message']             = array(
					'from' => $booking->message,
					'to'   => $data['message'],
				);
				$snapshot['booking']['message'] = $data['message'];
				$relevant_update                = true;
			}
			if ( $booking->status !== $data['status'] ) {
				$changes['status'] = array(
					'from' => $booking->status,
					'to'   => $data['status'],
				);
				$relevant_update   = true;
			}
			if ( $changes ) {
				Bookings::update_snapshot(
					$id,
					$snapshot,
					array(
						'at'      => current_time( 'mysql' ),
						'by'      => get_current_user_id(),
						'changes' => $changes,
					)
				);
			}
			if ( $booking->status === $data['status'] && $relevant_update ) {
				$notify_status = 'updated';
			}
		}
		Rest::notify_booking_status( $id, $notify_status, true );
		\LiteCal\Modules\Calendar\GoogleCalendar\GoogleCalendarModule::sync_booking( $id, $data['status'] );
		self::flash_notice( esc_html__( 'Cambios guardados.', 'agenda-lite' ), 'success' );
		wp_safe_redirect( Helpers::admin_url( 'dashboard' ) );
		exit;
	}

	public static function bulk_update_bookings() {
		if ( ! self::can_access_staff_panel() ) {
			wp_die( esc_html__( 'Unauthorized', 'agenda-lite' ) );
		}
		check_admin_referer( 'litecal_bulk_update_bookings' );
		$redirect      = sanitize_text_field( wp_unslash( $_POST['redirect'] ?? 'bookings' ) );
		$redirect_page = $redirect === 'payments' ? 'payments' : 'bookings';
		$redirect_view = sanitize_key( (string) ( $_POST['redirect_view'] ?? '' ) );
		$redirect_url  = Helpers::admin_url( $redirect_page );
		if ( $redirect_page === 'bookings' && $redirect_view === 'trash' ) {
			$redirect_url = add_query_arg( array( 'view' => 'trash' ), $redirect_url );
		}
		$status_field = sanitize_text_field( wp_unslash( $_POST['status_field'] ?? 'status' ) );
		$status_field = $status_field === 'payment_status' ? 'payment_status' : 'status';
		$ids          = wp_unslash( $_POST['ids'] ?? array() ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Normalized below with intval.
		if ( is_string( $ids ) ) {
			$ids = array_filter( explode( ',', $ids ) );
		} elseif ( ! is_array( $ids ) ) {
			$ids = array( $ids );
		}
		$ids    = array_filter( array_map( 'intval', $ids ) );
		$status = sanitize_text_field( wp_unslash( $_POST['bulk_status'] ?? '' ) );
		if ( strpos( $status, 'payment_' ) === 0 ) {
			$status_field = 'payment_status';
			$status       = substr( $status, 8 );
		}
		if ( $status === 'expired' ) {
			$status = 'cancelled';
		}
		if ( $status === 'approved' ) {
			$status = 'paid';
		}
		if ( ! self::can_manage() && $status_field === 'payment_status' ) {
			wp_die( esc_html__( 'Unauthorized', 'agenda-lite' ) );
		}
		if ( ! $ids || $status === '' ) {
			wp_safe_redirect( $redirect_url );
			exit;
		}
		if ( ! self::can_manage() && $status === 'delete' ) {
			self::flash_notice( esc_html__( 'No tienes permisos para eliminar reservas.', 'agenda-lite' ), 'error' );
			wp_safe_redirect( $redirect_url );
			exit;
		}
		$ids = self::filter_owned_booking_ids( $ids );
		if ( ! $ids ) {
			self::flash_notice( esc_html__( 'No tienes permisos para modificar esas reservas.', 'agenda-lite' ), 'error' );
			wp_safe_redirect( $redirect_url );
			exit;
		}
		if ( $status === 'restore' ) {
			if ( ! self::can_manage() ) {
				self::flash_notice( esc_html__( 'No tienes permisos para restaurar reservas.', 'agenda-lite' ), 'error' );
				wp_safe_redirect( $redirect_url );
				exit;
			}
			foreach ( $ids as $id ) {
				$booking = Bookings::get( $id );
				if ( ! $booking || (string) ( $booking->status ?? '' ) !== 'deleted' ) {
					continue;
				}
				$restore_status = self::restore_status_for_booking( $booking );
				Bookings::update_status( $id, $restore_status );
				\LiteCal\Modules\Calendar\GoogleCalendar\GoogleCalendarModule::sync_booking( $id, $restore_status );
			}
			self::flash_notice( esc_html__( 'Reservas restauradas.', 'agenda-lite' ), 'success' );
			wp_safe_redirect( $redirect_url );
			exit;
		}
		if ( $status === 'delete_permanent' ) {
			if ( ! self::can_manage() ) {
				self::flash_notice( esc_html__( 'No tienes permisos para eliminar reservas.', 'agenda-lite' ), 'error' );
				wp_safe_redirect( $redirect_url );
				exit;
			}
			foreach ( $ids as $id ) {
				Bookings::delete_permanent( (int) $id );
			}
			self::flash_notice( esc_html__( 'Reservas eliminadas permanentemente.', 'agenda-lite' ), 'success' );
			wp_safe_redirect( $redirect_url );
			exit;
		}
		if ( $status === 'delete' ) {
			if ( $status_field === 'payment_status' || $redirect_page === 'payments' ) {
				self::void_payments( $ids );
				self::flash_notice( esc_html__( 'Pagos eliminados.', 'agenda-lite' ), 'success' );
				wp_safe_redirect( $redirect_url );
				exit;
			}
			foreach ( $ids as $id ) {
				$booking = Bookings::get( $id );
				if ( ! $booking || (string) ( $booking->status ?? '' ) === 'deleted' ) {
					continue;
				}
				$snapshot                      = Bookings::decode_snapshot( $booking );
				$snapshot['booking']['status'] = 'deleted';
				Bookings::update_snapshot(
					$id,
					$snapshot,
					array(
						'at'      => current_time( 'mysql' ),
						'by'      => get_current_user_id(),
						'changes' => array(
							'status' => array(
								'from' => (string) ( $booking->status ?? '' ),
								'to'   => 'deleted',
							),
						),
					)
				);
			}
			Bookings::delete_ids( $ids );
			foreach ( $ids as $id ) {
				\LiteCal\Modules\Calendar\GoogleCalendar\GoogleCalendarModule::sync_booking( $id, 'deleted' );
			}
			self::flash_notice(
				esc_html( $redirect_page === 'payments' ? __( 'Pagos eliminados.', 'agenda-lite' ) : __( 'Reservas movidas a papelera.', 'agenda-lite' ) ),
				'success'
			);
			wp_safe_redirect( $redirect_url );
			exit;
		}
		if ( $status_field === 'payment_status' ) {
			foreach ( $ids as $id ) {
				Bookings::update_payment( $id, array( 'payment_status' => $status ) );
				if ( $status === 'paid' ) {
					$booking = Bookings::get( $id );
					if ( $booking && (string) $booking->status !== 'confirmed' ) {
						Bookings::update_status( $id, 'confirmed' );
					}
					\LiteCal\Rest\Rest::notify_booking_status( $id, 'confirmed', true );
				} elseif ( in_array( $status, array( 'rejected', 'failed', 'cancelled' ), true ) ) {
					$booking = Bookings::get( $id );
					if ( $booking && (string) $booking->status !== 'cancelled' ) {
						Bookings::update_status( $id, 'cancelled' );
					}
					\LiteCal\Rest\Rest::notify_booking_status( $id, 'payment_failed', true );
				}
			}
			self::flash_notice( esc_html__( 'Estados de pago actualizados.', 'agenda-lite' ), 'success' );
			wp_safe_redirect( $redirect_url );
			exit;
		}
		foreach ( $ids as $id ) {
			Bookings::update_status( $id, $status );
			\LiteCal\Rest\Rest::notify_booking_status( $id, $status, true );
			\LiteCal\Modules\Calendar\GoogleCalendar\GoogleCalendarModule::sync_booking( $id, $status );
		}
		self::flash_notice( esc_html__( 'Estados actualizados.', 'agenda-lite' ), 'success' );
		wp_safe_redirect( $redirect_url );
		exit;
	}

	public static function empty_bookings_trash() {
		if ( ! self::can_manage() ) {
			wp_die( esc_html__( 'Unauthorized', 'agenda-lite' ) );
		}
		check_admin_referer( 'litecal_empty_bookings_trash' );

		$deleted = 0;
		do {
			[$rows] = Bookings::search( '', 1, 200, false, 'deleted', 'status', true, 'id', 'asc' );
			if ( ! $rows ) {
				break;
			}
			foreach ( (array) $rows as $row ) {
				if ( Bookings::delete_permanent( (int) $row->id ) !== false ) {
					++$deleted;
				}
			}
		} while ( ! empty( $rows ) );

		if ( $deleted > 0 ) {
			self::flash_notice( esc_html__( 'Papelera vaciada.', 'agenda-lite' ), 'success' );
		} else {
			self::flash_notice( esc_html__( 'No había reservas en papelera.', 'agenda-lite' ), 'success' );
		}
		wp_safe_redirect( add_query_arg( array( 'view' => 'trash' ), Helpers::admin_url( 'bookings' ) ) );
		exit;
	}

	public static function bulk_update_clients() {
		if ( ! self::can_access_staff_panel() ) {
			wp_die( esc_html__( 'Unauthorized', 'agenda-lite' ) );
		}
		check_admin_referer( 'litecal_bulk_update_clients' );
		$status        = sanitize_key( (string) ( $_POST['bulk_status'] ?? '' ) );
		$redirect_page = sanitize_key( (string) ( $_POST['redirect'] ?? 'clients' ) );
		$redirect_view = sanitize_key( (string) ( $_POST['redirect_view'] ?? '' ) );
		$redirect_url  = Helpers::admin_url( $redirect_page );
		if ( $redirect_page === 'clients' && $redirect_view === 'trash' ) {
			$redirect_url = add_query_arg( array( 'view' => 'trash' ), $redirect_url );
		}
		$emails = self::normalize_customer_email_keys( wp_unslash( $_POST['ids'] ?? array() ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Normalized below.
		if ( ! $emails || $status === '' ) {
			wp_safe_redirect( $redirect_url );
			exit;
		}
		$emails = self::filter_owned_customer_email_keys( $emails );
		if ( ! $emails ) {
			self::flash_notice( esc_html__( 'No tienes permisos para modificar esos clientes.', 'agenda-lite' ), 'error' );
			wp_safe_redirect( $redirect_url );
			exit;
		}
		$scope_employee_id = self::current_user_employee_id();
		if ( $status === 'restore' ) {
			if ( ! self::can_manage() ) {
				self::flash_notice( esc_html__( 'No tienes permisos para restaurar clientes.', 'agenda-lite' ), 'error' );
				wp_safe_redirect( $redirect_url );
				exit;
			}
			$restored_ids = self::restore_customer_email_keys( $emails, $scope_employee_id );
			self::flash_notice(
				! empty( $restored_ids ) ? esc_html__( 'Clientes restaurados.', 'agenda-lite' ) : esc_html__( 'No hubo clientes para restaurar.', 'agenda-lite' ),
				'success'
			);
			wp_safe_redirect( $redirect_url );
			exit;
		}
		if ( $status === 'delete_permanent' ) {
			if ( ! self::can_manage() ) {
				self::flash_notice( esc_html__( 'No tienes permisos para eliminar clientes.', 'agenda-lite' ), 'error' );
				wp_safe_redirect( $redirect_url );
				exit;
			}
			$deleted_ids = self::delete_customer_email_keys_permanently( $emails, $scope_employee_id );
			self::flash_notice(
				! empty( $deleted_ids ) ? esc_html__( 'Clientes eliminados permanentemente.', 'agenda-lite' ) : esc_html__( 'No hubo clientes para eliminar.', 'agenda-lite' ),
				'success'
			);
			wp_safe_redirect( $redirect_url );
			exit;
		}
		if ( $status === 'delete' ) {
			if ( ! self::can_manage() ) {
				self::flash_notice( esc_html__( 'No tienes permisos para eliminar clientes.', 'agenda-lite' ), 'error' );
				wp_safe_redirect( $redirect_url );
				exit;
			}
			$affected_ids = self::trash_customer_email_keys( $emails, $scope_employee_id );
			self::flash_notice(
				! empty( $affected_ids ) ? esc_html__( 'Clientes movidos a papelera.', 'agenda-lite' ) : esc_html__( 'No hubo clientes para mover a papelera.', 'agenda-lite' ),
				'success'
			);
			wp_safe_redirect( $redirect_url );
			exit;
		}
		wp_safe_redirect( $redirect_url );
		exit;
	}

	public static function empty_clients_trash() {
		if ( ! self::can_manage() ) {
			wp_die( esc_html__( 'Unauthorized', 'agenda-lite' ) );
		}
		check_admin_referer( 'litecal_empty_clients_trash' );
		$scope_employee_id = self::current_user_employee_id();
		$emails            = Bookings::customer_email_keys( 'trash', $scope_employee_id );
		$deleted_ids       = self::delete_customer_email_keys_permanently( $emails, $scope_employee_id );
		if ( ! empty( $deleted_ids ) ) {
			self::flash_notice( esc_html__( 'Papelera de clientes vaciada.', 'agenda-lite' ), 'success' );
		} else {
			self::flash_notice( esc_html__( 'No había clientes en papelera.', 'agenda-lite' ), 'success' );
		}
		wp_safe_redirect( add_query_arg( array( 'view' => 'trash' ), Helpers::admin_url( 'clients' ) ) );
		exit;
	}

	public static function ajax_update_booking_time() {
		if ( ! self::can_access_staff_panel() ) {
			wp_send_json_error( array( 'message' => esc_html__( 'Unauthorized', 'agenda-lite' ) ), 403 );
		}
		check_ajax_referer( 'litecal_update_booking', 'nonce' );
		$id               = absint( wp_unslash( $_POST['id'] ?? 0 ) );
		$start            = sanitize_text_field( wp_unslash( $_POST['start'] ?? '' ) );
		$end              = sanitize_text_field( wp_unslash( $_POST['end'] ?? '' ) );
		$mark_rescheduled = ! empty( $_POST['mark_rescheduled'] );
		if ( ! $id || empty( $start ) || empty( $end ) ) {
			wp_send_json_error( array( 'message' => esc_html__( 'Invalid', 'agenda-lite' ) ), 400 );
		}
		$booking = Bookings::get( $id );
		if ( ! $booking ) {
			wp_send_json_error( array( 'message' => esc_html__( 'Reserva no encontrada.', 'agenda-lite' ) ), 404 );
		}
		if ( ! self::can_access_booking( $booking ) ) {
			wp_send_json_error( array( 'message' => esc_html__( 'No autorizado.', 'agenda-lite' ) ), 403 );
		}

		$event = Events::get( (int) ( $booking->event_id ?? 0 ) );
		if ( ! $event ) {
			wp_send_json_error( array( 'message' => esc_html__( 'Servicio no encontrado.', 'agenda-lite' ) ), 404 );
		}

		$tz       = Availability::resolve_timezone( $event );
		$start_dt = \DateTimeImmutable::createFromFormat( 'Y-m-d H:i:s', $start, $tz );
		$end_dt   = \DateTimeImmutable::createFromFormat( 'Y-m-d H:i:s', $end, $tz );
		if ( ! $start_dt || ! $end_dt ) {
			wp_send_json_error( array( 'message' => esc_html__( 'Fecha u hora inválida.', 'agenda-lite' ) ), 400 );
		}
		if ( $end_dt <= $start_dt ) {
			wp_send_json_error( array( 'message' => esc_html__( 'El rango horario no es válido.', 'agenda-lite' ) ), 400 );
		}

		$start_date = wp_date( 'Y-m-d', $start_dt->getTimestamp(), $tz );
		$start_hm   = wp_date( 'H:i', $start_dt->getTimestamp(), $tz );
		$end_hm     = wp_date( 'H:i', $end_dt->getTimestamp(), $tz );
		$slots      = Availability::get_slots( $event, (int) ( $booking->employee_id ?? 0 ), $start_date, $id );
		$slot_ok    = false;
		foreach ( (array) $slots as $slot ) {
			if ( ( $slot['start'] ?? '' ) === $start_hm && ( $slot['end'] ?? '' ) === $end_hm && ( $slot['status'] ?? '' ) === 'available' ) {
				$slot_ok = true;
				break;
			}
		}
		if ( ! $slot_ok ) {
			wp_send_json_error( array( 'message' => esc_html__( 'Ese horario ya está ocupado.', 'agenda-lite' ) ), 409 );
		}

		global $wpdb;
		$table       = $wpdb->prefix . 'litecal_bookings';
		$update_data = array(
			'start_datetime' => $start,
			'end_datetime'   => $end,
			'updated_at'     => current_time( 'mysql' ),
		);
		if ( $mark_rescheduled ) {
			$update_data['status'] = 'rescheduled';
		}
		$wpdb->update( $table, $update_data, array( 'id' => $id ) );

		$snapshot = Bookings::decode_snapshot( $booking );
		$changes  = array();
		if ( $booking->start_datetime !== $start ) {
			$changes['start_datetime']    = array(
				'from' => $booking->start_datetime,
				'to'   => $start,
			);
			$snapshot['booking']['start'] = $start;
		}
		if ( $booking->end_datetime !== $end ) {
			$changes['end_datetime']    = array(
				'from' => $booking->end_datetime,
				'to'   => $end,
			);
			$snapshot['booking']['end'] = $end;
		}
		if ( $mark_rescheduled && $booking->status !== 'rescheduled' ) {
			$changes['status'] = array(
				'from' => $booking->status,
				'to'   => 'rescheduled',
			);
		}
		if ( $changes ) {
			Bookings::update_snapshot(
				$id,
				$snapshot,
				array(
					'at'      => current_time( 'mysql' ),
					'by'      => get_current_user_id(),
					'changes' => $changes,
				)
			);
		}
		if ( $mark_rescheduled ) {
			\LiteCal\Rest\Rest::notify_booking_status( $id, 'rescheduled', true );
			\LiteCal\Modules\Calendar\GoogleCalendar\GoogleCalendarModule::sync_booking( $id, 'rescheduled' );
		}
		wp_send_json_success( array( 'status' => 'ok' ) );
	}

	public static function export_bookings() {
		if ( ! self::can_access_staff_panel() ) {
			wp_die( esc_html__( 'Unauthorized', 'agenda-lite' ) );
		}
		check_admin_referer( 'litecal_export_bookings' );
		$scope_employee_id = self::current_user_employee_id();
		$include_deleted   = ! empty( $_POST['include_deleted'] );
		$status_filter     = $include_deleted ? 'deleted' : '';
		[$bookings]        = Bookings::search( '', 1, 50000, false, $status_filter, 'status', $include_deleted, 'created_at', 'desc', $scope_employee_id );
		header( 'Content-Type: text/csv' );
		header( 'Content-Disposition: attachment; filename="agenda-lite-reservas.csv"' );
		$out = fopen( 'php://output', 'w' );
		fputcsv(
			$out,
			array(
				__( 'ID', 'agenda-lite' ),
				__( 'Servicio', 'agenda-lite' ),
				__( 'Cliente', 'agenda-lite' ),
				__( 'Email', 'agenda-lite' ),
				__( 'Fecha', 'agenda-lite' ),
				__( 'Estado', 'agenda-lite' ),
				__( 'Pago', 'agenda-lite' ),
				__( 'Proveedor', 'agenda-lite' ),
				__( 'Monto', 'agenda-lite' ),
				__( 'Moneda', 'agenda-lite' ),
				__( 'Referencia', 'agenda-lite' ),
				__( 'Error', 'agenda-lite' ),
			)
		);
		foreach ( $bookings as $booking ) {
			$event = Events::get( $booking->event_id );
			$provider_meta = self::payment_provider_meta( $booking->payment_provider ?: '' );
			$provider_label = ! empty( $booking->payment_provider ) ? (string) ( $provider_meta['label'] ?? $booking->payment_provider ) : '';
			fputcsv(
				$out,
				array(
					self::csv_safe( (string) $booking->id ),
					self::csv_safe( (string) ( $event ? $event->title : '-' ) ),
					self::csv_safe( (string) $booking->name ),
					self::csv_safe( (string) $booking->email ),
					self::csv_safe( (string) $booking->start_datetime ),
					self::csv_safe( (string) self::status_label( $booking->status, 'booking' ) ),
					self::csv_safe( (string) self::status_label( $booking->payment_status ?: 'unpaid', 'payment' ) ),
					self::csv_safe( (string) $provider_label ),
					self::csv_safe( (string) ( $booking->payment_amount ?: '' ) ),
					self::csv_safe( (string) ( $booking->payment_currency ?: '' ) ),
					self::csv_safe( (string) ( $booking->payment_reference ?: '' ) ),
					self::csv_safe( (string) ( $booking->payment_error ?: '' ) ),
				)
			);
		}
		fclose( $out );
		exit;
	}

	public static function export_clients() {
		if ( ! self::can_access_staff_panel() ) {
			wp_die( esc_html__( 'Unauthorized', 'agenda-lite' ) );
		}
		check_admin_referer( 'litecal_export_clients' );
		$scope_employee_id = self::current_user_employee_id();
		$view             = sanitize_key( (string) ( $_POST['view'] ?? 'active' ) );
		$view             = $view === 'trash' ? 'trash' : 'active';
		$status_filter    = sanitize_key( (string) ( $_POST['status'] ?? '' ) );
		if ( ! in_array( $status_filter, array( '', 'blocked_any', 'blocked_preventive', 'blocked_manual' ), true ) ) {
			$status_filter = '';
		}
		$whitelist = array();
		if ( $view !== 'trash' && $status_filter !== '' ) {
			$whitelist = self::customer_email_keys_by_abuse_filter( $status_filter, $scope_employee_id );
		}
		if ( $view !== 'trash' && $status_filter !== '' && empty( $whitelist ) ) {
			$customers = array();
		} else {
			[$customers] = Bookings::customer_search( '', 1, 50000, $view, 'last_booking_at', 'desc', $scope_employee_id, $whitelist );
		}
		header( 'Content-Type: text/csv' );
		header( 'Content-Disposition: attachment; filename="agenda-lite-clientes.csv"' );
		$out = fopen( 'php://output', 'w' );
		fputcsv(
			$out,
			array(
				__( 'Nombre', 'agenda-lite' ),
				__( 'Apellido', 'agenda-lite' ),
				__( 'Email', 'agenda-lite' ),
				__( 'Teléfono', 'agenda-lite' ),
				__( 'Estado', 'agenda-lite' ),
				__( 'Cantidad de reservas', 'agenda-lite' ),
				__( 'Última reserva', 'agenda-lite' ),
			)
		);
		foreach ( (array) $customers as $customer ) {
			$parts = self::customer_name_parts_from_booking( $customer );
			$meta  = self::customer_abuse_status_meta( (string) ( $customer->email_key ?? '' ), true );
			fputcsv(
				$out,
				array(
					self::csv_safe( (string) ( $parts['first_name'] ?? '' ) ),
					self::csv_safe( (string) ( $parts['last_name'] ?? '' ) ),
					self::csv_safe( (string) ( $customer->latest_email ?? $customer->email_key ?? '' ) ),
					self::csv_safe( (string) ( $customer->latest_phone ?? '' ) ),
					self::csv_safe( (string) ( $meta['label'] ?? __( 'Normal', 'agenda-lite' ) ) ),
					self::csv_safe( (string) ( (int) ( $customer->total_bookings ?? 0 ) ) ),
					self::csv_safe( (string) ( $customer->last_booking_at ?? '' ) ),
				)
			);
		}
		fclose( $out );
		exit;
	}

	public static function export_payments() {
		if ( ! self::can_manage() ) {
			wp_die( esc_html__( 'Unauthorized', 'agenda-lite' ) );
		}
		check_admin_referer( 'litecal_export_payments' );
		[$bookings] = Bookings::search( '', 1, 50000, true, '', 'payment_status', false );
		header( 'Content-Type: text/csv' );
		header( 'Content-Disposition: attachment; filename="agenda-lite-pagos.csv"' );
		$out = fopen( 'php://output', 'w' );
		fputcsv( $out, array( 'ID', 'Servicio', 'Cliente', 'Email', 'Monto', 'Moneda', 'Estado', 'Proveedor', 'Referencia', 'Error', 'Fecha' ) );
		foreach ( $bookings as $booking ) {
			if ( ! empty( $booking->payment_voided ) ) {
				continue;
			}
			if ( empty( $booking->payment_provider ) && empty( $booking->payment_amount ) ) {
				continue;
			}
			$event = Events::get( $booking->event_id );
			$provider_meta = self::payment_provider_meta( $booking->payment_provider ?: '' );
			$provider_label = ! empty( $booking->payment_provider ) ? (string) ( $provider_meta['label'] ?? $booking->payment_provider ) : '';
			fputcsv(
				$out,
				array(
					self::csv_safe( (string) $booking->id ),
					self::csv_safe( (string) ( $event ? $event->title : '-' ) ),
					self::csv_safe( (string) $booking->name ),
					self::csv_safe( (string) $booking->email ),
					self::csv_safe( (string) ( $booking->payment_amount ?: '' ) ),
					self::csv_safe( (string) ( $booking->payment_currency ?: '' ) ),
					self::csv_safe( (string) self::status_label( $booking->payment_status ?: 'unpaid', 'payment' ) ),
					self::csv_safe( (string) $provider_label ),
					self::csv_safe( (string) ( $booking->payment_reference ?: '' ) ),
					self::csv_safe( (string) ( $booking->payment_error ?: '' ) ),
					self::csv_safe( (string) $booking->created_at ),
				)
			);
		}
		fclose( $out );
		exit;
	}

	public static function export_analytics() {
		if ( ! self::can_manage() ) {
			wp_die( esc_html__( 'Unauthorized', 'agenda-lite' ) );
		}
		check_admin_referer( 'litecal_export_analytics' );
		$filters        = Rest::analytics_filters_from_request( $_POST );
		$search         = sanitize_text_field( (string) wp_unslash( $_POST['search'] ?? '' ) );
		$sort_by        = sanitize_key( (string) ( $_POST['sortBy'] ?? 'start_datetime' ) );
		$sort_dir       = strtolower( sanitize_text_field( (string) wp_unslash( $_POST['sortDir'] ?? 'desc' ) ) );
		$can_view_email = current_user_can( 'manage_options' );
		[$rows]         = Rest::analytics_fetch_rows( $filters, 1, 100, $sort_by, $sort_dir, $search, true );
		$filename       = 'agenda-lite-analíticas.csv';
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
		$out = fopen( 'php://output', 'w' );
		fputcsv(
			$out,
			array(
				__( 'ID', 'agenda-lite' ),
				__( 'Fecha', 'agenda-lite' ),
				__( 'Hora', 'agenda-lite' ),
				__( 'Servicio', 'agenda-lite' ),
				__( 'Profesional', 'agenda-lite' ),
				__( 'Estado reserva', 'agenda-lite' ),
				__( 'Estado pago', 'agenda-lite' ),
				__( 'Proveedor', 'agenda-lite' ),
				__( 'Monto', 'agenda-lite' ),
				__( 'Cliente', 'agenda-lite' ),
				__( 'Email', 'agenda-lite' ),
				__( 'Referencia', 'agenda-lite' ),
			)
		);
		foreach ( (array) $rows as $row ) {
			$dt   = strtotime( (string) ( $row['start_datetime'] ?? '' ) );
			$date = $dt ? wp_date( 'd M Y', $dt, wp_timezone() ) : '-';
			$time = $dt ? self::format_time_short( (string) ( $row['start_datetime'] ?? '' ) ) : '-';
			$provider_meta  = self::payment_provider_meta( (string) ( $row['payment_provider'] ?? '' ) );
			$provider_label = ! empty( $row['payment_provider'] ) ? (string) ( $provider_meta['label'] ?? $row['payment_provider'] ) : '';
			fputcsv(
				$out,
				array(
					self::csv_safe( (string) ( (int) ( $row['id'] ?? 0 ) ) ),
					self::csv_safe( (string) $date ),
					self::csv_safe( (string) $time ),
					self::csv_safe( (string) ( $row['event_title'] ?? '' ) ),
					self::csv_safe( (string) ( $row['employee_name'] ?? '' ) ),
					self::csv_safe( (string) ( $row['status'] ?? '' ) ),
					self::csv_safe( (string) ( $row['payment_status'] ?? '' ) ),
					self::csv_safe( (string) $provider_label ),
					self::csv_safe( (string) ( (float) ( $row['payment_amount'] ?? 0 ) ) ),
					self::csv_safe( (string) ( $row['client_name'] ?? '' ) ),
					self::csv_safe( $can_view_email ? (string) ( $row['client_email'] ?? '' ) : '' ),
					self::csv_safe( (string) ( $row['payment_reference'] ?? '' ) ),
				)
			);
		}
		fclose( $out );
		exit;
	}

	public static function delete_bookings() {
		if ( ! self::can_access_staff_panel() ) {
			wp_die( esc_html__( 'Unauthorized', 'agenda-lite' ) );
		}
		if ( ! self::can_manage() ) {
			wp_die( esc_html__( 'No tienes permisos para eliminar reservas.', 'agenda-lite' ) );
		}
		check_admin_referer( 'litecal_delete_bookings' );
		$ids = wp_unslash( $_POST['ids'] ?? array() ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Normalized below with intval.
		if ( is_string( $ids ) ) {
			$ids = array_filter( explode( ',', $ids ) );
		} elseif ( ! is_array( $ids ) ) {
			$ids = array( $ids );
		}
		$ids = self::filter_owned_booking_ids( (array) $ids );
		if ( ! $ids ) {
			self::flash_notice( esc_html__( 'No tienes permisos para eliminar esas reservas.', 'agenda-lite' ), 'error' );
			$redirect      = sanitize_text_field( wp_unslash( $_POST['redirect'] ?? 'bookings' ) );
			$redirect_view = sanitize_key( (string) ( $_POST['redirect_view'] ?? '' ) );
			$url           = Helpers::admin_url( $redirect );
			if ( $redirect === 'bookings' && $redirect_view === 'trash' ) {
				$url = add_query_arg( array( 'view' => 'trash' ), $url );
			}
			wp_safe_redirect( $url );
			exit;
		}
		foreach ( $ids as $id ) {
			$booking = Bookings::get( (int) $id );
			if ( ! $booking || (string) ( $booking->status ?? '' ) === 'deleted' ) {
				continue;
			}
			$snapshot                      = Bookings::decode_snapshot( $booking );
			$snapshot['booking']['status'] = 'deleted';
			Bookings::update_snapshot(
				(int) $id,
				$snapshot,
				array(
					'at'      => current_time( 'mysql' ),
					'by'      => get_current_user_id(),
					'changes' => array(
						'status' => array(
							'from' => (string) ( $booking->status ?? '' ),
							'to'   => 'deleted',
						),
					),
				)
			);
		}
		Bookings::delete_ids( $ids );
		$ids = array_filter( array_map( 'intval', (array) $ids ) );
		foreach ( $ids as $id ) {
			\LiteCal\Modules\Calendar\GoogleCalendar\GoogleCalendarModule::sync_booking( $id, 'deleted' );
		}
		$redirect      = sanitize_text_field( wp_unslash( $_POST['redirect'] ?? 'bookings' ) );
		$redirect_view = sanitize_key( (string) ( $_POST['redirect_view'] ?? '' ) );
		$url           = Helpers::admin_url( $redirect );
		if ( $redirect === 'bookings' && $redirect_view === 'trash' ) {
			$url = add_query_arg( array( 'view' => 'trash' ), $url );
		}
		self::flash_notice( esc_html__( 'Reserva movida a papelera.', 'agenda-lite' ), 'success' );
		wp_safe_redirect( $url );
		exit;
	}

	public static function delete_payments() {
		if ( ! self::can_manage() ) {
			wp_die( esc_html__( 'Unauthorized', 'agenda-lite' ) );
		}
		check_admin_referer( 'litecal_delete_payments' );
		$ids = wp_unslash( $_POST['ids'] ?? array() ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Normalized below with intval.
		if ( is_string( $ids ) ) {
			$ids = array_filter( explode( ',', $ids ) );
		} elseif ( ! is_array( $ids ) ) {
			$ids = array( $ids );
		}
		$ids = array_filter( array_map( 'intval', $ids ) );
		if ( $ids ) {
			self::void_payments( $ids );
		}
		$redirect = sanitize_text_field( wp_unslash( $_POST['redirect'] ?? 'payments' ) );
		wp_safe_redirect( Helpers::admin_url( $redirect ) );
		exit;
	}

	private static function void_payments( array $ids ) {
		$ids = array_filter( array_map( 'intval', $ids ) );
		if ( ! $ids ) {
			return;
		}
		foreach ( $ids as $id ) {
			Bookings::update_payment(
				$id,
				array(
					'payment_status'    => 'unpaid',
					'payment_provider'  => null,
					'payment_reference' => null,
					'payment_amount'    => 0,
					'payment_currency'  => 'CLP',
					'payment_error'     => null,
					'payment_voided'    => 1,
				)
			);
		}
	}

	private static function restore_status_for_booking( $booking ) {
		$history = array();
		if ( is_object( $booking ) && ! empty( $booking->snapshot_history ) ) {
			$decoded = json_decode( (string) $booking->snapshot_history, true );
			if ( is_array( $decoded ) ) {
				$history = array_reverse( $decoded );
			}
		}
		foreach ( $history as $entry ) {
			$changes       = is_array( $entry['changes'] ?? null ) ? $entry['changes'] : array();
			$status_change = is_array( $changes['status'] ?? null ) ? $changes['status'] : array();
			$from          = sanitize_key( (string) ( $status_change['from'] ?? '' ) );
			$to            = sanitize_key( (string) ( $status_change['to'] ?? '' ) );
			if ( $to === 'deleted' && $from !== '' && $from !== 'deleted' ) {
				return $from;
			}
		}

		$payment_status = sanitize_key( (string) ( $booking->payment_status ?? '' ) );
		if ( in_array( $payment_status, array( 'paid', 'approved', 'completed', 'confirmed' ), true ) ) {
			return 'confirmed';
		}
		return 'pending';
	}

	public static function save_settings() {
		if ( ! self::can_manage() ) {
			wp_die( esc_html__( 'Unauthorized', 'agenda-lite' ) );
		}
		check_admin_referer( 'litecal_save_settings' );

		$tab = sanitize_text_field( wp_unslash( $_POST['tab'] ?? 'settings' ) );
		if ( $tab === 'public_page' ) {
			$tab = 'seo';
		}
		if ( $tab === 'integrations' ) {
			$current_options           = get_option( 'litecal_integrations', array() );
			$scope                     = sanitize_key( wp_unslash( $_POST['integration_scope'] ?? '' ) );
			$is_scoped                 = $scope !== '';
			$redirect_integrations_url = Helpers::admin_url( 'integrations' ) . ( $is_scoped ? '&integration=' . rawurlencode( $scope ) : '' );
			$scope_checkbox_map        = array(
				'recaptcha'          => 'recaptcha_enabled',
				'google_tag_manager' => 'gtm_enabled',
				'google_analytics'   => 'ga_enabled',
				'google_ads'         => 'gads_enabled',
				'meta_pixel'         => 'meta_pixel_enabled',
				'payments_flow'      => 'payments_flow',
				'payments_mp'        => 'payments_mp',
				'payments_webpay'    => 'payments_webpay',
				'payments_paypal'    => 'payments_paypal',
				'payments_stripe'    => 'payments_stripe',
				'payments_transfer'  => 'payments_transfer',
				'google_calendar'    => 'google_calendar',
				'zoom'               => 'zoom',
			);
			// phpcs:disable WordPress.Security.NonceVerification.Missing -- Nonce already validated in this request flow.
				$posted_value    = function ( $key, $default = '' ) use ( $current_options, $is_scoped ) {
					if ( array_key_exists( $key, $_POST ) ) {
						return wp_unslash( $_POST[ $key ] ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Caller sanitizes per-field.
					}
					if ( $is_scoped ) {
						return $current_options[ $key ] ?? $default;
					}
					return $default;
				};
				$posted_bool     = function ( $key, $default = 0 ) use ( $current_options, $is_scoped, $scope, $scope_checkbox_map ) {
					if ( array_key_exists( $key, $_POST ) ) {
						return ! empty( wp_unslash( $_POST[ $key ] ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Boolean check only.
					}
					if ( $is_scoped && ( ( $scope_checkbox_map[ $scope ] ?? '' ) === $key ) ) {
						return false;
					}
					if ( $is_scoped ) {
						return ! empty( $current_options[ $key ] ?? $default );
					}
					return false;
				};
				$posted_int_flag = function ( $key, $default = 1 ) use ( $current_options, $is_scoped ) {
					if ( array_key_exists( $key, $_POST ) ) {
						return ( (string) wp_unslash( $_POST[ $key ] ) === '1' ) ? 1 : 0; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Casted to strict 0/1.
					}
					if ( $is_scoped ) {
						return ( (string) ( $current_options[ $key ] ?? (string) $default ) === '1' ) ? 1 : 0;
					}
					return ( (string) $default === '1' ) ? 1 : 0;
				};
			// phpcs:enable WordPress.Security.NonceVerification.Missing
			$scope_matches = function ( ...$keys ) use ( $is_scoped, $scope ) {
				if ( ! $is_scoped ) {
					return true;
				}
				return in_array( $scope, $keys, true );
			};

			$flow_api_key     = trim( sanitize_text_field( (string) $posted_value( 'flow_api_key', '' ) ) );
			$flow_secret_key  = trim( sanitize_text_field( (string) $posted_value( 'flow_secret_key', '' ) ) );
			$flow_enabled     = $posted_bool( 'payments_flow', (int) ( $current_options['payments_flow'] ?? 0 ) );
			$paypal_client_id = trim( sanitize_text_field( (string) $posted_value( 'paypal_client_id', '' ) ) );
			$paypal_secret    = trim( sanitize_text_field( (string) $posted_value( 'paypal_secret', '' ) ) );
			$paypal_enabled   = $posted_bool( 'payments_paypal', (int) ( $current_options['payments_paypal'] ?? 0 ) );
			$paypal_sandbox   = $posted_int_flag( 'paypal_sandbox', (int) ( $current_options['paypal_sandbox'] ?? 1 ) );
			$stripe_enabled   = $posted_bool( 'payments_stripe', (int) ( $current_options['payments_stripe'] ?? 0 ) );
			$stripe_mode      = strtolower( trim( sanitize_text_field( (string) $posted_value( 'stripe_mode', 'test' ) ) ) );
			if ( $stripe_mode !== 'live' ) {
				$stripe_mode = 'test';
			}
			$stripe_public_key_test      = trim( sanitize_text_field( (string) $posted_value( 'stripe_public_key_test', '' ) ) );
			$stripe_secret_key_test      = trim( sanitize_text_field( (string) $posted_value( 'stripe_secret_key_test', '' ) ) );
			$stripe_webhook_secret_test  = trim( sanitize_text_field( (string) $posted_value( 'stripe_webhook_secret_test', '' ) ) );
			$stripe_public_key_live      = trim( sanitize_text_field( (string) $posted_value( 'stripe_public_key_live', '' ) ) );
			$stripe_secret_key_live      = trim( sanitize_text_field( (string) $posted_value( 'stripe_secret_key_live', '' ) ) );
			$stripe_webhook_secret_live  = trim( sanitize_text_field( (string) $posted_value( 'stripe_webhook_secret_live', '' ) ) );
			$mp_access_token             = trim( sanitize_text_field( (string) $posted_value( 'mp_access_token', '' ) ) );
			$mp_webhook_secret           = trim( sanitize_text_field( (string) $posted_value( 'mp_webhook_secret', '' ) ) );
			$mp_enabled                  = $posted_bool( 'payments_mp', (int) ( $current_options['payments_mp'] ?? 0 ) );
			$webpay_commerce_code        = trim( sanitize_text_field( (string) $posted_value( 'webpay_commerce_code', '' ) ) );
			$webpay_api_key              = trim( sanitize_text_field( (string) $posted_value( 'webpay_api_key', '' ) ) );
			$webpay_enabled              = $posted_bool( 'payments_webpay', (int) ( $current_options['payments_webpay'] ?? 0 ) );
			$transfer_enabled            = $posted_bool( 'payments_transfer', (int) ( $current_options['payments_transfer'] ?? 0 ) );
			$transfer_holder             = trim( sanitize_text_field( (string) $posted_value( 'transfer_holder', '' ) ) );
			$transfer_bank               = trim( sanitize_text_field( (string) $posted_value( 'transfer_bank', '' ) ) );
			$transfer_country            = trim( sanitize_text_field( (string) $posted_value( 'transfer_country', '' ) ) );
			$transfer_currency           = strtoupper( trim( sanitize_text_field( (string) $posted_value( 'transfer_currency', '' ) ) ) );
			$transfer_account_number     = trim( sanitize_text_field( (string) $posted_value( 'transfer_account_number', '' ) ) );
			$transfer_account_type       = trim( sanitize_text_field( (string) $posted_value( 'transfer_account_type', '' ) ) );
			$transfer_confirmation_email = trim( sanitize_email( (string) $posted_value( 'transfer_confirmation_email', '' ) ) );
			$transfer_extra_fields       = trim( sanitize_textarea_field( (string) $posted_value( 'transfer_extra_fields', '' ) ) );
			$transfer_instructions       = trim( sanitize_textarea_field( (string) $posted_value( 'transfer_instructions', '' ) ) );
			$google_calendar_enabled     = $posted_bool( 'google_calendar', (int) ( $current_options['google_calendar'] ?? 0 ) );
			$google_client_id            = trim( sanitize_text_field( (string) $posted_value( 'google_client_id', '' ) ) );
			$google_client_secret        = trim( sanitize_text_field( (string) $posted_value( 'google_client_secret', '' ) ) );
			$google_calendar_mode        = sanitize_key( (string) $posted_value( 'google_calendar_mode', (string) ( $current_options['google_calendar_mode'] ?? 'centralized' ) ) );
			if ( ! in_array( $google_calendar_mode, array( 'centralized', 'per_employee' ), true ) ) {
				$google_calendar_mode = 'centralized';
			}
			$google_calendar_id                   = trim( sanitize_text_field( (string) $posted_value( 'google_calendar_id', (string) ( $current_options['google_calendar_id'] ?? 'primary' ) ) ) );
				$google_calendar_employee_ids_raw = wp_unslash( $_POST['google_calendar_employee_ids'] ?? ( $current_options['google_calendar_employee_ids'] ?? array() ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Sanitized per item in foreach.
			if ( ! is_array( $google_calendar_employee_ids_raw ) ) {
				$google_calendar_employee_ids_raw = array();
			}
			$existing_employee_ids        = array_map(
				static function ( $employee ) {
					return (int) ( $employee->id ?? 0 );
				},
				\LiteCal\Core\Employees::all_booking_managers( true )
			);
			$existing_employee_ids        = array_values( array_filter( $existing_employee_ids ) );
			$google_calendar_employee_ids = array();
			foreach ( $google_calendar_employee_ids_raw as $employee_id => $calendar_id_value ) {
				$employee_id = (int) $employee_id;
				if ( $employee_id <= 0 || ! in_array( $employee_id, $existing_employee_ids, true ) ) {
					continue;
				}
				$calendar_id_value = trim( sanitize_text_field( (string) $calendar_id_value ) );
				if ( $calendar_id_value === '' ) {
					continue;
				}
				$google_calendar_employee_ids[ $employee_id ] = $calendar_id_value;
			}
			$zoom_enabled          = ( (string) $posted_value( 'zoom', (string) ( $current_options['zoom'] ?? '0' ) ) === '1' );
			$zoom_account_id       = trim( sanitize_text_field( (string) $posted_value( 'zoom_account_id', '' ) ) );
			$zoom_client_id        = trim( sanitize_text_field( (string) $posted_value( 'zoom_client_id', '' ) ) );
			$zoom_client_secret    = trim( sanitize_text_field( (string) $posted_value( 'zoom_client_secret', '' ) ) );
			$zoom_user_id          = trim( sanitize_text_field( (string) $posted_value( 'zoom_user_id', '' ) ) );
			$teams_enabled         = false;
			$teams_tenant_id       = '';
			$teams_client_id       = '';
			$teams_client_secret   = '';
			$teams_organizer_email = '';
			$gtm_enabled           = $posted_bool( 'gtm_enabled', (int) ( $current_options['gtm_enabled'] ?? 0 ) );
			$gtm_container_id      = strtoupper( trim( sanitize_text_field( (string) $posted_value( 'gtm_container_id', '' ) ) ) );
			$ga_enabled            = $posted_bool( 'ga_enabled', (int) ( $current_options['ga_enabled'] ?? 0 ) );
			$ga_measurement_id     = strtoupper( trim( sanitize_text_field( (string) $posted_value( 'ga_measurement_id', '' ) ) ) );
			$gads_enabled          = $posted_bool( 'gads_enabled', (int) ( $current_options['gads_enabled'] ?? 0 ) );
			$gads_tag_id           = strtoupper( trim( sanitize_text_field( (string) $posted_value( 'gads_tag_id', '' ) ) ) );
			$meta_pixel_enabled    = $posted_bool( 'meta_pixel_enabled', (int) ( $current_options['meta_pixel_enabled'] ?? 0 ) );
			$meta_pixel_input      = trim( (string) $posted_value( 'meta_pixel_input', (string) ( $current_options['meta_pixel_input'] ?? ( $current_options['meta_pixel_id'] ?? '' ) ) ) );
			$meta_pixel_id         = self::extract_meta_pixel_id( $meta_pixel_input );
			$recaptcha_enabled     = $posted_bool( 'recaptcha_enabled', (int) ( $current_options['recaptcha_enabled'] ?? 0 ) );
			$recaptcha_site_key    = trim( sanitize_text_field( (string) $posted_value( 'recaptcha_site_key', '' ) ) );
			$recaptcha_secret_key  = trim( sanitize_text_field( (string) $posted_value( 'recaptcha_secret_key', '' ) ) );
			if ( $google_calendar_id === '' ) {
				$google_calendar_id = 'primary';
			}
			$paypal_webhook_id = trim( (string) ( $current_options['paypal_webhook_id'] ?? '' ) );
			if ( $scope_matches( 'payments_paypal' ) && $paypal_client_id !== '' && $paypal_secret !== '' ) {
				$detected_webhook_id = self::paypal_detect_webhook_id( $paypal_client_id, $paypal_secret, (bool) $paypal_sandbox );
				if ( $detected_webhook_id !== '' ) {
					$paypal_webhook_id = $detected_webhook_id;
				}
			}
			if ( ! self::pro_feature_enabled( 'payments_flow' ) ) {
				$flow_enabled = false;
			}
			if ( ! self::pro_feature_enabled( 'payments_mp' ) ) {
				$mp_enabled = false;
			}
			if ( ! self::pro_feature_enabled( 'payments_webpay' ) ) {
				$webpay_enabled = false;
			}
			if ( ! self::pro_feature_enabled( 'payments_paypal' ) ) {
				$paypal_enabled = false;
			}
			if ( ! self::pro_feature_enabled( 'payments_stripe' ) ) {
				$stripe_enabled = false;
			}
			if ( ! self::pro_feature_enabled( 'calendar_google' ) ) {
				$google_calendar_enabled = false;
			}
			if ( ! self::pro_feature_enabled( 'video_zoom' ) ) {
				$zoom_enabled = false;
			}
			if ( $scope_matches( 'payments_flow' ) && $flow_enabled ) {
				$key_ok    = $flow_api_key !== '' && preg_match( '/^[A-Za-z0-9_-]{10,120}$/', $flow_api_key );
				$secret_ok = $flow_secret_key !== '' && preg_match( '/^[A-Za-z0-9_-]{10,120}$/', $flow_secret_key );
				if ( ! $key_ok || ! $secret_ok ) {
					self::flash_notice( esc_html__( 'Flow: API Key o Secret Key inválida. Usa solo letras, números, guiones o guión bajo (10-120 caracteres).', 'agenda-lite' ), 'error' );
					wp_safe_redirect( $redirect_integrations_url );
					exit;
				}
			}
			if ( $scope_matches( 'payments_paypal' ) && $paypal_enabled && ( $paypal_client_id === '' || $paypal_secret === '' ) ) {
				self::flash_notice( esc_html__( 'PayPal: debes ingresar Client ID y Secret para activar la integración.', 'agenda-lite' ), 'error' );
				wp_safe_redirect( $redirect_integrations_url );
				exit;
			}
			if ( $scope_matches( 'payments_stripe' ) && $stripe_enabled ) {
				$active_public = $stripe_mode === 'live' ? $stripe_public_key_live : $stripe_public_key_test;
				$active_secret = $stripe_mode === 'live' ? $stripe_secret_key_live : $stripe_secret_key_test;
				$active_whsec  = $stripe_mode === 'live' ? $stripe_webhook_secret_live : $stripe_webhook_secret_test;
				if ( $active_public === '' || $active_secret === '' || $active_whsec === '' ) {
					self::flash_notice(
						esc_html__( 'Stripe: debes ingresar Public key, Secret key y Webhook secret del modo activo para activar la integración.', 'agenda-lite' ),
						'error'
					);
					wp_safe_redirect( $redirect_integrations_url );
					exit;
				}
			}
			if ( $scope_matches( 'payments_mp' ) && $mp_enabled && $mp_access_token === '' ) {
				self::flash_notice( esc_html__( 'MercadoPago: debes ingresar el Access Token para activar la integración.', 'agenda-lite' ), 'error' );
				wp_safe_redirect( $redirect_integrations_url );
				exit;
			}
			if ( $scope_matches( 'payments_mp' ) && $mp_enabled && $mp_webhook_secret === '' ) {
				self::flash_notice( esc_html__( 'MercadoPago: debes ingresar el Webhook Secret para activar validación firmada.', 'agenda-lite' ), 'error' );
				wp_safe_redirect( $redirect_integrations_url );
				exit;
			}
			if ( $scope_matches( 'payments_webpay' ) && $webpay_enabled && ( $webpay_commerce_code === '' || $webpay_api_key === '' ) ) {
				self::flash_notice( esc_html__( 'Webpay Plus: debes ingresar Código de comercio y API Key para activar la integración.', 'agenda-lite' ), 'error' );
				wp_safe_redirect( $redirect_integrations_url );
				exit;
			}
			if ( $scope_matches( 'payments_transfer' ) && $transfer_enabled && ( $transfer_holder === '' || $transfer_bank === '' || $transfer_account_number === '' ) ) {
				self::flash_notice( esc_html__( 'Transferencia: debes ingresar titular, banco y número de cuenta para activar la integración.', 'agenda-lite' ), 'error' );
				wp_safe_redirect( $redirect_integrations_url );
				exit;
			}
			if ( $scope_matches( 'google_calendar' ) && $google_calendar_enabled && ( $google_client_id === '' || $google_client_secret === '' ) ) {
				self::flash_notice( esc_html__( 'Google Calendar: debes ingresar Client ID y Client Secret.', 'agenda-lite' ), 'error' );
				wp_safe_redirect( $redirect_integrations_url );
				exit;
			}
			$integration_warnings = array();
			if ( ! self::pro_feature_enabled( 'payments_flow' ) ) {
				$flow_enabled = false;
			}
			if ( ! self::pro_feature_enabled( 'payments_mp' ) ) {
				$mp_enabled = false;
			}
			if ( ! self::pro_feature_enabled( 'payments_webpay' ) ) {
				$webpay_enabled = false;
			}
			if ( ! self::pro_feature_enabled( 'payments_paypal' ) ) {
				$paypal_enabled = false;
			}
			if ( ! self::pro_feature_enabled( 'payments_stripe' ) ) {
				$stripe_enabled = false;
			}
			if ( ! self::pro_feature_enabled( 'calendar_google' ) ) {
				$google_calendar_enabled = false;
			}
			if ( ! self::pro_feature_enabled( 'video_zoom' ) ) {
				$zoom_enabled = false;
			}
			if ( ! self::pro_feature_enabled( 'video_teams' ) ) {
				$teams_enabled = false;
			}
			if ( $scope_matches( 'payments_flow' ) && ! self::pro_feature_enabled( 'payments_flow' ) ) {
				$integration_warnings[] = esc_html__( 'Flow es una integración Pro.', 'agenda-lite' );
			}
			if ( $scope_matches( 'payments_mp' ) && ! self::pro_feature_enabled( 'payments_mp' ) ) {
				$integration_warnings[] = esc_html__( 'MercadoPago es una integración Pro.', 'agenda-lite' );
			}
			if ( $scope_matches( 'payments_webpay' ) && ! self::pro_feature_enabled( 'payments_webpay' ) ) {
				$integration_warnings[] = esc_html__( 'Webpay Plus es una integración Pro.', 'agenda-lite' );
			}
			if ( $scope_matches( 'payments_paypal' ) && ! self::pro_feature_enabled( 'payments_paypal' ) ) {
				$integration_warnings[] = esc_html__( 'PayPal es una integración Pro.', 'agenda-lite' );
			}
			if ( $scope_matches( 'payments_stripe' ) && ! self::pro_feature_enabled( 'payments_stripe' ) ) {
				$integration_warnings[] = esc_html__( 'Stripe Checkout es una integración Pro.', 'agenda-lite' );
			}
			if ( $scope_matches( 'google_calendar' ) && ! self::pro_feature_enabled( 'calendar_google' ) ) {
				$integration_warnings[] = esc_html__( 'Google Calendar es una integración Pro.', 'agenda-lite' );
			}
			if ( $scope_matches( 'zoom' ) && ! self::pro_feature_enabled( 'video_zoom' ) ) {
				$integration_warnings[] = esc_html__( 'Zoom es una integración Pro.', 'agenda-lite' );
			}
			if ( $scope_matches( 'zoom' ) && $zoom_enabled && ( $zoom_account_id === '' || $zoom_client_id === '' || $zoom_client_secret === '' ) ) {
				$zoom_enabled           = false;
				$integration_warnings[] = esc_html__( 'Zoom quedó desactivado porque faltan credenciales obligatorias (Account ID, Client ID o Client Secret).', 'agenda-lite' );
			}
			if ( $scope_matches( 'teams' ) && ! empty( $current_options['teams'] ) ) {
				$integration_warnings[] = esc_html__( 'Microsoft Teams se dejó desactivado porque esta integración está en estado Próximamente.', 'agenda-lite' );
			}
			if ( $scope_matches( 'recaptcha' ) && $recaptcha_enabled && ( $recaptcha_site_key === '' || $recaptcha_secret_key === '' ) ) {
				self::flash_notice( esc_html__( 'reCAPTCHA v3: debes ingresar Site Key y Secret Key.', 'agenda-lite' ), 'error' );
				wp_safe_redirect( $redirect_integrations_url );
				exit;
			}
			if ( $scope_matches( 'google_tag_manager' ) && $gtm_enabled && ( $gtm_container_id === '' || ! preg_match( '/^GTM-[A-Z0-9]+$/', $gtm_container_id ) ) ) {
				self::flash_notice( esc_html__( 'Google Tag Manager: el ID debe tener formato válido, por ejemplo GTM-ABC1234.', 'agenda-lite' ), 'error' );
				wp_safe_redirect( $redirect_integrations_url );
				exit;
			}
			if ( $scope_matches( 'google_analytics' ) && $ga_enabled && ( $ga_measurement_id === '' || ! preg_match( '/^G-[A-Z0-9]+$/', $ga_measurement_id ) ) ) {
				self::flash_notice( esc_html__( 'Google Analytics: el Measurement ID debe tener formato válido, por ejemplo G-ABC123XYZ.', 'agenda-lite' ), 'error' );
				wp_safe_redirect( $redirect_integrations_url );
				exit;
			}
			if ( $scope_matches( 'google_ads' ) && $gads_enabled && ( $gads_tag_id === '' || ! preg_match( '/^AW-[A-Z0-9-]+$/', $gads_tag_id ) ) ) {
				self::flash_notice( esc_html__( 'Google Ads: el Tag ID debe tener formato válido, por ejemplo AW-123456789.', 'agenda-lite' ), 'error' );
				wp_safe_redirect( $redirect_integrations_url );
				exit;
			}
			if ( $scope_matches( 'meta_pixel' ) && $meta_pixel_enabled && ( $meta_pixel_id === '' || ! preg_match( '/^[0-9]{6,20}$/', $meta_pixel_id ) ) ) {
				self::flash_notice( esc_html__( 'Meta Pixel: el Pixel ID debe ser numérico (6 a 20 dígitos).', 'agenda-lite' ), 'error' );
				wp_safe_redirect( $redirect_integrations_url );
				exit;
			}
			$options = array(
				'payments_flow'                => $flow_enabled ? 1 : 0,
				'payments_mp'                  => $mp_enabled ? 1 : 0,
				'payments_webpay'              => $webpay_enabled ? 1 : 0,
				'payments_paypal'              => $paypal_enabled ? 1 : 0,
				'payments_stripe'              => $stripe_enabled ? 1 : 0,
				'payments_transfer'            => $transfer_enabled ? 1 : 0,
				'google_calendar'              => $google_calendar_enabled ? 1 : 0,
				'google_meet'                  => 0,
				'zoom'                         => $zoom_enabled ? 1 : 0,
				'zoom_account_id'              => $zoom_account_id,
				'zoom_client_id'               => $zoom_client_id,
				'zoom_client_secret'           => $zoom_client_secret,
				'zoom_user_id'                 => $zoom_user_id,
				'teams'                        => $teams_enabled ? 1 : 0,
				'teams_tenant_id'              => $teams_tenant_id,
				'teams_client_id'              => $teams_client_id,
				'teams_client_secret'          => $teams_client_secret,
				'teams_organizer_email'        => $teams_organizer_email,
				'gtm_enabled'                  => $gtm_enabled ? 1 : 0,
				'gtm_container_id'             => $gtm_container_id,
				'ga_enabled'                   => $ga_enabled ? 1 : 0,
				'ga_measurement_id'            => $ga_measurement_id,
				'gads_enabled'                 => $gads_enabled ? 1 : 0,
				'gads_tag_id'                  => $gads_tag_id,
				'meta_pixel_enabled'           => $meta_pixel_enabled ? 1 : 0,
				'meta_pixel_id'                => $meta_pixel_id,
				'meta_pixel_input'             => $meta_pixel_input,
				'recaptcha_enabled'            => $recaptcha_enabled ? 1 : 0,
				'recaptcha_site_key'           => $recaptcha_site_key,
				'recaptcha_secret_key'         => $recaptcha_secret_key,
				'flow_api_key'                 => $flow_api_key,
				'flow_secret_key'              => $flow_secret_key,
				'flow_return_url'              => esc_url_raw( (string) $posted_value( 'flow_return_url', (string) ( $current_options['flow_return_url'] ?? '' ) ) ),
				'flow_sandbox'                 => $posted_int_flag( 'flow_sandbox', (int) ( $current_options['flow_sandbox'] ?? 1 ) ),
				'mp_access_token'              => $mp_access_token,
				'mp_webhook_secret'            => $mp_webhook_secret,
				'mp_sandbox'                   => $posted_int_flag( 'mp_sandbox', (int) ( $current_options['mp_sandbox'] ?? 1 ) ),
				'webpay_commerce_code'         => $webpay_commerce_code,
				'webpay_api_key'               => $webpay_api_key,
				'webpay_sandbox'               => $posted_int_flag( 'webpay_sandbox', (int) ( $current_options['webpay_sandbox'] ?? 1 ) ),
				'transfer_holder'              => $transfer_holder,
				'transfer_bank'                => $transfer_bank,
				'transfer_country'             => $transfer_country,
				'transfer_currency'            => $transfer_currency,
				'transfer_account_number'      => $transfer_account_number,
				'transfer_account_type'        => $transfer_account_type,
				'transfer_confirmation_email'  => $transfer_confirmation_email,
				'transfer_extra_fields'        => $transfer_extra_fields,
				'transfer_instructions'        => $transfer_instructions,
				'paypal_client_id'             => $paypal_client_id,
				'paypal_secret'                => $paypal_secret,
				'paypal_webhook_id'            => $paypal_webhook_id,
				'paypal_sandbox'               => $paypal_sandbox,
				'stripe_mode'                  => $stripe_mode,
				'stripe_public_key_test'       => $stripe_public_key_test,
				'stripe_secret_key_test'       => $stripe_secret_key_test,
				'stripe_webhook_secret_test'   => $stripe_webhook_secret_test,
				'stripe_public_key_live'       => $stripe_public_key_live,
				'stripe_secret_key_live'       => $stripe_secret_key_live,
				'stripe_webhook_secret_live'   => $stripe_webhook_secret_live,
				'google_client_id'             => $google_client_id,
				'google_client_secret'         => $google_client_secret,
				'google_calendar_mode'         => $google_calendar_mode,
				'google_calendar_id'           => $google_calendar_id ?: 'primary',
				'google_calendar_employee_ids' => $google_calendar_employee_ids,
			);
			update_option( 'litecal_integrations', $options );
			$notice_message = esc_html__( 'Integraciones guardadas.', 'agenda-lite' );
			if ( ! empty( $integration_warnings ) ) {
				$notice_message .= ' ' . implode( ' ', $integration_warnings );
			}
			self::flash_notice( $notice_message, 'success' );
			wp_safe_redirect( $redirect_integrations_url );
			exit;
		}

		$current_settings = get_option( 'litecal_settings', array() );
		if ( $tab === 'general' ) {
			$prev_currency = strtoupper( (string) ( $current_settings['currency'] ?? 'USD' ) );
			$currency      = strtoupper( sanitize_text_field( wp_unslash( $_POST['currency'] ?? 'USD' ) ) );
			if ( ! in_array( $currency, array( 'CLP', 'USD' ), true ) ) {
				$currency = 'USD';
			}
			$time_format = sanitize_text_field( wp_unslash( $_POST['time_format'] ?? '12h' ) );
			if ( ! in_array( $time_format, array( '12h', '24h' ), true ) ) {
				$time_format = '12h';
			}
			$multicurrency_enabled     = ! empty( $_POST['multicurrency_enabled'] );
				$posted_provider_rates = wp_unslash( $_POST['multicurrency_provider_rate'] ?? array() ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Sanitized/casted per provider below.
			if ( ! is_array( $posted_provider_rates ) ) {
				$posted_provider_rates = array();
			}
			$integrations             = get_option( 'litecal_integrations', array() );
			$active_provider_keys     = self::active_payment_provider_keys( $integrations );
			$same_currency_count      = 0;
			$different_currency_count = 0;
			foreach ( $active_provider_keys as $provider_key ) {
				if ( $provider_key === 'stripe' ) {
					continue;
				}
				$provider_currency = self::provider_base_currency( $provider_key );
				if ( $provider_currency === $currency ) {
					++$same_currency_count;
				} else {
					++$different_currency_count;
				}
			}
			$multicurrency_provider_rates = array();
			if ( $multicurrency_enabled ) {
				if ( $same_currency_count <= 0 || $different_currency_count <= 0 ) {
					self::flash_notice( esc_html__( 'No se puede activar multimoneda: necesitas al menos un medio de pago en CLP y uno en USD (por ejemplo Webpay Plus + PayPal o Stripe Checkout).', 'agenda-lite' ), 'error' );
					wp_safe_redirect( Helpers::admin_url( 'settings' ) . '&tab=general' );
					exit;
				}
				foreach ( $active_provider_keys as $provider_key ) {
					if ( $provider_key === 'stripe' ) {
						continue;
					}
					$provider_currency = self::provider_base_currency( $provider_key );
					if ( $provider_currency === $currency ) {
						continue;
					}
					$raw_rate = $posted_provider_rates[ $provider_key ] ?? '';
					$rate     = (float) str_replace( ',', '.', (string) $raw_rate );
					if ( $rate <= 0 ) {
						self::flash_notice( esc_html__( 'No se puede guardar: debes definir una tasa de cambio para los medios activos en otra moneda.', 'agenda-lite' ), 'error' );
						wp_safe_redirect( Helpers::admin_url( 'settings' ) . '&tab=general' );
						exit;
					}
					$multicurrency_provider_rates[ $provider_key ] = $rate;
				}
			}
			if ( $currency !== $prev_currency && ! $multicurrency_enabled ) {
				$multicurrency_enabled        = false;
				$multicurrency_provider_rates = array();
			}
			if ( self::is_free_plan() ) {
				$multicurrency_enabled        = false;
				$multicurrency_provider_rates = array();
			}
			$current_settings['currency']                     = $currency;
			$current_settings['time_format']                  = $time_format;
			$current_settings['multicurrency_enabled']        = $multicurrency_enabled ? 1 : 0;
			$current_settings['multicurrency_provider_rates'] = $multicurrency_provider_rates;
			$current_settings['multicurrency_rates']          = array();
			update_option( 'litecal_settings', $current_settings );
			$appearance_current = get_option( 'litecal_appearance', array() );
			$accent             = sanitize_hex_color( wp_unslash( $_POST['accent'] ?? '#083a53' ) );
			$accent_text        = sanitize_hex_color( wp_unslash( $_POST['accent_text'] ?? '#ffffff' ) );
			update_option(
				'litecal_appearance',
				array(
					'accent'          => $accent ?: '#083a53',
					'accent_text'     => $accent_text ?: '#ffffff',
					'show_powered_by' => ! empty( $_POST['show_powered_by'] ) ? 1 : ( ! isset( $_POST['show_powered_by'] ) && ! empty( $appearance_current['show_powered_by'] ) ? 1 : 0 ),
				)
			);
			// Sync currency to existing events so front reflects the global setting.
			$events = Events::all();
			foreach ( $events as $evt ) {
				$evt_options = json_decode( $evt->options ?: '[]', true );
				if ( ! is_array( $evt_options ) ) {
					$evt_options = array();
				}
				Events::update(
					$evt->id,
					array(
						'currency' => $currency,
						'options'  => wp_json_encode( $evt_options ),
					)
				);
			}
		} elseif ( $tab === 'seo' ) {
			$current_settings['seo_brand']                = sanitize_text_field( wp_unslash( $_POST['seo_brand'] ?? get_bloginfo( 'name' ) ) );
			$current_settings['seo_fallback_description'] = sanitize_textarea_field( wp_unslash( $_POST['seo_fallback_description'] ?? '' ) );
				$current_settings['seo_default_image']    = esc_url_raw( wp_unslash( $_POST['seo_default_image'] ?? '' ) );
			update_option( 'litecal_settings', $current_settings );
		} elseif ( $tab === 'advanced' ) {
			$asset_mode = sanitize_key( (string) ( $_POST['asset_mode'] ?? 'cdn_fallback' ) );
			if ( ! in_array( $asset_mode, array( 'cdn_fallback', 'local_only' ), true ) ) {
				$asset_mode = 'cdn_fallback';
			}
			$trust_cloudflare_proxy    = ! empty( $_POST['trust_cloudflare_proxy'] ) ? 1 : 0;
			$revoke_caps_on_deactivate = ! empty( $_POST['revoke_caps_on_deactivate'] ) ? 1 : 0;

			$current_settings['asset_mode']                = $asset_mode;
			$current_settings['trust_cloudflare_proxy']    = $trust_cloudflare_proxy;
			$current_settings['roles_allowed']             = array( 'administrator' );
			$current_settings['revoke_caps_on_deactivate'] = $revoke_caps_on_deactivate;
			update_option( 'litecal_settings', $current_settings );
			if ( function_exists( 'litecal_sync_caps' ) ) {
				litecal_sync_caps( array( 'administrator' ) );
			}
		} elseif ( $tab === 'self_service' ) {
			if ( self::is_free_plan() ) {
				self::apply_free_self_service_settings( $current_settings );
			} else {
				$manage_reschedule_enabled          = ! empty( $_POST['manage_reschedule_enabled'] ) ? 1 : 0;
				$manage_cancel_free_enabled         = ! empty( $_POST['manage_cancel_free_enabled'] ) ? 1 : 0;
				$manage_cancel_paid_enabled         = ! empty( $_POST['manage_cancel_paid_enabled'] ) ? 1 : 0;
					$manage_reschedule_cutoff_hours = max( 0, min( 720, absint( wp_unslash( $_POST['manage_reschedule_cutoff_hours'] ?? 12 ) ) ) );
					$manage_cancel_cutoff_hours     = max( 0, min( 720, absint( wp_unslash( $_POST['manage_cancel_cutoff_hours'] ?? 24 ) ) ) );
					$manage_max_reschedules         = max( 0, min( 20, absint( wp_unslash( $_POST['manage_max_reschedules'] ?? 1 ) ) ) );
				$manage_cooldown_minutes        = max( 0, min( 240, absint( wp_unslash( $_POST['manage_cooldown_minutes'] ?? 10 ) ) ) );
				$manage_grace_minutes           = max( 0, min( 240, absint( wp_unslash( $_POST['manage_grace_minutes'] ?? 15 ) ) ) );
				$manage_rate_limit_token        = max( 1, min( 200, absint( wp_unslash( $_POST['manage_rate_limit_token'] ?? 20 ) ) ) );
				$manage_rate_limit_ip           = max( 1, min( 500, absint( wp_unslash( $_POST['manage_rate_limit_ip'] ?? 60 ) ) ) );
				$manage_allow_change_staff          = ! empty( $_POST['manage_allow_change_staff'] ) ? 1 : 0;

				$current_settings['manage_reschedule_enabled']      = $manage_reschedule_enabled;
				$current_settings['manage_cancel_free_enabled']     = $manage_cancel_free_enabled;
				$current_settings['manage_cancel_paid_enabled']     = $manage_cancel_paid_enabled;
				$current_settings['manage_reschedule_cutoff_hours'] = $manage_reschedule_cutoff_hours;
				$current_settings['manage_cancel_cutoff_hours']     = $manage_cancel_cutoff_hours;
				$current_settings['manage_max_reschedules']         = $manage_max_reschedules;
				$current_settings['manage_cooldown_minutes']        = $manage_cooldown_minutes;
				$current_settings['manage_grace_minutes']           = $manage_grace_minutes;
				$current_settings['manage_rate_limit_token']        = $manage_rate_limit_token;
				$current_settings['manage_rate_limit_ip']           = $manage_rate_limit_ip;
				$current_settings['manage_allow_change_staff']      = $manage_allow_change_staff;
			}
			update_option( 'litecal_settings', $current_settings );
		} elseif ( $tab === 'abuse_security' ) {
			if ( self::is_free_plan() ) {
				self::apply_free_self_service_settings( $current_settings );
			} else {
				$current_settings['manage_abuse_limit']        = max( 0, min( 200, absint( wp_unslash( $_POST['manage_abuse_limit'] ?? 3 ) ) ) );
				$current_settings['manage_abuse_max_codes']    = max( 1, min( 20, absint( wp_unslash( $_POST['manage_abuse_max_codes'] ?? 2 ) ) ) );
				$current_settings['manage_abuse_period_hours'] = max( 1, min( 720, absint( wp_unslash( $_POST['manage_abuse_period_hours'] ?? 24 ) ) ) );
			}
			update_option( 'litecal_settings', $current_settings );
		} elseif ( $tab === 'smtp' ) {
			$current_settings['smtp_host']        = sanitize_text_field( wp_unslash( $_POST['smtp_host'] ?? '' ) );
			$current_settings['smtp_port']        = sanitize_text_field( wp_unslash( $_POST['smtp_port'] ?? '' ) );
			$current_settings['smtp_user']        = sanitize_text_field( wp_unslash( $_POST['smtp_user'] ?? '' ) );
			$current_settings['smtp_pass']        = (string) wp_unslash( $_POST['smtp_pass'] ?? '' ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Password must be preserved in raw form.
			$current_settings['smtp_encryption']  = sanitize_text_field( wp_unslash( $_POST['smtp_encryption'] ?? 'none' ) );
			$current_settings['email_from_name']  = sanitize_text_field( wp_unslash( $_POST['email_from_name'] ?? get_bloginfo( 'name' ) ) );
			$current_settings['email_from_email'] = sanitize_email( wp_unslash( $_POST['email_from_email'] ?? get_option( 'admin_email' ) ) );
			update_option( 'litecal_settings', $current_settings );
		} elseif ( $tab === 'appearance' ) {
			$appearance_current = get_option( 'litecal_appearance', array() );
			$accent             = sanitize_hex_color( wp_unslash( $_POST['accent'] ?? '#083a53' ) );
			$accent_text        = sanitize_hex_color( wp_unslash( $_POST['accent_text'] ?? '#ffffff' ) );
			update_option(
				'litecal_appearance',
				array(
					'accent'          => $accent ?: '#083a53',
					'accent_text'     => $accent_text ?: '#ffffff',
					'show_powered_by' => ! empty( $_POST['show_powered_by'] ) ? 1 : ( ! isset( $_POST['show_powered_by'] ) && ! empty( $appearance_current['show_powered_by'] ) ? 1 : 0 ),
				)
			);
		}
		if ( ! empty( $_POST['templates'] ) && is_array( $_POST['templates'] ) ) {
			if ( $tab === 'templates' ) {
				if ( self::is_free_plan() ) {
					$current_settings['reminder_first_hours']  = 0;
					$current_settings['reminder_second_hours'] = 0;
					$current_settings['reminder_third_hours']  = 0;
				} else {
						$current_settings['reminder_first_hours']  = max( 0, min( 720, absint( wp_unslash( $_POST['reminder_first_hours'] ?? 24 ) ) ) );
						$current_settings['reminder_second_hours'] = max( 0, min( 720, absint( wp_unslash( $_POST['reminder_second_hours'] ?? 12 ) ) ) );
						$current_settings['reminder_third_hours']  = max( 0, min( 720, absint( wp_unslash( $_POST['reminder_third_hours'] ?? 1 ) ) ) );
				}
				update_option( 'litecal_settings', $current_settings );
			}
				$raw = wp_unslash( $_POST['templates'] ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Sanitized per key/value below.
			$map     = array();
			foreach ( array( 'confirmed', 'cancelled', 'rescheduled', 'updated', 'payment_expired', 'reminder' ) as $key ) {
				if ( self::is_free_plan() && $key === 'reminder' ) {
					continue;
				}
				$entry = $raw[ $key ] ?? array();
				if ( isset( $entry['client_subject'] ) || isset( $entry['client_body'] ) ) {
					$map[ $key . '_client_subject' ] = sanitize_text_field( $entry['client_subject'] ?? '' );
					$map[ $key . '_client_body' ]    = sanitize_textarea_field( $entry['client_body'] ?? '' );
				}
				if ( isset( $entry['team_subject'] ) || isset( $entry['team_body'] ) ) {
					$map[ $key . '_team_subject' ] = sanitize_text_field( $entry['team_subject'] ?? '' );
					$map[ $key . '_team_body' ]    = sanitize_textarea_field( $entry['team_body'] ?? '' );
				}
				if ( isset( $entry['guest_subject'] ) || isset( $entry['guest_body'] ) ) {
					$map[ $key . '_guest_subject' ] = sanitize_text_field( $entry['guest_subject'] ?? '' );
					$map[ $key . '_guest_body' ]    = sanitize_textarea_field( $entry['guest_body'] ?? '' );
				}
			}
			$current = get_option( 'litecal_email_templates', array() );
			foreach ( array( 'created', 'payment_failed' ) as $legacy_key ) {
				unset(
					$current[ $legacy_key . '_client_subject' ],
					$current[ $legacy_key . '_client_body' ],
					$current[ $legacy_key . '_team_subject' ],
					$current[ $legacy_key . '_team_body' ],
					$current[ $legacy_key . '_admin_subject' ],
					$current[ $legacy_key . '_admin_body' ]
				);
			}
			if ( $map ) {
				$current = array_merge( $current, $map );
			}
			update_option( 'litecal_email_templates', $current );
			$status_flags = get_option( 'litecal_email_template_status', array() );
			unset(
				$status_flags['created_client'],
				$status_flags['created_team'],
				$status_flags['payment_failed_client'],
				$status_flags['payment_failed_team']
			);
			if ( self::is_free_plan() ) {
				$status_flags['reminder_client'] = 0;
				$status_flags['reminder_team']   = 0;
				$status_flags['reminder_guest']  = 0;
			}
			update_option( 'litecal_email_template_status', $status_flags );
		}
		self::flash_notice( esc_html__( 'Ajustes guardados.', 'agenda-lite' ), 'success' );
		$tab = sanitize_text_field( wp_unslash( $_POST['tab'] ?? 'smtp' ) );
		wp_safe_redirect( Helpers::admin_url( 'settings' ) . '&tab=' . $tab );
		exit;
	}

	public static function ajax_validate_google_credentials() {
		if ( ! self::can_manage() ) {
			wp_send_json_error(
				array(
					'valid'   => false,
					'message' => __( 'No autorizado.', 'agenda-lite' ),
				),
				403
			);
		}
		check_ajax_referer( 'litecal_google_validate_credentials', 'nonce' );
		$client_id     = trim( sanitize_text_field( wp_unslash( $_POST['client_id'] ?? '' ) ) );
		$client_secret = trim( sanitize_text_field( wp_unslash( $_POST['client_secret'] ?? '' ) ) );
		if ( $client_id === '' || $client_secret === '' ) {
			wp_send_json_success(
				array(
					'valid'   => false,
					'message' => __( 'Ingresa Client ID y Client Secret para validar.', 'agenda-lite' ),
				)
			);
		}
		if ( ! (bool) preg_match( '/^\d+-[A-Za-z0-9\-]+\.apps\.googleusercontent\.com$/', $client_id )
			|| ! (bool) preg_match( '/^[A-Za-z0-9_-]{20,}$/', $client_secret ) ) {
			wp_send_json_success(
				array(
					'valid'   => false,
					'message' => __( 'Credenciales incorrectas. Revisa Client ID y Client Secret.', 'agenda-lite' ),
				)
			);
		}

		$redirect_uri = \LiteCal\Modules\Calendar\GoogleCalendar\GoogleCalendarModule::get_redirect_uri();
		$probe        = wp_remote_post(
			'https://oauth2.googleapis.com/token',
			array(
				'headers' => array(
					'Content-Type' => 'application/x-www-form-urlencoded',
				),
				'body'    => http_build_query(
					array(
						'code'          => 'litecal_probe_invalid_code',
						'client_id'     => $client_id,
						'client_secret' => $client_secret,
						'redirect_uri'  => $redirect_uri,
						'grant_type'    => 'authorization_code',
					)
				),
				'timeout' => 25,
			)
		);
		if ( is_wp_error( $probe ) ) {
			wp_send_json_success(
				array(
					'valid'   => false,
					'message' => __( 'No se pudo validar contra Google. Intenta nuevamente.', 'agenda-lite' ),
				)
			);
		}
		$body  = json_decode( wp_remote_retrieve_body( $probe ), true );
		$error = strtolower( (string) ( $body['error'] ?? '' ) );
		$ok    = false;
		if ( $error === 'invalid_client' ) {
			$ok = false;
		} elseif ( in_array( $error, array( 'invalid_grant', 'redirect_uri_mismatch' ), true ) ) {
			$ok = true;
		} elseif ( ! empty( $body['access_token'] ) ) {
			$ok = true;
		}

		if ( $ok ) {
			wp_send_json_success(
				array(
					'valid'   => true,
					'message' => __( 'Credenciales válidas. Conexión lista para Google Calendar.', 'agenda-lite' ),
				)
			);
		}
		wp_send_json_success(
			array(
				'valid'   => false,
				'message' => __( 'Credenciales incorrectas. Revisa Client ID y Client Secret.', 'agenda-lite' ),
			)
		);
	}

	private static function calendar_booking_payload( $booking ) {
		$status_label         = self::status_label( $booking->status, 'booking' );
		$has_payment_context  = self::booking_has_payment_context( $booking );
		$payment_status_raw   = $has_payment_context ? ( $booking->payment_status ?: 'unpaid' ) : '';
		$payment_status_label = $has_payment_context ? self::status_label( $payment_status_raw, 'payment' ) : __( 'Sin pago', 'agenda-lite' );
		$snapshot             = Bookings::decode_snapshot( $booking );
		$event_title          = $snapshot['event']['title'] ?? ( $booking->event_title ?: '-' );
		$customer_deleted     = self::is_customer_removed_email_key( $booking->email ?? '' );
		return array(
			'id'                   => (int) $booking->id,
			'event_id'             => (int) $booking->event_id,
			'event'                => $event_title,
			'name'                 => $booking->name,
			'email'                => $booking->email,
			'phone'                => $booking->phone,
			'company'              => $booking->company,
			'message'              => $booking->message,
			'employee_id'          => $booking->employee_id,
			'start'                => $booking->start_datetime,
			'end'                  => $booking->end_datetime,
			'status'               => $booking->status,
			'status_label'         => $status_label,
			'payment_status'       => $payment_status_raw,
			'payment_status_label' => $payment_status_label,
			'payment_provider'     => $booking->payment_provider,
			'payment_amount'       => $booking->payment_amount,
			'payment_currency'     => $booking->payment_currency,
			'payment_error'        => $booking->payment_error ?? '',
			'calendar_meet_link'   => $booking->calendar_meet_link ?? '',
			'payment_reference'    => $booking->payment_reference ?? '',
			'custom_fields'        => $booking->custom_fields ? json_decode( $booking->custom_fields, true ) : array(),
			'guests'               => $booking->guests ? json_decode( $booking->guests, true ) : array(),
			'customer_deleted'     => $customer_deleted ? 1 : 0,
			'customer_deleted_label' => $customer_deleted ? __( 'Cliente eliminado', 'agenda-lite' ) : '',
			'snapshot'             => $snapshot,
		);
	}

	public static function ajax_calendar_bookings() {
		if ( ! self::can_access_staff_panel() ) {
			wp_send_json_error( array( 'message' => __( 'No autorizado.', 'agenda-lite' ) ), 403 );
		}
		check_ajax_referer( 'litecal_calendar_bookings', 'nonce' );
		$start_date        = sanitize_text_field( wp_unslash( $_REQUEST['start'] ?? '' ) );
		$end_date          = sanitize_text_field( wp_unslash( $_REQUEST['end'] ?? '' ) );
		$scope_employee_id = self::current_user_employee_id();
		if ( ! self::can_manage() && self::is_booking_manager_user() && $scope_employee_id <= 0 ) {
			wp_send_json_success( array( 'bookings' => array() ) );
		}
		$employee_id = $scope_employee_id > 0 ? $scope_employee_id : absint( wp_unslash( $_REQUEST['employee_id'] ?? 0 ) );
		if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $start_date ) || ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $end_date ) ) {
			wp_send_json_error( array( 'message' => __( 'Rango inválido.', 'agenda-lite' ) ), 400 );
		}
		$rows    = Bookings::in_range( $start_date . ' 00:00:00', $end_date . ' 00:00:00', false, $employee_id );
		$payload = array();
		foreach ( $rows as $booking ) {
			if ( $employee_id > 0 && (int) ( $booking->employee_id ?? 0 ) !== $employee_id ) {
				continue;
			}
			$payload[] = self::calendar_booking_payload( $booking );
		}
		wp_send_json_success( array( 'bookings' => $payload ) );
	}

	private static function grid_request_args() {
		$page  = max( 1, absint( wp_unslash( $_REQUEST['page'] ?? 1 ) ) );
		$limit = absint( wp_unslash( $_REQUEST['limit'] ?? ( $_REQUEST['per_page'] ?? 10 ) ) );
		if ( ! in_array( $limit, array( 10, 25, 50, 100 ), true ) ) {
			$limit = 10;
		}
		$search = sanitize_text_field( (string) wp_unslash( $_REQUEST['search'] ?? ( $_REQUEST['q'] ?? '' ) ) );
		$status = sanitize_text_field( (string) wp_unslash( $_REQUEST['status'] ?? '' ) );
		$view   = sanitize_key( (string) ( $_REQUEST['view'] ?? '' ) );
		if ( ! in_array( $view, array( 'active', 'trash' ), true ) ) {
			$view = 'active';
		}
		if ( $view === 'trash' && $status === '' ) {
			$status = 'deleted';
		}
		$sort_by  = sanitize_key( (string) ( $_REQUEST['sortBy'] ?? 'id' ) );
		$sort_dir = strtolower( sanitize_text_field( (string) wp_unslash( $_REQUEST['sortDir'] ?? 'desc' ) ) );
		$sort_dir = $sort_dir === 'asc' ? 'asc' : 'desc';
		if ( $status === 'expired' ) {
			$status = 'cancelled';
		}
		return array(
			'page'     => $page,
			'limit'    => $limit,
			'search'   => $search,
			'status'   => $status,
			'view'     => $view,
			'sort_by'  => $sort_by,
			'sort_dir' => $sort_dir,
		);
	}

	private static function grid_booking_row( $booking, array $employee_map, $trash_view = false ) {
		$snapshot            = Bookings::decode_snapshot( $booking );
		$status_label        = self::status_label( $booking->status, 'booking' );
		$status_class        = self::status_class( $booking->status, 'booking' );
		$has_payment_context = self::booking_has_payment_context( $booking );
		$payment_label       = $has_payment_context ? self::status_label( $booking->payment_status ?: 'unpaid', 'payment' ) : __( 'Sin pago', 'agenda-lite' );
		$payment_class       = $has_payment_context ? self::status_class( $booking->payment_status ?: 'unpaid', 'payment' ) : 'is-muted';
		$amount_label        = $booking->payment_amount ? self::format_money_label( $booking->payment_amount, $booking->payment_currency ?: 'CLP' ) : __( 'Sin pago', 'agenda-lite' );
		$payment_datetime    = $booking->payment_received_at ?? '';
		if ( empty( $payment_datetime ) || $payment_datetime === '0000-00-00 00:00:00' ) {
			$payment_datetime = ! empty( $booking->updated_at ) ? $booking->updated_at : '';
		}
		$payment_date_label = $payment_datetime ? self::format_date_short( $payment_datetime ) : '-';
		$payment_time_label = $payment_datetime ? self::format_time_short( $payment_datetime ) : '-';
		$staff_limited      = ! self::can_manage() && self::is_booking_manager_user();
		$attendee           = $snapshot['employee']['name'] ?? '';
		if ( ! $attendee && ! empty( $booking->employee_id ) ) {
			$attendee = $employee_map[ (int) $booking->employee_id ] ?? '';
		}
		$attendee              = $attendee ?: '-';
		$customer_deleted      = self::is_customer_removed_email_key( $booking->email ?? '' );
		$calendar_url          = add_query_arg(
			array(
				'page'          => 'litecal-dashboard',
				'booking_id'    => (int) $booking->id,
				'calendar_date' => substr( (string) $booking->start_datetime, 0, 10 ),
			),
			admin_url( 'admin.php' )
		);
		$receipt_url           = add_query_arg(
			array(
				'agendalite_receipt'       => 1,
				'agendalite_receipt_admin' => 1,
				'booking_id'               => $booking->id,
				'_wpnonce'                 => wp_create_nonce( 'litecal_admin_receipt_' . (int) $booking->id ),
			),
			home_url( '/' )
		);
		$event_title           = $snapshot['event']['title'] ?? ( $booking->event_title ?: '-' );
		$event_short           = self::truncate_text( $event_title, 15 );
		$start_datetime        = (string) ( $booking->start_datetime ?? '' );
		$start_date_label      = $start_datetime ? self::format_date_short( $start_datetime ) : '-';
		$start_time_label      = $start_datetime ? self::format_time_short( $start_datetime ) : '-';
		$start_order           = $start_datetime ? substr( $start_datetime, 0, 19 ) : '';
		$start_cell            = '<span class="lc-date-cell" data-order="' . esc_attr( $start_order ) . '">' .
			esc_html( $start_date_label ) .
			'<small class="lc-subtime-cell">' . esc_html( $start_time_label ) . '</small>' .
			'</span>';
		$payment_order         = $payment_datetime ? substr( (string) $payment_datetime, 0, 19 ) : '';
		$payment_datetime_cell = $has_payment_context
			? '<span class="lc-date-cell" data-order="' . esc_attr( $payment_order ) . '">' .
				esc_html( $payment_date_label ) .
				'<small class="lc-subtime-cell">' . esc_html( $payment_time_label ) . '</small>' .
				'</span>'
			: '<span class="lc-ellipsis">' . esc_html__( 'Sin pago', 'agenda-lite' ) . '</span>';
		$delete_cell           = '';
		if ( $staff_limited ) {
			$delete_cell = '';
		} elseif ( $trash_view ) {
			$restore_nonce = wp_create_nonce( 'litecal_bulk_update_bookings' );
			$delete_nonce  = wp_create_nonce( 'litecal_bulk_update_bookings' );
			$restore_form  = '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="lc-inline-form">'
				. '<input type="hidden" name="_wpnonce" value="' . esc_attr( $restore_nonce ) . '">'
				. '<input type="hidden" name="action" value="litecal_bulk_update_bookings">'
				. '<input type="hidden" name="redirect" value="bookings">'
				. '<input type="hidden" name="redirect_view" value="trash">'
				. '<input type="hidden" name="ids" value="' . esc_attr( (string) $booking->id ) . '">'
				. '<input type="hidden" name="bulk_status" value="restore">'
				. '<button class="button lc-icon-btn lc-icon-btn-restore" type="submit" aria-label="' . esc_attr__( 'Restaurar reserva', 'agenda-lite' ) . '" title="' . esc_attr__( 'Restaurar reserva', 'agenda-lite' ) . '"><i class="ri-restart-line"></i></button>'
				. '</form>';
			$delete_form   = '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="lc-inline-form" data-lc-confirm-delete data-lc-confirm-title="' . esc_attr__( '¿Eliminar permanentemente esta reserva?', 'agenda-lite' ) . '" data-lc-confirm-text="' . esc_attr__( 'Esta acción no se puede deshacer ni recuperar.', 'agenda-lite' ) . '" data-lc-confirm-yes="' . esc_attr__( 'Eliminar permanentemente', 'agenda-lite' ) . '" data-lc-confirm-class="button button-link-delete">'
				. '<input type="hidden" name="_wpnonce" value="' . esc_attr( $delete_nonce ) . '">'
				. '<input type="hidden" name="action" value="litecal_bulk_update_bookings">'
				. '<input type="hidden" name="redirect" value="bookings">'
				. '<input type="hidden" name="redirect_view" value="trash">'
				. '<input type="hidden" name="ids" value="' . esc_attr( (string) $booking->id ) . '">'
				. '<input type="hidden" name="bulk_status" value="delete_permanent">'
				. '<button class="button lc-icon-btn lc-icon-btn-delete" type="submit" aria-label="' . esc_attr__( 'Eliminar permanentemente', 'agenda-lite' ) . '" title="' . esc_attr__( 'Eliminar permanentemente', 'agenda-lite' ) . '"><i class="ri-delete-bin-line"></i></button>'
				. '</form>';
			$delete_cell   = '<div class="lc-inline-actions">' . $restore_form . $delete_form . '</div>';
		} else {
			$delete_cell = '<button class="button button-link-delete lc-icon-btn lc-icon-btn-delete" type="button" data-lc-delete-id="' . esc_attr( $booking->id ) . '" aria-label="' . esc_attr__( 'Mover a papelera', 'agenda-lite' ) . '"><i class="ri-delete-bin-line"></i></button>';
		}

		return array(
			'id_raw'            => (int) $booking->id,
			'id'                => '#' . esc_html( $booking->id ),
			'client'            => '<span class="lc-ellipsis">' . esc_html( $booking->name ) . '</span>' . ( $customer_deleted ? '<small class="lc-subtime-cell">' . esc_html__( 'Cliente eliminado', 'agenda-lite' ) . '</small>' : '' ),
			'event'             => '<span class="lc-booking-event" title="' . esc_attr( $event_title ) . '">' . esc_html( $event_short ) . '</span>',
			'attendee'          => '<span class="lc-ellipsis">' . esc_html( $attendee ) . '</span>',
			'date_time'         => $start_cell,
			'status'            => '<span class="lc-badge ' . esc_attr( $status_class ) . '">' . esc_html( $status_label ) . '</span>',
			'payment'           => $has_payment_context
				? '<span class="lc-badge ' . esc_attr( $payment_class ) . '">' . esc_html( $payment_label ) . '</span>'
				: '<span class="lc-ellipsis">' . esc_html( $payment_label ) . '</span>',
			'amount'            => '<span class="lc-ellipsis">' . esc_html( $amount_label ) . '</span>',
			'payment_date_time' => $payment_datetime_cell,
			'calendar'          => $trash_view ? '' : '<div class="lc-cell-center"><a class="button lc-icon-btn lc-icon-btn-calendar" href="' . esc_url( $calendar_url ) . '" aria-label="' . esc_attr__( 'Ver calendario', 'agenda-lite' ) . '"><i class="ri-calendar-line"></i></a></div>',
			'receipt'           => $staff_limited ? '' : '<div class="lc-cell-center"><a class="button lc-icon-btn lc-icon-btn-receipt" href="' . esc_url( $receipt_url ) . '" target="_blank" rel="noopener" aria-label="' . esc_attr__( 'Ver recibo', 'agenda-lite' ) . '"><i class="ri-receipt-line"></i></a></div>',
			'delete'            => $delete_cell !== '' ? '<div class="lc-cell-center">' . $delete_cell . '</div>' : '',
		);
	}

	private static function grid_payment_row( $booking ) {
		$snapshot         = Bookings::decode_snapshot( $booking );
		$amount           = $booking->payment_amount ? self::format_money_label( $booking->payment_amount, $booking->payment_currency ?: 'CLP' ) : '-';
		$payment_status   = self::status_label( $booking->payment_status ?: 'unpaid', 'payment' );
		$payment_class    = self::status_class( $booking->payment_status ?: 'unpaid', 'payment' );
		$provider_meta    = self::payment_provider_meta( $booking->payment_provider ?: '' );
		$provider_label   = ! empty( $booking->payment_provider ) ? $provider_meta['label'] : '-';
		$event_title      = $snapshot['event']['title'] ?? ( $booking->event_title ?: '-' );
		$event_short      = self::truncate_text( $event_title, 15 );
		$receipt_url      = add_query_arg(
			array(
				'agendalite_receipt'       => 1,
				'agendalite_receipt_admin' => 1,
				'booking_id'               => $booking->id,
				'_wpnonce'                 => wp_create_nonce( 'litecal_admin_receipt_' . (int) $booking->id ),
			),
			home_url( '/' )
		);
		$payment_datetime = $booking->payment_received_at ?? '';
		if ( empty( $payment_datetime ) || $payment_datetime === '0000-00-00 00:00:00' ) {
			$payment_datetime = ! empty( $booking->updated_at ) ? $booking->updated_at : ( $snapshot['booking']['start'] ?? $booking->start_datetime );
		}
		$ref = $booking->payment_reference ?: '-';

		return array(
			'id_raw'    => (int) $booking->id,
			'id'        => '#' . esc_html( $booking->id ),
			'client'    => '<span class="lc-ellipsis">' . esc_html( $booking->name ) . '</span>',
			'event'     => '<span class="lc-booking-event" title="' . esc_attr( $event_title ) . '">' . esc_html( $event_short ) . '</span>',
			'date'      => '<span class="lc-date-cell" data-order="' . esc_attr( substr( (string) $payment_datetime, 0, 10 ) ) . '">' . esc_html( self::format_date_short( $payment_datetime ) ) . '</span>',
			'time'      => '<span class="lc-time-cell" data-order="' . esc_attr( substr( (string) $payment_datetime, 11, 8 ) ) . '">' . esc_html( self::format_time_short( $payment_datetime ) ) . '</span>',
			'provider'  => '<span class="lc-ellipsis">' . esc_html( $provider_label ) . '</span>',
			'amount'    => '<span class="lc-ellipsis">' . esc_html( $amount ) . '</span>',
			'status'    => '<span class="lc-badge ' . esc_attr( $payment_class ) . '">' . esc_html( $payment_status ) . '</span>',
			'reference' => '<span class="lc-ellipsis">' . esc_html( $ref ) . '</span>',
			'receipt'   => '<a class="button lc-icon-btn lc-icon-btn-receipt" href="' . esc_url( $receipt_url ) . '" target="_blank" rel="noopener" aria-label="' . esc_attr__( 'Ver recibo', 'agenda-lite' ) . '"><i class="ri-receipt-line"></i></a>',
			'delete'    => '<button class="button button-link-delete lc-icon-btn lc-icon-btn-delete" type="button" data-lc-delete-id="' . esc_attr( $booking->id ) . '" aria-label="' . esc_attr__( 'Eliminar', 'agenda-lite' ) . '"><i class="ri-delete-bin-line"></i></button>',
		);
	}

	public static function ajax_grid_bookings() {
		if ( ! self::can_access_staff_panel() ) {
			wp_send_json_error( array( 'message' => __( 'No autorizado.', 'agenda-lite' ) ), 403 );
		}
		if ( ! check_ajax_referer( 'litecal_grid_bookings', 'nonce', false ) && ! check_ajax_referer( 'litecal_grid_bookings', '_wpnonce', false ) ) {
			wp_send_json_error( array( 'message' => __( 'Solicitud inválida.', 'agenda-lite' ) ), 403 );
		}
		$args              = self::grid_request_args();
		$scope_employee_id = self::current_user_employee_id();
		if ( ! self::can_manage() && self::is_booking_manager_user() && $scope_employee_id <= 0 ) {
			wp_send_json_success(
				array(
					'page'  => (int) $args['page'],
					'limit' => (int) $args['limit'],
					'rows'  => array(),
					'total' => 0,
				)
			);
		}
		$include_deleted = ( $args['view'] === 'trash' ) || ( $args['status'] === 'deleted' );
		[$rows, $total]  = Bookings::search(
			$args['search'],
			$args['page'],
			$args['limit'],
			false,
			$args['status'],
			'status',
			$include_deleted,
			$args['sort_by'],
			$args['sort_dir'],
			$scope_employee_id
		);
		$employee_map    = array();
		foreach ( Employees::all_booking_managers( false ) as $emp ) {
			$employee_map[ (int) $emp->id ] = $emp->name;
		}
		$data = array();
		foreach ( $rows as $booking ) {
			$data[] = self::grid_booking_row( $booking, $employee_map, $args['view'] === 'trash' );
		}
		wp_send_json_success(
			array(
				'page'  => (int) $args['page'],
				'limit' => (int) $args['limit'],
				'rows'  => $data,
				'total' => (int) $total,
			)
		);
	}

	public static function ajax_grid_clients() {
		if ( ! self::can_access_staff_panel() ) {
			wp_send_json_error( array( 'message' => __( 'No autorizado.', 'agenda-lite' ) ), 403 );
		}
		if ( ! check_ajax_referer( 'litecal_grid_clients', 'nonce', false ) && ! check_ajax_referer( 'litecal_grid_clients', '_wpnonce', false ) ) {
			wp_send_json_error( array( 'message' => __( 'Solicitud inválida.', 'agenda-lite' ) ), 403 );
		}
		$args              = self::grid_request_args();
		$scope_employee_id = self::current_user_employee_id();
		$status_filter     = sanitize_key( (string) ( $args['status'] ?? '' ) );
		if ( ! in_array( $status_filter, array( '', 'blocked_any', 'blocked_preventive', 'blocked_manual' ), true ) ) {
			$status_filter = '';
		}
		if ( ! self::can_manage() && self::is_booking_manager_user() && $scope_employee_id <= 0 ) {
			wp_send_json_success(
				array(
					'page'  => (int) $args['page'],
					'limit' => (int) $args['limit'],
					'rows'  => array(),
					'total' => 0,
				)
			);
		}
		$blocked_whitelist = array();
		if ( $args['view'] !== 'trash' && $status_filter !== '' ) {
			$blocked_whitelist = self::customer_email_keys_by_abuse_filter( $status_filter, $scope_employee_id );
			if ( empty( $blocked_whitelist ) ) {
				wp_send_json_success(
					array(
						'page'  => (int) $args['page'],
						'limit' => (int) $args['limit'],
						'rows'  => array(),
						'total' => 0,
					)
				);
			}
		}
		[$rows, $total] = Bookings::customer_search(
			$args['search'],
			$args['page'],
			$args['limit'],
			$args['view'] === 'trash' ? 'trash' : 'active',
			$args['sort_by'],
			$args['sort_dir'],
			$scope_employee_id,
			$blocked_whitelist
		);
		$data = array();
		foreach ( (array) $rows as $customer ) {
			$data[] = self::grid_customer_row( $customer, $args['view'] === 'trash' );
		}
		wp_send_json_success(
			array(
				'page'  => (int) $args['page'],
				'limit' => (int) $args['limit'],
				'rows'  => $data,
				'total' => (int) $total,
			)
		);
	}

	public static function ajax_customer_history() {
		if ( ! self::can_access_staff_panel() ) {
			wp_send_json_error( array( 'message' => __( 'No autorizado.', 'agenda-lite' ) ), 403 );
		}
		if ( ! check_ajax_referer( 'litecal_customer_history', 'nonce', false ) && ! check_ajax_referer( 'litecal_customer_history', '_wpnonce', false ) ) {
			wp_send_json_error( array( 'message' => __( 'Solicitud inválida.', 'agenda-lite' ) ), 403 );
		}
		$email_key         = Bookings::normalize_email_key( sanitize_email( wp_unslash( $_GET['email'] ?? '' ) ) );
		$scope_employee_id = self::current_user_employee_id();
		if ( $email_key === '' ) {
			wp_send_json_error( array( 'message' => __( 'Cliente inválido.', 'agenda-lite' ) ), 400 );
		}
		$owned = self::filter_owned_customer_email_keys( array( $email_key ) );
		if ( empty( $owned ) ) {
			wp_send_json_error( array( 'message' => __( 'Cliente no encontrado.', 'agenda-lite' ) ), 404 );
		}
		$payload = self::customer_history_html( $email_key, $scope_employee_id );
		wp_send_json_success( $payload );
	}

	public static function ajax_customer_unlock_abuse() {
		if ( ! self::can_access_staff_panel() ) {
			wp_send_json_error( array( 'message' => __( 'No autorizado.', 'agenda-lite' ) ), 403 );
		}
		if ( ! check_ajax_referer( 'litecal_customer_unlock_abuse', 'nonce', false ) && ! check_ajax_referer( 'litecal_customer_unlock_abuse', '_wpnonce', false ) ) {
			wp_send_json_error( array( 'message' => __( 'Solicitud inválida.', 'agenda-lite' ) ), 403 );
		}
		$email_key         = Bookings::normalize_email_key( sanitize_email( wp_unslash( $_REQUEST['email'] ?? '' ) ) );
		$scope_employee_id = self::current_user_employee_id();
		if ( $email_key === '' ) {
			wp_send_json_error( array( 'message' => __( 'Cliente inválido.', 'agenda-lite' ) ), 400 );
		}
		$owned = self::filter_owned_customer_email_keys( array( $email_key ) );
		if ( empty( $owned ) ) {
			wp_send_json_error( array( 'message' => __( 'Cliente no encontrado.', 'agenda-lite' ) ), 404 );
		}
		Rest::reset_booking_abuse_for_email( $email_key );
		$payload            = self::customer_history_html( $email_key, $scope_employee_id );
		$payload['message'] = __( 'Cliente desbloqueado correctamente.', 'agenda-lite' );
		wp_send_json_success( $payload );
	}

	public static function ajax_customer_block_abuse() {
		if ( ! self::can_access_staff_panel() ) {
			wp_send_json_error( array( 'message' => __( 'No autorizado.', 'agenda-lite' ) ), 403 );
		}
		if ( ! check_ajax_referer( 'litecal_customer_block_abuse', 'nonce', false ) && ! check_ajax_referer( 'litecal_customer_block_abuse', '_wpnonce', false ) ) {
			wp_send_json_error( array( 'message' => __( 'Solicitud inválida.', 'agenda-lite' ) ), 403 );
		}
		$email_key         = Bookings::normalize_email_key( sanitize_email( wp_unslash( $_REQUEST['email'] ?? '' ) ) );
		$scope_employee_id = self::current_user_employee_id();
		if ( $email_key === '' ) {
			wp_send_json_error( array( 'message' => __( 'Cliente inválido.', 'agenda-lite' ) ), 400 );
		}
		$owned = self::filter_owned_customer_email_keys( array( $email_key ) );
		if ( empty( $owned ) ) {
			wp_send_json_error( array( 'message' => __( 'Cliente no encontrado.', 'agenda-lite' ) ), 404 );
		}
		$block = absint( wp_unslash( $_REQUEST['block'] ?? 1 ) ) === 1;
		Rest::booking_abuse_set_manual_block_for_email( $email_key, $block );
		$payload            = self::customer_history_html( $email_key, $scope_employee_id );
		$payload['message'] = $block
			? __( 'Cliente bloqueado manualmente.', 'agenda-lite' )
			: __( 'Bloqueo manual eliminado correctamente.', 'agenda-lite' );
		wp_send_json_success( $payload );
	}

	public static function ajax_grid_payments() {
		if ( ! self::can_manage() ) {
			wp_send_json_error( array( 'message' => __( 'No autorizado.', 'agenda-lite' ) ), 403 );
		}
		if ( ! check_ajax_referer( 'litecal_grid_payments', 'nonce', false ) && ! check_ajax_referer( 'litecal_grid_payments', '_wpnonce', false ) ) {
			wp_send_json_error( array( 'message' => __( 'Solicitud inválida.', 'agenda-lite' ) ), 403 );
		}
		$args           = self::grid_request_args();
		[$rows, $total] = Bookings::search(
			$args['search'],
			$args['page'],
			$args['limit'],
			true,
			$args['status'],
			'payment_status',
			true,
			$args['sort_by'],
			$args['sort_dir']
		);
		$data           = array();
		foreach ( $rows as $booking ) {
			$data[] = self::grid_payment_row( $booking );
		}
		wp_send_json_success(
			array(
				'page'  => (int) $args['page'],
				'limit' => (int) $args['limit'],
				'rows'  => $data,
				'total' => (int) $total,
			)
		);
	}

	public static function save_availability() {
		if ( ! self::can_manage() ) {
			wp_die( esc_html__( 'Unauthorized', 'agenda-lite' ) );
		}
		check_admin_referer( 'litecal_save_availability' );
		$scope    = sanitize_text_field( wp_unslash( $_POST['scope'] ?? 'global' ) );
		$scope_id = null;
		if ( $scope === 'event' ) {
			$scope_id = absint( wp_unslash( $_POST['scope_event_id'] ?? 0 ) );
		} elseif ( $scope === 'employee' ) {
			$scope_id = absint( wp_unslash( $_POST['scope_employee_id'] ?? 0 ) );
		}
		global $wpdb;
		$table = $wpdb->prefix . 'litecal_availability';
		$wpdb->delete(
			$table,
			array(
				'scope'    => $scope,
				'scope_id' => $scope_id,
			)
		);
		$now  = current_time( 'mysql' );
		$days = array_map( 'absint', (array) wp_unslash( $_POST['day'] ?? array() ) );
		foreach ( $days as $index => $day ) {
			$start = sanitize_text_field( wp_unslash( $_POST['start'][ $index ] ?? '' ) );
			$end   = sanitize_text_field( wp_unslash( $_POST['end'][ $index ] ?? '' ) );
			if ( ! $start || ! $end ) {
				continue;
			}
			$wpdb->insert(
				$table,
				array(
					'scope'       => $scope,
					'scope_id'    => $scope_id,
					'day_of_week' => (int) $day,
					'start_time'  => $start . ':00',
					'end_time'    => $end . ':00',
					'created_at'  => $now,
				)
			);
		}
		wp_safe_redirect( Helpers::admin_url( 'availability' ) );
		exit;
	}

	public static function save_time_off() {
		if ( ! self::can_access_staff_panel() ) {
			wp_die( esc_html__( 'Unauthorized', 'agenda-lite' ) );
		}
		check_admin_referer( 'litecal_save_time_off' );
		if ( self::is_free_plan() ) {
			self::flash_notice( esc_html__( 'La gestión de vacaciones está disponible en Agenda Lite Pro.', 'agenda-lite' ), 'error' );
			wp_safe_redirect( Helpers::admin_url( 'employees' ) );
			exit;
		}
		$scope    = sanitize_text_field( wp_unslash( $_POST['scope'] ?? 'global' ) );
		$scope_id = null;
		if ( $scope === 'employee' ) {
			$scope_id = absint( wp_unslash( $_POST['scope_employee_id'] ?? 0 ) );
		}
		if ( ! self::can_manage() ) {
			$scope_employee_id = self::current_user_employee_id();
			if ( $scope !== 'employee' || $scope_employee_id <= 0 || (int) $scope_id !== (int) $scope_employee_id ) {
				wp_die( esc_html__( 'Unauthorized', 'agenda-lite' ) );
			}
		}
		global $wpdb;
		$table       = $wpdb->prefix . 'litecal_time_off';
		$start_date  = sanitize_text_field( wp_unslash( $_POST['start_date'] ?? '' ) );
		$end_date    = sanitize_text_field( wp_unslash( $_POST['end_date'] ?? '' ) );
		$mode        = sanitize_text_field( wp_unslash( $_POST['action_mode'] ?? 'activate' ) );
		$reason_type = sanitize_text_field( wp_unslash( $_POST['reason_type'] ?? '' ) );
		$reason_raw  = sanitize_text_field( wp_unslash( $_POST['reason'] ?? '' ) );
		$reason      = $reason_raw;
		if ( $reason_type ) {
			$label  = $reason_type === 'feriado' ? 'Feriado' : 'Vacaciones';
			$reason = $reason_raw ? ( $label . ': ' . $reason_raw ) : $label;
		}
			if ( $mode === 'deactivate' ) {
				$wpdb->delete(
					$table,
					array(
						'scope'      => $scope,
						'scope_id'   => (int) $scope_id,
						'start_date' => $start_date,
						'end_date'   => $end_date,
					),
					array( '%s', '%d', '%s', '%s' )
				);
		} else {
			// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name comes from trusted $wpdb->prefix and values are prepared.
			$wpdb->query(
				$wpdb->prepare(
					"DELETE FROM {$table}
					WHERE scope = %s
					  AND scope_id = %d
					  AND start_date <= %s
					  AND end_date >= %s",
					$scope,
					(int) $scope_id,
					$end_date,
					$start_date
				)
			);
			// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->insert(
				$table,
				array(
					'scope'      => $scope,
					'scope_id'   => $scope_id,
					'start_date' => $start_date,
					'end_date'   => $end_date,
					'reason'     => $reason,
				)
			);
		}
		if ( $scope === 'employee' ) {
			wp_safe_redirect( Helpers::admin_url( 'employees' ) . '&edit=' . (int) $scope_id );
		} else {
			wp_safe_redirect( Helpers::admin_url( 'availability' ) );
		}
		exit;
	}

	public static function save_schedule() {
		if ( ! self::can_manage() ) {
			wp_die( esc_html__( 'Unauthorized', 'agenda-lite' ) );
		}
		check_admin_referer( 'litecal_save_schedule' );
		$schedule_id = sanitize_text_field( wp_unslash( $_POST['schedule_id'] ?? 'default' ) );
		$name        = sanitize_text_field( wp_unslash( $_POST['schedule_name'] ?? 'Horas laborales' ) );
		$timezone    = \LiteCal\Core\Helpers::site_timezone_name();
		$schedules   = get_option( 'litecal_schedules', array() );
		$existing    = is_array( $schedules[ $schedule_id ] ?? null ) ? $schedules[ $schedule_id ] : array();
		$days        = array();
		$breaks      = array();
		$bounds      = array();
		$invalid_day_count = 0;
		$day_enabled_raw   = array();
		if ( isset( $_POST['day_enabled'] ) && is_array( $_POST['day_enabled'] ) ) {
			$day_enabled_raw = array_map( 'sanitize_text_field', wp_unslash( $_POST['day_enabled'] ) );
		}
		foreach ( range( 1, 7 ) as $day ) {
			$enabled_value = $day_enabled_raw[ $day ] ?? '';
			$enabled       = in_array( (string) $enabled_value, array( '1', 'on', 'true' ), true );
			$start   = sanitize_text_field( wp_unslash( $_POST['start'][ $day ] ?? '' ) );
			$end     = sanitize_text_field( wp_unslash( $_POST['end'][ $day ] ?? '' ) );
			if ( $enabled && $start && $end && preg_match( '/^\d{2}:\d{2}$/', $start ) && preg_match( '/^\d{2}:\d{2}$/', $end ) && $start < $end ) {
				$day_ranges        = array();
				$break_start       = sanitize_text_field( wp_unslash( $_POST['break_start'][ $day ] ?? '' ) );
				$break_end         = sanitize_text_field( wp_unslash( $_POST['break_end'][ $day ] ?? '' ) );
				$break_enabled_raw = absint( wp_unslash( $_POST['break_enabled'][ $day ] ?? 0 ) );
				$break_enabled     = ( $break_enabled_raw === 1 )
					|| ( $break_start !== '' && $break_end !== '' );
				$valid_break       = $break_enabled
					&& preg_match( '/^\d{2}:\d{2}$/', $break_start )
					&& preg_match( '/^\d{2}:\d{2}$/', $break_end )
					&& $start < $break_start
					&& $break_start < $break_end
					&& $break_end < $end;

				if ( $valid_break ) {
					$day_ranges[]   = array(
						'start' => $start,
						'end'   => $break_start,
					);
					$day_ranges[]   = array(
						'start' => $break_end,
						'end'   => $end,
					);
					$breaks[ $day ] = array(
						'start' => $break_start,
						'end'   => $break_end,
					);
				} else {
					$day_ranges[] = array(
						'start' => $start,
						'end'   => $end,
					);
				}
				$days[ $day ]   = $day_ranges;
				$bounds[ $day ] = array(
					'start' => $start,
					'end'   => $end,
				);
			} elseif ( $enabled ) {
				++$invalid_day_count;
			}
		}
		if ( ! empty( $day_enabled_raw ) && empty( $days ) && $invalid_day_count > 0 ) {
			if ( ! empty( $existing['days'] ) && is_array( $existing['days'] ) ) {
				$days = $existing['days'];
			}
			if ( ! empty( $existing['breaks'] ) && is_array( $existing['breaks'] ) ) {
				$breaks = $existing['breaks'];
			}
			if ( ! empty( $existing['bounds'] ) && is_array( $existing['bounds'] ) ) {
				$bounds = $existing['bounds'];
			}
			self::flash_notice( esc_html__( 'No se guardaron cambios porque hay horarios inválidos. Revisa horas de inicio y término.', 'agenda-lite' ), 'error' );
		}
		$schedules[ $schedule_id ] = array(
			'id'       => $schedule_id,
			'name'     => $name !== '' ? $name : ( (string) ( $existing['name'] ?? 'Horas laborales' ) ),
			'timezone' => $timezone,
			'days'     => $days,
			'breaks'   => $breaks,
			'bounds'   => $bounds,
		);
		update_option( 'litecal_schedules', $schedules );
		if ( ! get_option( 'litecal_default_schedule' ) ) {
			update_option( 'litecal_default_schedule', $schedule_id );
		}
		if ( empty( $day_enabled_raw ) || $invalid_day_count === 0 || ! empty( $days ) ) {
			self::flash_notice( esc_html__( 'Horario guardado.', 'agenda-lite' ), 'success' );
		}
		wp_safe_redirect( Helpers::admin_url( 'availability' ) . '&schedule_id=' . $schedule_id );
		exit;
	}

	public static function new_schedule() {
		if ( ! self::can_manage() ) {
			wp_die( esc_html__( 'Unauthorized', 'agenda-lite' ) );
		}
		check_admin_referer( 'litecal_new_schedule' );
		$id               = 'schedule_' . wp_rand( 1000, 9999 );
		$schedules        = get_option( 'litecal_schedules', array() );
		$had_any          = ! empty( $schedules );
		$schedules[ $id ] = array(
			'id'       => $id,
			'name'     => 'Nuevo horario',
			'timezone' => \LiteCal\Core\Helpers::site_timezone_name(),
			'days'     => array(),
		);
		update_option( 'litecal_schedules', $schedules );
		if ( ! $had_any ) {
			update_option( 'litecal_default_schedule', $id );
		}
		wp_safe_redirect( Helpers::admin_url( 'availability' ) . '&schedule_id=' . $id );
		exit;
	}

	public static function delete_schedule() {
		if ( ! self::can_manage() ) {
			wp_die( esc_html__( 'Unauthorized', 'agenda-lite' ) );
		}
		check_admin_referer( 'litecal_delete_schedule' );
		$id        = sanitize_text_field( wp_unslash( $_POST['schedule_id'] ?? '' ) );
		$schedules = get_option( 'litecal_schedules', array() );
		if ( $id && isset( $schedules[ $id ] ) ) {
			unset( $schedules[ $id ] );
			update_option( 'litecal_schedules', $schedules );
			if ( get_option( 'litecal_default_schedule' ) === $id ) {
				$first = array_key_first( $schedules ) ?: 'default';
				update_option( 'litecal_default_schedule', $first );
			}
			self::flash_notice( esc_html__( 'Horario eliminado.', 'agenda-lite' ), 'success' );
		}
		wp_safe_redirect( Helpers::admin_url( 'availability' ) );
		exit;
	}

	public static function set_default_schedule() {
		if ( ! self::can_manage() ) {
			wp_die( esc_html__( 'Unauthorized', 'agenda-lite' ) );
		}
		check_admin_referer( 'litecal_set_default_schedule' );
		$id = sanitize_text_field( wp_unslash( $_POST['schedule_id'] ?? '' ) );
		if ( $id && ! empty( $_POST['is_default'] ) ) {
			update_option( 'litecal_default_schedule', $id );
			self::flash_notice( esc_html__( 'Horario predeterminado actualizado.', 'agenda-lite' ), 'success' );
		}
		wp_safe_redirect( Helpers::admin_url( 'availability' ) . '&schedule_id=' . $id );
		exit;
	}

	public static function currency_flag( $currency ) {
		$map = array(
			'CLP' => '🇨🇱',
			'USD' => '🇺🇸',
			'EUR' => '🇪🇺',
			'AUD' => '🇦🇺',
			'BRL' => '🇧🇷',
			'CAD' => '🇨🇦',
			'CNY' => '🇨🇳',
			'CZK' => '🇨🇿',
			'DKK' => '🇩🇰',
			'HKD' => '🇭🇰',
			'HUF' => '🇭🇺',
			'ILS' => '🇮🇱',
			'JPY' => '🇯🇵',
			'MYR' => '🇲🇾',
			'MXN' => '🇲🇽',
			'NOK' => '🇳🇴',
			'NZD' => '🇳🇿',
			'PHP' => '🇵🇭',
			'PLN' => '🇵🇱',
			'GBP' => '🇬🇧',
			'RUB' => '🇷🇺',
			'SGD' => '🇸🇬',
			'SEK' => '🇸🇪',
			'CHF' => '🇨🇭',
			'TWD' => '🇹🇼',
			'THB' => '🇹🇭',
		);
		return (string) ( $map[ strtoupper( (string) $currency ) ] ?? '' );
	}

	public static function multicurrency_enabled( $settings = null ) {
		if ( ! is_array( $settings ) ) {
			$settings = get_option( 'litecal_settings', array() );
		}
		if ( self::is_free_plan() ) {
			return false;
		}
		return ! empty( $settings['multicurrency_enabled'] );
	}

	public static function multicurrency_rates( $settings = null ) {
		if ( ! is_array( $settings ) ) {
			$settings = get_option( 'litecal_settings', array() );
		}
		$raw = $settings['multicurrency_rates'] ?? array();
		if ( ! is_array( $raw ) ) {
			$decoded = json_decode( (string) $raw, true );
			$raw     = is_array( $decoded ) ? $decoded : array();
		}
		$clean = array();
		foreach ( $raw as $code => $rate ) {
			$code  = strtoupper( sanitize_text_field( (string) $code ) );
			$value = (float) $rate;
			if ( $code !== '' && $value > 0 ) {
				$clean[ $code ] = $value;
			}
		}
		return $clean;
	}

	public static function active_payment_provider_keys( $integrations = null ) {
		if ( ! is_array( $integrations ) ) {
			$integrations = get_option( 'litecal_integrations', array() );
		}
		$map    = array(
			'flow'   => 'payments_flow',
			'webpay' => 'payments_webpay',
			'mp'     => 'payments_mp',
			'paypal' => 'payments_paypal',
			'stripe' => 'payments_stripe',
		);
		$active = array();
		foreach ( $map as $provider => $option_key ) {
			if ( ! empty( $integrations[ $option_key ] ) ) {
				if ( $provider === 'flow' && ! self::pro_feature_enabled( 'payments_flow' ) ) {
					continue;
				}
				if ( $provider === 'webpay' && ! self::pro_feature_enabled( 'payments_webpay' ) ) {
					continue;
				}
				if ( $provider === 'mp' && ! self::pro_feature_enabled( 'payments_mp' ) ) {
					continue;
				}
				if ( $provider === 'paypal' && ! self::pro_feature_enabled( 'payments_paypal' ) ) {
					continue;
				}
				if ( $provider === 'stripe' && ! self::pro_feature_enabled( 'payments_stripe' ) ) {
					continue;
				}
				$active[] = $provider;
			}
		}
		return $active;
	}

	public static function provider_base_currency( $provider ) {
		$provider = sanitize_text_field( (string) $provider );
		if ( in_array( $provider, array( 'flow', 'mp', 'webpay' ), true ) ) {
			return 'CLP';
		}
		if ( $provider === 'paypal' ) {
			return 'USD';
		}
		if ( $provider === 'stripe' ) {
			return 'USD';
		}
		return '';
	}

	public static function multicurrency_provider_rates( $settings = null ) {
		if ( ! is_array( $settings ) ) {
			$settings = get_option( 'litecal_settings', array() );
		}
		if ( self::is_free_plan() ) {
			return array();
		}
		$raw = $settings['multicurrency_provider_rates'] ?? array();
		if ( ! is_array( $raw ) ) {
			$decoded = json_decode( (string) $raw, true );
			$raw     = is_array( $decoded ) ? $decoded : array();
		}
		$clean = array();
		foreach ( $raw as $provider => $rate ) {
			$provider = sanitize_text_field( (string) $provider );
			$value    = (float) $rate;
			if ( $provider !== '' && $value > 0 ) {
				$clean[ $provider ] = $value;
			}
		}
		return $clean;
	}

	public static function payment_provider_currency( $provider, $global_currency, $settings = null ) {
		$provider        = sanitize_text_field( (string) $provider );
		$global_currency = strtoupper( (string) ( $global_currency ?: 'USD' ) );
		if ( ! is_array( $settings ) ) {
			$settings = get_option( 'litecal_settings', array() );
		}
		$multicurrency = self::multicurrency_enabled( $settings );
		if ( $provider === 'paypal' ) {
			if ( ! $multicurrency ) {
				return self::paypal_supported_currency( $global_currency ) ? $global_currency : '';
			}
			return 'USD';
		}
		if ( $provider === 'stripe' ) {
			return preg_match( '/^[A-Z]{3}$/', $global_currency ) ? $global_currency : '';
		}
		if ( in_array( $provider, array( 'flow', 'mp', 'webpay' ), true ) ) {
			if ( $multicurrency ) {
				return 'CLP';
			}
			return $global_currency === 'CLP' ? 'CLP' : '';
		}
		if ( $provider === 'transfer' ) {
			return $global_currency;
		}
		return '';
	}

	public static function convert_from_global( $amount, $global_currency, $target_currency, $settings = null ) {
		$global_currency = strtoupper( (string) ( $global_currency ?: 'USD' ) );
		$target_currency = strtoupper( (string) ( $target_currency ?: $global_currency ) );
		$amount          = (float) $amount;
		if ( $target_currency === $global_currency ) {
			return $amount;
		}
		if ( ! is_array( $settings ) ) {
			$settings = get_option( 'litecal_settings', array() );
		}
		$rates = self::multicurrency_rates( $settings );
		$rate  = (float) ( $rates[ $target_currency ] ?? 0 );
		if ( $rate <= 0 ) {
			return 0.0;
		}
		return $amount / $rate;
	}

	public static function convert_from_global_for_provider( $amount, $global_currency, $provider, $settings = null ) {
		$amount          = (float) $amount;
		$global_currency = strtoupper( (string) ( $global_currency ?: 'USD' ) );
		$provider        = sanitize_text_field( (string) $provider );
		if ( ! is_array( $settings ) ) {
			$settings = get_option( 'litecal_settings', array() );
		}
		$target_currency = self::payment_provider_currency( $provider, $global_currency, $settings );
		if ( $target_currency === '' || $target_currency === $global_currency ) {
			return $amount;
		}
		$rates = self::multicurrency_provider_rates( $settings );
		$rate  = (float) ( $rates[ $provider ] ?? 0 );
		if ( $rate <= 0 ) {
			return 0.0;
		}
		// Pair USD/CLP can be entered as: 1 USD = X CLP.
		if ( $global_currency === 'CLP' && $target_currency === 'USD' ) {
			return $amount / $rate;
		}
		if ( $global_currency === 'USD' && $target_currency === 'CLP' ) {
			return $amount * $rate;
		}
		// Generic fallback: 1 {global} = X {target}.
		return $amount * $rate;
	}

	public static function payment_methods_for_event( $global_currency, array $event_options = array(), $amount_global = null ) {
		$global_currency = strtoupper( (string) ( $global_currency ?: 'USD' ) );
		$integrations    = get_option( 'litecal_integrations', array() );
		$settings        = get_option( 'litecal_settings', array() );
		$definitions     = array(
			'flow'     => array(
				'key'   => 'flow',
				'label' => __( 'Flow', 'agenda-lite' ),
				'desc'  => __( 'Paga de manera rápida y segura con tarjetas de débito o crédito mediante FLOW.', 'agenda-lite' ),
				'logo'  => LITECAL_URL . 'assets/logos/flow.svg',
			),
			'webpay'   => array(
				'key'   => 'webpay',
				'label' => __( 'Webpay Plus', 'agenda-lite' ),
				'desc'  => __( 'Paga con débito o crédito usando Webpay Plus, la plataforma oficial de Transbank.', 'agenda-lite' ),
				'logo'  => LITECAL_URL . 'assets/logos/webpayplus.svg',
			),
			'mp'       => array(
				'key'   => 'mp',
				'label' => __( 'MercadoPago', 'agenda-lite' ),
				'desc'  => __( 'Paga con tarjetas de débito, crédito o con el saldo de tu cuenta MercadoPago.', 'agenda-lite' ),
				'logo'  => LITECAL_URL . 'assets/logos/mercadopago.svg',
			),
			'paypal'   => array(
				'key'   => 'paypal',
				'label' => __( 'PayPal', 'agenda-lite' ),
				'desc'  => __( 'Paga de forma simple y confiable con tarjeta o saldo PayPal desde cualquier lugar.', 'agenda-lite' ),
				'logo'  => LITECAL_URL . 'assets/logos/paypal.svg',
			),
			'stripe'   => array(
				'key'   => 'stripe',
				'label' => __( 'Stripe', 'agenda-lite' ),
				'desc'  => __( 'Paga de forma segura con tarjeta usando Stripe Checkout.', 'agenda-lite' ),
				'logo'  => LITECAL_URL . 'assets/logos/stripe.svg',
			),
			'transfer' => array(
				'key'   => 'transfer',
				'label' => __( 'Transferencia bancaria', 'agenda-lite' ),
				'desc'  => __( 'Tu reserva quedará Pendiente hasta que confirmemos el pago.', 'agenda-lite' ),
				'logo'  => LITECAL_URL . 'assets/logos/banco.svg',
			),
		);
		$methods         = array();
		foreach ( $definitions as $provider => $meta ) {
			if ( $provider === 'flow' && empty( $integrations['payments_flow'] ) ) {
				continue;
			}
			if ( $provider === 'flow' && ! self::pro_feature_enabled( 'payments_flow' ) ) {
				continue;
			}
			if ( $provider === 'webpay' && empty( $integrations['payments_webpay'] ) ) {
				continue;
			}
			if ( $provider === 'webpay' && ! self::pro_feature_enabled( 'payments_webpay' ) ) {
				continue;
			}
			if ( $provider === 'mp' && empty( $integrations['payments_mp'] ) ) {
				continue;
			}
			if ( $provider === 'mp' && ! self::pro_feature_enabled( 'payments_mp' ) ) {
				continue;
			}
			if ( $provider === 'paypal' && empty( $integrations['payments_paypal'] ) ) {
				continue;
			}
			if ( $provider === 'paypal' && ! self::pro_feature_enabled( 'payments_paypal' ) ) {
				continue;
			}
			if ( $provider === 'stripe' && empty( $integrations['payments_stripe'] ) ) {
				continue;
			}
			if ( $provider === 'stripe' && ! self::pro_feature_enabled( 'payments_stripe' ) ) {
				continue;
			}
			if ( $provider === 'transfer' && empty( $integrations['payments_transfer'] ) ) {
				continue;
			}
			$charge_currency = self::payment_provider_currency( $provider, $global_currency, $settings );
			if ( $charge_currency === '' ) {
				continue;
			}
			$meta['charge_currency'] = $charge_currency;
			$meta['available']       = true;
			if ( $amount_global !== null ) {
				if ( $charge_currency === $global_currency ) {
					$converted = (float) $amount_global;
				} else {
					$converted = self::convert_from_global_for_provider( (float) $amount_global, $global_currency, $provider, $settings );
				}
				if ( $converted <= 0 && $charge_currency !== $global_currency ) {
					continue;
				}
				$meta['charge_amount'] = $converted;
				if ( $provider !== 'stripe' && $converted > 0 && $charge_currency !== $global_currency ) {
					$meta['badge'] = __( 'Se cobrará:', 'agenda-lite' ) . ' ' . self::format_money_label( $converted, $charge_currency );
				}
			}
			$methods[] = $meta;
		}

		$all_methods      = $methods;
		$has_allowed_list = array_key_exists( 'payment_providers', $event_options );
		$allowed          = $event_options['payment_providers'] ?? array();
		if ( ! is_array( $allowed ) ) {
			$allowed = array_filter( array_map( 'trim', explode( ',', (string) $allowed ) ) );
		}
		if ( $has_allowed_list && empty( $allowed ) ) {
			return array();
		}
		if ( ! empty( $allowed ) ) {
			$allowed    = array_values( array_unique( array_map( 'sanitize_text_field', $allowed ) ) );
			$method_map = array();
			foreach ( $methods as $method ) {
				$method_map[ $method['key'] ] = $method;
			}
			$ordered = array();
			foreach ( $allowed as $method_key ) {
				if ( isset( $method_map[ $method_key ] ) ) {
					$ordered[] = $method_map[ $method_key ];
				}
			}
			$methods = ! empty( $ordered ) ? $ordered : $all_methods;
		}
		return $methods;
	}

	public static function event_extras_enabled() {
		return self::pro_feature_enabled( 'extras' );
	}

	public static function event_extras_config( $options, $currency = 'CLP', $allow_when_locked = false ) {
		$currency = strtoupper( (string) ( $currency ?: 'CLP' ) );
		$options  = is_array( $options ) ? $options : array();
		$enabled  = self::event_extras_enabled();
		if ( ! $enabled && ! $allow_when_locked ) {
			return array(
				'enabled'  => false,
				'currency' => $currency,
				'items'    => array(),
				'hours'    => array(
					'enabled'            => false,
					'label'              => __( 'Horas extra', 'agenda-lite' ),
					'interval_minutes'   => 15,
					'price_per_interval' => 0.0,
					'max_units'          => 8,
					'selector'           => 'select',
				),
			);
		}

		$raw_items = $options['extras_items'] ?? array();
		if ( is_string( $raw_items ) && $raw_items !== '' ) {
			$decoded_items = json_decode( $raw_items, true );
			$raw_items     = is_array( $decoded_items ) ? $decoded_items : array();
		}
		if ( ! is_array( $raw_items ) ) {
			$raw_items = array();
		}

		$items = array();
		foreach ( $raw_items as $index => $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}
			$name = trim( sanitize_text_field( (string) ( $item['name'] ?? '' ) ) );
			if ( $name === '' ) {
				continue;
			}
			$raw_price = $item['price'] ?? 0;
			$price     = is_numeric( $raw_price )
				? (float) $raw_price
				: self::parse_money( sanitize_text_field( (string) $raw_price ), $currency );
			$price = max( 0, (float) $price );
			$id    = sanitize_key( (string) ( $item['id'] ?? '' ) );
			if ( $id === '' ) {
				$id = 'extra_' . substr( md5( $name . '|' . (int) $index ), 0, 10 );
			}
			$items[] = array(
				'id'    => $id,
				'name'  => $name,
				'price' => round( $price, 2 ),
				'image' => esc_url_raw( (string) ( $item['image'] ?? '' ) ),
			);
			if ( count( $items ) >= 30 ) {
				break;
			}
		}

		$raw_hours = $options['extras_hours'] ?? array();
		if ( is_string( $raw_hours ) && $raw_hours !== '' ) {
			$decoded_hours = json_decode( $raw_hours, true );
			$raw_hours     = is_array( $decoded_hours ) ? $decoded_hours : array();
		}
		if ( ! is_array( $raw_hours ) ) {
			$raw_hours = array();
		}
		$hours_selector = sanitize_key( (string) ( $raw_hours['selector'] ?? 'select' ) );
		if ( ! in_array( $hours_selector, array( 'select', 'stepper' ), true ) ) {
			$hours_selector = 'select';
		}
		$hours_interval = max( 5, min( 240, (int) ( $raw_hours['interval_minutes'] ?? 15 ) ) );
		$hours_price    = $raw_hours['price_per_interval'] ?? 0;
		if ( ! is_numeric( $hours_price ) ) {
			$hours_price = self::parse_money( sanitize_text_field( (string) $hours_price ), $currency );
		}
		$hours_price = max( 0, (float) $hours_price );

		return array(
			'enabled'  => (bool) $enabled,
			'currency' => $currency,
			'items'    => $items,
			'hours'    => array(
				'enabled'            => ! empty( $raw_hours['enabled'] ),
				'label'              => sanitize_text_field( (string) ( $raw_hours['label'] ?? __( 'Horas extra', 'agenda-lite' ) ) ),
				'interval_minutes'   => $hours_interval,
				'price_per_interval' => round( $hours_price, 2 ),
				'max_units'          => max( 1, min( 96, (int) ( $raw_hours['max_units'] ?? 8 ) ) ),
				'selector'           => $hours_selector,
			),
		);
	}

	public static function currency_meta( $currency ) {
		$currency = strtoupper( $currency ?: 'CLP' );
		$map      = array(
			'CLP' => array(
				'symbol'   => '$',
				'decimal'  => ',',
				'thousand' => '.',
				'example'  => '10.000',
			),
			'USD' => array(
				'symbol'   => '$',
				'decimal'  => '.',
				'thousand' => ',',
				'example'  => '100.00',
			),
			'EUR' => array(
				'symbol'   => '€',
				'decimal'  => ',',
				'thousand' => '.',
				'example'  => '100,00',
			),
			'AUD' => array(
				'symbol'   => '$',
				'decimal'  => '.',
				'thousand' => ',',
				'example'  => '100.00',
			),
			'BRL' => array(
				'symbol'   => 'R$',
				'decimal'  => ',',
				'thousand' => '.',
				'example'  => '100,00',
			),
			'CAD' => array(
				'symbol'   => '$',
				'decimal'  => '.',
				'thousand' => ',',
				'example'  => '100.00',
			),
			'CNY' => array(
				'symbol'   => '¥',
				'decimal'  => '.',
				'thousand' => ',',
				'example'  => '100.00',
			),
			'CZK' => array(
				'symbol'   => 'Kč',
				'decimal'  => ',',
				'thousand' => '.',
				'example'  => '100,00',
			),
			'DKK' => array(
				'symbol'   => 'kr',
				'decimal'  => ',',
				'thousand' => '.',
				'example'  => '100,00',
			),
			'HKD' => array(
				'symbol'   => 'HK$',
				'decimal'  => '.',
				'thousand' => ',',
				'example'  => '100.00',
			),
			'HUF' => array(
				'symbol'   => 'Ft',
				'decimal'  => '',
				'thousand' => '.',
				'example'  => '100',
			),
			'ILS' => array(
				'symbol'   => '₪',
				'decimal'  => '.',
				'thousand' => ',',
				'example'  => '100.00',
			),
			'JPY' => array(
				'symbol'   => '¥',
				'decimal'  => '',
				'thousand' => ',',
				'example'  => '100',
			),
			'MYR' => array(
				'symbol'   => 'RM',
				'decimal'  => '.',
				'thousand' => ',',
				'example'  => '100.00',
			),
			'MXN' => array(
				'symbol'   => '$',
				'decimal'  => '.',
				'thousand' => ',',
				'example'  => '100.00',
			),
			'NOK' => array(
				'symbol'   => 'kr',
				'decimal'  => ',',
				'thousand' => '.',
				'example'  => '100,00',
			),
			'NZD' => array(
				'symbol'   => '$',
				'decimal'  => '.',
				'thousand' => ',',
				'example'  => '100.00',
			),
			'PHP' => array(
				'symbol'   => '₱',
				'decimal'  => '.',
				'thousand' => ',',
				'example'  => '100.00',
			),
			'PLN' => array(
				'symbol'   => 'zł',
				'decimal'  => ',',
				'thousand' => '.',
				'example'  => '100,00',
			),
			'GBP' => array(
				'symbol'   => '£',
				'decimal'  => '.',
				'thousand' => ',',
				'example'  => '100.00',
			),
			'RUB' => array(
				'symbol'   => '₽',
				'decimal'  => '.',
				'thousand' => ',',
				'example'  => '100.00',
			),
			'SGD' => array(
				'symbol'   => '$',
				'decimal'  => '.',
				'thousand' => ',',
				'example'  => '100.00',
			),
			'SEK' => array(
				'symbol'   => 'kr',
				'decimal'  => ',',
				'thousand' => '.',
				'example'  => '100,00',
			),
			'CHF' => array(
				'symbol'   => 'CHF',
				'decimal'  => '.',
				'thousand' => ',',
				'example'  => '100.00',
			),
			'TWD' => array(
				'symbol'   => 'NT$',
				'decimal'  => '',
				'thousand' => ',',
				'example'  => '100',
			),
			'THB' => array(
				'symbol'   => '฿',
				'decimal'  => '.',
				'thousand' => ',',
				'example'  => '100.00',
			),
		);
		return $map[ $currency ] ?? $map['CLP'];
	}

	public static function parse_money( $value, $currency ) {
		$meta  = self::currency_meta( $currency );
		$value = trim( (string) $value );
		if ( $value === '' ) {
			return 0.0;
		}
		$value = preg_replace( '/[^0-9\\' . preg_quote( $meta['decimal'], '/' ) . '\\' . preg_quote( $meta['thousand'], '/' ) . ']/', '', $value );
		if ( $meta['thousand'] !== '' ) {
			$value = str_replace( $meta['thousand'], '', $value );
		}
		if ( $meta['decimal'] !== '.' ) {
			$value = str_replace( $meta['decimal'], '.', $value );
		}
		return (float) $value;
	}

	private static function is_virtual_location( $location ) {
		$location = sanitize_key( (string) $location );
		return in_array( $location, array( 'google_meet', 'zoom', 'teams', 'online', 'virtual' ), true );
	}

	public static function format_money( $value, $currency ) {
		$meta          = self::currency_meta( $currency );
		$zero_decimals = array( 'CLP', 'JPY', 'HUF', 'TWD' );
		$decimals      = in_array( strtoupper( $currency ), $zero_decimals, true ) ? 0 : 2;
		return number_format( (float) $value, $decimals, $meta['decimal'], $meta['thousand'] );
	}

	public static function format_money_label( $value, $currency ) {
		$currency  = strtoupper( $currency ?: 'CLP' );
		$meta      = self::currency_meta( $currency );
		$formatted = self::format_money( $value, $currency );
		return $meta['symbol'] . $formatted . ' ' . $currency;
	}

	public static function paypal_supported_currency( $currency ) {
		$currency  = strtoupper( $currency ?: 'USD' );
		$supported = array( 'AUD', 'BRL', 'CAD', 'CNY', 'CZK', 'DKK', 'EUR', 'HKD', 'HUF', 'ILS', 'JPY', 'MYR', 'MXN', 'NOK', 'NZD', 'PHP', 'PLN', 'GBP', 'RUB', 'SGD', 'SEK', 'CHF', 'TWD', 'THB', 'USD' );
		return in_array( $currency, $supported, true );
	}
	public static function admin_menu_css() {
		echo '<style>
        #adminmenu .wp-menu-image img {
            padding: 5px 0 0 !important;
            opacity: 1 !important;
            width: 24px !important;
            height: 24px !important;
            display: block;
            margin: 0 auto;
        }
        #adminmenu .wp-not-current-submenu .wp-menu-image img {
            opacity: 1 !important;
            filter: none !important;
        }
        #adminmenu .wp-has-current-submenu .wp-menu-image img,
        #adminmenu .wp-menu-open .wp-menu-image img {
            padding: 0 !important;
        }
        </style>';
	}
}
