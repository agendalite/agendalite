<?php
/**
 * Public payment receipt template.
 *
 * @package LiteCal
 */

// phpcs:disable Squiz.Commenting,WordPress.NamingConventions.ValidVariableName -- Project style preference: keep concise docs and legacy variable names without functional impact.

// phpcs:disable WordPress.PHP.YodaConditions.NotYoda,Universal.Operators.DisallowShortTernary.Found -- Project style preference; no functional impact.
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Template-scope variables are local to include context.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$appearance           = get_option( 'litecal_appearance', array() );
$accent               = $appearance['accent'] ?? '#083a53';
$accent_text          = $appearance['accent_text'] ?? '#ffffff';
$settings             = get_option( 'litecal_settings', array() );
$txt_order_receipt    = __( 'Recibo de orden', 'agenda-lite' );
$txt_booking_fallback = __( 'Reserva', 'agenda-lite' );
$receipt_data         = null;
if ( ! empty( $booking ) ) {
	$snapshot       = \LiteCal\Core\Bookings::decode_snapshot( $booking );
	$pending_at_raw = $booking->payment_pending_at ?? null;
	$pending_at_ts  = ! empty( $pending_at_raw ) ? strtotime( (string) $pending_at_raw ) : 0;
	if ( $pending_at_ts <= 0 && ! empty( $booking->created_at ) ) {
		$pending_at_ts = strtotime( (string) $booking->created_at );
	}
	$retry_seconds_left = 0;
	if ( $pending_at_ts > 0 && in_array( strtolower( (string) ( $booking->payment_status ?? '' ) ), array( 'pending', 'unpaid' ), true ) ) {
		$retry_seconds_left = max( 0, 600 - ( (int) current_time( 'timestamp' ) - (int) $pending_at_ts ) );
	}
	$employee = null;
	if ( ! empty( $snapshot['employee'] ) && is_array( $snapshot['employee'] ) ) {
		$employee = array(
			'id'    => (int) ( $snapshot['employee']['id'] ?? 0 ),
			'name'  => $snapshot['employee']['name'] ?? '',
			'email' => $snapshot['employee']['email'] ?? '',
		);
	}
	$receipt_data = array(
		'id'                         => (int) $booking->id,
		'event_id'                   => (int) $booking->event_id,
		'name'                       => $booking->name,
		'start'                      => $booking->start_datetime,
		'end'                        => $booking->end_datetime,
		'custom_fields'              => $booking->custom_fields ? json_decode( $booking->custom_fields, true ) : array(),
		'guests'                     => $booking->guests ? json_decode( $booking->guests, true ) : array(),
		'created_at_ts'              => ! empty( $booking->created_at ) ? strtotime( $booking->created_at ) : 0,
		'created_at'                 => $booking->created_at ?? '',
		'payment_pending_at'         => $pending_at_raw,
		'payment_pending_at_ts'      => $pending_at_ts > 0 ? $pending_at_ts : null,
		'payment_retry_seconds_left' => $retry_seconds_left,
		'payment_can_retry'          => $retry_seconds_left > 0,
		'status'                     => $booking->status ?? '',
		'payment_status'             => $booking->payment_status ?? '',
		'payment_provider'           => $booking->payment_provider ?? '',
		'payment_amount'             => $booking->payment_amount ?? 0,
		'payment_currency'           => $booking->payment_currency ?? 'CLP',
		'payment_reference'          => $booking->payment_reference ?? '',
		'meet_link'                  => $booking->calendar_meet_link ?? '',
		'meeting_provider'           => sanitize_key( (string) ( $booking->video_provider ?? ( $snapshot['event']['location'] ?? '' ) ) ),
		'snapshot'                   => $snapshot,
		'employee'                   => $employee,
	);
}
?><!doctype html>
<html <?php language_attributes(); ?>>
<head>
	<meta charset="<?php bloginfo( 'charset' ); ?>">
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<style>
	:root { --lc-accent: <?php echo esc_attr( $accent ); ?>; --lc-accent-text: <?php echo esc_attr( $accent_text ); ?>; }
	</style>
	<?php wp_head(); ?>
</head>
<body <?php body_class( 'litecal-page' ); ?>>
	<main id="main" class="lc-main" role="main">
	<div class="lc-wrap is-return-view">
	<div class="lc-card is-success" data-lc-card>
		<div class="lc-panel lc-panel-right lc-stage lc-stage-success" data-lc-stage="success" style="display:block;">
		<div class="lc-success">
			<div class="lc-success-icon">✓</div>
			<h2 data-lc-success-title><?php echo esc_html( $txt_order_receipt ); ?></h2>
			<p data-lc-success-message></p>
			<div class="lc-receipt" data-lc-receipt></div>
		</div>
		</div>
	</div>
	</div>
	<script>
	window.litecalEvent = 
	<?php
	echo wp_json_encode(
		array(
			'event'     => array(
				'id'    => (int) ( $event->id ?? 0 ),
				'title' => $event->title ?? $txt_booking_fallback,
			),
			'employees' => $employees ?? array(),
			'settings'  => array(
				'time_format' => $settings['time_format'] ?? '12h',
			),
			'payment'   => array(
				'mode'     => 'free',
				'price'    => (float) ( $event->price ?? 0 ),
				'currency' => $event->currency ?? 'CLP',
				'methods'  => array(),
			),
		)
	);
	?>
	;
	window.litecalReceiptData = <?php echo wp_json_encode( $receipt_data ); ?>;
	</script>
	</main>
	<?php wp_footer(); ?>
</body>
</html>
