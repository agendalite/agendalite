<?php
/**
 * Builder integrations for Gutenberg, Elementor, and WPBakery.
 *
 * @package LiteCal
 */

namespace LiteCal\Public;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use LiteCal\Core\Events;

class BuilderIntegration {

	public static function init() {
		add_action( 'init', array( self::class, 'register_blocks' ) );
		add_action( 'init', array( self::class, 'register_wpbakery_shortcodes' ) );
		add_action( 'enqueue_block_editor_assets', array( self::class, 'enqueue_block_editor_assets' ) );
		add_action( 'elementor/editor/after_enqueue_styles', array( self::class, 'enqueue_elementor_editor_assets' ) );
		add_action( 'elementor/preview/enqueue_styles', array( self::class, 'enqueue_elementor_editor_assets' ) );
		add_action( 'vc_before_init', array( self::class, 'register_wpbakery_elements' ) );
		add_action( 'vc_backend_editor_enqueue_js_css', array( self::class, 'enqueue_wpbakery_editor_assets' ) );
		add_action( 'vc_frontend_editor_enqueue_js_css', array( self::class, 'enqueue_wpbakery_editor_assets' ) );
		add_filter( 'block_categories_all', array( self::class, 'register_block_category' ), 10, 2 );
		add_action( 'elementor/elements/categories_registered', array( self::class, 'register_elementor_category' ) );
		add_action( 'elementor/widgets/register', array( self::class, 'register_elementor_widgets' ) );
	}

	public static function builder_icon_url() {
		return LITECAL_URL . 'assets/editor/agendalite-builder-icon.svg';
	}

	public static function builder_widgets_image_url() {
		return LITECAL_URL . 'assets/editor/ilustraciones/wg.webp';
	}

	public static function register_blocks() {
		if ( ! function_exists( 'register_block_type' ) ) {
			return;
		}

		self::register_editor_style();

		wp_register_script(
			'litecal-builder-blocks',
			LITECAL_URL . 'assets/editor/blocks.js',
			array( 'wp-blocks', 'wp-element', 'wp-components', 'wp-block-editor', 'wp-i18n' ),
			file_exists( LITECAL_PATH . 'assets/editor/blocks.js' ) ? (string) filemtime( LITECAL_PATH . 'assets/editor/blocks.js' ) : LITECAL_VERSION,
			true
		);

		register_block_type(
			'agendalite/public-page',
			array(
				'api_version'     => 2,
				'editor_script'   => 'litecal-builder-blocks',
				'editor_style'    => 'litecal-builder-editor',
				'render_callback' => array( self::class, 'render_public_page_block' ),
				'attributes'      => array(
					'showDescription' => array(
						'type'    => 'boolean',
						'default' => true,
					),
					'showTitle'       => array(
						'type'    => 'boolean',
						'default' => true,
					),
					'showCount'       => array(
						'type'    => 'boolean',
						'default' => true,
					),
					'showSearch'      => array(
						'type'    => 'boolean',
						'default' => true,
					),
					'showSort'        => array(
						'type'    => 'boolean',
						'default' => true,
					),
					'showFilters'     => array(
						'type'    => 'boolean',
						'default' => true,
					),
					'showPoweredBy'   => array(
						'type'    => 'boolean',
						'default' => true,
					),
				),
			)
		);

		register_block_type(
			'agendalite/service-booking',
			array(
				'api_version'     => 2,
				'editor_script'   => 'litecal-builder-blocks',
				'editor_style'    => 'litecal-builder-editor',
				'render_callback' => array( self::class, 'render_service_block' ),
				'attributes'      => array(
					'slug'            => array(
						'type'    => 'string',
						'default' => '',
					),
					'showTimezone'    => array(
						'type'    => 'boolean',
						'default' => true,
					),
					'showTimeFormat'  => array(
						'type'    => 'boolean',
						'default' => true,
					),
					'showDescription' => array(
						'type'    => 'boolean',
						'default' => true,
					),
					'showPoweredBy'   => array(
						'type'    => 'boolean',
						'default' => true,
					),
				),
			)
		);
	}

	public static function enqueue_block_editor_assets() {
		self::register_editor_style();
		wp_enqueue_style( 'litecal-builder-editor' );
		wp_enqueue_script( 'litecal-builder-blocks' );
		wp_add_inline_script(
			'litecal-builder-blocks',
			'window.litecalBuilderBlocks = window.litecalBuilderBlocks || {}; window.litecalBuilderBlocks.services = ' . wp_json_encode( self::service_options() ) . '; window.litecalBuilderBlocks.iconUrl = ' . wp_json_encode( self::builder_icon_url() ) . '; window.litecalBuilderBlocks.widgetsImageUrl = ' . wp_json_encode( self::builder_widgets_image_url() ) . ';',
			'before'
		);
	}

	public static function enqueue_elementor_editor_assets() {
		self::register_editor_style();
		wp_enqueue_style( 'litecal-builder-editor' );
	}

	public static function enqueue_wpbakery_editor_assets() {
		self::register_editor_style();
		wp_enqueue_style( 'litecal-builder-editor' );
	}

	private static function register_editor_style() {
		if ( wp_style_is( 'litecal-builder-editor', 'registered' ) ) {
			return;
		}

		wp_register_style(
			'litecal-builder-editor',
			LITECAL_URL . 'assets/editor/blocks.css',
			array(),
			file_exists( LITECAL_PATH . 'assets/editor/blocks.css' ) ? (string) filemtime( LITECAL_PATH . 'assets/editor/blocks.css' ) : LITECAL_VERSION
		);
	}

	public static function register_block_category( $categories, $post ) {
		if ( ! is_array( $categories ) ) {
			$categories = array();
		}

		array_unshift(
			$categories,
			array(
				'slug'  => 'agendalite',
				'title' => __( 'Agenda Lite', 'agenda-lite' ),
				'icon'  => null,
			)
		);

		return $categories;
	}

	public static function render_public_page_block( $attributes ) {
		return self::wrap_public_page_markup(
			array(
				'show_description' => ! empty( $attributes['showDescription'] ),
				'show_title'       => ! empty( $attributes['showTitle'] ),
				'show_count'       => ! empty( $attributes['showCount'] ),
				'show_search'      => ! empty( $attributes['showSearch'] ),
				'show_sort'        => ! empty( $attributes['showSort'] ),
				'show_filters'     => ! empty( $attributes['showFilters'] ),
				'show_powered_by'  => ! empty( $attributes['showPoweredBy'] ),
			),
			self::is_builder_editor_request()
		);
	}

	public static function render_service_block( $attributes ) {
		return self::wrap_service_booking_markup(
			array(
				'slug'             => sanitize_title( (string) ( $attributes['slug'] ?? '' ) ),
				'show_timezone'    => ! empty( $attributes['showTimezone'] ),
				'show_time_format' => ! empty( $attributes['showTimeFormat'] ),
				'show_description' => ! empty( $attributes['showDescription'] ),
				'show_powered_by'  => ! empty( $attributes['showPoweredBy'] ),
			),
			self::is_builder_editor_request()
		);
	}

	public static function register_wpbakery_shortcodes() {
		add_shortcode( 'agendalite_public_page_builder', array( self::class, 'render_wpbakery_public_page_shortcode' ) );
		add_shortcode( 'agendalite_service_booking_builder', array( self::class, 'render_wpbakery_service_shortcode' ) );
	}

	public static function register_wpbakery_elements() {
		if ( ! function_exists( 'vc_map' ) ) {
			return;
		}

		vc_map(
			array(
				'name'        => __( 'Página de servicios', 'agenda-lite' ),
				'base'        => 'agendalite_public_page_builder',
				'icon'        => self::builder_icon_url(),
				'category'    => __( 'Agenda Lite', 'agenda-lite' ),
				'description' => __( 'Listado público de servicios con opciones simples.', 'agenda-lite' ),
				'params'      => self::wpbakery_public_page_params(),
			)
		);

		vc_map(
			array(
				'name'        => __( 'Servicios individuales', 'agenda-lite' ),
				'base'        => 'agendalite_service_booking_builder',
				'icon'        => self::builder_icon_url(),
				'category'    => __( 'Agenda Lite', 'agenda-lite' ),
				'description' => __( 'Reserva de un servicio específico con opciones simples.', 'agenda-lite' ),
				'params'      => self::wpbakery_service_booking_params(),
			)
		);
	}

	public static function render_wpbakery_public_page_shortcode( $atts = array() ) {
		$atts = shortcode_atts(
			array(
				'show_description' => '1',
				'show_title'       => '1',
				'show_count'       => '1',
				'show_search'      => '1',
				'show_sort'        => '1',
				'show_filters'     => '1',
				'show_powered_by'  => '1',
			),
			(array) $atts,
			'agendalite_public_page_builder'
		);

		return self::wrap_public_page_markup(
			array(
				'show_description' => PublicSite::shortcode_flag( $atts['show_description'], true ),
				'show_title'       => PublicSite::shortcode_flag( $atts['show_title'], true ),
				'show_count'       => PublicSite::shortcode_flag( $atts['show_count'], true ),
				'show_search'      => PublicSite::shortcode_flag( $atts['show_search'], true ),
				'show_sort'        => PublicSite::shortcode_flag( $atts['show_sort'], true ),
				'show_filters'     => PublicSite::shortcode_flag( $atts['show_filters'], true ),
				'show_powered_by'  => PublicSite::shortcode_flag( $atts['show_powered_by'], true ),
			),
			self::is_builder_editor_request()
		);
	}

	public static function render_wpbakery_service_shortcode( $atts = array() ) {
		$atts = shortcode_atts(
			array(
				'slug'             => '',
				'show_timezone'    => '1',
				'show_time_format' => '1',
				'show_description' => '1',
				'show_powered_by'  => '1',
			),
			(array) $atts,
			'agendalite_service_booking_builder'
		);

		return self::wrap_service_booking_markup(
			array(
				'slug'             => sanitize_title( (string) $atts['slug'] ),
				'show_timezone'    => PublicSite::shortcode_flag( $atts['show_timezone'], true ),
				'show_time_format' => PublicSite::shortcode_flag( $atts['show_time_format'], true ),
				'show_description' => PublicSite::shortcode_flag( $atts['show_description'], true ),
				'show_powered_by'  => PublicSite::shortcode_flag( $atts['show_powered_by'], true ),
			),
			self::is_builder_editor_request()
		);
	}

	private static function wpbakery_public_page_params() {
		return array(
			self::wpbakery_toggle_param( 'show_description', __( 'Mostrar descripción', 'agenda-lite' ) ),
			self::wpbakery_toggle_param( 'show_title', __( 'Mostrar título', 'agenda-lite' ) ),
			self::wpbakery_toggle_param( 'show_count', __( 'Mostrar cantidad', 'agenda-lite' ) ),
			self::wpbakery_toggle_param( 'show_search', __( 'Mostrar buscador', 'agenda-lite' ) ),
			self::wpbakery_toggle_param( 'show_sort', __( 'Mostrar orden', 'agenda-lite' ) ),
			self::wpbakery_toggle_param( 'show_filters', __( 'Mostrar etiquetas de filtro', 'agenda-lite' ) ),
			self::wpbakery_toggle_param( 'show_powered_by', __( 'Mostrar "Desarrollado por"', 'agenda-lite' ) ),
		);
	}

	private static function wpbakery_service_booking_params() {
		$service_values = array( __( 'Selecciona un servicio', 'agenda-lite' ) => '' );
		foreach ( self::service_options() as $service ) {
			$slug = (string) ( $service['slug'] ?? '' );
			if ( $slug === '' ) {
				continue;
			}

			$service_values[ (string) ( $service['title'] ?? $slug ) ] = $slug;
		}

		return array(
			array(
				'type'        => 'dropdown',
				'heading'     => __( 'Servicio', 'agenda-lite' ),
				'param_name'  => 'slug',
				'value'       => $service_values,
				'admin_label' => true,
			),
			self::wpbakery_toggle_param( 'show_timezone', __( 'Mostrar zona horaria', 'agenda-lite' ) ),
			self::wpbakery_toggle_param( 'show_time_format', __( 'Mostrar formato de hora', 'agenda-lite' ) ),
			self::wpbakery_toggle_param( 'show_description', __( 'Mostrar descripción', 'agenda-lite' ) ),
			self::wpbakery_toggle_param( 'show_powered_by', __( 'Mostrar "Desarrollado por"', 'agenda-lite' ) ),
		);
	}

	private static function wpbakery_toggle_param( $param_name, $heading ) {
		return array(
			'type'       => 'dropdown',
			'heading'    => $heading,
			'param_name' => $param_name,
			'value'      => array(
				__( 'Sí', 'agenda-lite' ) => '1',
				__( 'No', 'agenda-lite' ) => '0',
			),
			'std'        => '1',
		);
	}

	private static function public_page_placeholder_labels() {
		return array(
			'show_description' => __( 'Mostrar descripción', 'agenda-lite' ),
			'show_title'       => __( 'Mostrar título', 'agenda-lite' ),
			'show_count'       => __( 'Mostrar cantidad', 'agenda-lite' ),
			'show_search'      => __( 'Mostrar buscador', 'agenda-lite' ),
			'show_sort'        => __( 'Mostrar orden', 'agenda-lite' ),
			'show_filters'     => __( 'Mostrar etiquetas de filtro', 'agenda-lite' ),
			'show_powered_by'  => __( 'Mostrar "Desarrollado por"', 'agenda-lite' ),
		);
	}

	private static function service_booking_placeholder_labels() {
		return array(
			'show_timezone'    => __( 'Mostrar zona horaria', 'agenda-lite' ),
			'show_time_format' => __( 'Mostrar formato de hora', 'agenda-lite' ),
			'show_description' => __( 'Mostrar descripción', 'agenda-lite' ),
			'show_powered_by'  => __( 'Mostrar "Desarrollado por"', 'agenda-lite' ),
		);
	}

	public static function service_options() {
		$services = array_values(
			array_filter(
				(array) Events::all(),
				static function ( $event ) {
					return is_object( $event ) && ( $event->status ?? 'draft' ) === 'active';
				}
			)
		);
		$options  = array();

		foreach ( $services as $service ) {
			$options[] = array(
				'slug'  => (string) ( $service->slug ?? '' ),
				'title' => (string) ( $service->title ?? '' ),
			);
		}

		return $options;
	}

	public static function register_elementor_category( $elements_manager ) {
		if ( ! is_object( $elements_manager ) || ! method_exists( $elements_manager, 'add_category' ) ) {
			return;
		}

		$elements_manager->add_category(
			'agendalite',
			array(
				'title' => __( 'Agenda Lite', 'agenda-lite' ),
				'icon'  => 'litecal-elementor-icon',
			)
		);
	}

	public static function register_elementor_widgets( $widgets_manager ) {
		if ( ! did_action( 'elementor/loaded' ) || ! is_object( $widgets_manager ) ) {
			return;
		}

		$public_widget  = '\\LiteCal\\Public\\Elementor\\PublicPageWidget';
		$service_widget = '\\LiteCal\\Public\\Elementor\\ServiceBookingWidget';
		$register       = method_exists( $widgets_manager, 'register' ) ? 'register' : ( method_exists( $widgets_manager, 'register_widget_type' ) ? 'register_widget_type' : '' );

		if ( $register === '' ) {
			return;
		}

		if ( class_exists( $public_widget ) ) {
			$widgets_manager->{$register}( new $public_widget() );
		}

		if ( class_exists( $service_widget ) ) {
			$widgets_manager->{$register}( new $service_widget() );
		}
	}

	public static function wrap_service_booking_markup( array $args, $is_editor = true ) {
		if ( $is_editor ) {
			return self::builder_placeholder_markup(
				__( 'Servicios individuales', 'agenda-lite' ),
				self::service_booking_placeholder_labels(),
				$args
			);
		}

		$slug = sanitize_title( (string) ( $args['slug'] ?? '' ) );
		if ( $slug === '' ) {
			return '<div class="lc-empty-state-wrap"><div class="lc-empty-state lc-empty-state--event-not-found"><div>' . esc_html__( 'Selecciona un servicio en el widget.', 'agenda-lite' ) . '</div></div></div>';
		}

		return self::wrap_frontend_markup(
			'service-booking',
			PublicSite::render_event_embed_by_slug( $slug, $args )
		);
	}

	public static function wrap_public_page_markup( array $args, $is_editor = true ) {
		if ( $is_editor ) {
			return self::builder_placeholder_markup(
				__( 'Página de servicios', 'agenda-lite' ),
				self::public_page_placeholder_labels(),
				$args
			);
		}

		return self::wrap_frontend_markup(
			'public-page',
			PublicSite::render_public_page( $args )
		);
	}

	private static function wrap_frontend_markup( $type, $content ) {
		$type = sanitize_html_class( (string) $type );
		return '<div class="litecal-builder-frontend litecal-builder-frontend--' . esc_attr( $type ) . '">' . $content . '</div>';
	}

	public static function builder_placeholder_markup( $title, array $labels, array $states ) {
		$items = '';

		foreach ( $labels as $key => $label ) {
			$enabled = ! empty( $states[ $key ] );
			$items  .= sprintf(
				'<li class="litecal-builder-placeholder__item"><span class="litecal-builder-placeholder__dot %s"></span><span class="litecal-builder-placeholder__text">%s</span><span class="litecal-builder-placeholder__state">%s</span></li>',
				$enabled ? 'is-on' : 'is-off',
				esc_html( $label ),
				esc_html( $enabled ? __( 'Activo', 'agenda-lite' ) : __( 'Oculto', 'agenda-lite' ) )
			);
		}

		return sprintf(
			'%s<div class="litecal-builder-placeholder" aria-hidden="true"><div class="litecal-builder-placeholder__media"><img class="litecal-builder-placeholder__image" src="%s" alt="%s"></div><h3 class="litecal-builder-placeholder__title">%s</h3><p class="litecal-builder-placeholder__message">%s</p><ul class="litecal-builder-placeholder__list">%s</ul></div>',
			self::builder_placeholder_inline_css(),
			esc_url( self::builder_widgets_image_url() ),
			esc_attr__( 'Ilustración de widgets', 'agenda-lite' ),
			esc_html( $title ),
			esc_html__( 'Este widget muestra su contenido real en el frontend. Aquí solo se configuran sus opciones de visualización.', 'agenda-lite' ),
			$items
		);
	}

	private static function builder_placeholder_inline_css() {
		return '<style>.litecal-builder-placeholder{background:#fff;border:1px solid #e2e8f0;border-radius:20px;padding:24px;color:#0f172a;box-shadow:none}.litecal-builder-placeholder__title{margin:0 0 8px;color:#0f172a;font-size:26px;line-height:1.15;font-weight:700;text-align:center}.litecal-builder-placeholder__message{margin:0 0 18px;color:#475569;font-size:14px;line-height:1.6;text-align:center}.litecal-builder-placeholder__media{display:flex;justify-content:center;align-items:center;margin:0 0 18px;padding:8px 0 2px}.litecal-builder-placeholder__image{display:block;width:min(100%,340px);height:auto;max-width:100%;object-fit:contain}.litecal-builder-placeholder__list{margin:0;padding:0;list-style:none;display:grid;gap:10px}.litecal-builder-placeholder__item{display:grid;grid-template-columns:10px 1fr auto;align-items:center;gap:12px;min-height:44px;padding:0 14px;border:1px solid #e2e8f0;border-radius:14px;background:#fff}.litecal-builder-placeholder__dot{width:10px;height:10px;border-radius:999px;background:#94a3b8}.litecal-builder-placeholder__dot.is-on{background:#00d277}.litecal-builder-placeholder__dot.is-off{background:#cbd5e1}.litecal-builder-placeholder__text{color:#0f172a;font-size:14px;font-weight:600}.litecal-builder-placeholder__state{color:#64748b;font-size:12px;font-weight:600;letter-spacing:.02em}@media (max-width:640px){.litecal-builder-placeholder{padding:18px}.litecal-builder-placeholder__title{font-size:22px}.litecal-builder-placeholder__item{grid-template-columns:10px 1fr;grid-template-areas:"dot text" ". state";align-items:start;padding:12px 14px}.litecal-builder-placeholder__item .litecal-builder-placeholder__dot{grid-area:dot;margin-top:5px}.litecal-builder-placeholder__item .litecal-builder-placeholder__text{grid-area:text}.litecal-builder-placeholder__item .litecal-builder-placeholder__state{grid-area:state}}</style>';
	}

	private static function is_wpbakery_editor_request() {
		if ( function_exists( 'vc_is_inline' ) && vc_is_inline() ) {
			return true;
		}

		$editable = sanitize_text_field( wp_unslash( $_REQUEST['vc_editable'] ?? '' ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only builder context detection.
		if ( in_array( strtolower( (string) $editable ), array( '1', 'true', 'yes' ), true ) ) {
			return true;
		}

		$action = sanitize_key( (string) ( $_REQUEST['action'] ?? '' ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only builder context detection.
		return strpos( $action, 'vc_' ) === 0;
	}

	private static function is_builder_editor_request() {
		return is_admin() || wp_doing_ajax() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) || self::is_wpbakery_editor_request();
	}
}
