<?php
/**
 * Public business services listing template.
 *
 * @package LiteCal
 */

// phpcs:disable Squiz.Commenting,WordPress.NamingConventions.ValidVariableName -- Project style preference: keep concise docs and legacy variable names without functional impact.
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals -- Template scope variables are local to this include.

// phpcs:disable WordPress.PHP.YodaConditions.NotYoda,Universal.Operators.DisallowShortTernary.Found -- Project style preference; no functional impact.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$services_count    = count( $events );
$services_per_page = max( 1, min( 12, (int) ( $view['services_per_page'] ?? 6 ) ) );
$show_description  = ! array_key_exists( 'show_description', $view ) || ! empty( $view['show_description'] );
$show_title        = ! array_key_exists( 'show_title', $view ) || ! empty( $view['show_title'] );
$show_count        = ! array_key_exists( 'show_count', $view ) || ! empty( $view['show_count'] );
$show_search       = ! array_key_exists( 'show_search', $view ) || ! empty( $view['show_search'] );
$show_sort         = ! array_key_exists( 'show_sort', $view ) || ! empty( $view['show_sort'] );
$show_filters      = ! array_key_exists( 'show_filters', $view ) || ! empty( $view['show_filters'] );
$show_powered_by_option = ! array_key_exists( 'show_powered_by', $view ) || ! empty( $view['show_powered_by'] );
$has_free_services = false;
$has_paid_services = false;
$has_online_services = false;
$has_inperson_services = false;

foreach ( (array) $events as $filter_event_item ) {
	$filter_options   = json_decode( (string) ( $filter_event_item->options ?? '{}' ), true );
	$filter_price_mode = sanitize_key( (string) ( $filter_options['price_mode'] ?? '' ) );
	$requires_payment = (
		( ! empty( $filter_event_item->require_payment ) && (float) ( $filter_event_item->price ?? 0 ) > 0 ) ||
		( 'onsite' === $filter_price_mode && (float) ( $filter_event_item->price ?? 0 ) > 0 )
	);
	if ( $requires_payment ) {
		$has_paid_services = true;
	} else {
		$has_free_services = true;
	}

	$location_raw = sanitize_key( (string) ( $filter_event_item->location ?? '' ) );
	$is_online    = in_array( $location_raw, array( 'google_meet', 'zoom', 'teams', 'online', 'virtual' ), true );
	if ( $is_online ) {
		$has_online_services = true;
	} else {
		$has_inperson_services = true;
	}
}

$appearance  = get_option( 'litecal_appearance', array() );
$accent      = sanitize_hex_color( (string) ( $appearance['accent'] ?? '#083a53' ) ) ?: '#083a53';
$accent_text = sanitize_hex_color( (string) ( $appearance['accent_text'] ?? '#ffffff' ) ) ?: '#ffffff';
$show_powered_by = ( ! isset( $appearance['show_powered_by'] ) || ! empty( $appearance['show_powered_by'] ) ) && $show_powered_by_option;
?>
<style>
	:root {
	--lc-accent: <?php echo esc_attr( $accent ); ?>;
	--lc-accent-text: <?php echo esc_attr( $accent_text ); ?>;
	}
</style>

<section id="lc-public-services" class="lc-public-services lc-ap-services-shell lc-services-only" data-lc-public-services data-lc-public-per-page="<?php echo esc_attr( (string) $services_per_page ); ?>">
	<div class="lc-ap-services-layout lc-services-only-layout">
	<div class="lc-ap-services-main">
		<div class="lc-public-toolbar">
		<?php if ( $show_search ) : ?>
		<label class="lc-public-search">
			<i class="ri-search-line" aria-hidden="true"></i>
			<input
			type="search"
			placeholder="<?php esc_attr_e( 'Buscar servicio (ej: permanente, pedicure, retiro...)', 'agenda-lite' ); ?>"
			data-lc-public-search
			/>
		</label>
		<?php endif; ?>
		<?php if ( $show_sort ) : ?>
		<div class="lc-public-toolbar-actions">
			<label class="lc-public-sort-wrap">
			<span><?php esc_html_e( 'Orden', 'agenda-lite' ); ?></span>
			<select data-lc-public-sort>
				<option value="default"><?php esc_html_e( 'Orden definido', 'agenda-lite' ); ?></option>
				<option value="name_asc"><?php esc_html_e( 'Nombre (A-Z)', 'agenda-lite' ); ?></option>
				<option value="price_asc"><?php esc_html_e( 'Precio: menor a mayor', 'agenda-lite' ); ?></option>
				<option value="price_desc"><?php esc_html_e( 'Precio: mayor a menor', 'agenda-lite' ); ?></option>
			</select>
			</label>
			<button type="button" class="lc-public-clear" data-lc-public-clear><?php esc_html_e( 'Limpiar', 'agenda-lite' ); ?></button>
		</div>
		<?php endif; ?>
		</div>

		<?php if ( $show_filters ) : ?>
		<div class="lc-public-filter-row" role="group" aria-label="<?php esc_attr_e( 'Filtros de servicios', 'agenda-lite' ); ?>">
		<button type="button" class="lc-public-filter-btn is-active" data-lc-public-filter="all" aria-pressed="true"><?php esc_html_e( 'Todos', 'agenda-lite' ); ?></button>
		<?php if ( $has_free_services ) : ?>
		<button type="button" class="lc-public-filter-btn" data-lc-public-filter="free" aria-pressed="false"><?php esc_html_e( 'Gratis', 'agenda-lite' ); ?></button>
		<?php endif; ?>
		<?php if ( $has_paid_services ) : ?>
		<button type="button" class="lc-public-filter-btn" data-lc-public-filter="paid" aria-pressed="false"><?php esc_html_e( 'Con pago', 'agenda-lite' ); ?></button>
		<?php endif; ?>
		<?php if ( $has_online_services ) : ?>
		<button type="button" class="lc-public-filter-btn" data-lc-public-filter="online" aria-pressed="false"><?php esc_html_e( 'Online', 'agenda-lite' ); ?></button>
		<?php endif; ?>
		<?php if ( $has_inperson_services ) : ?>
		<button type="button" class="lc-public-filter-btn" data-lc-public-filter="inperson" aria-pressed="false"><?php esc_html_e( 'Presencial', 'agenda-lite' ); ?></button>
		<?php endif; ?>
		</div>
		<?php endif; ?>

		<?php if ( $show_title || $show_count ) : ?>
		<div class="lc-public-services-head">
		<?php if ( $show_title ) : ?>
		<h2><?php esc_html_e( 'Servicios disponibles', 'agenda-lite' ); ?></h2>
		<?php endif; ?>
		<?php if ( $show_count ) : ?>
		<small><span data-lc-public-count><?php echo esc_html( (string) $services_count ); ?></span> <?php echo esc_html( _n( 'servicio', 'servicios', $services_count, 'agenda-lite' ) ); ?></small>
		<?php endif; ?>
		</div>
		<?php endif; ?>

		<?php if ( $services_count <= 0 ) : ?>
		<div class="lc-empty-state-wrap">
			<div class="lc-empty-state lc-empty-state--event-not-found">
			<div><?php esc_html_e( 'Aún no hay servicios publicados.', 'agenda-lite' ); ?></div>
			</div>
		</div>
		<?php else : ?>
		<div class="lc-business-services-grid lc-public-services-grid" data-lc-public-grid>
			<?php foreach ( $events as $index => $event_item ) : ?>
				<?php
				$event_title       = (string) ( $event_item->title ?? __( 'Servicio', 'agenda-lite' ) );
				$event_url         = home_url( '/' . trim( (string) ( $event_item->slug ?? '' ), '/' ) . '/' );
				$event_description = trim( wp_strip_all_tags( (string) ( $event_item->description ?? '' ) ) );
				if ( $event_description === '' ) {
					$event_description = __( 'Reserva online disponible para este servicio.', 'agenda-lite' );
				}
				if ( function_exists( 'mb_strimwidth' ) ) {
					$event_description = mb_strimwidth( $event_description, 0, 170, '...', 'UTF-8' );
				} elseif ( strlen( $event_description ) > 170 ) {
					$event_description = substr( $event_description, 0, 170 ) . '...';
				}
				$duration_label   = self::public_page_duration_label( (int) ( $event_item->duration ?? 0 ) );
				$price_label      = self::public_page_price_label( $event_item );
				$event_options    = json_decode( (string) ( $event_item->options ?? '{}' ), true );
				$event_price_mode = sanitize_key( (string) ( $event_options['price_mode'] ?? '' ) );
				$requires_payment = (
					( ! empty( $event_item->require_payment ) && (float) ( $event_item->price ?? 0 ) > 0 ) ||
					( 'onsite' === $event_price_mode && (float) ( $event_item->price ?? 0 ) > 0 )
				);
				$price_numeric    = $requires_payment ? (float) $event_item->price : 0;
				$event_employees  = array_values(
					array_filter(
						(array) \LiteCal\Core\Events::employees( (int) ( $event_item->id ?? 0 ) ),
						static function ( $employee_item ) {
							return is_object( $employee_item );
						}
					)
				);
				if ( ! empty( $event_employees ) ) {
					foreach ( $event_employees as $employee_item ) {
						$employee_item->avatar_url = \LiteCal\Core\Helpers::avatar_url( $employee_item->avatar_url ?? '', 'thumbnail' );
					}
				}
				$visible_employees   = array_slice( $event_employees, 0, 3 );
				$remaining_employees = max( 0, count( $event_employees ) - count( $visible_employees ) );
				$location_raw        = sanitize_key( (string) ( $event_item->location ?? '' ) );
				$location_label      = ucfirst( (string) ( $event_item->location ?? '' ) );
				$location_logo_url   = '';
				if ( $location_raw === 'google_meet' ) {
					$location_label    = 'Google Meet';
					$location_logo_url = LITECAL_URL . 'assets/logos/googlemeet.svg';
				} elseif ( $location_raw === 'zoom' ) {
					$location_label    = 'Zoom';
					$location_logo_url = LITECAL_URL . 'assets/logos/zoom.svg';
				} elseif ( $location_raw === 'teams' ) {
					$location_label    = 'Microsoft Teams';
					$location_logo_url = LITECAL_URL . 'assets/logos/teams.svg';
				} elseif ( $location_raw === 'presencial' ) {
					$location_label = __( 'Presencial', 'agenda-lite' );
				}
				$is_online     = in_array( $location_raw, array( 'google_meet', 'zoom', 'teams', 'online', 'virtual' ), true );
				$location_type = $is_online ? 'online' : 'inperson';
				?>
			<article
				id="lc-public-service-<?php echo esc_attr( (string) (int) ( $event_item->id ?? ( $index + 1 ) ) ); ?>"
				class="lc-business-service-card lc-public-service-card lc-service-card-pro"
				data-lc-public-service-card
				data-index="<?php echo esc_attr( (string) $index ); ?>"
				data-name="<?php echo esc_attr( strtolower( $event_title ) ); ?>"
				data-description="<?php echo esc_attr( strtolower( $event_description ) ); ?>"
				data-price="<?php echo esc_attr( (string) $price_numeric ); ?>"
				data-paid="<?php echo $requires_payment ? '1' : '0'; ?>"
				data-location-type="<?php echo esc_attr( $location_type ); ?>"
			>
				<div class="lc-public-service-top">
				<div class="lc-service-staff-stack" aria-label="<?php esc_attr_e( 'Profesionales asignados', 'agenda-lite' ); ?>">
					<?php if ( ! empty( $visible_employees ) ) : ?>
							<?php foreach ( $visible_employees as $employee_entry ) : ?>
								<?php
								$employee_name     = trim( (string) ( $employee_entry->name ?? '' ) );
								$employee_avatar   = trim( (string) ( $employee_entry->avatar_url ?? '' ) );
								$employee_avatar_id = function_exists( 'attachment_url_to_postid' ) ? (int) attachment_url_to_postid( $employee_avatar ) : 0;
								$employee_initials = '';
								foreach ( preg_split( '/\s+/', $employee_name ) as $part ) {
									$part = trim( (string) $part );
									if ( $part === '' ) {
									continue;
								}
								$employee_initials .= function_exists( 'mb_substr' ) ? mb_strtoupper( mb_substr( $part, 0, 1 ) ) : strtoupper( substr( $part, 0, 1 ) );
								if ( strlen( $employee_initials ) >= 2 ) {
									break;
								}
							}
							if ( $employee_initials === '' ) {
								$employee_initials = '--';
							}
							?>
							<span class="lc-service-staff-avatar" title="<?php echo esc_attr( $employee_name ); ?>">
								<?php if ( $employee_avatar !== '' ) : ?>
									<?php
									if ( $employee_avatar_id > 0 && function_exists( 'wp_get_attachment_image' ) ) {
										echo wp_kses_post(
											wp_get_attachment_image(
												$employee_avatar_id,
												array( 40, 40 ),
												false,
												array(
													'alt'      => $employee_name,
													'loading'  => 'lazy',
													'decoding' => 'async',
													'sizes'    => '40px',
												)
											)
										);
									} else {
										?>
										<img src="<?php echo esc_url( $employee_avatar ); ?>" alt="<?php echo esc_attr( $employee_name ); ?>" loading="lazy" decoding="async" width="40" height="40" sizes="40px">
										<?php
									}
									?>
							<?php else : ?>
								<span><?php echo esc_html( $employee_initials ); ?></span>
							<?php endif; ?>
							</span>
					<?php endforeach; ?>
						<?php if ( $remaining_employees > 0 ) : ?>
								<?php /* translators: %d: count of additional assigned professionals. */ ?>
						<span class="lc-service-staff-more" title="<?php echo esc_attr( sprintf( __( 'Más %d profesionales', 'agenda-lite' ), $remaining_employees ) ); ?>">+<?php echo esc_html( (string) $remaining_employees ); ?></span>
					<?php endif; ?>
					<?php else : ?>
					<span class="lc-service-staff-avatar is-empty" title="<?php esc_attr_e( 'Sin profesional asignado', 'agenda-lite' ); ?>"><i class="ri-user-3-line" aria-hidden="true"></i></span>
					<?php endif; ?>
				</div>
				<span class="lc-service-location-chip <?php echo $location_type === 'online' ? 'is-online' : 'is-inperson'; ?>">
					<?php if ( $location_logo_url !== '' ) : ?>
					<img class="lc-service-location-logo" src="<?php echo esc_url( $location_logo_url ); ?>" alt="<?php echo esc_attr( $location_label ); ?>" loading="lazy" decoding="async">
					<?php else : ?>
					<i class="ri-map-pin-5-line" aria-hidden="true"></i>
					<?php endif; ?>
					<?php echo esc_html( $location_label ); ?>
				</span>
				</div>

				<h3 class="lc-service-title"><?php echo esc_html( $event_title ); ?></h3>
				<?php if ( $show_description ) : ?>
				<p class="lc-service-desc"><?php echo esc_html( $event_description ); ?></p>
				<?php endif; ?>

				<div class="lc-business-service-meta lc-public-service-meta">
				<?php if ( $duration_label !== '' ) : ?>
					<span><i class="ri-time-line" aria-hidden="true"></i><?php echo esc_html( $duration_label ); ?></span>
				<?php endif; ?>
				</div>

				<div class="lc-public-service-footer">
				<strong class="lc-business-price"><?php echo esc_html( $price_label ); ?></strong>
				<a class="lc-business-service-btn lc-public-service-btn" href="<?php echo esc_url( $event_url ); ?>"><?php esc_html_e( 'Reservar', 'agenda-lite' ); ?></a>
				</div>
			</article>
			<?php endforeach; ?>
		</div>
		<div class="lc-public-pagination" data-lc-public-pagination></div>
		<div class="lc-empty-state-wrap" data-lc-public-empty style="display:none;">
			<div class="lc-empty-state lc-empty-state--event-not-found">
			<div><?php esc_html_e( 'No hay servicios que coincidan con tu búsqueda.', 'agenda-lite' ); ?></div>
			</div>
		</div>
		<?php endif; ?>
	</div>
	</div>
</section>

<?php if ( $show_powered_by ) : ?>
<div class="lc-footer">
	<span><?php echo esc_html__( 'Desarrollado por', 'agenda-lite' ); ?></span>
	<a href="https://www.agendalite.com/" target="_blank" rel="noopener noreferrer" aria-label="<?php echo esc_attr__( 'Agenda Lite', 'agenda-lite' ); ?>">
		<img class="lc-footer-logo" src="<?php echo esc_url( LITECAL_URL . 'assets/admin/powerby.svg' ); ?>" alt="<?php echo esc_attr__( 'Agenda Lite', 'agenda-lite' ); ?>" />
	</a>
</div>
<?php endif; ?>

<?php
if ( ! empty( $events ) ) {
	$catalog_items = array();
	foreach ( $events as $i => $event_item ) {
		$catalog_items[] = array(
			'@type'    => 'ListItem',
			'position' => $i + 1,
			'url'      => home_url( '/' . trim( (string) ( $event_item->slug ?? '' ), '/' ) . '/' ),
			'name'     => (string) ( $event_item->title ?? '' ),
		);
	}
	$ld_catalog = array(
		'@context'        => 'https://schema.org',
		'@type'           => 'OfferCatalog',
		'name'            => __( 'Servicios', 'agenda-lite' ),
		'itemListElement' => $catalog_items,
	);
	?>
	<script type="application/ld+json"><?php echo wp_json_encode( $ld_catalog, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ); ?></script>
	<?php
}
?>
