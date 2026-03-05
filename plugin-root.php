<?php
/**
 * Plugin Name: Agenda Lite
 * Plugin URI: https://www.agendalite.com
 * Description: Plataforma profesional de reservas online para WordPress: agenda, pagos, automatizaciones e integraciones.
 * Version: 1.0.0
 * Author: Hostnauta
 * Author URI: https://www.hostnauta.com
 * Text Domain: agenda-lite
 * License: GPLv2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 *
 * @package LiteCal
 */

// phpcs:disable Squiz.Commenting,WordPress.NamingConventions.ValidVariableName -- Project style preference: keep concise docs and legacy variable names without functional impact.

// phpcs:disable WordPress.PHP.YodaConditions.NotYoda,Universal.Operators.DisallowShortTernary.Found -- Project style preference; no functional impact.

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'LITECAL_VERSION', '1.0.0' );
define( 'LITECAL_PATH', plugin_dir_path( __FILE__ ) );
define( 'LITECAL_URL', plugin_dir_url( __FILE__ ) );
define( 'LITECAL_TEXTDOMAIN', 'agenda-lite' );
define( 'LITECAL_BOOKING_MANAGER_ROLE', 'litecal_booking_manager' );
define( 'LITECAL_IS_FREE', true );

litecal_autoload();

function litecal_autoload() {
	spl_autoload_register(
		function ( $class ) {
			if ( strpos( $class, 'LiteCal\\' ) !== 0 ) {
				return;
			}
			$relative_path = str_replace( 'LiteCal\\', '', $class );
			$relative_path = str_replace( '\\', '/', $relative_path );
			$parts         = explode( '/', $relative_path );
			$class_name    = array_pop( $parts );
			$folder        = implode( '/', $parts );
			$paths         = array(
				LITECAL_PATH . 'includes/' . ( $folder ? $folder . '/' : '' ) . 'class-' . strtolower( (string) $class_name ) . '.php',
				LITECAL_PATH . 'includes/' . $relative_path . '.php',
			);
			foreach ( $paths as $path ) {
				if ( file_exists( $path ) ) {
					require_once $path;
					return;
				}
			}
		}
	);
}

function litecal_pro_is_active() {
	return litecal_pro_runtime_active();
}

function litecal_pro_feature_enabled( $feature ) {
	$feature = sanitize_key( (string) $feature );
	if ( $feature === '' ) {
		return false;
	}
	if ( ! litecal_pro_runtime_active() ) {
		return false;
	}
	if ( litecal_pro_has_trusted_runtime_feature_callback() ) {
		try {
			return (bool) litecal_pro_runtime_feature_enabled( $feature );
		} catch ( \Throwable $e ) {
			return false;
		}
	}
	if ( litecal_pro_has_trusted_license_manager() ) {
		try {
			return (bool) \LiteCalPro\Licensing\LicenseManager::filter_feature_enabled( false, $feature );
		} catch ( \Throwable $e ) {
			return false;
		}
	}
	return false;
}

function litecal_pro_upgrade_url() {
	$default = 'https://www.agendalite.com/';
	$url     = (string) apply_filters( 'litecal_pro_upgrade_url', $default );
	if ( $url === '' ) {
		$url = $default;
	}
	return esc_url_raw( $url );
}

function litecal_register_premium_fallbacks() {
	if ( litecal_pro_is_plugin_really_active() && defined( 'LITECAL_PRO_FILE' ) ) {
		return;
	}
	$fallback_file = LITECAL_PATH . 'includes/Compatibility/class-premiumfallback.php';
	if ( ! file_exists( $fallback_file ) ) {
		return;
	}
	require_once $fallback_file;
	if ( class_exists( '\\LiteCal\\Compatibility\\PremiumFallback' ) ) {
		\LiteCal\Compatibility\PremiumFallback::register_aliases();
	}
}

function litecal_pro_runtime_active() {
	static $cached = null;
	if ( $cached !== null && did_action( 'plugins_loaded' ) ) {
		return $cached;
	}

	$is_active = false;
	$pro_file = trailingslashit( WP_PLUGIN_DIR ) . 'agenda-lite-pro/agenda-lite-pro.php';
	if ( ! file_exists( $pro_file ) || ! litecal_pro_is_plugin_really_active() ) {
		if ( did_action( 'plugins_loaded' ) ) {
			$cached = false;
		}
		return false;
	}
	if ( ! defined( 'LITECAL_PRO_FILE' ) || ! defined( 'LITECAL_PRO_VERSION' ) ) {
		if ( did_action( 'plugins_loaded' ) ) {
			$cached = false;
		}
		return false;
	}

	$expected = realpath( $pro_file );
	$loaded   = realpath( (string) LITECAL_PRO_FILE );
	if ( ! $expected || ! $loaded ) {
		if ( did_action( 'plugins_loaded' ) ) {
			$cached = false;
		}
		return false;
	}
	if ( wp_normalize_path( $expected ) !== wp_normalize_path( $loaded ) ) {
		if ( did_action( 'plugins_loaded' ) ) {
			$cached = false;
		}
		return false;
	}
	if ( ! litecal_pro_has_trusted_license_manager() ) {
		if ( did_action( 'plugins_loaded' ) ) {
			$cached = false;
		}
		return false;
	}

	try {
		$is_active = (bool) \LiteCalPro\Licensing\LicenseManager::is_license_valid();
	} catch ( \Throwable $e ) {
		$is_active = false;
	}

	if ( did_action( 'plugins_loaded' ) ) {
		$cached = $is_active;
	}
	return $is_active;
}

function litecal_pro_is_plugin_really_active() {
	$basename = 'agenda-lite-pro/agenda-lite-pro.php';
	$active   = (array) get_option( 'active_plugins', array() );
	if ( in_array( $basename, $active, true ) ) {
		return true;
	}
	if ( ! is_multisite() ) {
		return false;
	}
	$network = get_site_option( 'active_sitewide_plugins', array() );
	if ( ! is_array( $network ) ) {
		return false;
	}
	return isset( $network[ $basename ] );
}

function litecal_pro_is_trusted_path( $path ) {
	if ( ! defined( 'LITECAL_PRO_PATH' ) ) {
		return false;
	}
	$root      = realpath( (string) LITECAL_PRO_PATH );
	$candidate = realpath( (string) $path );
	if ( ! $root || ! $candidate ) {
		return false;
	}
	$root      = trailingslashit( wp_normalize_path( $root ) );
	$candidate = wp_normalize_path( $candidate );
	return strpos( $candidate, $root ) === 0;
}

function litecal_pro_has_trusted_license_manager() {
	if ( ! class_exists( '\\LiteCalPro\\Licensing\\LicenseManager' ) ) {
		return false;
	}
	try {
		$reflection = new \ReflectionClass( '\\LiteCalPro\\Licensing\\LicenseManager' );
	} catch ( \ReflectionException $e ) {
		return false;
	}
	$file = $reflection->getFileName();
	if ( ! litecal_pro_is_trusted_path( $file ) ) {
		return false;
	}
	if ( ! $reflection->hasMethod( 'is_license_valid' ) ) {
		return false;
	}
	$method = $reflection->getMethod( 'is_license_valid' );
	return $method->isStatic();
}

function litecal_pro_has_trusted_runtime_feature_callback() {
	if ( ! function_exists( 'litecal_pro_runtime_feature_enabled' ) ) {
		return false;
	}
	try {
		$reflection = new \ReflectionFunction( 'litecal_pro_runtime_feature_enabled' );
	} catch ( \ReflectionException $e ) {
		return false;
	}
	return litecal_pro_is_trusted_path( $reflection->getFileName() );
}

function litecal_grant_caps() {
	litecal_sync_caps();
}

function litecal_default_role_caps() {
	return array( 'administrator' );
}

function litecal_allowed_role_caps() {
	$roles        = litecal_default_role_caps();
	$booking_role = defined( 'LITECAL_BOOKING_MANAGER_ROLE' ) ? sanitize_key( (string) LITECAL_BOOKING_MANAGER_ROLE ) : 'litecal_booking_manager';
	$valid_roles  = array();
	if ( function_exists( 'wp_roles' ) && wp_roles() instanceof \WP_Roles ) {
		$valid_roles = array_keys( (array) wp_roles()->roles );
	}
	$roles = array_values(
		array_filter(
			array_map( 'sanitize_key', (array) $roles ),
			static function ( $slug ) use ( $valid_roles, $booking_role ) {
				if ( $slug === '' ) {
					return false;
				}
				if ( $slug === $booking_role ) {
					return false;
				}
				if ( empty( $valid_roles ) ) {
					return true;
				}
				return in_array( $slug, $valid_roles, true );
			}
		)
	);
	if ( ! in_array( 'administrator', $roles, true ) ) {
		$roles[] = 'administrator';
	}
	return array_values( array_unique( $roles ) );
}

function litecal_sync_caps( $allowed_roles = null ) {
	$allowed_roles = is_array( $allowed_roles ) ? $allowed_roles : litecal_allowed_role_caps();
	$booking_role  = defined( 'LITECAL_BOOKING_MANAGER_ROLE' ) ? sanitize_key( (string) LITECAL_BOOKING_MANAGER_ROLE ) : 'litecal_booking_manager';
	if ( ! in_array( 'administrator', $allowed_roles, true ) ) {
		$allowed_roles[] = 'administrator';
	}
	$all_roles = array();
	if ( function_exists( 'wp_roles' ) && wp_roles() instanceof \WP_Roles ) {
		$all_roles = array_keys( (array) wp_roles()->roles );
	} else {
		$all_roles = litecal_default_role_caps();
	}
	foreach ( $all_roles as $slug ) {
		$role = get_role( $slug );
		if ( ! $role ) {
			continue;
		}
		if ( $slug === $booking_role ) {
			if ( $role->has_cap( 'manage_agendalite' ) ) {
				$role->remove_cap( 'manage_agendalite' );
			}
			continue;
		}
		if ( in_array( $slug, $allowed_roles, true ) ) {
			if ( ! $role->has_cap( 'manage_agendalite' ) ) {
				$role->add_cap( 'manage_agendalite' );
			}
		} elseif ( $role->has_cap( 'manage_agendalite' ) ) {
			$role->remove_cap( 'manage_agendalite' );
		}
	}
}

function litecal_revoke_caps() {
	$all_roles = array();
	if ( function_exists( 'wp_roles' ) && wp_roles() instanceof \WP_Roles ) {
		$all_roles = array_keys( (array) wp_roles()->roles );
	} else {
		$all_roles = litecal_default_role_caps();
	}
	foreach ( $all_roles as $slug ) {
		$role = get_role( $slug );
		if ( $role && $role->has_cap( 'manage_agendalite' ) ) {
			$role->remove_cap( 'manage_agendalite' );
		}
	}
}

function litecal_pro_plugin_installed() {
	$pro_file = trailingslashit( WP_PLUGIN_DIR ) . 'agenda-lite-pro/agenda-lite-pro.php';
	return file_exists( $pro_file );
}

function litecal_add_free_plugin_action_links( $links ) {
	if ( litecal_pro_plugin_installed() ) {
		return $links;
	}
	$url     = esc_url( 'https://www.agendalite.com/pro' );
	$title   = esc_attr__( 'Desbloquea módulos premium (licencias, pagos avanzados, video y sincronización)', 'agenda-lite' );
	$label   = esc_html__( 'Mejorar a Pro', 'agenda-lite' );
	$links[] = '<a class="lc-plugin-row-pro-link" href="' . $url . '" target="_blank" rel="noopener noreferrer" title="' . $title . '"><span class="lc-plugin-row-pro-label"><i class="ri-vip-crown-line" aria-hidden="true"></i><span>' . $label . '</span></span></a>';
	return $links;
}

function litecal_enqueue_plugin_row_pro_assets( $hook_suffix ) {
	if ( $hook_suffix !== 'plugins.php' ) {
		return;
	}
	if ( litecal_pro_plugin_installed() ) {
		return;
	}
	wp_enqueue_style(
		'litecal-remixicon-admin',
		LITECAL_URL . 'assets/vendor/remixicon/remixicon.css',
		array(),
		LITECAL_VERSION
	);
	wp_add_inline_style(
		'litecal-remixicon-admin',
		'.lc-plugin-row-pro-label{display:inline-flex;align-items:center;gap:6px;font-weight:600}.lc-plugin-row-pro-label .ri-vip-crown-line{font-size:15px;line-height:1;color:#c07a00}'
	);
}

function litecal_activate( $network_wide = false ) {
	$activate_blog = static function () {
		litecal_register_booking_manager_role();
		\LiteCal\Core\DB::activate();
		litecal_grant_caps();
	};
	if ( is_multisite() && $network_wide ) {
		$site_ids = get_sites( array( 'fields' => 'ids' ) );
		foreach ( $site_ids as $site_id ) {
			switch_to_blog( (int) $site_id );
			$activate_blog();
			restore_current_blog();
		}
		return;
	}
	$activate_blog();
}

function litecal_deactivate( $network_wide = false ) {
	$deactivate_blog = static function () {
		\LiteCal\Core\DB::deactivate();
		$settings = get_option( 'litecal_settings', array() );
		if ( ! empty( $settings['revoke_caps_on_deactivate'] ) ) {
			litecal_revoke_caps();
		}
	};
	if ( is_multisite() && $network_wide ) {
		$site_ids = get_sites( array( 'fields' => 'ids' ) );
		foreach ( $site_ids as $site_id ) {
			switch_to_blog( (int) $site_id );
			$deactivate_blog();
			restore_current_blog();
		}
		return;
	}
	$deactivate_blog();
}

register_activation_hook( __FILE__, 'litecal_activate' );
register_deactivation_hook( __FILE__, 'litecal_deactivate' );

function litecal_register_booking_manager_role() {
	$role_key = defined( 'LITECAL_BOOKING_MANAGER_ROLE' ) ? LITECAL_BOOKING_MANAGER_ROLE : 'litecal_booking_manager';
	$caps     = array(
		'read'         => true,
		'upload_files' => true,
		'edit_posts'   => true,
	);
	if ( ! get_role( $role_key ) ) {
		add_role( $role_key, __( 'Gestor de reservas', 'agenda-lite' ), $caps );
	} else {
		$role = get_role( $role_key );
		if ( $role ) {
			foreach ( $caps as $cap => $grant ) {
				if ( $grant && ! $role->has_cap( $cap ) ) {
					$role->add_cap( $cap );
				}
			}
		}
	}
}

add_action(
	'plugins_loaded',
	function () {
		litecal_register_booking_manager_role();
		litecal_grant_caps();
		LiteCal\Core\Plugin::get_instance();
	}
);
add_action( 'plugins_loaded', 'litecal_register_premium_fallbacks', 1 );
add_filter( 'plugin_action_links_' . plugin_basename( __FILE__ ), 'litecal_add_free_plugin_action_links' );
add_action( 'admin_enqueue_scripts', 'litecal_enqueue_plugin_row_pro_assets' );
