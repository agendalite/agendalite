<?php
/**
 * Uninstall routine for Agenda Lite.
 *
 * @package LiteCal
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

$litecal_roles_obj = function_exists( 'wp_roles' ) ? wp_roles() : null;
$litecal_roles     = ( $litecal_roles_obj instanceof \WP_Roles ) ? array_keys( (array) $litecal_roles_obj->roles ) : array( 'administrator', 'editor', 'shop_manager' );

foreach ( $litecal_roles as $litecal_role_slug ) {
	$litecal_role = get_role( (string) $litecal_role_slug );
	if ( $litecal_role && $litecal_role->has_cap( 'manage_agendalite' ) ) {
		$litecal_role->remove_cap( 'manage_agendalite' );
	}
}

$litecal_booking_role = defined( 'LITECAL_BOOKING_MANAGER_ROLE' ) ? LITECAL_BOOKING_MANAGER_ROLE : 'litecal_booking_manager';
if ( get_role( $litecal_booking_role ) ) {
	remove_role( $litecal_booking_role );
}
