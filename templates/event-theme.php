<?php
/**
 * Theme wrapper for single service booking pages.
 *
 * @package LiteCal
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( '\LiteCal\Core\Events' ) ) {
	return;
}

$litecal_event = $GLOBALS['litecal_current_event'] ?? null;
if ( is_object( $litecal_event ) && ! empty( $litecal_event->id ) ) {
	$litecal_event = \LiteCal\Core\Events::get( (int) $litecal_event->id );
}

if ( ! is_object( $litecal_event ) || empty( $litecal_event->id ) ) {
	$litecal_slug = sanitize_title( (string) get_query_var( 'litecal_event' ) );
	if ( $litecal_slug === '' ) {
		$litecal_slug = trim( (string) wp_parse_url( sanitize_text_field( (string) wp_unslash( $_SERVER['REQUEST_URI'] ?? '' ) ), PHP_URL_PATH ), '/' );
		$litecal_slug = sanitize_title( $litecal_slug );
	}
	if ( $litecal_slug !== '' ) {
		$litecal_event = \LiteCal\Core\Events::get_by_slug( $litecal_slug );
	}
}

if ( ! is_object( $litecal_event ) || empty( $litecal_event->id ) ) {
	return;
}

$GLOBALS['litecal_current_event'] = $litecal_event;

get_header();

$litecal_template = LITECAL_PATH . 'templates/event-page.php';
if ( file_exists( $litecal_template ) ) {
	(
		static function () use ( $litecal_event, $litecal_template ) {
			$event = $litecal_event;
			include $litecal_template;
		}
	)();
}

get_footer();
