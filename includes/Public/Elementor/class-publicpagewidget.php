<?php
/**
 * Elementor widget for Agenda Lite public page.
 *
 * @package LiteCal
 */

namespace LiteCal\Public\Elementor;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Elementor\Controls_Manager;
use Elementor\Widget_Base;
class PublicPageWidget extends Widget_Base {

	public function get_name() {
		return 'agendalite_public_page';
	}

	public function get_title() {
		return __( 'Página de servicios', 'agenda-lite' );
	}

	public function get_icon() {
		return 'litecal-elementor-icon';
	}

	public function get_categories() {
		return array( 'agendalite' );
	}

	protected function register_controls() {
		$this->start_controls_section(
			'section_content',
			array(
				'label' => __( 'Contenido', 'agenda-lite' ),
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
			'show_title',
			array(
				'label'        => __( 'Mostrar título', 'agenda-lite' ),
				'type'         => Controls_Manager::SWITCHER,
				'label_on'     => __( 'Sí', 'agenda-lite' ),
				'label_off'    => __( 'No', 'agenda-lite' ),
				'return_value' => 'yes',
				'default'      => 'yes',
			)
		);

		$this->add_control(
			'show_count',
			array(
				'label'        => __( 'Mostrar cantidad', 'agenda-lite' ),
				'type'         => Controls_Manager::SWITCHER,
				'label_on'     => __( 'Sí', 'agenda-lite' ),
				'label_off'    => __( 'No', 'agenda-lite' ),
				'return_value' => 'yes',
				'default'      => 'yes',
			)
		);

		$this->add_control(
			'show_search',
			array(
				'label'        => __( 'Mostrar buscador', 'agenda-lite' ),
				'type'         => Controls_Manager::SWITCHER,
				'label_on'     => __( 'Sí', 'agenda-lite' ),
				'label_off'    => __( 'No', 'agenda-lite' ),
				'return_value' => 'yes',
				'default'      => 'yes',
			)
		);

		$this->add_control(
			'show_sort',
			array(
				'label'        => __( 'Mostrar orden', 'agenda-lite' ),
				'type'         => Controls_Manager::SWITCHER,
				'label_on'     => __( 'Sí', 'agenda-lite' ),
				'label_off'    => __( 'No', 'agenda-lite' ),
				'return_value' => 'yes',
				'default'      => 'yes',
			)
		);

		$this->add_control(
			'show_filters',
			array(
				'label'        => __( 'Mostrar etiquetas de filtro', 'agenda-lite' ),
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
		$args = array(
			'show_description' => ( 'yes' === (string) ( $settings['show_description'] ?? 'yes' ) ),
			'show_title'       => ( 'yes' === (string) ( $settings['show_title'] ?? 'yes' ) ),
			'show_count'       => ( 'yes' === (string) ( $settings['show_count'] ?? 'yes' ) ),
			'show_search'      => ( 'yes' === (string) ( $settings['show_search'] ?? 'yes' ) ),
			'show_sort'        => ( 'yes' === (string) ( $settings['show_sort'] ?? 'yes' ) ),
			'show_filters'     => ( 'yes' === (string) ( $settings['show_filters'] ?? 'yes' ) ),
			'show_powered_by'  => ( 'yes' === (string) ( $settings['show_powered_by'] ?? 'yes' ) ),
		);
		echo \LiteCal\Public\BuilderIntegration::wrap_public_page_markup( $args, $this->is_edit_mode() ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Builder wrapper returns placeholder in editor and frontend markup otherwise.
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
