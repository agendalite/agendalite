<?php
/**
 * REST API routes and handlers for Agenda Lite.
 *
 * @package LiteCal
 */

namespace LiteCal\Rest;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// phpcs:disable Squiz.Commenting,WordPress.NamingConventions.ValidVariableName -- Project style preference: keep concise docs and legacy variable names without functional impact.

// phpcs:disable WordPress.PHP.YodaConditions.NotYoda,Universal.Operators.DisallowShortTernary.Found -- Project style preference; no functional impact.
// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Custom Agenda Lite tables via $wpdb->prefix.
// phpcs:disable WordPress.WP.AlternativeFunctions.file_system_operations_readfile -- Controlled private-file download response.

use LiteCal\Core\Events;
use LiteCal\Core\Employees;
use LiteCal\Core\Availability;
use LiteCal\Core\Bookings;
use LiteCal\Core\Helpers;
use LiteCal\Modules\Payments\Flow\FlowProvider;
use LiteCal\Modules\Payments\PayPal\PayPalProvider;
use LiteCal\Modules\Payments\MercadoPago\MercadoPagoProvider;
use LiteCal\Modules\Payments\Stripe\StripeProvider;
use LiteCal\Modules\Payments\WebpayPlus\WebpayPlusProvider;

class Rest {

	private const BOOKING_TOKEN_TTL       = 259200; // 72 hours
	private const BOOKING_ABUSE_CODE_TTL  = 900; // 15 minutes
	private const BOOKING_ABUSE_LINK_TTL  = 2592000; // 30 days
	private const MAX_GUESTS_PER_BOOKING  = 10;
	private static $booking_token_runtime = array();
	private static function debug_enabled() {
		if ( defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG ) {
			return true;
		}
		$settings = get_option( 'litecal_settings', array() );
		return ! empty( $settings['debug'] );
	}

	private static function debug_log( $message, array $context = array() ) {
		if ( ! self::debug_enabled() ) {
			return;
		}
		$clean = array();
		foreach ( $context as $key => $value ) {
			if ( is_array( $value ) || is_object( $value ) ) {
				continue;
			}
			$key = sanitize_key( (string) $key );
			if ( $key === '' ) {
				continue;
			}
			$clean[ $key ] = sanitize_text_field( (string) $value );
		}
		$line = '[AgendaLite][Rest] ' . sanitize_text_field( (string) $message );
		if ( $clean ) {
			$line .= ' ' . wp_json_encode( $clean );
		}
			error_log( $line ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Debug logging is opt-in via plugin debug setting / WP_DEBUG_LOG.
	}

	private static function wp_datetime_to_ts( $value ) {
		if ( empty( $value ) ) {
			return 0;
		}
		$tz = wp_timezone();
		$dt = \DateTimeImmutable::createFromFormat( 'Y-m-d H:i:s', $value, $tz );
		if ( $dt instanceof \DateTimeImmutable ) {
			return (int) $dt->getTimestamp();
		}
		$fallback = strtotime( $value );
		return $fallback ? (int) $fallback : 0;
	}

	private static function current_ts() {
		return (int) current_datetime()->getTimestamp();
	}

	private static function rest_error( $code, $message, $data = array() ) {
		$translated     = is_string( $message ) ? sanitize_text_field( $message ) : '';
		$http_status    = isset( $data['status'] ) ? (int) $data['status'] : 400;
		$data['status'] = $http_status;
		$data['error']  = array(
			'code'    => sanitize_key( (string) $code ),
			'message' => $translated,
			'status'  => $http_status,
		);
		return new \WP_Error( $code, $translated, $data );
	}

	private static function rest_response( $code, $message, $status = 'ok', array $extra = array() ) {
		$payload = array(
			'code'    => sanitize_key( (string) $code ),
			'message' => is_string( $message ) ? sanitize_text_field( $message ) : '',
			'status'  => (string) $status,
		);
		foreach ( $extra as $key => $value ) {
			if ( $key === 'code' || $key === 'message' ) {
				continue;
			}
			$payload[ $key ] = $value;
		}
		return $payload;
	}

	private static function webhook_response( $status, array $extra = array() ) {
		$status = (string) $status;
		$map    = array(
			'ok'                    => array(
				'code'    => 'ok',
				'message' => 'Webhook procesado.',
			),
			'ignored'               => array(
				'code'    => 'ignored',
				'message' => 'Webhook ignorado.',
			),
			'verification_failed'   => array(
				'code'    => 'verification_failed',
				'message' => 'Firma de webhook inválida.',
			),
			'missing_token'         => array(
				'code'    => 'missing_token',
				'message' => 'Falta token en webhook.',
			),
			'invalid'               => array(
				'code'    => 'invalid',
				'message' => 'Payload inválido.',
			),
			'booking_not_found'     => array(
				'code'    => 'booking_not_found',
				'message' => 'Reserva no encontrada.',
			),
			'missing_payment_id'    => array(
				'code'    => 'missing_payment_id',
				'message' => 'Falta el id de pago.',
			),
			'payment_lookup_failed' => array(
				'code'    => 'payment_lookup_failed',
				'message' => 'No se pudo consultar el pago.',
			),
			'reference_mismatch'    => array(
				'code'    => 'reference_mismatch',
				'message' => 'Referencia de pago no coincide.',
			),
			'amount_mismatch'       => array(
				'code'    => 'amount_mismatch',
				'message' => 'Monto de pago no coincide.',
			),
			'locked'                => array(
				'code'    => 'locked',
				'message' => 'Recurso temporalmente bloqueado.',
			),
			'invalid_payload'       => array(
				'code'    => 'invalid_payload',
				'message' => 'Payload inválido.',
			),
		);
		$base   = $map[ $status ] ?? array(
			'code'    => sanitize_key( $status ),
			'message' => 'Estado webhook.',
		);
		return self::rest_response( $base['code'], $base['message'], $status, $extra );
	}

	private static function can_manage_bookings() {
		return current_user_can( 'manage_options' ) || current_user_can( 'manage_agendalite' );
	}

	private static function provider_feature_enabled( $provider ) {
		$provider    = sanitize_key( (string) $provider );
		$feature_map = array(
			'flow'   => 'payments_flow',
			'mp'     => 'payments_mp',
			'webpay' => 'payments_webpay',
			'paypal' => 'payments_paypal',
			'stripe' => 'payments_stripe',
		);
		$feature     = (string) ( $feature_map[ $provider ] ?? '' );
		if ( $feature === '' ) {
			return true;
		}
		if ( ! function_exists( 'litecal_pro_feature_enabled' ) ) {
			return false;
		}
		return (bool) litecal_pro_feature_enabled( $feature );
	}

	private static function free_plan_restrictions_enabled() {
		if ( ! defined( 'LITECAL_IS_FREE' ) || ! LITECAL_IS_FREE ) {
			return false;
		}
		if ( function_exists( 'litecal_pro_is_active' ) && litecal_pro_is_active() ) {
			return false;
		}
		return true;
	}

	public static function booking_access_token( $booking ) {
		if ( is_numeric( $booking ) ) {
			$booking = Bookings::get( (int) $booking );
		}
		if ( ! $booking ) {
			return '';
		}
		$booking_id = (int) ( $booking->id ?? 0 );
		if ( $booking_id <= 0 ) {
			return '';
		}
		if ( isset( self::$booking_token_runtime[ $booking_id ] ) ) {
			return (string) self::$booking_token_runtime[ $booking_id ];
		}
		if ( Bookings::has_token_hash_column() ) {
			// Rotate token when only hash exists (non-recoverable by design).
			$created_at = current_time( 'mysql' );
			$token      = self::generate_booking_access_token();
			$hash       = hash_hmac( 'sha256', $token, wp_salt( 'litecal_booking_token' ) );
			Bookings::store_token_hash( $booking_id, $hash, $created_at );
			self::$booking_token_runtime[ $booking_id ] = $token;
			return $token;
		}
		// Legacy fallback for installs without booking_token_hash columns.
		return self::legacy_booking_access_token( $booking );
	}

	private static function legacy_booking_access_token( $booking ) {
		$seed = implode(
			'|',
			array(
				(int) ( $booking->id ?? 0 ),
				(int) ( $booking->event_id ?? 0 ),
				(string) ( $booking->email ?? '' ),
				(string) ( $booking->start_datetime ?? '' ),
				(string) ( $booking->created_at ?? '' ),
			)
		);
		return hash_hmac( 'sha256', $seed, wp_salt( 'litecal_booking' ) );
	}

	private static function generate_booking_access_token() {
		try {
			return rtrim( strtr( base64_encode( random_bytes( 32 ) ), '+/', '-_' ), '=' );
		} catch ( \Throwable $e ) {
			return wp_generate_password( 64, false, false );
		}
	}

	private static function assert_booking_access( $request, $booking ) {
		if ( self::can_manage_bookings() ) {
			return true;
		}
		if ( self::booking_token_expired( $booking ) ) {
			return self::rest_error( 'forbidden', 'Token expirado', array( 'status' => 403 ) );
		}
		$booking_token = sanitize_text_field( (string) $request->get_param( 'booking_token' ) );
		if ( empty( $booking_token ) ) {
			return self::rest_error( 'forbidden', 'No autorizado', array( 'status' => 403 ) );
		}
		if ( ! self::booking_token_matches( $booking, $booking_token ) ) {
			return self::rest_error( 'forbidden', 'Token inválido', array( 'status' => 403 ) );
		}
		$booking_id = (int) ( $booking->id ?? 0 );
		if ( $booking_id > 0 ) {
			self::$booking_token_runtime[ $booking_id ] = $booking_token;
		}
		return true;
	}

	public static function booking_token_is_valid( $booking, $booking_token ) {
		if ( is_numeric( $booking ) ) {
			$booking = Bookings::get( (int) $booking );
		}
		if ( ! $booking ) {
			return false;
		}
		if ( self::booking_token_expired( $booking ) ) {
			return false;
		}
		$booking_token = sanitize_text_field( (string) $booking_token );
		if ( $booking_token === '' ) {
			return false;
		}
		return self::booking_token_matches( $booking, $booking_token );
	}

	private static function booking_token_matches( $booking, $booking_token ) {
		if ( ! $booking ) {
			return false;
		}
		$booking_token = sanitize_text_field( (string) $booking_token );
		if ( $booking_token === '' ) {
			return false;
		}
		if ( Bookings::has_token_hash_column() ) {
			if ( ! empty( $booking->booking_token_hash ) ) {
				$incoming_hash = hash_hmac( 'sha256', $booking_token, wp_salt( 'litecal_booking_token' ) );
				return hash_equals( (string) $booking->booking_token_hash, $incoming_hash );
			}
			$expected_legacy = self::legacy_booking_access_token( $booking );
			if ( empty( $expected_legacy ) || ! hash_equals( $expected_legacy, $booking_token ) ) {
				return false;
			}
			// One-time upgrade path for legacy bookings without token hash.
			$incoming_hash = hash_hmac( 'sha256', $booking_token, wp_salt( 'litecal_booking_token' ) );
			$created_at    = $booking->booking_token_created_at ?: ( $booking->created_at ?? current_time( 'mysql' ) );
			Bookings::store_token_hash( (int) ( $booking->id ?? 0 ), $incoming_hash, $created_at );
			return true;
		}
		$expected_token = self::legacy_booking_access_token( $booking );
		return ! empty( $expected_token ) && hash_equals( $expected_token, $booking_token );
	}

	private static function booking_token_expired( $booking ) {
		if ( ! $booking ) {
			return true;
		}
		$base = $booking->booking_token_created_at ?: ( $booking->payment_pending_at ?: $booking->created_at );
		$ts   = self::wp_datetime_to_ts( $base );
		if ( $ts <= 0 ) {
			return true;
		}
		return ( self::current_ts() - $ts ) > self::BOOKING_TOKEN_TTL;
	}

	private static function normalize_guests( $value ) {
		if ( is_string( $value ) ) {
			$list = array_filter( array_map( 'trim', explode( ',', $value ) ) );
			return array_slice( array_values( $list ), 0, self::MAX_GUESTS_PER_BOOKING );
		}
		if ( is_array( $value ) ) {
			return array_slice( array_values( $value ), 0, self::MAX_GUESTS_PER_BOOKING );
		}
		return array();
	}

	private static function normalize_files( $file ) {
		if ( empty( $file ) || ! isset( $file['name'] ) ) {
			return array();
		}
		if ( ! is_array( $file['name'] ) ) {
			return array( $file );
		}
		$count = count( $file['name'] );
		$files = array();
		for ( $i = 0; $i < $count; $i++ ) {
			$files[] = array(
				'name'     => $file['name'][ $i ] ?? '',
				'type'     => $file['type'][ $i ] ?? '',
				'tmp_name' => $file['tmp_name'][ $i ] ?? '',
				'error'    => $file['error'][ $i ] ?? 0,
				'size'     => $file['size'][ $i ] ?? 0,
			);
		}
		return array_values(
			array_filter(
				$files,
				function ( $item ) {
					return ! empty( $item['name'] );
				}
			)
		);
	}

	private static function verify_recaptcha( $token ) {
		$integrations = get_option( 'litecal_integrations', array() );
		if ( empty( $integrations['recaptcha_enabled'] ) ) {
			return true;
		}
		$secret = trim( (string) ( $integrations['recaptcha_secret_key'] ?? '' ) );
		if ( $secret === '' || $token === '' ) {
			return false;
		}
		$response = wp_remote_post(
			'https://www.google.com/recaptcha/api/siteverify',
			array(
				'timeout' => 10,
				'body'    => array(
					'secret'   => $secret,
					'response' => $token,
					'remoteip' => self::client_ip(),
				),
			)
		);
		if ( is_wp_error( $response ) ) {
			return false;
		}
		$payload = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( empty( $payload['success'] ) ) {
			return false;
		}
		if ( isset( $payload['score'] ) && (float) $payload['score'] < 0.3 ) {
			return false;
		}
		return true;
	}

	private static function transfer_details_from_options( $options, $fallback_currency = 'CLP' ) {
		if ( ! is_array( $options ) ) {
			$options = array();
		}
		$currency = strtoupper( (string) ( $options['transfer_currency'] ?? $fallback_currency ) );
		if ( $currency === '' ) {
			$currency = strtoupper( (string) $fallback_currency );
		}
		$rows = array();
		$base = array(
			'Titular / Beneficiario' => (string) ( $options['transfer_holder'] ?? '' ),
			'Banco'                  => (string) ( $options['transfer_bank'] ?? '' ),
			'País'                   => (string) ( $options['transfer_country'] ?? '' ),
			'Moneda'                 => $currency,
			'N° de cuenta'           => (string) ( $options['transfer_account_number'] ?? '' ),
			'Tipo de cuenta'         => (string) ( $options['transfer_account_type'] ?? '' ),
			'Correo de confirmación' => (string) ( $options['transfer_confirmation_email'] ?? '' ),
		);
		foreach ( $base as $label => $value ) {
			$value = trim( (string) $value );
			if ( $value === '' ) {
				continue;
			}
			$rows[] = array(
				'label' => sanitize_text_field( $label ),
				'value' => sanitize_text_field( $value ),
			);
		}
		$extra_raw = (string) ( $options['transfer_extra_fields'] ?? '' );
		if ( $extra_raw !== '' ) {
			$lines = preg_split( '/\r\n|\r|\n/', $extra_raw );
			foreach ( $lines as $line ) {
				$line = trim( (string) $line );
				if ( $line === '' ) {
					continue;
				}
				$parts = explode( ':', $line, 2 );
				if ( count( $parts ) === 2 ) {
					$label = sanitize_text_field( trim( (string) $parts[0] ) );
					$value = sanitize_text_field( trim( (string) $parts[1] ) );
				} else {
					$label = __( 'Dato', 'agenda-lite' );
					$value = sanitize_text_field( $line );
				}
				if ( $value === '' ) {
					continue;
				}
				$rows[] = array(
					'label' => $label,
					'value' => $value,
				);
			}
		}
		return array(
			'currency'     => $currency,
			'instructions' => sanitize_textarea_field( (string) ( $options['transfer_instructions'] ?? '' ) ),
			'rows'         => $rows,
		);
	}

	private static function payment_expiration_seconds( $booking, $snapshot = null ) {
		$provider = strtolower( (string) ( $booking->payment_provider ?? '' ) );
		if ( in_array( $provider, array( 'transfer', 'onsite' ), true ) ) {
			return 0;
		}
		return 600;
	}

	private static function file_rules_from_field( array $field ) {
		$allowed = $field['file_allowed'] ?? array();
		if ( ! is_array( $allowed ) ) {
			$allowed = array();
		}
		$custom    = $field['file_custom'] ?? '';
		$max_mb    = (float) ( $field['file_max_mb'] ?? 5 );
		$max_mb    = $max_mb > 0 ? $max_mb : 5;
		$max_files = (int) ( $field['file_max_files'] ?? 1 );
		$max_files = $max_files > 0 ? $max_files : 1;
		$required  = ! empty( $field['required'] );

		$map   = array(
			'pdf'    => array(
				'ext'  => array( 'pdf' ),
				'mime' => array( 'application/pdf' ),
			),
			'images' => array(
				'ext'  => array( 'jpg', 'jpeg', 'png', 'webp' ),
				'mime' => array( 'image/jpeg', 'image/png', 'image/webp' ),
			),
			'docs'   => array(
				'ext'  => array( 'doc', 'docx' ),
				'mime' => array( 'application/msword', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document' ),
			),
			'excel'  => array(
				'ext'  => array( 'xls', 'xlsx' ),
				'mime' => array( 'application/vnd.ms-excel', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' ),
			),
			'zip'    => array(
				'ext'  => array( 'zip' ),
				'mime' => array( 'application/zip', 'application/x-zip-compressed' ),
			),
		);
		$exts  = array();
		$mimes = array();
		foreach ( $allowed as $key ) {
			if ( ! empty( $map[ $key ] ) ) {
				$exts  = array_merge( $exts, $map[ $key ]['ext'] );
				$mimes = array_merge( $mimes, $map[ $key ]['mime'] );
			}
		}
		if ( ! empty( $custom ) ) {
			foreach ( array_map( 'trim', explode( ',', $custom ) ) as $item ) {
				if ( $item === '' ) {
					continue;
				}
				if ( strpos( $item, '/' ) !== false ) {
					$mimes[] = $item;
				} else {
					$exts[] = ltrim( $item, '.' );
				}
			}
		}
		$exts      = array_values( array_unique( array_filter( $exts ) ) );
		$mimes     = array_values( array_unique( array_filter( $mimes ) ) );
		$max_bytes = min( wp_max_upload_size(), (int) ( $max_mb * 1024 * 1024 ) );
		$label     = $exts ? strtoupper( implode( ', ', $exts ) ) : 'formatos permitidos';

		return array(
			'exts'      => $exts,
			'mimes'     => $mimes,
			'max_size'  => $max_bytes,
			'max_mb'    => $max_mb,
			'max_files' => $max_files,
			'required'  => $required,
			'label'     => $label,
		);
	}

	private static function file_is_allowed( array $file, array $exts, array $mimes ) {
		$blocked = array( 'php', 'php3', 'php4', 'php5', 'phtml', 'js', 'html', 'htm', 'svg', 'exe', 'sh', 'bat', 'cmd', 'cgi', 'pl', 'py', 'jar', 'com', 'msi', 'ps1' );
		$name    = (string) ( $file['name'] ?? '' );
		$tmp     = (string) ( $file['tmp_name'] ?? '' );
		if ( ! $tmp || ! is_uploaded_file( $tmp ) ) {
			return false;
		}
		$check = wp_check_filetype_and_ext( $tmp, $name );
		$ext   = strtolower( $check['ext'] ?? '' );
		$mime  = strtolower( $check['type'] ?? '' );
		if ( ! $ext || in_array( $ext, $blocked, true ) ) {
			return false;
		}
		if ( empty( $exts ) && empty( $mimes ) ) {
			return true;
		}
		if ( $ext && in_array( $ext, $exts, true ) ) {
			return true;
		}
		if ( $mime && in_array( $mime, $mimes, true ) ) {
			return true;
		}
		return false;
	}

	private static function private_upload_base_dir() {
		$upload   = wp_upload_dir( null, false );
		$base_dir = trailingslashit( (string) ( $upload['basedir'] ?? '' ) ) . 'litecal-private';
		wp_mkdir_p( $base_dir );
		self::ensure_private_upload_rules( $base_dir );
		return $base_dir;
	}

	public static function private_upload_dir( $dirs ) {
		$subdir         = '/litecal-private';
		$dirs['subdir'] = $subdir;
		$dirs['path']   = trailingslashit( (string) ( $dirs['basedir'] ?? '' ) ) . 'litecal-private';
		$dirs['url']    = trailingslashit( (string) ( $dirs['baseurl'] ?? '' ) ) . 'litecal-private';
		wp_mkdir_p( $dirs['path'] );
		self::ensure_private_upload_rules( $dirs['path'] );
		return $dirs;
	}

	private static function ensure_private_upload_rules( $directory ) {
		$dir = rtrim( (string) $directory, '/' );
		if ( $dir === '' || ! is_dir( $dir ) ) {
			return;
		}
		$index = trailingslashit( $dir ) . 'index.php';
		if ( ! file_exists( $index ) ) {
			@file_put_contents( $index, "<?php\n// Silence is golden.\n" );
		}
		$htaccess = trailingslashit( $dir ) . '.htaccess';
		if ( ! file_exists( $htaccess ) ) {
			@file_put_contents( $htaccess, "Require all denied\nDeny from all\n" );
		}
		$webconfig = trailingslashit( $dir ) . 'web.config';
		if ( ! file_exists( $webconfig ) ) {
			@file_put_contents( $webconfig, "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<configuration>\n  <system.webServer>\n    <security>\n      <authorization>\n        <remove users=\"*\" roles=\"\" verbs=\"\" />\n        <add accessType=\"Deny\" users=\"*\" />\n      </authorization>\n    </security>\n  </system.webServer>\n</configuration>\n" );
		}
		$nginx_example = trailingslashit( $dir ) . 'nginx-deny.conf.example';
		if ( ! file_exists( $nginx_example ) ) {
			$snippet = "location ^~ /wp-content/uploads/litecal-private/ {\n    deny all;\n    return 403;\n}\n";
			@file_put_contents( $nginx_example, $snippet );
		}
	}

	private static function booking_file_token( $booking_id, $field_key, $stored_name ) {
		$seed = implode(
			'|',
			array(
				(int) $booking_id,
				sanitize_key( (string) $field_key ),
				sanitize_file_name( (string) $stored_name ),
			)
		);
		$raw  = hash_hmac( 'sha256', $seed, wp_salt( 'litecal_file_token' ), true );
		return rtrim( strtr( base64_encode( $raw ), '+/', '-_' ), '=' );
	}

	private static function booking_file_url( $booking_id, $file_token, $booking_token = '' ) {
		$url           = rest_url( 'litecal/v1/booking/' . (int) $booking_id . '/file/' . rawurlencode( (string) $file_token ) );
		$booking_token = sanitize_text_field( (string) $booking_token );
		if ( $booking_token !== '' ) {
			$url = add_query_arg( 'booking_token', $booking_token, $url );
		}
		return $url;
	}

	public static function init() {
		add_action( 'rest_api_init', array( self::class, 'routes' ) );
	}

	public static function routes() {
		register_rest_route(
			'litecal/v1',
			'/event/(?P<slug>[a-zA-Z0-9-]+)',
			array(
				'methods'             => 'GET',
				'callback'            => array( self::class, 'get_event' ),
				'permission_callback' => '__return_true',
			)
		);

		register_rest_route(
			'litecal/v1',
			'/availability',
			array(
				'methods'             => 'GET',
				'callback'            => array( self::class, 'get_availability' ),
				'permission_callback' => '__return_true',
			)
		);
		register_rest_route(
			'litecal/v1',
			'/analytics/summary',
			array(
				'methods'             => 'GET',
				'callback'            => array( self::class, 'analytics_summary' ),
				'permission_callback' => array( self::class, 'analytics_permission' ),
			)
		);
		register_rest_route(
			'litecal/v1',
			'/analytics/timeseries',
			array(
				'methods'             => 'GET',
				'callback'            => array( self::class, 'analytics_timeseries' ),
				'permission_callback' => array( self::class, 'analytics_permission' ),
			)
		);
		register_rest_route(
			'litecal/v1',
			'/analytics/bookings',
			array(
				'methods'             => 'GET',
				'callback'            => array( self::class, 'analytics_bookings' ),
				'permission_callback' => array( self::class, 'analytics_permission' ),
			)
		);

		register_rest_route(
			'litecal/v1',
			'/bookings',
			array(
				'methods'             => 'POST',
				'callback'            => array( self::class, 'create_booking' ),
				'permission_callback' => '__return_true',
			)
		);

		register_rest_route(
			'litecal/v1',
			'/payments/webhook',
			array(
				'methods'             => 'POST',
				'callback'            => array( self::class, 'payments_webhook' ),
				'permission_callback' => '__return_true',
			)
		);

		register_rest_route(
			'litecal/v1',
			'/payments/paypal-capture',
			array(
				'methods'             => 'POST',
				'callback'            => array( self::class, 'paypal_capture' ),
				'permission_callback' => '__return_true',
			)
		);

		register_rest_route(
			'litecal/v1',
			'/payments/cancel',
			array(
				'methods'             => 'POST',
				'callback'            => array( self::class, 'cancel_payment' ),
				'permission_callback' => '__return_true',
			)
		);

		register_rest_route(
			'litecal/v1',
			'/payments/resume',
			array(
				'methods'             => 'POST',
				'callback'            => array( self::class, 'resume_payment' ),
				'permission_callback' => '__return_true',
			)
		);
		register_rest_route(
			'litecal/v1',
			'/payments/stripe/create-session',
			array(
				'methods'             => 'POST',
				'callback'            => array( self::class, 'stripe_create_session' ),
				'permission_callback' => '__return_true',
			)
		);
		register_rest_route(
			'litecal/v1',
			'/payments/stripe/webhook',
			array(
				'methods'             => 'POST',
				'callback'            => array( self::class, 'stripe_webhook' ),
				'permission_callback' => '__return_true',
			)
		);

		register_rest_route(
			'litecal/v1',
			'/booking/(?P<id>\d+)',
			array(
				'methods'             => 'GET',
				'callback'            => array( self::class, 'get_booking' ),
				'permission_callback' => '__return_true',
			)
		);
		register_rest_route(
			'litecal/v1',
			'/booking/(?P<id>\d+)/file/(?P<token>[A-Za-z0-9_-]+)',
			array(
				'methods'             => 'GET',
				'callback'            => array( self::class, 'download_booking_file' ),
				'permission_callback' => '__return_true',
			)
		);
		register_rest_route(
			'litecal/v1',
			'/booking/(?P<id>\d+)/refresh',
			array(
				'methods'             => 'POST',
				'callback'            => array( self::class, 'refresh_booking' ),
				'permission_callback' => '__return_true',
			)
		);
		register_rest_route(
			'litecal/v1',
			'/booking/(?P<id>\d+)/reschedule',
			array(
				'methods'             => 'POST',
				'callback'            => array( self::class, 'reschedule_booking' ),
				'permission_callback' => '__return_true',
			)
		);
		register_rest_route(
			'litecal/v1',
			'/booking/(?P<id>\d+)/cancel',
			array(
				'methods'             => 'POST',
				'callback'            => array( self::class, 'cancel_booking' ),
				'permission_callback' => '__return_true',
			)
		);
	}

	public static function analytics_permission() {
		return self::can_manage_bookings();
	}

	private static function analytics_paid_statuses() {
		return array( 'paid', 'approved', 'completed', 'confirmed' );
	}

	private static function analytics_can_view_email() {
		return current_user_can( 'manage_options' );
	}

	private static function analytics_status_label( $status ) {
		$status = strtolower( (string) $status );
		$map    = array(
			'pending'         => __( 'Pendiente', 'agenda-lite' ),
			'pending_payment' => __( 'Pendiente', 'agenda-lite' ),
			'confirmed'       => __( 'Confirmado', 'agenda-lite' ),
			'cancelled'       => __( 'Cancelado', 'agenda-lite' ),
			'canceled'        => __( 'Cancelado', 'agenda-lite' ),
			'expired'         => __( 'Cancelado', 'agenda-lite' ),
			'rescheduled'     => __( 'Reagendado', 'agenda-lite' ),
			'no_show'         => __( 'No asistió', 'agenda-lite' ),
		);
		return $map[ $status ] ?? ucfirst( $status );
	}

	private static function analytics_payment_status_label( $status ) {
		$status = strtolower( (string) $status );
		$map    = array(
			'paid'      => __( 'Aprobado', 'agenda-lite' ),
			'approved'  => __( 'Aprobado', 'agenda-lite' ),
			'pending'   => __( 'Pendiente', 'agenda-lite' ),
			'unpaid'    => __( 'Pendiente', 'agenda-lite' ),
			'cancelled' => __( 'Cancelado', 'agenda-lite' ),
			'canceled'  => __( 'Cancelado', 'agenda-lite' ),
			'expired'   => __( 'Cancelado', 'agenda-lite' ),
			'failed'    => __( 'Rechazado', 'agenda-lite' ),
			'rejected'  => __( 'Rechazado', 'agenda-lite' ),
		);
		return $map[ $status ] ?? ucfirst( $status );
	}

	private static function analytics_provider_label( $provider ) {
		$provider = strtolower( (string) $provider );
		$map      = array(
			'flow'        => 'Flow',
			'mp'          => 'MercadoPago',
			'mercadopago' => 'MercadoPago',
			'webpay'      => 'Webpay Plus',
			'paypal'      => 'PayPal',
			'stripe'      => 'Stripe',
			'transfer'    => __( 'Transferencia', 'agenda-lite' ),
			'onsite'      => __( 'Pago presencial', 'agenda-lite' ),
		);
		return $map[ $provider ] ?? ucfirst( $provider );
	}

	private static function analytics_format_money( $amount, $currency ) {
		$amount   = (float) $amount;
		$currency = strtoupper( (string) $currency );
		if ( $currency === '' ) {
			$currency = 'CLP';
		}
		if ( in_array( $currency, array( 'CLP', 'ARS', 'COP', 'MXN', 'PEN' ), true ) ) {
			return '$' . number_format( $amount, 0, ',', '.' ) . ' ' . $currency;
		}
		return number_format( $amount, 2, '.', ',' ) . ' ' . $currency;
	}

	private static function analytics_format_date( $datetime ) {
		$datetime = (string) $datetime;
		if ( $datetime === '' || $datetime === '0000-00-00 00:00:00' ) {
			return '-';
		}
		$ts = strtotime( $datetime );
		if ( ! $ts ) {
			return $datetime;
		}
		return wp_date( 'd M Y', $ts, wp_timezone() );
	}

	private static function analytics_format_time( $datetime ) {
		$datetime = (string) $datetime;
		if ( $datetime === '' || $datetime === '0000-00-00 00:00:00' ) {
			return '-';
		}
		$ts = strtotime( $datetime );
		if ( ! $ts ) {
			return '-';
		}
		$format = self::internal_time_format() === '24h' ? 'H:i' : 'g:ia';
		return wp_date( $format, $ts, wp_timezone() );
	}

	private static function internal_time_format() {
		$settings    = get_option( 'litecal_settings', array() );
		$time_format = (string) ( $settings['time_format'] ?? '12h' );
		return $time_format === '24h' ? '24h' : '12h';
	}

	private static function internal_format_date( $datetime, $human = true ) {
		$datetime = (string) $datetime;
		if ( $datetime === '' || $datetime === '0000-00-00 00:00:00' ) {
			return $datetime;
		}
		$ts = self::wp_datetime_to_ts( $datetime );
		if ( ! $ts ) {
			return $datetime;
		}
		$format = $human ? 'd M Y' : 'Y-m-d';
		return wp_date( $format, $ts, wp_timezone() );
	}

	private static function internal_format_time( $datetime ) {
		$datetime = (string) $datetime;
		if ( $datetime === '' || $datetime === '0000-00-00 00:00:00' ) {
			return '';
		}
		$ts = self::wp_datetime_to_ts( $datetime );
		if ( ! $ts ) {
			return '';
		}
		$format = self::internal_time_format() === '24h' ? 'H:i' : 'g:ia';
		return wp_date( $format, $ts, wp_timezone() );
	}

	private static function internal_format_time_range( $start_datetime, $end_datetime = '' ) {
		$start_label = self::internal_format_time( $start_datetime );
		$end_label   = self::internal_format_time( $end_datetime );
		if ( $start_label === '' ) {
			return '';
		}
		if ( $end_label === '' ) {
			return $start_label;
		}
		return $start_label . ' - ' . $end_label;
	}

	public static function analytics_filters_from_request( $source ) {
		$source    = is_array( $source ) ? $source : array();
		$today     = current_time( 'Y-m-d' );
		$date_from = sanitize_text_field( (string) ( $source['date_from'] ?? $today ) );
		$date_to   = sanitize_text_field( (string) ( $source['date_to'] ?? $today ) );
		if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date_from ) ) {
			$date_from = $today;
		}
		if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date_to ) ) {
			$date_to = $today;
		}
		if ( $date_from > $date_to ) {
			$tmp       = $date_from;
			$date_from = $date_to;
			$date_to   = $tmp;
		}
		$from_dt = \DateTimeImmutable::createFromFormat( 'Y-m-d', $date_from, wp_timezone() );
		$to_dt   = \DateTimeImmutable::createFromFormat( 'Y-m-d', $date_to, wp_timezone() );
		if ( $from_dt instanceof \DateTimeImmutable && $to_dt instanceof \DateTimeImmutable ) {
			$days = (int) $from_dt->diff( $to_dt )->days;
			if ( $days > 366 ) {
				$date_to = $from_dt->modify( '+366 days' )->format( 'Y-m-d' );
			}
		}

		$group_by = sanitize_key( (string) ( $source['group_by'] ?? 'day' ) );
		if ( ! in_array( $group_by, array( 'day', 'week', 'month', 'year' ), true ) ) {
			$group_by = 'day';
		}

		return array(
			'date_from'        => $date_from,
			'date_to'          => $date_to,
			'group_by'         => $group_by,
			'event_id'         => max( 0, (int) ( $source['event_id'] ?? 0 ) ),
			'employee_id'      => max( 0, (int) ( $source['employee_id'] ?? 0 ) ),
			'booking_status'   => sanitize_key( (string) ( $source['booking_status'] ?? '' ) ),
			'payment_status'   => sanitize_key( (string) ( $source['payment_status'] ?? '' ) ),
			'payment_provider' => sanitize_key( (string) ( $source['payment_provider'] ?? '' ) ),
		);
	}

	private static function analytics_payment_datetime_expr() {
		return "NULLIF(b.payment_received_at, '0000-00-00 00:00:00')";
	}

	private static function analytics_bucket_expression( $group_by, $datetime_expr ) {
		$group_by      = (string) $group_by;
		$datetime_expr = (string) $datetime_expr;
		if ( $group_by === 'week' ) {
			return "DATE_SUB(DATE({$datetime_expr}), INTERVAL WEEKDAY({$datetime_expr}) DAY)";
		}
		if ( $group_by === 'month' ) {
			return "DATE_FORMAT({$datetime_expr}, '%Y-%m-01')";
		}
		if ( $group_by === 'year' ) {
			return "YEAR({$datetime_expr})";
		}
		return "DATE({$datetime_expr})";
	}

	private static function analytics_build_where( array $filters, array &$params, $scope = 'all', $datetime_expr = 'b.start_datetime' ) {
		$where   = array();
		$where[] = "b.status <> 'deleted'";
		if ( $scope === 'payments' ) {
			$where[] = "{$datetime_expr} IS NOT NULL";
		}
		$where[]  = "{$datetime_expr} >= %s";
		$params[] = $filters['date_from'] . ' 00:00:00';
		$where[]  = "{$datetime_expr} <= %s";
		$params[] = $filters['date_to'] . ' 23:59:59';
		if ( ! empty( $filters['event_id'] ) ) {
			$where[]  = 'b.event_id = %d';
			$params[] = (int) $filters['event_id'];
		}
		if ( ! empty( $filters['employee_id'] ) ) {
			$where[]  = 'b.employee_id = %d';
			$params[] = (int) $filters['employee_id'];
		}
		if ( $scope !== 'payments' && ! empty( $filters['booking_status'] ) ) {
			if ( $filters['booking_status'] === 'cancelled' ) {
				$where[] = "(b.status = 'cancelled' OR b.status = 'canceled' OR b.status = 'expired')";
			} else {
				$where[]  = 'b.status = %s';
				$params[] = $filters['booking_status'];
			}
		}
		if ( $scope !== 'bookings' && ! empty( $filters['payment_status'] ) ) {
			if ( $filters['payment_status'] === 'cancelled' ) {
				$where[] = "(b.payment_status = 'cancelled' OR b.payment_status = 'canceled' OR b.payment_status = 'expired')";
			} else {
				$where[]  = 'b.payment_status = %s';
				$params[] = $filters['payment_status'];
			}
		}
		if ( $scope !== 'bookings' && ! empty( $filters['payment_provider'] ) ) {
			$where[]  = 'b.payment_provider = %s';
			$params[] = $filters['payment_provider'];
		}
		return implode( ' AND ', $where );
	}

	private static function analytics_site_currency() {
		$settings = get_option( 'litecal_settings', array() );
		$currency = strtoupper( (string) ( $settings['currency'] ?? 'CLP' ) );
		return $currency !== '' ? $currency : 'CLP';
	}

	private static function analytics_currency_decimals( $currency ) {
		$currency = strtoupper( (string) $currency );
		return in_array( $currency, array( 'CLP', 'ARS', 'COP', 'MXN', 'PEN' ), true ) ? 0 : 2;
	}

	private static function analytics_amount_to_site_currency( $amount, $payment_currency, $payment_provider, $settings = null ) {
		$amount = (float) $amount;
		if ( $amount <= 0 ) {
			return 0.0;
		}
		if ( ! is_array( $settings ) ) {
			$settings = get_option( 'litecal_settings', array() );
		}

		$site_currency = strtoupper( (string) ( $settings['currency'] ?? 'CLP' ) );
		if ( $site_currency === '' ) {
			$site_currency = 'CLP';
		}
		$provider         = sanitize_key( (string) $payment_provider );
		$payment_currency = strtoupper( (string) $payment_currency );
		if ( $payment_currency === '' ) {
			return round( $amount, self::analytics_currency_decimals( $site_currency ) );
		}

		// Legacy guard: some old PayPal rows were stored with amount in USD but currency as CLP.
		if (
			$provider === 'paypal'
			&& $payment_currency === $site_currency
			&& $site_currency === 'CLP'
			&& $amount > 0
			&& $amount < 1000
		) {
			$provider_currency = \LiteCal\Admin\Admin::provider_base_currency( $provider );
			$provider_rates    = \LiteCal\Admin\Admin::multicurrency_provider_rates( $settings );
			$provider_rate     = (float) ( $provider_rates[ $provider ] ?? 0 );
			if ( $provider_currency === 'USD' && $provider_rate > 0 ) {
				return round( $amount * $provider_rate, self::analytics_currency_decimals( $site_currency ) );
			}
		}

		if ( $payment_currency === $site_currency ) {
			return round( $amount, self::analytics_currency_decimals( $site_currency ) );
		}

		if ( $provider !== '' ) {
			$provider_currency = \LiteCal\Admin\Admin::provider_base_currency( $provider );
			$provider_rates    = \LiteCal\Admin\Admin::multicurrency_provider_rates( $settings );
			$provider_rate     = (float) ( $provider_rates[ $provider ] ?? 0 );

			if ( $provider_rate > 0 && $provider_currency === $payment_currency && $provider_currency !== $site_currency ) {
				if ( $site_currency === 'CLP' && $provider_currency === 'USD' ) {
					return round( $amount * $provider_rate, self::analytics_currency_decimals( $site_currency ) );
				}
				if ( $site_currency === 'USD' && $provider_currency === 'CLP' ) {
					return round( $amount / $provider_rate, self::analytics_currency_decimals( $site_currency ) );
				}
			}
		}

		$rates = \LiteCal\Admin\Admin::multicurrency_rates( $settings );
		$rate  = (float) ( $rates[ $payment_currency ] ?? 0 );
		if ( $rate > 0 ) {
			return round( $amount * $rate, self::analytics_currency_decimals( $site_currency ) );
		}

		return round( $amount, self::analytics_currency_decimals( $site_currency ) );
	}

	public static function analytics_fetch_rows( array $filters, $page = 1, $limit = 25, $sort_by = 'start_datetime', $sort_dir = 'desc', $search = '', $for_export = false ) {
		global $wpdb;
		$table           = $wpdb->prefix . 'litecal_bookings';
		$events_table    = $wpdb->prefix . 'litecal_events';
		$employees_table = $wpdb->prefix . 'litecal_employees';

		$page     = max( 1, (int) $page );
		$limit    = max( 1, min( 100, (int) $limit ) );
		$offset   = ( $page - 1 ) * $limit;
		$search   = sanitize_text_field( (string) $search );
		$sort_dir = strtolower( (string) $sort_dir ) === 'asc' ? 'ASC' : 'DESC';
		$sort_map = array(
			'id'               => 'b.id',
			'start_datetime'   => 'b.start_datetime',
			'event_title'      => 'e.title',
			'employee_name'    => 'em.name',
			'status'           => 'b.status',
			'payment_status'   => 'b.payment_status',
			'payment_provider' => 'b.payment_provider',
			'amount'           => 'b.payment_amount',
			'client_name'      => 'b.name',
		);
		$order_by = $sort_map[ $sort_by ] ?? 'b.start_datetime';

		$params = array();
		$where  = self::analytics_build_where( $filters, $params, 'all', 'b.start_datetime' );
		if ( $search !== '' ) {
			$like     = '%' . $wpdb->esc_like( $search ) . '%';
			$where   .= ' AND (b.name LIKE %s OR b.email LIKE %s OR e.title LIKE %s OR b.payment_reference LIKE %s)';
			$params[] = $like;
			$params[] = $like;
			$params[] = $like;
			$params[] = $like;
		}

		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table names are built from $wpdb->prefix and WHERE/ORDER parts are whitelisted in this method.
		$count_sql = "SELECT COUNT(*)
	            FROM {$table} b
	            LEFT JOIN {$events_table} e ON e.id = b.event_id
	            LEFT JOIN {$employees_table} em ON em.id = b.employee_id
	            WHERE {$where}";
		$total     = $params ? (int) $wpdb->get_var( $wpdb->prepare( $count_sql, $params ) ) : (int) $wpdb->get_var( $count_sql );

		$select_sql = "SELECT
                b.id,
                b.start_datetime,
                b.status,
                b.payment_status,
                b.payment_provider,
                b.payment_amount,
                b.payment_currency,
                b.name as client_name,
                b.email as client_email,
                b.payment_reference,
                e.title as event_title,
                em.name as employee_name
            FROM {$table} b
            LEFT JOIN {$events_table} e ON e.id = b.event_id
            LEFT JOIN {$employees_table} em ON em.id = b.employee_id
            WHERE {$where}
            ORDER BY {$order_by} {$sort_dir}, b.id DESC";
		if ( ! $for_export ) {
			$select_sql  .= ' LIMIT %d OFFSET %d';
			$query_params = array_merge( $params, array( $limit, $offset ) );
			$rows         = $wpdb->get_results( $wpdb->prepare( $select_sql, $query_params ), ARRAY_A );
		} else {
			$rows = $params ? $wpdb->get_results( $wpdb->prepare( $select_sql, $params ), ARRAY_A ) : $wpdb->get_results( $select_sql, ARRAY_A );
		}
		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		return array( $rows, $total );
	}

	public static function analytics_summary( $request ) {
		global $wpdb;
		$filters       = self::analytics_filters_from_request( $request->get_params() );
		$force_refresh = (int) $request->get_param( 'no_cache' ) === 1;
		$cache_key     = 'litecal_analytics_summary_' . md5( wp_json_encode( $filters ) );
		if ( ! $force_refresh ) {
			$cached = get_transient( $cache_key );
			if ( is_array( $cached ) ) {
				return self::rest_response( 'analytics_summary', 'Resumen obtenido.', 'ok', $cached );
			}
		}

		$table = $wpdb->prefix . 'litecal_bookings';

		$booking_params = array();
		$bookings_where = self::analytics_build_where( $filters, $booking_params, 'bookings', 'b.start_datetime' );
		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table names are built from $wpdb->prefix and SQL fragments are controlled.
		$bookings_sql = "SELECT
	            COUNT(*) as total_bookings,
	            SUM(CASE WHEN b.status = 'confirmed' THEN 1 ELSE 0 END) as confirmed_bookings,
	            SUM(CASE WHEN b.status IN ('pending','pending_payment') THEN 1 ELSE 0 END) as pending_bookings,
            SUM(CASE WHEN b.status IN ('cancelled','canceled','expired') THEN 1 ELSE 0 END) as cancelled_bookings,
            SUM(CASE WHEN b.status = 'rescheduled' THEN 1 ELSE 0 END) as rescheduled_bookings
            FROM {$table} b
            WHERE {$bookings_where}";
		$booking_row  = $booking_params ? $wpdb->get_row( $wpdb->prepare( $bookings_sql, $booking_params ), ARRAY_A ) : $wpdb->get_row( $bookings_sql, ARRAY_A );

		$trash_where  = array( "b.status = 'deleted'" );
		$trash_params = array();
		if ( ! empty( $filters['event_id'] ) ) {
			$trash_where[]  = 'b.event_id = %d';
			$trash_params[] = (int) $filters['event_id'];
		}
		if ( ! empty( $filters['employee_id'] ) ) {
			$trash_where[]  = 'b.employee_id = %d';
			$trash_params[] = (int) $filters['employee_id'];
		}
		$trash_sql = "SELECT COUNT(*) FROM {$table} b WHERE " . implode( ' AND ', $trash_where );
		$trashed   = $trash_params ? (int) $wpdb->get_var( $wpdb->prepare( $trash_sql, $trash_params ) ) : (int) $wpdb->get_var( $trash_sql );

		$payment_dt_expr = self::analytics_payment_datetime_expr();
		$payment_params  = array();
		$payments_where  = self::analytics_build_where( $filters, $payment_params, 'payments', $payment_dt_expr );
		$paid_in         = "'" . implode( "','", array_map( 'esc_sql', self::analytics_paid_statuses() ) ) . "'";
		$paid_expr       = "(COALESCE(b.payment_voided, 0) = 0 AND b.payment_status IN ({$paid_in}))";
		$payments_sql    = "SELECT
            b.payment_amount,
            b.payment_currency,
	            b.payment_provider
	            FROM {$table} b
	            WHERE {$payments_where}
	              AND {$paid_expr}";
		$payment_rows    = $payment_params ? $wpdb->get_results( $wpdb->prepare( $payments_sql, $payment_params ), ARRAY_A ) : $wpdb->get_results( $payments_sql, ARRAY_A );
		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		$total         = (int) ( $booking_row['total_bookings'] ?? 0 );
		$paid_count    = 0;
		$revenue       = 0.0;
		$settings      = get_option( 'litecal_settings', array() );
		$site_currency = self::analytics_site_currency();
		$site_decimals = self::analytics_currency_decimals( $site_currency );
		foreach ( (array) $payment_rows as $payment_row ) {
			$amount = (float) ( $payment_row['payment_amount'] ?? 0 );
			if ( $amount <= 0 ) {
				continue;
			}
			++$paid_count;
			$revenue += self::analytics_amount_to_site_currency(
				$amount,
				(string) ( $payment_row['payment_currency'] ?? '' ),
				(string) ( $payment_row['payment_provider'] ?? '' ),
				$settings
			);
		}
		$revenue    = round( $revenue, $site_decimals );
		$ticket_avg = $paid_count > 0 ? $revenue / $paid_count : 0;
		$ticket_avg = round( $ticket_avg, $site_decimals );
		$data       = array(
			'filters' => $filters,
			'kpis'    => array(
				'total'            => $total,
				'confirmed'        => (int) ( $booking_row['confirmed_bookings'] ?? 0 ),
				'pending'          => (int) ( $booking_row['pending_bookings'] ?? 0 ),
				'cancelled'        => (int) ( $booking_row['cancelled_bookings'] ?? 0 ),
				'rescheduled'      => (int) ( $booking_row['rescheduled_bookings'] ?? 0 ),
				'trashed'          => $trashed,
				'revenue'          => $revenue,
				'ticket_avg'       => $ticket_avg,
				'revenue_currency' => $site_currency,
			),
		);
		set_transient( $cache_key, $data, 30 );
		return self::rest_response( 'analytics_summary', 'Resumen obtenido.', 'ok', $data );
	}

	public static function analytics_timeseries( $request ) {
		global $wpdb;
		$filters       = self::analytics_filters_from_request( $request->get_params() );
		$force_refresh = (int) $request->get_param( 'no_cache' ) === 1;
		$cache_key     = 'litecal_analytics_timeseries_' . md5( wp_json_encode( $filters ) );
		if ( ! $force_refresh ) {
			$cached = get_transient( $cache_key );
			if ( is_array( $cached ) ) {
				return self::rest_response( 'analytics_timeseries', 'Series obtenidas.', 'ok', $cached );
			}
		}

		$table                = $wpdb->prefix . 'litecal_bookings';
		$group_by             = $filters['group_by'];
		$bucket_expr_bookings = self::analytics_bucket_expression( $group_by, 'b.start_datetime' );
		$payment_dt_expr      = self::analytics_payment_datetime_expr();
		$bucket_expr_revenue  = self::analytics_bucket_expression( $group_by, $payment_dt_expr );
		$paid_in              = "'" . implode( "','", array_map( 'esc_sql', self::analytics_paid_statuses() ) ) . "'";
		$paid_expr            = "(COALESCE(b.payment_voided, 0) = 0 AND b.payment_status IN ({$paid_in}))";

		$bookings_params = array();
		$bookings_where  = self::analytics_build_where( $filters, $bookings_params, 'bookings', 'b.start_datetime' );
		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table names are built from $wpdb->prefix and SQL fragments are controlled.
		$bookings_sql = "SELECT
	            {$bucket_expr_bookings} as bucket,
	            COUNT(*) as bookings_count,
	            SUM(CASE WHEN b.status = 'confirmed' THEN 1 ELSE 0 END) as confirmed_count,
            SUM(CASE WHEN b.status IN ('pending','pending_payment') THEN 1 ELSE 0 END) as pending_count,
            SUM(CASE WHEN b.status IN ('cancelled','canceled','expired') THEN 1 ELSE 0 END) as cancelled_count,
            SUM(CASE WHEN b.status = 'rescheduled' THEN 1 ELSE 0 END) as rescheduled_count
            FROM {$table} b
            WHERE {$bookings_where}
            GROUP BY bucket
            ORDER BY bucket ASC";
		$booking_rows = $bookings_params ? $wpdb->get_results( $wpdb->prepare( $bookings_sql, $bookings_params ), ARRAY_A ) : $wpdb->get_results( $bookings_sql, ARRAY_A );

		$payments_params = array();
		$payments_where  = self::analytics_build_where( $filters, $payments_params, 'payments', $payment_dt_expr );
		$payments_sql    = "SELECT
            {$bucket_expr_revenue} as bucket,
            b.payment_currency,
            b.payment_provider,
            SUM(b.payment_amount) as revenue
            FROM {$table} b
	            WHERE {$payments_where}
	              AND {$paid_expr}
	            GROUP BY bucket, b.payment_currency, b.payment_provider
	            ORDER BY bucket ASC";
		$payment_rows    = $payments_params ? $wpdb->get_results( $wpdb->prepare( $payments_sql, $payments_params ), ARRAY_A ) : $wpdb->get_results( $payments_sql, ARRAY_A );
		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		$tz                 = wp_timezone();
		$labels             = array();
		$labels_revenue     = array();
		$bookings_series    = array();
		$confirmed_series   = array();
		$pending_series     = array();
		$cancelled_series   = array();
		$rescheduled_series = array();
		$revenue_series     = array();
		$bookings_points    = array();
		$revenue_points     = array();
		foreach ( (array) $booking_rows as $row ) {
			$bucket = (string) ( $row['bucket'] ?? '' );
			if ( $bucket === '' ) {
				continue;
			}
			$label = '';
			if ( $group_by === 'year' ) {
				$label = preg_replace( '/[^0-9]/', '', $bucket );
				if ( $label === '' ) {
					continue;
				}
			} else {
				$bucket_dt = \DateTimeImmutable::createFromFormat( '!Y-m-d', substr( $bucket, 0, 10 ), $tz );
				if ( ! $bucket_dt instanceof \DateTimeImmutable ) {
					continue;
				}
				$bucket_ts = $bucket_dt->getTimestamp();
				$label     = wp_date( 'd M', $bucket_ts, $tz );
				if ( $group_by === 'week' ) {
					/* translators: %s: week label date. */
					$label = sprintf( __( 'Sem %s', 'agenda-lite' ), wp_date( 'd M', $bucket_ts, $tz ) );
				} elseif ( $group_by === 'month' ) {
					$label = wp_date( 'M Y', $bucket_ts, $tz );
				}
			}
			$labels[]             = $label;
			$bookings_series[]    = (int) ( $row['bookings_count'] ?? 0 );
			$confirmed_series[]   = (int) ( $row['confirmed_count'] ?? 0 );
			$pending_series[]     = (int) ( $row['pending_count'] ?? 0 );
			$cancelled_series[]   = (int) ( $row['cancelled_count'] ?? 0 );
			$rescheduled_series[] = (int) ( $row['rescheduled_count'] ?? 0 );
			$bookings_points[]    = array(
				'bucket' => $bucket,
				'label'  => $label,
				'value'  => (int) ( $row['bookings_count'] ?? 0 ),
			);
		}

		$settings      = get_option( 'litecal_settings', array() );
		$site_currency = self::analytics_site_currency();
		$site_decimals = self::analytics_currency_decimals( $site_currency );
		$revenue_map   = array();
		foreach ( (array) $payment_rows as $row ) {
			$bucket = (string) ( $row['bucket'] ?? '' );
			if ( $bucket === '' ) {
				continue;
			}
			$amount = self::analytics_amount_to_site_currency(
				(float) ( $row['revenue'] ?? 0 ),
				(string) ( $row['payment_currency'] ?? '' ),
				(string) ( $row['payment_provider'] ?? '' ),
				$settings
			);
			if ( ! isset( $revenue_map[ $bucket ] ) ) {
				$revenue_map[ $bucket ] = 0.0;
			}
			$revenue_map[ $bucket ] += $amount;
		}
		if ( ! empty( $revenue_map ) ) {
			ksort( $revenue_map );
			foreach ( $revenue_map as $bucket => $amount ) {
				$label = '';
				if ( $group_by === 'year' ) {
					$label = preg_replace( '/[^0-9]/', '', (string) $bucket );
					if ( $label === '' ) {
						continue;
					}
				} else {
					$bucket_dt = \DateTimeImmutable::createFromFormat( '!Y-m-d', substr( (string) $bucket, 0, 10 ), $tz );
					if ( ! $bucket_dt instanceof \DateTimeImmutable ) {
						continue;
					}
					$bucket_ts = $bucket_dt->getTimestamp();
					$label     = wp_date( 'd M', $bucket_ts, $tz );
					if ( $group_by === 'week' ) {
						/* translators: %s: week label date. */
						$label = sprintf( __( 'Sem %s', 'agenda-lite' ), wp_date( 'd M', $bucket_ts, $tz ) );
					} elseif ( $group_by === 'month' ) {
						$label = wp_date( 'M Y', $bucket_ts, $tz );
					}
				}
				$labels_revenue[] = $label;
				$amount_rounded   = round( (float) $amount, $site_decimals );
				$revenue_series[] = $amount_rounded;
				$revenue_points[] = array(
					'bucket' => (string) $bucket,
					'label'  => $label,
					'value'  => $amount_rounded,
				);
			}
		}
		$data = array(
			'filters'        => $filters,
			'labels'         => $labels,
			'labels_revenue' => $labels_revenue,
			'series'         => array(
				'bookings'    => $bookings_series,
				'confirmed'   => $confirmed_series,
				'pending'     => $pending_series,
				'cancelled'   => $cancelled_series,
				'rescheduled' => $rescheduled_series,
				'revenue'     => $revenue_series,
			),
			'points'         => array(
				'bookings' => $bookings_points,
				'revenue'  => $revenue_points,
			),
			'meta'           => array(
				'group_by' => $group_by,
				'currency' => $site_currency,
			),
		);
		set_transient( $cache_key, $data, 30 );
		return self::rest_response( 'analytics_timeseries', 'Series obtenidas.', 'ok', $data );
	}

	public static function analytics_bookings( $request ) {
		$params  = $request->get_params();
		$filters = self::analytics_filters_from_request( $params );
		$page    = max( 1, (int) ( $params['page'] ?? 1 ) );
		$limit   = (int) ( $params['limit'] ?? 25 );
		if ( ! in_array( $limit, array( 10, 25, 50, 100 ), true ) ) {
			$limit = 25;
		}
		$sort_by   = sanitize_key( (string) ( $params['sortBy'] ?? 'start_datetime' ) );
		$sort_dir  = strtolower( sanitize_text_field( (string) ( $params['sortDir'] ?? 'desc' ) ) );
		$search    = sanitize_text_field( (string) ( $params['search'] ?? '' ) );
		$cache_key = 'litecal_analytics_bookings_' . md5(
			wp_json_encode(
				array(
					'filters'  => $filters,
					'page'     => $page,
					'limit'    => $limit,
					'sort_by'  => $sort_by,
					'sort_dir' => $sort_dir,
					'search'   => $search,
					'email'    => self::analytics_can_view_email() ? 1 : 0,
				)
			)
		);
		$cached    = get_transient( $cache_key );
		if ( is_array( $cached ) ) {
			return self::rest_response( 'analytics_bookings', 'Detalle obtenido.', 'ok', $cached );
		}

		[$rows, $total] = self::analytics_fetch_rows( $filters, $page, $limit, $sort_by, $sort_dir, $search, false );
		$can_view_email = self::analytics_can_view_email();
		$data_rows      = array();
		foreach ( (array) $rows as $row ) {
			$status_label   = self::analytics_status_label( $row['status'] ?? '' );
			$payment_label  = self::analytics_payment_status_label( $row['payment_status'] ?? '' );
			$provider_label = '';
			if ( ! empty( $row['payment_provider'] ) ) {
				$provider_label = self::analytics_provider_label( $row['payment_provider'] );
			} else {
				$provider_label = __( 'Sin pago', 'agenda-lite' );
			}
			$client = (string) ( $row['client_name'] ?? '' );
			if ( $can_view_email && ! empty( $row['client_email'] ) ) {
				$client .= ' · ' . (string) $row['client_email'];
			}
			$data_rows[] = array(
				'id'       => (int) ( $row['id'] ?? 0 ),
				'date'     => self::analytics_format_date( $row['start_datetime'] ?? '' ),
				'time'     => self::analytics_format_time( $row['start_datetime'] ?? '' ),
				'event'    => (string) ( $row['event_title'] ?? '-' ),
				'employee' => (string) ( $row['employee_name'] ?? '-' ),
				'status'   => $status_label,
				'payment'  => $payment_label,
				'provider' => $provider_label,
				'amount'   => self::analytics_format_money( (float) ( $row['payment_amount'] ?? 0 ), (string) ( $row['payment_currency'] ?? 'CLP' ) ),
				'client'   => $client,
				'view_url' => add_query_arg(
					array(
						'page'          => 'litecal-dashboard',
						'booking_id'    => (int) ( $row['id'] ?? 0 ),
						'calendar_date' => substr( (string) ( $row['start_datetime'] ?? '' ), 0, 10 ),
					),
					admin_url( 'admin.php' )
				),
			);
		}
		$payload = array(
			'rows'           => $data_rows,
			'total'          => (int) $total,
			'page'           => (int) $page,
			'limit'          => (int) $limit,
			'can_view_email' => $can_view_email ? 1 : 0,
		);
		set_transient( $cache_key, $payload, 60 );
		return self::rest_response( 'analytics_bookings', 'Detalle obtenido.', 'ok', $payload );
	}

	public static function get_event( $request ) {
		$slug  = sanitize_text_field( $request['slug'] );
		$event = Events::get_by_slug( $slug );
		if ( ! $event ) {
			return self::rest_error( 'not_found', 'Servicio no encontrado', array( 'status' => 404 ) );
		}
		$employees     = Events::employees( $event->id );
		$custom_fields = json_decode( (string) ( $event->custom_fields ?? '[]' ), true );
		if ( ! is_array( $custom_fields ) ) {
			$custom_fields = array();
		}
		$free_restrictions = self::free_plan_restrictions_enabled();
		$custom_fields     = array_values(
			array_filter(
				array_map(
					static function ( $field ) use ( $free_restrictions ) {
						if ( ! is_array( $field ) ) {
							return null;
						}
						$type    = sanitize_key( (string) ( $field['type'] ?? 'text' ) );
						$enabled = ! isset( $field['enabled'] ) || ! empty( $field['enabled'] );
						if ( ! $enabled ) {
							return null;
						}
						if ( $free_restrictions && $type === 'file' ) {
							return null;
						}
						$key = sanitize_key( (string) ( $field['key'] ?? '' ) );
						if ( $key === '' ) {
							return null;
						}
						$row = array(
							'key'      => $key,
							'label'    => sanitize_text_field( (string) ( $field['label'] ?? $key ) ),
							'type'     => $type !== '' ? $type : 'text',
							'required' => ! empty( $field['required'] ) ? 1 : 0,
						);
						if ( ! empty( $field['placeholder'] ) ) {
							$row['placeholder'] = sanitize_text_field( (string) $field['placeholder'] );
						}
						if ( ! empty( $field['options'] ) && is_array( $field['options'] ) ) {
							$row['options'] = array_values( array_map( 'sanitize_text_field', (array) $field['options'] ) );
						}
						return $row;
					},
					$custom_fields
				)
			)
		);

		$event_options = json_decode( (string) ( $event->options ?? '[]' ), true );
		if ( ! is_array( $event_options ) ) {
			$event_options = array();
		}
			$allow_guests_public = $free_restrictions ? 0 : ( ! empty( $event_options['allow_guests'] ) ? 1 : 0 );
			$max_guests_public   = $allow_guests_public
				? max( 1, min( self::MAX_GUESTS_PER_BOOKING, (int) ( $event_options['max_guests'] ?? 0 ) ) )
				: 0;
			$public_options      = array(
				'notice_hours'  => max( 0, (int) ( $event_options['notice_hours'] ?? 0 ) ),
				'limit_per_day' => max( 0, (int) ( $event_options['limit_per_day'] ?? 0 ) ),
				'future_days'   => max( 0, (int) ( $event_options['future_days'] ?? 0 ) ),
				'max_guests'    => $max_guests_public,
				'allow_guests'  => $allow_guests_public,
				'price_mode'    => sanitize_key( (string) ( $event_options['price_mode'] ?? 'total' ) ),
			);

		$event_dto = array(
			'id'               => (int) $event->id,
			'title'            => sanitize_text_field( (string) $event->title ),
			'slug'             => sanitize_title( (string) $event->slug ),
			'description'      => wp_kses_post( (string) ( $event->description ?? '' ) ),
			'duration'         => (int) ( $event->duration ?? 0 ),
			'price'            => (float) ( $event->price ?? 0 ),
			'currency'         => strtoupper( (string) ( $event->currency ?? 'CLP' ) ),
			'location'         => sanitize_key( (string) ( $event->location ?? '' ) ),
			'location_details' => sanitize_text_field( (string) ( $event->location_details ?? '' ) ),
			'require_payment'  => ! empty( $event->require_payment ) ? 1 : 0,
			'custom_fields'    => $custom_fields,
			'options'          => $public_options,
		);

		$employees_dto = array_values(
			array_filter(
				array_map(
					static function ( $employee ) {
						if ( ! is_object( $employee ) ) {
							return null;
						}
						return array(
							'id'         => (int) ( $employee->id ?? 0 ),
							'name'       => sanitize_text_field( (string) ( $employee->name ?? '' ) ),
							'title'      => sanitize_text_field( (string) ( $employee->title ?? '' ) ),
							'avatar_url' => Helpers::avatar_url( (string) ( $employee->avatar_url ?? '' ), 'thumbnail' ),
						);
					},
					(array) $employees
				)
			)
		);
		return self::rest_response(
			'event_found',
			'Servicio encontrado.',
			'ok',
			array(
				'event'     => $event_dto,
				'employees' => $employees_dto,
			)
		);
	}

	public static function get_availability( $request ) {
		$event_id     = (int) $request->get_param( 'event_id' );
		$date         = sanitize_text_field( $request->get_param( 'date' ) );
		$employee_id  = (int) $request->get_param( 'employee_id' );
		$booking_id   = (int) $request->get_param( 'booking_id' );
		$global_limit = self::rate_limit( 'availability_global', 60, 60 );
		if ( is_wp_error( $global_limit ) ) {
			return $global_limit;
		}
		$limit = self::rate_limit( "availability:{$event_id}", 10, 60 );
		if ( is_wp_error( $limit ) ) {
			return $limit;
		}
		$event = Events::get( $event_id );
		if ( ! $event || empty( $date ) ) {
			return self::rest_error( 'invalid', 'Datos inválidos', array( 'status' => 400 ) );
		}
		$event_employees = Events::employees( $event_id );
		if ( count( $event_employees ) > 1 && $employee_id <= 0 ) {
			return self::rest_response( 'availability_found', 'Selecciona un profesional para ver horarios.', 'ok', array( 'slots' => array() ) );
		}
		$slots = Availability::get_slots( $event, $employee_id, $date, $booking_id );
		return self::rest_response( 'availability_found', 'Disponibilidad obtenida.', 'ok', array( 'slots' => $slots ) );
	}

	public static function create_booking( $request ) {
		$payload = $request->get_json_params();
		if ( empty( $payload ) || ! is_array( $payload ) ) {
			$payload = $request->get_params();
		}
		$event_id     = (int) ( $payload['event_id'] ?? 0 );
		$global_limit = self::rate_limit( 'create_booking_global', 15, 60 );
		if ( is_wp_error( $global_limit ) ) {
			return $global_limit;
		}
		$limit = self::rate_limit( "create_booking:{$event_id}", 5, 60 );
		if ( is_wp_error( $limit ) ) {
			return $limit;
		}
		$recaptcha_token = sanitize_text_field( (string) ( $payload['recaptcha_token'] ?? '' ) );
		if ( ! self::verify_recaptcha( $recaptcha_token ) ) {
			return self::rest_error( 'recaptcha', 'Validación de seguridad fallida.', array( 'status' => 400 ) );
		}
		$files_payload = $request->get_file_params();
		$employee_id   = (int) ( $payload['employee_id'] ?? 0 );
		$start         = sanitize_text_field( $payload['start'] ?? '' );
		$end           = sanitize_text_field( $payload['end'] ?? '' );
		$event         = Events::get( $event_id );
		if ( ! $event || empty( $start ) || empty( $end ) ) {
			return self::rest_error( 'invalid', 'Datos inválidos', array( 'status' => 400 ) );
		}
		$event_employees    = Events::employees( $event_id );
		$event_employee_ids = array_values(
			array_filter(
				array_map(
					static function ( $item ) {
						return (int) ( $item->id ?? 0 );
					},
					(array) $event_employees
				)
			)
		);
		if ( $employee_id <= 0 ) {
			if ( count( $event_employee_ids ) === 1 ) {
				$employee_id = (int) $event_employee_ids[0];
			} elseif ( count( $event_employee_ids ) > 1 ) {
				return self::rest_error( 'employee_required', 'Selecciona un profesional.', array( 'status' => 400 ) );
			}
		} elseif ( ! empty( $event_employee_ids ) && ! in_array( $employee_id, $event_employee_ids, true ) ) {
			return self::rest_error( 'invalid_employee', 'Profesional inválido para este servicio.', array( 'status' => 400 ) );
		}
		$slot_resource  = $employee_id > 0 ? ( 'employee:' . $employee_id ) : ( 'event:' . $event_id );
		$slot_start_raw = preg_replace( '/\s+/', ' ', (string) $start );
		$slot_end_raw   = preg_replace( '/\s+/', ' ', (string) $end );
		$slot_start     = trim( is_string( $slot_start_raw ) ? $slot_start_raw : (string) $start );
		$slot_end       = trim( is_string( $slot_end_raw ) ? $slot_end_raw : (string) $end );
		if ( preg_match( '/^\d{4}-\d{2}-\d{2}\s+\d{2}:\d{2}/', $slot_start, $slot_match_start ) ) {
			$slot_start = substr( (string) $slot_match_start[0], 0, 16 );
		}
		if ( preg_match( '/^\d{4}-\d{2}-\d{2}\s+\d{2}:\d{2}/', $slot_end, $slot_match_end ) ) {
			$slot_end = substr( (string) $slot_match_end[0], 0, 16 );
		}
		if ( $slot_start === '' || $slot_end === '' ) {
			$slot_start = md5( (string) $start );
			$slot_end   = md5( (string) $end );
		}
		$slot_lock_key = 'booking_slot|' . $slot_resource . '|' . $slot_start . '|' . $slot_end;
		if ( ! self::acquire_lock( $slot_lock_key, 20 ) ) {
			return self::rest_error( 'slot_locked', 'Este horario se está reservando. Intenta nuevamente.', array( 'status' => 409 ) );
		}
		try {

			$email = sanitize_email( $payload['email'] ?? '' );
			if ( empty( $email ) || ! filter_var( $email, FILTER_VALIDATE_EMAIL ) ) {
				return self::rest_error( 'invalid_email', 'Email inválido.', array( 'status' => 400 ) );
			}
			$client_phone_raw  = sanitize_text_field( (string) ( $payload['phone'] ?? '' ) );
			$client_device_id  = sanitize_text_field( (string) ( $payload['client_device_id'] ?? '' ) );
			$client_ip         = self::client_ip();
			$client_user_agent = self::client_user_agent();
			$client_ip_prefix  = self::booking_abuse_ip_prefix( $client_ip );
			$client_ua_key     = self::booking_abuse_ua_key( $client_user_agent );
			$client_network    = ( $client_ip_prefix !== '' && $client_ua_key !== '' )
				? hash( 'sha256', 'ip:' . $client_ip_prefix . '|ua:' . $client_ua_key )
				: '';
			// Flow validará el dominio; aquí solo validamos formato básico.

			$custom_fields         = array();
			$custom_fields_def_raw = json_decode( $event->custom_fields ?: '[]', true );
			if ( ! is_array( $custom_fields_def_raw ) ) {
				$custom_fields_def_raw = array();
			}
			$free_restrictions    = self::free_plan_restrictions_enabled();
			$custom_fields_def    = array();
			$custom_fields_schema = array();
			foreach ( $custom_fields_def_raw as $field_def ) {
				if ( ! is_array( $field_def ) ) {
					continue;
				}
				$enabled = ! isset( $field_def['enabled'] ) || ! empty( $field_def['enabled'] );
				if ( ! $enabled ) {
					continue;
				}
				$field_key = sanitize_key( (string) ( $field_def['key'] ?? '' ) );
				if ( $field_key === '' ) {
					continue;
				}
				$field_type = sanitize_key( (string) ( $field_def['type'] ?? 'text' ) );
				if ( $free_restrictions && $field_type === 'file' ) {
					continue;
				}
				$custom_fields_schema[ $field_key ] = $field_type;
				$custom_fields_def[]                = $field_def;
			}
			if ( ! empty( $payload['custom_fields'] ) && is_string( $payload['custom_fields'] ) ) {
				$decoded = json_decode( $payload['custom_fields'], true );
				if ( is_array( $decoded ) ) {
					$payload['custom_fields'] = $decoded;
				}
			}
			if ( ! empty( $payload['custom_fields'] ) && is_array( $payload['custom_fields'] ) ) {
				foreach ( $payload['custom_fields'] as $key => $value ) {
					$field_key = sanitize_key( (string) $key );
					if ( $field_key === '' || ! array_key_exists( $field_key, $custom_fields_schema ) ) {
						continue;
					}
					if ( $custom_fields_schema[ $field_key ] === 'file' ) {
						continue;
					}
					if ( is_array( $value ) ) {
						$custom_fields[ $field_key ] = array_map( 'sanitize_text_field', $value );
					} else {
						$custom_fields[ $field_key ] = sanitize_text_field( $value );
					}
				}
			}

			$first_name = sanitize_text_field( $payload['first_name'] ?? '' );
			$last_name  = sanitize_text_field( $payload['last_name'] ?? '' );
			$full_name  = trim( $first_name . ' ' . $last_name );
			if ( ! $full_name ) {
				$full_name = sanitize_text_field( $payload['name'] ?? '' );
			}
			$guest_emails = array_values( array_filter( array_map( 'sanitize_email', self::normalize_guests( $payload['guests'] ?? array() ) ) ) );

			$settings      = get_option( 'litecal_settings', array() );
			$currency      = $event->currency ?: ( $settings['currency'] ?? 'CLP' );
			$event_options = json_decode( $event->options ?: '[]', true );
			$allow_guests  = ! $free_restrictions && ! empty( $event_options['allow_guests'] );
				if ( ! $allow_guests ) {
					$guest_emails = array();
				} else {
					$max_guests = max( 1, min( self::MAX_GUESTS_PER_BOOKING, (int) ( $event_options['max_guests'] ?? 0 ) ) );
					if ( $max_guests > 0 && count( $guest_emails ) > $max_guests ) {
						$guest_emails = array_slice( $guest_emails, 0, $max_guests );
					}
				}
			$notice_hours  = max( 0, (int) ( $event_options['notice_hours'] ?? 0 ) );
			$limit_per_day = max( 0, (int) ( $event_options['limit_per_day'] ?? 0 ) );
			$future_days   = max( 0, (int) ( $event_options['future_days'] ?? 0 ) );

			$tz       = Availability::resolve_timezone( $event );
			$start_dt = \DateTimeImmutable::createFromFormat( 'Y-m-d H:i:s', $start, $tz );
			if ( ! $start_dt ) {
				$start_dt = \DateTimeImmutable::createFromFormat( 'Y-m-d H:i', $start, $tz );
			}
			$end_dt = \DateTimeImmutable::createFromFormat( 'Y-m-d H:i:s', $end, $tz );
			if ( ! $end_dt ) {
				$end_dt = \DateTimeImmutable::createFromFormat( 'Y-m-d H:i', $end, $tz );
			}
			if ( ! $start_dt || ! $end_dt ) {
				return self::rest_error( 'invalid_datetime', 'Fecha u hora inválida.', array( 'status' => 400 ) );
			}
			$start_ts = $start_dt->getTimestamp();
			$end_ts   = $end_dt->getTimestamp();
			if ( $end_ts <= $start_ts ) {
				return self::rest_error( 'invalid_datetime', 'El rango horario no es válido.', array( 'status' => 400 ) );
			}
			if ( $notice_hours > 0 ) {
				$now_ts = ( new \DateTimeImmutable( 'now', $tz ) )->getTimestamp();
				$min_ts = $now_ts + ( $notice_hours * 3600 );
				if ( $start_ts < $min_ts ) {
					return self::rest_error( 'notice', 'La reserva requiere mayor anticipación.', array( 'status' => 400 ) );
				}
			}
			if ( $future_days > 0 ) {
				$max_date = ( new \DateTimeImmutable( 'now', $tz ) )->modify( '+' . $future_days . ' days' )->format( 'Y-m-d' );
				if ( wp_date( 'Y-m-d', $start_ts, $tz ) > $max_date ) {
					return self::rest_error( 'future_limit', 'La fecha supera el máximo permitido.', array( 'status' => 400 ) );
				}
			}
			$start_date = wp_date( 'Y-m-d', $start_ts, $tz );
			$start_hm   = wp_date( 'H:i', $start_ts, $tz );
			$end_hm     = wp_date( 'H:i', $end_ts, $tz );

			// Server-side slot validation to prevent bypassing buffer/notice/availability rules.
			$available_slots = Availability::get_slots( $event, $employee_id, $start_date );
			$matched_slot    = null;
			foreach ( (array) $available_slots as $slot_item ) {
				if ( ( $slot_item['start'] ?? '' ) === $start_hm && ( $slot_item['end'] ?? '' ) === $end_hm ) {
					$matched_slot = $slot_item;
					break;
				}
			}
			if ( ! $matched_slot || ( $matched_slot['status'] ?? '' ) !== 'available' ) {
				return self::rest_error( 'slot_unavailable', 'El horario seleccionado ya no está disponible.', array( 'status' => 409 ) );
			}

			if ( $limit_per_day > 0 ) {
				$count        = 0;
				$day_bookings = Bookings::by_date( $event_id, $employee_id, $start_date );
				foreach ( $day_bookings as $booking ) {
					if ( ! in_array( $booking->status, array( 'cancelled', 'deleted', 'expired' ), true ) ) {
						++$count;
					}
				}
				if ( $count >= $limit_per_day ) {
					return self::rest_error( 'daily_limit', 'Se alcanzó el límite diario para este servicio.', array( 'status' => 400 ) );
				}
			}
			$price_mode           = $event_options['price_mode'] ?? 'total';
			$partial_percent      = (int) ( $event_options['partial_percent'] ?? 30 );
			$partial_fixed_amount = (float) ( $event_options['partial_fixed_amount'] ?? 0 );
			$price_regular        = $event_options['price_regular'] ?? ( $event->price ?? 0 );
			$price_sale           = $event_options['price_sale'] ?? '';
			if ( ! in_array( $partial_percent, array( 10, 20, 30, 40, 50, 60, 70, 80, 90 ), true ) ) {
				$partial_percent = 30;
			}

			$base_price = max( 0, (float) $event->price );
			$extras_cfg = \LiteCal\Admin\Admin::event_extras_config( $event_options, $currency );
			if ( ! is_array( $extras_cfg ) ) {
				$extras_cfg = array();
			}
			$raw_extras_items = $payload['extras_items'] ?? array();
			if ( is_string( $raw_extras_items ) ) {
				$raw_extras_items = explode( ',', $raw_extras_items );
			}
			if ( ! is_array( $raw_extras_items ) ) {
				$raw_extras_items = array();
			}
			$selected_extra_ids = array_values(
				array_unique(
					array_filter(
						array_map(
							static function ( $item ) {
								return sanitize_key( (string) $item );
							},
							(array) $raw_extras_items
						)
					)
				)
			);
			$extras_map = array();
			foreach ( (array) ( $extras_cfg['items'] ?? array() ) as $extra_item ) {
				if ( ! is_array( $extra_item ) ) {
					continue;
				}
				$extra_id = sanitize_key( (string) ( $extra_item['id'] ?? '' ) );
				if ( $extra_id === '' ) {
					continue;
				}
				$extras_map[ $extra_id ] = array(
					'id'    => $extra_id,
					'name'  => sanitize_text_field( (string) ( $extra_item['name'] ?? '' ) ),
					'price' => max( 0, (float) ( $extra_item['price'] ?? 0 ) ),
					'image' => esc_url_raw( (string) ( $extra_item['image'] ?? '' ) ),
				);
			}
			$selected_extra_items = array();
			$extras_items_total   = 0.0;
			foreach ( $selected_extra_ids as $extra_id ) {
				if ( ! isset( $extras_map[ $extra_id ] ) ) {
					continue;
				}
				$item = $extras_map[ $extra_id ];
				$extras_items_total     += (float) $item['price'];
				$selected_extra_items[] = $item;
			}
			$extras_items_total = round( $extras_items_total, 2 );

			$extras_hours_cfg = is_array( $extras_cfg['hours'] ?? null ) ? $extras_cfg['hours'] : array();
			$extras_hours_enabled = ! empty( $extras_hours_cfg['enabled'] );
			$extras_hours_units = 0;
			$extras_hours_price = max( 0, (float) ( $extras_hours_cfg['price_per_interval'] ?? 0 ) );
			$extras_hours_max   = max( 1, (int) ( $extras_hours_cfg['max_units'] ?? 8 ) );
			if ( $extras_hours_enabled ) {
				$extras_hours_units = absint( $payload['extras_hours_units'] ?? 0 );
				$extras_hours_units = min( $extras_hours_units, $extras_hours_max );
			}
			$extras_hours_total = round( $extras_hours_units * $extras_hours_price, 2 );
			$extras_total       = round( $extras_items_total + $extras_hours_total, 2 );
			$service_total      = round( $base_price + $extras_total, 2 );

			$charge_amount = $service_total;
			if ( $price_mode === 'partial_percent' ) {
				$charge_amount = round( $service_total * ( $partial_percent / 100 ), 2 );
			}
			if ( $price_mode === 'partial_fixed' && $partial_fixed_amount > 0 ) {
				$charge_amount = min( $service_total, round( $partial_fixed_amount + $extras_total, 2 ) );
			}
			if ( $price_mode === 'free' ) {
				$charge_amount = 0;
			}
			$charge_amount = max( 0, (float) $charge_amount );
			$requires_payment = ( ! empty( $event->require_payment ) && $price_mode !== 'onsite' && (float) $charge_amount > 0 );
			$abuse_guard      = self::enforce_booking_abuse_guard(
				$event,
				$email,
				$client_phone_raw,
				$client_device_id,
				$client_ip,
				$client_user_agent,
				$price_mode,
				(float) $charge_amount,
				$payload['abuse_code'] ?? ''
			);
			if ( is_wp_error( $abuse_guard ) ) {
				return $abuse_guard;
			}

			$employee = $employee_id ? Employees::get( $employee_id ) : null;
			$snapshot = array(
				'event'             => array(
					'id'                   => (int) $event->id,
					'title'                => $event->title ?? '',
					'slug'                 => $event->slug ?? '',
					'description'          => wp_strip_all_tags( $event->description ?? '' ),
					'price'                => $base_price,
					'service_total'        => $service_total,
					'extras_total'         => $extras_total,
					'currency'             => $currency,
					'price_mode'           => $price_mode,
					'price_regular'        => $price_regular,
					'price_sale'           => $price_sale,
					'partial_percent'      => $partial_percent,
					'partial_fixed_amount' => $partial_fixed_amount,
					'location'             => $event->location ?? '',
					'location_details'     => $event->location_details ?? '',
					'duration'             => (int) $event->duration,
					'gap_between_bookings' => Availability::resolve_gap_minutes( $event, $event_options ),
					'buffer_before'        => 0,
					'buffer_after'         => 0,
					'timezone'             => \LiteCal\Core\Helpers::site_timezone_name(),
				),
				'employee'          => $employee ? array(
					'id'         => (int) $employee->id,
					'name'       => $employee->name,
					'email'      => $employee->email,
					'title'      => $employee->title ?? '',
					'avatar_url' => Helpers::avatar_url( (string) ( $employee->avatar_url ?? '' ), 'thumbnail' ),
				) : null,
				'custom_fields_def' => $custom_fields_def,
				'booking'           => array(
					'start'      => $start,
					'end'        => $end,
					'first_name' => $first_name,
					'last_name'  => $last_name,
					'email'      => $email,
					'phone'      => $client_phone_raw,
					'device_id'  => $client_device_id,
					'ip_prefix'  => $client_ip_prefix,
					'ua_key'     => $client_ua_key,
					'network_key' => $client_network,
					'guests'     => $guest_emails,
					'created_at' => current_time( 'mysql' ),
				),
				'extras'            => array(
					'items'       => $selected_extra_items,
					'items_total' => $extras_items_total,
					'hours'       => array(
						'enabled'            => $extras_hours_enabled ? 1 : 0,
						'label'              => sanitize_text_field( (string) ( $extras_hours_cfg['label'] ?? __( 'Horas extra', 'agenda-lite' ) ) ),
						'interval_minutes'   => max( 5, (int) ( $extras_hours_cfg['interval_minutes'] ?? 15 ) ),
						'price_per_interval' => $extras_hours_price,
						'units'              => $extras_hours_units,
						'total'              => $extras_hours_total,
					),
					'total'       => $extras_total,
				),
			);

			$booking_id    = Bookings::create(
				array(
					'event_id'          => $event_id,
					'employee_id'       => $employee_id ?: null,
					'start_datetime'    => $start,
					'end_datetime'      => $end,
					'name'              => $full_name,
					'email'             => $email,
					'phone'             => $client_phone_raw,
					'company'           => sanitize_text_field( $payload['company'] ?? '' ),
					'message'           => sanitize_textarea_field( $payload['message'] ?? '' ),
					'guests'            => wp_json_encode( $guest_emails ),
					'custom_fields'     => wp_json_encode( $custom_fields ),
					'status'            => $requires_payment ? 'pending' : 'confirmed',
					'payment_status'    => 'unpaid',
					'payment_provider'  => null,
					'payment_reference' => null,
					'payment_amount'    => $requires_payment ? $charge_amount : 0,
					'payment_currency'  => $currency,
					'snapshot'          => wp_json_encode( $snapshot ),
				)
			);
			$booking_token = self::booking_access_token( $booking_id );

			$file_errors = '';
			$file_meta   = array();
			if ( ! empty( $custom_fields_def ) && ! $free_restrictions ) {
				self::private_upload_base_dir();
				add_filter( 'upload_dir', array( self::class, 'private_upload_dir' ) );
				try {
					foreach ( $custom_fields_def as $field ) {
						$enabled = ! isset( $field['enabled'] ) || $field['enabled'];
						if ( ! $enabled || ( $field['type'] ?? '' ) !== 'file' ) {
							continue;
						}
						$key = $field['key'] ?? '';
						if ( ! $key ) {
							continue;
						}
						$rules      = self::file_rules_from_field( $field );
						$input_name = 'file_' . $key;
						$files      = isset( $files_payload[ $input_name ] ) ? self::normalize_files( $files_payload[ $input_name ] ) : array();
						if ( empty( $files ) && ! empty( $rules['required'] ) ) {
							$file_errors = 'Debes adjuntar un archivo.';
							break;
						}
						if ( ! empty( $files ) && count( $files ) > $rules['max_files'] ) {
							$file_errors = 'Se excede el máximo de archivos permitidos.';
							break;
						}
						$saved = array();
						foreach ( $files as $index => $file ) {
							if ( ! empty( $file['error'] ) && (int) $file['error'] !== UPLOAD_ERR_OK ) {
								$file_errors = 'No se pudo subir el archivo.';
								break 2;
							}
							if ( ! empty( $rules['max_size'] ) && (int) $file['size'] > $rules['max_size'] ) {
								$file_errors = 'Archivo demasiado grande. Máximo: ' . $rules['max_mb'] . ' MB.';
								break 2;
							}
							if ( ! self::file_is_allowed( $file, $rules['exts'], $rules['mimes'] ) ) {
								$file_errors = 'Archivo no permitido. Solo: ' . $rules['label'];
								break 2;
							}
							$upload = wp_handle_upload(
								$file,
								array(
									'test_form' => false,
									'unique_filename_callback' => function ( $dir, $name, $ext ) use ( $booking_id, $key, $index ) {
										$safe = sanitize_key( $key );
										$rand = wp_generate_password( 6, false, false );
										return 'booking_' . $booking_id . '_' . $safe . '_' . $index . '_' . $rand . $ext;
									},
								)
							);
							if ( isset( $upload['error'] ) ) {
								$file_errors = $upload['error'];
								break 2;
							}
							$stored_name = sanitize_file_name( (string) basename( (string) ( $upload['file'] ?? '' ) ) );
							if ( $stored_name === '' ) {
								$file_errors = 'No se pudo guardar el archivo.';
								break 2;
							}
							$check = wp_check_filetype_and_ext( (string) $upload['file'], (string) $file['name'] );
							$ext   = strtolower( $check['ext'] ?? '' );
							$mime  = strtolower( $check['type'] ?? ( $upload['type'] ?? '' ) );
							if ( ! $ext ) {
								$file_errors = 'Archivo no permitido.';
								break 2;
							}
							$file_token = self::booking_file_token( $booking_id, $key, $stored_name );
							$saved[]    = array(
								'original_name' => sanitize_file_name( (string) $file['name'] ),
								'stored_name'   => $stored_name,
								'token'         => $file_token,
								'url'           => self::booking_file_url( $booking_id, $file_token, $booking_token ),
								'size'          => (int) $file['size'],
								'mime'          => $mime,
								'ext'           => $ext,
							);
						}
						if ( $saved ) {
							$file_meta[ $key ]     = $saved;
							$custom_fields[ $key ] = $saved;
						}
					}
				} finally {
					remove_filter( 'upload_dir', array( self::class, 'private_upload_dir' ) );
				}
			}

			if ( ! empty( $file_errors ) ) {
				Bookings::delete_permanent( $booking_id );
				return self::rest_error( 'file_error', $file_errors, array( 'status' => 400 ) );
			}

			if ( $file_meta ) {
				$snapshot['files'] = $file_meta;
				global $wpdb;
				$table = $wpdb->prefix . 'litecal_bookings';
				$wpdb->update(
					$table,
					array(
						'custom_fields' => wp_json_encode( $custom_fields ),
						'snapshot'      => wp_json_encode( $snapshot ),
					),
					array( 'id' => $booking_id )
				);
			}

			$payment_url        = '';
			$payment_provider   = '';
			$payment_reference  = '';
			$payment_currency   = strtoupper( (string) $currency );
			$amount             = (float) $charge_amount;
			$requested_provider = '';
			$payment            = array();
			$is_manual_transfer = false;
			if ( $requires_payment ) {
				$integrations       = get_option( 'litecal_integrations', array() );
				$requested_provider = sanitize_text_field( $payload['payment_provider'] ?? '' );
				$available_methods  = \LiteCal\Admin\Admin::payment_methods_for_event( $currency, $event_options, $charge_amount );
				$available_map      = array();
				foreach ( $available_methods as $method ) {
					if ( empty( $method['key'] ) ) {
						continue;
					}
					$available_map[ $method['key'] ] = $method;
				}
				$provider_key = '';
				if ( $requested_provider !== '' && isset( $available_map[ $requested_provider ] ) ) {
					$provider_key = $requested_provider;
				} elseif ( ! empty( $available_methods ) ) {
					$provider_key = (string) ( $available_methods[0]['key'] ?? '' );
				}
				if ( $provider_key === '' || empty( $available_map[ $provider_key ] ) ) {
					Bookings::delete_permanent( $booking_id );
					return self::rest_error( 'payment_error', 'No hay medios de pago disponibles para esta moneda.', array( 'status' => 400 ) );
				}
				if ( $provider_key !== 'transfer' && ! self::provider_feature_enabled( $provider_key ) ) {
					Bookings::delete_permanent( $booking_id );
					return self::rest_error( 'payment_error', 'Este medio de pago requiere Agenda Lite Pro.', array( 'status' => 400 ) );
				}
				$provider_currency = strtoupper( (string) ( $available_map[ $provider_key ]['charge_currency'] ?? $currency ) );
				$provider_amount   = (float) ( $available_map[ $provider_key ]['charge_amount'] ?? \LiteCal\Admin\Admin::convert_from_global_for_provider( $charge_amount, $currency, $provider_key ) );
				if ( $provider_amount <= 0 ) {
					Bookings::delete_permanent( $booking_id );
					return self::rest_error( 'payment_error', 'No se pudo calcular el monto para el medio de pago seleccionado.', array( 'status' => 400 ) );
				}

				if ( $provider_key === 'flow' ) {
					if ( $provider_currency !== 'CLP' ) {
						Bookings::delete_permanent( $booking_id );
						return self::rest_error( 'payment_error', 'Flow solo admite CLP.', array( 'status' => 400 ) );
					}
					$provider = new FlowProvider();
					$amount   = $provider_amount;
					if ( $amount > 0 ) {
						$payment = $provider->create_payment(
							array(
								'id'         => $booking_id,
								'email'      => sanitize_email( $payload['email'] ?? '' ),
								'name'       => $full_name,
								'first_name' => $first_name,
								'last_name'  => $last_name,
								'phone'      => $client_phone_raw,
							),
							array(
								'title'         => $event->title,
								'price'         => (float) $amount,
								'currency'      => $provider_currency,
								'slug'          => $event->slug,
								'booking_token' => $booking_token,
							)
						);
						if ( ! empty( $payment['payment_url'] ) ) {
							$payment_url       = $payment['payment_url'];
							$payment_provider  = 'flow';
							$payment_reference = sanitize_text_field( $payment['token'] ?? '' );
							$payment_currency  = $provider_currency;
							Bookings::update_payment(
								$booking_id,
								array(
									'payment_status'    => 'pending',
									'payment_provider'  => 'flow',
									'payment_reference' => $payment_reference,
									'payment_currency'  => $provider_currency,
									'payment_amount'    => $amount,
								)
							);
						} elseif ( ! empty( $payment['error'] ) ) {
							Bookings::update_payment(
								$booking_id,
								array(
									'payment_status'    => 'unpaid',
									'payment_provider'  => null,
									'payment_reference' => null,
									'payment_voided'    => 1,
									'payment_error'     => sanitize_text_field( $payment['error'] ),
								)
							);
							self::debug_log(
								'flow payment create failed',
								array(
									'booking_id' => (string) $booking_id,
									'provider'   => 'flow',
								)
							);
							Bookings::delete_permanent( $booking_id );
							return self::rest_error( 'payment_error', $payment['error'], array( 'status' => 400 ) );
						} else {
							Bookings::delete_permanent( $booking_id );
							return self::rest_error( 'payment_error', 'No se pudo iniciar el pago.', array( 'status' => 400 ) );
						}
					}
				} elseif ( $provider_key === 'mp' ) {
					if ( $provider_currency !== 'CLP' ) {
						Bookings::delete_permanent( $booking_id );
						return self::rest_error( 'payment_error', 'Mercado Pago solo admite CLP en este sitio.', array( 'status' => 400 ) );
					}
					$provider = new MercadoPagoProvider();
					$amount   = $provider_amount;
					if ( $amount > 0 ) {
						$return_url       = add_query_arg(
							array(
								'agendalite_payment' => 'mp',
								'booking_id'         => $booking_id,
								'booking_token'      => $booking_token,
							),
							home_url( '/' . trim( $event->slug, '/' ) )
						);
						$notification_url = home_url( '/wp-json/litecal/v1/payments/webhook?provider=mp' );
						$payment          = $provider->create_payment(
							array(
								'id'    => $booking_id,
								'email' => sanitize_email( $payload['email'] ?? '' ),
								'name'  => $full_name,
							),
							array(
								'title'            => $event->title,
								'price'            => (float) $amount,
								'currency'         => $provider_currency,
								'slug'             => $event->slug,
								'return_url'       => $return_url,
								'notification_url' => $notification_url,
							)
						);
						if ( ! empty( $payment['payment_url'] ) ) {
							$payment_url       = $payment['payment_url'];
							$payment_provider  = 'mp';
							$payment_reference = sanitize_text_field( $payment['preference_id'] ?? '' );
							$payment_currency  = $provider_currency;
							Bookings::update_payment(
								$booking_id,
								array(
									'payment_status'    => 'pending',
									'payment_provider'  => 'mp',
									'payment_reference' => $payment_reference,
									'payment_currency'  => $provider_currency,
									'payment_amount'    => $amount,
								)
							);
						} elseif ( ! empty( $payment['error'] ) ) {
							Bookings::update_payment(
								$booking_id,
								array(
									'payment_status'    => 'unpaid',
									'payment_provider'  => null,
									'payment_reference' => null,
									'payment_voided'    => 1,
									'payment_error'     => sanitize_text_field( $payment['error'] ),
								)
							);
							self::debug_log(
								'mercadopago payment create failed',
								array(
									'booking_id' => (string) $booking_id,
									'provider'   => 'mp',
								)
							);
							Bookings::delete_permanent( $booking_id );
							return self::rest_error( 'payment_error', $payment['error'], array( 'status' => 400 ) );
						} else {
							Bookings::delete_permanent( $booking_id );
							return self::rest_error( 'payment_error', 'No se pudo iniciar el pago.', array( 'status' => 400 ) );
						}
					}
				} elseif ( $provider_key === 'webpay' ) {
					if ( $provider_currency !== 'CLP' ) {
						Bookings::delete_permanent( $booking_id );
						return self::rest_error( 'payment_error', 'Webpay Plus solo admite CLP.', array( 'status' => 400 ) );
					}
					$provider = new WebpayPlusProvider();
					$amount   = $provider_amount;
					if ( $amount > 0 ) {
						$return_url = add_query_arg(
							array(
								'agendalite_payment' => 'webpay',
								'booking_id'         => $booking_id,
								'booking_token'      => $booking_token,
							),
							home_url( '/' . trim( $event->slug, '/' ) )
						);
						$payment    = $provider->create_payment(
							array(
								'id'         => $booking_id,
								'buy_order'  => (string) $booking_id,
								'session_id' => (string) $booking_id,
							),
							array(
								'title'      => $event->title,
								'price'      => (float) $amount,
								'currency'   => $provider_currency,
								'slug'       => $event->slug,
								'return_url' => $return_url,
							)
						);
						if ( ! empty( $payment['payment_url'] ) && ! empty( $payment['token'] ) ) {
							$gateway_url       = $payment['payment_url'];
							$token             = $payment['token'];
							$payment_url       = add_query_arg(
								array(
									'litecal_webpay' => 1,
									'token_ws'       => $token,
									'url'            => rawurlencode( $gateway_url ),
								),
								home_url( '/' )
							);
							$payment_provider  = 'webpay';
							$payment_reference = sanitize_text_field( $token );
							$payment_currency  = $provider_currency;
							Bookings::update_payment(
								$booking_id,
								array(
									'payment_status'    => 'pending',
									'payment_provider'  => 'webpay',
									'payment_reference' => $payment_reference,
									'payment_currency'  => $provider_currency,
									'payment_amount'    => $amount,
								)
							);
						} elseif ( ! empty( $payment['error'] ) ) {
							Bookings::update_payment(
								$booking_id,
								array(
									'payment_status'    => 'unpaid',
									'payment_provider'  => null,
									'payment_reference' => null,
									'payment_voided'    => 1,
									'payment_error'     => sanitize_text_field( $payment['error'] ),
								)
							);
							self::debug_log(
								'webpay payment create failed',
								array(
									'booking_id' => (string) $booking_id,
									'provider'   => 'webpay',
								)
							);
							Bookings::delete_permanent( $booking_id );
							return self::rest_error( 'payment_error', $payment['error'], array( 'status' => 400 ) );
						} else {
							Bookings::delete_permanent( $booking_id );
							return self::rest_error( 'payment_error', 'No se pudo iniciar el pago.', array( 'status' => 400 ) );
						}
					}
				} elseif ( $provider_key === 'paypal' ) {
					if ( ! \LiteCal\Admin\Admin::paypal_supported_currency( $provider_currency ) ) {
						Bookings::delete_permanent( $booking_id );
						return self::rest_error( 'payment_error', 'PayPal no soporta la moneda seleccionada.', array( 'status' => 400 ) );
					}
					$provider = new PayPalProvider();
					$amount   = $provider_amount;
					if ( $amount > 0 ) {
						$return_url = add_query_arg(
							array(
								'agendalite_payment' => 'paypal',
								'booking_id'         => $booking_id,
								'booking_token'      => $booking_token,
							),
							home_url( '/' . trim( $event->slug, '/' ) )
						);
						$cancel_url = add_query_arg(
							array(
								'agendalite_payment' => 'paypal',
								'booking_id'         => $booking_id,
								'booking_token'      => $booking_token,
								'cancelled'          => '1',
							),
							home_url( '/' . trim( $event->slug, '/' ) )
						);
						$payment    = $provider->create_payment(
							array(
								'id'         => $booking_id,
								'email'      => sanitize_email( $payload['email'] ?? '' ),
								'name'       => $full_name,
								'first_name' => $first_name,
								'last_name'  => $last_name,
								'phone'      => $client_phone_raw,
							),
							array(
								'title'      => $event->title,
								'price'      => (float) $amount,
								'currency'   => $provider_currency,
								'slug'       => $event->slug,
								'return_url' => $return_url,
								'cancel_url' => $cancel_url,
							)
						);
						if ( ! empty( $payment['payment_url'] ) ) {
							$payment_url       = $payment['payment_url'];
							$payment_provider  = 'paypal';
							$payment_reference = sanitize_text_field( $payment['order_id'] ?? '' );
							$payment_currency  = $provider_currency;
							Bookings::update_payment(
								$booking_id,
								array(
									'payment_status'    => 'pending',
									'payment_provider'  => 'paypal',
									'payment_reference' => $payment_reference,
									'payment_currency'  => $provider_currency,
									'payment_amount'    => $amount,
								)
							);
						} elseif ( ! empty( $payment['error'] ) ) {
							Bookings::update_payment(
								$booking_id,
								array(
									'payment_status'    => 'unpaid',
									'payment_provider'  => null,
									'payment_reference' => null,
									'payment_voided'    => 1,
									'payment_error'     => sanitize_text_field( $payment['error'] ),
								)
							);
							self::debug_log(
								'paypal payment create failed',
								array(
									'booking_id' => (string) $booking_id,
									'provider'   => 'paypal',
								)
							);
							Bookings::delete_permanent( $booking_id );
							return self::rest_error( 'payment_error', $payment['error'], array( 'status' => 400 ) );
						} else {
							Bookings::delete_permanent( $booking_id );
							return self::rest_error( 'payment_error', 'No se pudo iniciar el pago.', array( 'status' => 400 ) );
						}
					}
				} elseif ( $provider_key === 'stripe' ) {
					$provider = new StripeProvider();
					$amount   = $provider_amount;
					if ( $amount > 0 ) {
						$success_url = add_query_arg(
							array(
								'agendalite_payment' => 'stripe',
								'booking_id'         => $booking_id,
								'booking_token'      => $booking_token,
								'session_id'         => '{CHECKOUT_SESSION_ID}',
							),
							home_url( '/' . trim( $event->slug, '/' ) )
						);
						$cancel_url  = add_query_arg(
							array(
								'agendalite_payment' => 'stripe',
								'booking_id'         => $booking_id,
								'booking_token'      => $booking_token,
								'cancelled'          => '1',
								'session_id'         => '{CHECKOUT_SESSION_ID}',
							),
							home_url( '/' . trim( $event->slug, '/' ) )
						);
						$payment     = $provider->create_payment(
							array(
								'id'    => $booking_id,
								'email' => sanitize_email( $payload['email'] ?? '' ),
								'name'  => $full_name,
							),
							array(
								'event_id'    => (int) $event->id,
								'title'       => $event->title,
								'description' => wp_strip_all_tags( (string) ( $event->description ?? '' ) ),
								'price'       => (float) $amount,
								'currency'    => $provider_currency,
								'slug'        => $event->slug,
								'success_url' => $success_url,
								'cancel_url'  => $cancel_url,
							)
						);
						if ( ! empty( $payment['payment_url'] ) ) {
							$payment_url       = $payment['payment_url'];
							$payment_provider  = 'stripe';
							$payment_reference = sanitize_text_field( $payment['session_id'] ?? '' );
							$payment_currency  = $provider_currency;
							Bookings::update_payment(
								$booking_id,
								array(
									'payment_status'    => 'pending',
									'payment_provider'  => 'stripe',
									'payment_reference' => $payment_reference,
									'payment_currency'  => $provider_currency,
									'payment_amount'    => $amount,
									'payment_error'     => '',
								)
							);
						} elseif ( ! empty( $payment['error'] ) ) {
							Bookings::update_payment(
								$booking_id,
								array(
									'payment_status'    => 'unpaid',
									'payment_provider'  => null,
									'payment_reference' => null,
									'payment_voided'    => 1,
									'payment_error'     => sanitize_text_field( $payment['error'] ),
								)
							);
							self::debug_log(
								'stripe payment create failed',
								array(
									'booking_id' => (string) $booking_id,
									'provider'   => 'stripe',
								)
							);
							Bookings::delete_permanent( $booking_id );
							return self::rest_error( 'payment_error', $payment['error'], array( 'status' => 400 ) );
						} else {
							Bookings::delete_permanent( $booking_id );
							return self::rest_error( 'payment_error', 'No se pudo iniciar el pago.', array( 'status' => 400 ) );
						}
					}
				} elseif ( $provider_key === 'transfer' ) {
					$is_manual_transfer   = true;
					$amount               = $provider_amount;
					$payment_provider     = 'transfer';
					$payment_currency     = $provider_currency;
					$payment_reference    = strtoupper( 'TR-' . wp_generate_password( 10, false, false ) );
					$payment_url          = add_query_arg(
						array(
							'agendalite_payment' => 'transfer',
							'booking_id'         => $booking_id,
							'booking_token'      => $booking_token,
						),
						home_url( '/' . trim( $event->slug, '/' ) )
					);
					$transfer_details     = self::transfer_details_from_options( $integrations, $provider_currency );
					$snapshot['transfer'] = $transfer_details;
					global $wpdb;
					$table = $wpdb->prefix . 'litecal_bookings';
					$wpdb->update(
						$table,
						array(
							'snapshot' => wp_json_encode( $snapshot ),
						),
						array( 'id' => $booking_id )
					);
					Bookings::update_payment(
						$booking_id,
						array(
							'payment_status'    => 'pending',
							'payment_provider'  => 'transfer',
							'payment_reference' => $payment_reference,
							'payment_currency'  => $provider_currency,
							'payment_amount'    => $amount,
							'payment_error'     => '',
						)
					);
				}
			}

			if ( $price_mode === 'onsite' && (float) $service_total > 0 ) {
				$amount           = (float) $service_total;
				$payment_provider = 'onsite';
				Bookings::update_payment(
					$booking_id,
					array(
						'payment_status'    => 'unpaid',
						'payment_provider'  => 'onsite',
						'payment_reference' => null,
						'payment_currency'  => $currency,
						'payment_amount'    => $amount,
						'payment_error'     => '',
					)
				);
			}

			$payload['status']            = $requires_payment ? 'pending' : 'confirmed';
			$payload['payment_status']    = $requires_payment ? ( $payment_url ? 'pending' : 'unpaid' ) : 'unpaid';
			$payload['payment_provider']  = $requires_payment ? ( $payment_provider ?: $requested_provider ) : ( $price_mode === 'onsite' && (float) $service_total > 0 ? 'onsite' : '' );
			$payload['payment_amount']    = $requires_payment ? (float) $amount : ( $price_mode === 'onsite' && (float) $service_total > 0 ? (float) $service_total : 0.0 );
			$payload['payment_currency']  = $payment_currency;
			$payload['payment_reference'] = $requires_payment ? ( $payment_reference ?: '' ) : '';
			$payload['service_total']     = (float) $service_total;
			$payload['extras_total']      = (float) $extras_total;
			$payload['extras_items']      = $selected_extra_items;
			$payload['extras_hours_units'] = (int) $extras_hours_units;
			if ( $employee ) {
				$payload['employee'] = array(
					'id'    => (int) $employee->id,
					'name'  => $employee->name,
					'email' => $employee->email,
				);
			}
			$payload['event_title'] = $snapshot['event']['title'] ?? ( $event->title ?? '' );
			if ( $requires_payment ) {
				if ( $is_manual_transfer ) {
					try {
						self::notify_booking_status( $booking_id, 'pending', true );
					} catch ( \Throwable $e ) {
						self::debug_log(
							'notify_booking_status_exception',
							array(
								'booking_id' => (string) $booking_id,
								'status'     => 'pending',
								'error'      => (string) $e->getMessage(),
							)
						);
					}
				}
			} else {
				try {
					self::notify_booking_status( $booking_id, 'confirmed', true );
				} catch ( \Throwable $e ) {
					self::debug_log(
						'notify_booking_status_exception',
						array(
							'booking_id' => (string) $booking_id,
							'status'     => 'confirmed',
							'error'      => (string) $e->getMessage(),
						)
					);
				}
			}
			try {
				\LiteCal\Modules\Calendar\GoogleCalendar\GoogleCalendarModule::sync_booking(
					$booking_id,
					$requires_payment ? 'pending' : 'confirmed'
				);
			} catch ( \Throwable $e ) {
				self::debug_log(
					'create_booking_sync_exception',
					array(
						'booking_id' => (string) $booking_id,
						'status'     => $requires_payment ? 'pending' : 'confirmed',
						'error'      => (string) $e->getMessage(),
					)
				);
			}

			return self::rest_response(
				'booking_created',
				'Reserva creada correctamente.',
				$requires_payment ? 'pending' : 'confirmed',
				array(
					'booking_id'                 => $booking_id,
					'booking_token'              => $booking_token,
					'status'                     => $requires_payment ? 'pending' : 'confirmed',
					'payment_url'                => $payment_url,
					'payment_required'           => $requires_payment,
					'payment_error'              => ( ! empty( $payment['error'] ) ? sanitize_text_field( $payment['error'] ) : '' ),
					'payment_provider'           => $requires_payment ? ( $payment_provider ?: $requested_provider ) : '',
					'payment_reference'          => $requires_payment ? ( $payment_reference ?: '' ) : '',
					'payment_amount'             => $requires_payment ? (float) $amount : 0.0,
					'payment_currency'           => $payment_currency,
					'payment_expiration_minutes' => $is_manual_transfer ? 0 : 10,
					'transfer'                   => $is_manual_transfer ? ( $snapshot['transfer'] ?? array() ) : array(),
				)
			);
		} finally {
			self::release_lock( $slot_lock_key );
		}
	}

	public static function payments_webhook( $request ) {
		$provider = sanitize_text_field( $request->get_param( 'provider' ) );
		if ( $provider === 'stripe' ) {
			if ( ! self::provider_feature_enabled( 'stripe' ) ) {
				return self::webhook_response( 'ignored' );
			}
			return self::stripe_webhook( $request );
		}
		if ( $provider !== 'flow' && $provider !== 'paypal' && $provider !== 'mp' && $provider !== 'webpay' ) {
			return self::webhook_response( 'ignored' );
		}
		if ( ! self::provider_feature_enabled( $provider ) ) {
			return self::webhook_response( 'ignored' );
		}
		$limit = self::rate_limit( "webhook:{$provider}", 120, 60 );
		if ( is_wp_error( $limit ) ) {
			return $limit;
		}

		if ( $provider === 'flow' ) {
			$flow_payload = $request->get_params();
			$flow         = new FlowProvider();
			if ( ! $flow->verify_webhook( $flow_payload, $request->get_headers() ) ) {
				return self::webhook_response( 'verification_failed' );
			}
			$token = sanitize_text_field( $request->get_param( 'token' ) );
			if ( empty( $token ) ) {
				return self::webhook_response( 'missing_token' );
			}

			$status = $flow->get_status( $token );
			if ( empty( $status['commerce_order'] ) && empty( $status['commerceOrder'] ) ) {
				return self::webhook_response( 'invalid' );
			}

			$commerce   = $status['commerce_order'] ?? $status['commerceOrder'];
			$booking_id = (int) $commerce;
			if ( $booking_id <= 0 ) {
				return self::webhook_response( 'invalid' );
			}

			$booking = Bookings::get( $booking_id );
			if ( ! $booking ) {
				return self::webhook_response( 'booking_not_found' );
			}
			$lock = self::acquire_lock( "litecal_payhook_flow_{$booking_id}", 30 );
			if ( ! $lock ) {
				return self::webhook_response( 'locked' );
			}
			$current_payment = (string) $booking->payment_status;
			$current_status  = (string) $booking->status;
			if ( $current_payment === 'paid' ) {
				self::release_lock( "litecal_payhook_flow_{$booking_id}" );
				return self::webhook_response( 'ok' );
			}

			$payment_status = 'pending';
			$booking_status = 'pending';
			$flow_status    = (int) ( $status['status'] ?? 0 );
			if ( $flow_status === 2 ) {
				$payment_status = 'paid';
				$booking_status = 'confirmed';
			} elseif ( in_array( $flow_status, array( 3, 4 ), true ) ) {
				$payment_status = 'rejected';
				$booking_status = 'cancelled';
			}
			if ( in_array( $current_payment, array( 'rejected', 'cancelled' ), true ) && $payment_status === 'pending' ) {
				self::release_lock( "litecal_payhook_flow_{$booking_id}" );
				return self::webhook_response( 'ok' );
			}
			if ( $current_payment !== $payment_status ) {
				Bookings::update_payment(
					$booking_id,
					array(
						'payment_status'    => $payment_status,
						'payment_provider'  => 'flow',
						'payment_reference' => $token,
						'payment_error'     => '',
					)
				);
			}
			if ( $current_status !== $booking_status ) {
				Bookings::update_status( $booking_id, $booking_status );
			}
			if ( $booking_status === 'confirmed' && $current_status !== 'confirmed' ) {
				self::notify_booking_status( $booking_id, 'confirmed', true );
			} elseif ( $booking_status === 'cancelled' && $current_status !== 'cancelled' ) {
				self::notify_booking_status( $booking_id, 'payment_failed', true );
			}
			self::release_lock( "litecal_payhook_flow_{$booking_id}" );
			return self::webhook_response( 'ok' );
		}

		if ( $provider === 'mp' ) {
			$payload = $request->get_json_params();
			if ( ! $payload ) {
				$payload = $request->get_params();
			}
			$type       = sanitize_text_field( $payload['type'] ?? $payload['topic'] ?? $request->get_param( 'type' ) ?? $request->get_param( 'topic' ) ?? '' );
			$payment_id = $payload['data']['id'] ?? ( $payload['id'] ?? ( $request->get_param( 'id' ) ?? '' ) );
			$payment_id = sanitize_text_field( (string) $payment_id );
			if ( $payment_id === '' ) {
				return self::webhook_response( 'missing_payment_id' );
			}

			if ( empty( $payload['data'] ) || ! is_array( $payload['data'] ) ) {
				$payload['data'] = array();
			}
			if ( empty( $payload['data']['id'] ) ) {
				$payload['data']['id'] = $payment_id;
			}
			$provider = new MercadoPagoProvider();
			if ( ! $provider->verify_webhook( $payload, $request->get_headers() ) ) {
				return self::webhook_response( 'verification_failed' );
			}
			$payment = $provider->get_payment( $payment_id );
			if ( is_wp_error( $payment ) ) {
				return self::webhook_response( 'payment_lookup_failed' );
			}

			$booking_id = (int) ( $payment['external_reference'] ?? 0 );
			if ( $booking_id <= 0 && ! empty( $payment['metadata']['booking_id'] ) ) {
				$booking_id = (int) $payment['metadata']['booking_id'];
			}
			if ( $booking_id <= 0 ) {
				return self::webhook_response( 'booking_not_found' );
			}

			$booking = Bookings::get( $booking_id );
			if ( ! $booking ) {
				return self::webhook_response( 'booking_not_found' );
			}
			$lock = self::acquire_lock( "litecal_payhook_mp_{$booking_id}", 30 );
			if ( ! $lock ) {
				return self::webhook_response( 'locked' );
			}
			$current_payment = (string) $booking->payment_status;
			$current_status  = (string) $booking->status;
			if ( $current_payment === 'paid' ) {
				self::release_lock( "litecal_payhook_mp_{$booking_id}" );
				return self::webhook_response( 'ok', array( 'type' => $type ) );
			}

			$status         = strtolower( (string) ( $payment['status'] ?? '' ) );
			$payment_status = 'pending';
			$booking_status = 'pending';
			if ( $status === 'approved' ) {
				$payment_status = 'paid';
				$booking_status = 'confirmed';
			} elseif ( in_array( $status, array( 'rejected', 'cancelled', 'charged_back', 'refunded' ), true ) ) {
				$payment_status = 'rejected';
				$booking_status = 'cancelled';
			} elseif ( in_array( $status, array( 'in_process', 'pending' ), true ) ) {
				$payment_status = 'pending';
				$booking_status = 'pending';
			}
			if ( in_array( $current_payment, array( 'rejected', 'cancelled' ), true ) && $payment_status === 'pending' ) {
				self::release_lock( "litecal_payhook_mp_{$booking_id}" );
				return self::webhook_response( 'ok', array( 'type' => $type ) );
			}
			if ( $current_payment !== $payment_status ) {
				Bookings::update_payment(
					$booking_id,
					array(
						'payment_status'    => $payment_status,
						'payment_provider'  => 'mp',
						'payment_reference' => sanitize_text_field( $payment_id ),
						'payment_amount'    => (float) ( $payment['transaction_amount'] ?? 0 ),
						'payment_currency'  => sanitize_text_field( $payment['currency_id'] ?? 'CLP' ),
						'payment_error'     => '',
					)
				);
			}
			if ( $current_status !== $booking_status ) {
				Bookings::update_status( $booking_id, $booking_status );
			}
			if ( $booking_status === 'confirmed' && $current_status !== 'confirmed' ) {
				self::notify_booking_status( $booking_id, 'confirmed', true );
			} elseif ( $booking_status === 'cancelled' && $current_status !== 'cancelled' ) {
				self::notify_booking_status( $booking_id, 'payment_failed', true );
			}
			self::release_lock( "litecal_payhook_mp_{$booking_id}" );
			return self::webhook_response( 'ok', array( 'type' => $type ) );
		}

		if ( $provider === 'webpay' ) {
			$payload = $request->get_json_params();
			if ( ! $payload || ! is_array( $payload ) ) {
				$payload = $request->get_params();
			}
			$webpay = new WebpayPlusProvider();
			if ( ! $webpay->verify_webhook( (array) $payload, $request->get_headers() ) ) {
				return self::webhook_response( 'verification_failed' );
			}
			$token = sanitize_text_field( (string) ( $payload['token_ws'] ?? $payload['token'] ?? $payload['TBK_TOKEN'] ?? $request->get_param( 'token_ws' ) ?? $request->get_param( 'token' ) ?? '' ) );
			if ( $token === '' ) {
				return self::webhook_response( 'missing_token' );
			}
			$commit = $webpay->get_verified_commit( $token );
			if ( ! $commit ) {
				$commit = $webpay->commit( $token );
			}
			if ( is_wp_error( $commit ) || ! is_array( $commit ) ) {
				return self::webhook_response( 'invalid' );
			}

			$booking_id = (int) ( $commit['buy_order'] ?? ( $payload['buy_order'] ?? ( $payload['TBK_ORDEN_COMPRA'] ?? 0 ) ) );
			if ( $booking_id <= 0 ) {
				return self::webhook_response( 'booking_not_found' );
			}

			$booking = Bookings::get( $booking_id );
			if ( ! $booking ) {
				return self::webhook_response( 'booking_not_found' );
			}
			if ( ! empty( $booking->payment_reference ) && ! hash_equals( (string) $booking->payment_reference, $token ) ) {
				return self::webhook_response( 'reference_mismatch' );
			}
			$commit_amount  = isset( $commit['amount'] ) ? (float) $commit['amount'] : 0.0;
			$booking_amount = isset( $booking->payment_amount ) ? (float) $booking->payment_amount : 0.0;
			if ( $booking_amount > 0 && $commit_amount > 0 && abs( $booking_amount - $commit_amount ) > 0.01 ) {
				return self::webhook_response( 'amount_mismatch' );
			}

			$lock = self::acquire_lock( "litecal_payhook_webpay_{$booking_id}", 30 );
			if ( ! $lock ) {
				return self::webhook_response( 'locked' );
			}
			$current_payment = (string) $booking->payment_status;
			$current_status  = (string) $booking->status;
			if ( $current_payment === 'paid' ) {
				self::release_lock( "litecal_payhook_webpay_{$booking_id}" );
				return self::webhook_response( 'ok' );
			}

			$wp_status     = strtoupper( (string) ( $commit['status'] ?? '' ) );
			$response_code = isset( $commit['response_code'] ) ? (int) $commit['response_code'] : -1;
			$is_approved   = ( $wp_status === 'AUTHORIZED' && $response_code === 0 );

			$payment_status = 'pending';
			$booking_status = 'pending';
			if ( $is_approved ) {
				$payment_status = 'paid';
				$booking_status = 'confirmed';
			} elseif ( in_array( $wp_status, array( 'FAILED', 'REVERSED', 'REJECTED', 'NULLIFIED' ), true ) || $response_code !== 0 ) {
				$payment_status = 'rejected';
				$booking_status = 'cancelled';
			}
			if ( in_array( $current_payment, array( 'rejected', 'cancelled' ), true ) && $payment_status === 'pending' ) {
				self::release_lock( "litecal_payhook_webpay_{$booking_id}" );
				return self::webhook_response( 'ok' );
			}
			if ( $current_payment !== $payment_status ) {
				$payment_payload = array(
					'payment_status'    => $payment_status,
					'payment_provider'  => 'webpay',
					'payment_reference' => $token,
					'payment_error'     => '',
				);
				if ( $commit_amount > 0 ) {
					$payment_payload['payment_amount'] = $commit_amount;
				}
				Bookings::update_payment( $booking_id, $payment_payload );
			}
			if ( $current_status !== $booking_status ) {
				Bookings::update_status( $booking_id, $booking_status );
			}
			if ( $booking_status === 'confirmed' && $current_status !== 'confirmed' ) {
				self::notify_booking_status( $booking_id, 'confirmed', true );
			} elseif ( $booking_status === 'cancelled' && $current_status !== 'cancelled' ) {
				self::notify_booking_status( $booking_id, 'payment_failed', true );
			}
			self::release_lock( "litecal_payhook_webpay_{$booking_id}" );
			return self::webhook_response( 'ok' );
		}

		$headers = $request->get_headers();
		$payload = $request->get_json_params();
		if ( ! $payload ) {
			return self::webhook_response( 'invalid_payload' );
		}

		$provider = new PayPalProvider();
		if ( ! $provider->verify_webhook( $payload, $headers ) ) {
			return self::webhook_response( 'verification_failed' );
		}

		$event_type = strtoupper( (string) ( $payload['event_type'] ?? '' ) );
		$resource   = $payload['resource'] ?? array();
		$booking_id = (int) ( $resource['custom_id'] ?? $resource['invoice_id'] ?? 0 );
		if ( $booking_id <= 0 ) {
			$booking_id = (int) ( $resource['purchase_units'][0]['custom_id'] ?? 0 );
		}
				if ( $booking_id <= 0 ) {
					$order_id = $resource['supplementary_data']['related_ids']['order_id'] ?? $resource['id'] ?? '';
					if ( $order_id ) {
						global $wpdb;
						$table_name = $wpdb->prefix . 'litecal_bookings';
						// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Plugin table name uses trusted $wpdb->prefix and WP 6.0 cannot use %i safely.
						$booking_id = (int) $wpdb->get_var(
							$wpdb->prepare(
								"SELECT id FROM {$table_name} WHERE payment_reference = %s LIMIT 1",
								(string) $order_id
							)
						);
						// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					}
				}
		if ( $booking_id <= 0 ) {
			return self::webhook_response( 'booking_not_found' );
		}

		$booking = Bookings::get( $booking_id );
		if ( ! $booking ) {
			return self::webhook_response( 'booking_not_found' );
		}
		$lock = self::acquire_lock( "litecal_payhook_paypal_{$booking_id}", 30 );
		if ( ! $lock ) {
			return self::webhook_response( 'locked' );
		}
		$current_payment = (string) $booking->payment_status;
		$current_status  = (string) $booking->status;
		if ( $current_payment === 'paid' ) {
			self::release_lock( "litecal_payhook_paypal_{$booking_id}" );
			return self::webhook_response( 'ok' );
		}

		$payment_status = 'pending';
		$booking_status = 'pending';
		if ( $event_type === 'PAYMENT.CAPTURE.COMPLETED' ) {
			$payment_status = 'paid';
			$booking_status = 'confirmed';
		} elseif ( in_array( $event_type, array( 'PAYMENT.CAPTURE.DENIED', 'PAYMENT.CAPTURE.REVERSED', 'PAYMENT.CAPTURE.REFUNDED' ), true ) ) {
			$payment_status = 'rejected';
			$booking_status = 'cancelled';
		} elseif ( $event_type === 'CHECKOUT.ORDER.APPROVED' ) {
			$payment_status = 'pending';
			$booking_status = 'pending';
		}
		if ( in_array( $current_payment, array( 'rejected', 'cancelled' ), true ) && $payment_status === 'pending' ) {
			self::release_lock( "litecal_payhook_paypal_{$booking_id}" );
			return self::webhook_response( 'ok' );
		}
		$reference       = $resource['id'] ?? $resource['supplementary_data']['related_ids']['order_id'] ?? '';
		$paypal_amount   = 0.0;
		$paypal_currency = '';
		if ( ! empty( $resource['amount']['value'] ) ) {
			$paypal_amount   = (float) $resource['amount']['value'];
			$paypal_currency = strtoupper( (string) ( $resource['amount']['currency_code'] ?? '' ) );
		} elseif ( ! empty( $resource['purchase_units'][0]['payments']['captures'][0]['amount']['value'] ) ) {
			$paypal_amount   = (float) $resource['purchase_units'][0]['payments']['captures'][0]['amount']['value'];
			$paypal_currency = strtoupper( (string) ( $resource['purchase_units'][0]['payments']['captures'][0]['amount']['currency_code'] ?? '' ) );
		}
		if ( $current_payment !== $payment_status ) {
			$payment_update = array(
				'payment_status'    => $payment_status,
				'payment_provider'  => 'paypal',
				'payment_reference' => sanitize_text_field( $reference ),
				'payment_error'     => '',
			);
			if ( $paypal_amount > 0 ) {
				$payment_update['payment_amount'] = $paypal_amount;
			}
			if ( $paypal_currency !== '' ) {
				$payment_update['payment_currency'] = $paypal_currency;
			}
			Bookings::update_payment( $booking_id, $payment_update );
		}
		if ( $current_status !== $booking_status ) {
			Bookings::update_status( $booking_id, $booking_status );
		}
		if ( $booking_status === 'confirmed' && $current_status !== 'confirmed' ) {
			self::notify_booking_status( $booking_id, 'confirmed', true );
		} elseif ( $booking_status === 'cancelled' && $current_status !== 'cancelled' ) {
			self::notify_booking_status( $booking_id, 'payment_failed', true );
		}
		self::release_lock( "litecal_payhook_paypal_{$booking_id}" );
		return self::webhook_response( 'ok' );
	}

	public static function paypal_capture( $request ) {
		if ( ! self::provider_feature_enabled( 'paypal' ) ) {
			return self::rest_error( 'forbidden', 'PayPal requiere Agenda Lite Pro.', array( 'status' => 403 ) );
		}
		$booking_id = (int) $request->get_param( 'booking_id' );
		$order_id   = sanitize_text_field( $request->get_param( 'token' ) );
		if ( $booking_id <= 0 || empty( $order_id ) ) {
			return self::rest_error( 'invalid', 'Datos inválidos', array( 'status' => 400 ) );
		}
		$limit = self::rate_limit( "paypal_capture:{$booking_id}", 10, 60 );
		if ( is_wp_error( $limit ) ) {
			return $limit;
		}

		$booking = Bookings::get( $booking_id );
		if ( ! $booking ) {
			return self::rest_error( 'not_found', 'Reserva no encontrada', array( 'status' => 404 ) );
		}
		$access = self::assert_booking_access( $request, $booking );
		if ( is_wp_error( $access ) ) {
			return $access;
		}
		$lock_key = "litecal_paypal_capture_{$booking_id}";
		if ( ! self::acquire_lock( $lock_key, 20 ) ) {
			return self::rest_response(
				'capture_locked',
				'La reserva está siendo procesada.',
				(string) $booking->status,
				array(
					'payment_status' => (string) $booking->payment_status,
				)
			);
		}
		if ( $booking->payment_status === 'paid' ) {
			self::release_lock( $lock_key );
			return self::rest_response(
				'already_paid',
				'El pago ya fue confirmado.',
				(string) $booking->status,
				array(
					'payment_status' => (string) $booking->payment_status,
				)
			);
		}
		$event = Events::get( (int) $booking->event_id );
		if ( ! $event ) {
			self::release_lock( $lock_key );
			return self::rest_error( 'not_found', 'Servicio no encontrado', array( 'status' => 404 ) );
		}

		$provider = new PayPalProvider();
		$capture  = $provider->capture_order( $order_id );
		if ( is_wp_error( $capture ) ) {
			if ( $booking->payment_status === 'paid' ) {
				self::release_lock( $lock_key );
				return self::rest_response(
					'already_paid',
					'El pago ya fue confirmado.',
					(string) $booking->status,
					array(
						'payment_status' => (string) $booking->payment_status,
					)
				);
			}
			Bookings::update_payment(
				$booking_id,
				array(
					'payment_status'    => 'pending',
					'payment_provider'  => 'paypal',
					'payment_reference' => $order_id,
					'payment_error'     => $capture->get_error_message(),
				)
			);
			self::release_lock( $lock_key );
			return self::rest_error( 'capture_failed', $capture->get_error_message(), array( 'status' => 400 ) );
		}

		$payment_status = 'pending';
		$booking_status = 'pending';
		$capture_status = strtoupper( (string) ( $capture['status'] ?? '' ) );
		if ( $capture_status === 'COMPLETED' ) {
			$payment_status = 'paid';
			$booking_status = 'confirmed';
		}
		$payment_id       = $capture['purchase_units'][0]['payments']['captures'][0]['id'] ?? $order_id;
		$capture_amount   = (float) ( $capture['purchase_units'][0]['payments']['captures'][0]['amount']['value'] ?? 0 );
		$capture_currency = strtoupper( (string) ( $capture['purchase_units'][0]['payments']['captures'][0]['amount']['currency_code'] ?? '' ) );
		$capture_update   = array(
			'payment_status'    => $payment_status,
			'payment_provider'  => 'paypal',
			'payment_reference' => $payment_id,
			'payment_error'     => '',
		);
		if ( $capture_amount > 0 ) {
			$capture_update['payment_amount'] = $capture_amount;
		}
		if ( $capture_currency !== '' ) {
			$capture_update['payment_currency'] = $capture_currency;
		}
		Bookings::update_payment( $booking_id, $capture_update );
		Bookings::update_status( $booking_id, $booking_status );

		if ( $booking_status === 'confirmed' ) {
			self::notify_booking_status( $booking_id, 'confirmed', true );
		}
		self::release_lock( $lock_key );

		return self::rest_response(
			'capture_processed',
			'Pago procesado.',
			(string) $booking_status,
			array(
				'payment_status' => (string) $payment_status,
			)
		);
	}

	public static function cancel_payment( $request ) {
		$booking_id = (int) $request->get_param( 'booking_id' );
		$provider   = sanitize_text_field( $request->get_param( 'provider' ) );
		if ( $booking_id <= 0 || $provider === '' ) {
			return self::rest_error( 'invalid', 'Datos inválidos', array( 'status' => 400 ) );
		}
		$limit = self::rate_limit( "cancel_payment:{$booking_id}", 12, 60 );
		if ( is_wp_error( $limit ) ) {
			return $limit;
		}
		$booking = Bookings::get( $booking_id );
		if ( ! $booking ) {
			return self::rest_error( 'not_found', 'Reserva no encontrada', array( 'status' => 404 ) );
		}
		$access = self::assert_booking_access( $request, $booking );
		if ( is_wp_error( $access ) ) {
			return $access;
		}
		if ( $booking->payment_provider && $booking->payment_provider !== $provider ) {
			return self::rest_error( 'invalid', 'Proveedor inválido', array( 'status' => 400 ) );
		}
		if ( $booking->payment_status === 'paid' ) {
			return self::rest_response(
				'already_paid',
				'El pago ya fue confirmado.',
				(string) $booking->status,
				array(
					'payment_status' => (string) $booking->payment_status,
				)
			);
		}
		// Returning from checkout without paying should keep the booking retryable.
		Bookings::update_payment(
			$booking_id,
			array(
				'payment_status'     => 'pending',
				'payment_provider'   => $provider,
				'payment_reference'  => $booking->payment_reference,
				'payment_error'      => 'Pago no completado',
				'payment_voided'     => 0,
				'payment_pending_at' => ! empty( $booking->payment_pending_at ) ? $booking->payment_pending_at : current_time( 'mysql' ),
			)
		);
		if ( ! in_array( (string) $booking->status, array( 'pending', 'confirmed' ), true ) ) {
			Bookings::update_status( $booking_id, 'pending' );
		}
		return self::rest_response(
			'payment_cancelled',
			'Pago marcado como no completado.',
			'pending',
			array(
				'payment_status' => 'pending',
			)
		);
	}

	public static function resume_payment( $request ) {
		$booking_id = (int) $request->get_param( 'booking_id' );
		$provider   = sanitize_text_field( $request->get_param( 'provider' ) );
		if ( $booking_id <= 0 || $provider === '' ) {
			return self::rest_error( 'invalid', 'Datos inválidos', array( 'status' => 400 ) );
		}
		if ( $provider !== 'transfer' && ! self::provider_feature_enabled( $provider ) ) {
			return self::rest_error( 'forbidden', 'Este medio de pago requiere Agenda Lite Pro.', array( 'status' => 403 ) );
		}
		$limit = self::rate_limit( "resume_payment:{$booking_id}", 12, 60 );
		if ( is_wp_error( $limit ) ) {
			return $limit;
		}
		$booking = Bookings::get( $booking_id );
		if ( ! $booking ) {
			return self::rest_error( 'not_found', 'Reserva no encontrada', array( 'status' => 404 ) );
		}
		$access = self::assert_booking_access( $request, $booking );
		if ( is_wp_error( $access ) ) {
			return $access;
		}
		$request_token = sanitize_text_field( (string) $request->get_param( 'booking_token' ) );
		$booking_token = $request_token !== '' ? $request_token : self::booking_access_token( $booking );
		if ( $booking->payment_status === 'paid' ) {
			return self::rest_response(
				'already_paid',
				'El pago ya fue confirmado.',
				(string) $booking->status,
				array(
					'payment_status' => (string) $booking->payment_status,
				)
			);
		}
		$event = Events::get( (int) $booking->event_id );
		if ( ! $event ) {
			return self::rest_error( 'not_found', 'Servicio no encontrado', array( 'status' => 404 ) );
		}
		$snapshot = Bookings::decode_snapshot( $booking );
		$amount   = (float) $booking->payment_amount;
		if ( $amount <= 0 ) {
			$event_options        = json_decode( $event->options ?: '[]', true );
			$price_mode           = $event_options['price_mode'] ?? 'total';
			$partial_percent      = (int) ( $event_options['partial_percent'] ?? 30 );
			$partial_fixed_amount = (float) ( $event_options['partial_fixed_amount'] ?? 0 );
			if ( ! in_array( $partial_percent, array( 10, 20, 30, 40, 50, 60, 70, 80, 90 ), true ) ) {
				$partial_percent = 30;
			}
			$amount = (float) $event->price;
			if ( $price_mode === 'partial_percent' ) {
				$amount = round( $amount * ( $partial_percent / 100 ), 2 );
			}
			if ( $price_mode === 'partial_fixed' && $partial_fixed_amount > 0 ) {
				$amount = $partial_fixed_amount;
			}
			if ( $price_mode === 'free' ) {
				$amount = 0;
			}
		}
		$global_currency   = strtoupper( (string) ( $snapshot['event']['currency'] ?? ( $event->currency ?: ( get_option( 'litecal_settings', array() )['currency'] ?? 'CLP' ) ) ) );
		$currency          = strtoupper( (string) ( $booking->payment_currency ?: $global_currency ) );
		$title             = $snapshot['event']['title'] ?? $event->title;
		$slug              = $snapshot['event']['slug'] ?? $event->slug;
		$payment_url       = '';
		$payment           = array();
		$available_methods = \LiteCal\Admin\Admin::payment_methods_for_event( $global_currency, $snapshot['event'] ?? array(), $amount > 0 ? $amount : null );
		$available_map     = array();
		foreach ( $available_methods as $method ) {
			if ( ! empty( $method['key'] ) ) {
				$available_map[ $method['key'] ] = $method;
			}
		}
		if ( ! isset( $available_map[ $provider ] ) ) {
			return self::rest_error( 'payment_error', 'El medio de pago no está disponible con la moneda actual.', array( 'status' => 400 ) );
		}
		$provider_currency = strtoupper( (string) ( $available_map[ $provider ]['charge_currency'] ?? $currency ) );
		$provider_amount   = (float) $amount;
		if ( ! empty( $booking->payment_currency ) && strtoupper( (string) $booking->payment_currency ) !== '' && (float) $booking->payment_amount > 0 ) {
			$provider_currency = strtoupper( (string) $booking->payment_currency );
			$provider_amount   = (float) $booking->payment_amount;
		} elseif ( $provider_currency !== $global_currency ) {
			$source_amount   = (float) ( $amount > 0 ? $amount : ( $snapshot['event']['price'] ?? 0 ) );
			$provider_amount = \LiteCal\Admin\Admin::convert_from_global_for_provider( $source_amount, $global_currency, $provider );
		}
		if ( $provider_amount <= 0 ) {
			$provider_amount = (float) $amount;
		}
		if ( $provider_amount <= 0 ) {
			return self::rest_error( 'payment_error', 'No se pudo calcular el monto para retomar el pago.', array( 'status' => 400 ) );
		}

		if ( $provider === 'flow' ) {
			if ( $provider_currency !== 'CLP' ) {
				return self::rest_error( 'payment_error', 'Flow solo admite CLP.', array( 'status' => 400 ) );
			}
			$flow    = new FlowProvider();
			$payment = $flow->create_payment(
				array(
					'id'    => $booking->id,
					'email' => $booking->email,
					'name'  => $booking->name,
				),
				array(
					'title'         => $title,
					'price'         => (float) $provider_amount,
					'currency'      => $provider_currency,
					'slug'          => $slug,
					'booking_token' => $booking_token,
				)
			);
			if ( ! empty( $payment['payment_url'] ) ) {
				$payment_url = $payment['payment_url'];
				Bookings::update_payment(
					$booking->id,
					array(
						'payment_status'    => 'pending',
						'payment_provider'  => 'flow',
						'payment_reference' => sanitize_text_field( $payment['token'] ?? '' ),
						'payment_currency'  => $provider_currency,
						'payment_amount'    => $provider_amount,
						'payment_error'     => '',
					)
				);
			}
		} elseif ( $provider === 'mp' ) {
			if ( $provider_currency !== 'CLP' ) {
				return self::rest_error( 'payment_error', 'Mercado Pago solo admite CLP en este sitio.', array( 'status' => 400 ) );
			}
			$mp               = new MercadoPagoProvider();
			$return_url       = add_query_arg(
				array(
					'agendalite_payment' => 'mp',
					'booking_id'         => $booking->id,
					'booking_token'      => $booking_token,
				),
				home_url( '/' . trim( $slug, '/' ) )
			);
			$notification_url = home_url( '/wp-json/litecal/v1/payments/webhook?provider=mp' );
			$payment          = $mp->create_payment(
				array(
					'id'    => $booking->id,
					'email' => $booking->email,
					'name'  => $booking->name,
				),
				array(
					'title'            => $title,
					'price'            => (float) $provider_amount,
					'currency'         => $provider_currency,
					'slug'             => $slug,
					'return_url'       => $return_url,
					'notification_url' => $notification_url,
				)
			);
			if ( ! empty( $payment['payment_url'] ) ) {
				$payment_url = $payment['payment_url'];
				Bookings::update_payment(
					$booking->id,
					array(
						'payment_status'    => 'pending',
						'payment_provider'  => 'mp',
						'payment_reference' => sanitize_text_field( $payment['preference_id'] ?? '' ),
						'payment_currency'  => $provider_currency,
						'payment_amount'    => $provider_amount,
						'payment_error'     => '',
					)
				);
			}
		} elseif ( $provider === 'webpay' ) {
			if ( $provider_currency !== 'CLP' ) {
				return self::rest_error( 'payment_error', 'Webpay Plus solo admite CLP.', array( 'status' => 400 ) );
			}
			$webpay     = new WebpayPlusProvider();
			$return_url = add_query_arg(
				array(
					'agendalite_payment' => 'webpay',
					'booking_id'         => $booking->id,
					'booking_token'      => $booking_token,
				),
				home_url( '/' . trim( $slug, '/' ) )
			);
			$payment    = $webpay->create_payment(
				array(
					'id'         => $booking->id,
					'buy_order'  => (string) $booking->id,
					'session_id' => (string) $booking->id,
				),
				array(
					'title'      => $title,
					'price'      => (float) $provider_amount,
					'currency'   => $provider_currency,
					'slug'       => $slug,
					'return_url' => $return_url,
				)
			);
			if ( ! empty( $payment['payment_url'] ) && ! empty( $payment['token'] ) ) {
				$gateway_url = $payment['payment_url'];
				$token       = $payment['token'];
				$payment_url = add_query_arg(
					array(
						'litecal_webpay' => 1,
						'token_ws'       => $token,
						'url'            => rawurlencode( $gateway_url ),
					),
					home_url( '/' )
				);
				Bookings::update_payment(
					$booking->id,
					array(
						'payment_status'    => 'pending',
						'payment_provider'  => 'webpay',
						'payment_reference' => sanitize_text_field( $token ),
						'payment_currency'  => $provider_currency,
						'payment_amount'    => $provider_amount,
						'payment_error'     => '',
					)
				);
			}
		} elseif ( $provider === 'paypal' ) {
			if ( ! \LiteCal\Admin\Admin::paypal_supported_currency( $provider_currency ) ) {
				return self::rest_error( 'payment_error', 'PayPal no soporta la moneda seleccionada.', array( 'status' => 400 ) );
			}
			$paypal     = new PayPalProvider();
			$return_url = add_query_arg(
				array(
					'agendalite_payment' => 'paypal',
					'booking_id'         => $booking->id,
					'booking_token'      => $booking_token,
				),
				home_url( '/' . trim( $slug, '/' ) )
			);
			$cancel_url = add_query_arg(
				array(
					'agendalite_payment' => 'paypal',
					'booking_id'         => $booking->id,
					'booking_token'      => $booking_token,
					'cancelled'          => '1',
				),
				home_url( '/' . trim( $slug, '/' ) )
			);
			$payment    = $paypal->create_payment(
				array(
					'id'    => $booking->id,
					'email' => $booking->email,
					'name'  => $booking->name,
				),
				array(
					'title'      => $title,
					'price'      => (float) $provider_amount,
					'currency'   => $provider_currency,
					'slug'       => $slug,
					'return_url' => $return_url,
					'cancel_url' => $cancel_url,
				)
			);
			if ( ! empty( $payment['payment_url'] ) ) {
				$payment_url = $payment['payment_url'];
				Bookings::update_payment(
					$booking->id,
					array(
						'payment_status'    => 'pending',
						'payment_provider'  => 'paypal',
						'payment_reference' => sanitize_text_field( $payment['order_id'] ?? '' ),
						'payment_currency'  => $provider_currency,
						'payment_amount'    => $provider_amount,
						'payment_error'     => '',
					)
				);
			}
		} elseif ( $provider === 'stripe' ) {
			$stripe      = new StripeProvider();
			$success_url = add_query_arg(
				array(
					'agendalite_payment' => 'stripe',
					'booking_id'         => $booking->id,
					'booking_token'      => $booking_token,
					'session_id'         => '{CHECKOUT_SESSION_ID}',
				),
				home_url( '/' . trim( $slug, '/' ) )
			);
			$cancel_url  = add_query_arg(
				array(
					'agendalite_payment' => 'stripe',
					'booking_id'         => $booking->id,
					'booking_token'      => $booking_token,
					'cancelled'          => '1',
					'session_id'         => '{CHECKOUT_SESSION_ID}',
				),
				home_url( '/' . trim( $slug, '/' ) )
			);
			$payment     = $stripe->create_payment(
				array(
					'id'    => $booking->id,
					'email' => $booking->email,
					'name'  => $booking->name,
				),
				array(
					'event_id'    => (int) $event->id,
					'title'       => $title,
					'description' => wp_strip_all_tags( (string) ( $snapshot['event']['description'] ?? ( $event->description ?? '' ) ) ),
					'price'       => (float) $provider_amount,
					'currency'    => $provider_currency,
					'slug'        => $slug,
					'success_url' => $success_url,
					'cancel_url'  => $cancel_url,
				)
			);
			if ( ! empty( $payment['payment_url'] ) ) {
				$payment_url = $payment['payment_url'];
				Bookings::update_payment(
					$booking->id,
					array(
						'payment_status'    => 'pending',
						'payment_provider'  => 'stripe',
						'payment_reference' => sanitize_text_field( $payment['session_id'] ?? '' ),
						'payment_currency'  => $provider_currency,
						'payment_amount'    => $provider_amount,
						'payment_error'     => '',
					)
				);
			}
		} elseif ( $provider === 'transfer' ) {
			$payment_url = add_query_arg(
				array(
					'agendalite_payment' => 'transfer',
					'booking_id'         => $booking->id,
					'booking_token'      => $booking_token,
				),
				home_url( '/' . trim( $slug, '/' ) )
			);
			Bookings::update_payment(
				$booking->id,
				array(
					'payment_status'    => 'pending',
					'payment_provider'  => 'transfer',
					'payment_reference' => ! empty( $booking->payment_reference ) ? $booking->payment_reference : strtoupper( 'TR-' . wp_generate_password( 10, false, false ) ),
					'payment_currency'  => $provider_currency,
					'payment_amount'    => $provider_amount,
					'payment_error'     => '',
				)
			);
		}

		if ( $payment_url ) {
			return self::rest_response(
				'payment_resumed',
				'Pago retomado correctamente.',
				'ok',
				array(
					'payment_url' => $payment_url,
				)
			);
		}

		return self::rest_error( 'payment_error', 'No se pudo retomar el pago.', array( 'status' => 400 ) );
	}

	public static function stripe_create_session( $request ) {
		if ( ! self::provider_feature_enabled( 'stripe' ) ) {
			return self::rest_error( 'forbidden', 'Stripe requiere Agenda Lite Pro.', array( 'status' => 403 ) );
		}
		$booking_id = (int) $request->get_param( 'booking_id' );
		if ( $booking_id <= 0 ) {
			return self::rest_error( 'invalid', 'Datos inválidos', array( 'status' => 400 ) );
		}
		$request->set_param( 'provider', 'stripe' );
		return self::resume_payment( $request );
	}

	public static function stripe_webhook( $request ) {
		if ( ! self::provider_feature_enabled( 'stripe' ) ) {
			return self::webhook_response( 'ignored' );
		}
		$limit = self::rate_limit( 'webhook:stripe', 120, 60 );
		if ( is_wp_error( $limit ) ) {
			return $limit;
		}

		$provider = new StripeProvider();
		$raw_body = (string) $request->get_body();
		$event    = $provider->parse_webhook_event( $raw_body, $request->get_headers() );
		if ( is_wp_error( $event ) ) {
			return self::rest_error( 'verification_failed', $event->get_error_message(), array( 'status' => 400 ) );
		}

		$event_id   = sanitize_text_field( (string) ( $event['id'] ?? '' ) );
		$event_type = strtolower( (string) ( $event['type'] ?? '' ) );
		if ( $event_id === '' || $event_type === '' ) {
			return self::rest_error( 'invalid_payload', 'Payload inválido.', array( 'status' => 400 ) );
		}
		$event_transient = 'litecal_stripe_evt_' . md5( $event_id );
		if ( get_transient( $event_transient ) ) {
			return self::webhook_response(
				'ok',
				array(
					'idempotent' => true,
					'event'      => $event_type,
				)
			);
		}

		$object = $event['data']['object'] ?? array();
		if ( ! is_array( $object ) ) {
			return self::rest_error( 'invalid_payload', 'Payload inválido.', array( 'status' => 400 ) );
		}
		$metadata   = is_array( $object['metadata'] ?? null ) ? $object['metadata'] : array();
		$booking_id = (int) ( $metadata['booking_id'] ?? ( $object['client_reference_id'] ?? 0 ) );
		if ( $booking_id <= 0 && ! empty( $object['payment_intent'] ) && is_array( $object['payment_intent'] ) && ! empty( $object['payment_intent']['metadata']['booking_id'] ) ) {
			$booking_id = (int) $object['payment_intent']['metadata']['booking_id'];
		}
		if ( $booking_id <= 0 ) {
			return self::webhook_response( 'booking_not_found' );
		}

		$booking = Bookings::get( $booking_id );
		if ( ! $booking ) {
			return self::webhook_response( 'booking_not_found' );
		}

		$lock_key = "litecal_payhook_stripe_{$booking_id}";
		if ( ! self::acquire_lock( $lock_key, 30 ) ) {
			return self::webhook_response( 'locked' );
		}

		$current_payment = strtolower( (string) $booking->payment_status );
		$current_status  = strtolower( (string) $booking->status );
		$payment_status  = '';
		$booking_status  = '';
		if ( in_array( $event_type, array( 'checkout.session.completed', 'payment_intent.succeeded' ), true ) ) {
			$payment_status = 'paid';
			$booking_status = 'confirmed';
		} elseif ( $event_type === 'payment_intent.payment_failed' ) {
			$payment_status = 'rejected';
			$booking_status = 'cancelled';
		} elseif ( $event_type === 'checkout.session.expired' ) {
			$payment_status = 'cancelled';
			$booking_status = 'cancelled';
		} else {
			self::release_lock( $lock_key );
			set_transient( $event_transient, 1, DAY_IN_SECONDS );
			return self::webhook_response( 'ignored', array( 'event' => $event_type ) );
		}

		if ( $current_payment === 'paid' && $payment_status === 'paid' ) {
			self::release_lock( $lock_key );
			set_transient( $event_transient, 1, DAY_IN_SECONDS );
			return self::webhook_response(
				'ok',
				array(
					'event'      => $event_type,
					'idempotent' => true,
				)
			);
		}

		$currency       = strtoupper( (string) ( $object['currency'] ?? ( $booking->payment_currency ?: 'USD' ) ) );
		$amount_minor   = (int) ( $object['amount_total'] ?? ( $object['amount_received'] ?? ( $object['amount'] ?? 0 ) ) );
		$amount_value   = $amount_minor > 0 ? (float) $provider->from_minor_unit( $amount_minor, $currency ) : (float) $booking->payment_amount;
		$booking_amount = (float) ( $booking->payment_amount ?? 0 );
		if ( $payment_status === 'paid' && $booking_amount > 0 && $amount_value > 0 && abs( $booking_amount - $amount_value ) > 0.05 ) {
			self::release_lock( $lock_key );
			return self::webhook_response( 'amount_mismatch' );
		}

		$session_id        = '';
		$payment_intent_id = '';
		if ( ( $object['object'] ?? '' ) === 'checkout.session' ) {
			$session_id = sanitize_text_field( (string) ( $object['id'] ?? '' ) );
			if ( is_string( $object['payment_intent'] ?? null ) ) {
				$payment_intent_id = sanitize_text_field( (string) $object['payment_intent'] );
			} elseif ( is_array( $object['payment_intent'] ?? null ) ) {
				$payment_intent_id = sanitize_text_field( (string) ( $object['payment_intent']['id'] ?? '' ) );
			}
		} elseif ( ( $object['object'] ?? '' ) === 'payment_intent' ) {
			$payment_intent_id = sanitize_text_field( (string) ( $object['id'] ?? '' ) );
			$session_id        = sanitize_text_field( (string) ( $booking->payment_reference ?? '' ) );
		}
		if ( $session_id === '' ) {
			$session_id = sanitize_text_field( (string) ( $booking->payment_reference ?? '' ) );
		}

		$payment_update = array(
			'payment_status'    => $payment_status,
			'payment_provider'  => 'stripe',
			'payment_reference' => $session_id,
			'payment_error'     => '',
			'payment_voided'    => 0,
		);
		if ( $currency !== '' ) {
			$payment_update['payment_currency'] = $currency;
		}
		if ( $amount_value > 0 ) {
			$payment_update['payment_amount'] = $amount_value;
		}
		if ( $payment_status !== $current_payment ) {
			Bookings::update_payment( $booking_id, $payment_update );
		}
		if ( $booking_status !== '' && $booking_status !== $current_status ) {
			Bookings::update_status( $booking_id, $booking_status );
		}

		self::store_payment_snapshot_meta(
			$booking_id,
			$booking,
			array(
				'provider'                 => 'stripe',
				'stripe_session_id'        => $session_id,
				'stripe_payment_intent_id' => $payment_intent_id,
				'amount_paid'              => $amount_value,
				'currency'                 => $currency,
				'status'                   => $payment_status,
				'updated_at'               => current_time( 'mysql' ),
				'event_type'               => $event_type,
			)
		);

		if ( $booking_status === 'confirmed' && $current_status !== 'confirmed' ) {
			self::notify_booking_status( $booking_id, 'confirmed', true );
		} elseif ( $booking_status === 'cancelled' && $current_status !== 'cancelled' ) {
			self::notify_booking_status( $booking_id, 'payment_failed', true );
		}

		self::release_lock( $lock_key );
		set_transient( $event_transient, 1, DAY_IN_SECONDS );
		return self::webhook_response( 'ok', array( 'event' => $event_type ) );
	}

	public static function get_booking( $request ) {
		$id         = (int) $request['id'];
		$event_id   = (int) $request->get_param( 'event_id' );
		$is_receipt = (string) $request->get_param( 'receipt' ) === '1';
		if ( ! $id || ( ! $event_id && ! $is_receipt ) ) {
			return self::rest_error( 'invalid', 'Datos inválidos', array( 'status' => 400 ) );
		}
		$limit = self::rate_limit( "get_booking:{$id}", 60, 60 );
		if ( is_wp_error( $limit ) ) {
			return $limit;
		}
		$booking = Bookings::get( $id );
		if ( ! $booking || ( $event_id && (int) $booking->event_id !== $event_id ) ) {
			return self::rest_error( 'not_found', 'No encontrado', array( 'status' => 404 ) );
		}
		$event = Events::get( (int) ( $booking->event_id ?? 0 ) );
		if ( ! $event ) {
			return self::rest_error( 'not_found', 'Servicio no encontrado', array( 'status' => 404 ) );
		}
		$access = self::assert_booking_access( $request, $booking );
		if ( is_wp_error( $access ) ) {
			return $access;
		}
		$request_token = sanitize_text_field( (string) $request->get_param( 'booking_token' ) );
		$employee      = null;
		if ( ! empty( $booking->employee_id ) ) {
			$employee = \LiteCal\Core\Employees::get( $booking->employee_id );
		}
		$pending_at             = property_exists( $booking, 'payment_pending_at' ) ? $booking->payment_pending_at : null;
		$pending_ts             = self::wp_datetime_to_ts( ! empty( $pending_at ) ? $pending_at : $booking->created_at );
		$created_ts             = self::wp_datetime_to_ts( $booking->created_at );
		$now_ts                 = self::current_ts();
		$snapshot               = Bookings::decode_snapshot( $booking );
		$access_token_for_links = $request_token !== '' ? $request_token : self::booking_access_token( $booking );
		if ( ! empty( $snapshot['files'] ) && is_array( $snapshot['files'] ) ) {
			foreach ( $snapshot['files'] as $field_key => $rows ) {
				if ( ! is_array( $rows ) ) {
					continue;
				}
				foreach ( $rows as $idx => $file_item ) {
					if ( ! is_array( $file_item ) ) {
						continue;
					}
					$file_token = sanitize_text_field( (string) ( $file_item['token'] ?? '' ) );
					if ( $file_token === '' ) {
						continue;
					}
					$snapshot['files'][ $field_key ][ $idx ]['url'] = self::booking_file_url( $id, $file_token, $access_token_for_links );
				}
			}
		}
		$expiration_seconds = self::payment_expiration_seconds( $booking, $snapshot );
		$retry_seconds_left = 0;
		if ( $pending_ts > 0 ) {
			$retry_seconds_left = max( 0, $expiration_seconds - ( $now_ts - $pending_ts ) );
		}
		$transfer_details = array();
		if ( (string) $booking->payment_provider === 'transfer' ) {
			if ( ! empty( $snapshot['transfer'] ) && is_array( $snapshot['transfer'] ) ) {
				$transfer_details = $snapshot['transfer'];
			} else {
				$transfer_details = self::transfer_details_from_options( get_option( 'litecal_integrations', array() ), (string) ( $booking->payment_currency ?: 'CLP' ) );
			}
		}
		$meeting_provider        = sanitize_key( (string) ( $booking->video_provider ?? ( $snapshot['event']['location'] ?? '' ) ) );
		$paid_statuses           = array( 'paid', 'approved', 'completed', 'confirmed' );
		$requires_payment_gate   = ! empty( $booking->payment_provider ) || ( (float) ( $booking->payment_amount ?? 0 ) > 0 );
		$can_expose_meeting_link = ! $requires_payment_gate || in_array( strtolower( (string) $booking->payment_status ), $paid_statuses, true );
		$meeting_link            = $can_expose_meeting_link ? (string) $booking->calendar_meet_link : '';
		$manage_state            = self::manage_state_for_booking( $booking, $event, $access_token_for_links );
		return self::rest_response(
			'booking_found',
			'Reserva encontrada.',
			(string) $booking->status,
			array(
				'id'                         => (int) $booking->id,
				'event_id'                   => (int) $booking->event_id,
				'name'                       => $booking->name,
				'first_name'                 => $snapshot['booking']['first_name'] ?? '',
				'last_name'                  => $snapshot['booking']['last_name'] ?? '',
				'email'                      => $booking->email,
				'phone'                      => $booking->phone,
				'start'                      => $booking->start_datetime,
				'end'                        => $booking->end_datetime,
				'custom_fields'              => $booking->custom_fields ? json_decode( $booking->custom_fields, true ) : array(),
				'guests'                     => $booking->guests ? json_decode( $booking->guests, true ) : array(),
				'created_at_ts'              => $created_ts,
				'created_at'                 => $booking->created_at,
				'payment_pending_at'         => $pending_at,
				'payment_pending_at_ts'      => $pending_ts ?: null,
				'payment_received_at'        => $booking->payment_received_at ?? null,
				'payment_retry_seconds_left' => $retry_seconds_left,
				'payment_can_retry'          => $retry_seconds_left > 0,
				'payment_expiration_minutes' => (int) floor( $expiration_seconds / 60 ),
				'status'                     => $booking->status,
				'payment_status'             => $booking->payment_status,
				'payment_provider'           => $booking->payment_provider,
				'payment_amount'             => $booking->payment_amount,
				'payment_currency'           => $booking->payment_currency,
				'payment_reference'          => $booking->payment_reference,
				'transfer'                   => $transfer_details,
				'meet_link'                  => $meeting_link,
				'meeting_provider'           => $meeting_provider,
				'snapshot'                   => $snapshot,
				'booking_token'              => $access_token_for_links,
				'manage'                     => $manage_state,
				'employee'                   => $employee ? array(
					'id'    => (int) $employee->id,
					'name'  => $employee->name,
					'email' => $employee->email,
				) : null,
			)
		);
	}

	public static function download_booking_file( $request ) {
		$id    = (int) $request['id'];
		$token = sanitize_text_field( (string) $request['token'] );
		if ( $id <= 0 || $token === '' ) {
			return self::rest_error( 'invalid', 'Solicitud inválida.', array( 'status' => 400 ) );
		}
		$limit = self::rate_limit( "download_file:{$id}", 40, 60 );
		if ( is_wp_error( $limit ) ) {
			return $limit;
		}
		$booking = Bookings::get( $id );
		if ( ! $booking ) {
			return self::rest_error( 'not_found', 'No encontrado', array( 'status' => 404 ) );
		}
		$access = self::assert_booking_access( $request, $booking );
		if ( is_wp_error( $access ) ) {
			return $access;
		}
		$snapshot = Bookings::decode_snapshot( $booking );
		$files    = is_array( $snapshot['files'] ?? null ) ? $snapshot['files'] : array();
		$match    = null;
		foreach ( $files as $rows ) {
			if ( ! is_array( $rows ) ) {
				continue;
			}
			foreach ( $rows as $file_item ) {
				if ( ! is_array( $file_item ) ) {
					continue;
				}
				if ( ! hash_equals( (string) ( $file_item['token'] ?? '' ), $token ) ) {
					continue;
				}
				$match = $file_item;
				break 2;
			}
		}
		if ( ! $match ) {
			return self::rest_error( 'not_found', 'Archivo no encontrado.', array( 'status' => 404 ) );
		}
		$stored_name = sanitize_file_name( (string) ( $match['stored_name'] ?? '' ) );
		if ( $stored_name === '' ) {
			return self::rest_error( 'not_found', 'Archivo no encontrado.', array( 'status' => 404 ) );
		}
		$base_dir  = self::private_upload_base_dir();
		$path      = trailingslashit( $base_dir ) . $stored_name;
		$real_base = realpath( $base_dir );
		$real_path = realpath( $path );
		if ( ! $real_base || ! $real_path || strpos( $real_path, $real_base ) !== 0 || ! file_exists( $real_path ) || ! is_readable( $real_path ) ) {
			return self::rest_error( 'not_found', 'Archivo no encontrado.', array( 'status' => 404 ) );
		}
		$download_name = sanitize_file_name( (string) ( $match['original_name'] ?? 'archivo' ) );
		if ( $download_name === '' ) {
			$download_name = $stored_name;
		}
		$mime = sanitize_text_field( (string) ( $match['mime'] ?? '' ) );
		if ( $mime === '' ) {
			$mime = 'application/octet-stream';
		}
		nocache_headers();
		header( 'Content-Type: ' . $mime );
		header( 'Content-Length: ' . (string) filesize( $real_path ) );
		header( 'X-Content-Type-Options: nosniff' );
		header( 'Content-Disposition: attachment; filename="' . str_replace( '"', '', $download_name ) . '"' );
		readfile( $real_path );
		exit;
	}

	private static function refresh_booking_payment_state( $booking ) {
		if ( ! $booking ) {
			return $booking;
		}
		$booking_id = (int) ( $booking->id ?? 0 );
		if ( $booking_id <= 0 ) {
			return $booking;
		}

		if ( $booking->payment_provider === 'flow' && self::provider_feature_enabled( 'flow' ) && ! empty( $booking->payment_reference ) && in_array( $booking->payment_status, array( 'pending', 'unpaid' ), true ) ) {
			$flow         = new FlowProvider();
			$status       = $flow->get_status( $booking->payment_reference );
			$flow_status  = (int) ( $status['status'] ?? 0 );
			$prev_status  = (string) $booking->status;
			$prev_payment = (string) $booking->payment_status;
			if ( $flow_status === 2 ) {
				Bookings::update_payment( $booking_id, array( 'payment_status' => 'paid' ) );
				Bookings::update_status( $booking_id, 'confirmed' );
				if ( $prev_payment !== 'paid' || $prev_status !== 'confirmed' ) {
					self::notify_booking_status( $booking_id, 'confirmed', true );
				}
			} elseif ( in_array( $flow_status, array( 3, 4 ), true ) ) {
				Bookings::update_payment( $booking_id, array( 'payment_status' => 'rejected' ) );
				Bookings::update_status( $booking_id, 'cancelled' );
				if ( $prev_payment !== 'rejected' || $prev_status !== 'cancelled' ) {
					self::notify_booking_status( $booking_id, 'payment_failed', true );
				}
			}
			$booking = Bookings::get( $booking_id );
		}

		if ( $booking->payment_provider === 'webpay' && self::provider_feature_enabled( 'webpay' ) && ! empty( $booking->payment_reference ) && in_array( $booking->payment_status, array( 'pending', 'unpaid' ), true ) ) {
			$webpay = new WebpayPlusProvider();
			$commit = $webpay->commit( $booking->payment_reference );
			if ( ! is_wp_error( $commit ) ) {
				$wp_status     = strtoupper( (string) ( $commit['status'] ?? '' ) );
				$response_code = isset( $commit['response_code'] ) ? (int) ( $commit['response_code'] ) : -1;
				$is_approved   = ( $wp_status === 'AUTHORIZED' && $response_code === 0 );
				$prev_status   = (string) $booking->status;
				$prev_payment  = (string) $booking->payment_status;
				if ( $is_approved ) {
					Bookings::update_payment( $booking_id, array( 'payment_status' => 'paid' ) );
					Bookings::update_status( $booking_id, 'confirmed' );
					if ( $prev_payment !== 'paid' || $prev_status !== 'confirmed' ) {
						self::notify_booking_status( $booking_id, 'confirmed', true );
					}
				} elseif ( in_array( $wp_status, array( 'FAILED', 'REVERSED', 'REJECTED' ), true ) || $response_code !== 0 ) {
					Bookings::update_payment( $booking_id, array( 'payment_status' => 'rejected' ) );
					Bookings::update_status( $booking_id, 'cancelled' );
					if ( $prev_payment !== 'rejected' || $prev_status !== 'cancelled' ) {
						self::notify_booking_status( $booking_id, 'payment_failed', true );
					}
				}
				$booking = Bookings::get( $booking_id );
			}
		}

		if ( $booking->payment_provider === 'stripe' && self::provider_feature_enabled( 'stripe' ) && ! empty( $booking->payment_reference ) && in_array( $booking->payment_status, array( 'pending', 'unpaid' ), true ) ) {
			$stripe  = new StripeProvider();
			$session = $stripe->get_checkout_session( (string) $booking->payment_reference );
			if ( ! is_wp_error( $session ) && is_array( $session ) ) {
				$session_status         = strtolower( (string) ( $session['status'] ?? '' ) );
				$session_payment_status = strtolower( (string) ( $session['payment_status'] ?? '' ) );
				if ( $session_status === 'complete' && $session_payment_status === 'paid' ) {
					$currency        = strtoupper( (string) ( $session['currency'] ?? ( $booking->payment_currency ?: 'USD' ) ) );
					$amount_minor    = (int) ( $session['amount_total'] ?? 0 );
					$amount_value    = $amount_minor > 0 ? (float) $stripe->from_minor_unit( $amount_minor, $currency ) : (float) $booking->payment_amount;
					$payment_payload = array(
						'payment_status'    => 'paid',
						'payment_provider'  => 'stripe',
						'payment_reference' => sanitize_text_field( (string) ( $session['id'] ?? $booking->payment_reference ) ),
						'payment_error'     => '',
					);
					if ( $currency !== '' ) {
						$payment_payload['payment_currency'] = $currency;
					}
					if ( $amount_value > 0 ) {
						$payment_payload['payment_amount'] = $amount_value;
					}
					Bookings::update_payment( $booking_id, $payment_payload );
					$prev_status = (string) $booking->status;
					Bookings::update_status( $booking_id, 'confirmed' );
					self::store_payment_snapshot_meta(
						$booking_id,
						$booking,
						array(
							'provider'                 => 'stripe',
							'stripe_session_id'        => sanitize_text_field( (string) ( $session['id'] ?? '' ) ),
							'stripe_payment_intent_id' => sanitize_text_field( (string) ( is_array( $session['payment_intent'] ?? null ) ? ( $session['payment_intent']['id'] ?? '' ) : ( $session['payment_intent'] ?? '' ) ) ),
							'amount_paid'              => $amount_value,
							'currency'                 => $currency,
							'status'                   => 'paid',
							'updated_at'               => current_time( 'mysql' ),
							'event_type'               => 'session_reconcile',
						)
					);
					if ( $prev_status !== 'confirmed' ) {
						self::notify_booking_status( $booking_id, 'confirmed', true );
					}
					$booking = Bookings::get( $booking_id );
				} elseif ( $session_status === 'expired' ) {
					$prev_status = (string) $booking->status;
					Bookings::update_payment(
						$booking_id,
						array(
							'payment_status'    => 'cancelled',
							'payment_provider'  => 'stripe',
							'payment_reference' => sanitize_text_field( (string) ( $session['id'] ?? $booking->payment_reference ) ),
							'payment_error'     => 'Checkout expirado',
						)
					);
					Bookings::update_status( $booking_id, 'cancelled' );
					if ( $prev_status !== 'cancelled' ) {
						self::notify_booking_status( $booking_id, 'payment_failed', true );
					}
					$booking = Bookings::get( $booking_id );
				}
			}
		}

		$has_real_payment_context = ! empty( $booking->payment_provider )
			|| ! empty( $booking->payment_reference )
			|| ( (float) ( $booking->payment_amount ?? 0 ) > 0 );

		// Hard guard: free/no-payment bookings must never expire as "payment pending".
		if ( ! $has_real_payment_context ) {
			if ( ! in_array( (string) ( $booking->payment_status ?? '' ), array( 'unpaid', '' ), true ) ) {
				Bookings::update_payment(
					$booking_id,
					array(
						'payment_status'    => 'unpaid',
						'payment_provider'  => null,
						'payment_reference' => null,
						'payment_error'     => '',
						'payment_voided'    => 0,
					)
				);
				$booking = Bookings::get( $booking_id );
			}
			return $booking;
		}

		if ( in_array( $booking->payment_status, array( 'pending', 'unpaid' ), true ) ) {
			$prev_status        = (string) $booking->status;
			$prev_payment       = (string) $booking->payment_status;
			$pending_at         = property_exists( $booking, 'payment_pending_at' ) ? $booking->payment_pending_at : null;
			$pending_ts         = self::wp_datetime_to_ts( ! empty( $pending_at ) ? $pending_at : $booking->created_at );
			$expiration_seconds = self::payment_expiration_seconds( $booking );
			$retry_seconds_left = $pending_ts > 0 ? max( 0, $expiration_seconds - ( self::current_ts() - $pending_ts ) ) : 0;
			if ( $pending_ts && $expiration_seconds > 0 && $retry_seconds_left <= 0 ) {
				Bookings::update_payment(
					$booking_id,
					array(
						'payment_status' => 'cancelled',
						'payment_error'  => 'Pago cancelado por tiempo limite',
						'payment_voided' => 0,
					)
				);
				Bookings::update_status( $booking_id, 'cancelled' );
				if ( $prev_payment !== 'cancelled' || $prev_status !== 'cancelled' ) {
					self::notify_booking_status( $booking_id, 'payment_expired', true );
				}
				$booking = Bookings::get( $booking_id );
			}
		}

		return $booking;
	}

	private static function store_payment_snapshot_meta( $booking_id, $booking, array $meta ) {
		$booking_id = (int) $booking_id;
		if ( $booking_id <= 0 || empty( $meta ) ) {
			return;
		}
		$snapshot = Bookings::decode_snapshot( $booking );
		if ( ! is_array( $snapshot ) ) {
			$snapshot = array();
		}
		if ( empty( $snapshot['payment'] ) || ! is_array( $snapshot['payment'] ) ) {
			$snapshot['payment'] = array();
		}
		foreach ( $meta as $key => $value ) {
			if ( $key === '' ) {
				continue;
			}
			if ( is_string( $value ) ) {
				$snapshot['payment'][ sanitize_key( (string) $key ) ] = sanitize_text_field( $value );
			} elseif ( is_float( $value ) || is_int( $value ) ) {
				$snapshot['payment'][ sanitize_key( (string) $key ) ] = (float) $value;
			} elseif ( is_bool( $value ) ) {
				$snapshot['payment'][ sanitize_key( (string) $key ) ] = $value ? 1 : 0;
			}
		}
		global $wpdb;
		$table   = $wpdb->prefix . 'litecal_bookings';
		$updated = $wpdb->update(
			$table,
			array(
				'snapshot'   => wp_json_encode( $snapshot ),
				'updated_at' => current_time( 'mysql' ),
			),
			array( 'id' => $booking_id )
		);
		if ( $updated !== false ) {
			Bookings::bump_cache_version();
		}
	}

	private static function acquire_lock( $key, $ttl = 20 ) {
		$ttl = (int) $ttl;
		if ( $ttl <= 0 ) {
			$ttl = 20;
		}
		$option_name = 'litecal_lock_' . md5( (string) $key );
		$expires_at  = time() + $ttl;
		if ( add_option( $option_name, (string) $expires_at, '', false ) ) {
			return true;
		}
		$current_expiry = (int) get_option( $option_name, 0 );
		if ( $current_expiry > 0 && $current_expiry < time() ) {
			delete_option( $option_name );
			if ( add_option( $option_name, (string) $expires_at, '', false ) ) {
				return true;
			}
		}
		return false;
	}

	private static function release_lock( $key ) {
		$option_name = 'litecal_lock_' . md5( (string) $key );
		delete_option( $option_name );
	}

	private static function booking_abuse_option_name( $scope, $key ) {
		return 'litecal_booking_abuse_' . sanitize_key( (string) $scope ) . '_' . md5( (string) $key );
	}

	private static function booking_abuse_link_option_name( $email_key ) {
		return 'litecal_booking_abuse_link_' . md5( (string) $email_key );
	}

	private static function booking_abuse_unlock_option_name( $email_key ) {
		return 'litecal_booking_abuse_unlock_' . md5( (string) $email_key );
	}

	private static function booking_abuse_manual_blocks_option_name() {
		return 'litecal_booking_abuse_manual_blocks';
	}

	private static function booking_abuse_manual_blocked_keys() {
		$stored = get_option( self::booking_abuse_manual_blocks_option_name(), array() );
		if ( is_string( $stored ) ) {
			$stored = array_filter( explode( ',', $stored ) );
		}
		if ( ! is_array( $stored ) ) {
			$stored = array();
		}
		$normalized = array();
		foreach ( $stored as $email ) {
			$key = Bookings::normalize_email_key( $email );
			if ( $key !== '' ) {
				$normalized[] = $key;
			}
		}
		return array_values( array_unique( $normalized ) );
	}

	public static function booking_abuse_is_manually_blocked_email( $email ) {
		$email_key = Bookings::normalize_email_key( $email );
		if ( $email_key === '' ) {
			return false;
		}
		return in_array( $email_key, self::booking_abuse_manual_blocked_keys(), true );
	}

	public static function booking_abuse_set_manual_block_for_email( $email, $blocked = true ) {
		$email_key = Bookings::normalize_email_key( $email );
		if ( $email_key === '' ) {
			return false;
		}
		$blocked = ! empty( $blocked );
		$list    = self::booking_abuse_manual_blocked_keys();
		$exists  = in_array( $email_key, $list, true );
		$changed = false;
		if ( $blocked && ! $exists ) {
			$list[] = $email_key;
			$changed = true;
		}
		if ( ! $blocked && $exists ) {
			$list = array_values( array_diff( $list, array( $email_key ) ) );
			$changed = true;
		}
		if ( ! $changed ) {
			return true;
		}
		update_option( self::booking_abuse_manual_blocks_option_name(), array_values( array_unique( $list ) ), false );
		Bookings::bump_cache_version();
		return true;
	}

	private static function booking_abuse_phone_key( $phone ) {
		$phone = preg_replace( '/[^\d\+]/', '', (string) $phone );
		if ( ! is_string( $phone ) || $phone === '' ) {
			return '';
		}
		if ( strpos( $phone, '00' ) === 0 ) {
			$phone = '+' . substr( $phone, 2 );
		}
		if ( strpos( $phone, '+' ) === false ) {
			$phone = '+' . ltrim( $phone, '+' );
		}
		return sanitize_text_field( $phone );
	}

	private static function booking_abuse_device_key( $device_id ) {
		$device_id = strtolower( preg_replace( '/[^a-zA-Z0-9\-_]/', '', (string) $device_id ) );
		if ( ! is_string( $device_id ) ) {
			return '';
		}
		$device_id = trim( $device_id );
		if ( $device_id === '' ) {
			return '';
		}
		return substr( $device_id, 0, 96 );
	}

	private static function booking_abuse_ua_key( $user_agent ) {
		$user_agent = sanitize_text_field( strtolower( trim( preg_replace( '/\s+/', ' ', (string) $user_agent ) ) ) );
		if ( ! is_string( $user_agent ) || $user_agent === '' ) {
			return '';
		}
		return hash( 'sha256', substr( $user_agent, 0, 255 ) );
	}

	private static function booking_abuse_ip_prefix( $ip ) {
		$ip = sanitize_text_field( (string) $ip );
		if ( ! filter_var( $ip, FILTER_VALIDATE_IP ) ) {
			return '';
		}
		$packed = @inet_pton( $ip );
		if ( $packed === false ) {
			return '';
		}
		if ( strlen( $packed ) === 4 ) {
			$parts = unpack( 'C4', $packed );
			if ( ! is_array( $parts ) || count( $parts ) < 3 ) {
				return '';
			}
			return sprintf( '%d.%d.%d.0/24', (int) $parts[1], (int) $parts[2], (int) $parts[3] );
		}
		if ( strlen( $packed ) === 16 ) {
			$hex = bin2hex( substr( $packed, 0, 8 ) );
			return substr( $hex, 0, 4 ) . ':' . substr( $hex, 4, 4 ) . ':' . substr( $hex, 8, 4 ) . ':' . substr( $hex, 12, 4 ) . '::/64';
		}
		return '';
	}

	private static function booking_abuse_signals( $email, $phone = '', $device_id = '', $ip = '', $user_agent = '' ) {
		$ip = sanitize_text_field( (string) $ip );
		$ip_prefix = '';
		if ( strpos( $ip, '/' ) !== false ) {
			$ip_prefix = $ip;
		} else {
			$ip_prefix = self::booking_abuse_ip_prefix( $ip );
		}
		$ua_key = self::booking_abuse_ua_key( $user_agent );
		$signals = array(
			'email'     => Bookings::normalize_email_key( $email ),
			'phone'     => self::booking_abuse_phone_key( $phone ),
			'device'    => self::booking_abuse_device_key( $device_id ),
			'ip_prefix' => $ip_prefix,
			'ua'        => $ua_key,
			'network'   => ( $ip_prefix !== '' && $ua_key !== '' )
				? hash( 'sha256', 'ip:' . $ip_prefix . '|ua:' . $ua_key )
				: '',
		);
		$signals['subject'] = self::booking_abuse_subject_key( $signals );
		return $signals;
	}

	private static function booking_abuse_subject_key( array $signals ) {
		$subject_parts = array();
		$has_strong    = ( $signals['phone'] !== '' || $signals['device'] !== '' );
		if ( $has_strong ) {
			if ( $signals['phone'] !== '' ) {
				$subject_parts[] = 'phone:' . $signals['phone'];
			}
			if ( $signals['device'] !== '' ) {
				$subject_parts[] = 'device:' . $signals['device'];
			}
			if ( $signals['network'] !== '' ) {
				$subject_parts[] = 'network:' . $signals['network'];
			}
			if ( $signals['ip_prefix'] !== '' ) {
				$subject_parts[] = 'ip:' . $signals['ip_prefix'];
			}
		} elseif ( $signals['network'] !== '' ) {
			$subject_parts[] = 'network:' . $signals['network'];
			$subject_parts[] = 'ip:' . $signals['ip_prefix'];
		} elseif ( $signals['email'] !== '' ) {
			$subject_parts[] = 'email:' . $signals['email'];
		} elseif ( $signals['ip_prefix'] !== '' ) {
			$subject_parts[] = 'ip:' . $signals['ip_prefix'];
		}
		return $subject_parts ? hash( 'sha256', implode( '|', $subject_parts ) ) : '';
	}

	private static function booking_abuse_identifiers_from_signals( array $signals, $include_subject = true ) {
		$list = array();
		foreach ( array( 'email', 'phone', 'device', 'ip_prefix', 'network' ) as $scope ) {
			$key = sanitize_text_field( (string) ( $signals[ $scope ] ?? '' ) );
			if ( $key !== '' ) {
				$list[] = array(
					'scope' => $scope,
					'key'   => $key,
				);
			}
		}
		if ( $include_subject ) {
			$subject = sanitize_text_field( (string) ( $signals['subject'] ?? '' ) );
			if ( $subject !== '' ) {
				$list[] = array(
					'scope' => 'subject',
					'key'   => $subject,
				);
			}
		}
		return $list;
	}

	private static function booking_abuse_append_identifier( array &$identifiers, $scope, $key ) {
		$scope = sanitize_key( (string) $scope );
		$key   = sanitize_text_field( (string) $key );
		if ( $scope === '' || $key === '' ) {
			return;
		}
		$identifiers[ $scope . '|' . $key ] = array(
			'scope' => $scope,
			'key'   => $key,
		);
	}

	private static function booking_abuse_state( $scope, $key ) {
		$key_name = self::booking_abuse_option_name( $scope, $key );
		$state    = get_option( $key_name, array() );
		if ( ! is_array( $state ) ) {
			$state = array();
		}
		$codes = array();
		foreach ( (array) ( $state['codes_sent_at'] ?? array() ) as $ts ) {
			$ts = (int) $ts;
			if ( $ts > 0 ) {
				$codes[] = $ts;
			}
		}
		sort( $codes );
		return array(
			'codes_sent_at'       => $codes,
			'active_code_hash'    => sanitize_text_field( (string) ( $state['active_code_hash'] ?? '' ) ),
			'active_code_expires' => max( 0, (int) ( $state['active_code_expires'] ?? 0 ) ),
			'blocked_until'       => max( 0, (int) ( $state['blocked_until'] ?? 0 ) ),
			'blocked_at'          => max( 0, (int) ( $state['blocked_at'] ?? 0 ) ),
			'updated_at'          => max( 0, (int) ( $state['updated_at'] ?? 0 ) ),
		);
	}

	private static function save_booking_abuse_state( $scope, $key, array $state ) {
		$key_name = self::booking_abuse_option_name( $scope, $key );
		if (
			empty( $state['codes_sent_at'] )
			&& empty( $state['active_code_hash'] )
			&& empty( $state['blocked_until'] )
		) {
			delete_option( $key_name );
			return;
		}
		update_option( $key_name, $state, false );
	}

	private static function prune_booking_abuse_state( array $state, $period_hours ) {
		$now            = self::current_ts();
		$period_seconds = max( HOUR_IN_SECONDS, absint( $period_hours ) * HOUR_IN_SECONDS );
		$cutoff         = $now - $period_seconds;
		$codes          = array();
		foreach ( (array) ( $state['codes_sent_at'] ?? array() ) as $sent_at ) {
			$sent_at = (int) $sent_at;
			if ( $sent_at >= $cutoff && $sent_at <= $now ) {
				$codes[] = $sent_at;
			}
		}
		sort( $codes );
		$state['codes_sent_at'] = $codes;
		if ( (int) ( $state['active_code_expires'] ?? 0 ) <= $now ) {
			$state['active_code_hash']    = '';
			$state['active_code_expires'] = 0;
		}
		if ( (int) ( $state['blocked_until'] ?? 0 ) <= $now ) {
			$state['blocked_until'] = 0;
			$state['blocked_at']    = 0;
		}
		$state['updated_at'] = $now;
		return $state;
	}

	private static function booking_abuse_link_state( $email_key ) {
		$email_key = Bookings::normalize_email_key( $email_key );
		if ( $email_key === '' ) {
			return array(
				'updated_at'   => 0,
				'identifiers'  => array(),
			);
		}
		$raw = get_option( self::booking_abuse_link_option_name( $email_key ), array() );
		if ( ! is_array( $raw ) ) {
			$raw = array();
		}
		$identifiers = array();
		foreach ( (array) ( $raw['identifiers'] ?? array() ) as $scope => $keys ) {
			$scope = sanitize_key( (string) $scope );
			if ( $scope === '' || ! is_array( $keys ) ) {
				continue;
			}
			foreach ( $keys as $key => $ts ) {
				$key = sanitize_text_field( (string) $key );
				$ts  = (int) $ts;
				if ( $key === '' || $ts <= 0 ) {
					continue;
				}
				if ( ! isset( $identifiers[ $scope ] ) ) {
					$identifiers[ $scope ] = array();
				}
				$identifiers[ $scope ][ $key ] = $ts;
			}
		}
		return array(
			'updated_at'  => max( 0, (int) ( $raw['updated_at'] ?? 0 ) ),
			'identifiers' => $identifiers,
		);
	}

	private static function save_booking_abuse_link_state( $email_key, array $state ) {
		$email_key = Bookings::normalize_email_key( $email_key );
		if ( $email_key === '' ) {
			return;
		}
		$opt_name = self::booking_abuse_link_option_name( $email_key );
		if ( empty( $state['identifiers'] ) ) {
			delete_option( $opt_name );
			return;
		}
		update_option( $opt_name, $state, false );
	}

	private static function booking_abuse_remember_identifier_links( $email_key, array $identifiers ) {
		$email_key = Bookings::normalize_email_key( $email_key );
		if ( $email_key === '' || empty( $identifiers ) ) {
			return;
		}
		$now    = self::current_ts();
		$state  = self::booking_abuse_link_state( $email_key );
		$stored = is_array( $state['identifiers'] ?? null ) ? $state['identifiers'] : array();
		foreach ( $identifiers as $identifier ) {
			$scope = sanitize_key( (string) ( $identifier['scope'] ?? '' ) );
			$key   = sanitize_text_field( (string) ( $identifier['key'] ?? '' ) );
			if ( $scope === '' || $key === '' ) {
				continue;
			}
			if ( ! isset( $stored[ $scope ] ) || ! is_array( $stored[ $scope ] ) ) {
				$stored[ $scope ] = array();
			}
			$stored[ $scope ][ $key ] = $now;
		}
		$cutoff = $now - self::BOOKING_ABUSE_LINK_TTL;
		foreach ( $stored as $scope => $keys ) {
			foreach ( $keys as $key => $ts ) {
				if ( (int) $ts < $cutoff ) {
					unset( $stored[ $scope ][ $key ] );
				}
			}
			if ( empty( $stored[ $scope ] ) ) {
				unset( $stored[ $scope ] );
			}
		}
		self::save_booking_abuse_link_state(
			$email_key,
			array(
				'updated_at'  => $now,
				'identifiers' => $stored,
			)
		);
	}

	private static function booking_abuse_code_hash( $code ) {
		return hash_hmac( 'sha256', (string) $code, wp_salt( 'litecal_booking_abuse' ) );
	}

	private static function booking_abuse_unlock_since( $email_key, $period_hours ) {
		$email_key = Bookings::normalize_email_key( $email_key );
		if ( $email_key === '' ) {
			return 0;
		}
		$now    = self::current_ts();
		$period = max( HOUR_IN_SECONDS, absint( $period_hours ) * HOUR_IN_SECONDS );
		$ts     = (int) get_option( self::booking_abuse_unlock_option_name( $email_key ), 0 );
		if ( $ts <= 0 ) {
			return 0;
		}
		if ( ( $ts + $period ) <= $now ) {
			delete_option( self::booking_abuse_unlock_option_name( $email_key ) );
			return 0;
		}
		return $ts;
	}

	private static function booking_abuse_collect_identifiers_for_email( $email_key, $include_history = false ) {
		$email_key = Bookings::normalize_email_key( $email_key );
		if ( $email_key === '' ) {
			return array();
		}
		$identifiers = array();
		$base_list   = self::booking_abuse_identifiers_from_signals(
			self::booking_abuse_signals( $email_key ),
			true
		);
		foreach ( $base_list as $identifier ) {
			self::booking_abuse_append_identifier(
				$identifiers,
				$identifier['scope'] ?? '',
				$identifier['key'] ?? ''
			);
		}

		$link_state = self::booking_abuse_link_state( $email_key );
		foreach ( (array) ( $link_state['identifiers'] ?? array() ) as $scope => $keys ) {
			$scope = sanitize_key( (string) $scope );
			if ( $scope === '' || ! is_array( $keys ) ) {
				continue;
			}
			foreach ( $keys as $key => $ts ) {
				self::booking_abuse_append_identifier( $identifiers, $scope, $key );
			}
		}

		if ( $include_history ) {
			global $wpdb;
			$table = $wpdb->prefix . 'litecal_bookings';
			// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name built from $wpdb->prefix.
			$sql  = "SELECT phone, snapshot FROM {$table} WHERE LOWER(TRIM(email)) = %s ORDER BY id DESC LIMIT 250";
			$rows = $wpdb->get_results( $wpdb->prepare( $sql, $email_key ) );
			// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			foreach ( (array) $rows as $row ) {
				$row_snapshot = json_decode( (string) ( $row->snapshot ?? '{}' ), true );
				if ( ! is_array( $row_snapshot ) ) {
					$row_snapshot = array();
				}
				$row_booking  = is_array( $row_snapshot['booking'] ?? null ) ? $row_snapshot['booking'] : array();
				$row_phone    = (string) ( $row->phone ?? '' );
				$row_device   = self::booking_abuse_device_key( (string) ( $row_booking['device_id'] ?? '' ) );
				$row_ip       = sanitize_text_field( (string) ( $row_booking['ip_prefix'] ?? '' ) );
				$row_ua_key   = sanitize_text_field( (string) ( $row_booking['ua_key'] ?? '' ) );
				$row_network  = sanitize_text_field( (string) ( $row_booking['network_key'] ?? '' ) );
				if ( $row_network === '' && $row_ip !== '' && $row_ua_key !== '' ) {
					$row_network = hash( 'sha256', 'ip:' . $row_ip . '|ua:' . $row_ua_key );
				}
				$row_signals = self::booking_abuse_signals( $email_key, $row_phone, $row_device, $row_ip );
				if ( $row_network !== '' ) {
					$row_signals['network'] = $row_network;
				}
				$row_signals['subject'] = self::booking_abuse_subject_key( $row_signals );
				$row_list               = self::booking_abuse_identifiers_from_signals( $row_signals, true );
				foreach ( $row_list as $identifier ) {
					self::booking_abuse_append_identifier(
						$identifiers,
						$identifier['scope'] ?? '',
						$identifier['key'] ?? ''
					);
				}
			}
		}

		return array_values( $identifiers );
	}

	private static function send_booking_abuse_code( $email, $code ) {
		$email = sanitize_email( (string) $email );
		if ( $email === '' ) {
			return false;
		}
		$subject = sprintf(
			/* translators: %s: site name. */
			__( 'Código de verificación para tu reserva en %s', 'agenda-lite' ),
			wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES )
		);
		$body    = sprintf(
			/* translators: %s: six-digit verification code. */
			__( "Tu código de verificación es: %s\n\nEste código vence en 15 minutos.\nSi no solicitaste esta reserva, puedes ignorar este correo.", 'agenda-lite' ),
			(string) $code
		);
		return (bool) wp_mail(
			$email,
			$subject,
			$body,
			array( 'Content-Type: text/plain; charset=UTF-8' )
		);
	}

	private static function count_recent_bookings_by_signals( array $signals, $period_hours, $since_ts = 0 ) {
		$counts = array(
			'email'     => 0,
			'phone'     => 0,
			'device'    => 0,
			'ip_prefix' => 0,
			'network'   => 0,
			'subject'   => 0,
		);
		$period_hours = max( 1, absint( $period_hours ) );
		$cutoff_ts    = self::current_ts() - ( $period_hours * HOUR_IN_SECONDS );
		if ( (int) $since_ts > 0 ) {
			$cutoff_ts = max( $cutoff_ts, (int) $since_ts );
		}
		$cutoff       = wp_date( 'Y-m-d H:i:s', $cutoff_ts, wp_timezone() );
		global $wpdb;
		$table      = $wpdb->prefix . 'litecal_bookings';
		$where_parts = array(
			$wpdb->prepare( 'created_at >= %s', $cutoff ),
			"COALESCE(status, '') <> 'deleted'",
		);
		$match_ors   = array();
		if ( ! empty( $signals['email'] ) ) {
			$match_ors[] = $wpdb->prepare( 'LOWER(TRIM(email)) = %s', (string) $signals['email'] );
		}
		if ( ! empty( $signals['phone'] ) ) {
			$phone_digits = preg_replace( '/\D+/', '', (string) $signals['phone'] );
			if ( is_string( $phone_digits ) && strlen( $phone_digits ) >= 6 ) {
				$match_ors[] = $wpdb->prepare( 'phone LIKE %s', '%' . $wpdb->esc_like( $phone_digits ) . '%' );
			}
		}
		if ( ! empty( $signals['device'] ) ) {
			$match_ors[] = $wpdb->prepare( 'snapshot LIKE %s', '%' . $wpdb->esc_like( (string) $signals['device'] ) . '%' );
		}
		if ( ! empty( $signals['ip_prefix'] ) ) {
			$match_ors[] = $wpdb->prepare( 'snapshot LIKE %s', '%' . $wpdb->esc_like( (string) $signals['ip_prefix'] ) . '%' );
		}
		if ( ! empty( $signals['network'] ) ) {
			$match_ors[] = $wpdb->prepare( 'snapshot LIKE %s', '%' . $wpdb->esc_like( (string) $signals['network'] ) . '%' );
		}
		if ( ! empty( $match_ors ) ) {
			$where_parts[] = '(' . implode( ' OR ', $match_ors ) . ')';
		}
		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- WHERE clauses are prepared above; table name comes from trusted $wpdb->prefix.
		$prepared_sql = "SELECT email, phone, snapshot FROM {$table} WHERE " . implode( ' AND ', $where_parts );
		$rows = $wpdb->get_results( $prepared_sql );
		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		foreach ( (array) $rows as $row ) {
			$row_email    = Bookings::normalize_email_key( (string) ( $row->email ?? '' ) );
			$row_phone    = self::booking_abuse_phone_key( (string) ( $row->phone ?? '' ) );
			$row_snapshot = json_decode( (string) ( $row->snapshot ?? '{}' ), true );
			if ( ! is_array( $row_snapshot ) ) {
				$row_snapshot = array();
			}
			$row_booking   = is_array( $row_snapshot['booking'] ?? null ) ? $row_snapshot['booking'] : array();
			$row_device    = self::booking_abuse_device_key( (string) ( $row_booking['device_id'] ?? '' ) );
			$row_ip_prefix = sanitize_text_field( (string) ( $row_booking['ip_prefix'] ?? '' ) );
			$row_ua_key    = sanitize_text_field( (string) ( $row_booking['ua_key'] ?? '' ) );
			$row_network   = sanitize_text_field( (string) ( $row_booking['network_key'] ?? '' ) );
			if ( $row_network === '' && $row_ip_prefix !== '' && $row_ua_key !== '' ) {
				$row_network = hash( 'sha256', 'ip:' . $row_ip_prefix . '|ua:' . $row_ua_key );
			}
			$row_signals   = self::booking_abuse_signals( $row_email, $row_phone, $row_device, $row_ip_prefix, $row_ua_key );
			if ( ! empty( $signals['email'] ) && $row_email === $signals['email'] ) {
				++$counts['email'];
			}
			if ( ! empty( $signals['phone'] ) && $row_phone !== '' && $row_phone === $signals['phone'] ) {
				++$counts['phone'];
			}
			if ( ! empty( $signals['device'] ) && $row_device !== '' && $row_device === $signals['device'] ) {
				++$counts['device'];
			}
			if ( ! empty( $signals['ip_prefix'] ) && $row_ip_prefix !== '' && $row_ip_prefix === $signals['ip_prefix'] ) {
				++$counts['ip_prefix'];
			}
			if ( ! empty( $signals['network'] ) && $row_network !== '' && $row_network === $signals['network'] ) {
				++$counts['network'];
			}
			if ( ! empty( $signals['subject'] ) && ! empty( $row_signals['subject'] ) && hash_equals( (string) $row_signals['subject'], (string) $signals['subject'] ) ) {
				++$counts['subject'];
			}
		}
		return $counts;
	}

	private static function booking_abuse_guard_identifier( array $signals ) {
		$order = array( 'device', 'network', 'phone', 'email', 'ip_prefix', 'subject' );
		foreach ( $order as $scope ) {
			$key = sanitize_text_field( (string) ( $signals[ $scope ] ?? '' ) );
			if ( $key !== '' ) {
				return array(
					'scope' => $scope,
					'key'   => $key,
				);
			}
		}
		return array(
			'scope' => 'email',
			'key'   => sanitize_text_field( (string) ( $signals['email'] ?? '' ) ),
		);
	}

	private static function booking_abuse_active_block( array $signals, $period_hours ) {
		$now          = self::current_ts();
		$identifiers  = self::booking_abuse_identifiers_from_signals( $signals, true );
		foreach ( $identifiers as $identifier ) {
			$scope = sanitize_key( (string) ( $identifier['scope'] ?? '' ) );
			$key   = sanitize_text_field( (string) ( $identifier['key'] ?? '' ) );
			if ( $scope === '' || $key === '' ) {
				continue;
			}
			$state = self::prune_booking_abuse_state( self::booking_abuse_state( $scope, $key ), $period_hours );
			self::save_booking_abuse_state( $scope, $key, $state );
			$blocked_until = (int) ( $state['blocked_until'] ?? 0 );
			if ( $blocked_until > $now ) {
				return array(
					'blocked_until' => $blocked_until,
					'scope'         => $scope,
				);
			}
		}
		return array();
	}

	private static function booking_abuse_block_identifiers( array $signals, $period_hours ) {
		$now         = self::current_ts();
		$block_until = $now + ( max( 1, absint( $period_hours ) ) * HOUR_IN_SECONDS );
		$identifiers = self::booking_abuse_identifiers_from_signals( $signals, true );
		foreach ( $identifiers as $identifier ) {
			$scope = sanitize_key( (string) ( $identifier['scope'] ?? '' ) );
			$key   = sanitize_text_field( (string) ( $identifier['key'] ?? '' ) );
			if ( $scope === '' || $key === '' ) {
				continue;
			}
			$state                  = self::booking_abuse_state( $scope, $key );
			$state['blocked_until'] = max( (int) ( $state['blocked_until'] ?? 0 ), $block_until );
			$state['blocked_at']    = max( (int) ( $state['blocked_at'] ?? 0 ), $now );
			$state['active_code_hash']    = '';
			$state['active_code_expires'] = 0;
			$state = self::prune_booking_abuse_state( $state, $period_hours );
			self::save_booking_abuse_state( $scope, $key, $state );
		}
		return $block_until;
	}

	public static function booking_abuse_status_for_email( $email, $include_history = true ) {
		$email_key = Bookings::normalize_email_key( $email );
		if ( $email_key === '' ) {
			return array(
				'is_blocked'    => false,
				'has_state'     => false,
				'manual_blocked'=> false,
				'unlock_since'  => 0,
				'blocked_until' => 0,
				'scope'         => '',
			);
		}
		$manual_blocked = self::booking_abuse_is_manually_blocked_email( $email_key );
		if ( $manual_blocked ) {
			return array(
				'is_blocked'     => true,
				'has_state'      => true,
				'manual_blocked' => true,
				'unlock_since'   => 0,
				'blocked_until'  => 0,
				'scope'          => 'manual',
			);
		}
		$settings     = get_option( 'litecal_settings', array() );
		$period_hours = max( 1, (int) ( $settings['manage_abuse_period_hours'] ?? 24 ) );
		$now          = self::current_ts();
		$unlock_since = self::booking_abuse_unlock_since( $email_key, $period_hours );
		$identifiers  = self::booking_abuse_collect_identifiers_for_email( $email_key, ! empty( $include_history ) );

		$blocked_until = 0;
		$blocked_scope = '';
		$has_state     = false;
		foreach ( $identifiers as $identifier ) {
			$scope = sanitize_key( (string) ( $identifier['scope'] ?? '' ) );
			$key   = sanitize_text_field( (string) ( $identifier['key'] ?? '' ) );
			if ( $scope === '' || $key === '' ) {
				continue;
			}
			$state         = self::prune_booking_abuse_state( self::booking_abuse_state( $scope, $key ), $period_hours );
			$current_until = (int) ( $state['blocked_until'] ?? 0 );
			$current_blocked_at = max( 0, (int) ( $state['blocked_at'] ?? 0 ) );
			self::save_booking_abuse_state( $scope, $key, $state );
			if (
				( $current_until > $now && ( $unlock_since <= 0 || $current_blocked_at > $unlock_since ) )
				|| ! empty( $state['active_code_hash'] )
			) {
				$has_state = true;
			}
			if (
				$current_until > $blocked_until
				&& ( $unlock_since <= 0 || $current_blocked_at > $unlock_since )
			) {
				$blocked_until = $current_until;
				$blocked_scope = $scope;
			}
		}

		$is_blocked = ( $blocked_until > $now );

		return array(
			'is_blocked'    => $is_blocked,
			'has_state'     => $has_state,
			'manual_blocked'=> false,
			'unlock_since'  => $unlock_since,
			'blocked_until' => $is_blocked ? $blocked_until : 0,
			'scope'         => $is_blocked ? $blocked_scope : '',
		);
	}

	public static function reset_booking_abuse_for_email( $email ) {
		$email_key = Bookings::normalize_email_key( $email );
		if ( $email_key === '' ) {
			return false;
		}
		self::booking_abuse_set_manual_block_for_email( $email_key, false );
		delete_option( self::booking_abuse_option_name( 'email', $email_key ) );
		delete_option( 'litecal_booking_abuse_' . md5( (string) $email_key ) );
		$identifiers = self::booking_abuse_collect_identifiers_for_email( $email_key, true );
		foreach ( $identifiers as $identifier ) {
			$scope = sanitize_key( (string) ( $identifier['scope'] ?? '' ) );
			$key   = sanitize_text_field( (string) ( $identifier['key'] ?? '' ) );
			if ( $scope === '' || $key === '' ) {
				continue;
			}
			delete_option( self::booking_abuse_option_name( $scope, $key ) );
		}
		delete_option( self::booking_abuse_link_option_name( $email_key ) );
		update_option( self::booking_abuse_unlock_option_name( $email_key ), self::current_ts(), false );
		return true;
	}

	private static function enforce_booking_abuse_guard( $event, $email, $phone, $device_id, $ip, $user_agent, $price_mode, $charge_amount, $provided_code = '' ) {
		unset( $price_mode, $charge_amount );
		$signals      = self::booking_abuse_signals( $email, $phone, $device_id, $ip, $user_agent );
		$email_key    = (string) ( $signals['email'] ?? '' );
		if ( $email_key === '' ) {
			return true;
		}
		if ( self::booking_abuse_is_manually_blocked_email( $email_key ) ) {
			return self::rest_error(
				'abuse_blocked',
				__( 'Este cliente no puede crear nuevas reservas.', 'agenda-lite' ),
				array(
					'status'            => 429,
					'blocked_permanent' => 1,
				)
			);
		}
		$policy       = self::event_manage_policy( $event );
		$limit        = max( 0, (int) ( $policy['abuse_limit'] ?? 3 ) );
		$max_codes    = max( 1, (int) ( $policy['abuse_max_codes'] ?? 2 ) );
		$period_hours = max( 1, (int) ( $policy['abuse_period_hours'] ?? 24 ) );
		$unlock_since = self::booking_abuse_unlock_since( $email_key, $period_hours );
		$counts       = self::count_recent_bookings_by_signals( $signals, $period_hours, $unlock_since );
		$strongest    = max(
			(int) ( $counts['email'] ?? 0 ),
			(int) ( $counts['phone'] ?? 0 ),
			(int) ( $counts['device'] ?? 0 ),
			(int) ( $counts['network'] ?? 0 ),
			(int) ( $counts['subject'] ?? 0 ),
			max( 0, (int) ( $counts['ip_prefix'] ?? 0 ) - 2 )
		);
		$combined     = (int) ( $counts['email'] ?? 0 )
			+ (int) ( $counts['phone'] ?? 0 )
			+ (int) ( $counts['device'] ?? 0 )
			+ (int) ( $counts['network'] ?? 0 )
			+ (int) ( $counts['subject'] ?? 0 )
			+ max( 0, (int) ( $counts['ip_prefix'] ?? 0 ) - 1 );
		$threshold_reached = $strongest >= $limit || ( $limit > 0 && $combined >= ( $limit * 2 ) ) || ( $limit <= 0 && $strongest > 0 );
		if ( ! $threshold_reached ) {
			return true;
		}

		$identifiers_for_link = self::booking_abuse_identifiers_from_signals( $signals, true );
		self::booking_abuse_remember_identifier_links( $email_key, $identifiers_for_link );

		if ( $unlock_since <= 0 ) {
			$blocked = self::booking_abuse_active_block( $signals, $period_hours );
			if ( ! empty( $blocked['blocked_until'] ) ) {
				return self::rest_error(
					'abuse_blocked',
					__( 'Este cliente fue bloqueado temporalmente por múltiples solicitudes de reserva.', 'agenda-lite' ),
					array(
						'status'             => 429,
						'abuse_limit'        => $limit,
						'abuse_max_codes'    => $max_codes,
						'abuse_period_hours' => $period_hours,
						'blocked_until'      => (int) $blocked['blocked_until'],
						'blocked_scope'      => sanitize_key( (string) ( $blocked['scope'] ?? '' ) ),
					)
				);
			}
		}

		$guard            = self::booking_abuse_guard_identifier( $signals );
		$guard_scope      = sanitize_key( (string) ( $guard['scope'] ?? '' ) );
		$guard_key        = sanitize_text_field( (string) ( $guard['key'] ?? '' ) );
		if ( $guard_scope === '' || $guard_key === '' ) {
			$guard_scope = 'email';
			$guard_key   = $email_key;
		}
		$same_guard_email = ( $guard_scope === 'email' && $guard_key === $email_key );
		$state            = self::prune_booking_abuse_state( self::booking_abuse_state( $guard_scope, $guard_key ), $period_hours );
		$email_state      = $same_guard_email
			? $state
			: self::prune_booking_abuse_state( self::booking_abuse_state( 'email', $email_key ), $period_hours );
		$provided_code = preg_replace( '/\s+/', '', sanitize_text_field( (string) $provided_code ) );
		$provided_hash = $provided_code !== '' ? self::booking_abuse_code_hash( $provided_code ) : '';
		$now           = self::current_ts();
		$guard_valid   = (
			$provided_hash !== ''
			&& ! empty( $state['active_code_hash'] )
			&& (int) ( $state['active_code_expires'] ?? 0 ) > $now
			&& hash_equals( (string) $state['active_code_hash'], $provided_hash )
		);
		$email_valid   = (
			$provided_hash !== ''
			&& ! empty( $email_state['active_code_hash'] )
			&& (int) ( $email_state['active_code_expires'] ?? 0 ) > $now
			&& hash_equals( (string) $email_state['active_code_hash'], $provided_hash )
		);
		if ( $guard_valid || $email_valid ) {
			$state['active_code_hash']         = '';
			$state['active_code_expires']      = 0;
			$email_state['active_code_hash']   = '';
			$email_state['active_code_expires']= 0;
			$state = self::prune_booking_abuse_state( $state, $period_hours );
			self::save_booking_abuse_state( $guard_scope, $guard_key, $state );
			if ( ! $same_guard_email ) {
				$email_state = self::prune_booking_abuse_state( $email_state, $period_hours );
				self::save_booking_abuse_state( 'email', $email_key, $email_state );
			}
			return true;
		}

		if ( $provided_hash !== '' && ( ! empty( $state['active_code_hash'] ) || ! empty( $email_state['active_code_hash'] ) ) ) {
			self::save_booking_abuse_state( $guard_scope, $guard_key, $state );
			if ( ! $same_guard_email ) {
				self::save_booking_abuse_state( 'email', $email_key, $email_state );
			}
			$codes_sent = max(
				count( (array) ( $state['codes_sent_at'] ?? array() ) ),
				count( (array) ( $email_state['codes_sent_at'] ?? array() ) )
			);
			return self::rest_error(
				'abuse_verification_required',
				__( 'El código es inválido o expiró. Revisa tu correo e intenta nuevamente.', 'agenda-lite' ),
				array(
					'status'             => 429,
					'abuse_limit'        => $limit,
					'abuse_max_codes'    => $max_codes,
					'abuse_period_hours' => $period_hours,
					'codes_sent'         => $codes_sent,
					'codes_remaining'    => max( 0, $max_codes - $codes_sent ),
				)
			);
		}

		$codes_sent = max(
			count( (array) ( $state['codes_sent_at'] ?? array() ) ),
			count( (array) ( $email_state['codes_sent_at'] ?? array() ) )
		);
		if ( $codes_sent >= $max_codes ) {
			$blocked_until = self::booking_abuse_block_identifiers( $signals, $period_hours );
			return self::rest_error(
				'abuse_blocked',
				__( 'Este cliente no puede crear nuevas reservas temporalmente.', 'agenda-lite' ),
				array(
					'status'             => 429,
					'abuse_limit'        => $limit,
					'abuse_max_codes'    => $max_codes,
					'abuse_period_hours' => $period_hours,
					'codes_sent'         => $codes_sent,
					'blocked_until'      => $blocked_until,
				)
			);
		}

		$code = str_pad( (string) wp_rand( 0, 999999 ), 6, '0', STR_PAD_LEFT );
		if ( ! self::send_booking_abuse_code( $email_key, $code ) ) {
			return self::rest_error(
				'abuse_code_email_failed',
				__( 'No pudimos enviar el código de verificación. Intenta nuevamente.', 'agenda-lite' ),
				array( 'status' => 500 )
			);
		}
		$state['codes_sent_at'][]     = $now;
		$state['active_code_hash']    = self::booking_abuse_code_hash( $code );
		$state['active_code_expires'] = $now + self::BOOKING_ABUSE_CODE_TTL;
		$state                        = self::prune_booking_abuse_state( $state, $period_hours );
		self::save_booking_abuse_state( $guard_scope, $guard_key, $state );
		if ( ! $same_guard_email ) {
			$email_state['codes_sent_at'][]      = $now;
			$email_state['active_code_hash']     = $state['active_code_hash'];
			$email_state['active_code_expires']  = $state['active_code_expires'];
			$email_state                         = self::prune_booking_abuse_state( $email_state, $period_hours );
			self::save_booking_abuse_state( 'email', $email_key, $email_state );
		}
		$codes_sent = max(
			count( (array) ( $state['codes_sent_at'] ?? array() ) ),
			count( (array) ( $email_state['codes_sent_at'] ?? array() ) )
		);

		return self::rest_error(
			'abuse_verification_required',
			__( 'Ingresa el código enviado a tu correo para continuar.', 'agenda-lite' ),
			array(
				'status'             => 429,
				'abuse_limit'        => $limit,
				'abuse_max_codes'    => $max_codes,
				'abuse_period_hours' => $period_hours,
				'codes_sent'         => $codes_sent,
				'codes_remaining'    => max( 0, $max_codes - $codes_sent ),
				'signals'            => array(
					'email'   => (int) ( $counts['email'] ?? 0 ),
					'phone'   => (int) ( $counts['phone'] ?? 0 ),
					'device'  => (int) ( $counts['device'] ?? 0 ),
					'network' => (int) ( $counts['network'] ?? 0 ),
					'ip'      => (int) ( $counts['ip_prefix'] ?? 0 ),
				),
			)
		);
	}

	private static function manage_defaults() {
		$settings = get_option( 'litecal_settings', array() );
		if ( self::free_plan_restrictions_enabled() ) {
			return array(
				'reschedule_enabled'      => false,
				'cancel_free_enabled'     => false,
				'cancel_paid_enabled'     => false,
				'reschedule_cutoff_hours' => 12,
				'cancel_cutoff_hours'     => 24,
				'grace_minutes'           => 15,
				'max_reschedules'         => 0,
				'cooldown_minutes'        => 10,
				'token_limit_hour'        => max( 1, (int) ( $settings['manage_rate_limit_token'] ?? 20 ) ),
				'ip_limit_hour'           => max( 1, (int) ( $settings['manage_rate_limit_ip'] ?? 60 ) ),
				'allow_change_staff'      => false,
				'abuse_limit'             => max( 0, (int) ( $settings['manage_abuse_limit'] ?? 3 ) ),
				'abuse_max_codes'         => max( 1, (int) ( $settings['manage_abuse_max_codes'] ?? 2 ) ),
				'abuse_period_hours'      => max( 1, (int) ( $settings['manage_abuse_period_hours'] ?? 24 ) ),
			);
		}
		return array(
			'reschedule_enabled'      => ! array_key_exists( 'manage_reschedule_enabled', $settings ) || ! empty( $settings['manage_reschedule_enabled'] ),
			'cancel_free_enabled'     => ! array_key_exists( 'manage_cancel_free_enabled', $settings ) || ! empty( $settings['manage_cancel_free_enabled'] ),
			'cancel_paid_enabled'     => ! empty( $settings['manage_cancel_paid_enabled'] ),
			'reschedule_cutoff_hours' => max( 0, (int) ( $settings['manage_reschedule_cutoff_hours'] ?? 12 ) ),
			'cancel_cutoff_hours'     => max( 0, (int) ( $settings['manage_cancel_cutoff_hours'] ?? 24 ) ),
			'grace_minutes'           => max( 0, (int) ( $settings['manage_grace_minutes'] ?? 15 ) ),
			'max_reschedules'         => max( 0, (int) ( $settings['manage_max_reschedules'] ?? 1 ) ),
			'cooldown_minutes'        => max( 0, (int) ( $settings['manage_cooldown_minutes'] ?? 10 ) ),
			'token_limit_hour'        => max( 1, (int) ( $settings['manage_rate_limit_token'] ?? 20 ) ),
			'ip_limit_hour'           => max( 1, (int) ( $settings['manage_rate_limit_ip'] ?? 60 ) ),
			'allow_change_staff'      => ! empty( $settings['manage_allow_change_staff'] ),
			'abuse_limit'             => max( 0, (int) ( $settings['manage_abuse_limit'] ?? 3 ) ),
			'abuse_max_codes'         => max( 1, (int) ( $settings['manage_abuse_max_codes'] ?? 2 ) ),
			'abuse_period_hours'      => max( 1, (int) ( $settings['manage_abuse_period_hours'] ?? 24 ) ),
		);
	}

	private static function event_manage_policy( $event, $booking = null ) {
		$defaults = self::manage_defaults();
		$options  = json_decode( (string) ( $event->options ?? '[]' ), true );
		if ( ! is_array( $options ) ) {
			$options = array();
		}
		$use_global = array_key_exists( 'manage_use_global', $options )
			? ! empty( $options['manage_use_global'] )
			: ( ! array_key_exists( 'manage_override', $options ) || ! empty( $options['manage_override'] ) );
		if ( self::free_plan_restrictions_enabled() ) {
			$use_global = true;
		}
		$policy = $defaults;
		if ( ! $use_global ) {
			$policy['reschedule_enabled']      = ! empty( $options['manage_reschedule_enabled'] );
			$policy['cancel_free_enabled']     = ! empty( $options['manage_cancel_free_enabled'] );
			$policy['cancel_paid_enabled']     = ! empty( $options['manage_cancel_paid_enabled'] );
			$policy['reschedule_cutoff_hours'] = max( 0, (int) ( $options['manage_reschedule_cutoff_hours'] ?? $defaults['reschedule_cutoff_hours'] ) );
			$policy['cancel_cutoff_hours']     = max( 0, (int) ( $options['manage_cancel_cutoff_hours'] ?? $defaults['cancel_cutoff_hours'] ) );
			$policy['grace_minutes']           = max( 0, (int) ( $options['manage_grace_minutes'] ?? $defaults['grace_minutes'] ) );
			$policy['max_reschedules']         = max( 0, (int) ( $options['manage_max_reschedules'] ?? $defaults['max_reschedules'] ) );
			$policy['cooldown_minutes']        = max( 0, (int) ( $options['manage_cooldown_minutes'] ?? $defaults['cooldown_minutes'] ) );
			$policy['allow_change_staff']      = ! empty( $options['manage_allow_change_staff'] );
		}
		$policy['override']       = $use_global ? 0 : 1;
		$policy['abuse_override'] = 0;
		if ( $booking ) {
			$is_paid                      = self::booking_is_paid( $booking );
			$policy['cancel_enabled']     = $is_paid ? $policy['cancel_paid_enabled'] : $policy['cancel_free_enabled'];
			$policy['cancel_policy_type'] = $is_paid ? 'paid' : 'free';
			$policy['is_paid_booking']    = $is_paid ? 1 : 0;
		} else {
			$policy['cancel_enabled']     = 0;
			$policy['cancel_policy_type'] = 'free';
			$policy['is_paid_booking']    = 0;
		}
		return $policy;
	}

	private static function booking_is_paid( $booking ) {
		$paid_statuses  = array( 'paid', 'approved', 'completed', 'confirmed' );
		$payment_status = strtolower( (string) ( $booking->payment_status ?? '' ) );
		if ( in_array( $payment_status, $paid_statuses, true ) ) {
			return true;
		}
		return ( (float) ( $booking->payment_amount ?? 0 ) > 0 ) && ! empty( $booking->payment_provider );
	}

	private static function booking_manage_meta( $booking ) {
		$snapshot = Bookings::decode_snapshot( $booking );
		$manage   = is_array( $snapshot['manage'] ?? null ) ? $snapshot['manage'] : array();
		$history  = is_array( $manage['history'] ?? null ) ? $manage['history'] : array();
		return array(
			'snapshot' => $snapshot,
			'manage'   => array(
				'reschedule_count'   => max( 0, (int) ( $manage['reschedule_count'] ?? 0 ) ),
				'last_reschedule_at' => sanitize_text_field( (string) ( $manage['last_reschedule_at'] ?? '' ) ),
				'cancel_count'       => max( 0, (int) ( $manage['cancel_count'] ?? 0 ) ),
				'history'            => $history,
			),
		);
	}

	private static function save_booking_snapshot( $booking_id, array $snapshot ) {
		global $wpdb;
		$table   = $wpdb->prefix . 'litecal_bookings';
		$updated = $wpdb->update(
			$table,
			array(
				'snapshot'   => wp_json_encode( $snapshot ),
				'updated_at' => current_time( 'mysql' ),
			),
			array( 'id' => (int) $booking_id )
		);
		if ( $updated !== false ) {
			Bookings::bump_cache_version();
		}
		return $updated;
	}

	private static function manage_urls( $event, $booking, $booking_token = '' ) {
		$booking_id = (int) ( $booking->id ?? 0 );
		if ( $booking_id <= 0 ) {
			return array(
				'manage'     => '',
				'reschedule' => '',
				'cancel'     => '',
			);
		}
		if ( $booking_token === '' ) {
			$booking_token = self::booking_access_token( $booking );
		}
		$base   = trailingslashit( home_url( '/' . trim( (string) ( $event->slug ?? '' ), '/' ) . '/' ) );
		$common = array(
			'agendalite_manage' => '1',
			'booking_id'        => $booking_id,
			'booking_token'     => $booking_token,
		);
		return array(
			'manage'     => add_query_arg( $common, $base ),
			'reschedule' => add_query_arg( array_merge( $common, array( 'manage_action' => 'reschedule' ) ), $base ),
			'cancel'     => add_query_arg( array_merge( $common, array( 'manage_action' => 'cancel' ) ), $base ),
		);
	}

	private static function manage_state_for_booking( $booking, $event, $booking_token = '' ) {
		$policy          = self::event_manage_policy( $event, $booking );
		$meta_pack       = self::booking_manage_meta( $booking );
		$meta            = $meta_pack['manage'];
		$urls            = self::manage_urls( $event, $booking, $booking_token );
		$reschedule_eval = self::evaluate_manage_action( $booking, $policy, $meta, 'reschedule' );
		$cancel_eval     = self::evaluate_manage_action( $booking, $policy, $meta, 'cancel' );
		return array(
			'policy'     => $policy,
			'reschedule' => $reschedule_eval,
			'cancel'     => $cancel_eval,
			'meta'       => array(
				'reschedule_count'   => (int) ( $meta['reschedule_count'] ?? 0 ),
				'last_reschedule_at' => (string) ( $meta['last_reschedule_at'] ?? '' ),
				'cancel_count'       => (int) ( $meta['cancel_count'] ?? 0 ),
			),
			'urls'       => $urls,
		);
	}

	private static function evaluate_manage_action( $booking, array $policy, array $meta, $action ) {
		$action         = sanitize_key( (string) $action );
		$now_ts         = self::current_ts();
		$start_ts       = self::wp_datetime_to_ts( (string) ( $booking->start_datetime ?? '' ) );
		$created_ts     = self::wp_datetime_to_ts( (string) ( $booking->created_at ?? '' ) );
		$current_status = sanitize_key( (string) ( $booking->status ?? '' ) );
		if ( in_array( $current_status, array( 'cancelled', 'deleted', 'expired' ), true ) ) {
			return array(
				'allowed'     => 0,
				'reason_code' => 'invalid_status',
				'reason'      => __( 'Esta reserva ya no permite cambios.', 'agenda-lite' ),
			);
		}
		if ( $start_ts <= 0 || $start_ts <= $now_ts ) {
			return array(
				'allowed'     => 0,
				'reason_code' => 'past_booking',
				'reason'      => __( 'La reserva ya comenzó o terminó.', 'agenda-lite' ),
			);
		}
		$grace_minutes = max( 0, (int) ( $policy['grace_minutes'] ?? 0 ) );
		$in_grace      = $grace_minutes > 0 && $created_ts > 0 && $now_ts <= ( $created_ts + ( $grace_minutes * 60 ) );

		if ( $action === 'reschedule' ) {
			if ( empty( $policy['reschedule_enabled'] ) ) {
				return array(
					'allowed'     => 0,
					'reason_code' => 'reschedule_disabled',
					'reason'      => __( 'La opción de reagendar por cliente está desactivada.', 'agenda-lite' ),
				);
			}
			$max_reschedules = max( 0, (int) ( $policy['max_reschedules'] ?? 0 ) );
			if ( $max_reschedules > 0 && (int) ( $meta['reschedule_count'] ?? 0 ) >= $max_reschedules ) {
				return array(
					'allowed'     => 0,
					'reason_code' => 'max_reschedules',
					'reason'      => __( 'Ya usaste el máximo de cambios permitido.', 'agenda-lite' ),
				);
			}
			$cooldown_minutes   = max( 0, (int) ( $policy['cooldown_minutes'] ?? 0 ) );
			$last_reschedule_ts = self::wp_datetime_to_ts( (string) ( $meta['last_reschedule_at'] ?? '' ) );
			if ( $cooldown_minutes > 0 && $last_reschedule_ts > 0 ) {
				$next_change_ts = $last_reschedule_ts + ( $cooldown_minutes * 60 );
				if ( $now_ts < $next_change_ts ) {
					return array(
						'allowed'     => 0,
						'reason_code' => 'cooldown',
						'reason'      => __( 'Debes esperar antes de volver a reagendar.', 'agenda-lite' ),
					);
				}
			}
			$cutoff_hours = max( 0, (int) ( $policy['reschedule_cutoff_hours'] ?? 0 ) );
			if ( $cutoff_hours > 0 && ! $in_grace ) {
				$cutoff_ts = $start_ts - ( $cutoff_hours * HOUR_IN_SECONDS );
				if ( $now_ts > $cutoff_ts ) {
					return array(
						'allowed'     => 0,
						'reason_code' => 'reschedule_cutoff',
						/* translators: %d: minimum hours before appointment to reschedule. */
						'reason'      => sprintf( __( 'Estás dentro del período de %d horas para reagendar.', 'agenda-lite' ), $cutoff_hours ),
					);
				}
			}
			return array(
				'allowed'     => 1,
				'reason_code' => 'ok',
				'reason'      => '',
			);
		}

		if ( empty( $policy['cancel_enabled'] ) ) {
			return array(
				'allowed'     => 0,
				'reason_code' => 'cancel_disabled',
				'reason'      => __( 'La cancelación por cliente está desactivada para esta reserva.', 'agenda-lite' ),
			);
		}
		$cutoff_hours = max( 0, (int) ( $policy['cancel_cutoff_hours'] ?? 0 ) );
		if ( $cutoff_hours > 0 && ! $in_grace ) {
			$cutoff_ts = $start_ts - ( $cutoff_hours * HOUR_IN_SECONDS );
			if ( $now_ts > $cutoff_ts ) {
				return array(
					'allowed'     => 0,
					'reason_code' => 'cancel_cutoff',
					/* translators: %d: minimum hours before appointment to cancel. */
					'reason'      => sprintf( __( 'Estás dentro del período de %d horas para cancelar.', 'agenda-lite' ), $cutoff_hours ),
				);
			}
		}
		if ( (int) ( $meta['cancel_count'] ?? 0 ) > 0 ) {
			return array(
				'allowed'     => 0,
				'reason_code' => 'already_cancelled_once',
				'reason'      => __( 'Esta reserva ya fue cancelada previamente.', 'agenda-lite' ),
			);
		}
		return array(
			'allowed'     => 1,
			'reason_code' => 'ok',
			'reason'      => '',
		);
	}

	private static function manage_rate_limit( $request, $booking, array $policy, $action ) {
		if ( self::can_manage_bookings() ) {
			return true;
		}
		$action           = sanitize_key( (string) $action );
		$token_limit_hour = max( 1, (int) ( $policy['token_limit_hour'] ?? 20 ) );
		if ( $action === 'reschedule' ) {
			$max_reschedules = max( 0, (int) ( $policy['max_reschedules'] ?? 0 ) );
			if ( $max_reschedules > 0 ) {
				// Avoid conflicting settings where max changes > token requests allowed.
				$token_limit_hour = max( $token_limit_hour, $max_reschedules + 2 );
			}
		} elseif ( $action === 'cancel' ) {
			$token_limit_hour = max( $token_limit_hour, 2 );
		}
		$request_token = sanitize_text_field( (string) $request->get_param( 'booking_token' ) );
		if ( $request_token !== '' ) {
			$token_hash  = substr( md5( $request_token ), 0, 16 );
			$token_limit = self::rate_limit( "manage:{$action}:token:{$token_hash}", $token_limit_hour, HOUR_IN_SECONDS );
			if ( is_wp_error( $token_limit ) ) {
				return $token_limit;
			}
		}
		$ip_scope = 'manage:' . $action . ':booking:' . (int) ( $booking->id ?? 0 );
		$ip_limit = self::rate_limit( $ip_scope, max( 1, (int) ( $policy['ip_limit_hour'] ?? 60 ) ), HOUR_IN_SECONDS );
		if ( is_wp_error( $ip_limit ) ) {
			return $ip_limit;
		}
		return true;
	}

	private static function sanitize_manage_reason( $reason ) {
		$reason = sanitize_text_field( (string) $reason );
		if ( $reason === '' ) {
			return '';
		}
		$allowed = array( 'no_puedo', 'me_equivoque', 'cambio_planes', 'otro' );
		if ( in_array( $reason, $allowed, true ) ) {
			return $reason;
		}
		return 'otro';
	}

	private static function client_user_agent() {
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Sanitized with wp_unslash/sanitize_text_field.
		$user_agent = sanitize_text_field( (string) wp_unslash( $_SERVER['HTTP_USER_AGENT'] ?? '' ) );
		if ( ! is_string( $user_agent ) ) {
			return '';
		}
		return substr( trim( $user_agent ), 0, 255 );
	}

	private static function client_ip() {
		$settings       = get_option( 'litecal_settings', array() );
		$trust_cf_proxy = ! empty( $settings['trust_cloudflare_proxy'] );
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Sanitized with wp_unslash/sanitize_text_field and validated as IP below.
		$remote_ip    = sanitize_text_field( trim( (string) wp_unslash( $_SERVER['REMOTE_ADDR'] ?? '' ) ) );
		$remote_valid = ( $remote_ip !== '' && filter_var( $remote_ip, FILTER_VALIDATE_IP ) );
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Sanitized with wp_unslash/sanitize_text_field and validated as IP below.
		$cf_ip = sanitize_text_field( trim( (string) wp_unslash( $_SERVER['HTTP_CF_CONNECTING_IP'] ?? '' ) ) );
		if ( $cf_ip !== '' && filter_var( $cf_ip, FILTER_VALIDATE_IP ) ) {
			$remote_is_cf        = $remote_valid && self::ip_in_cidrs( $remote_ip, self::cloudflare_proxy_cidrs() );
			$trusted_local_proxy = $trust_cf_proxy && $remote_valid && self::is_private_or_reserved_ip( $remote_ip );
			if ( $remote_is_cf || $trusted_local_proxy ) {
				return $cf_ip;
			}
		}
		if ( $remote_valid ) {
			return $remote_ip;
		}
		return '0.0.0.0';
	}

	private static function is_private_or_reserved_ip( $ip ) {
		if ( ! filter_var( $ip, FILTER_VALIDATE_IP ) ) {
			return false;
		}
		$public = filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE );
		return $public === false;
	}

	private static function cloudflare_proxy_cidrs() {
		$cidrs    = array(
			'173.245.48.0/20',
			'103.21.244.0/22',
			'103.22.200.0/22',
			'103.31.4.0/22',
			'141.101.64.0/18',
			'108.162.192.0/18',
			'190.93.240.0/20',
			'188.114.96.0/20',
			'197.234.240.0/22',
			'198.41.128.0/17',
			'162.158.0.0/15',
			'104.16.0.0/13',
			'104.24.0.0/14',
			'172.64.0.0/13',
			'131.0.72.0/22',
			'2400:cb00::/32',
			'2606:4700::/32',
			'2803:f800::/32',
			'2405:b500::/32',
			'2405:8100::/32',
			'2a06:98c0::/29',
			'2c0f:f248::/32',
		);
		$filtered = apply_filters( 'litecal_cloudflare_proxy_cidrs', $cidrs );
		return is_array( $filtered ) ? array_values( array_filter( array_map( 'trim', $filtered ) ) ) : $cidrs;
	}

	private static function ip_in_cidrs( $ip, array $cidrs ) {
		foreach ( $cidrs as $cidr ) {
			if ( self::ip_in_cidr( $ip, $cidr ) ) {
				return true;
			}
		}
		return false;
	}

	private static function ip_in_cidr( $ip, $cidr ) {
		$cidr = trim( (string) $cidr );
		if ( $cidr === '' ) {
			return false;
		}
		if ( strpos( $cidr, '/' ) === false ) {
			return hash_equals( $ip, $cidr );
		}
		[$subnet, $mask_bits] = explode( '/', $cidr, 2 );
		$ip_bin               = @inet_pton( $ip );
		$subnet_bin           = @inet_pton( $subnet );
		if ( $ip_bin === false || $subnet_bin === false ) {
			return false;
		}
		$mask_bits = (int) $mask_bits;
		$length    = strlen( $ip_bin );
		if ( $length !== strlen( $subnet_bin ) ) {
			return false;
		}
		$max_bits = $length * 8;
		if ( $mask_bits < 0 || $mask_bits > $max_bits ) {
			return false;
		}
		$bytes = intdiv( $mask_bits, 8 );
		$bits  = $mask_bits % 8;
		if ( $bytes > 0 && substr( $ip_bin, 0, $bytes ) !== substr( $subnet_bin, 0, $bytes ) ) {
			return false;
		}
		if ( $bits === 0 ) {
			return true;
		}
		$mask = chr( ( 0xFF00 >> $bits ) & 0xFF );
		return ( $ip_bin[ $bytes ] & $mask ) === ( $subnet_bin[ $bytes ] & $mask );
	}

	private static function rate_limit( $scope, $limit = 30, $window = 60 ) {
		$limit  = (int) $limit;
		$window = (int) $window;
		if ( $limit <= 0 || $window <= 0 ) {
			return true;
		}
		$ip   = self::client_ip();
		$key  = 'litecal_rl_' . md5( $scope . '|' . $ip );
		$now  = time();
		$data = get_transient( $key );
		if ( ! is_array( $data ) ) {
			set_transient(
				$key,
				array(
					'count' => 1,
					'start' => $now,
				),
				$window
			);
			return true;
		}
		$start = (int) ( $data['start'] ?? $now );
		$count = (int) ( $data['count'] ?? 0 );
		if ( ( $now - $start ) >= $window ) {
			set_transient(
				$key,
				array(
					'count' => 1,
					'start' => $now,
				),
				$window
			);
			return true;
		}
		++$count;
		$data['count'] = $count;
		set_transient( $key, $data, $window );
		if ( $count > $limit ) {
			return self::rest_error( 'rate_limited', 'Demasiadas solicitudes. Intenta nuevamente.', array( 'status' => 429 ) );
		}
		return true;
	}

	public static function refresh_booking( $request ) {
		$id = (int) $request['id'];
		if ( ! $id ) {
			return self::rest_error( 'invalid', 'Datos inválidos', array( 'status' => 400 ) );
		}
		$limit = self::rate_limit( "refresh_booking:{$id}", 30, 60 );
		if ( is_wp_error( $limit ) ) {
			return $limit;
		}
		$booking = Bookings::get( $id );
		if ( ! $booking ) {
			return self::rest_error( 'not_found', 'No encontrado', array( 'status' => 404 ) );
		}
		$access = self::assert_booking_access( $request, $booking );
		if ( is_wp_error( $access ) ) {
			return $access;
		}
		$booking = self::refresh_booking_payment_state( $booking );
		return self::rest_response(
			'booking_refreshed',
			'Reserva actualizada.',
			(string) $booking->status,
			array(
				'ok'             => true,
				'id'             => (int) $booking->id,
				'status'         => (string) $booking->status,
				'payment_status' => (string) $booking->payment_status,
			)
		);
	}

	public static function reschedule_booking( $request ) {
		$id = (int) $request['id'];
		if ( $id <= 0 ) {
			return self::rest_error( 'invalid', 'Datos inválidos', array( 'status' => 400 ) );
		}
		$global_limit = self::rate_limit( 'manage_reschedule_global', 60, 60 );
		if ( is_wp_error( $global_limit ) ) {
			return $global_limit;
		}
		$booking = Bookings::get( $id );
		if ( ! $booking ) {
			return self::rest_error( 'not_found', 'No encontrado', array( 'status' => 404 ) );
		}
		$access = self::assert_booking_access( $request, $booking );
		if ( is_wp_error( $access ) ) {
			return $access;
		}
		$event = Events::get( (int) ( $booking->event_id ?? 0 ) );
		if ( ! $event ) {
			return self::rest_error( 'invalid', 'Servicio no encontrado', array( 'status' => 400 ) );
		}
		$policy       = self::event_manage_policy( $event, $booking );
		$manage_limit = self::manage_rate_limit( $request, $booking, $policy, 'reschedule' );
		if ( is_wp_error( $manage_limit ) ) {
			return $manage_limit;
		}

		$meta_pack   = self::booking_manage_meta( $booking );
		$snapshot    = $meta_pack['snapshot'];
		$manage_meta = $meta_pack['manage'];
		$allowed     = self::evaluate_manage_action( $booking, $policy, $manage_meta, 'reschedule' );
		if ( empty( $allowed['allowed'] ) ) {
			return self::rest_error(
				'reschedule_blocked',
				$allowed['reason'] ?? 'No es posible reagendar esta reserva.',
				array(
					'status'      => 409,
					'reason_code' => sanitize_key( (string) ( $allowed['reason_code'] ?? 'blocked' ) ),
				)
			);
		}

		$payload = $request->get_json_params();
		if ( ! is_array( $payload ) || empty( $payload ) ) {
			$payload = $request->get_params();
		}
		$start  = sanitize_text_field( (string) ( $payload['start'] ?? '' ) );
		$end    = sanitize_text_field( (string) ( $payload['end'] ?? '' ) );
		$reason = self::sanitize_manage_reason( $payload['reason'] ?? '' );
		if ( $start === '' || $end === '' ) {
			return self::rest_error( 'invalid', 'Debes indicar nueva fecha y hora.', array( 'status' => 400 ) );
		}

		$target_employee_id = (int) ( $payload['employee_id'] ?? ( $booking->employee_id ?? 0 ) );
		$event_employees    = Events::employees( (int) $event->id );
		$event_employee_ids = array_values(
			array_filter(
				array_map(
					static function ( $item ) {
						return (int) ( $item->id ?? 0 );
					},
					(array) $event_employees
				)
			)
		);
		$original_employee_id = (int) ( $booking->employee_id ?? 0 );
		if ( $original_employee_id <= 0 ) {
			$snapshot = json_decode( (string) ( $booking->snapshot ?? '[]' ), true );
			if ( is_array( $snapshot ) ) {
				$original_employee_id = (int) ( $snapshot['employee']['id'] ?? 0 );
			}
		}

		if ( $target_employee_id <= 0 ) {
			if ( $original_employee_id > 0 ) {
				$target_employee_id = $original_employee_id;
			} elseif ( count( $event_employee_ids ) === 1 ) {
				$target_employee_id = (int) $event_employee_ids[0];
			}
		}
		if ( $target_employee_id <= 0 ) {
			return self::rest_error( 'employee_required', 'Selecciona un profesional válido.', array( 'status' => 400 ) );
		}
		if ( ! empty( $event_employee_ids ) && ! in_array( $target_employee_id, $event_employee_ids, true ) ) {
			return self::rest_error( 'invalid_employee', 'Profesional inválido para este servicio.', array( 'status' => 400 ) );
		}
		if ( ! $policy['allow_change_staff'] ) {
			if ( $original_employee_id > 0 ) {
				if ( $target_employee_id !== $original_employee_id ) {
					return self::rest_error( 'staff_locked', 'Esta reserva debe mantenerse con el mismo profesional.', array( 'status' => 409 ) );
				}
				$target_employee_id = $original_employee_id;
			}
		}

		$tz       = Availability::resolve_timezone( $event );
		$start_dt = \DateTimeImmutable::createFromFormat( 'Y-m-d H:i:s', $start, $tz );
		if ( ! $start_dt ) {
			$start_dt = \DateTimeImmutable::createFromFormat( 'Y-m-d H:i', $start, $tz );
		}
		$end_dt = \DateTimeImmutable::createFromFormat( 'Y-m-d H:i:s', $end, $tz );
		if ( ! $end_dt ) {
			$end_dt = \DateTimeImmutable::createFromFormat( 'Y-m-d H:i', $end, $tz );
		}
		if ( ! $start_dt || ! $end_dt || $end_dt->getTimestamp() <= $start_dt->getTimestamp() ) {
			return self::rest_error( 'invalid_datetime', 'Fecha u hora inválida.', array( 'status' => 400 ) );
		}
		$start_date       = wp_date( 'Y-m-d', $start_dt->getTimestamp(), $tz );
		$start_hm         = wp_date( 'H:i', $start_dt->getTimestamp(), $tz );
		$end_hm           = wp_date( 'H:i', $end_dt->getTimestamp(), $tz );
		$duration_minutes = (int) round( ( $end_dt->getTimestamp() - $start_dt->getTimestamp() ) / 60 );
		if ( $duration_minutes <= 0 || $duration_minutes !== (int) $event->duration ) {
			return self::rest_error( 'invalid_range', 'El rango horario no coincide con la duración del servicio.', array( 'status' => 400 ) );
		}

		$available_slots = Availability::get_slots( $event, $target_employee_id, $start_date, $id );
		$selected_slot   = null;
		foreach ( (array) $available_slots as $slot_item ) {
			if ( ( $slot_item['start'] ?? '' ) === $start_hm && ( $slot_item['end'] ?? '' ) === $end_hm ) {
				$selected_slot = $slot_item;
				break;
			}
		}
		if ( ! $selected_slot || ( $selected_slot['status'] ?? '' ) !== 'available' ) {
			return self::rest_error( 'slot_unavailable', 'El horario seleccionado no está disponible.', array( 'status' => 409 ) );
		}

		$resource      = $target_employee_id > 0 ? ( 'employee:' . $target_employee_id ) : ( 'event:' . (int) $event->id );
		$slot_lock_key = 'booking_reschedule|' . $resource . '|' . $start_date . '|' . $start_hm . '|' . $end_hm;
		if ( ! self::acquire_lock( $slot_lock_key, 20 ) ) {
			return self::rest_error( 'slot_locked', 'Este horario se está reservando. Intenta nuevamente.', array( 'status' => 409 ) );
		}

		try {
			$booking = Bookings::get( $id );
			if ( ! $booking ) {
				return self::rest_error( 'not_found', 'No encontrado', array( 'status' => 404 ) );
			}
			$meta_pack   = self::booking_manage_meta( $booking );
			$snapshot    = $meta_pack['snapshot'];
			$manage_meta = $meta_pack['manage'];
			$allowed     = self::evaluate_manage_action( $booking, $policy, $manage_meta, 'reschedule' );
			if ( empty( $allowed['allowed'] ) ) {
				return self::rest_error(
					'reschedule_blocked',
					$allowed['reason'] ?? 'No es posible reagendar esta reserva.',
					array(
						'status'      => 409,
						'reason_code' => sanitize_key( (string) ( $allowed['reason_code'] ?? 'blocked' ) ),
					)
				);
			}

			$previous_start    = (string) ( $booking->start_datetime ?? '' );
			$previous_end      = (string) ( $booking->end_datetime ?? '' );
			$previous_employee = (int) ( $booking->employee_id ?? 0 );

			$manage_meta['reschedule_count']   = max( 0, (int) ( $manage_meta['reschedule_count'] ?? 0 ) ) + 1;
			$manage_meta['last_reschedule_at'] = current_time( 'mysql' );
			if ( empty( $manage_meta['history'] ) || ! is_array( $manage_meta['history'] ) ) {
				$manage_meta['history'] = array();
			}
			$manage_meta['history'][] = array(
				'type'             => 'reschedule',
				'from_start'       => $previous_start,
				'from_end'         => $previous_end,
				'to_start'         => $start_dt->format( 'Y-m-d H:i:s' ),
				'to_end'           => $end_dt->format( 'Y-m-d H:i:s' ),
				'from_employee_id' => $previous_employee,
				'to_employee_id'   => $target_employee_id,
				'reason'           => $reason,
				'ip'               => self::client_ip(),
				'ua'               => sanitize_text_field( (string) wp_unslash( $_SERVER['HTTP_USER_AGENT'] ?? '' ) ),
				'at'               => current_time( 'mysql' ),
			);
			$manage_meta['history']   = array_slice( $manage_meta['history'], -50 );

			if ( ! is_array( $snapshot ) ) {
				$snapshot = array();
			}
			if ( ! isset( $snapshot['booking'] ) || ! is_array( $snapshot['booking'] ) ) {
				$snapshot['booking'] = array();
			}
			$snapshot['booking']['start'] = $start_dt->format( 'Y-m-d H:i:s' );
			$snapshot['booking']['end']   = $end_dt->format( 'Y-m-d H:i:s' );
			$snapshot['manage']           = $manage_meta;
			if ( $reason !== '' ) {
				$snapshot['manage']['last_reason'] = $reason;
			}
			$employee = Employees::get( $target_employee_id );
			if ( $employee ) {
				$snapshot['employee'] = array(
					'id'         => (int) $employee->id,
					'name'       => (string) ( $employee->name ?? '' ),
					'email'      => (string) ( $employee->email ?? '' ),
					'title'      => (string) ( $employee->title ?? '' ),
					'avatar_url' => Helpers::avatar_url( (string) ( $employee->avatar_url ?? '' ), 'thumbnail' ),
				);
			}

			global $wpdb;
			$table   = $wpdb->prefix . 'litecal_bookings';
			$updated = $wpdb->update(
				$table,
				array(
					'start_datetime' => $start_dt->format( 'Y-m-d H:i:s' ),
					'end_datetime'   => $end_dt->format( 'Y-m-d H:i:s' ),
					'employee_id'    => $target_employee_id ?: null,
					'status'         => 'rescheduled',
					'snapshot'       => wp_json_encode( $snapshot ),
					'updated_at'     => current_time( 'mysql' ),
				),
				array( 'id' => $id )
			);
			if ( $updated === false ) {
				return self::rest_error( 'db_error', 'No se pudo reagendar la reserva.', array( 'status' => 500 ) );
			}
			Bookings::bump_cache_version();
		} finally {
			self::release_lock( $slot_lock_key );
		}

		self::notify_booking_status( $id, 'rescheduled', true );
		$booking                = Bookings::get( $id );
		$access_token_for_links = self::booking_access_token( $booking );
		$state                  = self::manage_state_for_booking( $booking, $event, $access_token_for_links );
		return self::rest_response(
			'booking_rescheduled',
			'Reserva reagendada correctamente.',
			'rescheduled',
			array(
				'ok'            => true,
				'booking_id'    => $id,
				'booking_token' => $access_token_for_links,
				'booking'       => array(
					'start'       => (string) ( $booking->start_datetime ?? '' ),
					'end'         => (string) ( $booking->end_datetime ?? '' ),
					'employee_id' => (int) ( $booking->employee_id ?? 0 ),
					'status'      => (string) ( $booking->status ?? '' ),
				),
				'manage'        => $state,
			)
		);
	}

	public static function cancel_booking( $request ) {
		$id = (int) $request['id'];
		if ( $id <= 0 ) {
			return self::rest_error( 'invalid', 'Datos inválidos', array( 'status' => 400 ) );
		}
		$global_limit = self::rate_limit( 'manage_cancel_global', 60, 60 );
		if ( is_wp_error( $global_limit ) ) {
			return $global_limit;
		}
		$booking = Bookings::get( $id );
		if ( ! $booking ) {
			return self::rest_error( 'not_found', 'No encontrado', array( 'status' => 404 ) );
		}
		$access = self::assert_booking_access( $request, $booking );
		if ( is_wp_error( $access ) ) {
			return $access;
		}
		$event = Events::get( (int) ( $booking->event_id ?? 0 ) );
		if ( ! $event ) {
			return self::rest_error( 'invalid', 'Servicio no encontrado', array( 'status' => 400 ) );
		}
		$policy       = self::event_manage_policy( $event, $booking );
		$manage_limit = self::manage_rate_limit( $request, $booking, $policy, 'cancel' );
		if ( is_wp_error( $manage_limit ) ) {
			return $manage_limit;
		}

		$meta_pack   = self::booking_manage_meta( $booking );
		$snapshot    = $meta_pack['snapshot'];
		$manage_meta = $meta_pack['manage'];
		$allowed     = self::evaluate_manage_action( $booking, $policy, $manage_meta, 'cancel' );
		if ( empty( $allowed['allowed'] ) ) {
			return self::rest_error(
				'cancel_blocked',
				$allowed['reason'] ?? 'No es posible cancelar esta reserva.',
				array(
					'status'      => 409,
					'reason_code' => sanitize_key( (string) ( $allowed['reason_code'] ?? 'blocked' ) ),
				)
			);
		}

		$payload = $request->get_json_params();
		if ( ! is_array( $payload ) || empty( $payload ) ) {
			$payload = $request->get_params();
		}
		$reason              = self::sanitize_manage_reason( $payload['reason'] ?? '' );
		$payment_status      = sanitize_key( (string) ( $booking->payment_status ?? '' ) );
		$next_payment_status = in_array( $payment_status, array( 'pending', 'unpaid', '' ), true ) ? 'cancelled' : $payment_status;

		$manage_meta['cancel_count'] = max( 0, (int) ( $manage_meta['cancel_count'] ?? 0 ) ) + 1;
		if ( empty( $manage_meta['history'] ) || ! is_array( $manage_meta['history'] ) ) {
			$manage_meta['history'] = array();
		}
		$manage_meta['history'][] = array(
			'type'       => 'cancel',
			'from_start' => (string) ( $booking->start_datetime ?? '' ),
			'from_end'   => (string) ( $booking->end_datetime ?? '' ),
			'reason'     => $reason,
			'ip'         => self::client_ip(),
			'ua'         => sanitize_text_field( (string) wp_unslash( $_SERVER['HTTP_USER_AGENT'] ?? '' ) ),
			'at'         => current_time( 'mysql' ),
		);
		$manage_meta['history']   = array_slice( $manage_meta['history'], -50 );
		if ( ! is_array( $snapshot ) ) {
			$snapshot = array();
		}
		$snapshot['manage'] = $manage_meta;
		if ( $reason !== '' ) {
			$snapshot['manage']['last_reason'] = $reason;
		}

		global $wpdb;
		$table   = $wpdb->prefix . 'litecal_bookings';
		$updated = $wpdb->update(
			$table,
			array(
				'status'         => 'cancelled',
				'payment_status' => $next_payment_status,
				'snapshot'       => wp_json_encode( $snapshot ),
				'updated_at'     => current_time( 'mysql' ),
			),
			array( 'id' => $id )
		);
		if ( $updated === false ) {
			return self::rest_error( 'db_error', 'No se pudo cancelar la reserva.', array( 'status' => 500 ) );
		}
		Bookings::bump_cache_version();

		self::notify_booking_status( $id, 'cancelled', true );
		$booking                = Bookings::get( $id );
		$access_token_for_links = self::booking_access_token( $booking );
		$state                  = self::manage_state_for_booking( $booking, $event, $access_token_for_links );
		return self::rest_response(
			'booking_cancelled',
			'Reserva cancelada correctamente.',
			'cancelled',
			array(
				'ok'            => true,
				'booking_id'    => $id,
				'booking_token' => $access_token_for_links,
				'manage'        => $state,
			)
		);
	}

	private static function reminder_slots_from_settings() {
		$settings = get_option( 'litecal_settings', array() );
		$raw      = array(
			'first'  => (int) ( $settings['reminder_first_hours'] ?? 24 ),
			'second' => (int) ( $settings['reminder_second_hours'] ?? 12 ),
			'third'  => (int) ( $settings['reminder_third_hours'] ?? 1 ),
		);
		$used     = array();
		$slots    = array();
		foreach ( $raw as $slot => $hours ) {
			if ( $hours < 0 ) {
				$hours = 0;
			}
			if ( $hours > 720 ) {
				$hours = 720;
			}
			if ( $hours <= 0 ) {
				continue;
			}
			if ( isset( $used[ $hours ] ) ) {
				continue;
			}
			$used[ $hours ] = true;
			$slots[]        = array(
				'slot'  => $slot,
				'hours' => $hours,
			);
		}
		usort(
			$slots,
			static function ( $a, $b ) {
				return (int) $b['hours'] <=> (int) $a['hours'];
			}
		);
		return $slots;
	}

	private static function reminder_slot_already_sent( $booking, $slot ) {
		$snapshot  = Bookings::decode_snapshot( $booking );
		$reminders = is_array( $snapshot['reminders'] ?? null ) ? $snapshot['reminders'] : array();
		$sent      = is_array( $reminders['sent'] ?? null ) ? $reminders['sent'] : array();
		$entry     = $sent[ $slot ] ?? null;
		if ( ! is_array( $entry ) ) {
			return false;
		}
		$entry_start = (string) ( $entry['start'] ?? '' );
		if ( $entry_start === '' ) {
			return false;
		}
		return hash_equals( $entry_start, (string) ( $booking->start_datetime ?? '' ) );
	}

	private static function mark_reminder_slot_sent( $booking, $slot ) {
		$booking_id = (int) ( $booking->id ?? 0 );
		if ( $booking_id <= 0 ) {
			return;
		}
		$snapshot = Bookings::decode_snapshot( $booking );
		if ( ! is_array( $snapshot ) ) {
			$snapshot = array();
		}
		if ( empty( $snapshot['reminders'] ) || ! is_array( $snapshot['reminders'] ) ) {
			$snapshot['reminders'] = array();
		}
		if ( empty( $snapshot['reminders']['sent'] ) || ! is_array( $snapshot['reminders']['sent'] ) ) {
			$snapshot['reminders']['sent'] = array();
		}
		$snapshot['reminders']['sent'][ $slot ] = array(
			'at'    => current_time( 'mysql' ),
			'start' => (string) ( $booking->start_datetime ?? '' ),
		);
		global $wpdb;
		$table   = $wpdb->prefix . 'litecal_bookings';
		$updated = $wpdb->update(
			$table,
			array(
				'snapshot' => wp_json_encode( $snapshot ),
			),
			array( 'id' => $booking_id )
		);
		if ( $updated !== false ) {
			Bookings::bump_cache_version();
		}
	}

	public static function process_event_reminders() {
		if ( self::free_plan_restrictions_enabled() ) {
			update_option( 'litecal_last_reminder_run_ts', self::current_ts(), false );
			return;
		}
		$now_ts         = self::current_ts();
		$store_last_run = static function () use ( $now_ts ) {
			update_option( 'litecal_last_reminder_run_ts', $now_ts, false );
		};
		$slots          = self::reminder_slots_from_settings();
		if ( empty( $slots ) ) {
			$store_last_run();
			return;
		}
		$last_run_ts = (int) get_option( 'litecal_last_reminder_run_ts', 0 );
		if ( $last_run_ts <= 0 || $last_run_ts > $now_ts ) {
			$last_run_ts = $now_ts - 900;
		}
		// Keep a short overlap to avoid missing reminders on clock drift.
		$reminder_window_start = max( 0, $last_run_ts - 120 );
		$max_hours             = max(
			array_map(
				static function ( $slot ) {
					return (int) ( $slot['hours'] ?? 0 );
				},
				$slots
			)
		);
		if ( $max_hours <= 0 ) {
			$store_last_run();
			return;
		}
			$start_from = current_time( 'mysql' );
			$end_ts     = $now_ts + ( $max_hours * HOUR_IN_SECONDS ) + 900;
			$end_dt     = wp_date( 'Y-m-d H:i:s', $end_ts, wp_timezone() );

			global $wpdb;
			$table_name = $wpdb->prefix . 'litecal_bookings';
			// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Plugin table name uses trusted $wpdb->prefix and WP 6.0 cannot use %i safely.
			$bookings   = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT * FROM {$table_name} WHERE status = %s AND start_datetime >= %s AND start_datetime <= %s ORDER BY start_datetime ASC",
					'confirmed',
					(string) $start_from,
					(string) $end_dt
				)
			);
			// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			if ( empty( $bookings ) ) {
				$store_last_run();
				return;
			}
		foreach ( $bookings as $booking ) {
			$start_ts = self::wp_datetime_to_ts( $booking->start_datetime ?? '' );
			if ( $start_ts <= 0 || $start_ts <= $now_ts ) {
				continue;
			}
			$event = Events::get( (int) ( $booking->event_id ?? 0 ) );
			if ( ! $event ) {
				continue;
			}
			$snapshot = Bookings::decode_snapshot( $booking );
			$employee = ! empty( $booking->employee_id ) ? Employees::get( (int) $booking->employee_id ) : null;
			$payload  = array(
				'id'                => (int) $booking->id,
				'name'              => (string) $booking->name,
				'email'             => (string) $booking->email,
				'phone'             => (string) $booking->phone,
				'message'           => (string) $booking->message,
				'guests'            => $booking->guests ? json_decode( $booking->guests, true ) : array(),
				'start'             => (string) $booking->start_datetime,
				'end'               => (string) $booking->end_datetime,
				'status'            => (string) $booking->status,
				'template_status'   => 'reminder',
				'payment_status'    => (string) $booking->payment_status,
				'payment_provider'  => (string) $booking->payment_provider,
				'payment_amount'    => (float) $booking->payment_amount,
				'payment_currency'  => (string) $booking->payment_currency,
				'payment_reference' => (string) $booking->payment_reference,
				'meet_link'         => (string) ( $booking->calendar_meet_link ?? '' ),
				'meeting_provider'  => sanitize_key( (string) ( $booking->video_provider ?? ( $snapshot['event']['location'] ?? ( $event->location ?? '' ) ) ) ),
				'location_key'      => sanitize_key( (string) ( $snapshot['event']['location'] ?? ( $event->location ?? '' ) ) ),
				'location_details'  => (string) ( $snapshot['event']['location_details'] ?? ( $event->location_details ?? '' ) ),
				'event_title'       => (string) ( $snapshot['event']['title'] ?? ( $event->title ?? '' ) ),
				'first_name'        => (string) ( $snapshot['booking']['first_name'] ?? '' ),
				'last_name'         => (string) ( $snapshot['booking']['last_name'] ?? '' ),
				'transfer'          => ( is_array( $snapshot['transfer'] ?? null ) ? $snapshot['transfer'] : array() ),
			);
			if ( $employee ) {
				$payload['employee'] = array(
					'id'    => (int) $employee->id,
					'name'  => (string) $employee->name,
					'email' => (string) $employee->email,
				);
			}
			foreach ( $slots as $slot ) {
				$slot_key = 'reminder_' . sanitize_key( (string) ( $slot['slot'] ?? '' ) );
				$hours    = (int) ( $slot['hours'] ?? 0 );
				if ( $hours <= 0 || self::reminder_slot_already_sent( $booking, $slot_key ) ) {
					continue;
				}
				$trigger_ts = $start_ts - ( $hours * HOUR_IN_SECONDS );
				// Send if the trigger happened between last run and current run.
				if ( $trigger_ts > $now_ts || $trigger_ts < $reminder_window_start ) {
					continue;
				}
				try {
					self::send_emails( $event, $payload, $booking->start_datetime, true );
					self::mark_reminder_slot_sent( $booking, $slot_key );
				} catch ( \Throwable $e ) {
					self::debug_log(
						'reminder_send_exception',
						array(
							'booking_id' => (string) ( $booking->id ?? '' ),
							'slot'       => $slot_key,
							'error'      => $e->getMessage(),
						)
					);
				}
			}
		}
		$store_last_run();
	}

	private static function send_emails( $event, $payload, $start, $force = false ) {
		$settings  = get_option( 'litecal_settings', array() );
		$from_name = sanitize_text_field( (string) ( $settings['email_from_name'] ?? get_bloginfo( 'name' ) ) );
		$headers   = array(
			'Content-Type: text/html; charset=UTF-8',
		);

		$status           = $payload['status'] ?? 'pending';
		$template_status  = $payload['template_status'] ?? $status;
		$payment_status   = $payload['payment_status'] ?? '';
		$options          = json_decode( $event->options ?: '[]', true );
		$price_mode       = $options['price_mode'] ?? 'free';
		$requires_payment = ! in_array( $price_mode, array( 'free', 'onsite' ), true ) && (float) $event->price > 0;

		$template_key = $template_status;
		if ( $template_status === 'pending' ) {
			$template_key = 'created';
		}
		if ( ! in_array( $template_key, array( 'created', 'confirmed', 'cancelled', 'rescheduled', 'updated', 'payment_failed', 'payment_expired', 'reminder' ), true ) ) {
			$template_key = 'created';
		}
		if ( self::free_plan_restrictions_enabled() && $template_key === 'reminder' ) {
			self::debug_log(
				'email_skip_template',
				array(
					'booking_id'   => (string) ( $payload['id'] ?? '' ),
					'template_key' => $template_key,
					'status'       => (string) $status,
					'reason'       => 'free_plan_restriction',
				)
			);
			return;
		}
		$templates = get_option( 'litecal_email_templates', array() );
		$defaults  = array(
			'created'         => array(
				'client_subject' => '🗓️ Solicitud registrada: {servicio}',
				'client_body'    => 'Hola {cliente},

Recibimos correctamente tu solicitud para {servicio}. Tu reserva quedó en estado {estado}.

Fecha: {fecha_humana}
Hora: {hora_humana}

En cuanto se confirme la gestión del pago y disponibilidad, te enviaremos la confirmación final a este mismo correo.

Saludos,
{organizacion}',
				'admin_subject'  => '🆕 Nueva reserva pendiente: {servicio}',
				'admin_body'     => 'Hola profesional,

Ingresó una nueva reserva en estado {estado}.

Cliente: {cliente}
Fecha: {fecha_humana}
Hora: {hora_humana}

Revisa la reserva para confirmar, reagendar o cancelar según corresponda.

Saludos,
{organizacion}',
			),
			'confirmed'       => array(
				'client_subject' => '🎉 Reserva confirmada: {servicio} — {fecha_humana} {hora_humana}',
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
				'admin_subject'  => '🎉 Reserva confirmada: {servicio} — {fecha_humana} {hora_humana}',
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
			),
			'cancelled'       => array(
				'client_subject' => '❌ Reserva cancelada: {servicio} — {fecha_humana} {hora_humana}',
				'client_body'    => 'Hola {cliente},

Tu reserva para {servicio} fue cancelada.

Si quieres una nueva fecha, puedes volver a reservar cuando quieras en nuestro sitio web.

Saludos,
{organizacion}',
				'admin_subject'  => '❌ Reserva cancelada: {servicio} — {fecha_humana} {hora_humana}',
				'admin_body'     => 'Hola {staff_member},

La reserva para {servicio} se marcó como cancelada.

Cliente: {cliente}
Fecha: {fecha_humana}
Hora: {hora_humana}

Saludos,
{organizacion}',
			),
			'rescheduled'     => array(
				'client_subject' => '🎉 Reserva reagendada: {servicio} — {fecha_humana} {hora_humana}',
				'client_body'    => 'Hola {cliente},

Tu reserva para {servicio} fue reagendada.

Nueva fecha: {fecha_humana}
Nueva hora: {hora_humana}

Importante
Te recomendamos estar listo(a) unos minutos antes de la hora indicada.

Saludos,
{organizacion}',
				'admin_subject'  => '🎉 Reserva reagendada: {servicio} — {fecha_humana} {hora_humana}',
				'admin_body'     => 'Hola {staff_member},

La reserva para {servicio} fue reagendada.

Cliente: {cliente}
Nueva fecha: {fecha_humana}
Nueva hora: {hora_humana}

Saludos,
{organizacion}',
			),
			'updated'         => array(
				'client_subject' => '🎉 Reserva actualizada: {servicio} — {fecha_humana} {hora_humana}',
				'client_body'    => 'Hola {cliente},

Tu reserva para {servicio} fue actualizada.

Fecha: {fecha_humana}
Hora: {hora_humana}
Estado: {estado}

Este correo confirma cambios relevantes en tu reserva, como profesional asignado u otros datos de atención.

Saludos,
{organizacion}',
				'admin_subject'  => '🎉 Reserva actualizada: {servicio} — {fecha_humana} {hora_humana}',
				'admin_body'     => 'Hola {staff_member},

Se actualizó una reserva para {servicio}.

Cliente: {cliente}
Fecha: {fecha_humana}
Hora: {hora_humana}
Estado: {estado}

Revisa los cambios en el detalle de la reserva.

Saludos,
{organizacion}',
			),
			'payment_failed'  => array(
				'client_subject' => '❌ Pago rechazado: {servicio} — {fecha_humana} {hora_humana}',
				'client_body'    => 'Hola {cliente},

No se pudo completar el pago de tu reserva para {servicio}.

Fecha: {fecha_humana}
Hora: {hora_humana}
Estado del pago: {estado_pago}

Si quieres reservar este horario, intenta nuevamente con el mismo u otro medio de pago.

Saludos,
{organizacion}',
				'admin_subject'  => '❌ Pago rechazado: {servicio} — {fecha_humana} {hora_humana}',
				'admin_body'     => 'Hola {staff_member},

Se registró un pago rechazado para {servicio}.

Cliente: {cliente}
Fecha: {fecha_humana}
Hora: {hora_humana}
Estado del pago: {estado_pago}

Revisar si requiere seguimiento.

Saludos,
{organizacion}',
			),
			'payment_expired' => array(
				'client_subject' => '❌ Se agotó el tiempo para pagar tu reserva: {servicio} — {fecha_humana} {hora_humana}',
				'client_body'    => 'Hola {cliente},

La orden de pago de tu reserva para {servicio} venció por tiempo de espera.

Fecha: {fecha_humana}
Hora: {hora_humana}
Estado del pago: {estado_pago}

Si quieres una nueva fecha, puedes volver a reservar cuando quieras en nuestro sitio web.

Saludos,
{organizacion}',
				'admin_subject'  => '❌ Se agotó el tiempo para pagar tu reserva: {servicio} — {fecha_humana} {hora_humana}',
				'admin_body'     => 'Hola {staff_member},

Una orden pendiente expiró para {servicio}.

Cliente: {cliente}
Fecha: {fecha_humana}
Hora: {hora_humana}
Estado del pago: {estado_pago}

Saludos,
{organizacion}',
			),
			'reminder'        => array(
				'client_subject' => '⏰ Recordatorio: {servicio} — {fecha_humana} {hora_humana}',
				'client_body'    => 'Hola {cliente},

Te recordamos tu reserva de {servicio}.

Fecha: {fecha_humana}
Hora: {hora_humana}
{meet_link}

Importante
Te recomendamos estar listo(a) unos minutos antes de la hora indicada.

Saludos,
{organizacion}',
				'admin_subject'  => '⏰ Recordatorio de atención: {servicio} — {fecha_humana} {hora_humana}',
				'admin_body'     => 'Hola {staff_member},

Este es un recordatorio de tu atención programada para {servicio}.

Cliente: {cliente}
Fecha: {fecha_humana}
Hora: {hora_humana}
{meet_link}

Saludos,
{organizacion}',
			),
		);

		$start_dt   = (string) ( $payload['start'] ?? $start );
		$end_dt     = (string) ( $payload['end'] ?? '' );
		$date_str   = self::internal_format_date( $start_dt, false );
		$time_str   = self::internal_format_time( $start_dt );
		$date_human = self::internal_format_date( $start_dt, true );
		$time_human = self::internal_format_time_range( $start_dt, $end_dt );
		$status_labels           = array(
			'pending'         => 'Pendiente',
			'confirmed'       => 'Confirmada',
			'cancelled'       => 'Cancelada',
			'rescheduled'     => 'Reagendada',
			'updated'         => 'Actualizada',
			'payment_failed'  => 'Pago rechazado',
			'payment_expired' => 'Pago expirado',
		);
		$payment_status_labels   = array(
			'paid'      => 'Aprobado',
			'pending'   => 'Pendiente',
			'unpaid'    => 'No pagado',
			'rejected'  => 'Rechazado',
			'failed'    => 'Rechazado',
			'cancelled' => 'Cancelado',
			'expired'   => 'Cancelado',
		);
		$provider_labels         = array(
			'flow'     => 'Flow',
			'paypal'   => 'PayPal',
			'mp'       => 'MercadoPago',
			'webpay'   => 'Webpay Plus',
			'stripe'   => 'Stripe',
			'transfer' => 'Transferencia bancaria',
			'onsite'   => 'Pago presencial',
		);
		$status_label            = $status_labels[ $status ] ?? ucfirst( $status );
		$payment_status_raw      = strtolower( (string) ( $payload['payment_status'] ?? '' ) );
		$payment_status_label    = $payment_status_labels[ $payment_status_raw ] ?? strtoupper( (string) ( $payload['payment_status'] ?? '-' ) );
		$provider_raw            = strtolower( (string) ( $payload['payment_provider'] ?? '' ) );
		$provider_label          = $provider_labels[ $provider_raw ] ?? ( (string) ( $payload['payment_provider'] ?? '-' ) );
		$payment_currency        = strtoupper( (string) ( $payload['payment_currency'] ?? ( $event->currency ?? 'CLP' ) ) );
		$payment_amount          = (float) ( $payload['payment_amount'] ?? 0 );
		$is_free_booking         = ! $requires_payment
			&& $provider_raw === ''
			&& $payment_amount <= 0
			&& trim( (string) ( $payload['payment_reference'] ?? '' ) ) === '';
		$is_free_confirmed       = $template_key === 'confirmed' && $is_free_booking;
		$free_confirmed_defaults = array(
			'client_subject' => '🎉 Reserva confirmada: {servicio} — {fecha_humana} {hora_humana}',
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
			'team_subject'   => '🎉 Reserva confirmada: {servicio} — {fecha_humana} {hora_humana}',
			'team_body'      => 'Hola {staff_member},

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
		);
		$guest_template_defaults = array(
			'confirmed'   => array(
				'subject' => '🎉 Te han invitado a: {servicio} — {fecha_humana} {hora_humana}',
				'body'    => 'Hola,

{cliente} te invitó a una reserva de {servicio}.

Fecha: {fecha_humana}
Hora: {hora_humana}
{meet_link}

Nos vemos pronto.
Saludos,
{organizacion}',
			),
			'rescheduled' => array(
				'subject' => '🎉 Invitación reagendada: {servicio} — {fecha_humana} {hora_humana}',
				'body'    => 'Hola,

La invitación de {servicio} fue reagendada.

Nueva fecha: {fecha_humana}
Nueva hora: {hora_humana}
{meet_link}

Saludos,
{organizacion}',
			),
			'cancelled'   => array(
				'subject' => '❌ Invitación cancelada: {servicio} — {fecha_humana} {hora_humana}',
				'body'    => 'Hola,

La invitación de {servicio} fue cancelada.

Saludos,
{organizacion}',
			),
			'reminder'    => array(
				'subject' => '⏰ Recordatorio: {servicio} — {fecha_humana} {hora_humana}',
				'body'    => 'Hola,

Te recordamos la reserva de {servicio}.

Fecha: {fecha_humana}
Hora: {hora_humana}
{meet_link}

Saludos,
{organizacion}',
			),
		);
		$payment_amount_label    = $payment_amount > 0
			? wp_strip_all_tags( number_format_i18n( $payment_amount, $payment_currency === 'CLP' ? 0 : 2 ) . ' ' . $payment_currency )
			: '-';
		$payment_reference       = (string) ( $payload['payment_reference'] ?? '-' );
		$client_first_name       = trim( (string) ( $payload['first_name'] ?? '' ) );
		$client_last_name        = trim( (string) ( $payload['last_name'] ?? '' ) );
		$client_phone            = trim( (string) ( $payload['phone'] ?? '' ) );
		$meet_link               = trim( (string) ( $payload['meet_link'] ?? '' ) );
		$location_key            = sanitize_key( (string) ( $payload['location_key'] ?? '' ) );
		$location_details        = trim( (string) ( $payload['location_details'] ?? '' ) );
		$location_details_text   = sanitize_text_field( $location_details );
		$is_presential           = in_array( $location_key, array( 'presencial', 'in_person', 'presential' ), true );
		$location_line           = '';
		if ( $meet_link !== '' ) {
			$location_line = 'Enlace de reunión: ' . $meet_link;
		} elseif ( $is_presential && $location_details_text !== '' ) {
			$location_line = 'Dirección: ' . $location_details_text;
		}
		$staff_member = trim( (string) ( $payload['employee']['name'] ?? '' ) );
		if ( $staff_member === '' && ! empty( $event->id ) ) {
			$event_employees_for_name = Events::employees( $event->id );
			foreach ( $event_employees_for_name as $emp_for_name ) {
				$candidate = trim( (string) ( $emp_for_name->name ?? '' ) );
				if ( $candidate !== '' ) {
					$staff_member = $candidate;
					break;
				}
			}
		}
		if ( $staff_member === '' ) {
			$staff_member = __( 'profesional', 'agenda-lite' );
		}
		$meeting_provider             = strtolower( (string) ( $payload['meeting_provider'] ?? '' ) );
		$transfer_payload             = is_array( $payload['transfer'] ?? null ) ? $payload['transfer'] : array();
		$transfer_rows                = is_array( $transfer_payload['rows'] ?? null ) ? $transfer_payload['rows'] : array();
		$transfer_instructions        = trim( (string) ( $transfer_payload['instructions'] ?? '' ) );
		$meeting_provider_labels      = array(
			'google_meet' => 'Google Meet',
			'zoom'        => 'Zoom',
			'teams'       => 'Microsoft Teams',
		);
		$meeting_provider_label       = $meeting_provider_labels[ $meeting_provider ] ?? 'Enlace de reunión';
		$event_title                  = $payload['event_title'] ?? ( $event->title ?? '' );
		$manage_link                  = trim( (string) ( $payload['manage_link'] ?? '' ) );
		$reschedule_link              = trim( (string) ( $payload['reschedule_link'] ?? '' ) );
		$cancel_link                  = trim( (string) ( $payload['cancel_link'] ?? '' ) );
		$vars                         = array(
			'{cliente}'         => $payload['name'] ?? '',
			'{servicio}'        => $event_title,
			'{evento}'          => $event_title,
			'{fecha}'           => $date_str,
			'{hora}'            => $time_str,
			'{fecha_humana}'    => $date_human,
			'{hora_humana}'     => $time_human,
			'{estado}'          => $status_label,
			'{estado_pago}'     => $payment_status_label,
			'{organizacion}'    => $from_name,
			'{meet_link}'       => $location_line,
			'{staff_member}'    => $staff_member,
			'{manage_link}'     => $manage_link,
			'{reschedule_link}' => $reschedule_link,
			'{cancel_link}'     => $cancel_link,
		);
		$updated_changes              = is_array( $payload['updated_changes'] ?? null ) ? $payload['updated_changes'] : array();
		$updated_change_keys          = array_fill_keys( array_keys( $updated_changes ), true );
		$is_updated_template          = $template_key === 'updated';
		$is_internal_note_changed     = $is_updated_template && isset( $updated_change_keys['message'] );
		$is_internal_note_only_update = $is_internal_note_changed && count( $updated_change_keys ) === 1;
		$include_payment_rows         = ! $is_free_booking && $template_key !== 'reminder';
		$internal_note_value          = trim( (string) ( $payload['message'] ?? '' ) );
		$apply                        = function ( $text, $audience = 'client' ) use ( $vars, $event_title, $date_human, $time_human, $status_label, $payment_status_label, $provider_label, $payment_amount_label, $payment_reference, $client_first_name, $client_last_name, $client_phone, $meet_link, $meeting_provider_label, $transfer_rows, $transfer_instructions, $include_payment_rows, $is_presential, $location_details_text, $staff_member, $is_updated_template, $updated_change_keys, $is_internal_note_changed, $internal_note_value, $template_key, $reschedule_link, $cancel_link ) {
			$resolved                = str_replace( array_keys( $vars ), array_values( $vars ), (string) $text );
			$resolved                = preg_replace( "/(\r?\n){3,}/", "\n\n", trim( (string) $resolved ) );
			$resolved                = nl2br( esc_html( $resolved ) );
			$fmtVal                  = static function ( $value, $is_changed = false ) {
				$escaped = esc_html( (string) $value );
				return $is_changed ? '<strong>' . $escaped . '</strong>' : $escaped;
			};
			$is_date_or_time_changed = isset( $updated_change_keys['start_datetime'] ) || isset( $updated_change_keys['end_datetime'] );
			$meet_html               = '';
			if ( $meet_link !== '' ) {
				$meet_html = '<tr><td style="padding:6px 0;color:#6b7280;">' . esc_html( $meeting_provider_label ) . '</td><td style="padding:6px 0;text-align:right;"><a href="' . esc_url( $meet_link ) . '" target="_blank" rel="noopener" style="color:#2563eb;text-decoration:underline;">Abrir enlace</a></td></tr>';
			} elseif ( $is_presential && $location_details_text !== '' ) {
				$meet_html = '<tr><td style="padding:6px 0;color:#6b7280;">Dirección</td><td style="padding:6px 0;text-align:right;color:#111827;">' . esc_html( $location_details_text ) . '</td></tr>';
			}
			$transfer_html = '';
			if ( ! empty( $transfer_rows ) ) {
				foreach ( $transfer_rows as $row ) {
					$label = sanitize_text_field( (string) ( $row['label'] ?? '' ) );
					$value = sanitize_text_field( (string) ( $row['value'] ?? '' ) );
					if ( $label === '' || $value === '' ) {
						continue;
					}
					$transfer_html .= '<tr><td style="padding:6px 0;color:#6b7280;">' . esc_html( $label ) . '</td><td style="padding:6px 0;text-align:right;color:#111827;">' . esc_html( $value ) . '</td></tr>';
				}
				if ( $transfer_instructions !== '' ) {
					$transfer_html .= '<tr><td style="padding:6px 0;color:#6b7280;">' . esc_html__( 'Instrucciones', 'agenda-lite' ) . '</td><td style="padding:6px 0;text-align:right;color:#111827;">' . esc_html( $transfer_instructions ) . '</td></tr>';
				}
			}
			$client_rows = '';
			if ( $audience !== 'guest' ) {
				if ( $client_first_name !== '' ) {
					$client_rows .= '<tr><td style="padding:6px 0;color:#6b7280;">Nombre</td><td style="padding:6px 0;text-align:right;color:#111827;">' . $fmtVal( $client_first_name, $is_updated_template && isset( $updated_change_keys['name'] ) ) . '</td></tr>';
				}
				if ( $client_last_name !== '' ) {
					$client_rows .= '<tr><td style="padding:6px 0;color:#6b7280;">Apellido</td><td style="padding:6px 0;text-align:right;color:#111827;">' . $fmtVal( $client_last_name, $is_updated_template && isset( $updated_change_keys['name'] ) ) . '</td></tr>';
				}
				if ( $client_phone !== '' ) {
					$client_rows .= '<tr><td style="padding:6px 0;color:#6b7280;">Teléfono</td><td style="padding:6px 0;text-align:right;color:#111827;">' . $fmtVal( $client_phone, $is_updated_template && isset( $updated_change_keys['phone'] ) ) . '</td></tr>';
				}
			}
			$payment_rows = '';
			if ( $include_payment_rows && $audience !== 'guest' ) {
				$payment_rows .= '<tr><td style="padding:6px 0;color:#6b7280;">Estado pago</td><td style="padding:6px 0;text-align:right;color:#111827;">' . $fmtVal( $payment_status_label, $is_updated_template && isset( $updated_change_keys['payment_status'] ) ) . '</td></tr>'
					. '<tr><td style="padding:6px 0;color:#6b7280;">Proveedor</td><td style="padding:6px 0;text-align:right;color:#111827;">' . $fmtVal( $provider_label, $is_updated_template && isset( $updated_change_keys['payment_provider'] ) ) . '</td></tr>'
					. '<tr><td style="padding:6px 0;color:#6b7280;">Monto</td><td style="padding:6px 0;text-align:right;color:#111827;">' . $fmtVal( $payment_amount_label, $is_updated_template && isset( $updated_change_keys['payment_amount'] ) ) . '</td></tr>'
					. '<tr><td style="padding:6px 0;color:#6b7280;">Referencia</td><td style="padding:6px 0;text-align:right;color:#111827;word-break:break-all;">' . $fmtVal( $payment_reference, $is_updated_template && isset( $updated_change_keys['payment_reference'] ) ) . '</td></tr>';
			}
			$internal_note_row = '';
			if ( $audience === 'team' && $is_internal_note_changed ) {
				$note_text         = $internal_note_value !== '' ? $internal_note_value : __( 'Sin nota', 'agenda-lite' );
				$internal_note_row = '<tr><td style="padding:6px 0;color:#6b7280;">Nota interna</td><td style="padding:6px 0;text-align:right;color:#111827;">' . $fmtVal( $note_text, true ) . '</td></tr>';
			}
			$manage_links_html = '';
			if ( $audience === 'client' && in_array( $template_key, array( 'confirmed', 'rescheduled' ), true ) ) {
				$buttons = '';
				if ( $reschedule_link !== '' ) {
					$buttons .= '<a href="' . esc_url( $reschedule_link ) . '" target="_blank" rel="noopener" style="display:inline-block;padding:9px 14px;border-radius:8px;border:1px solid #d1d5db;color:#111827;text-decoration:none;font-weight:600;">Reagendar</a>';
				}
				if ( $cancel_link !== '' ) {
					$buttons .= '<a href="' . esc_url( $cancel_link ) . '" target="_blank" rel="noopener" style="display:inline-block;padding:9px 14px;border-radius:8px;border:1px solid #ef4444;color:#b91c1c;text-decoration:none;font-weight:600;margin-left:8px;">Cancelar</a>';
				}
				if ( $buttons !== '' ) {
					$manage_links_html = '<div style="margin:14px 0 2px 0;padding:12px;border:1px solid #e5e7eb;border-radius:10px;background:#f8fafc;">'
						. '<div style="font-size:12px;color:#64748b;margin-bottom:8px;">Gestiona tu reserva</div>'
						. $buttons
						. '</div>';
				}
			}
			return '<div style="font-family:Inter,Segoe UI,Arial,sans-serif;color:#111827;line-height:1.45;max-width:640px;margin:0 auto;">'
				. '<div style="background:#ffffff;border:1px solid #e5e7eb;border-radius:12px;padding:20px;">'
				. '<div style="font-size:14px;margin-bottom:14px;color:#334155;">' . $resolved . '</div>'
				. $manage_links_html
				. '<div style="border-top:1px solid #e5e7eb;margin:14px 0;"></div>'
				. '<table role="presentation" style="width:100%;border-collapse:collapse;font-size:13px;">'
				. '<tr><td style="padding:6px 0;color:#6b7280;">Servicio</td><td style="padding:6px 0;text-align:right;color:#111827;font-weight:600;">' . esc_html( $event_title ) . '</td></tr>'
				. '<tr><td style="padding:6px 0;color:#6b7280;">Profesional</td><td style="padding:6px 0;text-align:right;color:#111827;">' . $fmtVal( $staff_member !== '' ? $staff_member : '-', $is_updated_template && isset( $updated_change_keys['employee_id'] ) ) . '</td></tr>'
				. '<tr><td style="padding:6px 0;color:#6b7280;">Fecha</td><td style="padding:6px 0;text-align:right;color:#111827;">' . $fmtVal( $date_human, $is_updated_template && $is_date_or_time_changed ) . '</td></tr>'
				. '<tr><td style="padding:6px 0;color:#6b7280;">Hora</td><td style="padding:6px 0;text-align:right;color:#111827;">' . $fmtVal( $time_human, $is_updated_template && $is_date_or_time_changed ) . '</td></tr>'
				. '<tr><td style="padding:6px 0;color:#6b7280;">Estado reserva</td><td style="padding:6px 0;text-align:right;color:#111827;">' . $fmtVal( $status_label, $is_updated_template && isset( $updated_change_keys['status'] ) ) . '</td></tr>'
				. $payment_rows
				. $client_rows
				. $internal_note_row
				. $meet_html
				. $transfer_html
				. '</table>'
				. '</div>'
				. '</div>';
		};
		$apply_subject                = function ( $text ) use ( $vars ) {
			$text = str_replace( array_keys( $vars ), array_values( $vars ), $text );
			return sanitize_text_field( $text );
		};
		$legacy_template_upgrade      = function ( $audience, $key, $subject, $body ) {
			$normalize = static function ( $value ) {
				return preg_replace( "/\r\n|\r/", "\n", trim( (string) $value ) );
			};
			$map       = array(
				'cancelled'       => array(
					'client' => array(
						'subject_old' => '❌ Reserva cancelada: {servicio}',
						'subject_new' => '❌ Reserva cancelada: {servicio} — {fecha_humana} {hora_humana}',
						'body_old'    => 'Hola {cliente},

Tu reserva para {servicio} fue cancelada.

Si quieres una nueva fecha, puedes volver a reservar cuando quieras o responder este correo.

Saludos,
{organizacion}',
						'body_new'    => 'Hola {cliente},

Tu reserva para {servicio} fue cancelada.

Si quieres una nueva fecha, puedes volver a reservar cuando quieras en nuestro sitio web.

Saludos,
{organizacion}',
					),
					'team'   => array(
						'subject_old' => '❌ Reserva cancelada: {servicio}',
						'subject_new' => '❌ Reserva cancelada: {servicio} — {fecha_humana} {hora_humana}',
						'body_old'    => 'Hola profesional,

La reserva para {servicio} se marcó como cancelada.

Cliente: {cliente}
Fecha: {fecha_humana}
Hora: {hora_humana}

Saludos,
{organizacion}',
						'body_new'    => 'Hola {staff_member},

La reserva para {servicio} se marcó como cancelada.

Cliente: {cliente}
Fecha: {fecha_humana}
Hora: {hora_humana}

Saludos,
{organizacion}',
					),
				),
				'rescheduled'     => array(
					'client' => array(
						'subject_old' => '🔁 Reserva reagendada: {servicio}',
						'subject_new' => '🎉 Reserva reagendada: {servicio} — {fecha_humana} {hora_humana}',
						'body_old'    => 'Hola {cliente},

Tu reserva para {servicio} fue reagendada.

Nueva fecha: {fecha_humana}
Nueva hora: {hora_humana}

Si necesitas otro ajuste, responde este correo y te ayudamos.

Saludos,
{organizacion}',
						'body_new'    => 'Hola {cliente},

Tu reserva para {servicio} fue reagendada.

Nueva fecha: {fecha_humana}
Nueva hora: {hora_humana}

Importante
Te recomendamos estar listo(a) unos minutos antes de la hora indicada.

Saludos,
{organizacion}',
					),
					'team'   => array(
						'subject_old' => '🔁 Reserva reagendada: {servicio}',
						'subject_new' => '🎉 Reserva reagendada: {servicio} — {fecha_humana} {hora_humana}',
						'body_old'    => 'Hola profesional,

La reserva para {servicio} fue reagendada.

Cliente: {cliente}
Nueva fecha: {fecha_humana}
Nueva hora: {hora_humana}

Saludos,
{organizacion}',
						'body_new'    => 'Hola {staff_member},

La reserva para {servicio} fue reagendada.

Cliente: {cliente}
Nueva fecha: {fecha_humana}
Nueva hora: {hora_humana}

Saludos,
{organizacion}',
					),
				),
				'updated'         => array(
					'client' => array(
						'subject_old' => '✏️ Reserva actualizada: {servicio}',
						'subject_new' => '🎉 Reserva actualizada: {servicio} — {fecha_humana} {hora_humana}',
					),
					'team'   => array(
						'subject_old' => '✏️ Reserva actualizada: {servicio}',
						'subject_new' => '🎉 Reserva actualizada: {servicio} — {fecha_humana} {hora_humana}',
						'body_old'    => 'Hola profesional,

Se actualizó una reserva para {servicio}.

Cliente: {cliente}
Fecha: {fecha_humana}
Hora: {hora_humana}
Estado: {estado}

Revisa los cambios en el detalle de la reserva.

Saludos,
{organizacion}',
						'body_new'    => 'Hola {staff_member},

Se actualizó una reserva para {servicio}.

Cliente: {cliente}
Fecha: {fecha_humana}
Hora: {hora_humana}
Estado: {estado}

Revisa los cambios en el detalle de la reserva.

Saludos,
{organizacion}',
					),
				),
				'payment_failed'  => array(
					'client' => array(
						'subject_old' => '⚠️ No se pudo completar el pago: {servicio}',
						'subject_new' => '❌ Pago rechazado: {servicio} — {fecha_humana} {hora_humana}',
					),
					'team'   => array(
						'subject_old' => '⚠️ Pago rechazado: {servicio}',
						'subject_new' => '❌ Pago rechazado: {servicio} — {fecha_humana} {hora_humana}',
						'body_old'    => 'Hola profesional,

Se registró un pago rechazado para {servicio}.

Cliente: {cliente}
Fecha: {fecha_humana}
Hora: {hora_humana}
Estado del pago: {estado_pago}

Revisar si requiere seguimiento.

Saludos,
{organizacion}',
						'body_new'    => 'Hola {staff_member},

Se registró un pago rechazado para {servicio}.

Cliente: {cliente}
Fecha: {fecha_humana}
Hora: {hora_humana}
Estado del pago: {estado_pago}

Revisar si requiere seguimiento.

Saludos,
{organizacion}',
					),
				),
				'payment_expired' => array(
					'client' => array(
						'subject_old' => '⌛ Orden expirada: {servicio}',
						'subject_new' => '❌ Se agotó el tiempo para pagar tu reserva: {servicio} — {fecha_humana} {hora_humana}',
						'body_old'    => 'Hola {cliente},

La orden de pago de tu reserva para {servicio} venció por tiempo de espera.

Fecha: {fecha_humana}
Hora: {hora_humana}
Estado del pago: {estado_pago}

Puedes reservar nuevamente cuando quieras.

Saludos,
{organizacion}',
						'body_new'    => 'Hola {cliente},

La orden de pago de tu reserva para {servicio} venció por tiempo de espera.

Fecha: {fecha_humana}
Hora: {hora_humana}
Estado del pago: {estado_pago}

Si quieres una nueva fecha, puedes volver a reservar cuando quieras en nuestro sitio web.

Saludos,
{organizacion}',
					),
					'team'   => array(
						'subject_old' => '⌛ Orden expirada: {servicio}',
						'subject_new' => '❌ Se agotó el tiempo para pagar tu reserva: {servicio} — {fecha_humana} {hora_humana}',
						'body_old'    => 'Hola profesional,

Una orden pendiente expiró para {servicio}.

Cliente: {cliente}
Fecha: {fecha_humana}
Hora: {hora_humana}
Estado del pago: {estado_pago}

Saludos,
{organizacion}',
						'body_new'    => 'Hola {staff_member},

Una orden pendiente expiró para {servicio}.

Cliente: {cliente}
Fecha: {fecha_humana}
Hora: {hora_humana}
Estado del pago: {estado_pago}

Saludos,
{organizacion}',
					),
				),
			);
			$def       = $map[ $key ][ $audience ] ?? null;
			if ( ! $def ) {
				return array( $subject, $body );
			}
			if ( ! empty( $def['subject_old'] ) && $normalize( $subject ) === $normalize( $def['subject_old'] ) ) {
				$subject = $def['subject_new'] ?? $subject;
			}
			if ( ! empty( $def['body_old'] ) && $normalize( $body ) === $normalize( $def['body_old'] ) ) {
				$body = $def['body_new'] ?? $body;
			}
			return array( $subject, $body );
		};

		$status_flags  = get_option( 'litecal_email_template_status', array() );
		$client_active = ! isset( $status_flags[ $template_key . '_client' ] ) || (int) $status_flags[ $template_key . '_client' ] === 1;
		$team_active   = ! isset( $status_flags[ $template_key . '_team' ] ) || (int) $status_flags[ $template_key . '_team' ] === 1;
		$guest_active  = ! isset( $status_flags[ $template_key . '_guest' ] ) || (int) $status_flags[ $template_key . '_guest' ] === 1;
		if ( self::free_plan_restrictions_enabled() ) {
			$guest_active = false;
		}

		if ( $client_active && ! empty( $payload['email'] ) && ! $is_internal_note_only_update ) {
			if ( $is_free_confirmed ) {
				$subject = $templates['confirmed_free_client_subject'] ?? $free_confirmed_defaults['client_subject'];
				$body    = $templates['confirmed_free_client_body'] ?? $free_confirmed_defaults['client_body'];
			} else {
				$subject = $templates[ $template_key . '_client_subject' ] ?? '';
				$body    = $templates[ $template_key . '_client_body' ] ?? '';
				if ( trim( $subject ) === '' ) {
					$subject = $defaults[ $template_key ]['client_subject'];
				}
				if ( trim( $body ) === '' ) {
					$body = $defaults[ $template_key ]['client_body'];
				}
			}
			[$subject, $body] = $legacy_template_upgrade( 'client', $template_key, $subject, $body );
			$client_sent      = wp_mail( $payload['email'], $apply_subject( $subject ), $apply( $body, 'client' ), $headers );
			if ( ! $client_sent ) {
				self::debug_log(
					'email_client_failed',
					array(
						'booking_id'   => (string) ( $payload['id'] ?? '' ),
						'to'           => (string) $payload['email'],
						'template_key' => $template_key,
					)
				);
			} else {
				self::debug_log(
					'email_client_sent',
					array(
						'booking_id'   => (string) ( $payload['id'] ?? '' ),
						'to'           => (string) $payload['email'],
						'template_key' => $template_key,
					)
				);
			}
		} else {
			self::debug_log(
				'email_client_skipped',
				array(
					'booking_id'         => (string) ( $payload['id'] ?? '' ),
					'template_key'       => $template_key,
					'client_active'      => $client_active ? '1' : '0',
					'has_email'          => ! empty( $payload['email'] ) ? '1' : '0',
					'internal_note_only' => $is_internal_note_only_update ? '1' : '0',
				)
			);
		}

		$team_email = $payload['employee']['email'] ?? '';
		if ( empty( $team_email ) && ! empty( $event->id ) ) {
			$event_employees = Events::employees( $event->id );
			foreach ( $event_employees as $emp ) {
				if ( ! empty( $emp->email ) ) {
					$team_email = $emp->email;
					break;
				}
			}
		}
		if ( $team_active && ! empty( $team_email ) ) {
			if ( $is_free_confirmed ) {
				$subject = $templates['confirmed_free_team_subject'] ?? $free_confirmed_defaults['team_subject'];
				$body    = $templates['confirmed_free_team_body'] ?? $free_confirmed_defaults['team_body'];
			} else {
				$subject = $templates[ $template_key . '_team_subject' ] ?? ( $templates[ $template_key . '_admin_subject' ] ?? '' );
				$body    = $templates[ $template_key . '_team_body' ] ?? ( $templates[ $template_key . '_admin_body' ] ?? '' );
				if ( trim( $subject ) === '' ) {
					$subject = $defaults[ $template_key ]['admin_subject'];
				}
				if ( trim( $body ) === '' ) {
					$body = $defaults[ $template_key ]['admin_body'];
				}
			}
			[$subject, $body] = $legacy_template_upgrade( 'team', $template_key, $subject, $body );
			$team_sent        = wp_mail( $team_email, $apply_subject( $subject ), $apply( $body, 'team' ), $headers );
			if ( ! $team_sent ) {
				self::debug_log(
					'email_team_failed',
					array(
						'booking_id'   => (string) ( $payload['id'] ?? '' ),
						'to'           => (string) $team_email,
						'template_key' => $template_key,
					)
				);
			} else {
				self::debug_log(
					'email_team_sent',
					array(
						'booking_id'   => (string) ( $payload['id'] ?? '' ),
						'to'           => (string) $team_email,
						'template_key' => $template_key,
					)
				);
			}
		} else {
			self::debug_log(
				'email_team_skipped',
				array(
					'booking_id'     => (string) ( $payload['id'] ?? '' ),
					'template_key'   => $template_key,
					'team_active'    => $team_active ? '1' : '0',
					'has_team_email' => ! empty( $team_email ) ? '1' : '0',
				)
			);
		}

			$guest_emails = array_unique( array_filter( array_map( 'sanitize_email', self::normalize_guests( $payload['guests'] ?? array() ) ) ) );
			if ( count( $guest_emails ) > self::MAX_GUESTS_PER_BOOKING ) {
				$guest_emails = array_slice( array_values( $guest_emails ), 0, self::MAX_GUESTS_PER_BOOKING );
			}
		if ( $guest_active && ! $is_internal_note_only_update && ! empty( $guest_emails ) && isset( $guest_template_defaults[ $template_key ] ) ) {
			$guest_subject = $templates[ $template_key . '_guest_subject' ] ?? $guest_template_defaults[ $template_key ]['subject'];
			$guest_body    = $templates[ $template_key . '_guest_body' ] ?? $guest_template_defaults[ $template_key ]['body'];
			foreach ( $guest_emails as $guest_email ) {
				if ( ! is_email( $guest_email ) || $guest_email === $payload['email'] || ( ! empty( $team_email ) && $guest_email === $team_email ) ) {
					continue;
				}
				$guest_sent = wp_mail( $guest_email, $apply_subject( $guest_subject ), $apply( $guest_body, 'guest' ), $headers );
				if ( ! $guest_sent ) {
					self::debug_log(
						'email_guest_failed',
						array(
							'booking_id'   => (string) ( $payload['id'] ?? '' ),
							'to'           => (string) $guest_email,
							'template_key' => $template_key,
						)
					);
				} else {
					self::debug_log(
						'email_guest_sent',
						array(
							'booking_id'   => (string) ( $payload['id'] ?? '' ),
							'to'           => (string) $guest_email,
							'template_key' => $template_key,
						)
					);
				}
			}
		} else {
			self::debug_log(
				'email_guest_skipped',
				array(
					'booking_id'         => (string) ( $payload['id'] ?? '' ),
					'template_key'       => $template_key,
					'guest_active'       => $guest_active ? '1' : '0',
					'has_guests'         => ! empty( $guest_emails ) ? '1' : '0',
					'internal_note_only' => $is_internal_note_only_update ? '1' : '0',
				)
			);
		}
	}

	public static function notify_booking_status( $booking_id, $status = null, $force = false ) {
		$booking = Bookings::get( (int) $booking_id );
		if ( ! $booking ) {
			return;
		}
		$sync_status = in_array( (string) $status, array( 'updated', 'payment_failed', 'payment_expired' ), true )
			? $booking->status
			: ( $status ?: $booking->status );
		try {
			\LiteCal\Modules\Calendar\GoogleCalendar\GoogleCalendarModule::sync_booking( $booking->id, $sync_status );
		} catch ( \Throwable $e ) {
			self::debug_log(
				'calendar_sync_exception',
				array(
					'booking_id' => (string) $booking->id,
					'status'     => (string) $sync_status,
					'error'      => $e->getMessage(),
				)
			);
		}
		$booking = Bookings::get( (int) $booking_id );
		$event   = Events::get( (int) $booking->event_id );
		if ( ! $event ) {
			return;
		}
		$snapshot        = Bookings::decode_snapshot( $booking );
		$employee        = $booking->employee_id ? Employees::get( (int) $booking->employee_id ) : null;
		$notify_status   = $status ?: $booking->status;
		$booking_token   = self::booking_access_token( $booking );
		$manage_state    = self::manage_state_for_booking( $booking, $event, $booking_token );
		$manage_urls     = is_array( $manage_state['urls'] ?? null ) ? $manage_state['urls'] : array(
			'manage'     => '',
			'reschedule' => '',
			'cancel'     => '',
		);
		$reschedule_link = ! empty( $manage_state['reschedule']['allowed'] ) ? (string) ( $manage_urls['reschedule'] ?? '' ) : '';
		$cancel_link     = ! empty( $manage_state['cancel']['allowed'] ) ? (string) ( $manage_urls['cancel'] ?? '' ) : '';
		$manage_link     = (string) ( $manage_urls['manage'] ?? '' );
		$payload         = array(
			'id'                => (int) $booking->id,
			'name'              => $booking->name,
			'email'             => $booking->email,
			'phone'             => $booking->phone,
			'message'           => $booking->message,
			'guests'            => $booking->guests ? json_decode( $booking->guests, true ) : array(),
			'start'             => $booking->start_datetime,
			'end'               => $booking->end_datetime,
			'status'            => in_array( $notify_status, array( 'updated', 'payment_failed', 'payment_expired' ), true ) ? $booking->status : $notify_status,
			'template_status'   => $notify_status,
			'payment_status'    => $booking->payment_status,
			'payment_provider'  => $booking->payment_provider,
			'payment_amount'    => (float) $booking->payment_amount,
			'payment_currency'  => $booking->payment_currency,
			'payment_reference' => $booking->payment_reference,
			'meet_link'         => $booking->calendar_meet_link ?? '',
			'meeting_provider'  => sanitize_key( (string) ( $booking->video_provider ?? ( $snapshot['event']['location'] ?? ( $event->location ?? '' ) ) ) ),
			'location_key'      => sanitize_key( (string) ( $snapshot['event']['location'] ?? ( $event->location ?? '' ) ) ),
			'location_details'  => (string) ( $snapshot['event']['location_details'] ?? ( $event->location_details ?? '' ) ),
			'event_title'       => $snapshot['event']['title'] ?? ( $event->title ?? '' ),
			'first_name'        => $snapshot['booking']['first_name'] ?? '',
			'last_name'         => $snapshot['booking']['last_name'] ?? '',
			'transfer'          => ( is_array( $snapshot['transfer'] ?? null ) ? $snapshot['transfer'] : array() ),
			'manage_link'       => $manage_link,
			'reschedule_link'   => $reschedule_link,
			'cancel_link'       => $cancel_link,
		);
		if ( $employee ) {
			$payload['employee'] = array(
				'id'    => (int) $employee->id,
				'name'  => $employee->name,
				'email' => $employee->email,
			);
		}
		if ( $notify_status === 'updated' && ! empty( $booking->snapshot_history ) ) {
			$history = json_decode( (string) $booking->snapshot_history, true );
			if ( is_array( $history ) ) {
				for ( $index = count( $history ) - 1; $index >= 0; $index-- ) {
					$entry = $history[ $index ] ?? null;
					if ( ! is_array( $entry ) ) {
						continue;
					}
					$changes = $entry['changes'] ?? null;
					if ( is_array( $changes ) && ! empty( $changes ) ) {
						$payload['updated_changes'] = $changes;
						break;
					}
				}
			}
		}
		try {
			self::send_emails( $event, $payload, $booking->start_datetime, $force );
		} catch ( \Throwable $e ) {
			self::debug_log(
				'send_emails_exception',
				array(
					'booking_id' => (string) $booking->id,
					'status'     => (string) $notify_status,
					'error'      => $e->getMessage(),
				)
			);
		}
	}
}
