(() => {
  const locationInput = document.querySelector('[data-lc-location-input]');
  const locationRadios = document.querySelectorAll('.lc-location-radio');
  const locationDetails = document.querySelectorAll('.lc-location-details-wrap');
  const scheduleSelect = document.querySelector('[data-lc-schedule-select]');
  const schedulePreview = document.querySelector('[data-lc-schedule-preview]');
  const avatarUploadBtn = document.querySelector('[data-lc-avatar-upload]');
  const avatarClearBtn = document.querySelector('[data-lc-avatar-clear]');
  const avatarInput = document.querySelector('[data-lc-avatar-input]');
  const avatarPreview = document.querySelector('.lc-media-preview');
  const seoImageUploadBtn = document.querySelector('[data-lc-seo-image-upload]');
  const seoImageClearBtn = document.querySelector('[data-lc-seo-image-clear]');
  const seoImageInput = document.querySelector('[data-lc-seo-image-input]');
  const seoImagePreview = document.querySelector('[data-lc-seo-image-preview]');
  const paymentsWrap = document.querySelector('[data-lc-payments-wrap]');
  const eventPaymentsList = document.querySelector('[data-lc-event-payments-list]');
  const priceModeSelect = document.querySelector('[data-lc-price-mode]');
  const priceWrap = document.querySelector('[data-lc-price-wrap]');
  const partialWrap = document.querySelector('[data-lc-partial-wrap]');
  const fixedWrap = document.querySelector('[data-lc-fixed-wrap]');
  const onsiteWrap = document.querySelector('[data-lc-onsite-wrap]');
  const allowGuestsInput = document.querySelector('[data-lc-allow-guests]');
  const guestsLimitWrap = document.querySelector('[data-lc-guests-limit-wrap]');
  const integrationOpeners = document.querySelectorAll('[data-lc-integration-open]');
  const integrationClosers = document.querySelectorAll('[data-lc-integration-close]');
  const rangeCalendar = document.querySelector('[data-lc-range-calendar]');
  const colorInputs = document.querySelectorAll('[data-lc-color-input]');
  const notyf = window.Notyf
    ? new window.Notyf({
        duration: 3200,
        position: { x: 'right', y: 'top' },
        dismissible: true,
      })
    : {
        success: (message) => window.alert(String(message || 'Operación completada.')),
        error: (message) => window.alert(String(message || 'Ocurrió un error.')),
      };
  const escHtmlUi = (value) =>
    String(value == null ? '' : value)
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;')
      .replace(/'/g, '&#39;');
  const escAttrUi = (value) => escHtmlUi(value);
  const escUrlUi = (value) => {
    const raw = String(value == null ? '' : value).trim();
    if (!raw) return '#';
    try {
      const parsed = new URL(raw, window.location.origin);
      if (parsed.protocol === 'http:' || parsed.protocol === 'https:') {
        return parsed.toString();
      }
    } catch (_) {
      return '#';
    }
    return '#';
  };

  const initLitecalColorPickers = () => {
    const syncColorValue = (input, forcedValue = null) => {
      const valueEl = input?.parentElement?.querySelector('[data-lc-color-value]');
      if (!valueEl) return;
      const raw = forcedValue != null ? String(forcedValue) : String(input?.value || '');
      valueEl.textContent = raw ? raw.toLowerCase() : '';
    };

    document.querySelectorAll('[data-lc-color-input]').forEach((input) => {
      const sync = () => {
        syncColorValue(input);
      };

      input.addEventListener('input', sync);
      input.addEventListener('change', sync);
      sync();

      const row = input.closest('.lc-color-row');
      if (!row || row.querySelector('.lc-pickr-mount')) {
        return;
      }

      const canUsePickr = typeof window.Pickr !== 'undefined' && window.Pickr && typeof window.Pickr.create === 'function';
      if (!canUsePickr) {
        return;
      }

      try {
        const mount = document.createElement('span');
        mount.className = 'lc-pickr-mount';
        row.insertBefore(mount, input);

        const defaultValue = input.getAttribute('data-default-color') || '#083a53';
        const picker = window.Pickr.create({
          el: mount,
          theme: 'nano',
          default: input.value || defaultValue,
          useAsButton: true,
          swatches: [defaultValue, '#ffffff', '#083a53', '#fb6514', '#0f172a'],
          components: {
            preview: true,
            opacity: false,
            hue: true,
            interaction: {
              hex: true,
              input: true,
              clear: true,
              save: true,
            },
          },
        });

        const applyColor = (color, fallback = '') => {
          const next = color && typeof color.toHEXA === 'function'
            ? color.toHEXA().toString()
            : (fallback || defaultValue);
          input.value = next;
          syncColorValue(input, next);
          mount.style.setProperty('--lc-pickr-color', next);
        };

        picker.on('init', (instance) => {
          applyColor(instance.getColor(), input.value || defaultValue);
        });
        picker.on('save', (color, instance) => {
          applyColor(color, input.value || defaultValue);
          instance.hide();
        });
        picker.on('change', (color) => {
          applyColor(color, input.value || defaultValue);
        });
        picker.on('clear', (instance) => {
          applyColor(null, defaultValue);
          instance.hide();
        });
      } catch (_err) {
        // Keep the manual hex input usable if Pickr fails to initialize.
      }
    });
  };

  initLitecalColorPickers();

  document.querySelectorAll('[data-lc-admin-notice]').forEach((el) => {
    const type = (el.getAttribute('data-lc-admin-notice') || 'success').toLowerCase();
    const message = el.getAttribute('data-lc-admin-notice-message') || '';
    if (!message) return;
    if (type === 'error') {
      notyf.error(message);
    } else {
      notyf.success(message);
    }
  });

  const initTooltips = () => {
    const nodes = document.querySelectorAll('[data-lc-tooltip-content]');
    if (!nodes.length) return;
    const canUseTippy = typeof window.tippy === 'function';
    nodes.forEach((el) => {
      const content = String(el.getAttribute('data-lc-tooltip-content') || '').trim();
      if (!content) return;
      const clickTooltip = el.classList.contains('lc-event-owner-more');
      if (canUseTippy) {
        el.classList.remove('lc-tooltip-fallback');
        el.removeAttribute('title');
        if (el._tippy) {
          el._tippy.destroy();
        }
        try {
          window.tippy(el, {
            content,
            placement: 'right-end',
            allowHTML: false,
            interactive: false,
            theme: 'light-border',
            trigger: clickTooltip ? 'mouseenter focus click' : 'mouseenter focus',
            appendTo: () => document.body,
            delay: [120, 0],
          });
        } catch (_err) {
          el.classList.add('lc-tooltip-fallback');
          el.setAttribute('title', content);
        }
      } else {
        el.classList.add('lc-tooltip-fallback');
        el.setAttribute('title', content);
      }
    });
  };
  initTooltips();

  const tableSelectionStore = new WeakMap();
  const getTableSelection = (table) => {
    if (!table) return new Set();
    if (!tableSelectionStore.has(table)) {
      tableSelectionStore.set(table, new Set());
    }
    return tableSelectionStore.get(table);
  };

  if (window.gridjs && window.gridjs.Grid) {
    const gridHtml = window.gridjs.html;
    const normalizeGridId = (raw) => {
      const text = String(raw == null ? '' : raw).trim();
      if (!text) return '';
      const numeric = text.match(/^#?(\d+)$/);
      if (numeric) {
        return numeric[1];
      }
      return text;
    };
    document.querySelectorAll('[data-lc-gridjs]').forEach((gridEl) => {
      const type = gridEl.getAttribute('data-lc-gridjs') || '';
      const card = gridEl.closest('.lc-card, .lc-panel') || document;
      const searchInput = card.querySelector('[data-lc-grid-search]');
      const statusSelect = card.querySelector('[data-lc-grid-status]');
      const sizeSelect = card.querySelector('[data-lc-grid-page-size]');
      const bulkForm = card.querySelector('[data-lc-bulk-form]');
      const bulkIdsInput = bulkForm ? bulkForm.querySelector('[data-lc-bulk-ids]') : null;
      const deleteForm = card.querySelector('[data-lc-delete-form]');
      const deleteIdsInput = deleteForm ? deleteForm.querySelector('[data-lc-delete-ids]') : null;
      const ajaxUrl = gridEl.getAttribute('data-lc-grid-url') || '';
      const ajaxAction = gridEl.getAttribute('data-lc-grid-action') || '';
      const ajaxNonce = gridEl.getAttribute('data-lc-grid-nonce') || '';
      const initialSize = parseInt(gridEl.getAttribute('data-lc-grid-page-size') || '10', 10);
      const staffLimited = gridEl.getAttribute('data-lc-grid-staff-limited') === '1';
      const gridView = String(gridEl.getAttribute('data-lc-grid-view') || 'active').trim() === 'trash' ? 'trash' : 'active';
      const selection = getTableSelection(gridEl);
      const state = {
        page: 1,
        limit: [10, 25, 50, 100].includes(initialSize) ? initialSize : 10,
        search: searchInput ? String(searchInput.value || '').trim() : '',
        status: statusSelect ? String(statusSelect.value || '').trim() : '',
        view: gridView,
        sortBy: 'id',
        sortDir: 'desc',
      };

      const sortFieldMap = type === 'payments'
        ? {
            2: 'id',
            3: 'client',
            4: 'event',
            5: 'payment_date',
            6: 'payment_date',
            7: 'provider',
            8: 'amount',
            9: 'payment_status',
            10: 'reference',
          }
        : type === 'customers'
          ? {
              2: 'name',
              3: 'name',
              4: 'email',
              5: 'phone',
              7: 'total_bookings',
              8: 'last_booking_at',
            }
          : {
              2: 'id',
              3: 'client',
              4: 'event',
              5: 'attendee',
              6: 'start_datetime',
              7: 'status',
              8: 'payment_status',
              9: 'payment_amount',
              10: 'payment_date',
            };

      const buildUrl = (overrides = {}) => {
        const params = new URLSearchParams();
        params.set('action', ajaxAction);
        params.set('_wpnonce', ajaxNonce);
        params.set('page', String(overrides.page || state.page));
        params.set('limit', String(overrides.limit || state.limit));
        params.set('search', overrides.search != null ? String(overrides.search) : state.search);
        params.set('status', overrides.status != null ? String(overrides.status) : state.status);
        params.set('view', overrides.view != null ? String(overrides.view) : state.view);
        params.set('sortBy', overrides.sortBy || state.sortBy);
        params.set('sortDir', overrides.sortDir || state.sortDir);
        return `${ajaxUrl}?${params.toString()}`;
      };

      const fetchHandle = (response) => {
        if (!response.ok) throw new Error('http_error');
        return response.json();
      };
      const parseRows = (payload) => {
        if (!payload?.success) throw new Error('bad_payload');
        return Array.isArray(payload?.data?.rows) ? payload.data.rows : [];
      };
      const parseTotal = (payload) => Number(payload?.data?.total || 0);

      const paginationServerUrl = (prev, page, limit) => {
        state.page = (Number(page) || 0) + 1;
        state.limit = [10, 25, 50, 100].includes(Number(limit)) ? Number(limit) : state.limit;
        return buildUrl();
      };

      const sortServerUrl = (prev, columns) => {
        if (Array.isArray(columns) && columns.length) {
          const next = columns[0];
          const mapped = sortFieldMap[next.index];
          if (mapped) {
            state.sortBy = mapped;
            state.sortDir = next.direction === 1 || next.direction === 'asc' ? 'asc' : 'desc';
          }
        } else {
          state.sortBy = 'id';
          state.sortDir = 'desc';
        }
        state.page = 1;
        return buildUrl();
      };

      const htmlCell = (cell) => gridHtml(String(cell == null ? '' : cell));
      const checkboxHeader = gridHtml('<input type="checkbox" data-lc-grid-select-all aria-label="Seleccionar todo">');
      const rowCheckFormatter = (_, row) => {
        let idRaw = '';
        const cells = Array.isArray(row?.cells) ? row.cells : [];
        for (let i = 0; i < cells.length; i += 1) {
          const normalized = normalizeGridId(cells[i]?.data);
          if (normalized) {
            idRaw = normalized;
            break;
          }
        }
        if (!idRaw) return '';
        const checked = selection.has(idRaw) ? ' checked' : '';
        return gridHtml(`<input type="checkbox" data-lc-grid-row-check value="${escAttrUi(idRaw)}"${checked}>`);
      };

      const columns = type === 'payments'
        ? [
            { id: 'id_raw', hidden: true },
            { name: checkboxHeader, sort: false, width: '42px', formatter: rowCheckFormatter },
            { id: 'id', name: 'ID', sort: true, formatter: htmlCell },
            { id: 'client', name: 'Cliente', sort: true, formatter: htmlCell },
            { id: 'event', name: 'Servicio', sort: true, formatter: htmlCell },
            { id: 'date', name: 'Fecha', sort: true, formatter: htmlCell },
            { id: 'time', name: 'Hora', sort: true, formatter: htmlCell },
            { id: 'provider', name: 'Proveedor', sort: true, formatter: htmlCell },
            { id: 'amount', name: 'Monto', sort: true, formatter: htmlCell },
            { id: 'status', name: 'Estado', sort: true, formatter: htmlCell },
            { id: 'reference', name: 'Referencia', sort: true, formatter: htmlCell },
            { id: 'receipt', name: 'Recibo', sort: false, formatter: htmlCell, hidden: staffLimited },
            { id: 'delete', name: 'Eliminar', sort: false, formatter: htmlCell },
          ]
        : type === 'customers'
          ? [
              { id: 'id_raw', hidden: true },
              { name: checkboxHeader, sort: false, width: '42px', formatter: rowCheckFormatter },
              { id: 'first_name', name: 'Nombre', sort: true, formatter: htmlCell },
              { id: 'last_name', name: 'Apellido', sort: true, formatter: htmlCell },
              { id: 'email', name: 'Correo', sort: true, formatter: htmlCell },
              { id: 'phone', name: 'Teléfono', sort: true, formatter: htmlCell },
              { id: 'abuse_status', name: 'Estado', sort: false, formatter: htmlCell },
              { id: 'total_bookings', name: 'Reservas', sort: true, formatter: htmlCell },
              { id: 'last_booking', name: 'Última reserva', sort: true, formatter: htmlCell },
              { id: 'delete', name: gridView === 'trash' ? 'Acciones' : 'Eliminar', sort: false, formatter: htmlCell, hidden: staffLimited },
            ]
        : [
            { id: 'id_raw', hidden: true },
            { name: checkboxHeader, sort: false, width: '42px', formatter: rowCheckFormatter },
            { id: 'id', name: 'ID', sort: true, formatter: htmlCell },
            { id: 'client', name: 'Cliente', sort: true, formatter: htmlCell },
            { id: 'event', name: 'Servicio', sort: true, formatter: htmlCell },
            { id: 'attendee', name: 'Profesional', sort: true, formatter: htmlCell },
            { id: 'date_time', name: 'Fecha reserva', sort: true, formatter: htmlCell },
            { id: 'status', name: 'Estado reserva', sort: true, formatter: htmlCell },
            { id: 'payment', name: 'Estado pago', sort: true, formatter: htmlCell, hidden: staffLimited },
            { id: 'amount', name: 'Monto', sort: true, formatter: htmlCell, hidden: staffLimited },
            { id: 'payment_date_time', name: 'Fecha pago', sort: true, formatter: htmlCell, hidden: staffLimited },
            { id: 'calendar', name: 'Calendario', sort: false, formatter: htmlCell, hidden: gridView === 'trash' },
            { id: 'receipt', name: 'Recibo', sort: false, formatter: htmlCell, hidden: staffLimited },
            { id: 'delete', name: gridView === 'trash' ? 'Acciones' : 'Eliminar', sort: false, formatter: htmlCell, hidden: staffLimited },
          ];

      const grid = new window.gridjs.Grid({
        columns,
        search: false,
        sort: {
          multiColumn: false,
          server: { url: sortServerUrl },
        },
        pagination: {
          enabled: true,
          limit: state.limit,
          server: { url: paginationServerUrl },
        },
        server: {
          url: buildUrl(),
          handle: fetchHandle,
          then: parseRows,
          total: parseTotal,
        },
        className: {
          container: 'lc-gridjs-container',
          table: 'lc-gridjs-table',
        },
        language: {
          loading: 'Cargando...',
          noRecordsFound: 'Sin resultados',
          error: 'Error al cargar los datos',
          pagination: {
            previous: 'Anterior',
            next: 'Siguiente',
          },
        },
      });

      grid.render(gridEl);

      const syncVisibleSelection = () => {
        const rowChecks = gridEl.querySelectorAll('[data-lc-grid-row-check]');
        let checked = 0;
        rowChecks.forEach((input) => {
          const id = String(input.value || '').trim();
          input.checked = !!id && selection.has(id);
          if (input.checked) checked += 1;
        });
        const allCheck = gridEl.querySelector('[data-lc-grid-select-all]');
        if (allCheck) {
          allCheck.checked = rowChecks.length > 0 && checked === rowChecks.length;
          allCheck.indeterminate = checked > 0 && checked < rowChecks.length;
        }
      };

      const refreshGrid = (resetPage = false) => {
        if (resetPage) state.page = 1;
        grid
          .updateConfig({
            pagination: {
              enabled: true,
              limit: state.limit,
              server: { url: paginationServerUrl },
            },
            server: {
              url: buildUrl(),
              handle: fetchHandle,
              then: parseRows,
              total: parseTotal,
            },
          })
          .forceRender();
      };

      let tooltipRefreshTimer = null;
      const observer = new MutationObserver(() => {
        syncVisibleSelection();
        if (tooltipRefreshTimer) {
          window.clearTimeout(tooltipRefreshTimer);
        }
        tooltipRefreshTimer = window.setTimeout(() => {
          initTooltips();
        }, 0);
      });
      observer.observe(gridEl, { childList: true, subtree: true });

      let searchTimer = null;
      if (searchInput) {
        searchInput.addEventListener('input', () => {
          if (searchTimer) window.clearTimeout(searchTimer);
          searchTimer = window.setTimeout(() => {
            state.search = String(searchInput.value || '').trim();
            refreshGrid(true);
          }, 220);
        });
      }
      if (statusSelect) {
        statusSelect.addEventListener('change', () => {
          state.status = String(statusSelect.value || '').trim();
          card.querySelectorAll('[data-lc-grid-export-status]').forEach((input) => {
            input.value = state.status;
          });
          refreshGrid(true);
        });
      }
      if (sizeSelect) {
        sizeSelect.addEventListener('change', () => {
          const next = parseInt(sizeSelect.value || '10', 10);
          state.limit = [10, 25, 50, 100].includes(next) ? next : 10;
          refreshGrid(true);
        });
      }

      if (bulkForm && bulkIdsInput) {
        bulkForm.addEventListener('submit', (event) => {
          const selectedFromDom = Array.from(gridEl.querySelectorAll('[data-lc-grid-row-check]:checked'))
            .map((input) => normalizeGridId(input.value || ''))
            .filter(Boolean);
          const selectedFromState = Array.from(selection).map((id) => normalizeGridId(id)).filter(Boolean);
          const selected = Array.from(new Set([...selectedFromDom, ...selectedFromState]));
          if (!selected.length) {
            event.preventDefault();
            return;
          }
          const bulkSelect = bulkForm.querySelector('select[name="bulk_status"]');
          if (bulkSelect && (bulkSelect.value === 'delete' || bulkSelect.value === 'delete_permanent')) {
            event.preventDefault();
            const isPermanentDelete = bulkSelect.value === 'delete_permanent' || gridView === 'trash';
            const confirmOptions = isPermanentDelete
              ? permanentDeleteConfirmOptions(true, type)
              : trashConfirmOptions(true, type);
            openConfirmModal({
              ...confirmOptions,
              onConfirm: () => {
                bulkIdsInput.value = selected.join(',');
                bulkForm.submit();
              },
            });
            return;
          }
          bulkIdsInput.value = selected.join(',');
        });
      }

      if (deleteForm && deleteIdsInput) {
        gridEl.addEventListener('click', (event) => {
          const btn = event.target.closest('[data-lc-delete-id]');
          if (!btn) return;
          const id = String(btn.getAttribute('data-lc-delete-id') || '').trim();
          if (!id) return;
          deleteIdsInput.value = id;
          const confirmOptions = gridView === 'trash'
            ? permanentDeleteConfirmOptions(false, type)
            : trashConfirmOptions(false, type);
          openConfirmModal({
            ...confirmOptions,
            onConfirm: () => {
              deleteForm.submit();
            },
          });
        });
      }

      gridEl.addEventListener('change', (event) => {
        const rowCheck = event.target.closest('[data-lc-grid-row-check]');
        if (rowCheck) {
          const id = normalizeGridId(rowCheck.value || '');
          if (id) {
            if (rowCheck.checked) selection.add(id);
            else selection.delete(id);
          }
          syncVisibleSelection();
          return;
        }
        const allCheck = event.target.closest('[data-lc-grid-select-all]');
        if (!allCheck) return;
        gridEl.querySelectorAll('[data-lc-grid-row-check]').forEach((input) => {
          const id = normalizeGridId(input.value || '');
          if (!id) return;
          input.checked = allCheck.checked;
          if (allCheck.checked) selection.add(id);
          else selection.delete(id);
        });
        syncVisibleSelection();
      });
    });
  }

  const analyticsRoot = document.querySelector('[data-lc-analytics-root]');
  if (analyticsRoot && window.litecalAnalytics) {
    const cfg = window.litecalAnalytics || {};
    const restBase = String(cfg.restBase || '').replace(/\/$/, '');
    const restNonce = String(cfg.nonce || '');
    const customRangeWrap = analyticsRoot.querySelector('[data-lc-an-custom-range]');
    const refreshBtn = analyticsRoot.querySelector('[data-lc-an-refresh]');
    const exportForm = analyticsRoot.querySelector('[data-lc-an-export-form]');
    const exportInputs = analyticsRoot.querySelectorAll('[data-lc-an-export]');
    const filterEls = {
      preset: analyticsRoot.querySelector('[data-lc-an-filter="preset"]'),
      date_from: analyticsRoot.querySelector('[data-lc-an-filter="date_from"]'),
      date_to: analyticsRoot.querySelector('[data-lc-an-filter="date_to"]'),
      group_by: analyticsRoot.querySelector('[data-lc-an-filter="group_by"]'),
      event_id: analyticsRoot.querySelector('[data-lc-an-filter="event_id"]'),
      employee_id: analyticsRoot.querySelector('[data-lc-an-filter="employee_id"]'),
      booking_status: analyticsRoot.querySelector('[data-lc-an-filter="booking_status"]'),
      payment_status: analyticsRoot.querySelector('[data-lc-an-filter="payment_status"]'),
      payment_provider: analyticsRoot.querySelector('[data-lc-an-filter="payment_provider"]'),
    };
    const kpiEls = {
      total: analyticsRoot.querySelector('[data-lc-an-kpi="total"]'),
      confirmed: analyticsRoot.querySelector('[data-lc-an-kpi="confirmed"]'),
      pending: analyticsRoot.querySelector('[data-lc-an-kpi="pending"]'),
      cancelled: analyticsRoot.querySelector('[data-lc-an-kpi="cancelled"]'),
      rescheduled: analyticsRoot.querySelector('[data-lc-an-kpi="rescheduled"]'),
      trashed: analyticsRoot.querySelector('[data-lc-an-kpi="trashed"]'),
      revenue: analyticsRoot.querySelector('[data-lc-an-kpi="revenue"]'),
      ticket_avg: analyticsRoot.querySelector('[data-lc-an-kpi="ticket_avg"]'),
    };
    const chartEls = {
      bookings: analyticsRoot.querySelector('[data-lc-an-chart="bookings"]'),
      revenue: analyticsRoot.querySelector('[data-lc-an-chart="revenue"]'),
    };
    const chartStateEls = {
      bookings: analyticsRoot.querySelector('[data-lc-an-chart-state="bookings"]'),
      revenue: analyticsRoot.querySelector('[data-lc-an-chart-state="revenue"]'),
    };

    if (restBase && restNonce) {
      const labels = cfg?.labels || {};
      const chartInstances = { bookings: null, revenue: null };
      let analyticsCurrency = 'CLP';
      let analyticsAbortController = null;
      let analyticsDebounceTimer = null;

      const pad2 = (n) => String(n).padStart(2, '0');
      const dateToIso = (dateObj) => `${dateObj.getFullYear()}-${pad2(dateObj.getMonth() + 1)}-${pad2(dateObj.getDate())}`;

      const setDateRangeByPreset = () => {
        const preset = filterEls.preset ? String(filterEls.preset.value || 'last7') : 'last7';
        const now = new Date();
        const today = new Date(now.getFullYear(), now.getMonth(), now.getDate());
        let from = new Date(today);
        let to = new Date(today);
        const isCustom = preset === 'custom';
        if (customRangeWrap) {
          customRangeWrap.hidden = !isCustom;
        }
        if (isCustom) {
          if (filterEls.date_from) filterEls.date_from.disabled = false;
          if (filterEls.date_to) filterEls.date_to.disabled = false;
          return;
        }
        if (preset === 'last7') {
          from.setDate(from.getDate() - 6);
        } else if (preset === 'last30') {
          from.setDate(from.getDate() - 29);
        } else if (preset === 'this_month') {
          from = new Date(today.getFullYear(), today.getMonth(), 1);
          to = new Date(today.getFullYear(), today.getMonth() + 1, 0);
        } else if (preset === 'this_year') {
          from = new Date(today.getFullYear(), 0, 1);
          to = new Date(today.getFullYear(), 11, 31);
        } else if (preset === 'prev_month') {
          from = new Date(today.getFullYear(), today.getMonth() - 1, 1);
          to = new Date(today.getFullYear(), today.getMonth(), 0);
        }
        if (filterEls.date_from) {
          filterEls.date_from.disabled = true;
          filterEls.date_from.value = dateToIso(from);
        }
        if (filterEls.date_to) {
          filterEls.date_to.disabled = true;
          filterEls.date_to.value = dateToIso(to);
        }
      };

      const getFilters = () => ({
        date_from: filterEls.date_from ? String(filterEls.date_from.value || '') : '',
        date_to: filterEls.date_to ? String(filterEls.date_to.value || '') : '',
        group_by: filterEls.group_by ? String(filterEls.group_by.value || 'day') : 'day',
        event_id: filterEls.event_id ? String(filterEls.event_id.value || '0') : '0',
        employee_id: filterEls.employee_id ? String(filterEls.employee_id.value || '0') : '0',
        booking_status: filterEls.booking_status ? String(filterEls.booking_status.value || '') : '',
        payment_status: filterEls.payment_status ? String(filterEls.payment_status.value || '') : '',
        payment_provider: filterEls.payment_provider ? String(filterEls.payment_provider.value || '') : '',
      });

      const buildFilterQuery = () => {
        const params = new URLSearchParams();
        const filters = getFilters();
        Object.keys(filters).forEach((key) => params.set(key, String(filters[key] || '')));
        return params;
      };

      const apiGet = async (path, signal = null, force = false) => {
        const query = buildFilterQuery();
        if (force) {
          query.set('no_cache', '1');
          query.set('_ts', String(Date.now()));
        }
        const response = await fetch(`${restBase}/${path}?${query.toString()}`, {
          method: 'GET',
          credentials: 'same-origin',
          headers: {
            'X-WP-Nonce': restNonce,
          },
          signal,
        });
        const json = await response.json();
        if (!response.ok || !json || json.status !== 'ok') {
          throw new Error('api_error');
        }
        return json;
      };

      const formatMoney = (value, currency = analyticsCurrency) => {
        const number = Number(value || 0);
        if (!Number.isFinite(number)) return '$0';
        const code = String(currency || 'CLP').toUpperCase();
        const zeroDecimal = ['CLP', 'ARS', 'COP', 'MXN', 'PEN'].includes(code);
        const formatted = number.toLocaleString('es-CL', {
          minimumFractionDigits: zeroDecimal ? 0 : 2,
          maximumFractionDigits: zeroDecimal ? 0 : 2,
        });
        return `$${formatted}`;
      };

      const applySummary = (payload) => {
        const kpis = payload?.kpis || {};
        analyticsCurrency = String(kpis.revenue_currency || analyticsCurrency || 'CLP').toUpperCase();
        if (kpiEls.total) kpiEls.total.textContent = String(kpis.total || 0);
        if (kpiEls.confirmed) kpiEls.confirmed.textContent = String(kpis.confirmed || 0);
        if (kpiEls.rescheduled) kpiEls.rescheduled.textContent = String(kpis.rescheduled || 0);
        if (kpiEls.pending) kpiEls.pending.textContent = String(kpis.pending || 0);
        if (kpiEls.cancelled) kpiEls.cancelled.textContent = String(kpis.cancelled || 0);
        if (kpiEls.trashed) kpiEls.trashed.textContent = String(kpis.trashed || 0);
        if (kpiEls.revenue) kpiEls.revenue.textContent = formatMoney(kpis.revenue || 0, analyticsCurrency);
        if (kpiEls.ticket_avg) kpiEls.ticket_avg.textContent = formatMoney(kpis.ticket_avg || 0, analyticsCurrency);
      };

      const syncExportInputs = () => {
        if (!exportInputs.length) return;
        const query = buildFilterQuery();
        exportInputs.forEach((input) => {
          const key = input.getAttribute('data-lc-an-export') || '';
          input.value = query.get(key) || '';
        });
      };

      const setChartState = (key, mode, message = '') => {
        const node = chartStateEls[key];
        if (!node) return;
        node.classList.remove('is-loading', 'is-error', 'is-empty');
        if (mode === 'hide') {
          node.textContent = '';
          node.setAttribute('hidden', 'hidden');
          return;
        }
        node.textContent = message;
        node.removeAttribute('hidden');
        if (mode === 'loading') node.classList.add('is-loading');
        if (mode === 'error') node.classList.add('is-error');
        if (mode === 'empty') node.classList.add('is-empty');
      };

      const ensureChart = (key, isMoney = false) => {
        if (chartInstances[key] || !window.Chart || !chartEls[key]) return chartInstances[key];
        const ctx = chartEls[key].getContext('2d');
        if (!ctx) return null;
        if (key === 'bookings') {
          chartInstances[key] = new window.Chart(ctx, {
            type: 'bar',
            data: {
              labels: [],
              datasets: [
                {
                  label: 'Confirmadas',
                  data: [],
                  backgroundColor: 'rgba(34,197,94,0.65)',
                  borderColor: '#22c55e',
                  borderWidth: 1,
                  borderRadius: 6,
                  maxBarThickness: 34,
                },
                {
                  label: 'Pendientes',
                  data: [],
                  backgroundColor: 'rgba(245,158,11,0.65)',
                  borderColor: '#f59e0b',
                  borderWidth: 1,
                  borderRadius: 6,
                  maxBarThickness: 34,
                },
                {
                  label: 'Canceladas',
                  data: [],
                  backgroundColor: 'rgba(239,68,68,0.65)',
                  borderColor: '#ef4444',
                  borderWidth: 1,
                  borderRadius: 6,
                  maxBarThickness: 34,
                },
                {
                  label: 'Reagendadas',
                  data: [],
                  backgroundColor: 'rgba(107,114,128,0.65)',
                  borderColor: '#6b7280',
                  borderWidth: 1,
                  borderRadius: 6,
                  maxBarThickness: 34,
                },
              ],
            },
            options: {
              responsive: true,
              maintainAspectRatio: false,
              animation: false,
              plugins: {
                legend: { display: true, position: 'top' },
              },
              scales: {
                x: {
                  stacked: true,
                  grid: { color: 'rgba(15,23,42,0.05)' },
                  ticks: { color: '#64748b', maxRotation: 0, autoSkip: true },
                },
                y: {
                  stacked: true,
                  beginAtZero: true,
                  grid: { color: 'rgba(15,23,42,0.08)' },
                  ticks: { color: '#64748b' },
                },
              },
            },
          });
          return chartInstances[key];
        }
        chartInstances[key] = new window.Chart(ctx, {
          type: 'bar',
          data: {
            labels: [],
            datasets: [{
              data: [],
              borderColor: key === 'revenue' ? '#0ea5e9' : '#16a34a',
              backgroundColor: key === 'revenue' ? 'rgba(14,165,233,0.55)' : 'rgba(22,163,74,0.55)',
              borderWidth: 1,
              borderRadius: 6,
              maxBarThickness: 34,
            }],
          },
          options: {
            responsive: true,
            maintainAspectRatio: false,
            animation: false,
            plugins: {
              legend: { display: false },
              tooltip: {
                callbacks: {
                  label: (ctxTooltip) => {
                    const val = Number(ctxTooltip.raw || 0);
                    return isMoney ? formatMoney(val, analyticsCurrency) : `${val}`;
                  },
                },
              },
            },
            scales: {
              x: {
                grid: { color: 'rgba(15,23,42,0.05)' },
                ticks: { color: '#64748b', maxRotation: 0, autoSkip: true },
              },
              y: {
                beginAtZero: true,
                grid: { color: 'rgba(15,23,42,0.08)' },
                ticks: {
                  color: '#64748b',
                  callback: (value) => (isMoney ? formatMoney(value, analyticsCurrency) : `${value}`),
                },
              },
            },
          },
        });
        return chartInstances[key];
      };

      const updateBookingsChart = (labelsArr, series) => {
        const chart = ensureChart('bookings', false);
        if (!chart) return;
        chart.data.labels = labelsArr;
        chart.data.datasets[0].data = series.confirmed || [];
        chart.data.datasets[1].data = series.pending || [];
        chart.data.datasets[2].data = series.cancelled || [];
        chart.data.datasets[3].data = series.rescheduled || [];
        chart.update('none');
      };

      const updateRevenueChart = (labelsArr, values) => {
        const chart = ensureChart('revenue', true);
        if (!chart) return;
        chart.data.labels = labelsArr;
        chart.data.datasets[0].data = values || [];
        chart.update('none');
      };

      const clearChart = (key) => {
        if (key === 'bookings') {
          updateBookingsChart([], { confirmed: [], pending: [], cancelled: [], rescheduled: [] });
          return;
        }
        updateRevenueChart([], []);
      };

      const normalizeSeriesPayload = (seriesJson) => {
        const labelsArr = Array.isArray(seriesJson?.labels) ? seriesJson.labels.map((v) => String(v || '')) : [];
        const labelsRevenueArr = Array.isArray(seriesJson?.labels_revenue)
          ? seriesJson.labels_revenue.map((v) => String(v || ''))
          : labelsArr;
        const bookings = Array.isArray(seriesJson?.series?.bookings) ? seriesJson.series.bookings.map((v) => Number(v || 0)) : [];
        const confirmed = Array.isArray(seriesJson?.series?.confirmed) ? seriesJson.series.confirmed.map((v) => Number(v || 0)) : [];
        const pending = Array.isArray(seriesJson?.series?.pending) ? seriesJson.series.pending.map((v) => Number(v || 0)) : [];
        const cancelled = Array.isArray(seriesJson?.series?.cancelled) ? seriesJson.series.cancelled.map((v) => Number(v || 0)) : [];
        const rescheduled = Array.isArray(seriesJson?.series?.rescheduled) ? seriesJson.series.rescheduled.map((v) => Number(v || 0)) : [];
        const revenue = Array.isArray(seriesJson?.series?.revenue) ? seriesJson.series.revenue.map((v) => Number(v || 0)) : [];
        return { labels: labelsArr, labelsRevenue: labelsRevenueArr, bookings, confirmed, pending, cancelled, rescheduled, revenue };
      };

      const refreshSummaryAndSeries = async (force = false) => {
        if (analyticsAbortController) {
          analyticsAbortController.abort();
        }
        analyticsAbortController = new AbortController();
        const { signal } = analyticsAbortController;
        setChartState('bookings', 'loading', labels.loading || 'Cargando...');
        setChartState('revenue', 'loading', labels.loading || 'Cargando...');
        try {
          const [summaryJson, seriesJson] = await Promise.all([
            apiGet('summary', signal, force),
            apiGet('timeseries', signal, force),
          ]);
          if (signal.aborted) return;
          applySummary(summaryJson || {});
          const parsed = normalizeSeriesPayload(seriesJson || {});
          const hasBookingData = parsed.labels.length > 0;
          const hasRevenueData = parsed.labelsRevenue.length > 0;

          if (!hasBookingData) {
            clearChart('bookings');
            setChartState('bookings', 'empty', labels.empty || 'Sin resultados');
          } else {
            updateBookingsChart(parsed.labels, {
              confirmed: parsed.confirmed,
              pending: parsed.pending,
              cancelled: parsed.cancelled,
              rescheduled: parsed.rescheduled,
            });
            setChartState('bookings', 'hide');
          }

          if (!hasRevenueData) {
            clearChart('revenue');
            setChartState('revenue', 'empty', labels.empty || 'Sin resultados');
          } else {
            updateRevenueChart(parsed.labelsRevenue, parsed.revenue);
            setChartState('revenue', 'hide');
          }
        } catch (error) {
          if (error && error.name === 'AbortError') return;
          clearChart('bookings');
          clearChart('revenue');
          setChartState('bookings', 'error', labels.error || 'Error al cargar');
          setChartState('revenue', 'error', labels.error || 'Error al cargar');
          notyf.error(labels.error || 'Error al cargar');
        }
      };

      const scheduleAnalyticsRefresh = () => {
        if (analyticsDebounceTimer) {
          window.clearTimeout(analyticsDebounceTimer);
        }
        analyticsDebounceTimer = window.setTimeout(() => {
          refreshSummaryAndSeries();
          syncExportInputs();
        }, 320);
      };

      if (filterEls.preset) {
        filterEls.preset.addEventListener('change', () => {
          setDateRangeByPreset();
          scheduleAnalyticsRefresh();
        });
      }
      Object.keys(filterEls).forEach((key) => {
        const el = filterEls[key];
        if (!el || key === 'preset') return;
        const handler = () => {
          if ((key === 'date_from' || key === 'date_to') && filterEls.preset && filterEls.preset.value !== 'custom') {
            return;
          }
          scheduleAnalyticsRefresh();
        };
        el.addEventListener('change', handler);
      });
      if (exportForm) {
        exportForm.addEventListener('submit', () => {
          syncExportInputs();
        });
      }
      if (refreshBtn) {
        refreshBtn.addEventListener('click', () => {
          refreshSummaryAndSeries(true);
          syncExportInputs();
        });
      }

      setDateRangeByPreset();
      refreshSummaryAndSeries();
      syncExportInputs();
    }
  }

  const templateBuilders = document.querySelectorAll('[data-lc-template-builder]');
  if (templateBuilders.length) {
    const caretState = new WeakMap();
    const insertTemplateToken = (field, token) => {
      if (!field || field.disabled || field.readOnly) return;
      const text = String(token || '');
      const caret = caretState.get(field) || {};
      const start = Number.isInteger(caret.start)
        ? caret.start
        : (typeof field.selectionStart === 'number' ? field.selectionStart : field.value.length);
      const end = Number.isInteger(caret.end)
        ? caret.end
        : (typeof field.selectionEnd === 'number' ? field.selectionEnd : field.value.length);
      const value = String(field.value || '');
      field.value = `${value.slice(0, start)}${text}${value.slice(end)}`;
      const nextCaret = start + text.length;
      field.focus();
      if (typeof field.setSelectionRange === 'function') {
        field.setSelectionRange(nextCaret, nextCaret);
      }
      caretState.set(field, { start: nextCaret, end: nextCaret });
      field.dispatchEvent(new Event('input', { bubbles: true }));
      field.dispatchEvent(new Event('change', { bubbles: true }));
    };

    templateBuilders.forEach((builder) => {
      const palette = builder.querySelector('[data-lc-template-palette]');
      const fields = Array.from(builder.querySelectorAll('[data-lc-template-field]'));
      const tokens = () => Array.from(builder.querySelectorAll('[data-lc-template-token]'));
      let activeField = fields[0] || null;
      let draggedToken = null;

      fields.forEach((field) => {
        const captureCaret = () => {
          caretState.set(field, {
            start: typeof field.selectionStart === 'number' ? field.selectionStart : String(field.value || '').length,
            end: typeof field.selectionEnd === 'number' ? field.selectionEnd : String(field.value || '').length,
          });
        };
        const activate = () => {
          activeField = field;
          captureCaret();
          fields.forEach((item) => item.classList.remove('is-drop-target'));
        };
        field.addEventListener('focus', activate);
        field.addEventListener('click', activate);
        field.addEventListener('keyup', captureCaret);
        field.addEventListener('select', captureCaret);
        field.addEventListener('input', captureCaret);
        field.addEventListener('dragover', (event) => {
          if (!draggedToken) return;
          event.preventDefault();
          field.classList.add('is-drop-target');
        });
        field.addEventListener('dragleave', () => {
          field.classList.remove('is-drop-target');
        });
        field.addEventListener('drop', (event) => {
          if (!draggedToken) return;
          event.preventDefault();
          activate();
          insertTemplateToken(field, draggedToken);
          field.classList.remove('is-drop-target');
        });
      });

      tokens().forEach((tokenEl) => {
        tokenEl.addEventListener('click', () => {
          if (!activeField) return;
          insertTemplateToken(activeField, tokenEl.dataset.lcTemplateToken || '');
        });
        tokenEl.addEventListener('dragstart', (event) => {
          draggedToken = tokenEl.dataset.lcTemplateToken || '';
          tokenEl.classList.add('is-dragging');
          if (event.dataTransfer) {
            event.dataTransfer.effectAllowed = 'copyMove';
            event.dataTransfer.setData('text/plain', draggedToken);
          }
        });
        tokenEl.addEventListener('dragend', () => {
          draggedToken = null;
          tokenEl.classList.remove('is-dragging');
          fields.forEach((field) => field.classList.remove('is-drop-target'));
        });
      });

      if (palette) {
        palette.addEventListener('dragover', (event) => {
          const draggedEl = builder.querySelector('.lc-template-token.is-dragging');
          if (!draggedEl) return;
          event.preventDefault();
          const target = event.target.closest('.lc-template-token');
          if (!target || target === draggedEl) return;
          const targetRect = target.getBoundingClientRect();
          const shouldInsertAfter = event.clientX > targetRect.left + targetRect.width / 2;
          palette.insertBefore(draggedEl, shouldInsertAfter ? target.nextSibling : target);
        });
      }
    });
  }

  const seoLengthBlocks = document.querySelectorAll('[data-lc-seo-length]');
  if (seoLengthBlocks.length) {
    const toNumber = (value, fallback) => {
      const n = Number.parseInt(String(value || ''), 10);
      return Number.isFinite(n) && n > 0 ? n : fallback;
    };
    const normalizeLengthText = (value) => String(value || '').replace(/\s+/g, ' ').trim();

    seoLengthBlocks.forEach((block) => {
      const input = block.querySelector('[data-lc-seo-length-input]');
      const fill = block.querySelector('[data-lc-seo-length-fill]');
      const status = block.querySelector('[data-lc-seo-length-status]');
      const count = block.querySelector('[data-lc-seo-length-count]');
      if (!input || !fill || !status || !count) return;

      const min = toNumber(block.getAttribute('data-min'), 30);
      const goodMax = toNumber(block.getAttribute('data-good-max'), 60);
      const warnMax = toNumber(block.getAttribute('data-warn-max'), Math.max(goodMax + 10, 70));

      const render = () => {
        const length = normalizeLengthText(input.value).length;
        let state = 'empty';
        let message = 'Sin texto';
        if (length > 0 && length < min) {
          state = 'short';
          message = `Muy corto (ideal ${min}-${goodMax})`;
        } else if (length >= min && length <= goodMax) {
          state = 'good';
          message = 'Longitud óptima';
        } else if (length > goodMax && length <= warnMax) {
          state = 'warn';
          message = 'Un poco largo';
        } else if (length > warnMax) {
          state = 'long';
          message = 'Demasiado largo';
        }

        const pct = warnMax > 0 ? Math.min(100, (length / warnMax) * 100) : 0;
        fill.style.width = `${length > 0 ? Math.max(8, pct) : 0}%`;
        block.setAttribute('data-state', state);
        status.textContent = message;
        count.textContent = `${length} caracteres`;
      };

      input.addEventListener('input', render);
      input.addEventListener('change', render);
      render();
    });
  }
  const dayRows = document.querySelectorAll('[data-lc-day-row]');

  if (dayRows.length) {
    dayRows.forEach((row) => {
      const toggle = row.querySelector('[data-lc-day-toggle]');
      const times = row.querySelector('[data-lc-day-times]');
      const start = row.querySelector('[data-lc-day-start]');
      const end = row.querySelector('[data-lc-day-end]');
      const breakToggle = row.querySelector('[data-lc-break-toggle]');
      const breakFields = row.querySelector('[data-lc-break-fields]');
      const breakStart = row.querySelector('[data-lc-break-start]');
      const breakEnd = row.querySelector('[data-lc-break-end]');
      const breakEnabledInput = row.querySelector('[data-lc-break-enabled]');
      let breakEnabled = breakEnabledInput ? breakEnabledInput.value === '1' : false;
      if (!toggle || !times || !start || !end) return;
      const syncBreak = () => {
        const dayEnabled = toggle.checked;
        if (breakToggle) {
          breakToggle.disabled = !dayEnabled;
          breakToggle.textContent = breakEnabled ? 'Quitar descanso' : 'Agregar descanso';
        }
        const showBreak = dayEnabled && breakEnabled;
        if (breakFields) {
          breakFields.classList.toggle('is-hidden', !showBreak);
        }
        if (breakStart) breakStart.disabled = !showBreak;
        if (breakEnd) breakEnd.disabled = !showBreak;
        if (breakEnabledInput) breakEnabledInput.value = breakEnabled ? '1' : '0';
      };
      const sync = () => {
        const enabled = toggle.checked;
        times.classList.toggle('is-hidden', !enabled);
        start.disabled = !enabled;
        end.disabled = !enabled;
        if (!enabled) {
          breakEnabled = false;
        }
        syncBreak();
      };
      if (breakToggle) {
        breakToggle.addEventListener('click', (e) => {
          e.preventDefault();
          if (breakToggle.disabled) return;
          breakEnabled = !breakEnabled;
          syncBreak();
        });
      }
      toggle.addEventListener('change', sync);
      sync();
    });
  }

  const availabilityTimeSelects = document.querySelectorAll('.lc-availability-days .lc-time-select');

  if (availabilityTimeSelects.length) {
    let availabilitySelectSheet = null;
    let availabilitySelectActive = null;
    let availabilitySelectCloseTimer = 0;

    const selectLabelByRole = (select) => {
      if (!select) return 'Seleccionar hora';
      if (select.hasAttribute('data-lc-day-start')) return 'Hora de inicio';
      if (select.hasAttribute('data-lc-day-end')) return 'Hora de término';
      if (select.hasAttribute('data-lc-break-start')) return 'Inicio de descanso';
      if (select.hasAttribute('data-lc-break-end')) return 'Fin de descanso';
      return 'Seleccionar hora';
    };

    const buildSelectTitle = (select) => {
      const row = select.closest('[data-lc-day-row]');
      const day = row ? String(row.querySelector('.lc-day-label label')?.textContent || '').trim() : '';
      const role = selectLabelByRole(select);
      return day ? `${day} · ${role}` : role;
    };

    const selectedOptionText = (select) => {
      if (!select || !select.options || !select.options.length) return 'Seleccionar';
      const option = select.options[select.selectedIndex >= 0 ? select.selectedIndex : 0];
      return String(option?.textContent || option?.label || '').trim() || 'Seleccionar';
    };

    const syncAvailabilitySelectTrigger = (instance) => {
      if (!instance || !instance.select || !instance.trigger || !instance.label) return;
      instance.label.textContent = selectedOptionText(instance.select);
      instance.trigger.disabled = !!instance.select.disabled;
      instance.root.classList.toggle('is-disabled', !!instance.select.disabled);
    };

    const closeAvailabilitySelectSheet = () => {
      if (!availabilitySelectSheet) return;
      availabilitySelectSheet.panel.classList.remove('is-open');
      availabilitySelectSheet.overlay.classList.remove('is-open');
      document.documentElement.classList.remove('lc-admin-select-sheet-open');
      window.clearTimeout(availabilitySelectCloseTimer);
      availabilitySelectCloseTimer = window.setTimeout(() => {
        if (!availabilitySelectSheet || availabilitySelectSheet.panel.classList.contains('is-open')) return;
        availabilitySelectSheet.panel.hidden = true;
        availabilitySelectSheet.overlay.hidden = true;
      }, 220);
    };

    const ensureAvailabilitySelectSheet = () => {
      if (availabilitySelectSheet) return availabilitySelectSheet;

      const overlay = document.createElement('div');
      overlay.className = 'lc-admin-select-sheet-overlay';
      overlay.hidden = true;

      const panel = document.createElement('div');
      panel.className = 'lc-admin-select-sheet-panel';
      panel.hidden = true;

      const head = document.createElement('div');
      head.className = 'lc-admin-select-sheet-head';
      const title = document.createElement('strong');
      title.className = 'lc-admin-select-sheet-title';
      title.textContent = 'Seleccionar hora';
      const close = document.createElement('button');
      close.type = 'button';
      close.className = 'lc-admin-select-sheet-close';
      close.setAttribute('aria-label', 'Cerrar');
      close.innerHTML = '<i class="ri-close-line"></i>';
      head.appendChild(title);
      head.appendChild(close);

      const list = document.createElement('div');
      list.className = 'lc-admin-select-sheet-list';

      panel.appendChild(head);
      panel.appendChild(list);

      document.body.appendChild(overlay);
      document.body.appendChild(panel);

      availabilitySelectSheet = { overlay, panel, title, close, list };

      overlay.addEventListener('click', closeAvailabilitySelectSheet);
      close.addEventListener('click', closeAvailabilitySelectSheet);
      document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape') {
          closeAvailabilitySelectSheet();
        }
      });

      return availabilitySelectSheet;
    };

    function renderAvailabilitySelectOptions(instance) {
      const sheet = ensureAvailabilitySelectSheet();
      sheet.list.innerHTML = '';
      const select = instance?.select;
      if (!select) return;
      const options = Array.from(select.options || []);
      let visible = 0;

      options.forEach((option) => {
        const label = String(option.textContent || option.label || '').trim();
        const value = String(option.value || '');
        if (!label) return;
        visible += 1;

        const btn = document.createElement('button');
        btn.type = 'button';
        btn.className = 'lc-admin-select-sheet-option';
        btn.dataset.value = value;
        btn.textContent = label;
        if (String(select.value || '') === value) {
          btn.classList.add('is-active');
        }
        if (option.disabled) {
          btn.disabled = true;
          btn.classList.add('is-disabled');
        }
        sheet.list.appendChild(btn);
      });

      if (!visible) {
        const empty = document.createElement('div');
        empty.className = 'lc-admin-select-sheet-empty';
        empty.textContent = 'Sin resultados';
        sheet.list.appendChild(empty);
      }
    }

    const openAvailabilitySelectSheet = (instance) => {
      if (!instance || !instance.select || instance.select.disabled) return;
      const sheet = ensureAvailabilitySelectSheet();
      availabilitySelectActive = instance;
      sheet.title.textContent = buildSelectTitle(instance.select);
      renderAvailabilitySelectOptions(instance);
      window.clearTimeout(availabilitySelectCloseTimer);
      sheet.panel.hidden = false;
      sheet.overlay.hidden = false;
      document.documentElement.classList.add('lc-admin-select-sheet-open');
      window.requestAnimationFrame(() => {
        sheet.panel.classList.add('is-open');
        sheet.overlay.classList.add('is-open');
      });
    };

    const bindAvailabilitySelect = (select) => {
      if (!select || select.dataset.lcAdminSelectEnhanced === '1') return;
      select.dataset.lcAdminSelectEnhanced = '1';

      const root = document.createElement('div');
      root.className = 'lc-tz-picker lc-admin-time-select';
      const trigger = document.createElement('button');
      trigger.type = 'button';
      trigger.className = 'lc-tz-trigger lc-admin-time-select-trigger';
      trigger.innerHTML = '<span class="lc-tz-trigger-text lc-admin-time-select-text"></span>';
      const label = trigger.querySelector('.lc-admin-time-select-text');
      select.classList.add('lc-admin-time-select-native');
      select.insertAdjacentElement('afterend', root);
      root.appendChild(trigger);

      const instance = { select, root, trigger, label };
      syncAvailabilitySelectTrigger(instance);

      trigger.addEventListener('click', (event) => {
        event.preventDefault();
        openAvailabilitySelectSheet(instance);
      });

      select.addEventListener('change', () => {
        syncAvailabilitySelectTrigger(instance);
        if (availabilitySelectActive && availabilitySelectActive.select === select) {
          renderAvailabilitySelectOptions(instance);
        }
      });

      const observer = new MutationObserver(() => {
        syncAvailabilitySelectTrigger(instance);
      });
      observer.observe(select, { attributes: true, attributeFilter: ['disabled'] });
    };

    availabilityTimeSelects.forEach((select) => bindAvailabilitySelect(select));

    const sheet = ensureAvailabilitySelectSheet();
    sheet.list.addEventListener('click', (event) => {
      const option = event.target.closest('.lc-admin-select-sheet-option[data-value]');
      if (!option || !availabilitySelectActive || !availabilitySelectActive.select) return;
      const select = availabilitySelectActive.select;
      const nextValue = String(option.dataset.value || '');
      if (String(select.value || '') !== nextValue) {
        select.value = nextValue;
        select.dispatchEvent(new Event('change', { bubbles: true }));
      }
      closeAvailabilitySelectSheet();
    });
  }

  document
    .querySelectorAll('#lc-location-section select[name="location"], #lc-team-section select[name="employees[]"]')
    .forEach((el) => el.remove());

  function toggleLocationDetails() {
    if (!locationInput || !locationDetails.length) return;
    const value = locationInput.value;
    locationDetails.forEach((el) => {
      const detailsInput = el.querySelector('[data-lc-location-details-input], input[name="location_details"], textarea[name="location_details"]');
      const detailsLabel = el.querySelector('[data-lc-location-details-label], label');
      const isPresential = value === 'presencial';
      const isVirtual = value === 'google_meet' || value === 'zoom' || value === 'teams';
      if (isPresential) {
        el.classList.remove('is-hidden');
        if (detailsLabel) {
          detailsLabel.textContent = 'Dirección';
        }
        if (detailsInput) {
          detailsInput.required = true;
          detailsInput.placeholder = 'Av. Ejemplo 123, Ciudad';
        }
      } else {
        el.classList.add('is-hidden');
        if (detailsInput) {
          detailsInput.required = false;
          if (isVirtual) {
            detailsInput.value = '';
          }
        }
      }
    });
  }

  if (locationInput && locationRadios.length) {
    let anyChecked = false;
    locationRadios.forEach((radio) => {
      if (radio.checked) {
        anyChecked = true;
      }
    });
    if (!anyChecked) {
      locationRadios[0].checked = true;
      locationInput.value = locationRadios[0].value;
    }
    locationRadios.forEach((radio) => {
      radio.addEventListener('change', () => {
        locationInput.value = radio.value;
        toggleLocationDetails();
      });
    });
    toggleLocationDetails();
  }
  if (locationInput) {
    locationInput.addEventListener('change', toggleLocationDetails);
    toggleLocationDetails();
  }

  if (allowGuestsInput && guestsLimitWrap) {
    const syncGuestsLimit = () => {
      guestsLimitWrap.style.display = allowGuestsInput.checked ? '' : 'none';
    };
    allowGuestsInput.addEventListener('change', syncGuestsLimit);
    syncGuestsLimit();
  }

  const eventManageOverrideInput = document.querySelector('[data-lc-event-manage-override]');
  const eventManageCardsWrap = document.querySelector('[data-lc-event-manage-cards]');
  if (eventManageOverrideInput && eventManageCardsWrap) {
    const syncManageOverrideView = () => {
      eventManageCardsWrap.style.display = eventManageOverrideInput.checked ? 'none' : '';
    };
    eventManageOverrideInput.addEventListener('change', syncManageOverrideView);
    syncManageOverrideView();
  }

  if (eventPaymentsList) {
    const syncPaymentRow = (row) => {
      const toggle = row.querySelector('[data-lc-payment-toggle]');
      const status = row.querySelector('[data-lc-payment-status]');
      if (!toggle || !status) return;
      status.textContent = toggle.checked ? 'Mostrar' : 'Oculto';
    };
    eventPaymentsList.querySelectorAll('[data-lc-payment-key]').forEach((row) => {
      syncPaymentRow(row);
    });
    eventPaymentsList.addEventListener('change', (event) => {
      const toggle = event.target.closest('[data-lc-payment-toggle]');
      if (!toggle) return;
      const row = toggle.closest('[data-lc-payment-key]');
      if (!row) return;
      syncPaymentRow(row);
    });

    let dragPaymentRow = null;
    eventPaymentsList.addEventListener('dragstart', (event) => {
      const row = event.target.closest('.lc-event-payment-row.is-draggable');
      if (!row) return;
      dragPaymentRow = row;
      row.classList.add('is-dragging');
      event.dataTransfer.effectAllowed = 'move';
    });
    eventPaymentsList.addEventListener('dragend', () => {
      if (dragPaymentRow) dragPaymentRow.classList.remove('is-dragging');
      dragPaymentRow = null;
    });
    eventPaymentsList.addEventListener('dragover', (event) => {
      if (!dragPaymentRow) return;
      event.preventDefault();
      const target = event.target.closest('.lc-event-payment-row.is-draggable');
      if (!target || target === dragPaymentRow) return;
      const rect = target.getBoundingClientRect();
      const next = (event.clientY - rect.top) > rect.height / 2;
      eventPaymentsList.insertBefore(dragPaymentRow, next ? target.nextSibling : target);
    });
    eventPaymentsList.addEventListener('drop', (event) => {
      if (!dragPaymentRow) return;
      event.preventDefault();
      if (notyf) {
        notyf.success('Orden actualizado, recuerda guardar para aplicar los cambios.');
      }
    });
  }

  const eventTeamList = document.querySelector('[data-lc-event-team-list]');
  if (eventTeamList) {
    const teamOrderInput = document.querySelector('[data-lc-team-order-input]');
    const maxTeamRaw = parseInt(String(eventTeamList.getAttribute('data-lc-team-max') || '0'), 10);
    const maxTeam = Number.isFinite(maxTeamRaw) && maxTeamRaw > 0 ? maxTeamRaw : 0;
    const syncTeamRow = (row) => {
      const toggle = row.querySelector('[data-lc-team-toggle]');
      const status = row.querySelector('[data-lc-team-status]');
      if (!toggle || !status) return;
      status.textContent = toggle.checked ? 'Asignado' : 'No asignado';
    };
    const syncTeamOrder = () => {
      if (!teamOrderInput) return;
      const orderedIds = Array.from(eventTeamList.querySelectorAll('[data-lc-team-id]'))
        .map((row) => String(row.getAttribute('data-lc-team-id') || '').trim())
        .filter(Boolean);
      teamOrderInput.value = orderedIds.join(',');
    };
    const enforceTeamMax = (currentToggle = null) => {
      if (maxTeam <= 0) return;
      const toggles = Array.from(eventTeamList.querySelectorAll('[data-lc-team-toggle]'));
      const checked = toggles.filter((toggle) => toggle.checked);
      if (checked.length <= maxTeam) return;
      const keeper = currentToggle && currentToggle.checked ? currentToggle : checked[0];
      checked.forEach((toggle) => {
        toggle.checked = toggle === keeper;
      });
      if (notyf) {
        notyf.error('Múltiples profesionales por servicio está disponible en Pro.');
      }
    };
    enforceTeamMax();
    eventTeamList.querySelectorAll('[data-lc-team-id]').forEach((row) => syncTeamRow(row));
    syncTeamOrder();
    eventTeamList.addEventListener('change', (event) => {
      const toggle = event.target.closest('[data-lc-team-toggle]');
      if (!toggle) return;
      enforceTeamMax(toggle);
      const row = toggle.closest('[data-lc-team-id]');
      if (!row) return;
      eventTeamList.querySelectorAll('[data-lc-team-id]').forEach((node) => syncTeamRow(node));
      syncTeamOrder();
    });

    let dragTeamRow = null;
    eventTeamList.addEventListener('dragstart', (event) => {
      const row = event.target.closest('.lc-event-team-row.is-draggable');
      if (!row) return;
      dragTeamRow = row;
      row.classList.add('is-dragging');
      event.dataTransfer.effectAllowed = 'move';
    });
    eventTeamList.addEventListener('dragend', () => {
      if (dragTeamRow) dragTeamRow.classList.remove('is-dragging');
      dragTeamRow = null;
    });
    eventTeamList.addEventListener('dragover', (event) => {
      if (!dragTeamRow) return;
      event.preventDefault();
      const target = event.target.closest('.lc-event-team-row.is-draggable');
      if (!target || target === dragTeamRow) return;
      const rect = target.getBoundingClientRect();
      const next = (event.clientY - rect.top) > rect.height / 2;
      eventTeamList.insertBefore(dragTeamRow, next ? target.nextSibling : target);
    });
    eventTeamList.addEventListener('drop', (event) => {
      if (!dragTeamRow) return;
      event.preventDefault();
      syncTeamOrder();
      if (notyf) {
        notyf.success('Orden actualizado, recuerda guardar para aplicar los cambios.');
      }
    });
  }

  const initDragOrderList = ({
    listSelector,
    formSelector,
    idsSelector,
    rowSelector,
    idAttr,
    successMessage,
    parseId,
  }) => {
    const listEl = document.querySelector(listSelector);
    const formEl = document.querySelector(formSelector);
    const idsInputEl = formEl ? formEl.querySelector(idsSelector) : null;
    if (!listEl || !formEl || !idsInputEl) return;

    let dragRow = null;
    let submitting = false;
    const resolveId = typeof parseId === 'function'
      ? parseId
      : (raw) => {
          const id = parseInt(String(raw || '0'), 10);
          return Number.isFinite(id) && id > 0 ? String(id) : '';
        };
    const collectIds = () => Array.from(listEl.querySelectorAll(rowSelector))
      .map((row) => resolveId(row.getAttribute(idAttr)))
      .filter((id) => String(id || '').trim() !== '');
    const submitOrder = () => {
      if (submitting) return;
      const ids = collectIds();
      if (!ids.length) return;
      idsInputEl.value = ids.join(',');
      submitting = true;
      formEl.submit();
    };

    listEl.addEventListener('dragstart', (event) => {
      const row = event.target.closest(`${rowSelector}.is-draggable`);
      if (!row) return;
      dragRow = row;
      row.classList.add('is-dragging');
      event.dataTransfer.effectAllowed = 'move';
    });
    listEl.addEventListener('dragend', () => {
      if (dragRow) dragRow.classList.remove('is-dragging');
      dragRow = null;
    });
    listEl.addEventListener('dragover', (event) => {
      if (!dragRow) return;
      event.preventDefault();
      const target = event.target.closest(`${rowSelector}.is-draggable`);
      if (!target || target === dragRow) return;
      const rect = target.getBoundingClientRect();
      const next = (event.clientY - rect.top) > rect.height / 2;
      listEl.insertBefore(dragRow, next ? target.nextSibling : target);
    });
    listEl.addEventListener('drop', (event) => {
      if (!dragRow) return;
      event.preventDefault();
      if (successMessage && notyf) {
        notyf.success(successMessage);
      }
      submitOrder();
    });
  };

  initDragOrderList({
    listSelector: '[data-lc-service-order-list]',
    formSelector: '[data-lc-service-order-form]',
    idsSelector: '[data-lc-service-order-ids]',
    rowSelector: '[data-lc-service-row]',
    idAttr: 'data-lc-service-id',
    successMessage: 'Orden de servicios actualizado.',
  });

  initDragOrderList({
    listSelector: '[data-lc-employee-order-list]',
    formSelector: '[data-lc-employee-order-form]',
    idsSelector: '[data-lc-employee-order-ids]',
    rowSelector: '[data-lc-employee-row]',
    idAttr: 'data-lc-employee-id',
    successMessage: 'Orden de profesionales actualizado.',
  });

  initDragOrderList({
    listSelector: '[data-lc-schedule-order-list]',
    formSelector: '[data-lc-schedule-order-form]',
    idsSelector: '[data-lc-schedule-order-ids]',
    rowSelector: '[data-lc-schedule-row]',
    idAttr: 'data-lc-schedule-id',
    successMessage: 'Orden de horarios actualizado.',
    parseId: (raw) => {
      const value = String(raw || '').trim();
      return /^[a-zA-Z0-9_-]+$/.test(value) ? value : '';
    },
  });

  const eventForm = document.querySelector('#lc-event-form');
  const priceRegularInput = eventForm ? eventForm.querySelector('input[name="price_regular"]') : null;
  const priceSaleInput = eventForm ? eventForm.querySelector('input[name="price_sale"]') : null;
  if (eventForm && priceRegularInput && priceSaleInput) {
    const parseMoneyValue = (raw) => {
      let value = String(raw || '').trim();
      if (!value) return 0;
      const hasComma = value.includes(',');
      const hasDot = value.includes('.');
      if (hasComma && hasDot) {
        if (value.lastIndexOf(',') > value.lastIndexOf('.')) {
          value = value.replace(/\./g, '').replace(',', '.');
        } else {
          value = value.replace(/,/g, '');
        }
      } else if (hasComma && !hasDot) {
        const parts = value.split(',');
        if (parts.length === 2 && parts[1].length <= 2) {
          value = parts[0].replace(/\./g, '') + '.' + parts[1];
        } else {
          value = value.replace(/,/g, '');
        }
      } else {
        const dotParts = value.split('.');
        if (dotParts.length > 1 && dotParts[dotParts.length - 1].length === 3) {
          value = value.replace(/\./g, '');
        } else {
          value = value.replace(/,/g, '');
        }
      }
      value = value.replace(/[^0-9.-]/g, '');
      const parsed = parseFloat(value);
      return Number.isFinite(parsed) ? parsed : 0;
    };
    const validateOfferPrice = (showToast) => {
      const regular = parseMoneyValue(priceRegularInput.value);
      const saleRaw = String(priceSaleInput.value || '').trim();
      const sale = parseMoneyValue(saleRaw);
      const invalid = saleRaw !== '' && sale > regular;
      const message = 'El precio de oferta no puede ser mayor al precio normal.';
      priceSaleInput.setCustomValidity(invalid ? message : '');
      if (invalid && showToast && notyf) {
        notyf.error(message);
      }
      return !invalid;
    };
    priceRegularInput.addEventListener('input', () => validateOfferPrice(false));
    priceSaleInput.addEventListener('input', () => validateOfferPrice(false));
    eventForm.addEventListener('submit', (event) => {
      if (!validateOfferPrice(true)) {
        event.preventDefault();
        priceSaleInput.reportValidity();
      }
    });
  }

  const multicurrencyToggle = document.querySelector('[data-lc-multicurrency-toggle]');
  const multicurrencyPanel = document.querySelector('[data-lc-multicurrency-panel]');
  const currencySelect = document.querySelector('[data-lc-currency-select]');
  if (multicurrencyToggle && multicurrencyPanel && currencySelect) {
    const warningEl = multicurrencyPanel.querySelector('[data-lc-mc-warning]');
    const rows = Array.from(multicurrencyPanel.querySelectorAll('[data-lc-mc-row]'));
    const computeCanEnable = (globalCurrency) => {
      let hasSame = false;
      let hasOther = false;
      rows.forEach((row) => {
        if (row.getAttribute('data-lc-mc-provider-active') !== '1') return;
        const providerCurrency = (row.getAttribute('data-lc-mc-provider-currency') || '').toUpperCase();
        if (!providerCurrency) return;
        if (providerCurrency === globalCurrency) {
          hasSame = true;
        } else {
          hasOther = true;
        }
      });
      return hasSame && hasOther;
    };
    const syncRows = () => {
      const globalCurrency = (currencySelect.value || 'CLP').toUpperCase();
      rows.forEach((row) => {
        const providerCurrency = (row.getAttribute('data-lc-mc-provider-currency') || '').toUpperCase();
        const isActive = row.getAttribute('data-lc-mc-provider-active') === '1';
        const rateInput = row.querySelector('[data-lc-mc-rate]');
        const show = providerCurrency && providerCurrency !== globalCurrency;
        row.style.display = show ? '' : 'none';
        if (rateInput) {
          rateInput.disabled = !show || !isActive;
        }
      });
      const canEnable = computeCanEnable(globalCurrency);
      multicurrencyPanel.setAttribute('data-lc-mc-can-enable', canEnable ? '1' : '0');
      if (warningEl) {
        warningEl.style.display = canEnable ? 'none' : '';
      }
      return canEnable;
    };
    const syncMulticurrencyPanel = () => {
      const canEnable = syncRows();
      const enabled = multicurrencyToggle.value === '1';
      if (enabled && !canEnable) {
        multicurrencyToggle.value = '0';
        multicurrencyPanel.style.display = 'none';
        if (notyf) {
          notyf.error('Necesitas al menos un medio de pago en CLP y uno en USD para activar multimoneda.');
        }
        return;
      }
      multicurrencyPanel.style.display = enabled ? '' : 'none';
    };
    multicurrencyToggle.addEventListener('change', syncMulticurrencyPanel);
    currencySelect.addEventListener('change', syncMulticurrencyPanel);
    syncMulticurrencyPanel();
  }

  const googleClientIdInput = document.querySelector('[data-lc-google-client-id]');
  const googleClientSecretInput = document.querySelector('[data-lc-google-client-secret]');
  const googleCredStatus = document.querySelector('[data-lc-google-cred-status]');
  const googleConnectButton = document.querySelector('[data-lc-google-connect-button]');
  if (googleClientIdInput && googleClientSecretInput && googleCredStatus && googleConnectButton) {
    const connected = googleCredStatus.getAttribute('data-lc-google-connected') === '1';
    const ajaxUrl = googleCredStatus.getAttribute('data-lc-google-ajax') || '';
    const ajaxNonce = googleCredStatus.getAttribute('data-lc-google-nonce') || '';
    const connectAction = googleConnectButton.getAttribute('data-lc-google-connect-action') || '';
    const connectNonce = googleConnectButton.getAttribute('data-lc-google-connect-nonce') || '';
    const isValidClientId = (value) => /^\d+-[a-z0-9-]+\.apps\.googleusercontent\.com$/i.test((value || '').trim());
    const isValidClientSecret = (value) => /^[A-Za-z0-9_-]{20,}$/.test((value || '').trim());
    const setStatus = (type, text) => {
      googleCredStatus.classList.remove('is-success', 'is-error', 'is-neutral');
      googleCredStatus.classList.add(type);
      googleCredStatus.textContent = text;
    };
    let validateTimer = null;
    let validateSeq = 0;
    const validateWithGoogle = (clientId, clientSecret, seqId) => {
      if (!ajaxUrl || !ajaxNonce) {
        return Promise.resolve({ valid: false, message: 'No se pudo validar contra Google.' });
      }
      const body = new URLSearchParams();
      body.set('action', 'litecal_validate_google_credentials');
      body.set('nonce', ajaxNonce);
      body.set('client_id', clientId);
      body.set('client_secret', clientSecret);
      return fetch(ajaxUrl, {
        method: 'POST',
        headers: {
          'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8',
        },
        body: body.toString(),
      })
        .then((res) => res.json())
        .then((json) => {
          if (seqId !== validateSeq) return null;
          const payload = json?.data || {};
          return { valid: !!payload.valid, message: payload.message || '' };
        })
        .catch(() => {
          if (seqId !== validateSeq) return null;
          return { valid: false, message: 'No se pudo validar contra Google. Intenta nuevamente.' };
        });
    };
    const submitGoogleConnect = (clientId, clientSecret) => {
      if (!connectAction || !connectNonce) {
        return;
      }
      const form = document.createElement('form');
      form.method = 'post';
      form.action = connectAction;
      form.style.display = 'none';
      [
        ['action', 'litecal_google_oauth_start'],
        ['_wpnonce', connectNonce],
        ['google_client_id', clientId],
        ['google_client_secret', clientSecret],
      ].forEach(([name, value]) => {
        const input = document.createElement('input');
        input.type = 'hidden';
        input.name = name;
        input.value = value;
        form.appendChild(input);
      });
      document.body.appendChild(form);
      form.submit();
    };
    const syncGoogleConnectState = () => {
      const clientId = (googleClientIdInput.value || '').trim();
      const clientSecret = (googleClientSecretInput.value || '').trim();
      if (connected) {
        setStatus('is-success', 'Credenciales válidas. Conexión lista para Google Calendar.');
        googleConnectButton.style.display = 'none';
        return;
      }
      if (validateTimer) {
        clearTimeout(validateTimer);
        validateTimer = null;
      }
      if (!clientId && !clientSecret) {
        setStatus('is-neutral', 'Ingresa Client ID y Client Secret para validar.');
        googleConnectButton.style.display = 'none';
        return;
      }
      if (!isValidClientId(clientId) || !isValidClientSecret(clientSecret)) {
        setStatus('is-error', 'Credenciales incorrectas. Revisa Client ID y Client Secret.');
        googleConnectButton.style.display = 'none';
        return;
      }
      setStatus('is-neutral', 'Validando credenciales con Google...');
      googleConnectButton.style.display = 'none';
      const seqId = ++validateSeq;
      validateTimer = setTimeout(() => {
        validateWithGoogle(clientId, clientSecret, seqId).then((result) => {
          if (!result) return;
          if (result.valid) {
            setStatus('is-success', result.message || 'Credenciales válidas. Conexión lista para Google Calendar.');
            googleConnectButton.style.display = '';
            return;
          }
          setStatus('is-error', result.message || 'Credenciales incorrectas. Revisa Client ID y Client Secret.');
          googleConnectButton.style.display = 'none';
        });
      }, 450);
    };
    googleConnectButton.addEventListener('click', () => {
      const clientId = (googleClientIdInput.value || '').trim();
      const clientSecret = (googleClientSecretInput.value || '').trim();
      if (!isValidClientId(clientId) || !isValidClientSecret(clientSecret)) {
        syncGoogleConnectState();
        return;
      }
      submitGoogleConnect(clientId, clientSecret);
    });
    googleClientIdInput.addEventListener('input', syncGoogleConnectState);
    googleClientSecretInput.addEventListener('input', syncGoogleConnectState);
    syncGoogleConnectState();
  }

  if (scheduleSelect && schedulePreview && window.litecalSchedules) {
    scheduleSelect.addEventListener('change', () => {
      const id = scheduleSelect.value;
      if (window.litecalSchedulePreviews && window.litecalSchedulePreviews[id]) {
        schedulePreview.innerHTML = String(window.litecalSchedulePreviews[id]);
        return;
      }
      schedulePreview.textContent = window.litecalSchedules[id] || '';
    });
  }

  const openIntegrationModal = (key) => {
    const modal = document.querySelector(`[data-lc-integration-modal="${key}"]`);
    if (!modal) return;
    modal.hidden = false;
    modal.classList.add('is-open');
  };

  const closeIntegrationModal = (modal) => {
    if (!modal) return;
    modal.classList.remove('is-open');
    modal.hidden = true;
  };

  integrationOpeners.forEach((btn) => {
    btn.addEventListener('click', () => {
      openIntegrationModal(btn.dataset.lcIntegrationOpen);
    });
  });

  integrationClosers.forEach((btn) => {
    btn.addEventListener('click', () => {
      const modal = btn.closest('.lc-integration-modal');
      closeIntegrationModal(modal);
    });
  });

  if (rangeCalendar) {
    const grid = rangeCalendar.querySelector('[data-lc-range-grid]');
    const label = rangeCalendar.querySelector('[data-lc-range-label]');
    const prevBtn = rangeCalendar.querySelector('[data-lc-range-prev]');
    const nextBtn = rangeCalendar.querySelector('[data-lc-range-next]');
    const inputStart = document.querySelector('[data-lc-range-start]');
    const inputEnd = document.querySelector('[data-lc-range-end]');
    const inputMode = document.querySelector('[data-lc-range-mode]');
    const toggleBtn = document.querySelector('[data-lc-range-toggle]');
    const messageEl = document.querySelector('[data-lc-range-message]');
    const typeSelect = document.querySelector('[data-lc-range-type]');
    let existing = [];
    try {
      const raw = rangeCalendar.getAttribute('data-lc-timeoff-ranges') || '[]';
      existing = JSON.parse(raw);
    } catch (e) {
      existing = [];
    }
    let current = new Date();
    current.setDate(1);
    let start = null;
    let end = null;
    let hover = null;

    const fmt = (d) => d.toISOString().split('T')[0];
    const inExisting = (s, e) =>
      existing.some((r) => r.start === s && r.end === e);
    const getExactExistingRange = (s, e) =>
      existing.find((r) => r.start === s && r.end === e) || null;
    const getExistingRange = (dateStr) =>
      existing.find((r) => dateStr >= r.start && dateStr <= r.end) || null;

    const updatePreview = () => {
      if (!grid) return;
      const cells = grid.querySelectorAll('.lc-range-date');
      cells.forEach((cell) => {
        const dateStr = cell.dataset.date;
        if (!dateStr) return;
        const inPreview =
          start && !end && hover && dateStr >= fmt(start) && dateStr <= fmt(hover);
        cell.classList.toggle('is-preview', !!inPreview && dateStr !== fmt(start));
      });
    };

    const render = () => {
      if (!grid || !label) return;
      grid.innerHTML = '';
      label.textContent = current.toLocaleString('es-ES', { month: 'long', year: 'numeric' });
      const first = new Date(current.getFullYear(), current.getMonth(), 1);
      const startDay = first.getDay();
      const days = ['DOM', 'LUN', 'MAR', 'MIÉ', 'JUE', 'VIE', 'SÁB'];
      days.forEach((d) => {
        const el = document.createElement('div');
        el.className = 'lc-calendar-day lc-range-head';
        el.textContent = d;
        grid.appendChild(el);
      });
      const total = new Date(current.getFullYear(), current.getMonth() + 1, 0).getDate();
      for (let i = 0; i < startDay; i++) {
        const empty = document.createElement('div');
        empty.className = 'lc-calendar-cell lc-range-empty is-outside-month';
        grid.appendChild(empty);
      }
      for (let d = 1; d <= total; d++) {
        const date = new Date(current.getFullYear(), current.getMonth(), d);
        const cell = document.createElement('div');
        cell.className = 'lc-calendar-cell lc-range-date has-bookings';
        const dateStr = fmt(date);
        cell.dataset.date = dateStr;
        const today = new Date();
        today.setHours(0, 0, 0, 0);
        const dayNumber = document.createElement('span');
        dayNumber.className = 'lc-calendar-number';
        dayNumber.textContent = String(d);
        cell.appendChild(dayNumber);
        if (date < today) {
          cell.classList.add('is-disabled');
        }
        if (fmt(date) === fmt(today)) {
          cell.classList.add('is-today');
        }
        const inRange =
          start && end && dateStr >= fmt(start) && dateStr <= fmt(end);
        const inPreview =
          start && !end && hover && dateStr >= fmt(start) && dateStr <= fmt(hover);
        const selectedRange =
          start && end ? getExactExistingRange(fmt(start), fmt(end)) : null;
        const storedRange = !inRange ? getExistingRange(dateStr) : null;
        const inStoredRange = !!storedRange;
        if (inStoredRange) {
          cell.classList.add('is-active');
        }
        if (start && dateStr === fmt(start)) cell.classList.add('is-active');
        if (end && dateStr === fmt(end)) cell.classList.add('is-active');
        if (inRange && !(dateStr === fmt(start) || dateStr === fmt(end))) {
          cell.classList.add('is-active');
        }
        if (inPreview && !(dateStr === fmt(start))) {
          cell.classList.add('is-preview');
        }
        if (inStoredRange || inRange || (start && dateStr === fmt(start)) || (end && dateStr === fmt(end))) {
          const badge = document.createElement('span');
          badge.className = 'lc-calendar-count lc-calendar-count--day';
          const resolvedType = inStoredRange
            ? storedRange?.type
            : (selectedRange?.type || typeSelect?.value || 'feriado');
          const activeType = resolvedType === 'feriado' ? 'Feriado' : 'Vacaciones';
          badge.innerHTML = `<span class="lc-calendar-dot"></span><span>${activeType}</span>`;
          cell.appendChild(badge);
        }
        cell.addEventListener('click', () => {
          if (cell.classList.contains('is-disabled')) return;
          if (!start || (start && end)) {
            start = date;
            end = null;
          } else if (date < start) {
            start = date;
          } else {
            end = date;
          }
          if (inputStart) inputStart.value = start ? fmt(start) : '';
          if (inputEnd) inputEnd.value = end ? fmt(end) : '';
          if (toggleBtn && inputMode) {
            const s = inputStart?.value || '';
            const e = inputEnd?.value || '';
            if (s) {
              const exactRange = getExactExistingRange(s, e);
              if (typeSelect && exactRange?.type) {
                typeSelect.value = exactRange.type;
              }
              if (inExisting(s, e)) {
                inputMode.value = 'deactivate';
                toggleBtn.textContent = typeSelect?.value === 'feriado' ? 'Desactivar Feriado' : 'Desactivar Vacaciones';
              } else {
                inputMode.value = 'activate';
                toggleBtn.textContent = typeSelect?.value === 'feriado' ? 'Activar Feriado' : 'Activar Vacaciones';
              }
              toggleBtn.disabled = !e;
            } else {
              toggleBtn.disabled = true;
            }
          }
          if (messageEl && inputStart) {
            const s = inputStart.value;
            const e = inputEnd?.value || '';
            messageEl.textContent = s && e ? `Has seleccionado el rango de fechas de ${s} a ${e}.` : 'Selecciona un rango de fechas.';
          }
          render();
        });
        cell.addEventListener('mouseenter', () => {
          if (start && !end) {
            hover = date;
            updatePreview();
          }
        });
        grid.appendChild(cell);
      }
    };

    prevBtn?.addEventListener('click', () => {
      current = new Date(current.getFullYear(), current.getMonth() - 1, 1);
      render();
    });
    nextBtn?.addEventListener('click', () => {
      current = new Date(current.getFullYear(), current.getMonth() + 1, 1);
      render();
    });

    if (existing.length && inputStart && inputEnd && inputMode && toggleBtn) {
      const first = existing[0];
      inputStart.value = first.start;
      inputEnd.value = first.end;
      if (typeSelect && first.type) {
        typeSelect.value = first.type;
      }
      inputMode.value = 'deactivate';
      toggleBtn.textContent = typeSelect?.value === 'feriado' ? 'Desactivar Feriado' : 'Desactivar Vacaciones';
      toggleBtn.disabled = false;
      start = new Date(first.start);
      end = new Date(first.end);
      if (messageEl) {
        messageEl.textContent = `Has seleccionado el rango de fechas de ${first.start} a ${first.end}.`;
      }
    } else if (toggleBtn) {
      toggleBtn.disabled = true;
      if (messageEl) {
        messageEl.textContent = 'Selecciona un rango de fechas.';
      }
    }

    if (typeSelect && toggleBtn) {
      const updateButtonForType = () => {
        const s = inputStart?.value || '';
        const e = inputEnd?.value || '';
        if (s) {
          const isActive = inExisting(s, e);
          if (isActive) {
            toggleBtn.textContent = typeSelect.value === 'feriado' ? 'Desactivar Feriado' : 'Desactivar Vacaciones';
          } else {
            toggleBtn.textContent = typeSelect.value === 'feriado' ? 'Activar Feriado' : 'Activar Vacaciones';
          }
        } else {
          toggleBtn.textContent = typeSelect.value === 'feriado' ? 'Activar Feriado' : 'Activar Vacaciones';
        }
      };
      updateButtonForType();
      typeSelect.addEventListener('change', updateButtonForType);
    }
    render();
  }

  document.querySelectorAll('[data-lc-auto-submit]').forEach((input) => {
    input.addEventListener('change', () => {
      if (input.form) {
        input.form.submit();
      }
    });
  });

  document.addEventListener('submit', (event) => {
    const form = event.target && event.target.closest ? event.target.closest('[data-lc-confirm-delete]') : null;
    if (!form) return;
    if (form.dataset.lcConfirmHandled === '1') {
      form.dataset.lcConfirmHandled = '';
      return;
    }
    event.preventDefault();
    const formOptions = {
      title: String(form.dataset.lcConfirmTitle || ''),
      text: String(form.dataset.lcConfirmText || ''),
      confirmText: String(form.dataset.lcConfirmYes || ''),
      cancelText: String(form.dataset.lcConfirmCancel || ''),
      confirmClass: String(form.dataset.lcConfirmClass || ''),
    };
    openConfirmModal({
      ...formOptions,
      onConfirm: () => {
        form.dataset.lcConfirmHandled = '1';
        form.submit();
      },
    });
  });

  document.querySelectorAll('[data-lc-richtext]').forEach((wrapper) => {
    const textarea = wrapper.querySelector('[data-lc-rt-input]');
    const editor = wrapper.querySelector('[data-lc-rt-editor]');
    const toolbar = wrapper.querySelector('.lc-richtext-toolbar');
    if (!textarea || !toolbar || !editor) return;
    toolbar.addEventListener('click', (event) => {
      const btn = event.target.closest('[data-lc-rt-action]');
      if (!btn) return;
      const action = btn.dataset.lcRtAction;
      if (action === 'bold') {
        document.execCommand('bold');
      } else if (action === 'italic') {
        document.execCommand('italic');
      } else if (action === 'link') {
        const url = window.prompt('Ingresa la URL');
        if (url) {
          document.execCommand('createLink', false, url);
        }
      }
      editor.focus();
    });
    const sync = () => {
      textarea.value = editor.innerHTML;
    };
    editor.addEventListener('input', sync);
    if (editor.form) {
      editor.form.addEventListener('submit', sync);
    }
  });

  document.querySelectorAll('[data-lc-custom-fields-json]').forEach((jsonInput) => {
    const section = jsonInput.closest('.lc-card, .lc-panel');
    const modal = (section && section.querySelector('[data-lc-question-modal]')) || document.querySelector('[data-lc-question-modal]') || document.querySelector('[data-lc-modal]');
    const list = section ? section.querySelector('[data-lc-questions-list]') : null;
    const addBtn = section ? section.querySelector('[data-lc-add-question]') : null;
    if (!modal || !list || !addBtn) return;

    const typeEl =
      modal.querySelector('[data-lc-question-type]') ||
      modal.querySelector('[data-lc-modal-type]');
    const keyEl =
      modal.querySelector('[data-lc-question-key]') ||
      modal.querySelector('[data-lc-modal-key]');
    const labelEl =
      modal.querySelector('[data-lc-question-label]') ||
      modal.querySelector('[data-lc-modal-label]');
    const requiredEl =
      modal.querySelector('[data-lc-question-required]') ||
      modal.querySelector('[data-lc-modal-required]');
    const optionsWrap = modal.querySelector('[data-lc-question-options-wrap]');
    const optionsList = modal.querySelector('[data-lc-question-options-list]');
    const optionsAdd = modal.querySelector('[data-lc-question-options-add]');
    const optionsLabel = modal.querySelector('[data-lc-question-options-label]');
    const errorEl = modal.querySelector('[data-lc-question-error]');
    const fileWrap = modal.querySelector('[data-lc-question-file]');
    const fileTypeChecks = modal.querySelectorAll('[data-lc-file-type]');
    const fileCustom = modal.querySelector('[data-lc-file-custom]');
    const fileMax = modal.querySelector('[data-lc-file-max]');
    const fileCount = modal.querySelector('[data-lc-file-count]');
    const fileHelp = modal.querySelector('[data-lc-file-help]');
    const saveBtn =
      modal.querySelector('[data-lc-question-save]') ||
      modal.querySelector('[data-lc-modal-save]');
    const closeEls = modal.querySelectorAll('[data-lc-question-close],[data-lc-modal-close]');
    const fileOptionDisabled = !!(typeEl && typeEl.querySelector('option[value="file"][disabled]'));
    const normalizeType = (value) => {
      const next = String(value || 'short_text');
      if (next === 'file' && fileOptionDisabled) {
        return 'short_text';
      }
      return next;
    };

    let editingKey = null;
    let fields = [];
    const addOptionRow = (value = '') => {
      if (!optionsList) return;
      const row = document.createElement('div');
      row.className = 'lc-question-option-row';
      row.innerHTML = `
        <input type="text" value="${escAttrUi(value)}" placeholder="Opción" />
        <button type="button" class="button button-link-delete" data-lc-option-remove>Eliminar</button>
      `;
      optionsList.appendChild(row);
    };

    try {
      fields = JSON.parse(jsonInput.value || '[]');
    } catch (e) {
      fields = [];
    }

    const sync = () => {
      jsonInput.value = JSON.stringify(fields);
    };
    if (jsonInput.form) {
      jsonInput.form.addEventListener('submit', sync);
    }

    const typeLabel = (type) => {
      if (type === 'long_text') return 'Long Text';
      if (type === 'select') return 'Select';
      if (type === 'multiselect') return 'MultiSelect';
      if (type === 'checkbox_group') return 'Checkbox Group';
      if (type === 'radio_group') return 'Radio Group';
      if (type === 'checkbox') return 'Checkbox';
      if (type === 'url') return 'URL';
      if (type === 'number') return 'Number';
      if (type === 'address') return 'Address';
      if (type === 'multiple_emails') return 'Multiple Emails';
      if (type === 'email') return 'Email';
      if (type === 'phone') return 'Phone';
      if (type === 'file') return 'Adjuntar archivo';
      return 'Short Text';
    };

    const openModal = (field) => {
      editingKey = field ? field.key : null;
      const titleEl =
        modal.querySelector('.lc-question-modal-title') || modal.querySelector('.lc-modal-title');
      if (titleEl) {
        titleEl.textContent = field ? 'Editar pregunta' : 'Agregar una pregunta';
      }
      typeEl.value = normalizeType(field?.type || 'short_text');
      keyEl.value = field?.key || '';
      labelEl.value = field?.label || '';
      requiredEl.checked = !!field?.required;
      if (optionsList) {
        optionsList.innerHTML = '';
        (field?.options || []).forEach((opt) => addOptionRow(opt));
      }
      if (errorEl) errorEl.hidden = true;
      const currentType = normalizeType(typeEl.value);
      const needsOptions = ['select','multiselect','checkbox_group','radio_group'].includes(currentType);
      const isFile = currentType === 'file';
      if (optionsLabel && optionsWrap) {
        optionsLabel.style.display = needsOptions ? 'block' : 'none';
        optionsWrap.style.display = needsOptions ? 'grid' : 'none';
      }
      if (fileWrap) {
        fileWrap.style.display = isFile ? 'block' : 'none';
      }
      if (needsOptions && optionsList && optionsList.children.length === 0) {
        addOptionRow('');
      }
      if (isFile) {
        const allowed = field?.file_allowed || ['pdf', 'images'];
        fileTypeChecks.forEach((check) => {
          check.checked = allowed.includes(check.value);
        });
        if (fileCustom) fileCustom.value = field?.file_custom || '';
        if (fileMax) fileMax.value = field?.file_max_mb || 5;
        if (fileCount) fileCount.value = field?.file_max_files || 1;
        if (fileHelp) fileHelp.value = field?.help || '';
      }
      modal.hidden = false; modal.removeAttribute('hidden'); modal.style.display = "grid"; modal.classList.add('is-open');
    };

    const closeModal = () => {
      modal.hidden = true; modal.setAttribute('hidden', ""); modal.style.display = ""; modal.classList.remove('is-open');
      editingKey = null;
      keyEl.value = '';
      labelEl.value = '';
      requiredEl.checked = false;
      if (optionsList) optionsList.innerHTML = '';
      typeEl.value = 'short_text';
      if (fileTypeChecks.length) {
        fileTypeChecks.forEach((check) => { check.checked = false; });
      }
      if (fileCustom) fileCustom.value = '';
      if (fileMax) fileMax.value = 5;
      if (fileCount) fileCount.value = 1;
      if (fileHelp) fileHelp.value = '';
    };

    const upsertRow = (field) => {
      const emptyRow = list.querySelector('.lc-empty-row');
      if (emptyRow) emptyRow.remove();
      let row = list.querySelector(`[data-lc-field-key="${field.key}"]`);
      if (!row) {
        row = document.createElement('div');
        row.className = 'lc-question-row is-draggable';
        row.dataset.lcFieldKey = field.key;
        row.setAttribute('draggable', 'true');
        row.innerHTML = `
          <div>
            <div class="lc-question-title"><span class="lc-drag-handle">⋮⋮</span></div>
            <div class="lc-question-sub"></div>
          </div>
          <div class="lc-question-actions">
            <label class="lc-toggle-row lc-toggle-row-left">
              <span class="lc-switch"><input type="checkbox" data-lc-field-toggle><span></span></span>
              <span data-lc-field-status></span>
            </label>
            <button type="button" class="button" data-lc-field-edit>Editar</button>
            <button type="button" class="button button-link-delete" data-lc-field-delete>Eliminar</button>
          </div>
        `;
        list.appendChild(row);
      }
      row.querySelector('.lc-question-title').textContent = field.label;
      row.querySelector('.lc-question-sub').textContent = typeLabel(field.type);
      const toggle = row.querySelector('[data-lc-field-toggle]');
      const status = row.querySelector('[data-lc-field-status]');
      toggle.checked = field.enabled !== false;
      status.textContent = toggle.checked ? 'Mostrar' : 'Oculto';
    };

    addBtn.addEventListener('click', (e) => { e.preventDefault(); openModal(null); });
    closeEls.forEach((el) => el.addEventListener('click', closeModal));

      if (typeEl) {
      typeEl.addEventListener('change', () => {
        const currentType = normalizeType(typeEl.value);
        if (currentType !== typeEl.value) {
          typeEl.value = currentType;
        }
        const needsOptions = ['select','multiselect','checkbox_group','radio_group'].includes(currentType);
        const isFile = currentType === 'file';
        if (optionsLabel && optionsWrap) {
          optionsLabel.style.display = needsOptions ? 'block' : 'none';
          optionsWrap.style.display = needsOptions ? 'grid' : 'none';
        }
        if (fileWrap) {
          fileWrap.style.display = isFile ? 'block' : 'none';
        }
        if (needsOptions && optionsList && optionsList.children.length === 0) {
          addOptionRow('');
        }
        if (errorEl) errorEl.hidden = true;
      });
    }

    if (optionsAdd) {
      optionsAdd.addEventListener('click', () => addOptionRow(''));
    }

    if (optionsList) {
      optionsList.addEventListener('click', (e) => {
        const btn = e.target.closest('[data-lc-option-remove]');
        if (!btn) return;
        const row = btn.closest('.lc-question-option-row');
        row && row.remove();
      });
    }


    saveBtn.addEventListener('click', () => {
      const labelVal = labelEl.value.trim();
      let key = keyEl.value.trim().toLowerCase().replace(/[^a-z0-9_]+/g, '_');
      if (!labelVal && !key) return;
      if (!key && labelVal) {
        key = labelVal.toLowerCase().replace(/[^a-z0-9_]+/g, '_');
      }
      if (!labelVal) return;
      const options = optionsList
        ? Array.from(optionsList.querySelectorAll('input')).map((i) => i.value.trim()).filter(Boolean)
        : [];

      const selectedType = normalizeType(typeEl.value);
      const needsOptions = ['select','multiselect','checkbox_group','radio_group'].includes(selectedType);
      if (needsOptions && options.length === 0) {
        if (errorEl) errorEl.hidden = false;
        return;
      }

      const field = {
        key,
        label: labelVal,
        type: selectedType,
        required: !!requiredEl.checked,
        enabled: true,
        options: options,
      };
      if (selectedType === 'file') {
        const allowed = Array.from(fileTypeChecks)
          .filter((check) => check.checked)
          .map((check) => check.value);
        field.file_allowed = allowed;
        field.file_custom = fileCustom ? fileCustom.value.trim() : '';
        field.file_max_mb = fileMax ? parseInt(fileMax.value || '5', 10) : 5;
        field.file_max_files = fileCount ? parseInt(fileCount.value || '1', 10) : 1;
        field.help = fileHelp ? fileHelp.value.trim() : '';
      }
      const existingIndex = fields.findIndex((f) => f.key === (editingKey || key));
      if (existingIndex >= 0) {
        fields[existingIndex] = { ...fields[existingIndex], ...field };
      } else {
        fields.push(field);
      }
      upsertRow(field);
      sync();
      closeModal();
    });

    list.addEventListener('click', (event) => {
      const editBtn = event.target.closest('[data-lc-field-edit]');
      if (editBtn) {
        const row = editBtn.closest('[data-lc-field-key]');
        const key = row?.dataset.lcFieldKey;
        const field = fields.find((f) => f.key === key);
        if (field) {
          openModal(field);
        }
      }
      const deleteBtn = event.target.closest('[data-lc-field-delete]');
      if (deleteBtn) {
        const row = deleteBtn.closest('[data-lc-field-key]');
        const key = row?.dataset.lcFieldKey;
        openConfirmModal({
          onConfirm: () => {
            fields = fields.filter((f) => f.key !== key);
            row?.remove();
            if (!list.querySelector('[data-lc-field-key]')) {
              const empty = document.createElement('div');
              empty.className = 'lc-empty-row';
              empty.textContent = 'No hay campos personalizados.';
              list.appendChild(empty);
            }
            sync();
          },
        });
      }
    });

    list.addEventListener('change', (event) => {
      const toggle = event.target.closest('[data-lc-field-toggle]');
      if (!toggle) return;
      const row = toggle.closest('[data-lc-field-key]');
      const key = row?.dataset.lcFieldKey;
      const field = fields.find((f) => f.key === key);
      if (!field) return;
      field.enabled = toggle.checked;
      const status = row.querySelector('[data-lc-field-status]');
      if (status) status.textContent = toggle.checked ? 'Mostrar' : 'Oculto';
      sync();
    });

    const showOrderSaved = () => {
      if (notyf) {
        notyf.success('Orden actualizado, recuerda guardar para aplicar los cambios.');
      }
    };

    const reorderFieldsFromDom = () => {
      const orderedKeys = Array.from(list.querySelectorAll('[data-lc-field-key]')).map(
        (row) => row.dataset.lcFieldKey
      );
      const ordered = [];
      orderedKeys.forEach((key) => {
        const field = fields.find((f) => f.key === key);
        if (field) ordered.push(field);
      });
      fields = ordered;
      sync();
      showOrderSaved();
    };

    let dragRow = null;
    list.addEventListener('dragstart', (event) => {
      const row = event.target.closest('.lc-question-row.is-draggable');
      if (!row) return;
      dragRow = row;
      row.classList.add('is-dragging');
      event.dataTransfer.effectAllowed = 'move';
    });
    list.addEventListener('dragend', () => {
      if (dragRow) dragRow.classList.remove('is-dragging');
      dragRow = null;
    });
    list.addEventListener('dragover', (event) => {
      if (!dragRow) return;
      event.preventDefault();
      const target = event.target.closest('.lc-question-row.is-draggable');
      if (!target || target === dragRow) return;
      const rect = target.getBoundingClientRect();
      const next = (event.clientY - rect.top) > rect.height / 2;
      list.insertBefore(dragRow, next ? target.nextSibling : target);
    });
    list.addEventListener('drop', (event) => {
      if (!dragRow) return;
      event.preventDefault();
      reorderFieldsFromDom();
    });
  });

  const openWpMediaPicker = (title, buttonText, callback) => {
    if (!(window.wp && window.wp.media)) {
      if (notyf) {
        notyf.error('No se pudo abrir la biblioteca de medios. Recarga la página e intenta nuevamente.');
      }
      return;
    }
    const frame = window.wp.media({
      title,
      button: { text: buttonText },
      multiple: false,
    });
    frame.on('select', () => {
      const attachment = frame.state().get('selection').first().toJSON();
      callback(attachment.url || '');
    });
    frame.open();
  };

  if (
    (avatarUploadBtn && avatarInput) ||
    (seoImageUploadBtn && seoImageInput)
  ) {
    let filePondRegistered = false;
    if (window.FilePond) {
      if (window.FilePondPluginImagePreview) {
        window.FilePond.registerPlugin(window.FilePondPluginImagePreview);
      }
      filePondRegistered = true;
    }
    const setAvatarFallback = () => {
      if (!avatarPreview || !avatarInput) return;
      const initials = avatarInput.dataset.lcAvatarInitials || '•';
      const colorClass = avatarInput.dataset.lcAvatarColor || '';
      avatarPreview.innerHTML = `<span class="lc-avatar-badge ${escAttrUi(colorClass)}">${escHtmlUi(initials)}</span>`;
    };
    const createPond = (previewEl, hiddenEl, emptyHtml, altText, imageHeight) => {
      if (!previewEl) return null;
      if (!filePondRegistered) {
        const url = String(hiddenEl.value || '').trim();
        previewEl.innerHTML = url ? `<img src="${escUrlUi(url)}" alt="${escAttrUi(altText)}" />` : emptyHtml;
        return {
          set: (nextUrl) => {
            const normalized = String(nextUrl || '').trim();
            previewEl.innerHTML = normalized ? `<img src="${escUrlUi(normalized)}" alt="${escAttrUi(altText)}" />` : emptyHtml;
          },
          clear: () => {
            previewEl.innerHTML = emptyHtml;
          },
        };
      }
      previewEl.classList.add('is-filepond');
      previewEl.innerHTML = '';
      const input = document.createElement('input');
      input.type = 'file';
      input.accept = 'image/*';
      input.className = 'lc-filepond-input';
      previewEl.appendChild(input);
      const pond = window.FilePond.create(input, {
        credits: false,
        allowMultiple: false,
        allowDrop: false,
        allowBrowse: false,
        allowPaste: false,
        imagePreviewHeight: imageHeight,
        labelIdle: 'Selecciona desde la biblioteca',
      });
      const setFromUrl = (url) => {
        const normalized = String(url || '').trim();
        pond.removeFiles();
        if (normalized) {
          pond.addFile(normalized).catch(() => {});
        }
      };
      setFromUrl(hiddenEl.value || '');
      return {
        set: setFromUrl,
        clear: () => pond.removeFiles(),
      };
    };

    const avatarPond = avatarInput
      ? createPond(
          avatarPreview,
          avatarInput,
          `<span class="lc-avatar-badge ${escAttrUi(avatarInput.dataset.lcAvatarColor || '')}">${escHtmlUi(avatarInput.dataset.lcAvatarInitials || '•')}</span>`,
          'Avatar',
          86
        )
      : null;

    const renderSeoPreview = (url) => {
      if (!seoImagePreview) return;
      const normalized = String(url || '').trim();
      if (normalized) {
        seoImagePreview.innerHTML = `<img src="${escUrlUi(normalized)}" alt="Imagen SEO" />`;
      } else {
        seoImagePreview.innerHTML = '<span>Sin imagen</span>';
      }
    };
    if (seoImageInput && seoImagePreview) {
      renderSeoPreview(seoImageInput.value || '');
    }

    if (avatarUploadBtn && avatarInput) {
      avatarUploadBtn.addEventListener('click', () => {
        openWpMediaPicker('Selecciona una imagen', 'Usar imagen', (url) => {
          avatarInput.value = url;
          if (avatarPond) avatarPond.set(url);
        });
      });
    }
    if (avatarClearBtn && avatarInput) {
      avatarClearBtn.addEventListener('click', () => {
        avatarInput.value = '';
        if (avatarPond) {
          avatarPond.clear();
          if (!filePondRegistered) {
            setAvatarFallback();
          }
        }
      });
    }
    if (seoImageUploadBtn && seoImageInput) {
      seoImageUploadBtn.addEventListener('click', (event) => {
        event.preventDefault();
        openWpMediaPicker('Selecciona una imagen SEO', 'Usar imagen', (url) => {
          seoImageInput.value = url;
          renderSeoPreview(url);
        });
      });
    }
    if (seoImageClearBtn && seoImageInput) {
      seoImageClearBtn.addEventListener('click', (event) => {
        event.preventDefault();
        seoImageInput.value = '';
        renderSeoPreview('');
      });
    }
  }

  const updatePriceMode = () => {
    if (!priceModeSelect) return;
    const mode = priceModeSelect.value;
    if (priceWrap) {
      priceWrap.classList.toggle('is-hidden', mode === 'free');
    }
    if (partialWrap) {
      partialWrap.classList.toggle('is-hidden', mode !== 'partial_percent');
    }
    if (fixedWrap) {
      fixedWrap.classList.toggle('is-hidden', mode !== 'partial_fixed');
    }
    if (onsiteWrap) {
      onsiteWrap.classList.toggle('is-hidden', mode !== 'onsite');
    }
    if (paymentsWrap) {
      paymentsWrap.classList.toggle('is-hidden', mode === 'free' || mode === 'onsite');
    }
    if (locationInput) {
      const selectRoot = locationInput.closest('[data-lc-select]');
      if (selectRoot) {
        selectRoot.querySelectorAll('[data-lc-select-option]').forEach((option) => {
          const value = String(option.getAttribute('data-value') || '');
          const disableForOnsite = mode === 'onsite' && value !== 'presencial';
          option.classList.toggle('is-disabled', disableForOnsite);
          if (disableForOnsite) {
            option.setAttribute('data-lc-onsite-disabled', '1');
            option.setAttribute('aria-disabled', 'true');
          } else {
            option.removeAttribute('data-lc-onsite-disabled');
            option.removeAttribute('aria-disabled');
          }
        });
      }
      if (mode === 'onsite' && locationInput.value !== 'presencial') {
        locationInput.value = 'presencial';
        if (selectRoot) {
          syncSelectFromValue(selectRoot);
        }
        locationInput.dispatchEvent(new Event('change', { bubbles: true }));
      }
    }
  };

  if (priceModeSelect) {
    priceModeSelect.addEventListener('change', updatePriceMode);
    updatePriceMode();
  }

  const extrasRoot = document.querySelector('[data-lc-extras-root]');
  if (extrasRoot) {
    const extrasList = extrasRoot.querySelector('[data-lc-extras-list]');
    const extrasJsonInput = extrasRoot.querySelector('[data-lc-extras-json]');
    const addExtraBtn = extrasRoot.querySelector('[data-lc-extra-add]');
    const extrasForm = extrasRoot.closest('form');

    const generateExtraId = () => `extra_${Math.random().toString(36).slice(2, 10)}`;

    const renderExtraImagePreview = (row) => {
      if (!row) return;
      const imageInput = row.querySelector('[data-lc-extra-image]');
      const preview = row.querySelector('[data-lc-extra-image-preview]');
      if (!imageInput || !preview) return;
      const url = String(imageInput.value || '').trim();
      if (!url) {
        preview.innerHTML = '<span class="lc-extra-image-empty">Sin imagen</span>';
        return;
      }
      preview.innerHTML = `<img src="${escAttrUi(url)}" alt="Imagen del extra" loading="lazy" decoding="async" />`;
    };

    const createExtraRow = (item = {}) => {
      const row = document.createElement('div');
      row.className = 'lc-extra-row';
      row.setAttribute('data-lc-extra-row', '1');
      row.dataset.lcExtraId = String(item.id || generateExtraId());
      const imageValue = String(item.image || '').trim();
      row.innerHTML = `
        <div class="lc-extra-row-head">
          <strong>Extra</strong>
          <button type="button" class="button button-link-delete" data-lc-extra-remove>Eliminar</button>
        </div>
        <div class="lc-extra-grid">
          <label>Nombre
            <input type="text" data-lc-extra-name value="${escAttrUi(item.name || '')}" placeholder="Nombre del extra" />
          </label>
          <label>Precio
            <input type="text" data-lc-extra-price value="${escAttrUi(item.price != null ? item.price : '')}" placeholder="0" />
          </label>
          <label class="lc-extra-image-field">Imagen
            <input type="hidden" data-lc-extra-image value="${escAttrUi(imageValue)}" />
            <div class="lc-extra-image-picker">
              <div class="lc-extra-image-preview" data-lc-extra-image-preview>
                ${imageValue ? `<img src="${escAttrUi(imageValue)}" alt="Imagen del extra" loading="lazy" decoding="async" />` : '<span class="lc-extra-image-empty">Sin imagen</span>'}
              </div>
              <div class="lc-extra-image-actions">
                <button type="button" class="button" data-lc-extra-image-open>Añadir imagen desde la biblioteca</button>
                <button type="button" class="button button-link-delete" data-lc-extra-image-clear>Quitar</button>
              </div>
            </div>
          </label>
        </div>
      `;
      return row;
    };

    const syncExtrasJson = () => {
      if (!extrasJsonInput || !extrasList) return;
      const rows = Array.from(extrasList.querySelectorAll('[data-lc-extra-row]'))
        .map((row) => {
          const name = String(row.querySelector('[data-lc-extra-name]')?.value || '').trim();
          const price = String(row.querySelector('[data-lc-extra-price]')?.value || '').trim();
          const image = String(row.querySelector('[data-lc-extra-image]')?.value || '').trim();
          if (!name) return null;
          return {
            id: String(row.dataset.lcExtraId || generateExtraId()),
            name,
            price,
            image,
          };
        })
        .filter(Boolean);
      extrasJsonInput.value = JSON.stringify(rows);
    };

    const loadExtrasRows = () => {
      if (!extrasList || !extrasJsonInput) return;
      extrasList.innerHTML = '';
      let parsed = [];
      try {
        const decoded = JSON.parse(String(extrasJsonInput.value || '[]'));
        if (Array.isArray(decoded)) parsed = decoded;
      } catch (_) {
        parsed = [];
      }
      parsed.forEach((item) => {
        extrasList.appendChild(createExtraRow(item || {}));
      });
      syncExtrasJson();
    };

    if (addExtraBtn && extrasList) {
      addExtraBtn.addEventListener('click', () => {
        extrasList.appendChild(createExtraRow());
        syncExtrasJson();
      });
    }

      if (extrasList) {
        extrasList.addEventListener('click', (event) => {
          const removeBtn = event.target.closest('[data-lc-extra-remove]');
          if (removeBtn) {
            const row = removeBtn.closest('[data-lc-extra-row]');
            if (!row) return;
            row.remove();
            syncExtrasJson();
            return;
          }
          const openImageBtn = event.target.closest('[data-lc-extra-image-open]');
          if (openImageBtn) {
            event.preventDefault();
            const row = openImageBtn.closest('[data-lc-extra-row]');
            if (!row) return;
            const imageInput = row.querySelector('[data-lc-extra-image]');
            if (!imageInput) return;
            openWpMediaPicker('Selecciona imagen del extra', 'Usar imagen', (url) => {
              imageInput.value = String(url || '').trim();
              renderExtraImagePreview(row);
              syncExtrasJson();
            });
            return;
          }
          const clearImageBtn = event.target.closest('[data-lc-extra-image-clear]');
          if (clearImageBtn) {
            event.preventDefault();
            const row = clearImageBtn.closest('[data-lc-extra-row]');
            if (!row) return;
            const imageInput = row.querySelector('[data-lc-extra-image]');
            if (!imageInput) return;
            imageInput.value = '';
            renderExtraImagePreview(row);
            syncExtrasJson();
          }
        });
      extrasList.addEventListener('input', syncExtrasJson);
      extrasList.addEventListener('change', syncExtrasJson);
    }

    if (extrasForm) {
      extrasForm.addEventListener('submit', syncExtrasJson);
    }

    loadExtrasRows();
  }

  const extrasHoursToggle = document.querySelector('[data-lc-extras-hours-toggle]');
  const extrasHoursFields = document.querySelector('[data-lc-extras-hours-fields]');
  if (extrasHoursToggle && extrasHoursFields) {
    const syncExtrasHoursVisibility = () => {
      extrasHoursFields.classList.toggle('is-hidden', !extrasHoursToggle.checked);
    };
    extrasHoursToggle.addEventListener('change', syncExtrasHoursVisibility);
    syncExtrasHoursVisibility();
  }

  document.querySelectorAll('[data-lc-integration-card]').forEach((card) => {
    const toggle = card.querySelector('[data-lc-integration-toggle]');
    const label = card.querySelector('[data-lc-toggle-label]');
    if (!toggle || !label) return;
    const update = () => {
      card.classList.toggle('is-active', toggle.checked);
      label.textContent = toggle.checked ? 'Activo' : 'Inactivo';
    };
    toggle.addEventListener('change', update);
    update();
  });

  document.querySelectorAll('.lc-integration-sheet-form').forEach((form) => {
    const transferToggle = form.querySelector('[data-lc-transfer-toggle]');
    if (!transferToggle) return;
    form.addEventListener('submit', (event) => {
      if (form.dataset.lcTransferSubmitConfirmed === '1') return;
      const wasInitiallyActive = transferToggle.getAttribute('data-lc-initial-active') === '1';
      const willActivate = !!transferToggle.checked;
      if (wasInitiallyActive || !willActivate) return;
      event.preventDefault();
      const confirmSubmit = () => {
        form.dataset.lcTransferSubmitConfirmed = '1';
        form.submit();
      };
      openConfirmModal({
        title: 'Activar Transferencia Bancaria',
        text: 'Al activar este medio de pago, las nuevas reservas quedarán en estado Pendiente. La fecha y hora quedarán bloqueadas (no disponibles) hasta que confirmes la recepción del pago o canceles la reserva manualmente para liberar el horario.',
        confirmText: 'Sí, activar transferencia',
        confirmClass: 'button button-primary',
        onConfirm: confirmSubmit,
      });
    });
  });

  document.querySelectorAll('select[name="stripe_mode"]').forEach((select) => {
    const root = select.closest('.lc-integration-fields') || select.closest('.lc-integration-modal-content') || select.closest('form');
    if (!root) return;
    const updateStripeMode = () => {
      const mode = select.value === 'live' ? 'live' : 'test';
      root.querySelectorAll('[data-lc-stripe-mode-field]').forEach((row) => {
        const rowMode = row.getAttribute('data-lc-stripe-mode-field') || 'test';
        row.style.display = rowMode === mode ? '' : 'none';
      });
    };
    select.addEventListener('change', updateStripeMode);
    updateStripeMode();
  });

  document.querySelectorAll('select[name="google_calendar_mode"]').forEach((select) => {
    const root = select.closest('.lc-integration-fields') || select.closest('.lc-integration-modal-content') || select.closest('form');
    if (!root) return;
    const centralWrap = root.querySelector('[data-lc-google-calendar-central]');
    const perEmployeeWrap = root.querySelector('[data-lc-google-calendar-per-employee]');
    const updateGoogleCalendarMode = () => {
      const mode = select.value === 'per_employee' ? 'per_employee' : 'centralized';
      if (centralWrap) {
        centralWrap.style.display = mode === 'centralized' ? '' : 'none';
      }
      if (perEmployeeWrap) {
        perEmployeeWrap.style.display = mode === 'per_employee' ? '' : 'none';
      }
    };
    select.addEventListener('change', updateGoogleCalendarMode);
    updateGoogleCalendarMode();
  });

  const modal = document.querySelector('[data-lc-modal]');
  if (modal) {
    modal.classList.remove('is-open');
    const body = modal.querySelector('[data-lc-modal-body]');
    const title = modal.querySelector('[data-lc-modal-title]');
    const defaultModalTitle = title ? String(title.textContent || '').trim() : 'Detalle';
    const closeBtns = modal.querySelectorAll('[data-lc-modal-close]');
    closeBtns.forEach((btn) =>
      btn.addEventListener('click', () => {
        if (title) title.textContent = defaultModalTitle;
        modal.classList.remove('is-open');
      })
    );
    document.querySelectorAll('.lc-detail-btn').forEach((btn) => {
      btn.addEventListener('click', () => {
        const raw = btn.getAttribute('data-lc-detail') || '{}';
        let data = {};
        try {
          data = JSON.parse(raw);
        } catch (e) {
          data = {};
        }
        if (title) {
          title.textContent = 'Detalle de la reserva';
        }
        if (body) {
          const paymentProviderLabel = data.payment_provider ? (providerLabels[data.payment_provider] || data.payment_provider) : '-';
          const rows = [
            ['Reserva', `#${data.id || '-'}`],
            ['Servicio', data.event || '-'],
            ['Cliente', data.name || '-'],
            ['Email', data.email || '-'],
            ['Teléfono', data.phone || '-'],
            ['Empresa', data.company || '-'],
            ['Fecha inicio', data.start || '-'],
            ['Fecha fin', data.end || '-'],
            ['Estado', data.status || '-'],
            ['Proveedor', paymentProviderLabel],
            ['Estado pago', data.payment_status || '-'],
            ['Monto', data.payment_amount || '-'],
            ['Referencia', data.payment_reference || '-'],
            ['Error', data.payment_error || '-'],
            ['Modificada manualmente', data.manual_modified || '-'],
            ['Fecha modificacion', data.manual_modified_at || '-'],
            ['Nota interna', data.message || '-'],
          ];
          body.innerHTML = rows
            .map(
              (row) =>
                `<div class="lc-modal-row"><strong>${escHtmlUi(row[0])}</strong><small>${escHtmlUi(row[1])}</small></div>`
            )
            .join('');
        }
        modal.classList.add('is-open');
      });
    });

    document.addEventListener('click', async (event) => {
      const trigger = event.target.closest('[data-lc-customer-history]');
      if (!trigger) return;
      event.preventDefault();
      const url = String(trigger.getAttribute('data-lc-customer-history') || '').trim();
      if (!url || !body) return;
      if (title) {
        title.textContent = 'Historial del cliente';
      }
      body.innerHTML = '<div class="lc-modal-loading">Cargando historial...</div>';
      modal.classList.add('is-open');
      try {
        const response = await fetch(url, { credentials: 'same-origin' });
        const payload = await response.json();
        if (!response.ok || !payload?.success) {
          throw new Error('history_error');
        }
        if (title) {
          title.textContent = String(payload?.data?.title || 'Historial del cliente');
        }
        body.innerHTML = String(payload?.data?.html || '<div class="lc-empty-state"><div>Sin historial.</div></div>');
      } catch (_error) {
        if (title) {
          title.textContent = 'Historial del cliente';
        }
        body.innerHTML = '<div class="lc-empty-state"><div>No se pudo cargar el historial del cliente.</div></div>';
      }
    });

    document.addEventListener('click', async (event) => {
      const trigger = event.target.closest('[data-lc-customer-unlock]');
      if (!trigger) return;
      event.preventDefault();
      const url = String(trigger.getAttribute('data-lc-customer-unlock') || '').trim();
      if (!url || !body) return;
      trigger.disabled = true;
      try {
        const response = await fetch(url, {
          method: 'POST',
          credentials: 'same-origin',
        });
        const payload = await response.json();
        if (!response.ok || !payload?.success) {
          throw new Error('unlock_error');
        }
        if (title) {
          title.textContent = String(payload?.data?.title || 'Historial del cliente');
        }
        body.innerHTML = String(payload?.data?.html || '<div class="lc-empty-state"><div>Sin historial.</div></div>');
        notyf.success(String(payload?.data?.message || 'Cliente desbloqueado correctamente.'));
      } catch (_error) {
        notyf.error('No se pudo desbloquear al cliente.');
      } finally {
        trigger.disabled = false;
      }
    });

    document.addEventListener('click', async (event) => {
      const trigger = event.target.closest('[data-lc-customer-block]');
      if (!trigger) return;
      event.preventDefault();
      const url = String(trigger.getAttribute('data-lc-customer-block') || '').trim();
      if (!url || !body) return;
      trigger.disabled = true;
      try {
        const response = await fetch(url, {
          method: 'POST',
          credentials: 'same-origin',
        });
        const payload = await response.json();
        if (!response.ok || !payload?.success) {
          throw new Error('block_error');
        }
        if (title) {
          title.textContent = String(payload?.data?.title || 'Historial del cliente');
        }
        body.innerHTML = String(payload?.data?.html || '<div class="lc-empty-state"><div>Sin historial.</div></div>');
        notyf.success(String(payload?.data?.message || 'Cliente bloqueado manualmente.'));
      } catch (_error) {
        notyf.error('No se pudo bloquear al cliente.');
      } finally {
        trigger.disabled = false;
      }
    });
  }

  document.querySelectorAll('[data-lc-auto-submit]').forEach((select) => {
    select.addEventListener('change', () => {
      if (select.form) select.form.submit();
    });
  });

  const closeAllSelects = () => {
    document.querySelectorAll('[data-lc-select].is-open').forEach((sel) => {
      sel.classList.remove('is-open');
    });
  };

  const getSelectInput = (select) => {
    if (!select) return null;
    return (
      select.querySelector('[data-lc-select-input]') ||
      select.closest('.lc-card, .lc-panel')?.querySelector('[data-lc-select-input]') ||
      null
    );
  };

  const syncSelectFromValue = (select) => {
    const input = getSelectInput(select);
    const triggerEl = select.querySelector('[data-lc-select-trigger]');
    const triggerIcon = triggerEl ? triggerEl.querySelector('.lc-select-icon') : null;
    const triggerText = triggerEl ? triggerEl.querySelector('.lc-select-text') : null;
    if (!input || !triggerEl) return;
    const value = input.value;
    if (!value) return;
    select.dataset.value = value;
    const options = select.querySelectorAll('[data-lc-select-option]');
    options.forEach((opt) => opt.classList.remove('is-selected'));
    const matched = select.querySelector(`[data-lc-select-option][data-value="${value}"]`);
    if (matched) {
      matched.classList.add('is-selected');
      const icon = matched.querySelector('.lc-select-option-icon');
      const text = matched.querySelector('.lc-select-option-text');
      if (triggerIcon && icon) triggerIcon.innerHTML = icon.innerHTML;
      if (triggerText && text) triggerText.textContent = text.textContent;
    }
  };

  document.querySelectorAll('[data-lc-select]').forEach(syncSelectFromValue);

  const handleSelectOption = (option) => {
    if (option.classList.contains('is-disabled') || option.getAttribute('aria-disabled') === 'true') {
      return;
    }
    const select = option.closest('[data-lc-select]');
    if (!select) return;
    if (select.hasAttribute('data-lc-select-multi')) {
      const input = option.querySelector('input[type="checkbox"]');
      if (input) {
        input.checked = !input.checked;
        input.dispatchEvent(new Event('change', { bubbles: true }));
      }
      return;
    }
    const input = getSelectInput(select);
    const triggerEl = select.querySelector('[data-lc-select-trigger]');
    const triggerIcon = triggerEl ? triggerEl.querySelector('.lc-select-icon') : null;
    const triggerText = triggerEl ? triggerEl.querySelector('.lc-select-text') : null;
    const icon = option.querySelector('.lc-select-option-icon');
    const text = option.querySelector('.lc-select-option-text');
    const value = option.getAttribute('data-value') || '';
    select.dataset.value = value;
    if (input) {
      input.value = value;
      input.setAttribute('value', value);
      input.dispatchEvent(new Event('change', { bubbles: true }));
    }
    const selectName = select.getAttribute('data-lc-select-name');
    if (selectName && selectName.includes('[]')) {
      const form = select.closest('form');
      if (form) {
        form.querySelectorAll(`input[data-lc-select-hidden][name="${selectName}"]`).forEach((el) => el.remove());
        const hidden = document.createElement('input');
        hidden.type = 'hidden';
        hidden.name = selectName;
        hidden.value = value;
        hidden.setAttribute('data-lc-select-hidden', '1');
        form.appendChild(hidden);
      }
    }
    if (triggerIcon && icon) triggerIcon.innerHTML = icon.innerHTML;
    if (triggerText && text) triggerText.textContent = text.textContent;
    select.querySelectorAll('[data-lc-select-option]').forEach((opt) => opt.classList.remove('is-selected'));
    option.classList.add('is-selected');
    select.classList.remove('is-open');
    if (input && input.hasAttribute('data-lc-location-input')) {
      input.dispatchEvent(new Event('change', { bubbles: true }));
      toggleLocationDetails();
    }
  };

  document.addEventListener('pointerdown', (event) => {
    const option = event.target.closest('[data-lc-select-option]');
    if (option) {
      event.preventDefault();
      handleSelectOption(option);
    }
  });

  document.addEventListener('click', (event) => {
    const trigger = event.target.closest('[data-lc-select-trigger]');
    const option = event.target.closest('[data-lc-select-option]');

    if (trigger) {
      event.preventDefault();
      const select = trigger.closest('[data-lc-select]');
      if (!select) return;
      const isOpen = select.classList.contains('is-open');
      closeAllSelects();
      if (!isOpen) select.classList.add('is-open');
      return;
    }

    if (option) {
      event.preventDefault();
      handleSelectOption(option);
      return;
    }

    closeAllSelects();
  });

  document.addEventListener('keydown', (event) => {
    if (event.key === 'Escape') closeAllSelects();
  });

  document.querySelectorAll('form').forEach((form) => {
    form.addEventListener('submit', () => {
      form.querySelectorAll('[data-lc-select]').forEach((select) => {
        const input = getSelectInput(select);
        const selectName = select.getAttribute('data-lc-select-name');
        const selected = select.querySelector('[data-lc-select-option].is-selected');
        const fallback = select.querySelector('[data-lc-select-option]');
        const value =
          select.dataset.value ||
          (selected || fallback)?.getAttribute('data-value') ||
          (input ? input.value : '') ||
          '';
        if (value) {
          if (input) {
            input.value = value;
            input.setAttribute('value', value);
          }
          if (selectName && selectName.includes('[]')) {
            form.querySelectorAll(`input[data-lc-select-hidden][name="${selectName}"]`).forEach((el) => el.remove());
            const hidden = document.createElement('input');
            hidden.type = 'hidden';
            hidden.name = selectName;
            hidden.value = value;
            hidden.setAttribute('data-lc-select-hidden', '1');
            form.appendChild(hidden);
          }
        }
      });
    });
  });

  document.querySelectorAll('[data-lc-select-multi]').forEach((select) => {
    const textEl = select.querySelector('[data-lc-select-multi-text]');
    const update = () => {
      const checked = Array.from(select.querySelectorAll('[data-lc-multi-option]:checked')).map(
        (el) => ({
          label: el.getAttribute('data-label') || '',
          logo: el.getAttribute('data-logo') || '',
        })
      );
      if (textEl) {
        if (!checked.length) {
          textEl.textContent = 'Selecciona medios de pago';
        } else {
          textEl.innerHTML = checked
            .map((item) => {
              const logo = item.logo
                ? `<img class="lc-chip-logo" src="${escUrlUi(item.logo)}" alt="${escAttrUi(item.label)}"/>`
                : '';
              return `<span class="lc-chip">${logo}${escHtmlUi(item.label)}</span>`;
            })
            .join('');
        }
      }
    };
    select.querySelectorAll('[data-lc-multi-option]').forEach((input) => {
      input.addEventListener('change', update);
    });
    update();
  });

  function updateMasterCheckbox(table) {
    if (!table) return;
    const master = table.querySelector('[data-lc-select-all]');
    if (!master) return;
    const boxes = Array.from(table.querySelectorAll('tbody [data-lc-select-id]'));
    if (!boxes.length) {
      master.checked = false;
      master.indeterminate = false;
      return;
    }
    const checked = boxes.filter((b) => b.checked).length;
    master.checked = checked > 0 && checked === boxes.length;
    master.indeterminate = checked > 0 && checked < boxes.length;
  }

  document.addEventListener('change', (event) => {
    const master = event.target.closest('[data-lc-select-all]');
    if (master) {
      const table = master.closest('table');
      if (!table) return;
      const selectedIds = getTableSelection(table);
      table.querySelectorAll('tbody [data-lc-select-id]').forEach((box) => {
        box.checked = master.checked;
        const key = String(box.value || '');
        if (!key) return;
        if (master.checked) {
          selectedIds.add(key);
        } else {
          selectedIds.delete(key);
        }
      });
      updateMasterCheckbox(table);
      return;
    }
    const rowBox = event.target.closest('[data-lc-select-id]');
    if (rowBox) {
      const table = rowBox.closest('table');
      const selectedIds = getTableSelection(table);
      const key = String(rowBox.value || '');
      if (key) {
        if (rowBox.checked) {
          selectedIds.add(key);
        } else {
          selectedIds.delete(key);
        }
      }
      updateMasterCheckbox(table);
    }
  });

  function ensureConfirmModal() {
    let modal = document.querySelector('[data-lc-confirm]');
    if (modal) return modal;
    modal = document.createElement('div');
    modal.className = 'lc-confirm';
    modal.setAttribute('data-lc-confirm', '1');
    modal.innerHTML = `
      <div class="lc-confirm-backdrop" data-lc-confirm-close></div>
      <div class="lc-confirm-card">
        <div class="lc-confirm-title">¿Eliminar elementos?</div>
        <div class="lc-confirm-text">Esta acción no se puede deshacer.</div>
        <div class="lc-confirm-actions">
          <button class="button" data-lc-confirm-close>Cancelar</button>
          <button class="button button-link-delete" data-lc-confirm-yes>Eliminar</button>
        </div>
      </div>
    `;
    document.body.appendChild(modal);
    modal.querySelectorAll('[data-lc-confirm-close]').forEach((btn) => {
      btn.addEventListener('click', () => modal.classList.remove('is-open'));
    });
    return modal;
  }

  function trashConfirmOptions(isBulk = false, entity = 'bookings') {
    if (entity === 'customers') {
      return {
        title: '¿Mover a papelera?',
        text: isBulk
          ? 'Los clientes seleccionados se enviarán a la papelera junto con su historial de reservas visible en esta vista. Podrás restaurarlos desde “Ver papelera”.'
          : 'Este cliente se enviará a la papelera junto con su historial de reservas visible en esta vista. Podrás restaurarlo desde “Ver papelera”.',
        confirmText: 'Mover a papelera',
        confirmClass: 'button button-primary',
      };
    }
    return {
      title: '¿Mover a papelera?',
      text: isBulk
        ? 'Las reservas seleccionadas se enviarán a la papelera. Podrás restaurarlas desde “Ver papelera”.'
        : 'Esta reserva se enviará a la papelera. Podrás restaurarla desde “Ver papelera”.',
      confirmText: 'Mover a papelera',
      confirmClass: 'button button-primary',
    };
  }

  function permanentDeleteConfirmOptions(isBulk = false, entity = 'bookings') {
    if (entity === 'customers') {
      return {
        title: isBulk ? '¿Eliminar permanentemente los clientes seleccionados?' : '¿Eliminar permanentemente este cliente?',
        text: 'Se eliminará todo el historial de reservas asociado a estos correos. Esta acción no se puede deshacer ni recuperar.',
        confirmText: 'Eliminar permanentemente',
        confirmClass: 'button button-link-delete',
      };
    }
    return {
      title: isBulk ? '¿Eliminar permanentemente los elementos seleccionados?' : '¿Eliminar permanentemente este elemento?',
      text: 'Esta acción no se puede deshacer ni recuperar.',
      confirmText: 'Eliminar permanentemente',
      confirmClass: 'button button-link-delete',
    };
  }

  function openConfirmModal(options = {}) {
    const modal = ensureConfirmModal();
    const titleEl = modal.querySelector('.lc-confirm-title');
    const textEl = modal.querySelector('.lc-confirm-text');
    const yesBtn = modal.querySelector('[data-lc-confirm-yes]');
    const closeBtns = Array.from(modal.querySelectorAll('[data-lc-confirm-close]'));
    const cancelBtns = closeBtns.filter((btn) => String(btn.tagName || '').toUpperCase() === 'BUTTON');
    const defaultTitle = '¿Eliminar elementos?';
    const defaultText = 'Esta acción no se puede deshacer.';
    const defaultConfirmText = 'Eliminar';
    const defaultConfirmClass = 'button button-link-delete';
    const defaultCancelText = 'Cancelar';
    const title = String(options.title || defaultTitle);
    const text = String(options.text || defaultText);
    const confirmText = String(options.confirmText || defaultConfirmText);
    const confirmClass = String(options.confirmClass || defaultConfirmClass);
    const cancelText = String(options.cancelText || defaultCancelText);

    if (titleEl) titleEl.textContent = title;
    if (textEl) textEl.textContent = text;
    if (yesBtn) {
      yesBtn.textContent = confirmText;
      yesBtn.className = confirmClass;
    }
    cancelBtns.forEach((btn) => {
      if (btn !== yesBtn) {
        btn.textContent = cancelText;
      }
    });

    const resetModal = () => {
      if (titleEl) titleEl.textContent = defaultTitle;
      if (textEl) textEl.textContent = defaultText;
      if (yesBtn) {
        yesBtn.textContent = defaultConfirmText;
        yesBtn.className = defaultConfirmClass;
        yesBtn.onclick = null;
      }
      cancelBtns.forEach((btn) => {
        if (btn !== yesBtn) {
          btn.textContent = defaultCancelText;
        }
      });
    };

    const handleCancel = () => {
      resetModal();
      if (typeof options.onCancel === 'function') {
        options.onCancel();
      }
    };
    closeBtns.forEach((btn) => {
      btn.addEventListener('click', handleCancel, { once: true });
    });

    if (yesBtn) {
      yesBtn.onclick = () => {
        modal.classList.remove('is-open');
        resetModal();
        if (typeof options.onConfirm === 'function') {
          options.onConfirm();
        }
      };
    }

    modal.classList.add('is-open');
    return modal;
  }

  document.querySelectorAll('[data-lc-delete-form]').forEach((form) => {
    const idsInput = form.querySelector('[data-lc-delete-ids]');
    const scope = form.closest('.lc-admin') || document;
    if (scope.querySelector('[data-lc-gridjs]')) return;
    const table = scope.querySelector('table');
    if (!table || !idsInput) return;
    table.addEventListener('click', (event) => {
      const btn = event.target.closest('[data-lc-delete-id]');
      if (!btn) return;
      idsInput.value = btn.getAttribute('data-lc-delete-id') || '';
      if (!idsInput.value) return;
      const redirectViewInput = form.querySelector('input[name="redirect_view"]');
      const isTrashView = String(redirectViewInput?.value || '').trim() === 'trash';
      const confirmOptions = isTrashView ? {} : trashConfirmOptions(false);
      openConfirmModal({
        ...confirmOptions,
        onConfirm: () => {
          form.submit();
        },
      });
    });
  });

  document.querySelectorAll('[data-lc-bulk-form]').forEach((form) => {
    const idsInput = form.querySelector('[data-lc-bulk-ids]');
    const scope = form.closest('.lc-admin') || document;
    if (scope.querySelector('[data-lc-gridjs]')) return;
    const table = scope.querySelector('table');
    if (!table || !idsInput) return;
    const getSelected = () => {
      const fromStore = Array.from(getTableSelection(table));
      if (fromStore.length) return fromStore;
      return Array.from(scope.querySelectorAll('[data-lc-select-id]:checked')).map((b) => b.value);
    };
    form.addEventListener('submit', (e) => {
      const ids = getSelected();
      if (!ids.length) {
        e.preventDefault();
        return;
      }
      const bulkSelect = form.querySelector('select[name="bulk_status"]');
      if (bulkSelect && (bulkSelect.value === 'delete' || bulkSelect.value === 'delete_permanent')) {
        e.preventDefault();
        const isPermanentDelete = bulkSelect.value === 'delete_permanent';
        const confirmOptions = isPermanentDelete ? permanentDeleteConfirmOptions(true) : trashConfirmOptions(true);
        openConfirmModal({
          ...confirmOptions,
          onConfirm: () => {
            idsInput.value = ids.join(',');
            form.submit();
          },
        });
        return;
      }
      idsInput.value = ids.join(',');
    });
  });

  const calendarApp = document.querySelector('[data-lc-calendar-app]');
  const stageEl = document.querySelector('[data-lc-cal-stage]');
  const miniEl = document.querySelector('[data-lc-cal-mini]');
  const rangeEl = document.querySelector('[data-lc-cal-range]');
  const todayBtn = document.querySelector('[data-lc-cal-today]');
  const prevBtn = document.querySelector('[data-lc-cal-prev]');
  const nextBtn = document.querySelector('[data-lc-cal-next]');
  const viewWrap = document.querySelector('[data-lc-cal-views]');
  const statusFilterEl = document.querySelector('[data-lc-cal-filter-status]');
  const employeeFilterEl = document.querySelector('[data-lc-cal-filter-employee]');
  const eventFilterEl = document.querySelector('[data-lc-cal-filter-event]');
  const dragHostEl = document.querySelector('[data-lc-cal-drag-host]');
  const dragChipEl = document.querySelector('[data-lc-cal-drag-chip]');
  const dragCancelEl = document.querySelector('[data-lc-cal-drag-cancel]');
  const slotModalEl = document.querySelector('[data-lc-cal-slot-modal]');
  const slotCloseEl = document.querySelector('[data-lc-cal-slot-close]');
  const slotCancelEl = document.querySelector('[data-lc-cal-slot-cancel]');
  const slotApplyEl = document.querySelector('[data-lc-cal-slot-apply]');
  const slotDateEl = document.querySelector('[data-lc-cal-slot-date]');
  const slotListEl = document.querySelector('[data-lc-cal-slot-list]');
  const detailModalEl = document.querySelector('[data-lc-cal-modal]');
  const detailViewEl = document.querySelector('[data-lc-cal-detail-view]');
  const detailCloseBtn = document.querySelector('[data-lc-cal-modal-close]');
  const popoverEl = document.querySelector('[data-lc-cal-popover]');
  const bookingDetailEl = document.querySelector('[data-lc-booking-detail]');
  const idInput = document.querySelector('[data-lc-booking-id]');
  const statusSelect = document.querySelector('[data-lc-booking-status]');
  const startInput = document.querySelector('[data-lc-booking-start]');
  const endInput = document.querySelector('[data-lc-booking-end]');
  const nameInput = document.querySelector('[data-lc-booking-name]');
  const emailInput = document.querySelector('[data-lc-booking-email]');
  const phoneInput = document.querySelector('[data-lc-booking-phone]');
  const messageInput = document.querySelector('[data-lc-booking-message]');
  const employeeSelect = document.querySelector('[data-lc-booking-employee]');
  const calendarForm = document.querySelector('.lc-calendar-form');
  const reschedulePanel = document.querySelector('[data-lc-reschedule-panel]');
  const bookingStartViewInput = document.querySelector('[data-lc-booking-start-view]');
  const bookingEndViewInput = document.querySelector('[data-lc-booking-end-view]');
  const bookingDateInput = document.querySelector('[data-lc-booking-date]');
  const bookingTimeStartInput = document.querySelector('[data-lc-booking-time-start]');
  const bookingTimeEndInput = document.querySelector('[data-lc-booking-time-end]');

  const scopeSelect = document.querySelector('[data-lc-scope]');
  const scopeEvent = document.querySelector('[data-lc-scope-event]');
  const scopeEmployee = document.querySelector('[data-lc-scope-employee]');
  const timeoffScope = document.querySelector('[data-lc-timeoff-scope]');
  const timeoffEmployee = document.querySelector('[data-lc-timeoff-employee]');

  if (scopeSelect) {
    const toggleScope = () => {
      if (scopeEvent) scopeEvent.classList.add('is-hidden');
      if (scopeEmployee) scopeEmployee.classList.add('is-hidden');
      if (scopeSelect.value === 'event' && scopeEvent) {
        scopeEvent.classList.remove('is-hidden');
      }
      if (scopeSelect.value === 'employee' && scopeEmployee) {
        scopeEmployee.classList.remove('is-hidden');
      }
    };
    scopeSelect.addEventListener('change', toggleScope);
    toggleScope();
  }

  if (timeoffScope) {
    const toggleTimeoff = () => {
      if (timeoffEmployee) timeoffEmployee.classList.add('is-hidden');
      if (timeoffScope.value === 'employee' && timeoffEmployee) {
        timeoffEmployee.classList.remove('is-hidden');
      }
    };
    timeoffScope.addEventListener('change', toggleTimeoff);
    toggleTimeoff();
  }

  if (!calendarApp || !window.litecalAdmin || !stageEl || !bookingDetailEl) {
    return;
  }

  const adminTimeFormat = window.litecalAdmin && window.litecalAdmin.timeFormat === '24h' ? '24h' : '12h';
  const formatAdminTime = (dateObj) => {
    if (!(dateObj instanceof Date) || Number.isNaN(dateObj.getTime())) return '';
    if (adminTimeFormat === '24h') {
      return dateObj.toLocaleTimeString('en-GB', { hour: '2-digit', minute: '2-digit', hour12: false });
    }
    return dateObj
      .toLocaleTimeString('en-US', { hour: 'numeric', minute: '2-digit', hour12: true })
      .replace(/\s/g, '')
      .toLowerCase();
  };

  let bookings = [];
  const bookingMap = {};
  const requestedQuery = new URLSearchParams(window.location.search);
  const requestedBookingId = parseInt(requestedQuery.get('booking_id') || '', 10) || 0;
  const requestedDate = /^\d{4}-\d{2}-\d{2}$/.test(requestedQuery.get('calendar_date') || '') ? requestedQuery.get('calendar_date') : '';
  const today = new Date();
  const state = {
    view: 'month',
    currentDate: requestedDate ? new Date(`${requestedDate}T12:00:00`) : new Date(today.getFullYear(), today.getMonth(), today.getDate()),
    selectedDate: requestedDate || '',
    mode: 'calendar',
    filters: {
      status: '',
      employee: '',
      event: '',
    },
    focusBookingId: requestedBookingId || 0,
    dragBookingId: 0,
    dragBookingData: null,
  };

  const setBookings = (list) => {
    bookings = Array.isArray(list) ? list : [];
    Object.keys(bookingMap).forEach((key) => {
      delete bookingMap[key];
    });
    bookings.forEach((booking) => {
      bookingMap[String(booking.id)] = booking;
    });
  };
  setBookings(bookings);

  const formatDate = (date) => {
    const y = date.getFullYear();
    const m = String(date.getMonth() + 1).padStart(2, '0');
    const d = String(date.getDate()).padStart(2, '0');
    return `${y}-${m}-${d}`;
  };

  const parseDateOnly = (dateStr) => {
    if (!/^\d{4}-\d{2}-\d{2}$/.test(dateStr || '')) return null;
    return new Date(`${dateStr}T12:00:00`);
  };

  const toHumanDateTime = (value) => {
    if (!value || typeof value !== 'string') return value || '';
    const [datePart, timePart = ''] = value.split(' ');
    const dateObj = parseDateOnly(datePart);
    if (!dateObj) return value;
    const dateText = dateObj.toLocaleDateString('es-CL', {
      day: '2-digit',
      month: '2-digit',
      year: 'numeric',
    });
    return `${dateText} ${timePart}`.trim();
  };
  const parseDateTime = (value) => {
    if (!value || typeof value !== 'string') return null;
    const normalized = value.replace(' ', 'T');
    const dt = new Date(normalized);
    return Number.isNaN(dt.getTime()) ? null : dt;
  };

  let popoverOverlayEl = null;
  let popoverMode = 'anchored';
  let popoverCloseTimer = 0;
  const clearPopoverCloseTimer = () => {
    if (!popoverCloseTimer) return;
    window.clearTimeout(popoverCloseTimer);
    popoverCloseTimer = 0;
  };
  if (popoverEl) {
    popoverOverlayEl = document.createElement('div');
    popoverOverlayEl.className = 'lc-cal-popover-overlay';
    popoverOverlayEl.setAttribute('data-lc-cal-popover-overlay', '');
    popoverOverlayEl.hidden = true;
    document.body.appendChild(popoverOverlayEl);
  }
  const formatHumanDate = (value) => {
    const dt = parseDateTime(value);
    if (!dt) return '';
    return dt.toLocaleDateString('es-CL', {
      day: '2-digit',
      month: 'short',
      year: 'numeric',
    }).replace('.', '');
  };
  const formatHour = (value) => {
    if (!value || typeof value !== 'string') return '';
    const dt = parseDateTime(value);
    if (dt) {
      return formatAdminTime(dt);
    }
    const parts = value.split(' ');
    if (!parts[1]) return '';
    const rawTime = parts[1].slice(0, 5);
    if (adminTimeFormat === '24h') {
      return rawTime;
    }
    const fallbackDate = new Date(`2000-01-01T${rawTime}:00`);
    if (!Number.isNaN(fallbackDate.getTime())) {
      return formatAdminTime(fallbackDate);
    }
    return rawTime;
  };
  const formatHumanRange = (start, end) => {
    const dateText = formatHumanDate(start);
    const startHour = formatHour(start);
    const endHour = formatHour(end);
    if (!dateText) return '-';
    if (!startHour && !endHour) return dateText;
    return `${dateText} · ${startHour || '--:--'} - ${endHour || '--:--'}`;
  };
  const splitDateAndTime = (value) => {
    const str = String(value || '');
    const [datePart = '', timePart = ''] = str.split(' ');
    return { date: datePart, time: timePart.slice(0, 5) };
  };
  const buildDateTime = (datePart, timePart) => {
    if (!datePart || !timePart) return '';
    return `${datePart} ${timePart}:00`;
  };
  const startOfWeek = (date) => {
    const copy = new Date(date.getFullYear(), date.getMonth(), date.getDate());
    const day = (copy.getDay() + 6) % 7;
    copy.setDate(copy.getDate() - day);
    return copy;
  };
  const endOfWeek = (date) => {
    const start = startOfWeek(date);
    const end = new Date(start);
    end.setDate(end.getDate() + 6);
    return end;
  };
  const addDays = (date, days) => {
    const next = new Date(date);
    next.setDate(next.getDate() + days);
    return next;
  };
  const rangeLabel = (from, to, view) => {
    if (view === 'month') {
      return state.currentDate.toLocaleDateString('es-CL', { month: 'long', year: 'numeric' });
    }
    if (view === 'day') {
      return from.toLocaleDateString('es-CL', { day: '2-digit', month: 'long', year: 'numeric' });
    }
    return `${from.toLocaleDateString('es-CL', { day: '2-digit', month: 'short' })} - ${to.toLocaleDateString('es-CL', { day: '2-digit', month: 'short', year: 'numeric' })}`.replace(/\./g, '');
  };
  const bookingDateOnly = (booking) => String(booking.start || '').split(' ')[0] || '';
  const bookingStartTs = (booking) => {
    const dt = parseDateTime(booking.start || '');
    return dt ? dt.getTime() : 0;
  };
  const formatSlotTime = (value) => {
    const str = String(value || '').trim();
    if (!str) return '--:--';
    if (/^\d{2}:\d{2}/.test(str)) {
      const rawTime = str.slice(0, 5);
      if (adminTimeFormat === '24h') {
        return rawTime;
      }
      const fallbackDate = new Date(`2000-01-01T${rawTime}:00`);
      return Number.isNaN(fallbackDate.getTime()) ? rawTime : formatAdminTime(fallbackDate);
    }
    const withDate = parseDateTime(str);
    if (!withDate) return str;
    return formatAdminTime(withDate);
  };
  const canUseDragReschedule = () => state.view === 'month' || state.view === 'week' || state.view === 'day';
  const getDragBooking = () => bookingMap[String(state.dragBookingId || 0)] || state.dragBookingData || null;
  const slotPickerState = {
    bookingId: 0,
    bookingData: null,
    date: '',
    slots: [],
    selectedStart: '',
    loading: false,
  };
  const setActionButtonLoading = (button, loading) => {
    if (!button) return;
    if (loading) {
      button.classList.add('lc-btn-loading');
      button.disabled = true;
      button.setAttribute('aria-busy', 'true');
      return;
    }
    button.classList.remove('lc-btn-loading');
    button.removeAttribute('aria-busy');
    button.disabled = false;
  };
  const restRoot = (() => {
    const root = (window.wpApiSettings && window.wpApiSettings.root) ? window.wpApiSettings.root : `${window.location.origin}/wp-json/`;
    return `${String(root).replace(/\/$/, '')}/litecal/v1`;
  })();
  const closeSlotPicker = () => {
    slotPickerState.bookingId = 0;
    slotPickerState.bookingData = null;
    slotPickerState.date = '';
    slotPickerState.slots = [];
    slotPickerState.selectedStart = '';
    slotPickerState.loading = false;
    if (slotApplyEl) {
      setActionButtonLoading(slotApplyEl, false);
      slotApplyEl.disabled = true;
    }
    if (slotListEl) slotListEl.innerHTML = '';
    if (slotDateEl) slotDateEl.textContent = '';
    if (slotModalEl) slotModalEl.hidden = true;
  };
  const renderSlotPicker = () => {
    if (!slotListEl) return;
    if (slotPickerState.loading) {
      slotListEl.innerHTML = '<div class="lc-cal-slot-empty">Cargando horarios...</div>';
      return;
    }
    const slots = Array.isArray(slotPickerState.slots) ? slotPickerState.slots : [];
    if (!slots.length) {
      slotListEl.innerHTML = '<div class="lc-cal-slot-empty">No hay horarios disponibles para este día.</div>';
      if (slotApplyEl) {
        setActionButtonLoading(slotApplyEl, false);
        slotApplyEl.disabled = true;
      }
      return;
    }
    const hasAvailable = slots.some((slot) => String(slot.status || '') === 'available');
    slotListEl.innerHTML = '';
    slots.forEach((slot) => {
      const btn = document.createElement('button');
      btn.type = 'button';
      btn.className = 'lc-slot';
      const isAvailable = String(slot.status || '') === 'available';
      if (!isAvailable) {
        btn.classList.add('is-unavailable');
        btn.disabled = true;
      }
      if (slotPickerState.selectedStart === slot.start) {
        btn.classList.add('is-selected');
      }
      btn.innerHTML = `
        <span class="dot"></span>
        <span>${escHtml(formatSlotTime(slot.start))}</span>
      `;
      btn.addEventListener('click', () => {
        if (!isAvailable) return;
        slotPickerState.selectedStart = slot.start;
        renderSlotPicker();
      });
      slotListEl.appendChild(btn);
    });
    if (slotApplyEl) {
      setActionButtonLoading(slotApplyEl, false);
      slotApplyEl.disabled = !hasAvailable || !slotPickerState.selectedStart;
    }
  };
  const openSlotPicker = (booking, dateStr) => {
    if (!slotModalEl || !slotListEl || !slotDateEl || !booking || !dateStr) return;
    const eventId = parseInt(booking.event_id, 10) || 0;
    if (!eventId) {
      if (notyf) notyf.error('No se pudo identificar el servicio para reagendar.');
      return;
    }
    const employeeId = parseInt(booking.employee_id, 10) || 0;
    slotPickerState.bookingId = parseInt(booking.id, 10) || 0;
    slotPickerState.bookingData = booking ? { ...booking } : null;
    slotPickerState.date = dateStr;
    slotPickerState.slots = [];
    slotPickerState.selectedStart = '';
    slotPickerState.loading = true;
    const dayDate = parseDateOnly(dateStr);
    slotDateEl.textContent = dayDate
      ? dayDate.toLocaleDateString('es-CL', { day: '2-digit', month: 'long', year: 'numeric' })
      : dateStr;
    slotModalEl.hidden = false;
    renderSlotPicker();
    const bookingId = parseInt(booking.id, 10) || 0;
    const url = `${restRoot}/availability?event_id=${encodeURIComponent(eventId)}&date=${encodeURIComponent(dateStr)}&employee_id=${encodeURIComponent(employeeId)}&booking_id=${encodeURIComponent(bookingId)}`;
    fetch(url, { credentials: 'same-origin' })
      .then((res) => res.json())
      .then((data) => {
        slotPickerState.loading = false;
        const slots = Array.isArray(data?.slots) ? data.slots : [];
        slotPickerState.slots = slots;
        renderSlotPicker();
      })
      .catch(() => {
        slotPickerState.loading = false;
        slotPickerState.slots = [];
        renderSlotPicker();
      });
  };
  const applySlotPicker = () => {
    const booking = bookingMap[String(slotPickerState.bookingId || 0)] || slotPickerState.bookingData || getDragBooking();
    if (!booking || !slotPickerState.selectedStart) return;
    const slot = (slotPickerState.slots || []).find((item) => item.start === slotPickerState.selectedStart);
    if (!slot || !slot.start || !slot.end) return;
    const datePart = String(slotPickerState.date || '').trim();
    const normalizeDateTime = (value) => {
      const raw = String(value || '').trim();
      if (!raw || !datePart) return '';
      if (/^\d{4}-\d{2}-\d{2}\s\d{2}:\d{2}(:\d{2})?$/.test(raw)) {
        return raw.length === 16 ? `${raw}:00` : raw;
      }
      if (/^\d{2}:\d{2}(:\d{2})?$/.test(raw)) {
        return `${datePart} ${raw.slice(0, 5)}:00`;
      }
      return '';
    };
    const startValue = normalizeDateTime(slot.start);
    const endValue = normalizeDateTime(slot.end);
    if (!startValue || !endValue) {
      if (notyf) notyf.error('No se pudo interpretar la hora seleccionada.');
      return;
    }
    if (slotApplyEl) setActionButtonLoading(slotApplyEl, true);
    const ajaxUrl = window.litecalAdmin.ajaxUrl || '';
    const nonce = window.litecalAdmin.nonce || '';
    const body = new URLSearchParams({
      action: 'litecal_update_booking_time',
      nonce,
      id: String(booking.id),
      start: startValue,
      end: endValue,
      mark_rescheduled: '1',
    });
    fetch(ajaxUrl, {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
      body: body.toString(),
    })
      .then((res) => res.json())
      .then((json) => {
        if (!json || !json.success) {
          const msg = (json && json.data && json.data.message) ? String(json.data.message) : 'No se pudo reagendar la reserva.';
          if (notyf) notyf.error(msg);
          if (slotApplyEl) setActionButtonLoading(slotApplyEl, false);
          return;
        }
        closeSlotPicker();
        clearDragReschedule(true);
        loadCurrentRange();
        if (notyf) notyf.success('Reserva reagendada correctamente.');
      })
      .catch(() => {
        if (notyf) notyf.error('No se pudo reagendar la reserva.');
        if (slotApplyEl) setActionButtonLoading(slotApplyEl, false);
      });
  };
  const clearDragReschedule = (silent = false) => {
    state.dragBookingId = 0;
    state.dragBookingData = null;
    if (dragHostEl) dragHostEl.hidden = true;
    if (dragChipEl) {
      dragChipEl.textContent = '';
      dragChipEl.classList.remove('is-dragging');
    }
    document.querySelectorAll('.is-drop-target').forEach((el) => el.classList.remove('is-drop-target'));
    if (!silent && notyf) {
      notyf.success('Reagenda por arrastre cancelada.');
    }
  };
  const startDragReschedule = (booking) => {
    if (!booking) return;
    state.dragBookingId = parseInt(booking.id, 10) || 0;
    state.dragBookingData = booking ? { ...booking } : null;
    if (!state.dragBookingId) return;
    if (dragChipEl) {
      dragChipEl.textContent = `${formatHour(booking.start)} ${booking.event || ''}`.trim();
    }
    if (dragHostEl) {
      dragHostEl.hidden = false;
    }
    if (notyf) {
      notyf.success('Selecciona un día en el calendario para elegir hora disponible.');
    }
  };
  const openRescheduleFromDrop = (booking, targetDate) => {
    if (!booking || !targetDate) return;
    openSlotPicker(booking, targetDate);
  };
  const bindDropTargetChild = (targetEl, dateStr) => {
    if (!targetEl || !dateStr) return;
    targetEl.addEventListener('dragover', (event) => {
      if (!getDragBooking()) return;
      event.preventDefault();
      if (event.dataTransfer) {
        event.dataTransfer.dropEffect = 'move';
      }
    });
    targetEl.addEventListener('drop', (event) => {
      const dragBooking = getDragBooking();
      if (!dragBooking) return;
      event.preventDefault();
      event.stopPropagation();
      openRescheduleFromDrop(dragBooking, dateStr);
      clearDragReschedule(true);
    });
  };
  const bindDropTarget = (targetEl, dateStr) => {
    if (!targetEl || !dateStr) return;
    targetEl.setAttribute('data-lc-drop-date', dateStr);
    targetEl.addEventListener('click', (event) => {
      const dragBooking = getDragBooking();
      if (!dragBooking) return;
      if (event.target.closest('.lc-cal-booking-pill, .lc-calendar-count')) return;
      event.preventDefault();
      event.stopPropagation();
      openRescheduleFromDrop(dragBooking, dateStr);
    });
    targetEl.addEventListener('dragenter', () => {
      if (!getDragBooking()) return;
      targetEl.classList.add('is-drop-target');
    });
    targetEl.addEventListener('dragover', (event) => {
      if (!getDragBooking()) return;
      event.preventDefault();
      if (event.dataTransfer) {
        event.dataTransfer.dropEffect = 'move';
      }
      targetEl.classList.add('is-drop-target');
    });
    targetEl.addEventListener('dragleave', () => {
      targetEl.classList.remove('is-drop-target');
    });
    targetEl.addEventListener('drop', (event) => {
      const dragBooking = getDragBooking();
      if (!dragBooking) return;
      event.preventDefault();
      targetEl.classList.remove('is-drop-target');
      openRescheduleFromDrop(dragBooking, dateStr);
      clearDragReschedule(true);
    });
  };

  let bookingPhoneIti = null;
  if (phoneInput && window.intlTelInput) {
    bookingPhoneIti = window.intlTelInput(phoneInput, {
      initialCountry: 'cl',
      preferredCountries: ['cl', 'ar', 'pe', 'co', 'mx', 'es', 'us'],
      separateDialCode: true,
      autoPlaceholder: 'polite',
      nationalMode: true,
      formatOnDisplay: true,
      utilsScript: '',
    });
  }

  const filteredBookings = () => {
    return bookings.filter((booking) => {
      const statusOk = !state.filters.status || String(booking.status || '') === state.filters.status;
      const employeeOk = !state.filters.employee || String(booking.employee_id || '') === state.filters.employee;
      const eventId = String((booking.snapshot && booking.snapshot.event && booking.snapshot.event.id) || booking.event_id || '');
      const eventOk = !state.filters.event || eventId === state.filters.event;
      return statusOk && employeeOk && eventOk;
    });
  };

  const groupByDate = (items) => {
    return items.reduce((acc, booking) => {
      const date = (booking.start || '').split(' ')[0];
      if (!date) return acc;
      acc[date] = acc[date] || [];
      acc[date].push(booking);
      return acc;
    }, {});
  };

  const fetchRangeBookings = (startDate, endDate) => {
    const ajaxUrl = window.litecalAdmin.ajaxUrl || '';
    const nonce = window.litecalAdmin.calendarNonce || '';
    if (!ajaxUrl || !nonce) {
      return Promise.resolve();
    }
    const params = new URLSearchParams({
      action: 'litecal_calendar_bookings',
      nonce,
      start: startDate,
      end: endDate,
    });
    if (state.filters.employee) {
      params.set('employee_id', state.filters.employee);
    }
    return fetch(`${ajaxUrl}?${params.toString()}`, { credentials: 'same-origin' })
      .then((res) => res.json())
      .then((json) => {
        if (json && json.success && json.data && Array.isArray(json.data.bookings)) {
          setBookings(json.data.bookings);
          return;
        }
        setBookings([]);
      })
      .catch(() => {
        setBookings([]);
      });
  };

  const escHtml = (value) => {
    return String(value == null ? '' : value)
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;')
      .replace(/'/g, '&#39;');
  };
  const escUrl = (value) => {
    const raw = String(value == null ? '' : value).trim();
    if (!raw) return '#';
    try {
      const parsed = new URL(raw, window.location.origin);
      if (parsed.protocol === 'http:' || parsed.protocol === 'https:') {
        return parsed.toString();
      }
    } catch (_) {
      return '#';
    }
    return '#';
  };
  const providerLabels = {
    flow: 'Flow',
    webpay: 'Webpay Plus',
    mp: 'MercadoPago',
    paypal: 'PayPal',
    onsite: 'Pago presencial',
  };
  const statusLabel = (value, type = 'booking') => {
    const status = String(value || '').toLowerCase();
    if (type === 'payment') {
      return (
        {
          paid: 'Aprobado',
          pending: 'Pendiente',
          rejected: 'Rechazado',
          failed: 'Rechazado',
          cancelled: 'Cancelado',
          canceled: 'Cancelado',
          unpaid: 'No pagado',
        }[status] || status || '-'
      );
    }
    return (
      {
        pending: 'Pendiente',
        confirmed: 'Confirmada',
        cancelled: 'Cancelada',
        canceled: 'Cancelada',
        rescheduled: 'Reagendada',
      }[status] || status || '-'
    );
  };
  const bookingStatusClass = (value) => {
    const status = String(value || '').toLowerCase();
    if (status === 'confirmed' || status === 'active') return 'is-confirmed';
    if (status === 'pending') return 'is-pending';
    if (status === 'cancelled' || status === 'canceled') return 'is-cancelled';
    if (status === 'rescheduled') return 'is-rescheduled';
    return 'is-rescheduled';
  };
  const formatFileSize = (bytes) => {
    const size = Number(bytes || 0);
    if (!size) return '-';
    if (size >= 1024 * 1024) return `${(size / (1024 * 1024)).toFixed(1)} MB`;
    return `${Math.max(1, Math.round(size / 1024))} KB`;
  };
  let currentBooking = null;

  const syncReschedulePanel = () => {
    if (!statusSelect || !reschedulePanel) return;
    const isRescheduled = statusSelect.value === 'rescheduled';
    reschedulePanel.hidden = !isRescheduled;
    if (bookingDateInput) bookingDateInput.disabled = !isRescheduled;
    if (bookingTimeStartInput) bookingTimeStartInput.disabled = !isRescheduled;
    if (bookingTimeEndInput) bookingTimeEndInput.disabled = !isRescheduled;
    if (!currentBooking) return;

    const originalStart = currentBooking.start || '';
    const originalEnd = currentBooking.end || '';
    if (bookingStartViewInput) {
      bookingStartViewInput.value = `${formatHumanDate(originalStart)} · ${formatHour(originalStart)}`.trim();
    }
    if (bookingEndViewInput) {
      bookingEndViewInput.value = `${formatHumanDate(originalEnd)} · ${formatHour(originalEnd)}`.trim();
    }

    if (!isRescheduled) {
      if (startInput) startInput.value = originalStart;
      if (endInput) endInput.value = originalEnd;
      return;
    }

    const originalStartParts = splitDateAndTime(originalStart);
    const originalEndParts = splitDateAndTime(originalEnd);
    if (bookingDateInput && !bookingDateInput.value) bookingDateInput.value = originalStartParts.date;
    if (bookingTimeStartInput && !bookingTimeStartInput.value) bookingTimeStartInput.value = originalStartParts.time;
    if (bookingTimeEndInput && !bookingTimeEndInput.value) bookingTimeEndInput.value = originalEndParts.time;
    const selectedDate = bookingDateInput ? bookingDateInput.value : '';
    const selectedStart = bookingTimeStartInput ? bookingTimeStartInput.value : '';
    const selectedEnd = bookingTimeEndInput ? bookingTimeEndInput.value : '';
    const nextStart = buildDateTime(selectedDate, selectedStart);
    const nextEnd = buildDateTime(selectedDate, selectedEnd);
    if (startInput && nextStart) startInput.value = nextStart;
    if (endInput && nextEnd) endInput.value = nextEnd;
  };

  const closePopover = () => {
    if (!popoverEl) return;
    const closingMode = popoverMode;
    popoverMode = 'anchored';
    clearPopoverCloseTimer();
    popoverEl.classList.remove('is-open');
    popoverEl.classList.remove('is-mobile');
    popoverEl.classList.remove('is-sheet');
    document.documentElement.classList.remove('lc-cal-sheet-open');
    if (popoverOverlayEl) {
      popoverOverlayEl.classList.remove('is-open');
    }
    if (closingMode === 'sheet') {
      popoverCloseTimer = window.setTimeout(() => {
        popoverEl.hidden = true;
        popoverEl.innerHTML = '';
        if (popoverOverlayEl) {
          popoverOverlayEl.hidden = true;
        }
      }, 220);
      return;
    }
    popoverEl.hidden = true;
    popoverEl.innerHTML = '';
    if (popoverOverlayEl) {
      popoverOverlayEl.hidden = true;
    }
  };
  const positionPopover = (anchor) => {
    if (!popoverEl || !anchor) return;
    if (popoverMode === 'sheet') {
      popoverEl.style.left = '50%';
      popoverEl.style.top = '50%';
      return;
    }
    const isMobileViewport = window.matchMedia('(max-width: 782px)').matches;
    if (isMobileViewport) {
      popoverEl.classList.add('is-mobile');
      popoverEl.style.left = '50%';
      popoverEl.style.top = '50%';
      return;
    }
    popoverEl.classList.remove('is-mobile');
    const rect = anchor.getBoundingClientRect();
    popoverEl.style.left = Math.max(12, Math.min(window.innerWidth - 360, rect.left)) + 'px';
    popoverEl.style.top = Math.max(12, rect.bottom + 8) + 'px';
  };

  const closePopoverOnViewportChange = () => {
    if (!popoverEl || popoverEl.hidden) return;
    if (popoverMode === 'sheet') return;
    closePopover();
  };

  const openDetail = () => {
    if (detailModalEl) detailModalEl.hidden = false;
  };
  const closeDetail = () => {
    if (detailModalEl) detailModalEl.hidden = true;
  };
  const renderFrame = () => {
    if (stageEl) stageEl.hidden = state.mode !== 'calendar';
  };

  const currentRange = () => {
    const base = new Date(state.currentDate.getFullYear(), state.currentDate.getMonth(), state.currentDate.getDate());
    if (state.view === 'month') {
      const first = new Date(base.getFullYear(), base.getMonth(), 1);
      const start = startOfWeek(first);
      const end = addDays(start, 41);
      return { start, end };
    }
    if (state.view === 'week') {
      const start = startOfWeek(base);
      const end = endOfWeek(base);
      return { start, end };
    }
    if (state.view === 'day') {
      return { start: base, end: base };
    }
    const start = base;
    const end = addDays(base, 30);
    return { start, end };
  };

  const updateRangeText = () => {
    const { start, end } = currentRange();
    if (rangeEl) {
      rangeEl.textContent = rangeLabel(start, end, state.view);
    }
    if (viewWrap) {
      viewWrap.querySelectorAll('[data-lc-cal-view]').forEach((btn) => {
        btn.classList.toggle('is-active', btn.getAttribute('data-lc-cal-view') === state.view);
      });
    }
  };

  const goToday = () => {
    state.currentDate = new Date(today.getFullYear(), today.getMonth(), today.getDate());
    state.mode = 'calendar';
    loadCurrentRange();
  };

  const shiftRange = (direction) => {
    if (state.view === 'month') {
      state.currentDate = new Date(state.currentDate.getFullYear(), state.currentDate.getMonth() + direction, 1);
    } else if (state.view === 'week') {
      state.currentDate = addDays(state.currentDate, 7 * direction);
    } else if (state.view === 'day') {
      state.currentDate = addDays(state.currentDate, direction);
    } else {
      state.currentDate = addDays(state.currentDate, 30 * direction);
    }
    state.mode = 'calendar';
    loadCurrentRange();
  };

  const visibleDays = (from, to) => {
    const days = [];
    const cursor = new Date(from);
    while (cursor <= to) {
      days.push(new Date(cursor));
      cursor.setDate(cursor.getDate() + 1);
    }
    return days;
  };

  const openBooking = (booking, anchor) => {
    if (!booking || !anchor) return;
    state.selectedDate = bookingDateOnly(booking);
    showPopover(booking, anchor);
  };

  const renderMonthView = () => {
    const { start, end } = currentRange();
    const grouped = groupByDate(filteredBookings());
    const month = state.currentDate.getMonth();
    const isMobileMonth = window.matchMedia('(max-width: 782px)').matches;
    stageEl.innerHTML = '';
    const grid = document.createElement('div');
    grid.className = 'lc-calendar-grid lc-calendar-grid-month';
    const weekdays = ['Lun', 'Mar', 'Mie', 'Jue', 'Vie', 'Sab', 'Dom'];
    weekdays.forEach((day) => {
      const label = document.createElement('div');
      label.className = 'lc-calendar-day';
      label.textContent = day;
      grid.appendChild(label);
    });
    visibleDays(start, end).forEach((day) => {
      const dateStr = formatDate(day);
      const cell = document.createElement('div');
      cell.className = 'lc-calendar-cell';
      bindDropTarget(cell, dateStr);
      if (day.getMonth() !== month) cell.classList.add('is-outside-month');
      if (dateStr === formatDate(today)) cell.classList.add('is-today');
      const dayNumber = document.createElement('span');
      dayNumber.className = 'lc-calendar-number';
      dayNumber.textContent = String(day.getDate());
      cell.appendChild(dayNumber);
      const dayBookings = (grouped[dateStr] || []).sort((a, b) => bookingStartTs(a) - bookingStartTs(b));
      if (dayBookings.length) {
        cell.classList.add('has-bookings');
        if (isMobileMonth) {
          const countEl = document.createElement('button');
          countEl.type = 'button';
          countEl.className = 'lc-calendar-count lc-calendar-count--day';
          bindDropTargetChild(countEl, dateStr);
          countEl.setAttribute('aria-label', dayBookings.length === 1 ? '1 reserva' : `${dayBookings.length} reservas`);
          countEl.innerHTML = `<span>${dayBookings.length}</span>`;
          countEl.addEventListener('click', (e) => {
            e.stopPropagation();
            const dragBooking = getDragBooking();
            if (dragBooking) {
              openRescheduleFromDrop(dragBooking, dateStr);
              return;
            }
            showDayBookingsPopover(dayBookings, countEl, dateStr);
          });
          cell.appendChild(countEl);
          cell.addEventListener('click', () => {
            const dragBooking = getDragBooking();
            if (dragBooking) {
              openRescheduleFromDrop(dragBooking, dateStr);
              return;
            }
            showDayBookingsPopover(dayBookings, cell, dateStr);
          });
        } else {
          const listWrap = document.createElement('div');
          listWrap.className = 'lc-cal-month-bookings';
          dayBookings.slice(0, 3).forEach((booking) => {
            const bookingBtn = document.createElement('button');
            bookingBtn.type = 'button';
            bookingBtn.className = 'lc-cal-booking-pill lc-cal-booking-pill--month';
            bindDropTargetChild(bookingBtn, dateStr);
            const statusClass = bookingStatusClass(booking.status);
            bookingBtn.innerHTML =
              '<span class="lc-cal-line"><span class="lc-cal-status-dot ' + statusClass + '"></span><span class="lc-cal-line-time">' + escHtml(formatHour(booking.start)) + '</span><span class="lc-cal-line-title">' + escHtml(booking.event) + '</span></span>';
            bookingBtn.addEventListener('click', (e) => {
              e.stopPropagation();
              const dragBooking = getDragBooking();
              if (dragBooking) {
                openRescheduleFromDrop(dragBooking, dateStr);
                return;
              }
              openBooking(booking, bookingBtn);
            });
            listWrap.appendChild(bookingBtn);
          });
          if (dayBookings.length > 3) {
            const moreEl = document.createElement('button');
            moreEl.type = 'button';
            moreEl.className = 'lc-calendar-count';
            bindDropTargetChild(moreEl, dateStr);
            moreEl.innerHTML = '<span class="lc-calendar-dot"></span><span>+' + (dayBookings.length - 3) + ' más</span>';
            moreEl.addEventListener('click', (e) => {
              e.stopPropagation();
              showDayBookingsPopover(dayBookings, moreEl, dateStr);
            });
            listWrap.appendChild(moreEl);
          }
          cell.appendChild(listWrap);
        }
      }
      grid.appendChild(cell);
    });
    stageEl.appendChild(grid);
  };

  const renderWeekLikeView = (dayMode = false) => {
    const grouped = groupByDate(filteredBookings());
    const { start, end } = currentRange();
    const days = dayMode ? [new Date(start)] : visibleDays(start, end);
    stageEl.innerHTML = '';
    const wrap = document.createElement('div');
    wrap.className = dayMode ? 'lc-cal-day-cards' : 'lc-cal-week-grid';
    days.forEach((day) => {
      const dateStr = formatDate(day);
      const col = document.createElement('div');
      col.className = 'lc-cal-week-col';
      bindDropTarget(col, dateStr);
      col.innerHTML = '<div class="lc-cal-week-head">' + escHtml(day.toLocaleDateString('es-CL', { weekday: 'short', day: '2-digit', month: 'short' }).replace(/\./g, '')) + '</div>';
      const events = (grouped[dateStr] || []).sort((a, b) => bookingStartTs(a) - bookingStartTs(b));
      if (!events.length) {
        const empty = document.createElement('div');
        empty.className = 'lc-cal-empty';
        empty.textContent = 'Sin reservas';
        col.appendChild(empty);
      } else {
        events.forEach((booking) => {
          const item = document.createElement('button');
          item.type = 'button';
          item.className = 'lc-cal-booking-pill';
          bindDropTargetChild(item, dateStr);
          const statusClass = bookingStatusClass(booking.status);
          item.innerHTML =
            '<span class="lc-cal-line"><span class="lc-cal-status-dot ' + statusClass + '"></span><span class="lc-cal-line-time">' + escHtml(formatHour(booking.start)) + '</span><span class="lc-cal-line-title">' + escHtml(booking.event) + '</span></span>';
          item.addEventListener('click', (event) => {
            const dragBooking = getDragBooking();
            if (dragBooking) {
              openRescheduleFromDrop(dragBooking, dateStr);
              return;
            }
            showPopover(booking, event.currentTarget);
          });
          col.appendChild(item);
        });
      }
      wrap.appendChild(col);
    });
    stageEl.appendChild(wrap);
  };

  const renderAgendaView = () => {
    const { start, end } = currentRange();
    const fromStr = formatDate(start);
    const toStr = formatDate(end);
    const items = filteredBookings()
      .filter((booking) => {
        const d = bookingDateOnly(booking);
        return d >= fromStr && d <= toStr;
      })
      .sort((a, b) => bookingStartTs(a) - bookingStartTs(b));
    stageEl.innerHTML = '';
    const list = document.createElement('div');
    list.className = 'lc-cal-agenda';
    if (!items.length) {
      list.innerHTML = '<p class="description">Sin reservas para este rango.</p>';
    } else {
      items.forEach((booking) => {
        const row = document.createElement('button');
        row.type = 'button';
        row.className = 'lc-cal-agenda-row';
        row.innerHTML = `
          <div class="lc-cal-agenda-time">${escHtml(formatHumanRange(booking.start, booking.end))}</div>
          <div class="lc-cal-agenda-main">
            <strong>${escHtml(booking.event)}</strong>
            <span>${escHtml(booking.name || '-')} · ${escHtml(statusLabel(booking.status, 'booking'))}</span>
          </div>
        `;
        row.addEventListener('click', (event) => showPopover(booking, event.currentTarget));
        list.appendChild(row);
      });
    }
    stageEl.appendChild(list);
  };

  function setActiveBooking(item, booking) {
    if (item && item.classList) {
      document.querySelectorAll('.lc-calendar-item').forEach((el) => el.classList.remove('is-active'));
      item.classList.add('is-active');
    }
    currentBooking = booking;
    if (idInput) idInput.value = booking.id;
    if (statusSelect) {
      const allowedStatus = ['pending', 'confirmed', 'cancelled'];
      const nextStatus = String(booking.status || '').toLowerCase();
      if (allowedStatus.includes(nextStatus)) {
        statusSelect.value = nextStatus;
      } else {
        let dynamicOption = statusSelect.querySelector('option[data-lc-dynamic-status]');
        if (!dynamicOption) {
          dynamicOption = document.createElement('option');
          dynamicOption.setAttribute('data-lc-dynamic-status', '1');
          dynamicOption.hidden = true;
          statusSelect.appendChild(dynamicOption);
        }
        dynamicOption.value = nextStatus || 'confirmed';
        dynamicOption.textContent = statusLabel(nextStatus || 'confirmed', 'booking');
        statusSelect.value = dynamicOption.value;
      }
    }
    if (startInput) startInput.value = booking.start;
    if (endInput) endInput.value = booking.end;
    if (nameInput) nameInput.value = booking.name;
    if (emailInput) emailInput.value = booking.email;
    if (phoneInput) {
      const rawPhone = String(booking.phone || '').trim();
      phoneInput.value = rawPhone;
      if (bookingPhoneIti && rawPhone) {
        try {
          bookingPhoneIti.setNumber(rawPhone);
        } catch (_) {}
      }
    }
    if (messageInput) messageInput.value = booking.message || '';
    if (employeeSelect) {
      employeeSelect.value = booking.employee_id || '';
      const employeeCustomSelect = employeeSelect.closest('[data-lc-select]');
      if (employeeCustomSelect) {
        syncSelectFromValue(employeeCustomSelect);
      }
    }
    const startParts = splitDateAndTime(booking.start);
    const endParts = splitDateAndTime(booking.end);
    if (bookingDateInput) bookingDateInput.value = startParts.date;
    if (bookingTimeStartInput) bookingTimeStartInput.value = startParts.time;
    if (bookingTimeEndInput) bookingTimeEndInput.value = endParts.time;
    syncReschedulePanel();
    renderBookingDetail(booking);
  }

  function renderBookingDetail(booking) {
    const snapshot = booking?.snapshot || {};
    const bookingSnap = snapshot?.booking || {};
    const eventSnap = snapshot?.event || {};
    const employeeSnap = snapshot?.employee || {};
    const customDefs = Array.isArray(snapshot?.custom_fields_def) ? snapshot.custom_fields_def : [];
    const customValues = booking?.custom_fields && typeof booking.custom_fields === 'object' ? booking.custom_fields : {};
    const firstName = bookingSnap.first_name || '';
    const lastName = bookingSnap.last_name || '';
    const email = booking.email || bookingSnap.email || '';
    const phone = booking.phone || bookingSnap.phone || '';
    const message = booking.message || bookingSnap.message || '';
    const guests = Array.isArray(booking.guests) ? booking.guests.filter(Boolean) : [];
    const attendeeName = employeeSnap.name || '-';
    const paymentProvider = booking.payment_provider ? (providerLabels[booking.payment_provider] || booking.payment_provider) : 'Sin pago';
    const paymentStatus = statusLabel(booking.payment_status, 'payment');
    const bookingStatus = statusLabel(booking.status, 'booking');
    const paymentAmount = Number(booking.payment_amount || 0);
    const paymentCurrency = booking.payment_currency || eventSnap.currency || 'CLP';
    const amountLabel = paymentAmount > 0
      ? new Intl.NumberFormat('es-CL', { style: 'currency', currency: paymentCurrency, maximumFractionDigits: paymentCurrency === 'CLP' ? 0 : 2 }).format(paymentAmount)
      : '-';

    const customRows = [];
    const seen = new Set();
    customDefs.forEach((def) => {
      const key = def?.key;
      if (!key || customValues[key] == null) return;
      seen.add(key);
      if ((def?.type || '') === 'file') return;
      const label = def?.label || key;
      let value = customValues[key];
      if (Array.isArray(value)) {
        if (value.length && typeof value[0] === 'object') return;
        value = value.join(', ');
      } else if (typeof value === 'object' && value !== null) {
        return;
      }
      if (String(value).trim() !== '') {
        customRows.push({ label, value: String(value) });
      }
    });
    Object.keys(customValues || {}).forEach((key) => {
      if (seen.has(key)) return;
      const value = customValues[key];
      if (value == null || value === '') return;
      if (typeof value === 'object') return;
      customRows.push({ label: key, value: Array.isArray(value) ? value.join(', ') : String(value) });
    });

    const filesRaw = snapshot?.files && typeof snapshot.files === 'object' ? snapshot.files : {};
    const files = [];
    Object.keys(filesRaw).forEach((fieldKey) => {
      const items = Array.isArray(filesRaw[fieldKey]) ? filesRaw[fieldKey] : [];
      items.forEach((file) => {
        if (!file?.url) return;
        files.push({
          field: fieldKey,
          name: file.original_name || file.stored_name || 'Archivo',
          mime: file.mime || '',
          size: file.size || 0,
          url: file.url,
        });
      });
    });

    const locationKey = eventSnap.location || '';
    const locationLabel = locationKey === 'google_meet'
      ? 'Google Meet'
      : (locationKey === 'presencial'
        ? 'Presencial'
        : (locationKey === 'zoom' ? 'Zoom' : (locationKey === 'teams' ? 'Microsoft Teams' : 'Ubicación personalizada')));
    const locationValue = booking.calendar_meet_link || eventSnap.location_details || '';

    const detailRows = [];
    if (booking.name) detailRows.push({ label: 'Cliente', value: booking.name });
    if (attendeeName && attendeeName !== '-') detailRows.push({ label: 'Profesional', value: attendeeName });
    if (firstName) detailRows.push({ label: 'Nombre', value: firstName });
    if (lastName) detailRows.push({ label: 'Apellido', value: lastName });
    if (email) detailRows.push({ label: 'Email', value: email });
    if (phone) detailRows.push({ label: 'Teléfono', value: phone });
    if (guests.length) detailRows.push({ label: 'Invitados', value: guests.join(', ') });
    if (locationValue) {
      const locationDisplay = (locationKey === 'google_meet' || locationKey === 'zoom' || locationKey === 'teams')
        ? `<a href="${escUrl(locationValue)}" target="_blank" rel="noopener">Abrir enlace</a>`
        : escHtml(locationValue);
      detailRows.push({ label: locationLabel, value: locationDisplay, isHtml: true });
    }
    if (message) detailRows.push({ label: 'Nota interna', value: message });
    customRows.forEach((row) => {
      detailRows.push({ label: row.label, value: row.value });
    });

    const hasExtraDetails = detailRows.length > 0 || files.length > 0;
    let extraHtml = '';
    if (hasExtraDetails) {
      extraHtml += '<div class="lc-calendar-detail-extra" data-lc-calendar-detail-extra hidden>';
      if (detailRows.length) {
        extraHtml += '<div class="lc-calendar-detail-section"><div class="lc-calendar-detail-heading">Detalles</div>';
        detailRows.forEach((row) => {
          extraHtml += `<div><span>${escHtml(row.label)}:</span> <strong>${row.isHtml ? row.value : escHtml(row.value)}</strong></div>`;
        });
        extraHtml += '</div>';
      }
      if (files.length) {
        extraHtml += '<div class="lc-calendar-detail-section"><div class="lc-calendar-detail-heading">Adjuntos</div><div class="lc-calendar-files">';
        files.forEach((file) => {
          extraHtml += `<div class="lc-calendar-file-row"><div><a href="${escUrl(file.url)}" target="_blank" rel="noopener">${escHtml(file.name)}</a><small>${escHtml(file.mime || '-')} · ${escHtml(formatFileSize(file.size))}</small></div><a class="button" href="${escUrl(file.url)}" target="_blank" rel="noopener">Ver/descargar</a></div>`;
        });
        extraHtml += '</div></div>';
      }
      extraHtml += '</div>';
    }

    let html = `
      <div class="lc-calendar-detail-card">
        <div class="lc-calendar-detail-title">${escHtml(eventSnap.title || booking.event || '-')}</div>
        <div class="lc-calendar-detail-sub">${escHtml(formatHumanRange(booking.start, booking.end))}</div>
        <div class="lc-calendar-detail-grid">
          <div><span>ID</span><strong>#${escHtml(booking.id)}</strong></div>
          <div><span>Estado reserva</span><strong>${escHtml(bookingStatus)}</strong></div>
          <div><span>Proveedor</span><strong>${escHtml(paymentProvider)}</strong></div>
          <div><span>Estado pago</span><strong>${escHtml(paymentStatus)}</strong></div>
          <div><span>Monto</span><strong>${escHtml(amountLabel)}</strong></div>
          <div><span>Referencia</span><strong>${escHtml(booking.payment_reference || '-')}</strong></div>
        </div>
        ${hasExtraDetails ? '<button type="button" class="lc-calendar-more-toggle" data-lc-calendar-toggle>+ Ver más detalles</button>' : ''}
        ${extraHtml}
      </div>`;

    bookingDetailEl.innerHTML = html;
  }

  if (bookingDetailEl) {
    bookingDetailEl.addEventListener('click', (event) => {
      const toggleBtn = event.target.closest('[data-lc-calendar-toggle]');
      if (!toggleBtn) return;
      const extra = bookingDetailEl.querySelector('[data-lc-calendar-detail-extra]');
      if (!extra) return;
      const isHidden = extra.hasAttribute('hidden');
      if (isHidden) {
        extra.removeAttribute('hidden');
        toggleBtn.textContent = '- Ver menos';
      } else {
        extra.setAttribute('hidden', 'hidden');
        toggleBtn.textContent = '+ Ver más detalles';
      }
    });
  }

  if (statusSelect) {
    statusSelect.addEventListener('change', syncReschedulePanel);
  }
  if (bookingDateInput) {
    bookingDateInput.addEventListener('change', syncReschedulePanel);
  }
  if (bookingTimeStartInput) {
    bookingTimeStartInput.addEventListener('change', syncReschedulePanel);
  }
  if (bookingTimeEndInput) {
    bookingTimeEndInput.addEventListener('change', syncReschedulePanel);
  }

  if (calendarForm) {
    const calendarSubmitBtn = calendarForm.querySelector('.lc-calendar-form-actions .button-primary, button[type="submit"], input[type="submit"]');
    calendarForm.addEventListener('submit', (event) => {
      if (calendarSubmitBtn && calendarSubmitBtn.classList.contains('lc-btn-loading')) {
        event.preventDefault();
        return;
      }
      if (phoneInput && bookingPhoneIti) {
        try {
          const parsed = bookingPhoneIti.getNumber();
          if (parsed) {
            phoneInput.value = parsed;
          }
        } catch (_) {}
      }
      if (statusSelect && statusSelect.value === 'rescheduled' && bookingDateInput && bookingTimeStartInput && bookingTimeEndInput) {
        const nextDate = bookingDateInput ? bookingDateInput.value : '';
        const nextStart = bookingTimeStartInput ? bookingTimeStartInput.value : '';
        const nextEnd = bookingTimeEndInput ? bookingTimeEndInput.value : '';
        if (!nextDate || !nextStart || !nextEnd) {
          event.preventDefault();
          if (notyf) {
            notyf.error('Selecciona nueva fecha y hora para reagendar.');
          }
          return;
        }
        const startValue = buildDateTime(nextDate, nextStart);
        const endValue = buildDateTime(nextDate, nextEnd);
        if (!startValue || !endValue || endValue <= startValue) {
          event.preventDefault();
          if (notyf) {
            notyf.error('La hora de fin debe ser mayor a la hora de inicio.');
          }
          return;
        }
        if (startInput) startInput.value = startValue;
        if (endInput) endInput.value = endValue;
      }
      if (calendarSubmitBtn) {
        setActionButtonLoading(calendarSubmitBtn, true);
      }
    });
  }

  function showPopover(booking, anchor) {
    if (!popoverEl || !booking || !anchor) return;
    clearPopoverCloseTimer();
    popoverMode = 'sheet';
    popoverEl.classList.remove('is-mobile');
    popoverEl.classList.remove('is-open');
    popoverEl.classList.add('is-sheet');
    const clientData = [booking.name || '-', booking.email || '-', booking.phone || '-'].filter(Boolean).join(' · ');
    const bookingStatus = statusLabel(booking.status, 'booking');
    const statusClass = bookingStatusClass(booking.status);
    popoverEl.innerHTML = `
      <div class="lc-cal-popover-head">${escHtml(booking.event || '-')}</div>
      <div class="lc-cal-popover-sub">${escHtml(formatHumanRange(booking.start, booking.end))}</div>
      <div class="lc-cal-popover-sub"><span class="lc-cal-status-dot ${escHtml(statusClass)}"></span>${escHtml(bookingStatus)}</div>
      <div class="lc-cal-popover-sub">${escHtml(clientData)}</div>
      <div class="lc-cal-popover-actions">
        <button type="button" data-lc-pop-act="detail">Ver detalle</button>
        <button type="button" data-lc-pop-act="reprogram">Reagendar</button>
        <button type="button" data-lc-pop-act="cancel">Cancelar</button>
        <button type="button" data-lc-pop-act="copy">Copiar datos</button>
      </div>
    `;
    positionPopover(anchor);
    popoverEl.hidden = false;
    if (popoverOverlayEl) {
      popoverOverlayEl.hidden = false;
    }
    document.documentElement.classList.add('lc-cal-sheet-open');
    window.requestAnimationFrame(() => {
      if (!popoverEl.hidden) {
        popoverEl.classList.add('is-open');
      }
      if (popoverOverlayEl && !popoverOverlayEl.hidden) {
        popoverOverlayEl.classList.add('is-open');
      }
    });
    popoverEl.querySelectorAll('button[data-lc-pop-act]').forEach((btn) => {
      btn.addEventListener('click', () => {
        const action = btn.getAttribute('data-lc-pop-act');
        if (action === 'detail') {
          if (state.dragBookingId) clearDragReschedule(true);
          setActiveBooking(null, booking);
          openDetail();
        } else if (action === 'reprogram') {
          if (canUseDragReschedule()) {
            startDragReschedule(booking);
          } else {
            if (state.dragBookingId) clearDragReschedule(true);
            setActiveBooking(null, booking);
            if (statusSelect) statusSelect.value = 'rescheduled';
            syncReschedulePanel();
            openDetail();
          }
        } else if (action === 'cancel') {
          if (state.dragBookingId) clearDragReschedule(true);
          setActiveBooking(null, booking);
          if (statusSelect) statusSelect.value = 'cancelled';
          syncReschedulePanel();
          openDetail();
        } else if (action === 'copy') {
          const payload = [booking.name || '', booking.email || '', booking.phone || ''].filter(Boolean).join(' | ');
          if (payload && navigator.clipboard) {
            navigator.clipboard.writeText(payload).catch(() => {});
          }
        }
        closePopover();
      });
    });
  }

  function showDayBookingsPopover(dayBookings, anchor, dateStr) {
    if (!popoverEl || !anchor || !Array.isArray(dayBookings) || !dayBookings.length) return;
    clearPopoverCloseTimer();
    popoverMode = 'sheet';
    popoverEl.classList.remove('is-mobile');
    popoverEl.classList.remove('is-open');
    popoverEl.classList.add('is-sheet');
    const dayDate = parseDateOnly(dateStr);
    const dayLabel = dayDate
      ? dayDate.toLocaleDateString('es-CL', { day: '2-digit', month: 'long', year: 'numeric' })
      : dateStr;
    let rows = '';
    dayBookings.forEach((booking) => {
      const statusClass = bookingStatusClass(booking.status);
      rows += `
        <button type="button" class="lc-cal-popover-list-item" data-lc-pop-booking-id="${escHtml(booking.id)}">
          <span class="lc-cal-line">
            <span class="lc-cal-status-dot ${escHtml(statusClass)}"></span>
            <span class="lc-cal-line-time">${escHtml(formatHour(booking.start))}</span>
            <span class="lc-cal-line-title">${escHtml(booking.event || '-')}</span>
          </span>
        </button>
      `;
    });
    popoverEl.innerHTML = `
      <div class="lc-cal-popover-head">${escHtml(dayLabel)}</div>
      <div class="lc-cal-popover-sub">Reservas del día</div>
      <div class="lc-cal-popover-list">${rows}</div>
    `;
    positionPopover(anchor);
    popoverEl.hidden = false;
    if (popoverOverlayEl) {
      popoverOverlayEl.hidden = false;
    }
    document.documentElement.classList.add('lc-cal-sheet-open');
    window.requestAnimationFrame(() => {
      if (!popoverEl.hidden) {
        popoverEl.classList.add('is-open');
      }
      if (popoverOverlayEl && !popoverOverlayEl.hidden) {
        popoverOverlayEl.classList.add('is-open');
      }
    });
    popoverEl.querySelectorAll('[data-lc-pop-booking-id]').forEach((btn) => {
      btn.addEventListener('click', (event) => {
        event.preventDefault();
        event.stopPropagation();
        const bookingId = String(btn.getAttribute('data-lc-pop-booking-id') || '');
        const target = bookingMap[bookingId];
        if (target) {
          showPopover(target, anchor);
        }
      });
    });
  }

  document.addEventListener('click', (event) => {
    if (!popoverEl || popoverEl.hidden) return;
    if (popoverEl.contains(event.target)) return;
    if (event.target.closest('.lc-calendar-item, .lc-cal-booking-pill, .lc-cal-agenda-row')) return;
    closePopover();
  });
  if (popoverOverlayEl) {
    popoverOverlayEl.addEventListener('click', () => {
      closePopover();
    });
  }

  if (detailCloseBtn) {
    detailCloseBtn.addEventListener('click', () => {
      closeDetail();
    });
  }

  if (detailModalEl) {
    detailModalEl.addEventListener('click', (event) => {
      if (event.target === detailModalEl) {
        closeDetail();
      }
    });
  }
  if (slotModalEl) {
    slotModalEl.addEventListener('click', (event) => {
      if (event.target === slotModalEl) {
        closeSlotPicker();
      }
    });
  }
  if (slotCloseEl) {
    slotCloseEl.addEventListener('click', closeSlotPicker);
  }
  if (slotCancelEl) {
    slotCancelEl.addEventListener('click', closeSlotPicker);
  }
  if (slotApplyEl) {
    slotApplyEl.addEventListener('click', applySlotPicker);
  }

  document.addEventListener('keydown', (event) => {
    if (event.key === 'Escape') {
      closePopover();
      if (detailModalEl && !detailModalEl.hidden) {
        closeDetail();
      }
      if (slotModalEl && !slotModalEl.hidden) {
        closeSlotPicker();
      }
      if (state.dragBookingId) {
        clearDragReschedule(true);
      }
    }
  });
  window.addEventListener('scroll', closePopoverOnViewportChange, true);
  let viewportChangeTimer = null;
  window.addEventListener('resize', () => {
    closePopoverOnViewportChange();
    if (viewportChangeTimer) window.clearTimeout(viewportChangeTimer);
    viewportChangeTimer = window.setTimeout(() => {
      if (state.mode === 'calendar') {
        renderCalendarStage();
      }
    }, 120);
  });

  if (dragChipEl) {
    dragChipEl.addEventListener('dragstart', (event) => {
      const dragBooking = getDragBooking();
      if (!dragBooking) {
        event.preventDefault();
        return;
      }
      dragChipEl.classList.add('is-dragging');
      if (event.dataTransfer) {
        event.dataTransfer.effectAllowed = 'move';
        event.dataTransfer.setData('text/plain', String(dragBooking.id));
        event.dataTransfer.setData('text/litecal-booking-id', String(dragBooking.id));
      }
    });
    dragChipEl.addEventListener('dragend', () => {
      dragChipEl.classList.remove('is-dragging');
      document.querySelectorAll('.is-drop-target').forEach((el) => el.classList.remove('is-drop-target'));
    });
  }
  if (dragCancelEl) {
    dragCancelEl.addEventListener('click', () => clearDragReschedule());
  }

  const renderMini = () => {
    if (!miniEl) return;
    const month = new Date(state.currentDate.getFullYear(), state.currentDate.getMonth(), 1);
    miniEl.innerHTML = '<div class="lc-cal-mini-title">' + escHtml(month.toLocaleDateString('es-CL', { month: 'long', year: 'numeric' })) + '</div>';
    const grid = document.createElement('div');
    grid.className = 'lc-cal-mini-grid';
    const start = startOfWeek(month);
    visibleDays(start, addDays(start, 41)).forEach((day) => {
      const btn = document.createElement('button');
      btn.type = 'button';
      btn.className = 'lc-cal-mini-day';
      const d = formatDate(day);
      if (d === formatDate(today)) btn.classList.add('is-today');
      if (d === state.selectedDate) btn.classList.add('is-active');
      btn.textContent = String(day.getDate());
      btn.addEventListener('click', () => {
        state.currentDate = new Date(day);
        state.selectedDate = d;
        state.mode = 'calendar';
        loadCurrentRange();
      });
      grid.appendChild(btn);
    });
    miniEl.appendChild(grid);
  };

  const renderCalendarStage = () => {
    closePopover();
    if (state.view === 'month') {
      renderMonthView();
    } else if (state.view === 'week') {
      renderWeekLikeView(false);
    } else if (state.view === 'day') {
      renderWeekLikeView(true);
    } else {
      renderAgendaView();
    }
  };

  const loadCurrentRange = () => {
    updateRangeText();
    renderFrame();
    renderMini();
    const { start, end } = currentRange();
    return fetchRangeBookings(formatDate(start), formatDate(addDays(end, 1))).then(() => {
      renderCalendarStage();
      if (state.focusBookingId && bookingMap[String(state.focusBookingId)]) {
        const target = bookingMap[String(state.focusBookingId)];
        setActiveBooking(null, target);
        openDetail();
        state.focusBookingId = 0;
      }
    });
  };

  const wireCalendarControls = () => {
    if (todayBtn) todayBtn.addEventListener('click', goToday);
    if (prevBtn) prevBtn.addEventListener('click', () => shiftRange(-1));
    if (nextBtn) nextBtn.addEventListener('click', () => shiftRange(1));
    if (viewWrap) {
      viewWrap.querySelectorAll('[data-lc-cal-view]').forEach((btn) => {
        btn.addEventListener('click', () => {
          const nextView = btn.getAttribute('data-lc-cal-view') || 'month';
          if ((nextView === 'agenda') && state.dragBookingId) {
            clearDragReschedule(true);
          }
          state.view = nextView;
          state.mode = 'calendar';
          loadCurrentRange();
        });
      });
    }
    if (statusFilterEl) {
      statusFilterEl.addEventListener('change', () => {
        state.filters.status = statusFilterEl.value || '';
        loadCurrentRange();
      });
    }
    if (employeeFilterEl) {
      employeeFilterEl.addEventListener('change', () => {
        state.filters.employee = employeeFilterEl.value || '';
        loadCurrentRange();
      });
    }
    if (eventFilterEl) {
      eventFilterEl.addEventListener('change', () => {
        state.filters.event = eventFilterEl.value || '';
        loadCurrentRange();
      });
    }
  };

  wireCalendarControls();
  loadCurrentRange();
})();
