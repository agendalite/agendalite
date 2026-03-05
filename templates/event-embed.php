<?php
/**
 * Embedded public booking template.
 *
 * @package LiteCal
 */

// phpcs:disable Squiz.Commenting,WordPress.NamingConventions.ValidVariableName -- Project style preference: keep concise docs and legacy variable names without functional impact.

// phpcs:disable WordPress.PHP.YodaConditions.NotYoda,Universal.Operators.DisallowShortTernary.Found -- Project style preference; no functional impact.
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Template-scope variables are local to include context.
// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read-only request vars used to render booking return state.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$litecal_render_options = isset( $litecal_render_options ) && is_array( $litecal_render_options ) ? $litecal_render_options : array();
$show_timezone          = ! array_key_exists( 'show_timezone', $litecal_render_options ) || ! empty( $litecal_render_options['show_timezone'] );
$show_time_format       = ! array_key_exists( 'show_time_format', $litecal_render_options ) || ! empty( $litecal_render_options['show_time_format'] );
$show_description       = ! array_key_exists( 'show_description', $litecal_render_options ) || ! empty( $litecal_render_options['show_description'] );
$show_powered_by_option = ! array_key_exists( 'show_powered_by', $litecal_render_options ) || ! empty( $litecal_render_options['show_powered_by'] );

$employees = \LiteCal\Core\Events::employees( $event->id );
if ( ! empty( $employees ) ) {
	foreach ( $employees as $employee_item ) {
		if ( ! is_object( $employee_item ) ) {
			continue;
		}
		$employee_item->avatar_url = \LiteCal\Core\Helpers::avatar_url( $employee_item->avatar_url ?? '', 'thumbnail' );
	}
}
$custom_fields = json_decode( $event->custom_fields ?: '[]', true );
if ( ! is_array( $custom_fields ) ) {
	$custom_fields = array();
}
$free_plan_restrictions = defined( 'LITECAL_IS_FREE' )
	&& LITECAL_IS_FREE
	&& ! ( function_exists( 'litecal_pro_is_active' ) && litecal_pro_is_active() );
if ( $free_plan_restrictions ) {
	$custom_fields = array_values(
		array_filter(
			$custom_fields,
			static function ( $field ) {
				if ( ! is_array( $field ) ) {
					return false;
				}
				return sanitize_key( (string) ( $field['type'] ?? '' ) ) !== 'file';
			}
		)
	);
}
$file_type_map                = array(
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
$location_ctx                 = \LiteCal\Public\PublicSite::normalize_event_location( $event );
$location_label               = $location_ctx['label'];
$location_details             = $location_ctx['details'];
$appearance                   = get_option( 'litecal_appearance', array() );
$accent                       = $appearance['accent'] ?? '#083a53';
$accent_text                  = $appearance['accent_text'] ?? '#ffffff';
$show_powered_by              = ( ! isset( $appearance['show_powered_by'] ) || ! empty( $appearance['show_powered_by'] ) ) && $show_powered_by_option;
$has_multiple_employees       = count( $employees ) > 1;
$host                         = $employees[0] ?? null;
$host_name                    = $has_multiple_employees
	? __( 'Profesional por definir', 'agenda-lite' )
	: ( $host ? $host->name : '' );
$host_avatar                  = $has_multiple_employees ? '' : ( $host->avatar_url ?? '' );
$host_title                   = (string) ( $host->title ?? '' );
$host_parts                   = preg_split( '/\s+/', trim( $host_name ) );
$host_initials                = strtoupper( substr( $host_parts[0] ?? '', 0, 1 ) . substr( $host_parts[1] ?? '', 0, 1 ) );
$meet_logo                    = '<img class="lc-location-logo" src="' . esc_url( LITECAL_URL . 'assets/logos/googlemeet.svg' ) . '" alt="Google Meet" />';
$zoom_logo                    = '<img class="lc-location-logo" src="' . esc_url( LITECAL_URL . 'assets/logos/zoom.svg' ) . '" alt="Zoom" />';
$teams_logo                   = '<img class="lc-location-logo" src="' . esc_url( LITECAL_URL . 'assets/logos/teams.svg' ) . '" alt="Microsoft Teams" />';
$location_icon                = '';
$schedules                    = get_option( 'litecal_schedules', array() );
$default_schedule             = get_option( 'litecal_default_schedule', 'default' );
$event_options                = json_decode( $event->options ?: '[]', true );
$schedule_id                  = ! empty( $event->availability_override )
	? ( $event_options['schedule_id'] ?? $default_schedule )
	: $default_schedule;
$schedule_days                = $schedules[ $schedule_id ]['days'] ?? array();
$schedule_timezone            = \LiteCal\Core\Helpers::resolve_schedule_timezone_name( $schedules[ $schedule_id ]['timezone'] ?? '' );
$price_mode                   = $event_options['price_mode'] ?? 'free';
$partial_percent              = (int) ( $event_options['partial_percent'] ?? 30 );
$partial_fixed_amount         = (float) ( $event_options['partial_fixed_amount'] ?? 0 );
$price_regular                = $event_options['price_regular'] ?? ( $event->price ?? 0 );
$price_sale                   = $event_options['price_sale'] ?? '';
$settings                     = get_option( 'litecal_settings', array() );
$currency                     = $event->currency ?: ( $settings['currency'] ?? 'CLP' );
$payment_return               = isset( $_GET['agendalite_payment'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['agendalite_payment'] ) ) : '';
$booking_id                   = isset( $_GET['booking_id'] ) ? (int) sanitize_text_field( wp_unslash( (string) $_GET['booking_id'] ) ) : 0;
$is_return_view               = $payment_return !== '' && $booking_id > 0;
$integrations                 = get_option( 'litecal_integrations', array() );
$google_oauth                 = get_option( 'litecal_google_oauth', array() );
$google_meet_allowed          = ! empty( $integrations['google_calendar'] ) && ( ! empty( $google_oauth['access_token'] ) || ! empty( $google_oauth['refresh_token'] ) );
$txt_google_meet              = __( 'Google Meet', 'agenda-lite' );
$txt_presential               = __( 'Presencial', 'agenda-lite' );
/* translators: %s: partial payment amount. */
$txt_partial_note_template    = __( 'Pagarás %s del total del servicio para asegurar tu reserva.', 'agenda-lite' );
/* translators: 1: percentage value, 2: total service amount. */
$txt_partial_percent_template = __( 'Este pago corresponde a un anticipo del %1$s%% para confirmar tu reserva sobre un servicio de %2$s.', 'agenda-lite' );
/* translators: 1: fixed partial amount, 2: total service amount. */
$txt_partial_fixed_template   = __( 'Este pago corresponde a un anticipo de %1$s para confirmar tu reserva sobre un servicio de %2$s.', 'agenda-lite' );
$txt_banner_flow              = __( 'Gracias, estamos validando tu pago.', 'agenda-lite' );
$txt_banner_stripe            = __( 'Gracias, estamos confirmando tu pago con Stripe.', 'agenda-lite' );
$txt_banner_transfer          = __( 'Reserva pendiente de transferencia bancaria.', 'agenda-lite' );
$txt_transfer_warning         = __( 'Tu reserva quedará Pendiente hasta que confirmemos el pago.', 'agenda-lite' );
$txt_meet_note                = __( 'Te llegará el enlace por correo al confirmar.', 'agenda-lite' );
$txt_summary                  = __( 'Selecciona una fecha y horario.', 'agenda-lite' );
$txt_step_professional        = __( 'Paso 1 · Profesional', 'agenda-lite' );
$txt_step_1                   = $has_multiple_employees ? __( 'Paso 2 · Fecha', 'agenda-lite' ) : __( 'Paso 1 · Fecha', 'agenda-lite' );
$txt_step_2                   = $has_multiple_employees ? __( 'Paso 3 · Horarios', 'agenda-lite' ) : __( 'Paso 2 · Horarios', 'agenda-lite' );
$txt_step_3                   = $has_multiple_employees ? __( 'Paso 4 · Tus datos', 'agenda-lite' ) : __( 'Paso 3 · Tus datos', 'agenda-lite' );
$txt_select_day               = __( 'Selecciona un día', 'agenda-lite' );
$txt_next                     = __( 'Siguiente', 'agenda-lite' );
$txt_back                     = __( '← Atrás', 'agenda-lite' );
$txt_name                     = __( 'Nombre *', 'agenda-lite' );
$txt_last_name                = __( 'Apellido *', 'agenda-lite' );
$txt_email                    = __( 'Email *', 'agenda-lite' );
$txt_phone                    = __( 'Teléfono *', 'agenda-lite' );
$txt_timezone                 = __( 'Zona horaria', 'agenda-lite' );
$txt_guest_add                = __( '+ Añadir invitado', 'agenda-lite' );
$txt_select_payment           = __( 'Selecciona medio de pago', 'agenda-lite' );
$txt_coming_soon              = __( 'Próximamente', 'agenda-lite' );
$txt_confirm                  = __( 'Confirmar', 'agenda-lite' );
$txt_extras                   = __( 'Extras', 'agenda-lite' );
$txt_extra_additional_cost    = __( 'Costo adicional', 'agenda-lite' );
$txt_success_title            = __( 'Esta reunión está programada', 'agenda-lite' );
$txt_success_message          = __( 'Hemos enviado un correo con los detalles.', 'agenda-lite' );
$txt_restart                  = __( 'Hacer otra reserva', 'agenda-lite' );
$txt_retry_payment            = __( 'Retomar pago', 'agenda-lite' );
$checkout_amount              = (float) ( $event->price ?? 0 );
if ( $price_mode === 'partial_percent' ) {
	$checkout_amount = round( $checkout_amount * ( $partial_percent / 100 ), 2 );
} elseif ( $price_mode === 'partial_fixed' && $partial_fixed_amount > 0 ) {
	$checkout_amount = (float) $partial_fixed_amount;
} elseif ( $price_mode === 'free' ) {
	$checkout_amount = 0;
}
$payment_methods = \LiteCal\Admin\Admin::payment_methods_for_event( $currency, $event_options, $checkout_amount );
if ( $price_mode === 'onsite' ) {
	$payment_methods = array();
}
$extras_config = \LiteCal\Admin\Admin::event_extras_config( $event_options, $currency );
$extras_items  = is_array( $extras_config['items'] ?? null ) ? $extras_config['items'] : array();
$extras_hours  = is_array( $extras_config['hours'] ?? null ) ? $extras_config['hours'] : array();
if ( $location_ctx['key'] === 'google_meet' && $google_meet_allowed ) {
	$location_icon = $meet_logo;
} elseif ( $location_ctx['key'] === 'zoom' ) {
	$location_icon = $zoom_logo;
} elseif ( $location_ctx['key'] === 'teams' ) {
	$location_icon = $teams_logo;
} elseif ( $location_ctx['is_presential'] ) {
	$location_icon = '<i class="ri-map-pin-5-line"></i>';
} elseif ( $location_ctx['is_phone'] ) {
	$location_icon = '<i class="ri-phone-line"></i>';
} else {
	$location_icon = '<i class="ri-video-line"></i>';
}
$location_label = $location_ctx['key'] === 'google_meet' && $google_meet_allowed ? $txt_google_meet : $location_label;
if ( $location_ctx['key'] === 'google_meet' && ! $google_meet_allowed ) {
	$location_label = $txt_presential;
	$location_icon  = '<i class="ri-map-pin-5-line"></i>';
}
$currency_meta         = \LiteCal\Admin\Admin::currency_meta( $currency );
$price_formatted       = \LiteCal\Admin\Admin::format_money( $event->price ?? 0, $currency );
$price_display         = strtoupper( $currency ) . ' ' . $price_formatted;
$price_regular_display = strtoupper( $currency ) . ' ' . \LiteCal\Admin\Admin::format_money( $price_regular, $currency );
$price_sale_display    = $price_sale !== '' ? strtoupper( $currency ) . ' ' . \LiteCal\Admin\Admin::format_money( $price_sale, $currency ) : '';
$partial_note          = '';
$partial_note_short    = '';
if ( $price_mode === 'partial_percent' && (float) $event->price > 0 ) {
	$partial_note_short = sprintf( $txt_partial_note_template, \LiteCal\Admin\Admin::format_money_label( $checkout_amount, $currency ) );
	$partial_note       = sprintf( $txt_partial_percent_template, $partial_percent, \LiteCal\Admin\Admin::format_money_label( $event->price, $currency ) );
}
if ( $price_mode === 'partial_fixed' && $partial_fixed_amount > 0 ) {
	$partial_note_short = sprintf( $txt_partial_note_template, \LiteCal\Admin\Admin::format_money_label( $checkout_amount, $currency ) );
	$partial_note       = sprintf( $txt_partial_fixed_template, \LiteCal\Admin\Admin::format_money_label( $partial_fixed_amount, $currency ), \LiteCal\Admin\Admin::format_money_label( $event->price, $currency ) );
}
$is_shortcode_clean = ! empty( $GLOBALS['litecal_event_embed_shortcode'] );
?>
<style>
	:root { --lc-accent: <?php echo esc_attr( $accent ); ?>; --lc-accent-text: <?php echo esc_attr( $accent_text ); ?>; }
</style>
<div class="lc-wrap lc-embed-wrap <?php echo $is_return_view ? 'is-return-view' : ''; ?> <?php echo $is_shortcode_clean ? 'lc-wrap--shortcode-clean' : ''; ?>">
	<div class="lc-card <?php echo $is_shortcode_clean ? 'lc-card--shortcode-clean' : ''; ?>" data-lc-card>
	<div class="lc-panel lc-panel-left">
	<?php if ( $host ) : ?>
		<div class="lc-host-mini" data-lc-host <?php echo $has_multiple_employees ? 'style="display:none;"' : ''; ?>>
		<img data-lc-host-avatar src="<?php echo esc_url( $host_avatar ); ?>" alt="<?php echo esc_attr( $host_name ); ?>" width="40" height="40" loading="eager" decoding="async" <?php echo empty( $host_avatar ) ? 'style="display:none;"' : ''; ?> />
		<span class="lc-host-fallback" data-lc-host-fallback <?php echo ! empty( $host_avatar ) ? 'style="display:none;"' : ''; ?>><?php echo esc_html( $host_initials ?: '•' ); ?></span>
		<span>
			<span data-lc-host-name><?php echo esc_html( $host_name ); ?></span>
			<small class="lc-host-title" data-lc-host-title <?php echo empty( $host_title ) || $has_multiple_employees ? 'style="display:none;"' : ''; ?>><?php echo esc_html( $host_title ); ?></small>
			<?php if ( $has_multiple_employees ) : ?>
			<a href="#" class="lc-host-change" data-lc-change-employee hidden><?php echo esc_html__( 'Cambiar profesional', 'agenda-lite' ); ?></a>
			<?php endif; ?>
		</span>
		</div>
	<?php endif; ?>
	<?php if ( $payment_return === 'flow' ) : ?>
		<div class="lc-return-banner"><?php echo esc_html( $txt_banner_flow ); ?></div>
	<?php elseif ( $payment_return === 'stripe' ) : ?>
		<div class="lc-return-banner"><?php echo esc_html( $txt_banner_stripe ); ?></div>
	<?php elseif ( $payment_return === 'transfer' ) : ?>
		<div class="lc-return-banner"><?php echo esc_html( $txt_banner_transfer ); ?></div>
	<?php endif; ?>
		<h1 class="lc-title"><?php echo esc_html( $event->title ); ?></h1>
		<?php if ( $price_mode === 'total' && (float) $event->price > 0 ) : ?>
			<?php if ( ! empty( $price_sale_display ) ) : ?>
			<div class="lc-payment-summary-row">
				<div class="lc-payment-summary"><s><?php echo esc_html( $price_regular_display ); ?></s></div>
				<div class="lc-payment-summary"><?php echo esc_html( $price_sale_display ); ?></div>
			</div>
			<?php else : ?>
			<div class="lc-payment-summary"><?php echo esc_html( $price_display ); ?></div>
			<?php endif; ?>
		<?php elseif ( in_array( $price_mode, array( 'partial_percent', 'partial_fixed' ), true ) && (float) $event->price > 0 ) : ?>
			<?php if ( ! empty( $price_sale_display ) ) : ?>
			<div class="lc-payment-summary-row">
				<div class="lc-payment-summary"><s><?php echo esc_html( $price_regular_display ); ?></s></div>
				<div class="lc-payment-summary"><?php echo esc_html( $price_sale_display ); ?></div>
			</div>
			<?php else : ?>
			<div class="lc-payment-summary"><?php echo esc_html( $price_display ); ?></div>
			<?php endif; ?>
			<?php if ( ! empty( $partial_note_short ) ) : ?>
			<div class="lc-payment-note-top"><?php echo esc_html( $partial_note_short ); ?></div>
			<?php endif; ?>
		<?php endif; ?>
		<?php if ( $show_description ) : ?>
		<div class="lc-desc"><?php echo wp_kses_post( $event->description ); ?></div>
		<?php endif; ?>
		<?php if ( $has_multiple_employees ) : ?>
		<div class="lc-employee-step" data-lc-employee-step>
			<div class="lc-step"><?php echo esc_html( $txt_step_professional ); ?></div>
			<div class="lc-employee-step-title"><?php echo esc_html__( 'Selecciona el profesional', 'agenda-lite' ); ?></div>
			<div class="lc-employee-step-list">
			<?php foreach ( $employees as $employee ) : ?>
				<?php
				$employee_name     = (string) ( $employee->name ?? '' );
				$employee_title    = (string) ( $employee->title ?? '' );
				$employee_avatar   = (string) ( $employee->avatar_url ?? '' );
				$employee_parts    = preg_split( '/\s+/', trim( $employee_name ) );
				$employee_initials = strtoupper( substr( $employee_parts[0] ?? '', 0, 1 ) . substr( $employee_parts[1] ?? '', 0, 1 ) );
				?>
				<button
				type="button"
				class="lc-employee-step-item"
				data-lc-employee-card="<?php echo esc_attr( (int) $employee->id ); ?>">
				<span class="lc-employee-step-avatar">
					<?php if ( ! empty( $employee_avatar ) ) : ?>
					<img src="<?php echo esc_url( $employee_avatar ); ?>" alt="<?php echo esc_attr( $employee_name ); ?>" width="40" height="40" loading="lazy" decoding="async" />
					<?php else : ?>
					<span class="lc-host-fallback"><?php echo esc_html( $employee_initials ?: '•' ); ?></span>
					<?php endif; ?>
				</span>
				<span class="lc-employee-step-meta">
					<strong><?php echo esc_html( $employee_name ); ?></strong>
					<?php if ( ! empty( $employee_title ) ) : ?>
					<small><?php echo esc_html( $employee_title ); ?></small>
					<?php else : ?>
					<small class="is-empty"><?php echo esc_html__( 'Profesional', 'agenda-lite' ); ?></small>
					<?php endif; ?>
				</span>
				</button>
			<?php endforeach; ?>
			</div>
		</div>
		<?php endif; ?>
		<div class="lc-meta">
		<div><i class="ri-time-line"></i> <?php echo esc_html( $event->duration ); ?> <?php echo esc_html__( 'min', 'agenda-lite' ); ?></div>
		<div class="lc-location-row">
			<span class="lc-location-row-main"><?php echo wp_kses_post( $location_icon ); ?> <?php echo esc_html( $location_label ); ?></span>
			<?php if ( in_array( $location_ctx['key'], array( 'google_meet', 'zoom' ), true ) ) : ?>
			<button
				type="button"
				class="lc-location-help"
				aria-label="<?php echo esc_attr( $txt_meet_note ); ?>"
				data-lc-tooltip-content="<?php echo esc_attr( $txt_meet_note ); ?>">
				<i class="ri-information-line"></i>
			</button>
			<?php endif; ?>
		</div>
		<?php if ( $location_ctx['is_virtual'] && $location_ctx['key'] !== 'google_meet' && ! empty( $location_ctx['details_url'] ) ) : ?>
			<div><i class="ri-link"></i> <a href="<?php echo esc_url( $location_ctx['details_url'] ); ?>" target="_blank" rel="noopener"><?php echo esc_html__( 'Abrir enlace', 'agenda-lite' ); ?></a></div>
		<?php endif; ?>
		<?php if ( $location_ctx['is_presential'] && ! empty( $location_details ) ) : ?>
			<div><i class="ri-map-pin-2-line"></i> <?php echo esc_html( $location_details ); ?></div>
		<?php endif; ?>
		<?php if ( $show_timezone ) : ?>
		<div class="lc-timezone-select-wrap">
			<label for="lc-timezone-select"><?php echo esc_html( $txt_timezone ); ?></label>
			<select id="lc-timezone-select" data-lc-timezone-select data-lc-default-timezone="<?php echo esc_attr( $schedule_timezone ); ?>"></select>
		</div>
		<?php endif; ?>
		</div>
		<div class="lc-summary" data-lc-summary><?php echo esc_html( $txt_summary ); ?></div>
	</div>

	<div class="lc-panel lc-panel-middle lc-stage lc-stage-select" data-lc-stage="select">
		<div class="lc-step"><?php echo esc_html( $txt_step_1 ); ?></div>
		<div class="lc-calendar" data-lc-calendar></div>
	</div>

		<div class="lc-panel lc-panel-right lc-stage lc-stage-select" data-lc-stage="select">
		<div class="lc-step"><?php echo esc_html( $txt_step_2 ); ?></div>
		<div class="lc-slots-header">
			<div class="lc-slots-date" data-lc-selected-date><?php echo esc_html( $txt_select_day ); ?></div>
			<?php if ( $show_time_format ) : ?>
			<div class="lc-time-toggle" data-lc-time-toggle>
			<button type="button" class="lc-time-option" data-lc-time-format="12h">12h</button>
			<button type="button" class="lc-time-option" data-lc-time-format="24h">24h</button>
			</div>
			<?php endif; ?>
		</div>
		<div class="lc-slots" data-lc-slots></div>
		<button class="lc-btn lc-next" type="button" data-lc-next disabled><?php echo esc_html( $txt_next ); ?></button>
	</div>

	<div class="lc-panel lc-panel-right lc-stage lc-stage-form" data-lc-stage="form">
		<div class="lc-form-header">
		<button class="lc-link" type="button" data-lc-back><?php echo esc_html( $txt_back ); ?></button>
		<div class="lc-step"><?php echo esc_html( $txt_step_3 ); ?></div>
		</div>
		<form class="lc-form" data-lc-form enctype="multipart/form-data">
		<label><?php echo esc_html( $txt_name ); ?></label>
		<input name="first_name" required />
		<label><?php echo esc_html( $txt_last_name ); ?></label>
		<input name="last_name" required />
		<label><?php echo esc_html( $txt_email ); ?></label>
		<input name="email" type="email" required />
		<label><?php echo esc_html( $txt_phone ); ?></label>
		<input name="phone" type="tel" class="lc-phone-input lc-phone-field" required />
		<?php foreach ( $custom_fields as $field ) : ?>
			<?php
			$required = ! empty( $field['required'] ) ? 'required' : '';
			$label    = $field['label'] ?? $field['key'];
			$enabled  = ! isset( $field['enabled'] ) || $field['enabled'];
			if ( ! $enabled ) {
				continue;
			}
			$field_type = $field['type'] ?? 'short_text';
			?>
			<?php if ( $field['key'] === 'phone' || $field_type === 'phone' ) : ?>
			<label><?php echo esc_html( $label ); ?><?php echo $required ? ' *' : ''; ?></label>
			<input name="custom_<?php echo esc_attr( $field['key'] ); ?>" data-lc-custom-key="<?php echo esc_attr( $field['key'] ); ?>" type="tel" class="lc-phone-input lc-phone-field" placeholder="9 1234 5678" <?php echo esc_attr( $required ); ?> />
			<?php elseif ( $field['key'] === 'company' ) : ?>
			<label><?php echo esc_html( $label ); ?><?php echo $required ? ' *' : ''; ?></label>
			<input name="custom_<?php echo esc_attr( $field['key'] ); ?>" data-lc-custom-key="<?php echo esc_attr( $field['key'] ); ?>" placeholder="<?php echo esc_attr__( 'Empresa', 'agenda-lite' ); ?>" <?php echo esc_attr( $required ); ?> />
			<?php elseif ( $field['key'] === 'message' || $field_type === 'long_text' ) : ?>
			<label><?php echo esc_html( $label ); ?><?php echo $required ? ' *' : ''; ?></label>
			<textarea name="custom_<?php echo esc_attr( $field['key'] ); ?>" data-lc-custom-key="<?php echo esc_attr( $field['key'] ); ?>" <?php echo esc_attr( $required ); ?>></textarea>
			<?php elseif ( $field_type === 'address' ) : ?>
			<label><?php echo esc_html( $label ); ?><?php echo $required ? ' *' : ''; ?></label>
			<textarea name="custom_<?php echo esc_attr( $field['key'] ); ?>" data-lc-custom-key="<?php echo esc_attr( $field['key'] ); ?>" <?php echo esc_attr( $required ); ?>></textarea>
			<?php elseif ( $field_type === 'number' ) : ?>
			<label><?php echo esc_html( $label ); ?><?php echo $required ? ' *' : ''; ?></label>
			<input name="custom_<?php echo esc_attr( $field['key'] ); ?>" data-lc-custom-key="<?php echo esc_attr( $field['key'] ); ?>" type="number" placeholder="<?php echo esc_attr__( '0', 'agenda-lite' ); ?>" <?php echo esc_attr( $required ); ?> />
			<?php elseif ( $field_type === 'url' ) : ?>
			<label><?php echo esc_html( $label ); ?><?php echo $required ? ' *' : ''; ?></label>
			<input name="custom_<?php echo esc_attr( $field['key'] ); ?>" data-lc-custom-key="<?php echo esc_attr( $field['key'] ); ?>" type="url" placeholder="https://..." <?php echo esc_attr( $required ); ?> />
			<?php elseif ( $field_type === 'select' || $field_type === 'multiselect' ) : ?>
			<label><?php echo esc_html( $label ); ?><?php echo $required ? ' *' : ''; ?></label>
			<select name="custom_<?php echo esc_attr( $field['key'] ); ?><?php echo $field_type === 'multiselect' ? '[]' : ''; ?>" data-lc-custom-key="<?php echo esc_attr( $field['key'] ); ?>" <?php echo $field_type === 'multiselect' ? 'multiple' : ''; ?> <?php echo esc_attr( $required ); ?>>
				<?php foreach ( ( $field['options'] ?? array() ) as $opt ) : ?>
				<option value="<?php echo esc_attr( $opt ); ?>"><?php echo esc_html( $opt ); ?></option>
				<?php endforeach; ?>
			</select>
			<?php elseif ( $field_type === 'checkbox_group' ) : ?>
			<label><?php echo esc_html( $label ); ?><?php echo $required ? ' *' : ''; ?></label>
			<div class="lc-field-group">
				<?php $first = true; foreach ( ( $field['options'] ?? array() ) as $opt ) : ?>
				<label class="lc-inline-option"><input type="checkbox" name="custom_<?php echo esc_attr( $field['key'] ); ?>[]" data-lc-custom-key="<?php echo esc_attr( $field['key'] ); ?>" value="<?php echo esc_attr( $opt ); ?>" <?php echo ( $required && $first ) ? 'required' : ''; ?>> <?php echo esc_html( $opt ); ?></label>
					<?php
					$first = false;
endforeach;
				?>
			</div>
			<?php elseif ( $field_type === 'radio_group' ) : ?>
			<label><?php echo esc_html( $label ); ?><?php echo $required ? ' *' : ''; ?></label>
			<div class="lc-field-group">
				<?php $first = true; foreach ( ( $field['options'] ?? array() ) as $opt ) : ?>
				<label class="lc-inline-option"><input type="radio" name="custom_<?php echo esc_attr( $field['key'] ); ?>" data-lc-custom-key="<?php echo esc_attr( $field['key'] ); ?>" value="<?php echo esc_attr( $opt ); ?>" <?php echo ( $required && $first ) ? 'required' : ''; ?>> <?php echo esc_html( $opt ); ?></label>
					<?php
					$first = false;
endforeach;
				?>
			</div>
			<?php elseif ( $field_type === 'checkbox' ) : ?>
			<label class="lc-inline-option"><input type="checkbox" name="custom_<?php echo esc_attr( $field['key'] ); ?>" data-lc-custom-key="<?php echo esc_attr( $field['key'] ); ?>" value="true" <?php echo esc_attr( $required ); ?>> <?php echo esc_html( $label ); ?><?php echo $required ? ' *' : ''; ?></label>
			<?php elseif ( $field_type === 'multiple_emails' ) : ?>
			<label><?php echo esc_html( $label ); ?><?php echo $required ? ' *' : ''; ?></label>
			<input name="custom_<?php echo esc_attr( $field['key'] ); ?>" data-lc-custom-key="<?php echo esc_attr( $field['key'] ); ?>" placeholder="<?php echo esc_attr__( 'emails separados por coma', 'agenda-lite' ); ?>" <?php echo esc_attr( $required ); ?> />
			<?php elseif ( $field_type === 'file' ) : ?>
				<?php
				$allowed   = $field['file_allowed'] ?? array();
				$custom    = $field['file_custom'] ?? '';
				$max_mb    = (float) ( $field['file_max_mb'] ?? 5 );
				$max_files = (int) ( $field['file_max_files'] ?? 1 );
				$max_files = max( 1, $max_files );
				$help      = $field['help'] ?? '';
				$exts      = array();
				$mimes     = array();
				foreach ( $allowed as $key ) {
					if ( ! empty( $file_type_map[ $key ] ) ) {
						$exts  = array_merge( $exts, $file_type_map[ $key ]['ext'] );
						$mimes = array_merge( $mimes, $file_type_map[ $key ]['mime'] );
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
				$exts   = array_values( array_unique( array_filter( $exts ) ) );
				$mimes  = array_values( array_unique( array_filter( $mimes ) ) );
				$accept = array();
				foreach ( $exts as $ext ) {
					$accept[] = '.' . $ext; }
				foreach ( $mimes as $mime ) {
					$accept[] = $mime; }
				$accept_attr = $accept ? implode( ',', $accept ) : '';
				$max_bytes   = wp_max_upload_size();
				if ( $max_mb > 0 ) {
					$max_bytes = min( $max_bytes, (int) ( $max_mb * 1024 * 1024 ) );
				}
				$input_name = 'file_' . $field['key'] . ( $max_files > 1 ? '[]' : '' );
				?>
			<label><?php echo esc_html( $label ); ?><?php echo $required ? ' *' : ''; ?></label>
			<input
				type="file"
				name="<?php echo esc_attr( $input_name ); ?>"
				class="lc-file-upload"
				data-lc-custom-file
				data-lc-file-key="<?php echo esc_attr( $field['key'] ); ?>"
				data-lc-file-required="<?php echo $required ? '1' : '0'; ?>"
				data-lc-file-exts="<?php echo esc_attr( implode( ',', $exts ) ); ?>"
				data-lc-file-mimes="<?php echo esc_attr( implode( ',', $mimes ) ); ?>"
				data-lc-file-max-bytes="<?php echo esc_attr( (string) $max_bytes ); ?>"
				data-lc-file-max-files="<?php echo esc_attr( (string) $max_files ); ?>"
				<?php echo $accept_attr ? 'accept="' . esc_attr( $accept_attr ) . '"' : ''; ?>
				<?php echo $max_files > 1 ? 'multiple' : ''; ?>
				<?php echo esc_attr( $required ); ?>
			/>
				<?php if ( ! empty( $help ) ) : ?>
				<small class="lc-help"><?php echo esc_html( $help ); ?></small>
			<?php endif; ?>
				<?php if ( $max_files > 1 ) : ?>
						<?php /* translators: %d: maximum allowed files. */ ?>
				<small class="lc-help"><?php printf( esc_html__( 'Máx. %d archivos.', 'agenda-lite' ), (int) $max_files ); ?></small>
			<?php endif; ?>
			<?php elseif ( $field_type === 'email' ) : ?>
			<label><?php echo esc_html( $label ); ?><?php echo $required ? ' *' : ''; ?></label>
			<input name="custom_<?php echo esc_attr( $field['key'] ); ?>" data-lc-custom-key="<?php echo esc_attr( $field['key'] ); ?>" type="email" placeholder="<?php echo esc_attr__( 'email@ejemplo.com', 'agenda-lite' ); ?>" <?php echo esc_attr( $required ); ?> />
			<?php else : ?>
			<label><?php echo esc_html( $label ); ?><?php echo $required ? ' *' : ''; ?></label>
			<input name="custom_<?php echo esc_attr( $field['key'] ); ?>" data-lc-custom-key="<?php echo esc_attr( $field['key'] ); ?>" <?php echo esc_attr( $required ); ?> />
			<?php endif; ?>
		<?php endforeach; ?>
		<?php
			$allow_guests = ! $free_plan_restrictions && ! empty( $event_options['allow_guests'] );
			$max_guests   = $allow_guests ? max( 1, min( 10, (int) ( $event_options['max_guests'] ?? 0 ) ) ) : 0;
		?>
		<?php if ( $allow_guests ) : ?>
			<div class="lc-guests" data-lc-guests data-lc-guests-max="<?php echo esc_attr( $max_guests ); ?>">
			<div class="lc-guest-list" data-lc-guest-list></div>
			<a href="#" class="lc-guest-add" data-lc-guest-add><?php echo esc_html( $txt_guest_add ); ?></a>
			</div>
		<?php endif; ?>
		<?php if ( ! empty( $extras_items ) || ! empty( $extras_hours['enabled'] ) ) : ?>
			<div class="lc-extras-choice" data-lc-extras>
			<label><?php echo esc_html( $txt_extras ); ?></label>
			<div class="lc-extras-options">
				<?php foreach ( $extras_items as $item ) : ?>
					<?php
					$extra_id    = sanitize_key( (string) ( $item['id'] ?? '' ) );
					$extra_name  = sanitize_text_field( (string) ( $item['name'] ?? '' ) );
					$extra_price = max( 0, (float) ( $item['price'] ?? 0 ) );
					$extra_image = esc_url( (string) ( $item['image'] ?? '' ) );
					if ( $extra_id === '' || $extra_name === '' ) {
						continue;
					}
					?>
					<label class="lc-extra-option">
						<input class="lc-extra-checkbox" type="checkbox" name="extras_items[]" value="<?php echo esc_attr( $extra_id ); ?>" data-lc-extra-item data-lc-extra-name="<?php echo esc_attr( $extra_name ); ?>" data-lc-extra-price="<?php echo esc_attr( (string) $extra_price ); ?>" />
						<span class="lc-extra-brand">
						<?php if ( $extra_image !== '' ) : ?>
							<img src="<?php echo esc_url( $extra_image ); ?>" alt="<?php echo esc_attr( $extra_name ); ?>" loading="lazy" decoding="async" />
						<?php else : ?>
							<i class="ri-add-circle-line"></i>
						<?php endif; ?>
						<span class="lc-extra-text"><strong><?php echo esc_html( $extra_name ); ?></strong></span>
						</span>
						<small class="lc-extra-price">+<?php echo esc_html( \LiteCal\Admin\Admin::format_money_label( $extra_price, $currency ) ); ?></small>
					</label>
				<?php endforeach; ?>
				<?php if ( ! empty( $extras_hours['enabled'] ) ) : ?>
					<?php
					$hours_label       = sanitize_text_field( (string) ( $extras_hours['label'] ?? __( 'Horas extra', 'agenda-lite' ) ) );
					$hours_interval    = max( 5, (int) ( $extras_hours['interval_minutes'] ?? 15 ) );
					$hours_price       = max( 0, (float) ( $extras_hours['price_per_interval'] ?? 0 ) );
					$hours_max_units   = max( 1, (int) ( $extras_hours['max_units'] ?? 8 ) );
					$hours_selector    = sanitize_key( (string) ( $extras_hours['selector'] ?? 'select' ) );
					$hours_use_stepper = $hours_selector === 'stepper';
					?>
					<div class="lc-extra-hours" data-lc-extra-hours data-lc-extra-hours-price="<?php echo esc_attr( (string) $hours_price ); ?>" data-lc-extra-hours-max="<?php echo esc_attr( (string) $hours_max_units ); ?>">
					<div class="lc-extra-hours-head">
						<strong><?php echo esc_html( $hours_label ); ?></strong>
							<small>
								<?php
								/* translators: %d: minutes per extra-time block. */
								printf( esc_html__( 'Intervalo de %d minutos', 'agenda-lite' ), absint( $hours_interval ) );
								?>
							</small>
					</div>
						<?php if ( $hours_use_stepper ) : ?>
							<div class="lc-extra-hours-options">
								<?php for ( $i = 0; $i <= $hours_max_units; $i++ ) : ?>
									<?php
									$hours_minutes = (int) ( $i * $hours_interval );
									$hours_amount  = max( 0, (float) ( $i * $hours_price ) );
									?>
									<label class="lc-extra-option lc-extra-option--hours">
										<input class="lc-extra-checkbox" type="radio" name="extras_hours_units" value="<?php echo esc_attr( (string) $i ); ?>" data-lc-extra-hours-units <?php checked( $i, 0 ); ?> />
										<span class="lc-extra-brand">
											<span class="lc-extra-text">
												<strong>
													<?php
													if ( 0 === $i ) {
														echo esc_html__( 'Sin tiempo extra', 'agenda-lite' );
														} else {
																printf(
																	/* translators: %d: additional minutes selected by the customer. */
																	esc_html__( '%d min adicionales', 'agenda-lite' ),
																	absint( $hours_minutes )
																);
													}
													?>
												</strong>
											</span>
										</span>
										<small class="lc-extra-price"><?php echo esc_html( '+' . \LiteCal\Admin\Admin::format_money_label( $hours_amount, $currency ) ); ?></small>
									</label>
								<?php endfor; ?>
							</div>
						<?php else : ?>
							<select name="extras_hours_units" class="lc-full-select" data-lc-extra-hours-units>
								<?php for ( $i = 0; $i <= $hours_max_units; $i++ ) : ?>
									<option value="<?php echo esc_attr( (string) $i ); ?>">
										<?php
										if ( 0 === $i ) {
											echo esc_html__( 'Sin tiempo extra', 'agenda-lite' );
											} else {
													printf(
														/* translators: %d: minutes for each extra-time option. */
														esc_html__( '%d min', 'agenda-lite' ),
														absint( $i * $hours_interval )
													);
										}
										?>
									</option>
								<?php endfor; ?>
							</select>
						<?php endif; ?>
						<div class="lc-extra-hours-note" data-lc-extra-hours-note><?php echo esc_html( $txt_extra_additional_cost . ': +' . \LiteCal\Admin\Admin::format_money_label( 0, $currency ) ); ?></div>
					</div>
				<?php endif; ?>
			</div>
			<div class="lc-extras-total" data-lc-extras-total hidden></div>
			</div>
		<?php endif; ?>
		<?php if ( in_array( $price_mode, array( 'total', 'partial_percent', 'partial_fixed' ), true ) && (float) $event->price > 0 && ! empty( $payment_methods ) ) : ?>
			<div class="lc-payment-choice">
			<label><?php echo esc_html( $txt_select_payment ); ?></label>
			<div class="lc-payment-options">
				<?php $first_available = true; ?>
				<?php foreach ( $payment_methods as $method ) : ?>
					<?php
					$disabled = empty( $method['available'] );
					$checked  = ( ! $disabled && $first_available ) ? 'checked' : '';
					if ( ! $disabled && $first_available ) {
						$first_available = false;
					}
					?>
				<label class="lc-payment-option <?php echo $disabled ? 'is-disabled' : ''; ?>" data-lc-payment-option data-lc-payment-key="<?php echo esc_attr( $method['key'] ); ?>">
					<input class="lc-payment-radio" type="radio" name="payment_provider" value="<?php echo esc_attr( $method['key'] ); ?>" <?php echo esc_attr( $checked ); ?> <?php echo $disabled ? 'disabled' : ''; ?> />
					<span class="lc-payment-brand">
					<?php if ( ! empty( $method['logo'] ) ) : ?>
						<img src="<?php echo esc_url( $method['logo'] ); ?>" alt="<?php echo esc_attr( $method['label'] ); ?>" />
					<?php else : ?>
						<i class="<?php echo esc_attr( $method['icon'] ?? 'ri-bank-card-line' ); ?>"></i>
					<?php endif; ?>
					<span class="lc-payment-text">
						<span class="lc-payment-title-row">
						<strong><?php echo esc_html( $method['label'] ); ?></strong>
						<?php if ( ! empty( $method['badge'] ) ) : ?>
							<small class="lc-payment-convert-badge" data-lc-payment-convert-badge><?php echo esc_html( $method['badge'] ); ?></small>
						<?php endif; ?>
						</span>
						<small><?php echo esc_html( $method['desc'] ); ?></small>
					</span>
					</span>
					<?php
					if ( $disabled ) :
						?>
						<small class="lc-disabled-note"><?php echo esc_html( $txt_coming_soon ); ?></small><?php endif; ?>
					</label>
				<?php endforeach; ?>
			</div>
			<div class="lc-transfer-warning" data-lc-transfer-warning hidden><?php echo esc_html( $txt_transfer_warning ); ?></div>
			</div>
		<?php endif; ?>
		<?php if ( ! empty( $partial_note ) ) : ?>
			<div class="lc-payment-note"><?php echo esc_html( $partial_note ); ?></div>
		<?php endif; ?>
		<div class="lc-form-actions">
		<button class="lc-btn" type="submit" data-lc-submit><?php echo esc_html( $txt_confirm ); ?></button>
		</div>
		</form>
	</div>

	<div class="lc-panel lc-panel-right lc-stage lc-stage-success" data-lc-stage="success">
		<div class="lc-success">
		<div class="lc-success-icon">✓</div>
		<h2 data-lc-success-title><?php echo esc_html( $txt_success_title ); ?></h2>
		<p data-lc-success-message><?php echo esc_html( $txt_success_message ); ?></p>
		<div class="lc-success-card" data-lc-success-summary style="display:none;"></div>
		<div class="lc-receipt" data-lc-receipt></div>
		<div class="lc-receipt-actions" style="position: static;margin-top: 12px;display:flex;gap: 0px;justify-content: space-evenly;">
			<button class="lc-btn" type="button" data-lc-restart><?php echo esc_html( $txt_restart ); ?></button>
			<button class="lc-btn" type="button" data-lc-retry-payment style="display:none;"><?php echo esc_html( $txt_retry_payment ); ?></button>
		</div>
		</div>
		</div>
	</div>
	<?php if ( $show_powered_by ) : ?>
	<div class="lc-footer">
		<span><?php echo esc_html__( 'Desarrollado por', 'agenda-lite' ); ?></span>
		<a href="https://www.agendalite.com/" target="_blank" rel="noopener noreferrer" aria-label="<?php echo esc_attr__( 'Agenda Lite', 'agenda-lite' ); ?>">
			<img class="lc-footer-logo" src="<?php echo esc_url( LITECAL_URL . 'assets/admin/powerby.svg' ); ?>" alt="<?php echo esc_attr__( 'Agenda Lite', 'agenda-lite' ); ?>" />
		</a>
	</div>
	<?php endif; ?>
	</div>
	<?php
	$event_options = json_decode( $event->options ?: '[]', true );
	$settings      = get_option( 'litecal_settings', array() );
	?>
	<script>
	window.litecalEvent = 
	<?php
	echo wp_json_encode(
		array(
			'event'                  => $event,
			'employees'              => $employees,
			'has_multiple_employees' => $has_multiple_employees,
			'schedule_days'          => array_keys( $schedule_days ),
			'time_off'               => LiteCal\Core\Helpers::time_off_ranges( $employees ),
			'settings'               => array(
				'time_format'       => $settings['time_format'] ?? '12h',
				'schedule_timezone' => $schedule_timezone,
			),
			'limits'                 => array(
				'future_days'   => (int) ( $event_options['future_days'] ?? 0 ),
				'limit_per_day' => (int) ( $event_options['limit_per_day'] ?? 0 ),
				'notice_hours'  => (int) ( $event_options['notice_hours'] ?? 0 ),
				'allow_guests'  => ( ! $free_plan_restrictions && ! empty( $event_options['allow_guests'] ) ),
					'max_guests'    => $allow_guests ? $max_guests : 0,
			),
			'payment'                => array(
				'mode'            => $price_mode,
				'price'           => (float) $event->price,
				'base_price'      => (float) $event->price,
				'partial_percent' => $partial_percent,
				'partial_fixed_amount' => $partial_fixed_amount,
				'currency'        => $currency,
				'methods'         => $payment_methods,
				'extras'          => $extras_config,
			),
		)
	);
	?>
	;
	</script>
</div>
