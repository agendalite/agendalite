# Changelog

## 1.0.0

Primera versión estable pública de Agenda Lite (Free) y empaquetado alineado para GitHub / WordPress.org.

### Agregado
- Sección **Clientes** en admin: historial agrupado por correo (nombre, email, teléfono, totales, última reserva) + exportación CSV.
- Popup de historial de cliente con reservas clickeables hacia calendario/backoffice cuando corresponde.
- Historial conserva trazabilidad de reservas eliminadas (marcadas visualmente como eliminadas).
- Sistema global **antiabuso**:
  - Límites por período.
  - Verificación por código al correo.
  - Bloqueo temporal automático.
  - Bloqueo manual permanente + desbloqueo manual.
  - Estados/filtros de clientes por condición de bloqueo.
- **Pago presencial** integrado (frontend, backend, recibo y estado), sin aplicar timeout de pago online.
- Widgets/bloques “builder-safe”: placeholder estable en editor y render real solo en frontend (Gutenberg, Elementor, WPBakery):
  - Página de servicios
  - Servicio individual

### Mejorado
- Compatibilidad de layout en filas/columnas (desktop/tablet/móvil) para widgets.
- Selector de zona horaria en frontend migrado a modal centrado con overlay/blur y animación suave.
- Popovers del calendario backend (“+N más” y click en evento) unificados al patrón modal centrado con overlay/blur.
- UX backend: botones críticos con estado de carga y bloqueo para evitar dobles envíos (reagendar / actualizar / confirmar).
- Exportaciones: normalización de “onsite” a “Presencial / Pago presencial”.
- Reservas gratis: ocultación de filas de pago no relevantes en recibo/correo.

### Corregido
- Servicios globales que no respondían correctamente cuando se usaban solos en frontend.
- Estilos faltantes en WPBakery y errores de escala/render en widget individual dentro de fila+columna.
- “Desarrollado por” sin logo en escenarios específicos (Elementor/front).
- Papelera/restauración de clientes: flujo estable; eliminar cliente ya no elimina reservas históricas.
- Seguridad/calidad para Plugin Check/PHPCS: sanitización, unslash, SQL preparado, escaping e i18n.
