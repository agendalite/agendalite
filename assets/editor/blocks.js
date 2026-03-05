( function ( wp ) {
	if ( ! wp || ! wp.blocks || ! wp.element ) {
		return;
	}

	const registerBlockType = wp.blocks.registerBlockType;
	const el = wp.element.createElement;
	const __ = wp.i18n.__;
	const InspectorControls = ( wp.blockEditor && wp.blockEditor.InspectorControls ) || ( wp.editor && wp.editor.InspectorControls );
	const useBlockProps = wp.blockEditor && wp.blockEditor.useBlockProps ? wp.blockEditor.useBlockProps : null;
	const PanelBody = wp.components.PanelBody;
	const ToggleControl = wp.components.ToggleControl;
	const SelectControl = wp.components.SelectControl;
	const ComboboxControl = wp.components.ComboboxControl || wp.components.SelectControl;
	const services = Array.isArray( window.litecalBuilderBlocks && window.litecalBuilderBlocks.services ) ? window.litecalBuilderBlocks.services : [];
	const widgetsImageUrl = window.litecalBuilderBlocks && window.litecalBuilderBlocks.widgetsImageUrl ? window.litecalBuilderBlocks.widgetsImageUrl : '';
	const serviceOptions = services
		.filter( ( service ) => service && service.slug )
		.map( ( service ) => ( {
			value: service.slug,
			label: service.title || service.slug,
		} ) );
	const agendaLiteIcon = el(
		'svg',
		{
			viewBox: '0 0 512 512',
			xmlns: 'http://www.w3.org/2000/svg',
		},
		el( 'polygon', { fill: '#00d277', points: '288.9 506 467 101.1 499.4 350.4 288.9 506' } ),
		el( 'polygon', { fill: '#00d277', points: '215.4 6 12.6 244.7 467 101.1 215.4 6' } ),
		el( 'polygon', { fill: '#00d277', points: '288.9 506 240.6 296.8 12.6 244.7 112.1 392.2 288.9 506' } ),
		el( 'polygon', { fill: '#00d277', points: '302 365.9 386.9 172.9 181.6 237.8 277.5 259.7 302 365.9' } )
	);

	function getBlockProps( className ) {
		if ( ! useBlockProps ) {
			return { className: className };
		}
		return useBlockProps( { className: className } );
	}

	function serviceSelector( attributes, setAttributes ) {
		const props = {
			label: __( 'Servicio', 'agenda-lite' ),
			value: attributes.slug || '',
			onChange: function ( value ) {
				setAttributes( { slug: value || '' } );
			},
			options: serviceOptions,
			help: __( 'Busca y selecciona el servicio que quieres mostrar.', 'agenda-lite' ),
		};

		if ( ComboboxControl === SelectControl ) {
			return el( SelectControl, props );
		}

		return el( ComboboxControl, Object.assign( {}, props, { options: serviceOptions } ) );
	}

	function placeholderCard( title, rows ) {
		const media = widgetsImageUrl
			? el(
				'div',
				{ className: 'litecal-builder-placeholder__media' },
				el( 'img', {
					className: 'litecal-builder-placeholder__image',
					src: widgetsImageUrl,
					alt: __( 'Ilustracion de widgets', 'agenda-lite' ),
				} )
			)
			: null;

		return el(
			'div',
			{ className: 'litecal-builder-placeholder' },
			media,
			el( 'h3', { className: 'litecal-builder-placeholder__title' }, title ),
			el(
				'p',
				{ className: 'litecal-builder-placeholder__message' },
				__( 'Este widget muestra su contenido real en el frontend. Aquí solo se configuran sus opciones de visualización.', 'agenda-lite' )
			),
			el(
				'ul',
				{ className: 'litecal-builder-placeholder__list' },
				rows.map( ( row ) =>
					el(
						'li',
						{ className: 'litecal-builder-placeholder__item', key: row.label },
						el( 'span', { className: 'litecal-builder-placeholder__dot ' + ( row.enabled ? 'is-on' : 'is-off' ) } ),
						el( 'span', { className: 'litecal-builder-placeholder__text' }, row.label ),
						el( 'span', { className: 'litecal-builder-placeholder__state' }, row.enabled ? __( 'Activo', 'agenda-lite' ) : __( 'Oculto', 'agenda-lite' ) )
					)
				)
			)
		);
	}

	registerBlockType( 'agendalite/public-page', {
		apiVersion: 2,
		title: __( 'Página de servicios', 'agenda-lite' ),
		description: __( 'Listado público de servicios con buscador y filtros.', 'agenda-lite' ),
		icon: agendaLiteIcon,
		category: 'agendalite',
		attributes: {
			showDescription: {
				type: 'boolean',
				default: true,
			},
			showTitle: {
				type: 'boolean',
				default: true,
			},
			showCount: {
				type: 'boolean',
				default: true,
			},
			showSearch: {
				type: 'boolean',
				default: true,
			},
			showSort: {
				type: 'boolean',
				default: true,
			},
				showFilters: {
					type: 'boolean',
					default: true,
				},
				showPoweredBy: {
					type: 'boolean',
					default: true,
				},
			},
		edit: function ( props ) {
			const attributes = props.attributes;
			const blockProps = getBlockProps( 'litecal-builder-block litecal-builder-block--public-page' );
			return el(
				wp.element.Fragment,
				null,
				el(
					InspectorControls,
					null,
					el(
						PanelBody,
						{ title: __( 'Opciones', 'agenda-lite' ), initialOpen: true },
						el( ToggleControl, {
							label: __( 'Mostrar descripción', 'agenda-lite' ),
							checked: !! attributes.showDescription,
							onChange: function ( value ) {
								props.setAttributes( { showDescription: !! value } );
							},
						} ),
						el( ToggleControl, {
							label: __( 'Mostrar título', 'agenda-lite' ),
							checked: !! attributes.showTitle,
							onChange: function ( value ) {
								props.setAttributes( { showTitle: !! value } );
							},
						} ),
						el( ToggleControl, {
							label: __( 'Mostrar cantidad', 'agenda-lite' ),
							checked: !! attributes.showCount,
							onChange: function ( value ) {
								props.setAttributes( { showCount: !! value } );
							},
						} ),
						el( ToggleControl, {
							label: __( 'Mostrar buscador', 'agenda-lite' ),
							checked: !! attributes.showSearch,
							onChange: function ( value ) {
								props.setAttributes( { showSearch: !! value } );
							},
						} ),
						el( ToggleControl, {
							label: __( 'Mostrar orden', 'agenda-lite' ),
							checked: !! attributes.showSort,
							onChange: function ( value ) {
								props.setAttributes( { showSort: !! value } );
							},
						} ),
							el( ToggleControl, {
								label: __( 'Mostrar etiquetas de filtro', 'agenda-lite' ),
								checked: !! attributes.showFilters,
								onChange: function ( value ) {
									props.setAttributes( { showFilters: !! value } );
								},
							} ),
							el( ToggleControl, {
								label: __( 'Mostrar "Desarrollado por"', 'agenda-lite' ),
								checked: !! attributes.showPoweredBy,
								onChange: function ( value ) {
									props.setAttributes( { showPoweredBy: !! value } );
								},
							} )
						)
					),
				el(
					'div',
					blockProps,
					placeholderCard(
						__( 'Página de servicios', 'agenda-lite' ),
						[
							{ label: __( 'Mostrar título', 'agenda-lite' ), enabled: !! attributes.showTitle },
							{ label: __( 'Mostrar cantidad', 'agenda-lite' ), enabled: !! attributes.showCount },
								{ label: __( 'Mostrar buscador', 'agenda-lite' ), enabled: !! attributes.showSearch },
								{ label: __( 'Mostrar orden', 'agenda-lite' ), enabled: !! attributes.showSort },
								{ label: __( 'Mostrar etiquetas de filtro', 'agenda-lite' ), enabled: !! attributes.showFilters },
								{ label: __( 'Mostrar descripción', 'agenda-lite' ), enabled: !! attributes.showDescription },
								{ label: __( 'Mostrar "Desarrollado por"', 'agenda-lite' ), enabled: !! attributes.showPoweredBy },
							]
						)
					)
			);
		},
		save: function () {
			return null;
		},
	} );

	registerBlockType( 'agendalite/service-booking', {
		apiVersion: 2,
		title: __( 'Servicios individuales', 'agenda-lite' ),
		description: __( 'Reserva embebida de un servicio específico.', 'agenda-lite' ),
		icon: agendaLiteIcon,
		category: 'agendalite',
		attributes: {
			slug: {
				type: 'string',
				default: '',
			},
			showTimezone: {
				type: 'boolean',
				default: true,
			},
			showTimeFormat: {
				type: 'boolean',
				default: true,
			},
			showDescription: {
				type: 'boolean',
				default: true,
			},
			showPoweredBy: {
				type: 'boolean',
				default: true,
			},
		},
		edit: function ( props ) {
			const attributes = props.attributes;
			const blockProps = getBlockProps( 'litecal-builder-block litecal-builder-block--service-booking' );

			return el(
				wp.element.Fragment,
				null,
				el(
					InspectorControls,
					null,
					el(
						PanelBody,
						{ title: __( 'Servicio', 'agenda-lite' ), initialOpen: true },
						serviceSelector( attributes, props.setAttributes )
					),
					el(
						PanelBody,
						{ title: __( 'Visibilidad', 'agenda-lite' ), initialOpen: true },
						el( ToggleControl, {
							label: __( 'Mostrar zona horaria', 'agenda-lite' ),
							checked: !! attributes.showTimezone,
							onChange: function ( value ) {
								props.setAttributes( { showTimezone: !! value } );
							},
						} ),
						el( ToggleControl, {
							label: __( 'Mostrar formato de hora', 'agenda-lite' ),
							checked: !! attributes.showTimeFormat,
							onChange: function ( value ) {
								props.setAttributes( { showTimeFormat: !! value } );
							},
						} ),
						el( ToggleControl, {
							label: __( 'Mostrar descripción', 'agenda-lite' ),
							checked: !! attributes.showDescription,
							onChange: function ( value ) {
								props.setAttributes( { showDescription: !! value } );
							},
						} ),
						el( ToggleControl, {
							label: __( 'Mostrar "Desarrollado por"', 'agenda-lite' ),
							checked: !! attributes.showPoweredBy,
							onChange: function ( value ) {
								props.setAttributes( { showPoweredBy: !! value } );
							},
						} )
					)
				),
				el(
					'div',
					blockProps,
					placeholderCard(
						__( 'Servicios individuales', 'agenda-lite' ),
						[
							{ label: __( 'Mostrar zona horaria', 'agenda-lite' ), enabled: !! attributes.showTimezone },
							{ label: __( 'Mostrar formato de hora', 'agenda-lite' ), enabled: !! attributes.showTimeFormat },
							{ label: __( 'Mostrar descripción', 'agenda-lite' ), enabled: !! attributes.showDescription },
							{ label: __( 'Mostrar "Desarrollado por"', 'agenda-lite' ), enabled: !! attributes.showPoweredBy },
						]
					)
				)
			);
		},
		save: function () {
			return null;
		},
	} );
} )( window.wp );
