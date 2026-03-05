<?php
/**
 * Public frontend rendering and shortcodes for Agenda Lite.
 *
 * @package LiteCal
 */

namespace LiteCal\Public;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// phpcs:disable Squiz.Commenting,WordPress.NamingConventions.ValidVariableName -- Project style preference: keep concise docs and legacy variable names without functional impact.

// phpcs:disable WordPress.PHP.YodaConditions.NotYoda,Universal.Operators.DisallowShortTernary.Found -- Project style preference; no functional impact.

use LiteCal\Core\Events;
use LiteCal\Core\Employees;
use LiteCal\Core\Helpers;

class PublicSite {

	private static $asset_mode_cache        = null;
	private static $marketing_head_rendered = false;
	private static $marketing_body_rendered = false;
	private static $event_template_active   = false;

	private static function public_shortcodes_enabled() {
		return (bool) apply_filters( 'litecal_enable_legacy_shortcodes', false );
	}

	public static function disabled_shortcode() {
		return '';
	}

	private static function elementor_active_kit_id() {
		if ( ! did_action( 'elementor/loaded' ) || ! class_exists( '\\Elementor\\Plugin' ) ) {
			return 0;
		}
		$elementor = \Elementor\Plugin::$instance ?? null;
		if ( ! $elementor || empty( $elementor->kits_manager ) || ! method_exists( $elementor->kits_manager, 'get_active_id' ) ) {
			return 0;
		}
		return (int) $elementor->kits_manager->get_active_id();
	}

	private static function enqueue_elementor_booking_assets() {
		if ( ! did_action( 'elementor/loaded' ) ) {
			return;
		}

		foreach ( array( 'elementor-frontend', 'elementor-icons', 'elementor-pro' ) as $style_handle ) {
			if ( wp_style_is( $style_handle, 'registered' ) ) {
				wp_enqueue_style( $style_handle );
			}
		}

		foreach ( array( 'elementor-frontend', 'elementor-pro' ) as $script_handle ) {
			if ( wp_script_is( $script_handle, 'registered' ) ) {
				wp_enqueue_script( $script_handle );
			}
		}

		$kit_id = self::elementor_active_kit_id();
		if ( $kit_id > 0 && class_exists( '\\Elementor\\Core\\Files\\CSS\\Post' ) ) {
			$css_file = \Elementor\Core\Files\CSS\Post::create( $kit_id );
			if ( $css_file && method_exists( $css_file, 'enqueue' ) ) {
				$css_file->enqueue();
			}
		}
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

	private static function enqueue_style_if_available( $handle, $url, $deps = array(), $ver = false, $media = 'all' ) {
		if ( ! $url ) {
			return false;
		}
		wp_enqueue_style( $handle, $url, $deps, $ver, $media );
		return true;
	}

	private static function add_intl_tel_flag_inline_css() {
		$flags_1x = self::vendor_asset_url( 'intl-tel-input/img/flags.png', '' );
		$flags_2x = self::vendor_asset_url( 'intl-tel-input/img/flags@2x.png', '' );
		if ( ! $flags_1x || ! wp_style_is( 'litecal-intl-tel', 'enqueued' ) ) {
			return;
		}
		$css = '.iti__flag{background-image:url("' . esc_url_raw( $flags_1x ) . '") !important;background-repeat:no-repeat !important;background-color:#dbdbdb !important;}';
		if ( $flags_2x ) {
			$css .= '@media (-webkit-min-device-pixel-ratio:2),(min-resolution:192dpi){.iti__flag{background-image:url("' . esc_url_raw( $flags_2x ) . '") !important;background-size:5652px 15px !important;}}';
		}
		wp_add_inline_style( 'litecal-intl-tel', $css );
	}

	private static function enqueue_script_if_available( $handle, $url, $deps = array(), $ver = false, $in_footer = false ) {
		if ( ! $url ) {
			return false;
		}
		wp_enqueue_script( $handle, $url, $deps, $ver, $in_footer );
		return true;
	}

	public static function vendor_asset_url( $relative, $cdn_url, &$is_external = null ) {
		$relative   = ltrim( (string) $relative, '/' );
		$local_path = LITECAL_PATH . 'assets/vendor/' . $relative;
		if ( file_exists( $local_path ) ) {
			$is_external = false;
			return LITECAL_URL . 'assets/vendor/' . $relative;
		}
		$is_external = false;
		return '';
	}

	private static function webpay_allowed_hosts() {
		$hosts = apply_filters(
			'litecal_webpay_allowed_hosts',
			array(
				'webpay3g.transbank.cl',
				'webpay3gint.transbank.cl',
			)
		);
		if ( ! is_array( $hosts ) ) {
			$hosts = array();
		}
		return array_values(
			array_unique(
				array_filter(
					array_map(
						static function ( $host ) {
							return strtolower( trim( (string) $host ) );
						},
						$hosts
					)
				)
			)
		);
	}

	private static function is_allowed_webpay_redirect_url( $url ) {
		$parts = wp_parse_url( (string) $url );
		if ( ! is_array( $parts ) ) {
			return false;
		}
		$scheme = strtolower( (string) ( $parts['scheme'] ?? '' ) );
		$host   = strtolower( (string) ( $parts['host'] ?? '' ) );
		if ( $scheme !== 'https' || $host === '' ) {
			return false;
		}
		return in_array( $host, self::webpay_allowed_hosts(), true );
	}

	public static function init() {
		add_action( 'init', array( self::class, 'rewrite' ) );
		add_filter( 'query_vars', array( self::class, 'query_vars' ) );
		add_action( 'template_redirect', array( self::class, 'template' ) );
		add_filter( 'template_include', array( self::class, 'template_include' ), 99 );
		if ( self::public_shortcodes_enabled() ) {
			add_shortcode( 'litecal_event', array( self::class, 'shortcode' ) );
			add_shortcode( 'agendalite_event', array( self::class, 'shortcode' ) );
			add_shortcode( 'litecal_public_page', array( self::class, 'public_page_shortcode' ) );
			add_shortcode( 'agendalite_public_page', array( self::class, 'public_page_shortcode' ) );
		} else {
			// Keep tags registered so legacy content does not print raw shortcode text.
			add_shortcode( 'litecal_event', array( self::class, 'disabled_shortcode' ) );
			add_shortcode( 'agendalite_event', array( self::class, 'disabled_shortcode' ) );
			add_shortcode( 'litecal_public_page', array( self::class, 'disabled_shortcode' ) );
			add_shortcode( 'agendalite_public_page', array( self::class, 'disabled_shortcode' ) );
		}
		add_action( 'wp_enqueue_scripts', array( self::class, 'assets' ) );
		add_filter( 'style_loader_tag', array( self::class, 'optimize_style_tag' ), 10, 4 );
		add_filter( 'wp_resource_hints', array( self::class, 'resource_hints' ), 10, 2 );
		add_action( 'wp_head', array( self::class, 'render_marketing_head' ), 1 );
		add_action( 'wp_head', array( self::class, 'render_runtime_seo_head' ), 2 );
		add_action( 'wp_body_open', array( self::class, 'render_marketing_body_noscript' ), 1 );
		add_filter( 'pre_get_document_title', array( self::class, 'filter_document_title' ), 999 );
		add_filter( 'document_title_parts', array( self::class, 'filter_document_title_parts' ), 999 );
		add_filter( 'get_canonical_url', array( self::class, 'filter_core_canonical_url' ), 999 );
		add_filter( 'wpseo_title', array( self::class, 'filter_wpseo_title' ), 999 );
		add_filter( 'wpseo_metadesc', array( self::class, 'filter_wpseo_description' ), 999 );
		add_filter( 'wpseo_canonical', array( self::class, 'filter_wpseo_canonical' ), 999 );
		add_filter( 'wpseo_opengraph_title', array( self::class, 'filter_wpseo_og_title' ), 999 );
		add_filter( 'wpseo_opengraph_desc', array( self::class, 'filter_wpseo_og_description' ), 999 );
		add_filter( 'wpseo_opengraph_image', array( self::class, 'filter_wpseo_og_image' ), 999 );
		add_filter( 'wpseo_twitter_title', array( self::class, 'filter_wpseo_twitter_title' ), 999 );
		add_filter( 'wpseo_twitter_description', array( self::class, 'filter_wpseo_twitter_description' ), 999 );
		add_filter( 'wpseo_twitter_image', array( self::class, 'filter_wpseo_twitter_image' ), 999 );
		add_filter( 'rank_math/frontend/title', array( self::class, 'filter_rankmath_title' ), 999 );
		add_filter( 'rank_math/frontend/description', array( self::class, 'filter_rankmath_description' ), 999 );
		add_filter( 'rank_math/frontend/canonical', array( self::class, 'filter_rankmath_canonical' ), 999 );
		add_filter( 'rank_math/opengraph/facebook/title', array( self::class, 'filter_rankmath_fb_title' ), 999 );
		add_filter( 'rank_math/opengraph/facebook/description', array( self::class, 'filter_rankmath_fb_description' ), 999 );
		add_filter( 'rank_math/opengraph/facebook/image', array( self::class, 'filter_rankmath_fb_image' ), 999 );
		add_filter( 'rank_math/opengraph/twitter/title', array( self::class, 'filter_rankmath_twitter_title' ), 999 );
		add_filter( 'rank_math/opengraph/twitter/description', array( self::class, 'filter_rankmath_twitter_description' ), 999 );
		add_filter( 'rank_math/opengraph/twitter/image', array( self::class, 'filter_rankmath_twitter_image' ), 999 );
	}

	public static function template_include( $template ) {
		if ( ! self::$event_template_active ) {
			return $template;
		}
		$wrapper = LITECAL_PATH . 'templates/event-theme.php';
		if ( file_exists( $wrapper ) ) {
			return $wrapper;
		}
		return $template;
	}

	public static function rewrite() {
		// Intentionally left minimal; we resolve event slugs dynamically on template_redirect
	}

	public static function query_vars( $vars ) {
		$vars[] = 'litecal_event';
		$vars[] = 'litecal_webpay';
		$vars[] = 'litecal_receipt';
		return $vars;
	}

	private static function marketing_config() {
		$integrations = get_option( 'litecal_integrations', array() );
		$gtm_id       = '';
		if ( ! empty( $integrations['gtm_enabled'] ) ) {
			$candidate = strtoupper( trim( (string) ( $integrations['gtm_container_id'] ?? '' ) ) );
			if ( (bool) preg_match( '/^GTM-[A-Z0-9]+$/', $candidate ) ) {
				$gtm_id = $candidate;
			}
		}
		$ga_id = '';
		if ( ! empty( $integrations['ga_enabled'] ) ) {
			$candidate = strtoupper( trim( (string) ( $integrations['ga_measurement_id'] ?? '' ) ) );
			if ( (bool) preg_match( '/^G-[A-Z0-9]+$/', $candidate ) ) {
				$ga_id = $candidate;
			}
		}
		$gads_id = '';
		if ( ! empty( $integrations['gads_enabled'] ) ) {
			$candidate = strtoupper( trim( (string) ( $integrations['gads_tag_id'] ?? '' ) ) );
			if ( (bool) preg_match( '/^AW-[A-Z0-9-]+$/', $candidate ) ) {
				$gads_id = $candidate;
			}
		}
		$meta_pixel_id = '';
		if ( ! empty( $integrations['meta_pixel_enabled'] ) ) {
			$candidate = trim( preg_replace( '/\D+/', '', (string) ( $integrations['meta_pixel_id'] ?? '' ) ) );
			if ( (bool) preg_match( '/^[0-9]{6,20}$/', $candidate ) ) {
				$meta_pixel_id = $candidate;
			}
		}
		return array(
			'gtm_id'        => $gtm_id,
			'ga_id'         => $ga_id,
			'gads_id'       => $gads_id,
			'meta_pixel_id' => $meta_pixel_id,
		);
	}

	private static function should_render_marketing() {
		if ( is_admin() ) {
			return false;
		}
		if ( empty( $GLOBALS['litecal_render_public_assets'] ) ) {
			return false;
		}
		$marketing = self::marketing_config();
		return ! empty( $marketing['gtm_id'] ) || ! empty( $marketing['ga_id'] ) || ! empty( $marketing['gads_id'] ) || ! empty( $marketing['meta_pixel_id'] );
	}

	public static function render_marketing_head() {
		if ( ! self::should_render_marketing() || self::$marketing_head_rendered ) {
			return;
		}
		$marketing     = self::marketing_config();
		$gtm_id        = (string) ( $marketing['gtm_id'] ?? '' );
		$ga_id         = (string) ( $marketing['ga_id'] ?? '' );
		$gads_id       = (string) ( $marketing['gads_id'] ?? '' );
		$meta_pixel_id = (string) ( $marketing['meta_pixel_id'] ?? '' );

		if ( $gtm_id !== '' ) {
			echo "<script>(function(w,d,s,l,i){w[l]=w[l]||[];w[l].push({'gtm.start':new Date().getTime(),event:'gtm.js'});var f=d.getElementsByTagName(s)[0],j=d.createElement(s),dl=l!='dataLayer'?'&l='+l:'';j.async=true;j.src='https://www.googletagmanager.com/gtm.js?id='+i+dl;f.parentNode.insertBefore(j,f);})(window,document,'script','dataLayer','" . esc_js( $gtm_id ) . "');</script>\n";
		}
		$gtag_ids = array_values( array_unique( array_filter( array( $ga_id, $gads_id ) ) ) );
		if ( ! empty( $gtag_ids ) ) {
			$gtag_loader = $ga_id !== '' ? $ga_id : $gads_id;
			$gtag_handle = 'litecal-gtag-loader';
			if ( ! wp_script_is( $gtag_handle, 'registered' ) ) {
				wp_register_script( $gtag_handle, 'https://www.googletagmanager.com/gtag/js?id=' . rawurlencode( (string) $gtag_loader ), array(), LITECAL_VERSION, false );
			}
			wp_enqueue_script( $gtag_handle );
			$gtag_config = "window.dataLayer=window.dataLayer||[];function gtag(){dataLayer.push(arguments);}gtag('js',new Date());";
			foreach ( $gtag_ids as $gtag_id ) {
				$gtag_config .= 'gtag("config",' . wp_json_encode( (string) $gtag_id ) . ');';
			}
			wp_add_inline_script( $gtag_handle, $gtag_config, 'after' );
		}

		if ( $meta_pixel_id !== '' ) {
			echo '<script>!function(f,b,e,v,n,t,s){if(f.fbq)return;n=f.fbq=function(){n.callMethod?';
			echo 'n.callMethod.apply(n,arguments):n.queue.push(arguments)};if(!f._fbq)f._fbq=n;';
			echo "n.push=n;n.loaded=!0;n.version='2.0';n.queue=[];t=b.createElement(e);t.async=!0;";
			echo "t.src=v;s=b.getElementsByTagName(e)[0];s.parentNode.insertBefore(t,s)}(window,document,'script','https://connect.facebook.net/en_US/fbevents.js');";
			echo "fbq('init','" . esc_js( $meta_pixel_id ) . "');fbq('track','PageView');</script>\n";
		}
		self::$marketing_head_rendered = true;
	}

	public static function render_marketing_body_noscript() {
		if ( ! self::should_render_marketing() || self::$marketing_body_rendered ) {
			return;
		}
		$marketing     = self::marketing_config();
		$gtm_id        = (string) ( $marketing['gtm_id'] ?? '' );
		$meta_pixel_id = (string) ( $marketing['meta_pixel_id'] ?? '' );
		if ( $gtm_id !== '' ) {
			echo '<noscript><iframe src="https://www.googletagmanager.com/ns.html?id=' . esc_attr( $gtm_id ) . '" height="0" width="0" style="display:none;visibility:hidden"></iframe></noscript>' . "\n";
		}
		if ( $meta_pixel_id !== '' ) {
			echo '<noscript><img height="1" width="1" style="display:none" src="https://www.facebook.com/tr?id=' . esc_attr( $meta_pixel_id ) . '&ev=PageView&noscript=1" alt=""/></noscript>' . "\n";
		}
		self::$marketing_body_rendered = true;
	}

	public static function assets() {
		$shortcodes_enabled       = self::public_shortcodes_enabled();
		$receipt_query_flag        = sanitize_key( (string) wp_unslash( $_GET['agendalite_receipt'] ?? '' ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only URL flag for frontend view state.
		$is_receipt_view           = (bool) ( get_query_var( 'litecal_receipt' ) || $receipt_query_flag !== '' );
		$slug                      = get_query_var( 'litecal_event' );
		$has_calendar_shortcode    = false;
		$has_public_page_shortcode = false;
		$has_calendar_block        = false;
		$has_public_page_block     = false;
		$content                   = '';
		if ( is_singular() ) {
			global $post;
			if ( $post && ! empty( $post->post_content ) ) {
				$content                   = (string) $post->post_content;
				if ( $shortcodes_enabled ) {
					$has_calendar_shortcode    = has_shortcode( $content, 'litecal_event' )
						|| has_shortcode( $content, 'agendalite_event' )
						|| stripos( $content, 'litecal_event' ) !== false
						|| stripos( $content, 'agendalite_event' ) !== false;
					$has_public_page_shortcode = has_shortcode( $content, 'litecal_public_page' )
						|| has_shortcode( $content, 'agendalite_public_page' )
						|| stripos( $content, 'litecal_public_page' ) !== false
						|| stripos( $content, 'agendalite_public_page' ) !== false;
				}
				$has_calendar_block        = self::has_block_name( $content, 'agendalite/service-booking' );
				$has_public_page_block     = self::has_block_name( $content, 'agendalite/public-page' );
			}
		}
		if ( ! $slug ) {
			$path = trim( wp_parse_url( sanitize_text_field( (string) wp_unslash( $_SERVER['REQUEST_URI'] ?? '' ) ), PHP_URL_PATH ), '/' );
			if ( $path ) {
				$event = \LiteCal\Core\Events::get_by_slug( $path );
				if ( $event ) {
					$slug = $event->slug;
				}
			}
		}

		if ( ! $slug && ! $has_calendar_shortcode && ! $has_public_page_shortcode && ! $has_calendar_block && ! $has_public_page_block && ! $is_receipt_view ) {
			$GLOBALS['litecal_render_public_assets'] = false;
			return;
		}
		$GLOBALS['litecal_render_public_assets'] = true;
		if ( $slug ) {
			self::enqueue_elementor_booking_assets();
		}
		if ( $shortcodes_enabled && ! $slug && $has_calendar_shortcode && $content !== '' ) {
			$slug = self::detect_shortcode_slug( $content );
		}
		if ( ! $slug && $has_calendar_block && $content !== '' ) {
			$slug = self::detect_block_service_slug( $content );
		}
		$asset_event = $slug ? Events::get_by_slug( $slug ) : null;
		if ( $asset_event && empty( $GLOBALS['litecal_current_event'] ) ) {
			$GLOBALS['litecal_current_event'] = $asset_event;
		}
		if ( ( $has_public_page_shortcode || $has_public_page_block ) && empty( $GLOBALS['litecal_public_page_detected'] ) ) {
			$GLOBALS['litecal_public_page_detected']  = true;
			$GLOBALS['litecal_public_page_canonical'] = self::current_request_canonical_url();
		}
		$is_booking_view = (bool) ( $slug || $has_calendar_shortcode || $has_calendar_block );
		if ( ( $has_public_page_shortcode || $has_public_page_block ) && ! $has_calendar_shortcode && ! $has_calendar_block && ! $slug ) {
			$is_booking_view = false;
		}
		$event_has_file_fields = self::event_has_file_fields( $asset_event );
		$public_css_ver        = file_exists( LITECAL_PATH . 'assets/public/public.css' ) ? (string) filemtime( LITECAL_PATH . 'assets/public/public.css' ) : LITECAL_VERSION;
		$public_js_ver         = file_exists( LITECAL_PATH . 'assets/public/public.js' ) ? (string) filemtime( LITECAL_PATH . 'assets/public/public.js' ) : LITECAL_VERSION;
		$public_script_deps    = array();
		wp_enqueue_style( 'litecal-public', LITECAL_URL . 'assets/public/public.css', array(), $public_css_ver );
		$remixicon_external = false;
		$inter_external     = false;
		$remixicon_url      = self::vendor_asset_url( 'remixicon/remixicon.css', '', $remixicon_external );
		self::enqueue_style_if_available( 'litecal-remixicon', $remixicon_url, array(), '4.9.1' );
		if ( $is_booking_view || $is_receipt_view ) {
			$notyf_css_url = self::vendor_asset_url( 'notyf/notyf.min.css', '' );
			self::enqueue_style_if_available( 'litecal-notyf-public', $notyf_css_url, array(), '3.10.0' );
			$notyf_js_url = self::vendor_asset_url( 'notyf/notyf.min.js', '' );
			if ( self::enqueue_script_if_available( 'litecal-notyf-public', $notyf_js_url, array(), '3.10.0', true ) ) {
				wp_script_add_data( 'litecal-notyf-public', 'defer', true );
				$public_script_deps[] = 'litecal-notyf-public';
			}
			$inter_url = self::vendor_asset_url( 'inter/inter.css', '', $inter_external );
			self::enqueue_style_if_available( 'litecal-inter', $inter_url, array(), null );
		}
		if ( $is_booking_view && ! $is_receipt_view ) {
			$intl_tel_css_external = false;
			$intl_tel_js_external  = false;
			$intl_tel_css_url      = self::vendor_asset_url( 'intl-tel-input/intlTelInput.css', '', $intl_tel_css_external );
			$intl_tel_js_url       = self::vendor_asset_url( 'intl-tel-input/intlTelInput.min.js', '', $intl_tel_js_external );
			self::enqueue_style_if_available( 'litecal-intl-tel', $intl_tel_css_url, array(), '17.0.12' );
			self::add_intl_tel_flag_inline_css();
			if ( self::enqueue_script_if_available( 'litecal-intl-tel', $intl_tel_js_url, array(), '17.0.12', true ) ) {
				wp_script_add_data( 'litecal-intl-tel', 'defer', true );
				$public_script_deps[] = 'litecal-intl-tel';
			}
			$GLOBALS['litecal_ext_hint_intl_tel'] = ( $intl_tel_css_external || $intl_tel_js_external );

			if ( $event_has_file_fields ) {
				$filepond_css_external         = false;
				$filepond_js_external          = false;
				$filepond_preview_css_external = false;
				$filepond_preview_js_external  = false;
				$filepond_css                  = self::vendor_asset_url( 'filepond/filepond.min.css', '', $filepond_css_external );
				$filepond_js                   = self::vendor_asset_url( 'filepond/filepond.min.js', '', $filepond_js_external );
				$filepond_preview_css          = self::vendor_asset_url( 'filepond/filepond-plugin-image-preview.min.css', '', $filepond_preview_css_external );
				$filepond_preview_js           = self::vendor_asset_url( 'filepond/filepond-plugin-image-preview.min.js', '', $filepond_preview_js_external );
				$filepond_style_loaded         = self::enqueue_style_if_available( 'litecal-filepond-public', $filepond_css, array(), '4.31.4' );
				self::enqueue_style_if_available( 'litecal-filepond-image-preview-public', $filepond_preview_css, $filepond_style_loaded ? array( 'litecal-filepond-public' ) : array(), '4.6.12' );
				$filepond_script_loaded = self::enqueue_script_if_available( 'litecal-filepond-public', $filepond_js, array(), '4.31.4', true );
				if ( self::enqueue_script_if_available( 'litecal-filepond-image-preview-public', $filepond_preview_js, $filepond_script_loaded ? array( 'litecal-filepond-public' ) : array(), '4.6.12', true ) ) {
					wp_script_add_data( 'litecal-filepond-image-preview-public', 'defer', true );
					$public_script_deps[] = 'litecal-filepond-image-preview-public';
				} elseif ( $filepond_script_loaded ) {
					$public_script_deps[] = 'litecal-filepond-public';
				}
				if ( $filepond_script_loaded ) {
					wp_script_add_data( 'litecal-filepond-public', 'defer', true );
				}
				$GLOBALS['litecal_ext_hint_filepond'] = ( $filepond_css_external || $filepond_js_external || $filepond_preview_css_external || $filepond_preview_js_external );
			}
		}
		if ( $is_booking_view || $is_receipt_view ) {
			wp_enqueue_script( 'litecal-public', LITECAL_URL . 'assets/public/public.js', $public_script_deps, $public_js_ver, true );
			wp_script_add_data( 'litecal-public', 'defer', true );
		}
		$GLOBALS['litecal_ext_hint_remixicon'] = $remixicon_external;
		$GLOBALS['litecal_ext_hint_inter']     = $inter_external;

		$integrations      = get_option( 'litecal_integrations', array() );
		$recaptcha_enabled = ! empty( $integrations['recaptcha_enabled'] ) && ! empty( $integrations['recaptcha_site_key'] );
		if ( $recaptcha_enabled && $is_booking_view && ! $is_receipt_view ) {
			wp_enqueue_script(
				'litecal-recaptcha',
				'https://www.google.com/recaptcha/api.js?render=' . rawurlencode( $integrations['recaptcha_site_key'] ),
				array(),
					LITECAL_VERSION,
				true
			);
			$GLOBALS['litecal_ext_hint_recaptcha'] = true;
		}

		if ( $is_booking_view || $is_receipt_view ) {
			wp_localize_script(
				'litecal-public',
				'litecal',
				array(
					'restUrl'   => esc_url_raw( rest_url( 'litecal/v1' ) ),
					'nonce'     => wp_create_nonce( 'wp_rest' ),
					'logos'     => array(
						'googleMeet' => esc_url_raw( LITECAL_URL . 'assets/logos/googlemeet.svg' ),
						'zoom'       => esc_url_raw( LITECAL_URL . 'assets/logos/zoom.svg' ),
						'teams'      => esc_url_raw( LITECAL_URL . 'assets/logos/teams.svg' ),
					),
					'recaptcha' => array(
						'enabled' => $recaptcha_enabled ? 1 : 0,
						'siteKey' => $recaptcha_enabled ? (string) $integrations['recaptcha_site_key'] : '',
					),
				)
			);
		}
	}

	public static function optimize_style_tag( $html, $handle, $href, $media ) {
		$non_blocking = array(
			'litecal-remixicon',
			'litecal-inter',
			'litecal-intl-tel',
			'litecal-notyf-public',
			'litecal-filepond-public',
			'litecal-filepond-image-preview-public',
		);
		if ( ! in_array( $handle, $non_blocking, true ) ) {
			return $html;
		}
		$media_attr = $media && $media !== 'all' ? $media : 'all';
		$href_esc   = esc_url( $href );
		return '<link rel="preload" as="style" href="' . $href_esc . '" onload="this.onload=null;this.rel=\'stylesheet\'" media="' . esc_attr( $media_attr ) . '">';
	}

	public static function resource_hints( $urls, $relation_type ) {
		if ( ! in_array( $relation_type, array( 'preconnect', 'dns-prefetch' ), true ) ) {
			return $urls;
		}
		if ( ! wp_style_is( 'litecal-public', 'enqueued' ) && ! wp_script_is( 'litecal-public', 'enqueued' ) ) {
			return $urls;
		}
		$hosts = array();
		if ( ! empty( $GLOBALS['litecal_ext_hint_recaptcha'] ) ) {
			$hosts[] = 'https://www.google.com';
		}
		$marketing = self::marketing_config();
		if ( ! empty( $marketing['gtm_id'] ) || ! empty( $marketing['ga_id'] ) || ! empty( $marketing['gads_id'] ) ) {
			$hosts[] = 'https://www.googletagmanager.com';
			$hosts[] = 'https://www.google-analytics.com';
		}
		if ( ! empty( $marketing['meta_pixel_id'] ) ) {
			$hosts[] = 'https://connect.facebook.net';
			$hosts[] = 'https://www.facebook.com';
		}
		$hosts = array_values( array_unique( $hosts ) );
		foreach ( $hosts as $host ) {
			if ( ! in_array( $host, $urls, true ) ) {
				$urls[] = $host;
			}
		}
		return $urls;
	}

	private static function detect_shortcode_slug( $content ) {
		$content = (string) $content;
		if ( $content === '' ) {
			return '';
		}
		$patterns = array(
			'/\[(?:litecal_event|agendalite_event)[^\]]*slug=["\']([a-z0-9\-]+)["\']/i',
			'/\[(?:litecal_event|agendalite_event)[^\]]*slug=([a-z0-9\-]+)/i',
		);
		foreach ( $patterns as $pattern ) {
			if ( preg_match( $pattern, $content, $matches ) ) {
				return sanitize_title( (string) ( $matches[1] ?? '' ) );
			}
		}
		return '';
	}

	private static function has_block_name( $content, $block_name ) {
		$content    = (string) $content;
		$block_name = trim( (string) $block_name );
		if ( $content === '' || $block_name === '' ) {
			return false;
		}
		if ( function_exists( 'has_block' ) && has_block( $block_name, $content ) ) {
			return true;
		}
		return strpos( $content, '<!-- wp:' . $block_name ) !== false;
	}

	private static function detect_block_service_slug( $content ) {
		if ( ! function_exists( 'parse_blocks' ) ) {
			return '';
		}
		$queue = parse_blocks( (string) $content );
		if ( ! is_array( $queue ) ) {
			return '';
		}
		while ( ! empty( $queue ) ) {
			$block = array_shift( $queue );
			if ( ! is_array( $block ) ) {
				continue;
			}
			if ( ( $block['blockName'] ?? '' ) === 'agendalite/service-booking' ) {
				$slug = sanitize_title( (string) ( $block['attrs']['slug'] ?? '' ) );
				if ( $slug !== '' ) {
					return $slug;
				}
			}
			if ( ! empty( $block['innerBlocks'] ) && is_array( $block['innerBlocks'] ) ) {
				$queue = array_merge( $queue, $block['innerBlocks'] );
			}
		}
		return '';
	}

	private static function event_has_file_fields( $event ) {
		if ( self::free_plan_restrictions_enabled() ) {
			return false;
		}
		if ( ! $event || empty( $event->custom_fields ) ) {
			return false;
		}
		$fields = json_decode( (string) $event->custom_fields, true );
		if ( ! is_array( $fields ) ) {
			return false;
		}
		foreach ( $fields as $field ) {
			if ( ! is_array( $field ) ) {
				continue;
			}
			if ( isset( $field['enabled'] ) && empty( $field['enabled'] ) ) {
				continue;
			}
			if ( sanitize_key( (string) ( $field['type'] ?? '' ) ) === 'file' ) {
				return true;
			}
		}
		return false;
	}

	public static function has_external_seo_plugin() {
		return defined( 'WPSEO_VERSION' ) || defined( 'RANK_MATH_VERSION' );
	}

	private static function seo_clean_text( $text ) {
		$clean = wp_strip_all_tags( (string) $text, true );
		$clean = preg_replace( '/\s+/u', ' ', $clean );
		return trim( (string) $clean );
	}

	private static function seo_finalize_description( $text ) {
		$value = trim( (string) $text );
		if ( $value === '' ) {
			return '';
		}
		$parts = preg_split( '/\s+/u', $value );
		if ( ! is_array( $parts ) ) {
			$parts = array( $value );
		}
		$stop_words = array(
			'a',
			'al',
			'con',
			'de',
			'del',
			'desde',
			'e',
			'el',
			'en',
			'la',
			'las',
			'los',
			'o',
			'para',
			'por',
			'sin',
			'su',
			'sus',
			'u',
			'y',
		);
		while ( ! empty( $parts ) ) {
			$last_raw = (string) end( $parts );
			$last     = function_exists( 'mb_strtolower' ) ? mb_strtolower( $last_raw ) : strtolower( $last_raw );
			$last     = trim( $last, " \t\n\r\0\x0B,.;:-_/" );
			if ( $last === '' || in_array( $last, $stop_words, true ) ) {
				array_pop( $parts );
				continue;
			}
			break;
		}
		$final = trim( implode( ' ', $parts ) );
		if ( $final === '' ) {
			return '';
		}
		$final = rtrim( $final, " \t\n\r\0\x0B,;:-_/" );
		if ( ! preg_match( '/[.!?]$/u', $final ) ) {
			$final .= '.';
		}
		return $final;
	}

	private static function seo_truncate( $text, $max ) {
		$value = self::seo_clean_text( $text );
		$len   = function_exists( 'mb_strlen' ) ? mb_strlen( $value ) : strlen( $value );
		if ( $value === '' || $len <= $max ) {
			return $value;
		}
		$slice = function_exists( 'mb_substr' ) ? mb_substr( $value, 0, $max + 1 ) : substr( $value, 0, $max + 1 );
		if ( preg_match( '/^(.+)\s[^\s]*$/u', $slice, $matches ) ) {
			return rtrim( $matches[1], ' ,.;:-|/' );
		}
		$hard = function_exists( 'mb_substr' ) ? mb_substr( $value, 0, $max ) : substr( $value, 0, $max );
		return rtrim( $hard, ' ,.;:-|/' );
	}

	private static function event_seo_image( $event, $settings = array() ) {
		$options = json_decode( (string) ( $event->options ?? '[]' ), true );
		if ( ! is_array( $options ) ) {
			$options = array();
		}
		$candidates = array(
			$event->image ?? '',
			$options['seo_image'] ?? '',
			$options['image'] ?? '',
			$options['image_url'] ?? '',
			$options['cover_image'] ?? '',
			$options['featured_image'] ?? '',
			$options['thumbnail'] ?? '',
			$options['thumbnail_url'] ?? '',
			self::seo_default_image( $settings ),
		);
		foreach ( $candidates as $candidate ) {
			$url = esc_url_raw( (string) $candidate );
			if ( $url !== '' ) {
				return $url;
			}
		}
		return '';
	}

	private static function seo_default_image( $settings = array() ) {
		$default = esc_url_raw( (string) ( $settings['seo_default_image'] ?? '' ) );
		if ( $default !== '' ) {
			return $default;
		}
		$site_icon_id = (int) get_option( 'site_icon' );
		if ( $site_icon_id > 0 ) {
			$icon = wp_get_attachment_image_url( $site_icon_id, 'full' );
			if ( $icon ) {
				return esc_url_raw( $icon );
			}
		}
		return '';
	}

	private static function current_request_canonical_url() {
		if ( is_singular() ) {
			$permalink = get_permalink();
			if ( $permalink ) {
				return esc_url_raw( $permalink );
			}
		}
		$path = (string) wp_parse_url( sanitize_text_field( (string) wp_unslash( $_SERVER['REQUEST_URI'] ?? '' ) ), PHP_URL_PATH );
		$path = '/' . ltrim( $path, '/' );
		if ( $path === '//' ) {
			$path = '/';
		}
		if ( $path !== '/' && substr( $path, -1 ) !== '/' ) {
			$path .= '/';
		}
		return esc_url_raw( home_url( $path ) );
	}

	private static function current_post_content_for_seo() {
		if ( ! is_singular() ) {
			return '';
		}
		global $post;
		if ( ! $post || empty( $post->post_content ) ) {
			return '';
		}
		return (string) $post->post_content;
	}

	private static function build_public_page_seo_payload( $canonical = '' ) {
		$settings = get_option( 'litecal_settings', array() );
		$brand    = self::seo_clean_text( $settings['seo_brand'] ?? get_bloginfo( 'name' ) );
		if ( $brand === '' ) {
			$brand = self::seo_clean_text( get_bloginfo( 'name' ) );
		}
		$title              = self::seo_truncate( $brand, 60 );
		$source_description = self::seo_clean_text( $settings['seo_fallback_description'] ?? '' );
		if ( $source_description === '' ) {
			$source_description = self::seo_clean_text( get_bloginfo( 'description' ) );
		}
		if ( $source_description === '' ) {
			$source_description = $brand;
		}
		$description = self::seo_finalize_description( self::seo_truncate( $source_description, 160 ) );
		$image       = self::seo_default_image( $settings );
		if ( $canonical === '' ) {
			$canonical = self::current_request_canonical_url();
		}

		return array(
			'brand'        => $brand,
			'title'        => $title,
			'description'  => $description,
			'canonical'    => esc_url_raw( $canonical ),
			'image'        => $image,
			'external_seo' => self::has_external_seo_plugin(),
		);
	}

	public static function normalize_event_location( $event ) {
		$key = sanitize_key( (string) ( $event->location ?? '' ) );
		if ( $key === '' ) {
			$key = 'presencial';
		}
		$details_raw   = (string) ( $event->location_details ?? '' );
		$details       = self::seo_clean_text( $details_raw );
		$is_virtual    = in_array( $key, array( 'google_meet', 'zoom', 'teams', 'online', 'virtual' ), true );
		$is_presential = in_array( $key, array( 'presencial', 'in_person', 'presential' ), true );
		$is_phone      = in_array( $key, array( 'phone', 'telefono', 'telephone' ), true );
		$details_url   = '';
		if ( $details !== '' && filter_var( $details, FILTER_VALIDATE_URL ) ) {
			$details_url = esc_url_raw( $details );
		}
		$label_map = array(
			'presencial'  => 'Presencial',
			'google_meet' => 'Google Meet',
			'zoom'        => 'Zoom',
			'teams'       => 'Microsoft Teams',
			'phone'       => 'Teléfono',
			'telefono'    => 'Teléfono',
			'telephone'   => 'Teléfono',
		);
		$label     = $label_map[ $key ] ?? ucwords( str_replace( '_', ' ', $key ) );
		return array(
			'key'           => $key,
			'label'         => $label,
			'details'       => $details,
			'details_url'   => $details_url,
			'is_virtual'    => $is_virtual,
			'is_presential' => $is_presential,
			'is_phone'      => $is_phone,
		);
	}

	public static function build_event_seo_payload( $event ) {
		$settings = get_option( 'litecal_settings', array() );
		$brand    = self::seo_clean_text( $settings['seo_brand'] ?? get_bloginfo( 'name' ) );
		if ( $brand === '' ) {
			$brand = self::seo_clean_text( get_bloginfo( 'name' ) );
		}
		$event_title = self::seo_clean_text( $event->title ?? '' );
		if ( $event_title === '' ) {
			// If the service has no title, keep SEO controlled by the global base title.
			$title = self::seo_truncate( $brand !== '' ? $brand : __( 'Servicio', 'agenda-lite' ), 60 );
		} else {
			$title = self::seo_truncate( $event_title, 60 );
		}
		$source_description = self::seo_clean_text( $event->description ?? '' );
		if ( $source_description === '' ) {
			$source_description = self::seo_clean_text( $settings['seo_fallback_description'] ?? '' );
		}
		if ( $source_description === '' ) {
			$source_description = ( $event_title !== '' ) ? $event_title : $brand;
		}
		$description = self::seo_finalize_description( self::seo_truncate( $source_description, 160 ) );
		$canonical   = home_url( '/' . trim( (string) ( $event->slug ?? '' ), '/' ) . '/' );
		$image       = self::event_seo_image( $event, $settings );

		return array(
			'brand'        => $brand,
			'title'        => $title,
			'description'  => $description,
			'canonical'    => esc_url_raw( $canonical ),
			'image'        => $image,
			'external_seo' => self::has_external_seo_plugin(),
		);
	}

	private static function build_event_schema( $event, $seo ) {
		$settings = get_option( 'litecal_settings', array() );
		$currency = strtoupper( (string) ( $event->currency ?: ( $settings['currency'] ?? 'CLP' ) ) );
		$price    = (float) ( $event->price ?? 0 );
		$location = self::normalize_event_location( $event );
		$schema   = array(
			'@context'    => 'https://schema.org',
			'@type'       => 'Service',
			'name'        => $seo['title'],
			'description' => $seo['description'],
			'url'         => $seo['canonical'],
			'provider'    => array(
				'@type' => 'Organization',
				'name'  => $seo['brand'] ?: get_bloginfo( 'name' ),
				'url'   => home_url( '/' ),
			),
		);
		if ( ! empty( $seo['image'] ) ) {
			$schema['image'] = $seo['image'];
		}
		if ( $location['is_virtual'] ) {
			$virtual_platform_url = array(
				'google_meet' => 'https://meet.google.com/',
				'zoom'        => 'https://zoom.us/',
				'teams'       => 'https://teams.microsoft.com/',
			);
			$virtual_url          = $location['details_url'];
			if ( $virtual_url === '' && ! empty( $virtual_platform_url[ $location['key'] ] ) ) {
				$virtual_url = $virtual_platform_url[ $location['key'] ];
			}
			if ( $virtual_url !== '' ) {
				$schema['location'] = array(
					'@type' => 'VirtualLocation',
					'url'   => $virtual_url,
				);
			}
		} elseif ( $location['is_presential'] && $location['details'] !== '' ) {
			$schema['location'] = array(
				'@type'   => 'Place',
				'name'    => $location['details'],
				'address' => array(
					'@type'         => 'PostalAddress',
					'streetAddress' => $location['details'],
				),
			);
		} elseif ( $location['is_phone'] ) {
			$schema['availableChannel'] = array(
				'@type'             => 'ServiceChannel',
				'serviceUrl'        => $seo['canonical'],
				'availableLanguage' => get_locale(),
			);
		}
		if ( $price > 0 ) {
			$schema['offers'] = array(
				'@type'         => 'Offer',
				'price'         => $price,
				'priceCurrency' => $currency,
				'url'           => $seo['canonical'],
			);
		}
		return $schema;
	}

	public static function render_event_seo_head( $event ) {
		$GLOBALS['litecal_runtime_seo_head_rendered'] = true;
		$seo      = self::build_event_seo_payload( $event );
		$external = ! empty( $seo['external_seo'] );
		if ( ! $external ) {
			if ( ! empty( $seo['title'] ) ) {
				echo '<title>' . esc_html( $seo['title'] ) . '</title>' . "\n";
			}
			if ( ! empty( $seo['description'] ) ) {
				echo '<meta name="description" content="' . esc_attr( $seo['description'] ) . '">' . "\n";
			}
			if ( ! empty( $seo['canonical'] ) ) {
				echo '<link rel="canonical" href="' . esc_url( $seo['canonical'] ) . '">' . "\n";
				echo '<meta property="og:url" content="' . esc_url( $seo['canonical'] ) . '">' . "\n";
			}
			if ( ! empty( $seo['title'] ) ) {
				echo '<meta property="og:title" content="' . esc_attr( $seo['title'] ) . '">' . "\n";
				echo '<meta name="twitter:title" content="' . esc_attr( $seo['title'] ) . '">' . "\n";
			}
			if ( ! empty( $seo['description'] ) ) {
				echo '<meta property="og:description" content="' . esc_attr( $seo['description'] ) . '">' . "\n";
				echo '<meta name="twitter:description" content="' . esc_attr( $seo['description'] ) . '">' . "\n";
			}
			echo '<meta property="og:type" content="website">' . "\n";
			echo '<meta name="twitter:card" content="summary_large_image">' . "\n";
			if ( ! empty( $seo['image'] ) ) {
				echo '<meta property="og:image" content="' . esc_url( $seo['image'] ) . '">' . "\n";
				echo '<meta name="twitter:image" content="' . esc_url( $seo['image'] ) . '">' . "\n";
			}
		}
		$schema = self::build_event_schema( $event, $seo );
		if ( ! empty( $schema ) ) {
			echo '<script type="application/ld+json">' . wp_json_encode( $schema, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) . '</script>' . "\n";
		}
	}

	private static function maybe_set_runtime_seo_context() {
		if ( is_admin() ) {
			return;
		}
		$shortcodes_enabled       = self::public_shortcodes_enabled();

		$content                   = self::current_post_content_for_seo();
		$has_calendar_shortcode    = false;
		$has_public_page_shortcode = false;
		$has_calendar_block        = false;
		$has_public_page_block     = false;
		if ( $content !== '' ) {
			if ( $shortcodes_enabled ) {
				$has_calendar_shortcode    = has_shortcode( $content, 'litecal_event' )
					|| has_shortcode( $content, 'agendalite_event' )
					|| stripos( $content, 'litecal_event' ) !== false
					|| stripos( $content, 'agendalite_event' ) !== false;
				$has_public_page_shortcode = has_shortcode( $content, 'litecal_public_page' )
					|| has_shortcode( $content, 'agendalite_public_page' )
					|| stripos( $content, 'litecal_public_page' ) !== false
					|| stripos( $content, 'agendalite_public_page' ) !== false;
			}
			$has_calendar_block        = self::has_block_name( $content, 'agendalite/service-booking' );
			$has_public_page_block     = self::has_block_name( $content, 'agendalite/public-page' );
		}

		if ( $has_public_page_shortcode || $has_public_page_block ) {
			$GLOBALS['litecal_public_page_detected'] = true;
			if ( empty( $GLOBALS['litecal_public_page_canonical'] ) ) {
				$GLOBALS['litecal_public_page_canonical'] = self::current_request_canonical_url();
			}
			if ( ! $has_calendar_shortcode && ! $has_calendar_block && empty( get_query_var( 'litecal_event' ) ) ) {
				unset( $GLOBALS['litecal_current_event'] );
			}
		}

		if ( ! empty( $GLOBALS['litecal_current_event'] ) ) {
			return;
		}

		if ( ( $has_public_page_shortcode || $has_public_page_block ) && ! $has_calendar_shortcode && ! $has_calendar_block ) {
			return;
		}

		$event = null;
		$slug  = sanitize_title( (string) get_query_var( 'litecal_event' ) );
		if ( $slug !== '' ) {
			$event = Events::get_by_slug( $slug );
		}
		if ( ! $event ) {
			$path = trim( (string) wp_parse_url( sanitize_text_field( (string) wp_unslash( $_SERVER['REQUEST_URI'] ?? '' ) ), PHP_URL_PATH ), '/' );
			if ( $path !== '' ) {
				$event = Events::get_by_slug( $path );
			}
		}
		if ( $shortcodes_enabled && ! $event && $has_calendar_shortcode ) {
			$shortcode_slug = self::detect_shortcode_slug( $content );
			if ( $shortcode_slug !== '' ) {
				$event = Events::get_by_slug( $shortcode_slug );
			}
		}
		if ( ! $event && $has_calendar_block ) {
			$block_slug = self::detect_block_service_slug( $content );
			if ( $block_slug !== '' ) {
				$event = Events::get_by_slug( $block_slug );
			}
		}
		if ( $event && ! empty( $event->id ) ) {
			$GLOBALS['litecal_current_event'] = $event;
		}
	}

	private static function current_event_for_seo() {
		if ( is_admin() ) {
			return null;
		}
		self::maybe_set_runtime_seo_context();
		$event = $GLOBALS['litecal_current_event'] ?? null;
		if ( ! is_object( $event ) || empty( $event->id ) ) {
			return null;
		}
		return $event;
	}

	private static function current_event_seo_payload() {
		$event = self::current_event_for_seo();
		if ( ! $event ) {
			return null;
		}
		return self::build_event_seo_payload( $event );
	}

	private static function current_public_page_seo_payload() {
		if ( is_admin() ) {
			return null;
		}
		self::maybe_set_runtime_seo_context();
		if ( empty( $GLOBALS['litecal_public_page_detected'] ) ) {
			return null;
		}
		$canonical = esc_url_raw( (string) ( $GLOBALS['litecal_public_page_canonical'] ?? '' ) );
		if ( ! empty( $GLOBALS['litecal_public_page_seo_payload'] ) && is_array( $GLOBALS['litecal_public_page_seo_payload'] ) ) {
			return $GLOBALS['litecal_public_page_seo_payload'];
		}
		$payload                                    = self::build_public_page_seo_payload( $canonical );
		$GLOBALS['litecal_public_page_seo_payload'] = $payload;
		return $payload;
	}

	private static function current_seo_payload() {
		$public_seo = self::current_public_page_seo_payload();
		if ( $public_seo ) {
			return $public_seo;
		}
		return self::current_event_seo_payload();
	}

	public static function filter_document_title( $title ) {
		$seo = self::current_seo_payload();
		if ( ! $seo || empty( $seo['title'] ) ) {
			return $title;
		}
		return (string) $seo['title'];
	}

	public static function filter_document_title_parts( $parts ) {
		if ( ! is_array( $parts ) ) {
			return $parts;
		}
		$seo = self::current_seo_payload();
		if ( ! $seo || empty( $seo['title'] ) ) {
			return $parts;
		}
		$parts['title'] = (string) $seo['title'];
		return $parts;
	}

	public static function filter_core_canonical_url( $canonical ) {
		$seo = self::current_seo_payload();
		if ( $seo && ! empty( $seo['canonical'] ) ) {
			return (string) $seo['canonical'];
		}
		return $canonical;
	}

	public static function filter_wpseo_title( $title ) {
		$seo = self::current_seo_payload();
		return ( $seo && ! empty( $seo['title'] ) ) ? (string) $seo['title'] : $title;
	}

	public static function filter_wpseo_description( $description ) {
		$seo = self::current_seo_payload();
		return ( $seo && ! empty( $seo['description'] ) ) ? (string) $seo['description'] : $description;
	}

	public static function filter_wpseo_canonical( $canonical ) {
		$seo = self::current_seo_payload();
		return ( $seo && ! empty( $seo['canonical'] ) ) ? (string) $seo['canonical'] : $canonical;
	}

	public static function filter_wpseo_og_title( $title ) {
		$seo = self::current_seo_payload();
		return ( $seo && ! empty( $seo['title'] ) ) ? (string) $seo['title'] : $title;
	}

	public static function filter_wpseo_og_description( $description ) {
		$seo = self::current_seo_payload();
		return ( $seo && ! empty( $seo['description'] ) ) ? (string) $seo['description'] : $description;
	}

	public static function filter_wpseo_og_image( $image ) {
		$seo = self::current_seo_payload();
		return ( $seo && ! empty( $seo['image'] ) ) ? (string) $seo['image'] : $image;
	}

	public static function filter_wpseo_twitter_title( $title ) {
		$seo = self::current_seo_payload();
		return ( $seo && ! empty( $seo['title'] ) ) ? (string) $seo['title'] : $title;
	}

	public static function filter_wpseo_twitter_description( $description ) {
		$seo = self::current_seo_payload();
		return ( $seo && ! empty( $seo['description'] ) ) ? (string) $seo['description'] : $description;
	}

	public static function filter_wpseo_twitter_image( $image ) {
		$seo = self::current_seo_payload();
		return ( $seo && ! empty( $seo['image'] ) ) ? (string) $seo['image'] : $image;
	}

	public static function filter_rankmath_title( $title ) {
		$seo = self::current_seo_payload();
		return ( $seo && ! empty( $seo['title'] ) ) ? (string) $seo['title'] : $title;
	}

	public static function filter_rankmath_description( $description ) {
		$seo = self::current_seo_payload();
		return ( $seo && ! empty( $seo['description'] ) ) ? (string) $seo['description'] : $description;
	}

	public static function filter_rankmath_canonical( $canonical ) {
		$seo = self::current_seo_payload();
		return ( $seo && ! empty( $seo['canonical'] ) ) ? (string) $seo['canonical'] : $canonical;
	}

	public static function filter_rankmath_fb_title( $title ) {
		$seo = self::current_seo_payload();
		return ( $seo && ! empty( $seo['title'] ) ) ? (string) $seo['title'] : $title;
	}

	public static function filter_rankmath_fb_description( $description ) {
		$seo = self::current_seo_payload();
		return ( $seo && ! empty( $seo['description'] ) ) ? (string) $seo['description'] : $description;
	}

	public static function filter_rankmath_fb_image( $image ) {
		$seo = self::current_seo_payload();
		return ( $seo && ! empty( $seo['image'] ) ) ? (string) $seo['image'] : $image;
	}

	public static function filter_rankmath_twitter_title( $title ) {
		$seo = self::current_seo_payload();
		return ( $seo && ! empty( $seo['title'] ) ) ? (string) $seo['title'] : $title;
	}

	public static function filter_rankmath_twitter_description( $description ) {
		$seo = self::current_seo_payload();
		return ( $seo && ! empty( $seo['description'] ) ) ? (string) $seo['description'] : $description;
	}

	public static function filter_rankmath_twitter_image( $image ) {
		$seo = self::current_seo_payload();
		return ( $seo && ! empty( $seo['image'] ) ) ? (string) $seo['image'] : $image;
	}

	public static function render_runtime_seo_head() {
		if ( ! empty( $GLOBALS['litecal_runtime_seo_head_rendered'] ) ) {
			return;
		}
		$seo = self::current_seo_payload();
		if ( ! $seo || ! is_array( $seo ) ) {
			return;
		}
		if ( ! empty( $seo['external_seo'] ) ) {
			return;
		}
		$GLOBALS['litecal_runtime_seo_head_rendered'] = true;
		if ( ! empty( $seo['title'] ) ) {
			echo '<meta property="og:title" content="' . esc_attr( (string) $seo['title'] ) . '">' . "\n";
			echo '<meta name="twitter:title" content="' . esc_attr( (string) $seo['title'] ) . '">' . "\n";
		}
		if ( ! empty( $seo['description'] ) ) {
			echo '<meta name="description" content="' . esc_attr( (string) $seo['description'] ) . '">' . "\n";
			echo '<meta property="og:description" content="' . esc_attr( (string) $seo['description'] ) . '">' . "\n";
			echo '<meta name="twitter:description" content="' . esc_attr( (string) $seo['description'] ) . '">' . "\n";
		}
		if ( ! empty( $seo['canonical'] ) ) {
			echo '<link rel="canonical" href="' . esc_url( (string) $seo['canonical'] ) . '">' . "\n";
			echo '<meta property="og:url" content="' . esc_url( (string) $seo['canonical'] ) . '">' . "\n";
		}
		echo '<meta property="og:type" content="website">' . "\n";
		echo '<meta name="twitter:card" content="summary_large_image">' . "\n";
		if ( ! empty( $seo['image'] ) ) {
			echo '<meta property="og:image" content="' . esc_url( (string) $seo['image'] ) . '">' . "\n";
			echo '<meta name="twitter:image" content="' . esc_url( (string) $seo['image'] ) . '">' . "\n";
		}
	}

	public static function template() {
		if ( ! empty( $_POST['token_ws'] ) ) {
			if ( ! function_exists( 'litecal_pro_feature_enabled' ) || ! litecal_pro_feature_enabled( 'payments_webpay' ) ) {
				wp_safe_redirect( home_url( '/' ) );
				exit;
			}
				$token  = sanitize_text_field( wp_unslash( $_POST['token_ws'] ) );
			$provider   = new \LiteCal\Modules\Payments\WebpayPlus\WebpayPlusProvider();
			$commit     = $provider->commit( $token );
			$booking_id = 0;
			$status     = 'failed';
			if ( ! is_wp_error( $commit ) ) {
				$booking_id    = (int) ( $commit['buy_order'] ?? 0 );
				$wp_status     = strtoupper( (string) ( $commit['status'] ?? '' ) );
				$response_code = isset( $commit['response_code'] ) ? (int) $commit['response_code'] : -1;
				$is_approved   = ( $wp_status === 'AUTHORIZED' && $response_code === 0 );
				$status        = $is_approved ? 'approved' : 'failed';
				if ( $booking_id > 0 ) {
					$payment_status = $is_approved ? 'paid' : 'rejected';
					$booking_status = $is_approved ? 'confirmed' : 'cancelled';
					\LiteCal\Core\Bookings::update_payment(
						$booking_id,
						array(
							'payment_status'    => $payment_status,
							'payment_provider'  => 'webpay',
							'payment_reference' => $token,
							'payment_error'     => '',
						)
					);
					\LiteCal\Core\Bookings::update_status( $booking_id, $booking_status );
					if ( $booking_status === 'confirmed' ) {
						\LiteCal\Rest\Rest::notify_booking_status( $booking_id, 'confirmed', true );
					} elseif ( $booking_status === 'cancelled' ) {
						\LiteCal\Rest\Rest::notify_booking_status( $booking_id, 'cancelled', true );
					}
				}
			}
			$event_slug    = '';
			$booking_token = '';
			if ( $booking_id > 0 ) {
				$booking = \LiteCal\Core\Bookings::get( $booking_id );
				if ( $booking ) {
					$event         = Events::get( (int) $booking->event_id );
					$event_slug    = $event ? $event->slug : '';
					$booking_token = \LiteCal\Rest\Rest::booking_access_token( $booking );
				}
			}
			$redirect = $event_slug ? home_url( '/' . trim( $event_slug, '/' ) . '/' ) : home_url( '/' );
			$redirect = add_query_arg(
				array(
					'agendalite_payment' => 'webpay',
					'booking_id'         => $booking_id,
					'booking_token'      => $booking_token,
					'status'             => $status,
				),
				$redirect
			);
			wp_safe_redirect( $redirect );
			exit;
		}

		if ( ! empty( $_POST['TBK_TOKEN'] ) ) {
				$booking_id = absint( wp_unslash( $_POST['TBK_ORDEN_COMPRA'] ?? 0 ) );
				$tbk_token  = sanitize_text_field( (string) wp_unslash( $_POST['TBK_TOKEN'] ?? '' ) );
			if ( $booking_id > 0 ) {
				$booking = \LiteCal\Core\Bookings::get( $booking_id );
				if (
					$booking
					&& (string) ( $booking->payment_provider ?? '' ) === 'webpay'
					&& $tbk_token !== ''
					&& hash_equals( (string) ( $booking->payment_reference ?? '' ), $tbk_token )
				) {
					\LiteCal\Core\Bookings::update_payment(
						$booking_id,
						array(
							'payment_status'    => 'rejected',
							'payment_provider'  => 'webpay',
							'payment_reference' => $tbk_token,
							'payment_error'     => 'Pago cancelado',
						)
					);
					\LiteCal\Core\Bookings::update_status( $booking_id, 'cancelled' );
					\LiteCal\Rest\Rest::notify_booking_status( $booking_id, 'cancelled', true );
				}
			}
			$event_slug    = '';
			$booking_token = '';
			if ( $booking_id > 0 ) {
				$booking = \LiteCal\Core\Bookings::get( $booking_id );
				if ( $booking ) {
					$event         = Events::get( (int) $booking->event_id );
					$event_slug    = $event ? $event->slug : '';
					$booking_token = \LiteCal\Rest\Rest::booking_access_token( $booking );
				}
			}
			$redirect = $event_slug ? home_url( '/' . trim( $event_slug, '/' ) . '/' ) : home_url( '/' );
			$redirect = add_query_arg(
				array(
					'agendalite_payment' => 'webpay',
					'booking_id'         => $booking_id,
					'booking_token'      => $booking_token,
					'status'             => 'cancelled',
				),
				$redirect
			);
			wp_safe_redirect( $redirect );
			exit;
		}

		if ( get_query_var( 'litecal_webpay' ) ) {
				$token = sanitize_text_field( wp_unslash( $_GET['token_ws'] ?? '' ) );
				$url   = sanitize_text_field( wp_unslash( $_GET['url'] ?? '' ) );
			$url       = $url ? rawurldecode( $url ) : '';
			if ( $token && $url && self::is_allowed_webpay_redirect_url( $url ) ) {
				echo '<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Redirigiendo</title></head><body>';
				echo '<form id="litecal-webpay-form" method="post" action="' . esc_url( $url ) . '">';
				echo '<input type="hidden" name="token_ws" value="' . esc_attr( $token ) . '">';
				echo '</form>';
				echo '<script>document.getElementById("litecal-webpay-form").submit();</script>';
				echo '</body></html>';
				exit;
			}
			if ( $token && $url ) {
				wp_die( 'URL de pago no permitida.', 'Solicitud inválida', array( 'response' => 400 ) );
			}
		}

		$receipt_view = get_query_var( 'litecal_receipt' );
		if ( $receipt_view || ! empty( $_GET['agendalite_receipt'] ) ) {
			$is_admin_receipt = ! empty( $_GET['agendalite_receipt_admin'] );
			if ( $is_admin_receipt ) {
				if ( ! is_user_logged_in() ) {
					auth_redirect();
				}
				if ( ! current_user_can( 'manage_options' ) && ! current_user_can( 'manage_agendalite' ) ) {
					wp_die( 'No autorizado.', '403', array( 'response' => 403 ) );
				}
					$booking_nonce        = sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ?? '' ) );
					$booking_id_for_nonce = absint( wp_unslash( $_GET['booking_id'] ?? 0 ) );
				if ( ! $booking_nonce || ! wp_verify_nonce( $booking_nonce, 'litecal_admin_receipt_' . $booking_id_for_nonce ) ) {
					wp_die( 'Token inválido.', '403', array( 'response' => 403 ) );
				}
			}
				$booking_id = absint( wp_unslash( $_GET['booking_id'] ?? 0 ) );
			if ( $booking_id > 0 ) {
				$booking = \LiteCal\Core\Bookings::get( $booking_id );
				if ( $booking ) {
					if ( ! $is_admin_receipt && ! current_user_can( 'manage_options' ) && ! current_user_can( 'manage_agendalite' ) ) {
							$booking_token = sanitize_text_field( (string) wp_unslash( $_GET['booking_token'] ?? '' ) );
						if ( ! \LiteCal\Rest\Rest::booking_token_is_valid( $booking, $booking_token ) ) {
							wp_die( 'No autorizado.', '403', array( 'response' => 403 ) );
						}
					}
					$snapshot                = \LiteCal\Core\Bookings::decode_snapshot( $booking );
					$event                   = new \stdClass();
					$event->id               = $snapshot['event']['id'] ?? (int) $booking->event_id;
					$event->title            = $snapshot['event']['title'] ?? 'Reserva';
					$event->slug             = $snapshot['event']['slug'] ?? '';
					$event->description      = $snapshot['event']['description'] ?? '';
					$event->duration         = $snapshot['event']['duration'] ?? 0;
					$event->location         = $snapshot['event']['location'] ?? 'presencial';
					$event->location_details = $snapshot['event']['location_details'] ?? '';
					$event->price            = $snapshot['event']['price'] ?? 0;
					$event->currency         = $snapshot['event']['currency'] ?? 'CLP';
					$event->options          = wp_json_encode( array() );
					$employee                = null;
					if ( ! empty( $snapshot['employee'] ) ) {
						$employee = (object) array(
							'id'         => $snapshot['employee']['id'] ?? 0,
							'name'       => $snapshot['employee']['name'] ?? '',
							'email'      => $snapshot['employee']['email'] ?? '',
							'avatar_url' => $snapshot['employee']['avatar_url'] ?? '',
							'title'      => $snapshot['employee']['title'] ?? '',
						);
					}
					$employees = $employee ? array( $employee ) : array();
					$template  = LITECAL_PATH . 'templates/receipt.php';
					if ( file_exists( $template ) ) {
						include $template;
						exit;
					}
				}
			}
		}

		$slug = get_query_var( 'litecal_event' );
		if ( ! $slug ) {
			$path = trim( wp_parse_url( sanitize_text_field( (string) wp_unslash( $_SERVER['REQUEST_URI'] ?? '' ) ), PHP_URL_PATH ), '/' );
			if ( $path ) {
				$event = Events::get_by_slug( $path );
			} else {
				$event = null;
			}
		} else {
			$event = Events::get_by_slug( $slug );
		}
		if ( ! $event ) {
			return;
		}
		global $wp_query;
		if ( is_object( $wp_query ) ) {
			$wp_query->is_404 = false;
			$wp_query->set_404( false );
		}
		status_header( 200 );
		add_filter(
			'body_class',
			function ( $classes ) {
				if ( ! is_array( $classes ) ) {
					return $classes;
				}
				return array_values( array_diff( $classes, array( 'error404' ) ) );
			},
			99
		);

		if ( class_exists( '\\LiteCal\\Admin\\Admin' ) ) {
			\LiteCal\Admin\Admin::refresh_all_event_statuses();
			$event = Events::get( $event->id );
		}
		$GLOBALS['litecal_current_event'] = $event;
		if ( ! empty( $_GET['agendalite_embed'] ) || ! empty( $_GET['litecal_embed'] ) ) {
			add_action(
				'wp_head',
				static function () use ( $event ) {
					self::render_event_seo_head( $event );
				},
				1
			);
			$template = LITECAL_PATH . 'templates/event-embed.php';
			if ( file_exists( $template ) ) {
					echo '<!doctype html><html ' . get_language_attributes() . '><head>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- get_language_attributes() returns safe HTML attributes.
				echo '<meta charset="' . esc_attr( get_bloginfo( 'charset' ) ) . '">';
				echo '<meta name="viewport" content="width=device-width, initial-scale=1">';
				wp_head();
				echo '</head><body class="litecal-embed">';
				if ( function_exists( 'wp_body_open' ) ) {
					wp_body_open();
				}
				include $template;
				wp_footer();
				echo '</body></html>';
				exit;
			}
		}

		add_filter(
			'body_class',
			function ( $classes ) {
				if ( ! is_array( $classes ) ) {
					$classes = array();
				}
				if ( ! in_array( 'litecal-page', $classes, true ) ) {
					$classes[] = 'litecal-page';
				}
				if ( did_action( 'elementor/loaded' ) ) {
					$classes[] = 'elementor-default';
					$classes[] = 'elementor-page';
					$kit_id    = self::elementor_active_kit_id();
					if ( $kit_id > 0 ) {
						$classes[] = 'elementor-kit-' . $kit_id;
					}
				}
				return array_values( array_unique( $classes ) );
			}
		);
		add_action(
			'wp_head',
			static function () use ( $event ) {
				self::render_event_seo_head( $event );
			},
			1
		);

		$template = LITECAL_PATH . 'templates/event-theme.php';
		if ( file_exists( $template ) ) {
			self::$event_template_active = true;
			return;
		}
	}

	private static function public_page_defaults() {
		return array(
			'public_show_banner'          => 1,
			'public_banner_image'         => '',
			'public_show_business_name'   => 1,
			'public_business_name'        => get_bloginfo( 'name' ),
			'public_show_slogan'          => 1,
			'public_business_slogan'      => __( 'Reserva online • Atención profesional', 'agenda-lite' ),
			'public_show_business_photo'  => 1,
			'public_business_photo'       => '',
			'public_show_description'     => 1,
			'public_business_description' => '',
			'public_show_address'         => 1,
			'public_business_address'     => '',
			'public_show_contact'         => 1,
			'public_business_contact'     => '',
			'public_show_hours'           => 1,
			'public_business_hours'       => '',
			'public_show_services'        => 1,
			'public_services_per_page'    => 6,
			'public_social_instagram_url' => '',
			'public_social_facebook_url'  => '',
			'public_social_tiktok_url'    => '',
			'public_social_youtube_url'   => '',
			'public_social_x_url'         => '',
			'public_social_linkedin_url'  => '',
			'public_social_whatsapp_url'  => '',
			'public_social_website_url'   => '',
		);
	}

	private static function normalize_public_url( $value ) {
		$value = trim( (string) $value );
		if ( $value === '' ) {
			return '';
		}
		if ( ! preg_match( '#^https?://#i', $value ) ) {
			$value = 'https://' . ltrim( $value, '/' );
		}
		return esc_url( $value );
	}

	private static function public_page_settings() {
		$settings = get_option( 'litecal_settings', array() );
		$defaults = self::public_page_defaults();
		$data     = array();
		foreach ( $defaults as $key => $default ) {
			if ( strpos( $key, 'public_show_' ) === 0 ) {
				$data[ $key ] = ! array_key_exists( $key, $settings ) ? (int) $default : ( ! empty( $settings[ $key ] ) ? 1 : 0 );
			} elseif ( $key === 'public_services_per_page' ) {
				$value        = (int) ( $settings[ $key ] ?? $default );
				$data[ $key ] = max( 1, min( 12, $value ) );
			} elseif ( strpos( $key, '_url' ) !== false ) {
				$data[ $key ] = self::normalize_public_url( (string) ( $settings[ $key ] ?? $default ) );
			} elseif ( strpos( $key, '_image' ) !== false || strpos( $key, '_photo' ) !== false ) {
				$data[ $key ] = esc_url( (string) ( $settings[ $key ] ?? $default ) );
			} elseif ( strpos( $key, '_description' ) !== false || strpos( $key, '_hours' ) !== false ) {
				$data[ $key ] = sanitize_textarea_field( (string) ( $settings[ $key ] ?? $default ) );
			} else {
				$data[ $key ] = sanitize_text_field( (string) ( $settings[ $key ] ?? $default ) );
			}
		}
		return $data;
	}

	private static function public_page_duration_label( $minutes ) {
		$minutes = max( 0, (int) $minutes );
		if ( $minutes <= 0 ) {
			return '';
		}
		if ( $minutes < 60 ) {
			/* translators: %d: duration in minutes. */
			return sprintf( _n( '%d min', '%d min', $minutes, 'agenda-lite' ), $minutes );
		}
		$hours = floor( $minutes / 60 );
		$rest  = $minutes % 60;
		if ( $rest <= 0 ) {
			/* translators: %d: duration in hours. */
			return sprintf( _n( '%d h', '%d h', $hours, 'agenda-lite' ), $hours );
		}
		return sprintf( '%d h %d min', $hours, $rest );
	}

	private static function public_page_price_label( $event ) {
		$settings          = get_option( 'litecal_settings', array() );
		$currency          = strtoupper( (string) ( $event->currency ?: ( $settings['currency'] ?? 'CLP' ) ) );
		$amount            = (float) ( $event->price ?? 0 );
		$event_options     = json_decode( (string) ( $event->options ?? '{}' ), true );
		$event_price_mode  = sanitize_key( (string) ( $event_options['price_mode'] ?? '' ) );
		$requires_payment  = ! empty( $event->require_payment ) || 'onsite' === $event_price_mode;
		if ( ! $requires_payment || $amount <= 0 ) {
			return __( 'Gratis', 'agenda-lite' );
		}
		$zero_decimals = array( 'CLP', 'JPY', 'HUF', 'TWD' );
		$decimals      = in_array( $currency, $zero_decimals, true ) ? 0 : 2;
		$formatted     = number_format( $amount, $decimals, ',', '.' );
		return '$' . $formatted . ' ' . $currency;
	}

	private static function public_page_sort_events( array $events ) {
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

	public static function public_page_shortcode( $atts = array() ) {
		$atts = shortcode_atts(
			array(
				'show_description' => 1,
				'show_title'       => 1,
				'show_count'       => 1,
				'show_search'      => 1,
				'show_sort'        => 1,
				'show_filters'     => 1,
				'show_powered_by'  => 1,
			),
			(array) $atts,
			'agendalite_public_page'
		);
		return self::render_public_page(
			array(
				'show_description' => self::shortcode_flag( $atts['show_description'], true ),
				'show_title'       => self::shortcode_flag( $atts['show_title'], true ),
				'show_count'       => self::shortcode_flag( $atts['show_count'], true ),
				'show_search'      => self::shortcode_flag( $atts['show_search'], true ),
				'show_sort'        => self::shortcode_flag( $atts['show_sort'], true ),
				'show_filters'     => self::shortcode_flag( $atts['show_filters'], true ),
				'show_powered_by'  => self::shortcode_flag( $atts['show_powered_by'], true ),
			)
		);
	}

	public static function render_public_page( array $args = array() ) {
		$GLOBALS['litecal_public_page_detected'] = true;
		if ( empty( $GLOBALS['litecal_public_page_canonical'] ) ) {
			$GLOBALS['litecal_public_page_canonical'] = self::current_request_canonical_url();
		}
		if ( empty( $GLOBALS['litecal_public_page_seo_payload'] ) || ! is_array( $GLOBALS['litecal_public_page_seo_payload'] ) ) {
			$GLOBALS['litecal_public_page_seo_payload'] = self::build_public_page_seo_payload( (string) $GLOBALS['litecal_public_page_canonical'] );
		}
		$public_css_ver = file_exists( LITECAL_PATH . 'assets/public/public.css' ) ? (string) filemtime( LITECAL_PATH . 'assets/public/public.css' ) : LITECAL_VERSION;
		$public_js_ver  = file_exists( LITECAL_PATH . 'assets/public/public.js' ) ? (string) filemtime( LITECAL_PATH . 'assets/public/public.js' ) : LITECAL_VERSION;
		wp_enqueue_style( 'litecal-public', LITECAL_URL . 'assets/public/public.css', array(), $public_css_ver );
		$remixicon_url = self::vendor_asset_url( 'remixicon/remixicon.css', '' );
		self::enqueue_style_if_available( 'litecal-remixicon', $remixicon_url, array(), '4.9.1' );
		wp_enqueue_script( 'litecal-public', LITECAL_URL . 'assets/public/public.js', array(), $public_js_ver, true );
		wp_script_add_data( 'litecal-public', 'defer', true );

		$settings = self::public_page_settings();
		$view     = array(
			'services_per_page' => max( 1, min( 12, (int) $settings['public_services_per_page'] ) ),
			'show_description'  => ! array_key_exists( 'show_description', $args ) || ! empty( $args['show_description'] ),
			'show_title'        => ! array_key_exists( 'show_title', $args ) || ! empty( $args['show_title'] ),
			'show_count'        => ! array_key_exists( 'show_count', $args ) || ! empty( $args['show_count'] ),
			'show_search'       => ! array_key_exists( 'show_search', $args ) || ! empty( $args['show_search'] ),
			'show_sort'         => ! array_key_exists( 'show_sort', $args ) || ! empty( $args['show_sort'] ),
			'show_filters'      => ! array_key_exists( 'show_filters', $args ) || ! empty( $args['show_filters'] ),
			'show_powered_by'   => ! array_key_exists( 'show_powered_by', $args ) || ! empty( $args['show_powered_by'] ),
		);

		$events    = array_values(
			array_filter(
				(array) Events::all(),
				static function ( $event ) {
					return is_object( $event ) && ( $event->status ?? 'draft' ) === 'active';
				}
			)
		);
		$events    = self::public_page_sort_events( $events );
		$employees = array_values(
			array_filter(
				(array) Employees::all_booking_managers( true ),
				static function ( $employee ) {
					return is_object( $employee ) && ( ( $employee->status ?? 'active' ) === 'active' );
				}
			)
		);

		ob_start();
		$template = LITECAL_PATH . 'templates/public-business.php';
		if ( file_exists( $template ) ) {
			include $template;
		}
		return ob_get_clean();
	}

	private static function enqueue_event_shortcode_assets( $event ) {
		$public_css_ver = file_exists( LITECAL_PATH . 'assets/public/public.css' ) ? (string) filemtime( LITECAL_PATH . 'assets/public/public.css' ) : LITECAL_VERSION;
		$public_js_ver  = file_exists( LITECAL_PATH . 'assets/public/public.js' ) ? (string) filemtime( LITECAL_PATH . 'assets/public/public.js' ) : LITECAL_VERSION;
		$deps           = array();

		wp_enqueue_style( 'litecal-public', LITECAL_URL . 'assets/public/public.css', array(), $public_css_ver );

		$remixicon_url = self::vendor_asset_url( 'remixicon/remixicon.css', '' );
		self::enqueue_style_if_available( 'litecal-remixicon', $remixicon_url, array(), '4.9.1' );

		$notyf_css_url = self::vendor_asset_url( 'notyf/notyf.min.css', '' );
		$notyf_js_url  = self::vendor_asset_url( 'notyf/notyf.min.js', '' );
		self::enqueue_style_if_available( 'litecal-notyf-public', $notyf_css_url, array(), '3.10.0' );
		if ( self::enqueue_script_if_available( 'litecal-notyf-public', $notyf_js_url, array(), '3.10.0', true ) ) {
			wp_script_add_data( 'litecal-notyf-public', 'defer', true );
			$deps[] = 'litecal-notyf-public';
		}

		$inter_url = self::vendor_asset_url( 'inter/inter.css', '' );
		self::enqueue_style_if_available( 'litecal-inter', $inter_url, array(), null );

		$intl_tel_css_url = self::vendor_asset_url( 'intl-tel-input/intlTelInput.css', '' );
		$intl_tel_js_url  = self::vendor_asset_url( 'intl-tel-input/intlTelInput.min.js', '' );
		self::enqueue_style_if_available( 'litecal-intl-tel', $intl_tel_css_url, array(), '17.0.12' );
		self::add_intl_tel_flag_inline_css();
		if ( self::enqueue_script_if_available( 'litecal-intl-tel', $intl_tel_js_url, array(), '17.0.12', true ) ) {
			wp_script_add_data( 'litecal-intl-tel', 'defer', true );
			$deps[] = 'litecal-intl-tel';
		}

		if ( self::event_has_file_fields( $event ) ) {
			$filepond_css         = self::vendor_asset_url( 'filepond/filepond.min.css', '' );
			$filepond_js          = self::vendor_asset_url( 'filepond/filepond.min.js', '' );
			$filepond_preview_css = self::vendor_asset_url( 'filepond/filepond-plugin-image-preview.min.css', '' );
			$filepond_preview_js  = self::vendor_asset_url( 'filepond/filepond-plugin-image-preview.min.js', '' );

			$filepond_style_loaded = self::enqueue_style_if_available( 'litecal-filepond-public', $filepond_css, array(), '4.31.4' );
			self::enqueue_style_if_available( 'litecal-filepond-image-preview-public', $filepond_preview_css, $filepond_style_loaded ? array( 'litecal-filepond-public' ) : array(), '4.6.12' );

			$filepond_script_loaded = self::enqueue_script_if_available( 'litecal-filepond-public', $filepond_js, array(), '4.31.4', true );
			if ( self::enqueue_script_if_available( 'litecal-filepond-image-preview-public', $filepond_preview_js, $filepond_script_loaded ? array( 'litecal-filepond-public' ) : array(), '4.6.12', true ) ) {
				wp_script_add_data( 'litecal-filepond-image-preview-public', 'defer', true );
				$deps[] = 'litecal-filepond-image-preview-public';
			} elseif ( $filepond_script_loaded ) {
				$deps[] = 'litecal-filepond-public';
			}
			if ( $filepond_script_loaded ) {
				wp_script_add_data( 'litecal-filepond-public', 'defer', true );
			}
		}

		wp_enqueue_script( 'litecal-public', LITECAL_URL . 'assets/public/public.js', $deps, $public_js_ver, true );
		wp_script_add_data( 'litecal-public', 'defer', true );

		$integrations      = get_option( 'litecal_integrations', array() );
		$recaptcha_enabled = ! empty( $integrations['recaptcha_enabled'] ) && ! empty( $integrations['recaptcha_site_key'] );
		if ( $recaptcha_enabled ) {
			wp_enqueue_script(
				'litecal-recaptcha',
				'https://www.google.com/recaptcha/api.js?render=' . rawurlencode( (string) $integrations['recaptcha_site_key'] ),
				array(),
					LITECAL_VERSION,
				true
			);
		}

		wp_localize_script(
			'litecal-public',
			'litecal',
			array(
				'restUrl'   => esc_url_raw( rest_url( 'litecal/v1' ) ),
				'nonce'     => wp_create_nonce( 'wp_rest' ),
				'logos'     => array(
					'googleMeet' => esc_url_raw( LITECAL_URL . 'assets/logos/googlemeet.svg' ),
					'zoom'       => esc_url_raw( LITECAL_URL . 'assets/logos/zoom.svg' ),
					'teams'      => esc_url_raw( LITECAL_URL . 'assets/logos/teams.svg' ),
				),
				'recaptcha' => array(
					'enabled' => $recaptcha_enabled ? 1 : 0,
					'siteKey' => $recaptcha_enabled ? (string) $integrations['recaptcha_site_key'] : '',
				),
			)
		);
	}

	public static function shortcode( $atts ) {
		$atts = shortcode_atts(
			array(
				'slug'             => '',
				'show_timezone'    => 1,
				'show_time_format' => 1,
				'show_description' => 1,
				'show_powered_by'  => 1,
			),
			(array) $atts,
			'agendalite_event'
		);
		return self::render_event_embed_by_slug(
			(string) $atts['slug'],
			array(
				'show_timezone'    => self::shortcode_flag( $atts['show_timezone'], true ),
				'show_time_format' => self::shortcode_flag( $atts['show_time_format'], true ),
				'show_description' => self::shortcode_flag( $atts['show_description'], true ),
				'show_powered_by'  => self::shortcode_flag( $atts['show_powered_by'], true ),
			)
		);
	}

	public static function shortcode_flag( $value, $default = true ) {
		if ( is_bool( $value ) ) {
			return $value;
		}

		if ( is_numeric( $value ) ) {
			return (int) $value !== 0;
		}

		$normalized = strtolower( trim( (string) $value ) );
		if ( $normalized === '' ) {
			return $default;
		}

		if ( in_array( $normalized, array( '0', 'false', 'no', 'off' ), true ) ) {
			return false;
		}

		if ( in_array( $normalized, array( '1', 'true', 'yes', 'on' ), true ) ) {
			return true;
		}

		return $default;
	}

	public static function render_event_embed_by_slug( $slug, array $args = array() ) {
		$event = Events::get_by_slug( sanitize_title( (string) $slug ) );
		if ( ! $event ) {
			return '<div class="lc-empty-state-wrap"><div class="lc-empty-state lc-empty-state--event-not-found"><div>Servicio no encontrado</div></div></div>';
		}
		return self::render_event_embed( $event, $args );
	}

	public static function render_event_embed( $event, array $args = array() ) {
		if ( ! is_object( $event ) || empty( $event->id ) ) {
			return '<div class="lc-empty-state-wrap"><div class="lc-empty-state lc-empty-state--event-not-found"><div>Servicio no encontrado</div></div></div>';
		}
		$GLOBALS['litecal_current_event'] = $event;
		$GLOBALS['litecal_event_embed_shortcode'] = true;
		self::enqueue_event_shortcode_assets( $event );
		$litecal_render_options = array(
			'show_timezone'    => ! array_key_exists( 'show_timezone', $args ) || ! empty( $args['show_timezone'] ),
			'show_time_format' => ! array_key_exists( 'show_time_format', $args ) || ! empty( $args['show_time_format'] ),
			'show_description' => ! array_key_exists( 'show_description', $args ) || ! empty( $args['show_description'] ),
			'show_powered_by'  => ! array_key_exists( 'show_powered_by', $args ) || ! empty( $args['show_powered_by'] ),
		);
		ob_start();
		(
			static function () use ( $event, $litecal_render_options ) {
				include LITECAL_PATH . 'templates/event-embed.php';
			}
		)();
		unset( $GLOBALS['litecal_event_embed_shortcode'] );
		return ob_get_clean();
	}
}
