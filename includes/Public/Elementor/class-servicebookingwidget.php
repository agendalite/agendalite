<?php
/**
 * Elementor widget for Agenda Lite service booking.
 *
 * @package LiteCal
 */

namespace LiteCal\Public\Elementor;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Elementor\Controls_Manager;
use Elementor\Widget_Base;
use LiteCal\Public\BuilderIntegration;
class ServiceBookingWidget extends Widget_Base {

	public function get_name() {
		return 'agendalite_service_booking';
	}

	public function get_title() {
		return __( 'Servicios individuales', 'agenda-lite' );
	}

	public function get_icon() {
		return 'litecal-elementor-icon';
	}

	public function get_categories() {
		return array( 'agendalite' );
	}

	protected function register_controls() {
		$service_options = array();
		foreach ( BuilderIntegration::service_options() as $service ) {
			$slug = (string) ( $service['slug'] ?? '' );
			if ( $slug === '' ) {
				continue;
			}
			$service_options[ $slug ] = (string) ( $service['title'] ?? $slug );
		}

		$this->start_controls_section(
			'section_content',
			array(
				'label' => __( 'Contenido', 'agenda-lite' ),
			)
		);

		$this->add_control(
			'slug',
			array(
				'label'       => __( 'Servicio', 'agenda-lite' ),
				'type'        => Controls_Manager::SELECT2,
				'options'     => $service_options,
				'label_block' => true,
			)
		);

		$this->add_control(
			'show_timezone',
			array(
				'label'        => __( 'Mostrar zona horaria', 'agenda-lite' ),
				'type'         => Controls_Manager::SWITCHER,
				'label_on'     => __( 'Sí', 'agenda-lite' ),
				'label_off'    => __( 'No', 'agenda-lite' ),
				'return_value' => 'yes',
				'default'      => 'yes',
			)
		);

		$this->add_control(
			'show_time_format',
			array(
				'label'        => __( 'Mostrar formato de hora', 'agenda-lite' ),
				'type'         => Controls_Manager::SWITCHER,
				'label_on'     => __( 'Sí', 'agenda-lite' ),
				'label_off'    => __( 'No', 'agenda-lite' ),
				'return_value' => 'yes',
				'default'      => 'yes',
			)
		);

		$this->add_control(
			'show_description',
			array(
				'label'        => __( 'Mostrar descripción', 'agenda-lite' ),
				'type'         => Controls_Manager::SWITCHER,
				'label_on'     => __( 'Sí', 'agenda-lite' ),
				'label_off'    => __( 'No', 'agenda-lite' ),
				'return_value' => 'yes',
				'default'      => 'yes',
			)
		);

		$this->add_control(
			'show_powered_by',
			array(
				'label'        => __( 'Mostrar "Desarrollado por"', 'agenda-lite' ),
				'type'         => Controls_Manager::SWITCHER,
				'label_on'     => __( 'Sí', 'agenda-lite' ),
				'label_off'    => __( 'No', 'agenda-lite' ),
				'return_value' => 'yes',
				'default'      => 'yes',
			)
		);

		$this->end_controls_section();
	}

	protected function render() {
		$settings = $this->get_settings_for_display();
		$slug     = sanitize_title( (string) ( $settings['slug'] ?? '' ) );
		if ( $slug === '' && ! $this->is_edit_mode() ) {
			echo '<div class="lc-empty-state-wrap"><div class="lc-empty-state lc-empty-state--event-not-found"><div>' . esc_html__( 'Selecciona un servicio en el widget.', 'agenda-lite' ) . '</div></div></div>';
			return;
		}
		$args = array(
			'slug'             => $slug,
			'show_timezone'    => ( 'yes' === (string) ( $settings['show_timezone'] ?? 'yes' ) ),
			'show_time_format' => ( 'yes' === (string) ( $settings['show_time_format'] ?? 'yes' ) ),
			'show_description' => ( 'yes' === (string) ( $settings['show_description'] ?? 'yes' ) ),
			'show_powered_by'  => ( 'yes' === (string) ( $settings['show_powered_by'] ?? 'yes' ) ),
		);
		echo BuilderIntegration::wrap_service_booking_markup( $args, $this->is_edit_mode() ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Builder wrapper returns placeholder in editor and frontend markup otherwise.
	}

	private function is_edit_mode() {
		if ( ! class_exists( '\Elementor\Plugin' ) ) {
			return false;
		}
		$plugin = \Elementor\Plugin::$instance ?? null;
		if ( ! $plugin || empty( $plugin->editor ) || ! method_exists( $plugin->editor, 'is_edit_mode' ) ) {
			return false;
		}
		return (bool) $plugin->editor->is_edit_mode();
	}
}
