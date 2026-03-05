(function () {
  function initPublicServices(scope) {
    var container = scope && scope.querySelectorAll ? scope : document;
    var roots = Array.from(container.querySelectorAll('[data-lc-public-services]')).filter(function (root) {
      return root && root.dataset.lcPublicReady !== '1';
    });

    roots.forEach(function (root) {
      root.dataset.lcPublicReady = '1';

      if (document.body) {
        document.body.classList.add('lc-public-services-active');
      }

      var input = root.querySelector('[data-lc-public-search]');
      var sort = root.querySelector('[data-lc-public-sort]');
      var clearBtn = root.querySelector('[data-lc-public-clear]');
      var grid = root.querySelector('[data-lc-public-grid]');
      var pager = root.querySelector('[data-lc-public-pagination]');
      var empty = root.querySelector('[data-lc-public-empty]');
      var count = root.querySelector('[data-lc-public-count]');
      var filterButtons = Array.prototype.slice.call(root.querySelectorAll('[data-lc-public-filter]'));
      if (!grid) return;

      var pageSize = parseInt(String(root.getAttribute('data-lc-public-per-page') || '6'), 10);
      if (!Number.isFinite(pageSize) || pageSize <= 0) pageSize = 6;
      if (pageSize > 12) pageSize = 12;

      var currentPage = 1;
      var currentFilter = 'all';
      var cards = Array.prototype.slice.call(grid.querySelectorAll('[data-lc-public-service-card]'));

      var applySort = function (items, mode) {
        var result = items.slice();
        result.sort(function (a, b) {
          if (mode === 'name_asc') {
            return String(a.getAttribute('data-name') || '').localeCompare(String(b.getAttribute('data-name') || ''));
          }
          if (mode === 'price_asc') {
            return (parseFloat(a.getAttribute('data-price') || '0') || 0) - (parseFloat(b.getAttribute('data-price') || '0') || 0);
          }
          if (mode === 'price_desc') {
            return (parseFloat(b.getAttribute('data-price') || '0') || 0) - (parseFloat(a.getAttribute('data-price') || '0') || 0);
          }
          return (parseInt(a.getAttribute('data-index') || '0', 10) || 0) - (parseInt(b.getAttribute('data-index') || '0', 10) || 0);
        });
        return result;
      };

      var matchesFilter = function (card) {
        if (currentFilter === 'all') return true;
        if (currentFilter === 'free') {
          return String(card.getAttribute('data-paid') || '0') !== '1';
        }
        if (currentFilter === 'paid') {
          return String(card.getAttribute('data-paid') || '0') === '1';
        }
        if (currentFilter === 'online') {
          return String(card.getAttribute('data-location-type') || '') === 'online';
        }
        if (currentFilter === 'inperson') {
          return String(card.getAttribute('data-location-type') || '') === 'inperson';
        }
        return true;
      };

      var updateFilterUI = function () {
        filterButtons.forEach(function (btn) {
          var key = String(btn.getAttribute('data-lc-public-filter') || 'all');
          var active = key === currentFilter;
          btn.classList.toggle('is-active', active);
          btn.setAttribute('aria-pressed', active ? 'true' : 'false');
        });
      };

      var renderPager = function (totalPages) {
        if (!pager) return;
        pager.innerHTML = '';
        if (totalPages <= 1) {
          pager.style.display = 'none';
          return;
        }
        pager.style.display = '';

        var prev = document.createElement('button');
        prev.type = 'button';
        prev.className = 'lc-public-page-btn';
        prev.textContent = '‹';
        prev.disabled = currentPage <= 1;
        prev.addEventListener('click', function () {
          if (currentPage <= 1) return;
          currentPage -= 1;
          render();
        });
        pager.appendChild(prev);

        for (var page = 1; page <= totalPages; page += 1) {
          var btn = document.createElement('button');
          btn.type = 'button';
          btn.className = 'lc-public-page-btn' + (page === currentPage ? ' is-active' : '');
          btn.textContent = String(page);
          (function (targetPage) {
            btn.addEventListener('click', function () {
              currentPage = targetPage;
              render();
            });
          })(page);
          pager.appendChild(btn);
        }

        var next = document.createElement('button');
        next.type = 'button';
        next.className = 'lc-public-page-btn';
        next.textContent = '›';
        next.disabled = currentPage >= totalPages;
        next.addEventListener('click', function () {
          if (currentPage >= totalPages) return;
          currentPage += 1;
          render();
        });
        pager.appendChild(next);
      };

      var render = function () {
        var search = input ? String(input.value || '').toLowerCase().trim() : '';
        var mode = sort ? String(sort.value || 'default') : 'default';
        var filtered = cards.filter(function (card) {
          var name = String(card.getAttribute('data-name') || '');
          var desc = String(card.getAttribute('data-description') || '');
          if (!matchesFilter(card)) return false;
          if (!search) return true;
          return name.indexOf(search) !== -1 || desc.indexOf(search) !== -1;
        });

        filtered = applySort(filtered, mode);

        var totalPages = Math.max(1, Math.ceil(filtered.length / pageSize));
        if (currentPage > totalPages) currentPage = totalPages;
        var start = (currentPage - 1) * pageSize;
        var visible = filtered.slice(start, start + pageSize);

        cards.forEach(function (card) {
          card.style.display = 'none';
        });
        visible.forEach(function (card) {
          card.style.display = '';
          grid.appendChild(card);
        });

        if (count) count.textContent = String(filtered.length);
        if (empty) empty.style.display = filtered.length ? 'none' : '';
        updateFilterUI();
        renderPager(totalPages);
      };

      if (input) {
        input.addEventListener('input', function () {
          currentPage = 1;
          render();
        });
      }

      if (sort) {
        sort.addEventListener('change', function () {
          currentPage = 1;
          render();
        });
      }

      filterButtons.forEach(function (btn) {
        btn.addEventListener('click', function () {
          var key = String(btn.getAttribute('data-lc-public-filter') || 'all');
          if (currentFilter === key) return;
          currentFilter = key;
          currentPage = 1;
          render();
        });
      });

      if (clearBtn) {
        clearBtn.addEventListener('click', function () {
          if (input) input.value = '';
          if (sort) sort.value = 'default';
          currentFilter = 'all';
          currentPage = 1;
          render();
        });
      }

      var showReserveLoader = function () {
        var loader = document.getElementById('lc-reserve-loader');
        if (!loader) {
          loader = document.createElement('div');
          loader.id = 'lc-reserve-loader';
          loader.className = 'lc-reserve-loader';
          loader.setAttribute('aria-hidden', 'true');
          loader.innerHTML = '<span class="lc-reserve-loader-dot"></span>';
          document.body.appendChild(loader);
        }
        window.requestAnimationFrame(function () {
          loader.classList.add('is-visible');
        });
      };

      root.addEventListener('click', function (event) {
        var target = event.target;
        if (!target || typeof target.closest !== 'function') return;
        var reserveLink = target.closest('.lc-public-service-btn');
        if (!reserveLink) return;
        if (event.defaultPrevented) return;
        if (event.button !== 0) return;
        if (event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) return;
        if (reserveLink.getAttribute('target') && reserveLink.getAttribute('target') !== '_self') return;
        var href = reserveLink.getAttribute('href');
        if (!href) return;
        if (reserveLink.dataset.lcOpening === '1') {
          event.preventDefault();
          return;
        }
        reserveLink.dataset.lcOpening = '1';
        event.preventDefault();
        showReserveLoader();
        window.setTimeout(function () {
          window.location.assign(href);
        }, 80);
      });

      render();
    });
  }

  function init(scope) {
    if (!window.litecalEvent) return;

    const scopeRoot = scope && typeof scope.querySelectorAll === 'function' ? scope : document;

    if (document.body) {
      document.body.classList.add('lc-public-active');
    }

    const isBuilderEditor = !!window.litecalBuilderEditor;

    const enforceMobileViewportLock = () => {
      if (isBuilderEditor) {
        return;
      }
      if (!window.matchMedia || !window.matchMedia('(max-width: 1024px)').matches) {
        return;
      }
      const targetContent = 'width=device-width, initial-scale=1, maximum-scale=1, user-scalable=no, viewport-fit=cover';
      let viewportEl = document.querySelector('meta[name="viewport"]');
      if (!viewportEl) {
        viewportEl = document.createElement('meta');
        viewportEl.setAttribute('name', 'viewport');
        document.head.appendChild(viewportEl);
      }
      viewportEl.setAttribute('content', targetContent);
    };
    enforceMobileViewportLock();
    window.addEventListener('orientationchange', enforceMobileViewportLock);
    window.addEventListener('resize', enforceMobileViewportLock, { passive: true });

    const enforceRecaptchaCompactMobile = () => {
      if (isBuilderEditor) {
        return;
      }
      if (!window.matchMedia || !window.matchMedia('(max-width: 900px)').matches) {
        return;
      }
      const applyCompact = (badge) => {
        if (!badge) return;
        badge.style.setProperty('right', '-186px', 'important');
        badge.style.setProperty('left', 'auto', 'important');
        badge.style.setProperty('width', '256px', 'important');
        badge.style.setProperty('max-width', '256px', 'important');
        badge.style.setProperty('transform', 'none', 'important');
      };
      const applyAll = () => {
        document.querySelectorAll('.grecaptcha-badge').forEach(applyCompact);
      };

      applyAll();
      window.setTimeout(applyAll, 300);
      window.setTimeout(applyAll, 900);

      const badgeObserver = new MutationObserver(() => {
        applyAll();
      });
      badgeObserver.observe(document.documentElement, { childList: true, subtree: true });
      window.setTimeout(() => {
        badgeObserver.disconnect();
      }, 12000);

      window.addEventListener('orientationchange', applyAll);
      window.addEventListener('resize', applyAll, { passive: true });
      window.addEventListener('touchstart', applyAll, { passive: true });
      window.addEventListener('touchend', applyAll, { passive: true });
      window.addEventListener('click', applyAll, { passive: true });
    };
    enforceRecaptchaCompactMobile();

    const state = {
      date: null,
      slot: null,
      slots: [],
      employeeId: window.litecalEvent.employees.length === 1 ? window.litecalEvent.employees[0].id : 0,
      timeFormat: '12h',
      sourceTimezone: 'UTC',
      timezone: 'UTC',
      manageToken: '',
      manageActionAllowed: true,
    };
    const escHtml = (value) =>
      String(value == null ? '' : value)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#39;');
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

    const cardEl = Array.from(scopeRoot.querySelectorAll('[data-lc-card]')).find((el) => el.dataset.litecalReady !== '1');
    if (!cardEl) return;
    cardEl.dataset.litecalReady = '1';
    const wrapEl = cardEl.closest('.lc-wrap') || cardEl.closest('.litecal-widget-shell') || scopeRoot || document;
    const builderBookingRoot = cardEl.closest('.litecal-builder-frontend--service-booking');
    const builderBookingWrap =
      builderBookingRoot && wrapEl && wrapEl.classList && wrapEl.classList.contains('lc-wrap--shortcode-clean')
        ? wrapEl
        : null;
    const setImportantStyle = (element, property, value) => {
      if (!element || !element.style) return;
      element.style.setProperty(property, value, 'important');
    };
    const forceStretchBox = (element, options) => {
      if (!element) return;
      const config = options || {};
      setImportantStyle(element, 'width', config.width || '100%');
      setImportantStyle(element, 'max-width', config.maxWidth || '100%');
      setImportantStyle(element, 'min-width', config.minWidth || '0');
      if (config.flex) {
        setImportantStyle(element, 'flex', config.flex);
      }
      if (config.flexBasis) {
        setImportantStyle(element, 'flex-basis', config.flexBasis);
      }
      if (config.alignSelf) {
        setImportantStyle(element, 'align-self', config.alignSelf);
      }
      if (config.display) {
        setImportantStyle(element, 'display', config.display);
      }
    };
    const query = (selector) => wrapEl.querySelector(selector);
    const queryAll = (selector) => Array.from(wrapEl.querySelectorAll(selector));
    const calendarEl = query('[data-lc-calendar]');
    const slotsEl = query('[data-lc-slots]');
    const formEl = query('[data-lc-form]');
    const summaryEl = query('[data-lc-summary]');
    const nextBtn = query('[data-lc-next]');
    const backBtn = query('[data-lc-back]');
    const dateLabel = query('[data-lc-selected-date]');
    const successSummary = query('[data-lc-success-summary]');
    const successBox = query('.lc-success');
    const restartBtn = query('[data-lc-restart]');
    const employeeSelect = query('[data-lc-employee]');
    const employeeStep = query('[data-lc-employee-step]');
    const employeeCards = queryAll('[data-lc-employee-card]');
    const changeEmployeeBtn = query('[data-lc-change-employee]');
    const hostWrap = query('[data-lc-host]');
    const hostAvatarEl = query('[data-lc-host-avatar]');
    const hostFallbackEl = query('[data-lc-host-fallback]');
    const hostNameEl = query('[data-lc-host-name]');
    const hostTitleEl = query('[data-lc-host-title]');
    const submitBtn = query('[data-lc-submit]');
    const extrasWrap = query('[data-lc-extras]');
    const extrasTotalEl = query('[data-lc-extras-total]');
    const extrasItemInputs = queryAll('[data-lc-extra-item]');
    const extrasHoursWrap = query('[data-lc-extra-hours]');
    const extrasHoursUnitsInputs = queryAll('[data-lc-extra-hours-units]');
    const extrasHoursUnitsInput = extrasHoursUnitsInputs.length ? extrasHoursUnitsInputs[0] : null;
    const extrasHoursNoteEl = query('[data-lc-extra-hours-note]');
    const extrasHoursDecBtn = query('[data-lc-extra-hours-dec]');
    const extrasHoursIncBtn = query('[data-lc-extra-hours-inc]');
    const timeToggle = query('[data-lc-time-toggle]');
    const timezoneSelect = query('[data-lc-timezone-select]');
    const paymentConfig = window.litecalEvent?.payment || {};
    const paymentMode = String(paymentConfig.mode || '').toLowerCase();
    const paymentCurrency = String(paymentConfig.currency || 'CLP').toUpperCase();
    const paymentMethods = Array.isArray(paymentConfig.methods) ? paymentConfig.methods : [];
    const paymentMethodMap = paymentMethods.reduce((acc, method) => {
      const key = String(method?.key || '').toLowerCase();
      if (key) acc[key] = method;
      return acc;
    }, {});
    const paymentOptionEls = queryAll('[data-lc-payment-option]');
    const summaryDefaultText = summaryEl ? String(summaryEl.textContent || '').trim() : '';
    const dateLabelDefaultText = dateLabel ? String(dateLabel.textContent || '').trim() : 'Selecciona un día';
    const hasMultipleEmployees = !!window.litecalEvent?.has_multiple_employees;
    const manageCfg = window.litecalEvent?.manage || {};
    const isManageView = Number(manageCfg.enabled || 0) === 1;
    const manageAction = String(manageCfg.action || '').toLowerCase();
    const defaultManageBlockedMessage = manageAction === 'cancel'
      ? 'Esta reserva ya fue cancelada y no permite más cambios.'
      : 'Ya no puedes reagendar esta reserva con este enlace.';
    const manageBookingId = Number(manageCfg.booking_id || 0);
    const manageBookingToken = String(manageCfg.booking_token || '');
    state.manageToken = manageBookingToken;
    const manageBoxEl = query('[data-lc-manage-box]');
    const manageCurrentEl = query('[data-lc-manage-current]');
    const managePolicyEl = query('[data-lc-manage-policy]');
    const manageReasonEl = query('[data-lc-manage-reason]');
    const manageCancelBtn = query('[data-lc-manage-cancel]');
    const selectStageEl = query('[data-lc-stage="select"]');
    let manageState = null;
    let manageCanChangeStaff = true;
    let stageAnimationTimer = 0;
    let slotsAbortController = null;

    const stabilizeBuilderBookingAncestors = () => {
      if (!builderBookingRoot) {
        return;
      }

      forceStretchBox(builderBookingRoot, {
        flex: '1 1 100%',
        flexBasis: '100%',
        alignSelf: 'stretch',
      });

      if (builderBookingWrap) {
        forceStretchBox(builderBookingWrap, {
          flex: '1 1 100%',
          flexBasis: '100%',
          alignSelf: 'stretch',
          display: 'block',
        });
      }

      const gutenbergColumn = builderBookingRoot.closest('.wp-block-column');
      const gutenbergColumns = builderBookingRoot.closest('.wp-block-columns');
      const gutenbergRow = gutenbergColumns && gutenbergColumns.parentElement
        ? gutenbergColumns.parentElement.closest('.wp-block-group.is-layout-flex, .wp-block-group.is-layout-grid, .wp-block-group')
        : null;
      const gutenbergColumnCount = gutenbergColumns
        ? Array.from(gutenbergColumns.children).filter((child) => child.classList && child.classList.contains('wp-block-column')).length
        : 0;

      if (gutenbergColumn && (!gutenbergColumns || gutenbergColumnCount <= 1)) {
        forceStretchBox(gutenbergColumn, {
          flex: '1 1 100%',
          flexBasis: '100%',
          alignSelf: 'stretch',
        });
      }

      if (gutenbergColumns) {
        forceStretchBox(gutenbergColumns, {
          flex: '1 1 100%',
          flexBasis: '100%',
          alignSelf: 'stretch',
        });
      }

      if (gutenbergRow) {
        forceStretchBox(gutenbergRow, {
          width: '100%',
          maxWidth: '100%',
          minWidth: '0',
        });
      }

      const elementorWidget = builderBookingRoot.closest('.elementor-widget');
      const elementorWidgetWrap = builderBookingRoot.closest('.elementor-widget-wrap');
      const elementorColumn = builderBookingRoot.closest('.elementor-column');
      const elementorContainer = builderBookingRoot.closest('.elementor-container, .e-con-inner, .e-con');

      forceStretchBox(elementorWidget, {
        flex: '1 1 100%',
        flexBasis: '100%',
        alignSelf: 'stretch',
      });
      forceStretchBox(elementorWidgetWrap, {
        flex: '1 1 100%',
        flexBasis: '100%',
        alignSelf: 'stretch',
      });
      forceStretchBox(elementorColumn, {
        flex: '1 1 100%',
        flexBasis: '100%',
        alignSelf: 'stretch',
      });
      forceStretchBox(elementorContainer, {
        width: '100%',
        maxWidth: '100%',
        minWidth: '0',
      });

      const bakeryElement = builderBookingRoot.closest('.wpb_content_element');
      const bakeryColumn = builderBookingRoot.closest('.vc_column_container');
      const bakeryColumnInner = builderBookingRoot.closest('.vc_column-inner');
      const bakeryRow = builderBookingRoot.closest('.vc_row, .wpb_row');

      forceStretchBox(bakeryElement, {
        flex: '1 1 100%',
        flexBasis: '100%',
        alignSelf: 'stretch',
      });
      forceStretchBox(bakeryColumn, {
        flex: '1 1 100%',
        flexBasis: '100%',
        alignSelf: 'stretch',
      });
      forceStretchBox(bakeryColumnInner, {
        width: '100%',
        maxWidth: '100%',
        minWidth: '0',
      });
      forceStretchBox(bakeryRow, {
        width: '100%',
        maxWidth: '100%',
        minWidth: '0',
      });
    };

    const syncBuilderBookingLayout = () => {
      if (!builderBookingWrap) {
        return;
      }
      stabilizeBuilderBookingAncestors();
      const wrapWidth = Math.round(builderBookingWrap.getBoundingClientRect().width || 0);
      const rootWidth = builderBookingRoot ? Math.round(builderBookingRoot.getBoundingClientRect().width || 0) : 0;
      const activeWidth = Math.max(wrapWidth, rootWidth);
      builderBookingWrap.classList.toggle('is-builder-stacked', activeWidth > 0 && activeWidth <= 1024);
    };

    if (builderBookingWrap) {
      stabilizeBuilderBookingAncestors();
      syncBuilderBookingLayout();
      if ('ResizeObserver' in window) {
        const builderLayoutObserver = new ResizeObserver(() => {
          syncBuilderBookingLayout();
        });
        builderLayoutObserver.observe(builderBookingWrap);
        if (builderBookingRoot && builderBookingRoot !== builderBookingWrap) {
          builderLayoutObserver.observe(builderBookingRoot);
        }
      }
      window.addEventListener('resize', syncBuilderBookingLayout, { passive: true });
    }

    if (changeEmployeeBtn && !hasMultipleEmployees) {
      changeEmployeeBtn.remove();
    } else if (changeEmployeeBtn && isManageView && manageAction === 'reschedule') {
      changeEmployeeBtn.hidden = true;
    }
    if (employeeStep && isManageView && manageAction === 'reschedule') {
      employeeStep.hidden = true;
      if (cardEl) {
        cardEl.classList.remove('is-employee-pick');
      }
    }

    const paramsEarly = new URLSearchParams(window.location.search || '');
    const returnProviderEarly = paramsEarly.get('agendalite_payment') || (paramsEarly.get('agendalite_receipt') === '1' ? 'receipt' : '');
    const returnBookingEarly = paramsEarly.get('booking_id');
    const isReturnView = !!(returnProviderEarly && returnBookingEarly);

    const applyReturnViewLayout = () => {
      document.body.classList.add('lc-return-view');
      if (wrapEl) {
        wrapEl.classList.add('is-return-view');
      }
      queryAll('[data-lc-stage="select"]').forEach((el) => {
        el.style.display = 'none';
      });
      queryAll('[data-lc-stage="form"]').forEach((el) => {
        el.style.display = 'none';
      });
      if (cardEl) {
        cardEl.classList.remove('is-form', 'is-employee-pick');
        cardEl.classList.add('is-success');
      }
      if (successBox) {
        successBox.classList.add('is-receipt-only');
      }
    };

    const setButtonLoading = (button, loading) => {
      if (!button) return;
      if (loading) {
        button.classList.add('is-loading');
        button.disabled = true;
        button.setAttribute('aria-busy', 'true');
        return;
      }
      button.classList.remove('is-loading');
      button.removeAttribute('aria-busy');
      button.disabled = false;
    };

    if (isReturnView) {
      applyReturnViewLayout();
    }

    const setCookie = (name, value, maxAgeSeconds) => {
      try {
        const safeName = encodeURIComponent(String(name || ''));
        const safeValue = encodeURIComponent(String(value || ''));
        if (!safeName) return;
        const maxAge = Number(maxAgeSeconds) > 0 ? `; max-age=${Math.floor(Number(maxAgeSeconds))}` : '';
        document.cookie = `${safeName}=${safeValue}${maxAge}; path=/; samesite=lax`;
      } catch (_) {
        // noop
      }
    };

    const randomDeviceId = () => {
      try {
        if (window.crypto && typeof window.crypto.getRandomValues === 'function') {
          const bytes = new Uint8Array(16);
          window.crypto.getRandomValues(bytes);
          return Array.from(bytes, (b) => b.toString(16).padStart(2, '0')).join('');
        }
      } catch (_) {
        // noop
      }
      return `${Date.now().toString(16)}${Math.random().toString(16).slice(2, 14)}`;
    };

    const getClientDeviceId = () => {
      const key = 'litecal_device_id';
      let deviceId = '';
      try {
        deviceId = window.localStorage ? String(window.localStorage.getItem(key) || '') : '';
      } catch (_) {
        deviceId = '';
      }
      deviceId = deviceId.toLowerCase().replace(/[^a-z0-9_-]/g, '').slice(0, 96);
      if (!deviceId) {
        deviceId = randomDeviceId();
        try {
          if (window.localStorage) {
            window.localStorage.setItem(key, deviceId);
          }
        } catch (_) {
          // noop
        }
      }
      setCookie('litecal_device_id', deviceId, 31536000);
      return deviceId;
    };

    const clientDeviceId = getClientDeviceId();

    const storedFormatRaw = window.localStorage ? window.localStorage.getItem('litecal_time_format') : null;
    const storedTimezoneRaw = window.localStorage ? window.localStorage.getItem('litecal_timezone') : null;
    const defaultFormat =
      (window.litecalEvent.settings && window.litecalEvent.settings.time_format) || '12h';
    const scheduleTimezone = (window.litecalEvent.settings && window.litecalEvent.settings.schedule_timezone) || 'UTC';
    const storedFormat = storedFormatRaw === '12h' || storedFormatRaw === '24h' ? storedFormatRaw : null;
    state.timeFormat = storedFormat || defaultFormat;
    state.sourceTimezone = scheduleTimezone || 'UTC';
    state.timezone = storedTimezoneRaw || state.sourceTimezone;

    const getBrowserTimezone = () => {
      try {
        return Intl.DateTimeFormat().resolvedOptions().timeZone || '';
      } catch (_) {
        return '';
      }
    };

    const supportedTimezones = () => {
      const zones = new Set();
      const browserTz = getBrowserTimezone();
      if (state.sourceTimezone) zones.add(state.sourceTimezone);
      if (browserTz) zones.add(browserTz);
      if (window.Intl && typeof window.Intl.supportedValuesOf === 'function') {
        try {
          window.Intl.supportedValuesOf('timeZone').forEach((z) => zones.add(z));
        } catch (_) {
          // noop
        }
      }
      if (!zones.size) {
        ['UTC', 'America/Santiago', 'America/New_York', 'Europe/Madrid'].forEach((z) => zones.add(z));
      }
      return Array.from(zones);
    };

    const isValidTimeZone = (tz) => {
      if (!tz || typeof tz !== 'string') return false;
      try {
        Intl.DateTimeFormat('en-US', { timeZone: tz }).format(new Date());
        return true;
      } catch (_) {
        return false;
      }
    };

    const timezoneSelectOptions = () => {
      if (!timezoneSelect) return;
      const zones = supportedTimezones();
      const browserTz = getBrowserTimezone();
      const frag = document.createDocumentFragment();
      zones.forEach((zone) => {
        const option = document.createElement('option');
        option.value = zone;
        option.textContent = zone;
        if (zone === state.sourceTimezone) {
          option.textContent = `${zone} (Por defecto)`;
        } else if (browserTz && zone === browserTz) {
          option.textContent = `${zone} (tu zona)`;
        }
        frag.appendChild(option);
      });
      timezoneSelect.innerHTML = '';
      timezoneSelect.appendChild(frag);
      if (!isValidTimeZone(state.timezone)) {
        state.timezone = state.sourceTimezone;
      }
      timezoneSelect.value = zones.includes(state.timezone) ? state.timezone : state.sourceTimezone;
      state.timezone = timezoneSelect.value;
    };

    let timezonePicker = null;
    const isCoarsePointer = () => {
      if (isBuilderEditor) {
        return false;
      }
      const ua = String(window.navigator?.userAgent || '');
      const mobileUa = /iPhone|iPad|iPod|Android|Mobile|CriOS|FxiOS/i.test(ua);
      const narrowViewport = window.innerWidth <= 900;
      return !!(
        mobileUa ||
        narrowViewport ||
        (window.matchMedia && window.matchMedia('(hover: none), (pointer: coarse)').matches) ||
        'ontouchstart' in window
      );
    };

    const timezoneIcon = (zone) => {
      return 'ri-global-line';
    };

    const timezoneLabel = (zone) => (zone === state.sourceTimezone ? `${zone} · Por defecto` : zone);

    const renderTimezonePicker = () => {
      if (!timezonePicker || !timezonePicker.list || !timezonePicker.search) return;
      const query = String(timezonePicker.search.value || '').trim().toLowerCase();
      timezonePicker.list.innerHTML = '';
      supportedTimezones()
        .filter((zone) => (!query ? true : zone.toLowerCase().includes(query)))
        .forEach((zone) => {
          const option = document.createElement('button');
          option.type = 'button';
          option.className = 'lc-tz-option';
          option.dataset.tz = zone;
          if (zone === state.timezone) {
            option.classList.add('is-active');
          }
          option.innerHTML = `<i class="${timezoneIcon(zone)}"></i><span>${escHtml(timezoneLabel(zone))}</span>`;
          timezonePicker.list.appendChild(option);
        });
    };

    const refreshTimezoneTrigger = () => {
      if (!timezonePicker || !timezonePicker.label) return;
      timezonePicker.label.textContent = timezoneLabel(state.timezone || state.sourceTimezone);
    };

    let timezoneCloseTimer = 0;

    const closeTimezonePicker = () => {
      if (!timezonePicker || !timezonePicker.panel) return;
      timezonePicker.root.classList.remove('is-open');
      timezonePicker.panel.classList.remove('is-open');
      if (timezonePicker.overlay) timezonePicker.overlay.classList.remove('is-open');
      document.documentElement.classList.remove('lc-tz-sheet-open');
      window.clearTimeout(timezoneCloseTimer);
      timezoneCloseTimer = window.setTimeout(() => {
        if (!timezonePicker || timezonePicker.panel.classList.contains('is-open')) return;
        timezonePicker.panel.hidden = true;
        if (timezonePicker.overlay) timezonePicker.overlay.hidden = true;
      }, 240);
    };

    const positionTimezonePanel = () => {
      if (!timezonePicker || !timezonePicker.panel) return;
      const panel = timezonePicker.panel;
      const viewportWidth = Math.max(window.innerWidth || 0, 320);
      const viewportHeight = Math.max(window.innerHeight || 0, 320);
      const panelWidth = Math.min(540, Math.max(260, viewportWidth - 28));
      const panelMaxHeight = Math.min(560, Math.max(260, viewportHeight - 120));
      panel.style.width = `${panelWidth}px`;
      panel.style.maxWidth = `calc(100vw - 28px)`;
      panel.style.maxHeight = `${panelMaxHeight}px`;
    };

    const initTimezonePicker = () => {
      if (!timezoneSelect) return;
      const root = document.createElement('div');
      root.className = 'lc-tz-picker';
      const trigger = document.createElement('button');
      trigger.type = 'button';
      trigger.className = 'lc-tz-trigger';
      trigger.innerHTML = '<span class="lc-tz-trigger-text"></span>';
      const panel = document.createElement('div');
      panel.className = 'lc-tz-panel';
      panel.hidden = true;
      const overlay = document.createElement('div');
      overlay.className = 'lc-tz-overlay';
      overlay.hidden = true;
      const search = document.createElement('input');
      search.type = 'search';
      search.className = 'lc-tz-search';
      search.placeholder = 'Buscar zona horaria...';
      const list = document.createElement('div');
      list.className = 'lc-tz-list';
      panel.appendChild(search);
      panel.appendChild(list);
      root.appendChild(trigger);
      timezoneSelect.insertAdjacentElement('afterend', root);
      document.body.appendChild(overlay);
      document.body.appendChild(panel);
      timezonePicker = {
        root,
        trigger,
        panel,
        overlay,
        search,
        list,
        label: trigger.querySelector('.lc-tz-trigger-text'),
      };
      refreshTimezoneTrigger();
      renderTimezonePicker();

      trigger.addEventListener('click', () => {
        const nextOpen = panel.hidden || !panel.classList.contains('is-open');
        if (nextOpen) {
          window.clearTimeout(timezoneCloseTimer);
          panel.hidden = false;
          overlay.hidden = false;
          root.classList.add('is-open');
          document.documentElement.classList.add('lc-tz-sheet-open');
          search.value = '';
          renderTimezonePicker();
          positionTimezonePanel();
          window.requestAnimationFrame(() => {
            panel.classList.add('is-open');
            overlay.classList.add('is-open');
          });
          window.setTimeout(() => {
            search.focus({ preventScroll: true });
          }, 30);
        } else {
          closeTimezonePicker();
        }
      });
      overlay.addEventListener('click', closeTimezonePicker);
      search.addEventListener('input', renderTimezonePicker);
      list.addEventListener('click', (event) => {
        const option = event.target.closest('.lc-tz-option[data-tz]');
        if (!option) return;
        setTimezone(option.dataset.tz || state.sourceTimezone);
        closeTimezonePicker();
      });
      document.addEventListener('click', (event) => {
        if (!root.contains(event.target) && !panel.contains(event.target)) {
          closeTimezonePicker();
        }
      });
      document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape') {
          closeTimezonePicker();
        }
      });
      window.addEventListener('resize', positionTimezonePanel);
    };

    let selectSheet = null;
    let selectSheetCloseTimer = 0;
    let selectSheetActive = null;

    const closeSelectSheet = () => {
      if (!selectSheet || !selectSheet.panel) return;
      selectSheet.panel.classList.remove('is-open');
      selectSheet.overlay.classList.remove('is-open');
      document.documentElement.classList.remove('lc-select-sheet-open');
      window.clearTimeout(selectSheetCloseTimer);
      selectSheetCloseTimer = window.setTimeout(() => {
        if (!selectSheet || selectSheet.panel.classList.contains('is-open')) return;
        selectSheet.panel.hidden = true;
        selectSheet.overlay.hidden = true;
      }, 220);
    };

    const ensureSelectSheet = () => {
      if (selectSheet) return selectSheet;
      const overlay = document.createElement('div');
      overlay.className = 'lc-select-sheet-overlay';
      overlay.hidden = true;

      const panel = document.createElement('div');
      panel.className = 'lc-select-sheet-panel';
      panel.hidden = true;

      const head = document.createElement('div');
      head.className = 'lc-select-sheet-head';

      const title = document.createElement('strong');
      title.className = 'lc-select-sheet-title';
      title.textContent = 'Seleccionar';

      const closeBtn = document.createElement('button');
      closeBtn.type = 'button';
      closeBtn.className = 'lc-select-sheet-close';
      closeBtn.setAttribute('aria-label', 'Cerrar');
      closeBtn.innerHTML = '<i class="ri-close-line"></i>';

      head.appendChild(title);
      head.appendChild(closeBtn);

      const list = document.createElement('div');
      list.className = 'lc-select-sheet-list';

      panel.appendChild(head);
      panel.appendChild(list);

      document.body.appendChild(overlay);
      document.body.appendChild(panel);

      selectSheet = {
        overlay,
        panel,
        title,
        closeBtn,
        list,
      };

      overlay.addEventListener('click', closeSelectSheet);
      closeBtn.addEventListener('click', closeSelectSheet);
      document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape') {
          closeSelectSheet();
        }
      });

      return selectSheet;
    };

    const selectCurrentLabel = (select) => {
      if (!select) return 'Seleccionar';
      const selectedOption = select.options && select.selectedIndex >= 0
        ? select.options[select.selectedIndex]
        : null;
      const fallbackOption = select.options && select.options.length ? select.options[0] : null;
      const option = selectedOption || fallbackOption;
      const text = option ? String(option.textContent || option.label || '').trim() : '';
      return text || 'Seleccionar';
    };

    const syncSelectTrigger = (instance) => {
      if (!instance || !instance.select || !instance.trigger || !instance.label) return;
      instance.label.textContent = selectCurrentLabel(instance.select);
      const isDisabled = !!instance.select.disabled;
      instance.trigger.disabled = isDisabled;
      instance.root.classList.toggle('is-disabled', isDisabled);
    };

    const renderSelectSheetOptions = () => {
      if (!selectSheet || !selectSheetActive || !selectSheetActive.select) return;
      const select = selectSheetActive.select;
      selectSheet.list.innerHTML = '';

      const options = Array.from(select.options || []);
      let visibleCount = 0;
      options.forEach((option) => {
        const value = String(option.value || '');
        const label = String(option.textContent || option.label || '').trim();
        if (!label) return;
        visibleCount += 1;
        const btn = document.createElement('button');
        btn.type = 'button';
        btn.className = 'lc-select-sheet-option';
        btn.dataset.value = value;
        btn.textContent = label;
        if (option.disabled) {
          btn.disabled = true;
          btn.classList.add('is-disabled');
        }
        if (String(select.value || '') === value) {
          btn.classList.add('is-active');
        }
        selectSheet.list.appendChild(btn);
      });

      if (!visibleCount) {
        const empty = document.createElement('div');
        empty.className = 'lc-select-sheet-empty';
        empty.textContent = 'Sin resultados';
        selectSheet.list.appendChild(empty);
      }
    };

    const openSelectSheet = (instance) => {
      if (!instance || !instance.select || instance.select.disabled) return;
      const sheet = ensureSelectSheet();
      selectSheetActive = instance;
      sheet.title.textContent = instance.title || 'Seleccionar';
      renderSelectSheetOptions();
      window.clearTimeout(selectSheetCloseTimer);
      sheet.panel.hidden = false;
      sheet.overlay.hidden = false;
      document.documentElement.classList.add('lc-select-sheet-open');
      window.requestAnimationFrame(() => {
        sheet.panel.classList.add('is-open');
        sheet.overlay.classList.add('is-open');
      });
    };

    const shouldEnhanceSelect = (select) => {
      if (!select || select.dataset.lcSelectEnhanced === '1') return false;
      if (select === timezoneSelect) return false;
      if (select.multiple) return false;
      if ((select.options || []).length <= 0) return false;
      if (select.closest('.lc-timezone-select-wrap')) return false;
      return true;
    };

    const initModalSelectPickers = () => {
      const selects = queryAll('select').filter(shouldEnhanceSelect);
      if (!selects.length) return;
      const instances = [];

      selects.forEach((select) => {
        select.dataset.lcSelectEnhanced = '1';
        const root = document.createElement('div');
        root.className = 'lc-tz-picker lc-sheet-select';
        const trigger = document.createElement('button');
        trigger.type = 'button';
        trigger.className = 'lc-tz-trigger lc-sheet-select-trigger';
        trigger.innerHTML = '<span class="lc-tz-trigger-text lc-sheet-select-trigger-text"></span>';
        const label = trigger.querySelector('.lc-sheet-select-trigger-text');

        select.classList.add('lc-sheet-select-native');
        select.insertAdjacentElement('afterend', root);
        root.appendChild(trigger);

        const fieldLabelEl = select.closest('label') || select.parentElement?.querySelector('label');
        const title = fieldLabelEl ? String(fieldLabelEl.textContent || '').trim() : 'Seleccionar';
        const instance = { select, root, trigger, label, title: title || 'Seleccionar' };
        instances.push(instance);
        syncSelectTrigger(instance);

        trigger.addEventListener('click', () => {
          openSelectSheet(instance);
        });
        select.addEventListener('change', () => {
          syncSelectTrigger(instance);
          if (selectSheetActive && selectSheetActive.select === select) {
            renderSelectSheetOptions();
          }
        });
      });

      const sheet = ensureSelectSheet();
      sheet.list.addEventListener('click', (event) => {
        const option = event.target.closest('.lc-select-sheet-option[data-value]');
        if (!option || !selectSheetActive || !selectSheetActive.select) return;
        const select = selectSheetActive.select;
        const nextValue = String(option.dataset.value || '');
        if (String(select.value || '') !== nextValue) {
          select.value = nextValue;
          select.dispatchEvent(new Event('change', { bubbles: true }));
        } else {
          syncSelectTrigger(selectSheetActive);
        }
        closeSelectSheet();
      });

      document.addEventListener('click', (event) => {
        if (!selectSheet || !selectSheetActive) return;
        const trigger = selectSheetActive.trigger;
        const panel = selectSheet.panel;
        if ((trigger && trigger.contains(event.target)) || (panel && panel.contains(event.target))) {
          return;
        }
        closeSelectSheet();
      });

      window.addEventListener('resize', () => {
        if (!selectSheet || selectSheet.panel.hidden) return;
        // keep rendering synced while viewport changes.
        renderSelectSheetOptions();
      });

      // Keep trigger labels in sync when script updates values programmatically.
      instances.forEach((instance) => syncSelectTrigger(instance));
    };

    const initLocationTooltips = () => {
      const nodes = queryAll('[data-lc-tooltip-content]');
      if (!nodes.length) return;
      const touchMode = isCoarsePointer();
      let mobileSheet = document.querySelector('[data-lc-tooltip-sheet]');
      let mobileSheetText = mobileSheet ? mobileSheet.querySelector('[data-lc-tooltip-sheet-text]') : null;
      const ensureMobileSheet = () => {
        if (mobileSheet && mobileSheetText) return;
        mobileSheet = document.createElement('div');
        mobileSheet.className = 'lc-tooltip-mobile-sheet';
        mobileSheet.setAttribute('data-lc-tooltip-sheet', '');
        mobileSheet.hidden = true;
        mobileSheet.innerHTML = `
          <div class="lc-tooltip-mobile-sheet__backdrop" data-lc-tooltip-sheet-close></div>
          <div class="lc-tooltip-mobile-sheet__card" role="dialog" aria-modal="true" aria-label="Información">
            <button type="button" class="lc-tooltip-mobile-sheet__close" data-lc-tooltip-sheet-close aria-label="Cerrar">
              <i class="ri-close-line"></i>
            </button>
            <div class="lc-tooltip-mobile-sheet__title">Información</div>
            <p class="lc-tooltip-mobile-sheet__text" data-lc-tooltip-sheet-text></p>
          </div>
        `;
        document.body.appendChild(mobileSheet);
        mobileSheetText = mobileSheet.querySelector('[data-lc-tooltip-sheet-text]');
        mobileSheet.addEventListener('click', (event) => {
          if (event.target.closest('[data-lc-tooltip-sheet-close]')) {
            mobileSheet.hidden = true;
            document.documentElement.classList.remove('lc-tooltip-sheet-open');
          }
        });
      };
      const openMobileSheet = (content) => {
        ensureMobileSheet();
        if (!mobileSheet || !mobileSheetText) return;
        mobileSheetText.textContent = String(content || '');
        mobileSheet.hidden = false;
        document.documentElement.classList.add('lc-tooltip-sheet-open');
      };
      const closeMobileSheet = () => {
        if (!mobileSheet) return;
        mobileSheet.hidden = true;
        document.documentElement.classList.remove('lc-tooltip-sheet-open');
      };
      nodes.forEach((el) => {
        const content = String(el.getAttribute('data-lc-tooltip-content') || '').trim();
        if (!content) return;
        el.classList.remove('lc-tooltip-fallback');
        el.classList.remove('is-open');
        if (!touchMode && typeof window.tippy === 'function') {
          el.removeAttribute('title');
          if (el._tippy) {
            el._tippy.destroy();
          }
          try {
            window.tippy(el, {
              content,
              placement: touchMode ? 'bottom' : 'right-end',
              arrow: true,
              allowHTML: false,
              interactive: touchMode,
              theme: 'light-border',
              delay: touchMode ? [0, 0] : [120, 0],
              trigger: touchMode ? 'click' : 'mouseenter focus',
              hideOnClick: true,
              appendTo: () => document.body,
            });
          } catch (_err) {
            el.setAttribute('title', content);
            el.classList.add('lc-tooltip-fallback');
          }
        } else {
          if (!touchMode) {
            el.setAttribute('title', content);
            el.classList.add('lc-tooltip-fallback');
            return;
          }
          el.removeAttribute('title');
          el.classList.add('lc-tooltip-fallback');
          const openTouchTooltip = (event) => {
            event.preventDefault();
            event.stopPropagation();
            openMobileSheet(content);
          };
          el.addEventListener('click', openTouchTooltip);
          el.addEventListener('touchstart', openTouchTooltip, { passive: false });
        }
      });
      document.addEventListener('click', (event) => {
        if (event.target.closest('[data-lc-tooltip-content]')) return;
        nodes.forEach((node) => node.classList.remove('is-open'));
        closeMobileSheet();
      });
      document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape') {
          closeMobileSheet();
        }
      });
    };

    const setTimeFormat = (format) => {
      state.timeFormat = format === '24h' ? '24h' : '12h';
      if (window.localStorage) {
        window.localStorage.setItem('litecal_time_format', state.timeFormat);
      }
      if (timeToggle) {
        timeToggle.querySelectorAll('[data-lc-time-format]').forEach((btn) => {
          const isActive = btn.dataset.lcTimeFormat === state.timeFormat;
          btn.classList.toggle('is-active', isActive);
        });
      }
      if (state.date) {
        renderSlots(state.slots || []);
        updateSummary();
      }
    };

    const setTimezone = (timezone) => {
      const next = isValidTimeZone(timezone) ? timezone : state.sourceTimezone;
      state.timezone = next;
      if (window.localStorage) {
        window.localStorage.setItem('litecal_timezone', state.timezone);
      }
      if (timezoneSelect && timezoneSelect.value !== state.timezone) {
        timezoneSelect.value = state.timezone;
      }
      refreshTimezoneTrigger();
      renderTimezonePicker();
      renderSlots(state.slots || []);
      updateSummary();
    };

    if (timeToggle) {
      timeToggle.classList.add('is-disabled');
      timeToggle.addEventListener('click', (e) => {
        const btn = e.target.closest('[data-lc-time-format]');
        if (!btn) return;
        if (timeToggle.classList.contains('is-disabled')) return;
        setTimeFormat(btn.dataset.lcTimeFormat);
      });
    }
    setTimeFormat(state.timeFormat);
    timezoneSelectOptions();
    initTimezonePicker();
    initModalSelectPickers();
    if (timezoneSelect) {
      timezoneSelect.addEventListener('change', function () {
        setTimezone(this.value || state.sourceTimezone);
      });
    }
    initLocationTooltips();

    const eventStatus = window.litecalEvent.event && window.litecalEvent.event.status ? window.litecalEvent.event.status : 'active';
    if (eventStatus !== 'active') {
      if (cardEl) {
        cardEl.classList.add('lc-event-disabled');
        cardEl.querySelectorAll('button,input,select,textarea').forEach((el) => {
          el.disabled = true;
        });
      }
      if (calendarEl) {
        const blockedMessage = isManageView ? defaultManageBlockedMessage : 'Servicio desactivado.';
        calendarEl.classList.add('is-blocked');
        calendarEl.innerHTML = '<div class="lc-event-status-note"><div>' + escHtml(blockedMessage) + '</div></div>';
      }
      if (slotsEl) {
        slotsEl.innerHTML = '';
        const slotsPanel = slotsEl.closest('.lc-panel');
        if (isManageView && slotsPanel) {
          slotsPanel.style.display = 'none';
        }
      }
      if (summaryEl) {
        summaryEl.textContent = '';
      }
      if (isManageView) {
        if (manageCancelBtn) {
          manageCancelBtn.style.display = 'none';
        }
        if (nextBtn) {
          nextBtn.style.display = 'none';
        }
      }
      return;
    }

    const findEmployee = (employeeId) => {
      const id = parseInt(employeeId, 10) || 0;
      if (!id) return null;
      const employees = Array.isArray(window.litecalEvent?.employees) ? window.litecalEvent.employees : [];
      return employees.find((item) => (parseInt(item?.id, 10) || 0) === id) || null;
    };

    const setHostEmployee = (employee) => {
      if (!hostWrap) return;
      if (!employee) {
        hostWrap.style.display = 'none';
        if (hostNameEl) hostNameEl.textContent = 'Profesional por definir';
        if (hostTitleEl) {
          hostTitleEl.textContent = '';
          hostTitleEl.style.display = 'none';
        }
        if (hostAvatarEl) hostAvatarEl.style.display = 'none';
        if (hostFallbackEl) {
          hostFallbackEl.textContent = '?';
          hostFallbackEl.style.display = 'inline-flex';
        }
        return;
      }
      hostWrap.style.display = '';
      const name = String(employee.name || '').trim();
      const title = String(employee.title || '').trim();
      const avatar = String(employee.avatar_url || '').trim();
      const parts = name.split(/\s+/).filter(Boolean);
      const initials = ((parts[0] || '').slice(0, 1) + (parts[1] || '').slice(0, 1)).toUpperCase() || '•';

      if (hostNameEl) hostNameEl.textContent = name;
      if (hostTitleEl) {
        if (title) {
          hostTitleEl.textContent = title;
          hostTitleEl.style.display = 'block';
        } else {
          hostTitleEl.textContent = '';
          hostTitleEl.style.display = 'none';
        }
      }
      if (hostAvatarEl) {
        if (avatar) {
          hostAvatarEl.setAttribute('src', avatar);
          hostAvatarEl.setAttribute('alt', name || 'Profesional');
          hostAvatarEl.style.display = '';
        } else {
          hostAvatarEl.style.display = 'none';
        }
      }
      if (hostFallbackEl) {
        hostFallbackEl.textContent = initials;
        hostFallbackEl.style.display = avatar ? 'none' : 'inline-flex';
      }
      if (changeEmployeeBtn && hasMultipleEmployees) {
        const allowChange = !isManageView || manageAction !== 'reschedule' || manageCanChangeStaff;
        changeEmployeeBtn.hidden = !allowChange;
      }
    };

    const syncEmployeeCards = () => {
      employeeCards.forEach((card) => {
        const cardId = parseInt(card.getAttribute('data-lc-employee-card') || '0', 10) || 0;
        card.classList.toggle('is-active', cardId > 0 && cardId === (parseInt(state.employeeId, 10) || 0));
      });
    };

    const resetBookingSelection = () => {
      state.date = null;
      state.slot = null;
      state.slots = [];
      if (summaryEl) {
        summaryEl.textContent = summaryDefaultText || 'Selecciona una fecha y horario.';
      }
      if (dateLabel) {
        dateLabel.textContent = dateLabelDefaultText || 'Selecciona un día';
      }
      if (slotsEl) {
        slotsEl.innerHTML = '<div class="lc-desc">Selecciona un día para ver horarios.</div>';
      }
      if (nextBtn) {
        nextBtn.disabled = true;
      }
      if (timeToggle) {
        timeToggle.classList.add('is-disabled');
      }
    };

    const animateStepTargets = (targets) => {
      if (window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches) {
        return;
      }
      const nodes = (Array.isArray(targets) ? targets : []).filter(Boolean);
      if (!nodes.length) {
        return;
      }
      window.clearTimeout(stageAnimationTimer);
      nodes.forEach((target) => {
        target.classList.remove('is-step-entering');
        void target.offsetWidth;
        target.classList.add('is-step-entering');
      });
      stageAnimationTimer = window.setTimeout(() => {
        nodes.forEach((target) => target.classList.remove('is-step-entering'));
      }, 520);
    };

    const openEmployeeStep = () => {
      if (!hasMultipleEmployees || !cardEl || !employeeStep) return;
      if (isManageView && manageAction === 'reschedule' && !manageCanChangeStaff) return;
      cardEl.classList.add('is-employee-pick');
      employeeStep.hidden = false;
      animateStepTargets([employeeStep]);
      if (changeEmployeeBtn) {
        changeEmployeeBtn.hidden = true;
      }
    };

    const closeEmployeeStep = () => {
      if (!hasMultipleEmployees || !cardEl || !employeeStep) return;
      cardEl.classList.remove('is-employee-pick');
      employeeStep.hidden = true;
    };

    const applyEmployeeSelection = (employeeId, options = {}) => {
      const keepDate = !!options.keepDate;
      const employee = findEmployee(employeeId);
      if (!employee) return;
      const previousId = parseInt(state.employeeId, 10) || 0;
      state.employeeId = parseInt(employee.id, 10) || 0;
      if (employeeSelect) {
        employeeSelect.value = String(state.employeeId);
      }
      setHostEmployee(employee);
      syncEmployeeCards();
      closeEmployeeStep();

      if (!keepDate || previousId !== state.employeeId) {
        resetBookingSelection();
      }
      renderCalendar(new Date());
      animateStepTargets([calendarEl, slotsEl]);
      if (keepDate && state.date) {
        loadSlots(state.date);
      }
    };

    if (employeeSelect) {
      const selectedEmployee = parseInt(employeeSelect.value, 10) || 0;
      if (selectedEmployee > 0) {
        state.employeeId = selectedEmployee;
      }
      employeeSelect.addEventListener('change', function () {
        applyEmployeeSelection(parseInt(this.value, 10) || 0);
      });
    }

    if (employeeCards.length) {
      employeeCards.forEach((card) => {
        card.addEventListener('click', () => {
          const employeeId = parseInt(card.getAttribute('data-lc-employee-card') || '0', 10) || 0;
          applyEmployeeSelection(employeeId);
        });
      });
    }

    if (changeEmployeeBtn) {
      changeEmployeeBtn.addEventListener('click', (event) => {
        event.preventDefault();
        if (isManageView && manageAction === 'reschedule' && !manageCanChangeStaff) {
          showToast('Esta reserva debe mantenerse con el mismo profesional.', 'error');
          return;
        }
        if (cardEl && cardEl.classList.contains('is-form')) {
          cardEl.classList.remove('is-form');
        }
        resetBookingSelection();
        openEmployeeStep();
      });
    }

    if (hasMultipleEmployees && !isReturnView && !isManageView) {
      setHostEmployee(null);
      openEmployeeStep();
    } else if ((parseInt(state.employeeId, 10) || 0) > 0) {
      setHostEmployee(findEmployee(state.employeeId));
    }

    function formatDate(date) {
      return `${date.getFullYear()}-${pad2(date.getMonth() + 1)}-${pad2(date.getDate())}`;
    }

    function pad2(n) {
      return String(n).padStart(2, '0');
    }

    function parseDateTimeParts(dateStr, timeStr) {
      const [year, month, day] = String(dateStr || '').split('-').map((v) => parseInt(v, 10));
      const [hour, minute] = String(timeStr || '').split(':').map((v) => parseInt(v, 10));
      return {
        year: Number.isFinite(year) ? year : 1970,
        month: Number.isFinite(month) ? month : 1,
        day: Number.isFinite(day) ? day : 1,
        hour: Number.isFinite(hour) ? hour : 0,
        minute: Number.isFinite(minute) ? minute : 0,
      };
    }

    function getTzOffsetMinutes(dateObj, timeZone) {
      const parts = new Intl.DateTimeFormat('en-US', {
        timeZone,
        year: 'numeric',
        month: '2-digit',
        day: '2-digit',
        hour: '2-digit',
        minute: '2-digit',
        second: '2-digit',
        hourCycle: 'h23',
      }).formatToParts(dateObj);
      const map = {};
      parts.forEach((part) => {
        map[part.type] = part.value;
      });
      const asUtc = Date.UTC(
        parseInt(map.year, 10),
        parseInt(map.month, 10) - 1,
        parseInt(map.day, 10),
        parseInt(map.hour, 10),
        parseInt(map.minute, 10),
        parseInt(map.second, 10)
      );
      return (asUtc - dateObj.getTime()) / 60000;
    }

    function zonedDateToUtc(dateStr, timeStr, timeZone) {
      const parts = parseDateTimeParts(dateStr, timeStr);
      let utcMs = Date.UTC(parts.year, parts.month - 1, parts.day, parts.hour, parts.minute, 0);
      for (let i = 0; i < 3; i += 1) {
        const offset = getTzOffsetMinutes(new Date(utcMs), timeZone);
        const next = Date.UTC(parts.year, parts.month - 1, parts.day, parts.hour, parts.minute, 0) - (offset * 60000);
        if (Math.abs(next - utcMs) < 1000) {
          utcMs = next;
          break;
        }
        utcMs = next;
      }
      return new Date(utcMs);
    }

    function formatDateInTimezone(dateObj, timeZone) {
      return new Intl.DateTimeFormat('es-ES', {
        timeZone,
        weekday: 'long',
        day: 'numeric',
        month: 'long',
        year: 'numeric',
      }).format(dateObj);
    }

    function formatTimeInTimezone(dateObj, timeZone) {
      if (state.timeFormat === '24h') {
        return new Intl.DateTimeFormat('en-GB', {
          timeZone,
          hour: '2-digit',
          minute: '2-digit',
          hourCycle: 'h23',
        }).format(dateObj);
      }
      return new Intl.DateTimeFormat('en-US', {
        timeZone,
        hour: 'numeric',
        minute: '2-digit',
        hour12: true,
      })
        .format(dateObj)
        .replace(/\s/g, '')
        .toLowerCase();
    }

    function formatTime(timeStr) {
      const [h, m] = timeStr.split(':');
      const hour = parseInt(h, 10);
      if (state.timeFormat === '24h') {
        return `${String(hour).padStart(2, '0')}:${m}`;
      }
      const suffix = hour >= 12 ? 'pm' : 'am';
      const hour12 = hour % 12 === 0 ? 12 : hour % 12;
      return `${hour12}:${m}${suffix}`;
    }

    function slotMoment(dateStr, timeStr) {
      return zonedDateToUtc(dateStr, timeStr, state.sourceTimezone || 'UTC');
    }

    function displaySlotTime(dateStr, timeStr) {
      const targetTz = state.timezone || state.sourceTimezone || 'UTC';
      if (targetTz === (state.sourceTimezone || 'UTC')) {
        return formatTime(timeStr);
      }
      const utcDate = slotMoment(dateStr, timeStr);
      return formatTimeInTimezone(utcDate, targetTz);
    }

    function currencyMeta(code) {
      const map = {
        CLP: { symbol: '$', decimal: ',', thousand: '.', decimals: 0 },
        USD: { symbol: '$', decimal: '.', thousand: ',', decimals: 2 },
        EUR: { symbol: '€', decimal: ',', thousand: '.', decimals: 2 },
      };
      return map[code] || map.CLP;
    }

    function formatMoney(value, code) {
      const meta = currencyMeta(code);
      const fixed = Number(value || 0).toFixed(meta.decimals);
      const parts = fixed.split('.');
      const intPart = parts[0].replace(/\B(?=(\d{3})+(?!\d))/g, meta.thousand);
      if (meta.decimals === 0) return `${meta.symbol}${intPart}`;
      return `${meta.symbol}${intPart}${meta.decimal}${parts[1]}`;
    }

    function roundMoney(value) {
      return Math.round((Number(value || 0) + Number.EPSILON) * 100) / 100;
    }

    function extrasHoursUnitsMax() {
      if (!extrasHoursWrap) return 0;
      const n = parseInt(String(extrasHoursWrap.getAttribute('data-lc-extra-hours-max') || '0'), 10);
      if (!Number.isFinite(n) || n < 0) return 0;
      return n;
    }

    function getExtrasHoursUnits() {
      if (!extrasHoursUnitsInputs.length) return 0;
      const max = extrasHoursUnitsMax();
      const hasRadioMode = extrasHoursUnitsInputs.some(
        (input) => input && String(input.type || '').toLowerCase() === 'radio'
      );
      let raw = 0;
      if (hasRadioMode) {
        const checked = extrasHoursUnitsInputs.find((input) => input && input.checked);
        raw = parseInt(String(checked?.value || '0'), 10);
      } else {
        raw = parseInt(String(extrasHoursUnitsInput?.value || '0'), 10);
      }
      if (!Number.isFinite(raw) || raw <= 0) return 0;
      if (max > 0) return Math.min(max, raw);
      return raw;
    }

    function setExtrasHoursUnits(next, options = {}) {
      if (!extrasHoursUnitsInputs.length) return;
      const shouldNotify = !!options.notify;
      const max = extrasHoursUnitsMax();
      let value = parseInt(String(next || '0'), 10);
      if (!Number.isFinite(value) || value < 0) value = 0;
      if (max > 0) value = Math.min(max, value);
      const hasRadioMode = extrasHoursUnitsInputs.some(
        (input) => input && String(input.type || '').toLowerCase() === 'radio'
      );
      if (hasRadioMode) {
        let matched = false;
        extrasHoursUnitsInputs.forEach((input) => {
          if (!input) return;
          const isMatch = String(input.value || '') === String(value);
          input.checked = isMatch;
          if (isMatch) matched = true;
        });
        if (!matched && extrasHoursUnitsInputs[0]) {
          extrasHoursUnitsInputs[0].checked = true;
        }
        return;
      }
      if (extrasHoursUnitsInput) {
        const nextValue = String(value);
        const changed = String(extrasHoursUnitsInput.value || '') !== nextValue;
        extrasHoursUnitsInput.value = nextValue;
        if (shouldNotify && changed) {
          extrasHoursUnitsInput.dispatchEvent(new Event('change', { bubbles: true }));
        }
      }
    }

    function collectSelectedExtras() {
      const selectedItems = [];
      let itemsTotal = 0;
      extrasItemInputs.forEach((input) => {
        if (!input || !input.checked) return;
        const id = String(input.value || '').trim();
        const name = String(input.getAttribute('data-lc-extra-name') || '').trim();
        const price = Math.max(0, Number(input.getAttribute('data-lc-extra-price') || 0));
        if (!id || !name) return;
        selectedItems.push({
          id,
          name,
          price: roundMoney(price),
        });
        itemsTotal += price;
      });

      const hoursUnits = getExtrasHoursUnits();
      const hoursPrice = extrasHoursWrap
        ? Math.max(0, Number(extrasHoursWrap.getAttribute('data-lc-extra-hours-price') || 0))
        : 0;
      const hoursTotal = roundMoney(hoursUnits * hoursPrice);

      return {
        items: selectedItems,
        itemsTotal: roundMoney(itemsTotal),
        hoursUnits,
        hoursPrice: roundMoney(hoursPrice),
        hoursTotal,
        total: roundMoney(itemsTotal + hoursTotal),
      };
    }

    function calculateCheckoutAmounts() {
      const extras = collectSelectedExtras();
      const basePrice = Math.max(0, Number(paymentConfig.base_price ?? paymentConfig.price ?? 0));
      const serviceTotal = roundMoney(basePrice + extras.total);
      const partialPercent = Math.max(0, Number(paymentConfig.partial_percent || 0));
      const partialFixed = Math.max(0, Number(paymentConfig.partial_fixed_amount || 0));
      let chargeNow = serviceTotal;

      if (paymentMode === 'free') {
        chargeNow = 0;
      } else if (paymentMode === 'onsite') {
        chargeNow = serviceTotal;
      } else if (paymentMode === 'partial_percent') {
        chargeNow = roundMoney(serviceTotal * (partialPercent / 100));
      } else if (paymentMode === 'partial_fixed') {
        chargeNow = roundMoney(Math.min(serviceTotal, partialFixed + extras.total));
      }

      return {
        basePrice: roundMoney(basePrice),
        serviceTotal,
        chargeNow: roundMoney(chargeNow),
        extras,
      };
    }

    function refreshExtrasSummary() {
      if (!extrasWrap) return;
      const totals = calculateCheckoutAmounts();
      const extrasAmountLabel = `+${formatMoney(totals.extras.total, paymentCurrency)}`;
      const hoursAmountLabel = `+${formatMoney(totals.extras.hoursTotal, paymentCurrency)}`;

      if (extrasHoursNoteEl) {
        extrasHoursNoteEl.textContent = `Costo adicional: ${hoursAmountLabel}`;
      }

      if (extrasTotalEl) {
        if (totals.extras.total > 0) {
          extrasTotalEl.hidden = false;
          extrasTotalEl.textContent = `Total extras: ${extrasAmountLabel}`;
        } else {
          extrasTotalEl.hidden = true;
          extrasTotalEl.textContent = '';
        }
      }
    }

    function baseChargeNowWithoutExtras() {
      const basePrice = Math.max(0, Number(paymentConfig.base_price ?? paymentConfig.price ?? 0));
      const partialPercent = Math.max(0, Number(paymentConfig.partial_percent || 0));
      const partialFixed = Math.max(0, Number(paymentConfig.partial_fixed_amount || 0));
      if (paymentMode === 'free') return 0;
      if (paymentMode === 'partial_percent') return roundMoney(basePrice * (partialPercent / 100));
      if (paymentMode === 'partial_fixed') return roundMoney(Math.min(basePrice, partialFixed));
      return roundMoney(basePrice);
    }

    function refreshPaymentConvertBadges() {
      if (!paymentOptionEls.length) return;
      const totals = calculateCheckoutAmounts();
      const baseChargeNow = baseChargeNowWithoutExtras();
      paymentOptionEls.forEach((optionEl) => {
        if (!optionEl) return;
        const badgeEl = optionEl.querySelector('[data-lc-payment-convert-badge]');
        if (!badgeEl) return;
        const keyFromAttr = String(optionEl.getAttribute('data-lc-payment-key') || '').toLowerCase();
        const inputEl = optionEl.querySelector('input[name="payment_provider"]');
        const providerKey = keyFromAttr || String(inputEl?.value || '').toLowerCase();
        const method = paymentMethodMap[providerKey];
        if (!method) return;
        const methodCurrency = String(method.charge_currency || '').toUpperCase();
        const baseMethodAmount = Math.max(0, Number(method.charge_amount || 0));
        if (!methodCurrency || methodCurrency === paymentCurrency || baseChargeNow <= 0 || baseMethodAmount <= 0) {
          return;
        }
        const convertedAmount = roundMoney((totals.chargeNow * baseMethodAmount) / baseChargeNow);
        badgeEl.textContent = `Se cobrará: ${formatMoney(convertedAmount, methodCurrency)}`;
      });
    }

    function refreshPricingUi() {
      refreshExtrasSummary();
      refreshPaymentConvertBadges();
      updateSubmitLabel();
      if (state.slot && state.date) {
        updateSummary();
      }
    }

    function updateSubmitLabel() {
      if (!submitBtn) return;
      const totals = calculateCheckoutAmounts();
      const chargeLabel = formatMoney(totals.chargeNow, paymentCurrency);
      if (['total', 'partial_percent', 'partial_fixed'].includes(paymentMode) && totals.chargeNow > 0) {
        submitBtn.textContent = `Pagar reserva • ${chargeLabel}`;
      } else if (paymentMode === 'onsite' && totals.serviceTotal > 0) {
        submitBtn.textContent = `Confirmar • Pago presencial ${formatMoney(totals.serviceTotal, paymentCurrency)}`;
      } else {
        submitBtn.textContent = 'Confirmar';
      }
    }

    function renderCalendar(dateObj) {
      calendarEl.innerHTML = '';
      const year = dateObj.getFullYear();
      const month = dateObj.getMonth();

      const header = document.createElement('div');
      header.className = 'lc-cal-header';
      header.innerHTML = `
        <button class="lc-btn" data-lc-prev type="button" aria-label="Mes anterior"><i class="ri-arrow-left-line"></i></button>
        <span>${dateObj.toLocaleString('es-ES', { month: 'long', year: 'numeric' })}</span>
        <button class="lc-btn" data-lc-next type="button" aria-label="Mes siguiente"><i class="ri-arrow-right-line"></i></button>
      `;
      calendarEl.appendChild(header);

      const grid = document.createElement('div');
      grid.className = 'lc-cal-grid';
      const weekdays = ['DOM', 'LUN', 'MAR', 'MIÉ', 'JUE', 'VIE', 'SÁB'];
      weekdays.forEach((day) => {
        const label = document.createElement('div');
        label.className = 'lc-cal-day';
        label.textContent = day;
        grid.appendChild(label);
      });

      const firstDay = new Date(year, month, 1);
      let start = new Date(firstDay);
      const weekday = firstDay.getDay();
      start.setDate(firstDay.getDate() - weekday);

      const timeOff = window.litecalEvent.time_off || [];
      const inTimeOff = (dateStr) =>
        timeOff.some((range) => {
          if (!range || typeof range !== 'object') return false;
          const employeeId = Number(range.employee_id || 0);
          if (employeeId > 0 && Number(state.employeeId || 0) > 0 && employeeId !== Number(state.employeeId || 0)) {
            return false;
          }
          if (employeeId > 0 && Number(state.employeeId || 0) <= 0 && window.litecalEvent.employees.length > 1) {
            return false;
          }
          return dateStr >= range.start && dateStr <= range.end;
        });
      const limits = window.litecalEvent.limits || {};
      const futureDays = Number(limits.future_days || 0);
      const maxDate = futureDays
        ? new Date(new Date().setHours(0, 0, 0, 0) + futureDays * 24 * 60 * 60 * 1000)
        : null;

      for (let i = 0; i < 42; i++) {
        const day = new Date(start);
        day.setDate(start.getDate() + i);
        const cell = document.createElement('div');
        cell.className = 'lc-date';
        cell.textContent = day.getDate();
        cell.dataset.date = formatDate(day);
        if (day.getMonth() !== month) {
          cell.classList.add('is-disabled');
        }
      if (state.date === formatDate(day)) {
        cell.classList.add('is-active');
      }
      const today = new Date();
      today.setHours(0, 0, 0, 0);
      const dayOfWeek = day.getDay() === 0 ? 7 : day.getDay();
      const allowedDays = window.litecalEvent.schedule_days || [];
      if (day < today || (allowedDays.length && !allowedDays.includes(dayOfWeek))) {
        cell.classList.add('is-disabled');
      }
      if (maxDate && day > maxDate) {
        cell.classList.add('is-disabled');
      }
      if (inTimeOff(cell.dataset.date)) {
        cell.classList.add('is-disabled');
      }
      cell.addEventListener('click', function () {
        if (cell.classList.contains('is-disabled')) return;
        state.date = cell.dataset.date;
        if (timeToggle) {
          timeToggle.classList.remove('is-disabled');
        }
        renderCalendar(new Date(state.date));
        loadSlots(state.date);
      });
        grid.appendChild(cell);
      }

      calendarEl.appendChild(grid);
      header.querySelector('[data-lc-prev]').addEventListener('click', function () {
        renderCalendar(new Date(year, month - 1, 1));
      });
      header.querySelector('[data-lc-next]').addEventListener('click', function () {
        renderCalendar(new Date(year, month + 1, 1));
      });
    }

    function renderSlots(slots) {
      state.slots = Array.isArray(slots) ? slots : [];
      slotsEl.innerHTML = '';
      if (!state.slots.length) {
        slotsEl.innerHTML = '<div class="lc-desc">No hay horarios disponibles.</div>';
        return;
      }
      state.slots.forEach((slot) => {
        const item = document.createElement('div');
        item.className = 'lc-slot';
        if (slot.status !== 'available') {
          item.classList.add('is-unavailable');
        }
        if (slot.status === 'cancelled') {
          item.classList.add('is-cancelled');
        }
        const slotDate = slot.date || state.date;
        if (state.slot && state.slot.start === slot.start && (state.slot.date || state.date) === slotDate) {
          item.classList.add('is-selected');
        }
        item.innerHTML = `
          <span class="dot"></span>
          <span>${escHtml(displaySlotTime(slotDate, slot.start))}</span>
        `;
        item.addEventListener('click', function () {
          if (slot.status !== 'available') return;
          state.slot = { ...slot, date: slotDate };
          updateSummary();
          renderSlots(state.slots);
        });
        slotsEl.appendChild(item);
      });
    }

    function applyNoticeRule(slots, date) {
      const noticeHours = Number(window.litecalEvent?.limits?.notice_hours || 0);
      if (!Array.isArray(slots) || noticeHours <= 0) {
        return Array.isArray(slots) ? slots : [];
      }
      const minNoticeMs = Date.now() + (noticeHours * 60 * 60 * 1000);
      return slots.map((slot) => {
        if (!slot || typeof slot !== 'object' || slot.status !== 'available') {
          return slot;
        }
        const slotDate = slot.date || date || state.date;
        if (!slotDate || !slot.start) {
          return slot;
        }
        const slotStartMs = slotMoment(slotDate, slot.start).getTime();
        if (Number.isFinite(slotStartMs) && slotStartMs < minNoticeMs) {
          return { ...slot, status: 'unavailable' };
        }
        return slot;
      });
    }

    function updateSummary() {
      if (!state.slot || !state.date) return;
      const slotDate = state.slot.date || state.date;
      const startUtc = slotMoment(slotDate, state.slot.start);
      const endUtc = slotMoment(slotDate, state.slot.end);
      const targetTz = state.timezone || state.sourceTimezone || 'UTC';
      const dateText = formatDateInTimezone(startUtc, targetTz);
      const timeText = `${formatTimeInTimezone(startUtc, targetTz)} - ${formatTimeInTimezone(endUtc, targetTz)}`;
      const totals = calculateCheckoutAmounts();
      const extrasLine = totals.extras.total > 0
        ? `<br/><small>Extras: +${escHtml(formatMoney(totals.extras.total, paymentCurrency))}</small>`
        : '';
      summaryEl.innerHTML = `<strong>${escHtml(dateText)}</strong><br/>${escHtml(timeText)}${extrasLine}`;
      if (dateLabel) {
        dateLabel.textContent = dateText;
      }
      if (nextBtn) {
        if (isManageView && manageAction === 'reschedule' && !state.manageActionAllowed) {
          nextBtn.disabled = true;
        } else {
          nextBtn.disabled = false;
        }
      }
    }

    function loadSlots(date) {
      state.slot = null;
      state.slots = [];
      if (nextBtn) {
        nextBtn.disabled = true;
      }
      if (window.litecalEvent.employees.length > 1 && !state.employeeId) {
        slotsEl.innerHTML = '<div class="lc-desc">Selecciona un empleado para ver horarios.</div>';
        return;
      }
      slotsEl.innerHTML = '<div class="lc-desc">Cargando horarios...</div>';
      if (slotsAbortController) {
        try {
          slotsAbortController.abort();
        } catch (_) {
          // noop
        }
      }
      slotsAbortController = typeof AbortController !== 'undefined' ? new AbortController() : null;
      const manageBookingQuery = isManageView && manageAction === 'reschedule' && manageBookingId
        ? `&booking_id=${manageBookingId}`
        : '';
      const url = `${window.litecal.restUrl}/availability?event_id=${window.litecalEvent.event.id}&date=${date}&employee_id=${state.employeeId}${manageBookingQuery}&_=${Date.now()}`;
      fetch(url, slotsAbortController ? { signal: slotsAbortController.signal } : undefined)
        .then((res) => res.json())
        .then((data) => {
          const nextSlots = applyNoticeRule(data.slots || [], date);
          renderSlots(nextSlots);
          animateStepTargets([slotsEl]);
        })
        .catch((error) => {
          if (error && error.name === 'AbortError') {
            return;
          }
          slotsEl.innerHTML = '<div class="lc-desc">No pudimos cargar los horarios. Intenta nuevamente.</div>';
        });
    }

    if (nextBtn) {
      nextBtn.addEventListener('click', function () {
        if (!state.slot || !state.date) return;
        if (isManageView && manageAction === 'reschedule') {
          if (!state.manageActionAllowed) {
            showToast('Este enlace ya no permite reagendar esta reserva.', 'error');
            nextBtn.disabled = true;
            return;
          }
          const slotDate = state.slot.date || state.date;
          const start = `${slotDate} ${state.slot.start}:00`;
          const end = `${slotDate} ${state.slot.end}:00`;
          const reason = manageReasonEl ? String(manageReasonEl.value || '') : '';
          setButtonLoading(nextBtn, true);
          runManageAction('reschedule', {
            start,
            end,
            employee_id: Number(state.employeeId || 0),
            reason,
          })
            .then(() => fetchBookingDetails(manageBookingId, state.manageToken, window.litecalEvent?.event?.id || 0))
            .then((bookingData) => {
              if (!bookingData) return;
              showToast('Reserva reagendada correctamente.', 'success');
              applyReturnViewLayout();
              applyManageStatusCopy(bookingData, 'reschedule');
            })
            .catch((err) => {
              const blockedMessage = normalizeManageBlockedMessage(err?.message || 'No se pudo reagendar la reserva.', 'reschedule');
              showToast(blockedMessage, 'error');
              const apiCode = String(err?.code || '');
              const apiStatus = Number(err?.httpStatus || 0);
              if (apiCode === 'invalid_token' || apiCode === 'reschedule_blocked' || (err?.reasonCode || '') !== '' || apiStatus === 403 || apiStatus === 409) {
                renderManageBlockedState(blockedMessage);
              }
            })
            .finally(() => {
              loadManageState().finally(() => {
                setButtonLoading(nextBtn, false);
                if (!state.manageActionAllowed) {
                  nextBtn.disabled = true;
                }
              });
            });
          return;
        }
        if (cardEl) {
          cardEl.classList.add('is-form');
        }
        animateStepTargets([formEl]);
      });
    }

    if (backBtn) {
      backBtn.addEventListener('click', function () {
        if (cardEl) {
          cardEl.classList.remove('is-form');
        }
        animateStepTargets([calendarEl, slotsEl]);
      });
    }

    const notyf = window.Notyf
      ? new window.Notyf({
          duration: 3200,
          position: { x: 'right', y: 'top' },
          dismissible: true,
        })
      : null;

    const showToast = (message, type = 'info') => {
      if (notyf) {
        if (type === 'error') {
          notyf.error(message);
        } else {
          notyf.success(message);
        }
        return;
      }
      // Fallback visible if Notyf is unavailable.
      window.alert(message);
    };

    const formatManageDateTime = (booking) => {
      const startRaw = String(booking?.start || '');
      const endRaw = String(booking?.end || '');
      if (!startRaw) return '';
      const startDate = startRaw.slice(0, 10);
      const startTime = startRaw.slice(11, 16);
      const endTime = endRaw ? endRaw.slice(11, 16) : '';
      if (!startDate || !startTime) return startRaw;
      const startObj = slotMoment(startDate, startTime);
      const endObj = endTime ? slotMoment(startDate, endTime) : null;
      const dateText = formatDateInTimezone(startObj, state.timezone || state.sourceTimezone || 'UTC');
      const timeText = endObj
        ? `${formatTimeInTimezone(startObj, state.timezone || state.sourceTimezone || 'UTC')} - ${formatTimeInTimezone(endObj, state.timezone || state.sourceTimezone || 'UTC')}`
        : formatTimeInTimezone(startObj, state.timezone || state.sourceTimezone || 'UTC');
      return `${dateText} · ${timeText}`;
    };

    const renderManagePolicy = (manage) => {
      if (!managePolicyEl || !manage || !manage.policy) return;
      const policy = manage.policy || {};
      const lines = [];
      if (manageAction === 'reschedule') {
        lines.push(`Puedes reagendar hasta ${Number(policy.reschedule_cutoff_hours || 0)} hora(s) antes.`);
        lines.push(`Máximo ${Number(policy.max_reschedules || 0)} cambio(s).`);
      } else if (manageAction === 'cancel') {
        lines.push(`Puedes cancelar hasta ${Number(policy.cancel_cutoff_hours || 0)} hora(s) antes.`);
        lines.push(policy.cancel_policy_type === 'paid' ? 'Reserva de pago: aplica política de pago definida.' : 'Reserva gratis: cancelación permitida según política.');
      }
      const evalState = manageAction === 'cancel' ? manage.cancel : manage.reschedule;
      if (evalState && Number(evalState.allowed || 0) !== 1 && evalState.reason) {
        lines.push(`Bloqueado: ${String(evalState.reason)}`);
      }
      managePolicyEl.innerHTML = lines.map((line) => `<div>${escHtml(line)}</div>`).join('');
    };

    const normalizeManageBlockedMessage = (rawMessage, action = manageAction) => {
      const text = String(rawMessage || '').trim();
      const fallback = action === 'cancel'
        ? 'Esta reserva ya fue cancelada y no permite más cambios.'
        : 'Ya no puedes reagendar esta reserva con este enlace.';
      if (!text) {
        return fallback;
      }
      const normalized = text.normalize('NFD').replace(/[\u0300-\u036f]/g, '').toLowerCase();
      if (action === 'cancel' && (normalized.includes('ya fue cancelada') || normalized.includes('ya no permite cambios') || normalized.includes('cancelad'))) {
        return 'Esta reserva ya fue cancelada y no permite más cambios.';
      }
      if (normalized.includes('token invalido') || normalized.includes('token') || normalized.includes('acceso denegado')) {
        return fallback;
      }
      return text;
    };

    const loadManageState = () => {
      if (!isManageView || !manageBookingId || !state.manageToken) return Promise.resolve(null);
      const eventId = Number(window.litecalEvent?.event?.id || 0);
      const url = `${window.litecal.restUrl}/booking/${manageBookingId}?event_id=${eventId}&booking_token=${encodeURIComponent(state.manageToken)}&_=${Date.now()}`;
      return fetch(url)
        .then((res) => res.json())
        .then((data) => {
          if (!data || data.status === 'error' || !data.id) {
            const err = new Error(data?.message || 'No pudimos cargar la reserva.');
            err.code = String(data?.code || '');
            err.reasonCode = String(data?.data?.reason_code || data?.error?.reason_code || '');
            err.httpStatus = Number(data?.data?.status || data?.error?.status || 0);
            throw err;
          }
          manageState = data;
          if (manageCurrentEl) {
            manageCurrentEl.textContent = `Tu reserva actual es: ${formatManageDateTime(data)}`;
          }
          renderManagePolicy(data.manage || {});
          const employeeId = Number(data?.employee?.id || 0);
          if (employeeId > 0) {
            state.employeeId = employeeId;
            if (employeeSelect) employeeSelect.value = String(employeeId);
            setHostEmployee(findEmployee(employeeId));
            syncEmployeeCards();
          }
          return data;
        })
        .catch((err) => {
          const blockedMessage = normalizeManageBlockedMessage(err?.message || 'No pudimos cargar la gestión de reserva.', manageAction);
          showToast(blockedMessage, 'error');
          const apiCode = String(err?.code || '');
          const apiStatus = Number(err?.httpStatus || 0);
          if (apiCode === 'invalid_token' || (err?.reasonCode || '') !== '' || apiStatus === 403 || apiStatus === 409) {
            renderManageBlockedState(blockedMessage);
          }
          return null;
        });
    };

    const runManageAction = (action, payload = {}) => {
      if (!manageBookingId || !state.manageToken) return Promise.reject(new Error('Token de gestión inválido.'));
      return fetch(`${window.litecal.restUrl}/booking/${manageBookingId}/${action}`, {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          'X-WP-Nonce': window.litecal.nonce,
        },
        body: JSON.stringify({
          booking_token: state.manageToken,
          ...payload,
        }),
      })
        .then((res) => res.json())
        .then((data) => {
          if (!data || data.status === 'error') {
            const err = new Error(data?.message || 'No se pudo completar la acción.');
            err.code = String(data?.code || '');
            err.reasonCode = String(data?.data?.reason_code || data?.error?.reason_code || '');
            err.httpStatus = Number(data?.data?.status || data?.error?.status || 0);
            throw err;
          }
          const nextToken = data.booking_token
            || data?.manage?.urls?.booking_token
            || '';
          if (nextToken) {
            state.manageToken = String(nextToken);
          }
          return data;
        });
    };

    const renderManageBlockedState = (message) => {
      if (!isManageView) return;
      state.manageActionAllowed = false;
      if (nextBtn) {
        nextBtn.disabled = true;
        nextBtn.style.display = 'none';
      }
      if (manageCancelBtn) {
        manageCancelBtn.disabled = true;
        manageCancelBtn.style.display = 'none';
      }
      const blockedMessage = normalizeManageBlockedMessage(message, manageAction);
      if (slotsEl) slotsEl.innerHTML = '';
      if (calendarEl) {
        calendarEl.classList.add('is-blocked');
        calendarEl.innerHTML = `<div class="lc-event-status-note"><div>${escHtml(blockedMessage)}</div></div>`;
      }
      const slotsPanel = slotsEl ? slotsEl.closest('.lc-panel') : null;
      if (slotsPanel) {
        slotsPanel.style.display = 'none';
      }
      if (selectStageEl) {
        selectStageEl.style.display = 'block';
      }
    };

    const isRestErrorPayload = (payload) => {
      if (!payload || typeof payload !== 'object') return true;
      const statusRaw = String(payload.status || '').toLowerCase();
      if (statusRaw === 'error') return true;
      const dataStatus = Number((payload.data && payload.data.status) || (payload.error && payload.error.status) || 0);
      return Number.isFinite(dataStatus) && dataStatus >= 400;
    };

    const restErrorCode = (payload) =>
      String(payload?.code || payload?.error?.code || payload?.data?.error?.code || '').toLowerCase();

    const emailPattern = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
    const transferWarningEl = query('[data-lc-transfer-warning]');
    let abuseCodeWrap = null;
    let abuseCodeInput = null;
    let abuseCodeHelp = null;

    const ensureAbuseCodeField = () => {
      if (!formEl) return;
      if (abuseCodeWrap && abuseCodeInput && abuseCodeHelp) return;
      const actionsEl = formEl.querySelector('.lc-form-actions');
      if (!actionsEl) return;
      abuseCodeWrap = document.createElement('div');
      abuseCodeWrap.className = 'lc-abuse-code-wrap';
      abuseCodeWrap.setAttribute('data-lc-abuse-code-wrap', '');
      abuseCodeWrap.hidden = true;

      const label = document.createElement('label');
      label.setAttribute('for', 'lc-abuse-code-input');
      label.textContent = 'Código de verificación';

      abuseCodeInput = document.createElement('input');
      abuseCodeInput.type = 'text';
      abuseCodeInput.name = 'abuse_code';
      abuseCodeInput.id = 'lc-abuse-code-input';
      abuseCodeInput.placeholder = 'Ingresa el código enviado por email';
      abuseCodeInput.autocomplete = 'one-time-code';
      abuseCodeInput.setAttribute('inputmode', 'numeric');
      abuseCodeInput.setAttribute('data-lc-abuse-code-input', '');

      abuseCodeHelp = document.createElement('small');
      abuseCodeHelp.className = 'lc-help';
      abuseCodeHelp.setAttribute('data-lc-abuse-code-help', '');
      abuseCodeHelp.textContent = '';

      abuseCodeWrap.appendChild(label);
      abuseCodeWrap.appendChild(abuseCodeInput);
      abuseCodeWrap.appendChild(abuseCodeHelp);
      formEl.insertBefore(abuseCodeWrap, actionsEl);
    };

    const showAbuseCodeField = (message = '') => {
      ensureAbuseCodeField();
      if (!abuseCodeWrap || !abuseCodeInput || !abuseCodeHelp) return;
      abuseCodeWrap.hidden = false;
      abuseCodeInput.required = true;
      abuseCodeHelp.textContent = String(message || 'Ingresa el código enviado a tu correo para continuar.');
      if (typeof abuseCodeInput.focus === 'function') {
        abuseCodeInput.focus();
      }
    };

    const hideAbuseCodeField = (clearValue = false) => {
      if (!abuseCodeWrap || !abuseCodeInput || !abuseCodeHelp) return;
      abuseCodeWrap.hidden = true;
      abuseCodeInput.required = false;
      abuseCodeHelp.textContent = '';
      if (clearValue) {
        abuseCodeInput.value = '';
      }
    };

    const updateTransferWarning = () => {
      if (!transferWarningEl || !formEl) return;
      const selected = formEl.querySelector('input[name="payment_provider"]:checked');
      const isTransfer = selected && selected.value === 'transfer';
      transferWarningEl.hidden = !isTransfer;
    };
    if (formEl) {
      formEl.querySelectorAll('input[name="payment_provider"]').forEach((radio) => {
        radio.addEventListener('change', updateTransferWarning);
      });
      updateTransferWarning();
    }

    if (extrasHoursUnitsInputs.length) {
      setExtrasHoursUnits(getExtrasHoursUnits(), { notify: false });
      extrasHoursUnitsInputs.forEach((input) => {
        input.addEventListener('input', () => {
          setExtrasHoursUnits(getExtrasHoursUnits(), { notify: false });
          refreshPricingUi();
        });
        input.addEventListener('change', () => {
          setExtrasHoursUnits(getExtrasHoursUnits(), { notify: false });
          refreshPricingUi();
        });
      });
    }

    if (extrasHoursDecBtn && extrasHoursUnitsInput) {
      extrasHoursDecBtn.addEventListener('click', () => {
        setExtrasHoursUnits(getExtrasHoursUnits() - 1, { notify: true });
        refreshPricingUi();
      });
    }

    if (extrasHoursIncBtn && extrasHoursUnitsInput) {
      extrasHoursIncBtn.addEventListener('click', () => {
        setExtrasHoursUnits(getExtrasHoursUnits() + 1, { notify: true });
        refreshPricingUi();
      });
    }

    if (extrasItemInputs.length) {
      extrasItemInputs.forEach((input) => {
        input.addEventListener('change', refreshPricingUi);
      });
    }

    refreshPricingUi();

    const showFirstValidationError = (form) => {
      const invalid = form.querySelector(':invalid');
      if (!invalid) {
        showToast('Completa los campos requeridos.', 'error');
        return;
      }
      let message = 'Completa los campos requeridos.';
      if ((invalid.type || '').toLowerCase() === 'email') {
        message = 'El correo no es válido.';
      } else if (invalid.validationMessage) {
        message = invalid.validationMessage;
      }
      showToast(message, 'error');
      if (typeof invalid.focus === 'function') {
        invalid.focus();
      }
    };

    const filesFromInput = (input) => {
      if (!input) return [];
      if (input._lcPond && typeof input._lcPond.getFiles === 'function') {
        return input._lcPond.getFiles().map((item) => item.file).filter(Boolean);
      }
      return Array.from(input.files || []);
    };

    if (formEl) {
      formEl.addEventListener('submit', function (e) {
      e.preventDefault();
      if (isManageView) {
        return;
      }
      if (!state.slot || !state.date) return;
      if (submitBtn && submitBtn.dataset.lcSubmitting === '1') return;

      if (!formEl.checkValidity()) {
        showFirstValidationError(formEl);
        return;
      }

      const formData = new FormData(formEl);
      const mainEmail = String(formData.get('email') || '').trim();
      if (!emailPattern.test(mainEmail)) {
        showToast('El correo no es válido.', 'error');
        const emailInput = formEl.querySelector('input[name="email"]');
        if (emailInput && typeof emailInput.focus === 'function') {
          emailInput.focus();
        }
        return;
      }
      const payment = window.litecalEvent.payment || {};
      const checkoutTotals = calculateCheckoutAmounts();
      if (payment.mode && !['free', 'onsite'].includes(String(payment.mode).toLowerCase()) && checkoutTotals.chargeNow > 0) {
        const provider = formData.get('payment_provider');
        if (!provider) {
          showToast('Selecciona un medio de pago para continuar.', 'error');
          return;
        }
      }
      let fileError = '';
      formEl.querySelectorAll('[data-lc-custom-file]').forEach((input) => {
        if (fileError) return;
        const required = input.dataset.lcFileRequired === '1';
        const maxFiles = parseInt(input.dataset.lcFileMaxFiles || '1', 10);
        const maxBytes = parseInt(input.dataset.lcFileMaxBytes || '0', 10);
        const allowedExts = (input.dataset.lcFileExts || '').split(',').map((v) => v.trim().toLowerCase()).filter(Boolean);
        const allowedMimes = (input.dataset.lcFileMimes || '').split(',').map((v) => v.trim().toLowerCase()).filter(Boolean);
        const files = filesFromInput(input);
        if (required && files.length === 0) {
          fileError = 'Debes adjuntar un archivo.';
          return;
        }
        if (files.length > maxFiles) {
          fileError = `Máximo ${maxFiles} archivo(s).`;
          return;
        }
        files.forEach((file) => {
          if (fileError) return;
          if (maxBytes > 0 && file.size > maxBytes) {
            fileError = 'Archivo demasiado grande. Verifica el tamaño máximo permitido.';
            return;
          }
          const ext = (file.name.split('.').pop() || '').toLowerCase();
          const mime = (file.type || '').toLowerCase();
          if ((allowedExts.length || allowedMimes.length) && !allowedExts.includes(ext) && !allowedMimes.includes(mime)) {
            fileError = 'Archivo no permitido. Verifica los tipos admitidos.';
          }
        });
      });
      if (fileError) {
        showToast(fileError, 'error');
        return;
      }
      const customFields = {};
      formEl.querySelectorAll('[data-lc-custom-key]').forEach((field) => {
        const key = field.dataset.lcCustomKey;
        if (!key) return;
        if (field.type === 'checkbox') {
          if (!customFields[key]) customFields[key] = [];
          if (field.checked) {
            customFields[key].push(field.value || 'true');
          }
          return;
        }
        if (field.type === 'radio') {
          if (field.checked) customFields[key] = field.value;
          return;
        }
        if (field.tagName === 'SELECT' && field.multiple) {
          customFields[key] = Array.from(field.selectedOptions).map((opt) => opt.value);
          return;
        }
        if (field.type === 'tel' && field._lcIti && typeof field._lcIti.getNumber === 'function') {
          const intlPhone = String(field._lcIti.getNumber() || '').trim();
          customFields[key] = intlPhone || field.value;
          return;
        }
        customFields[key] = field.value;
      });
      const firstName = formData.get('first_name') || '';
      const lastName = formData.get('last_name') || '';
      const fullName = `${firstName} ${lastName}`.trim();
      const slotDate = state.slot.date || state.date;
      formData.set('event_id', window.litecalEvent.event.id);
      formData.set('employee_id', state.employeeId || '');
      formData.set('start', `${slotDate} ${state.slot.start}:00`);
      formData.set('end', `${slotDate} ${state.slot.end}:00`);
      formData.set('name', fullName);
      formData.set('first_name', firstName);
      formData.set('last_name', lastName);
      formData.set('email', formData.get('email') || '');
      const mainPhoneInput = formEl.querySelector('input[name="phone"]');
      const intlMainPhone =
        mainPhoneInput && mainPhoneInput._lcIti && typeof mainPhoneInput._lcIti.getNumber === 'function'
          ? String(mainPhoneInput._lcIti.getNumber() || '').trim()
          : '';
      formData.set('phone', intlMainPhone || formData.get('phone') || customFields.phone || '');
      formData.set('company', formData.get('company') || customFields.company || '');
      formData.set('message', formData.get('message') || customFields.message || '');
      const guestInputs = Array.from(formEl.querySelectorAll('[data-lc-guest-input]'));
      const guests = guestInputs.map((input) => (input.value || '').trim()).filter(Boolean);
      const uniqueGuests = Array.from(new Set(guests));
      if (guests.length !== uniqueGuests.length) {
        showToast('No puedes repetir invitados.', 'error');
        return;
      }
      const maxGuests = parseInt(window.litecalEvent?.limits?.max_guests || '0', 10);
      if (maxGuests > 0 && uniqueGuests.length > maxGuests) {
        showToast(`Máximo ${maxGuests} invitados.`, 'error');
        return;
      }
      for (const email of uniqueGuests) {
        if (!emailPattern.test(email)) {
          showToast('Email de invitado inválido.', 'error');
          return;
        }
      }
      formData.set('guests', uniqueGuests.join(','));
      formData.set('custom_fields', JSON.stringify(customFields));
      const extrasSelection = collectSelectedExtras();
      formData.delete('extras_items[]');
      formData.delete('extras_items');
      extrasSelection.items.forEach((item) => {
        formData.append('extras_items[]', item.id);
      });
      formData.set('extras_hours_units', String(extrasSelection.hoursUnits || 0));
      formData.set('payment_provider', formData.get('payment_provider') || '');
      formData.set('client_timezone', state.timezone || state.sourceTimezone || 'UTC');
      formData.set('client_device_id', clientDeviceId || '');
      const submittedAbuseCode = abuseCodeInput ? String(abuseCodeInput.value || '').trim() : '';
      if (submittedAbuseCode) {
        formData.set('abuse_code', submittedAbuseCode);
      } else {
        formData.delete('abuse_code');
      }
      formEl.querySelectorAll('[data-lc-custom-file]').forEach((input) => {
        const files = filesFromInput(input);
        const fieldName = input.getAttribute('name');
        if (!fieldName) return;
        formData.delete(fieldName);
        files.forEach((file) => {
          formData.append(fieldName, file, file.name || 'archivo');
        });
      });
      const sendBooking = () => {
        if (submitBtn) {
          submitBtn.dataset.lcSubmitting = '1';
          setButtonLoading(submitBtn, true);
        }
        return fetch(`${window.litecal.restUrl}/bookings`, {
          method: 'POST',
          headers: {
            'X-WP-Nonce': window.litecal.nonce,
          },
          body: formData,
        })
          .then((res) => res.json())
          .then((data) => {
          if (isRestErrorPayload(data)) {
            const code = restErrorCode(data);
            if (code === 'abuse_verification_required') {
              showAbuseCodeField(
                data.message || 'Ingresa el código enviado a tu correo para continuar.'
              );
            } else if (code === 'abuse_blocked') {
              hideAbuseCodeField(true);
            }
            showToast(data.message || 'No pudimos procesar la solicitud.', 'error');
            if (submitBtn) {
              submitBtn.dataset.lcSubmitting = '0';
              setButtonLoading(submitBtn, false);
            }
            return;
          }
          hideAbuseCodeField(true);
          const selectedProvider = String(formData.get('payment_provider') || '').toLowerCase();
          if (data && data.payment_url) {
            if ((selectedProvider === 'transfer' || String(data.payment_provider || '').toLowerCase() === 'transfer') && data.booking_id && data.booking_token) {
              const openTransferReceipt = () => {
                if (window.history && typeof window.history.replaceState === 'function') {
                  const nextParams = new URLSearchParams(window.location.search || '');
                  nextParams.set('agendalite_payment', 'transfer');
                  nextParams.set('booking_id', String(data.booking_id));
                  nextParams.set('booking_token', String(data.booking_token));
                  const nextQuery = nextParams.toString();
                  const nextUrl = `${window.location.pathname}${nextQuery ? `?${nextQuery}` : ''}${window.location.hash || ''}`;
                  window.history.replaceState({}, document.title, nextUrl);
                }
                applyReturnViewLayout();
                if (cardEl) {
                  cardEl.classList.remove('is-form');
                  cardEl.classList.add('is-success');
                }
                if (successTitle) successTitle.textContent = 'Pago pendiente por transferencia';
                if (successMessage) successMessage.textContent = 'Tu reserva está confirmada como Pendiente hasta que validemos el pago.';
                setIconState('warning');
                setSuccessHeaderVisibility(false);
                fetchBookingDetails(data.booking_id, data.booking_token, window.litecalEvent?.event?.id || 0)
                  .then((bookingData) => {
                    if (!bookingData) return;
                    renderReceipt(bookingData);
                    applyReturnStatusCopy(bookingData, 'El pago fue rechazado o cancelado. Intenta nuevamente.');
                    showRetry(bookingData);
                  })
                  .catch(() => {
                    showToast('La reserva quedó pendiente, pero no pudimos cargar el recibo. Recarga la página.', 'error');
                  })
                  .finally(() => {
                    if (submitBtn) {
                      submitBtn.dataset.lcSubmitting = '0';
                      setButtonLoading(submitBtn, false);
                    }
                  });
              };
              openTransferReceipt();
              return;
            }
            window.location.href = data.payment_url;
            return;
          }
          if (data && data.payment_required) {
            const detail = data.payment_error ? `\n${data.payment_error}` : '';
            showToast(`No pudimos iniciar el pago. ${detail}`.trim(), 'error');
            if (submitBtn) {
              submitBtn.dataset.lcSubmitting = '0';
              setButtonLoading(submitBtn, false);
            }
            return;
          }
          if (cardEl) {
            cardEl.classList.remove('is-form');
            cardEl.classList.add('is-success');
          }
          if (data && !data.payment_required && data.booking_id) {
            applyReturnViewLayout();
            if (successTitle) successTitle.textContent = 'Reserva confirmada';
            if (successMessage) successMessage.textContent = 'Estamos cargando el detalle de tu reserva...';
            setIconState('ok');
            setSuccessHeaderVisibility(false);
            if (successSummary) {
              successSummary.style.display = 'none';
            }
            fetchBookingDetails(data.booking_id, data.booking_token || '', window.litecalEvent?.event?.id || 0)
              .then((bookingData) => {
                if (!bookingData) {
                  throw new Error('No booking data');
                }
                renderReceipt(bookingData, { mode: 'free_success', hideSuccessHeader: true });
                showRetry(null);
              })
              .catch(() => {
                setSuccessHeaderVisibility(true);
                if (successTitle) successTitle.textContent = 'Esta reunión está programada';
                if (successMessage) successMessage.textContent = 'Hemos enviado un correo con los detalles.';
                const slotDateFallback = state.slot.date || state.date;
                const fallbackStart = slotMoment(slotDateFallback, state.slot.start);
                const fallbackEnd = slotMoment(slotDateFallback, state.slot.end);
                const fallbackTz = state.timezone || state.sourceTimezone || 'UTC';
                const fallbackDateText = formatDateInTimezone(fallbackStart, fallbackTz);
                const fallbackTimeText = `${formatTimeInTimezone(fallbackStart, fallbackTz)} - ${formatTimeInTimezone(fallbackEnd, fallbackTz)}`;
                if (successSummary) {
                  successSummary.innerHTML = `
                    <div><strong>${escHtml(window.litecalEvent.event.title)}</strong></div>
                    <div>${escHtml(fallbackDateText)}</div>
                    <div>${escHtml(fallbackTimeText)}</div>
                  `;
                  successSummary.style.display = '';
                }
              })
              .finally(() => {
                if (submitBtn) {
                  submitBtn.dataset.lcSubmitting = '0';
                  setButtonLoading(submitBtn, false);
                }
              });
            return;
          }
          const slotDate = state.slot.date || state.date;
          const successStart = slotMoment(slotDate, state.slot.start);
          const successEnd = slotMoment(slotDate, state.slot.end);
          const targetTz = state.timezone || state.sourceTimezone || 'UTC';
          const dateText = formatDateInTimezone(successStart, targetTz);
          const timeText = `${formatTimeInTimezone(successStart, targetTz)} - ${formatTimeInTimezone(successEnd, targetTz)}`;
          if (successSummary) {
            successSummary.innerHTML = `
              <div><strong>${escHtml(window.litecalEvent.event.title)}</strong></div>
              <div>${escHtml(dateText)}</div>
              <div>${escHtml(timeText)}</div>
            `;
          }
          if (submitBtn) {
            submitBtn.dataset.lcSubmitting = '0';
            setButtonLoading(submitBtn, false);
          }
        })
        .catch(() => {
          showToast('No pudimos procesar la solicitud.', 'error');
          if (submitBtn) {
            submitBtn.dataset.lcSubmitting = '0';
            setButtonLoading(submitBtn, false);
          }
        });
      };

      const recaptchaCfg = window.litecal?.recaptcha || {};
      if (recaptchaCfg.enabled && recaptchaCfg.siteKey && window.grecaptcha && window.grecaptcha.execute) {
        window.grecaptcha.ready(() => {
          window.grecaptcha.execute(recaptchaCfg.siteKey, { action: 'booking' })
            .then((token) => {
              if (token) {
                formData.set('recaptcha_token', token);
              }
              sendBooking();
            })
            .catch(() => {
              sendBooking();
            });
        });
      } else {
        sendBooking();
      }
      });
    }

    if (restartBtn) {
      restartBtn.addEventListener('click', function () {
        window.location.href = window.location.pathname;
      });
    }

    const params = new URLSearchParams(window.location.search || '');
    const returnProvider = params.get('agendalite_payment') || (params.get('agendalite_receipt') === '1' ? 'receipt' : '');
    const returnBookingId = params.get('booking_id');
    const returnBookingToken = params.get('booking_token') || '';
    const returnToken = params.get('token');
    const returnCancelled = params.get('cancelled') === '1';
    if (returnProvider === 'paypal' && returnToken && window.history && typeof window.history.replaceState === 'function') {
      const cleanParams = new URLSearchParams(window.location.search || '');
      cleanParams.delete('token');
      cleanParams.delete('PayerID');
      const cleanQuery = cleanParams.toString();
      const cleanUrl = `${window.location.pathname}${cleanQuery ? `?${cleanQuery}` : ''}${window.location.hash || ''}`;
      window.history.replaceState({}, document.title, cleanUrl);
    }
    const receiptEl = query('[data-lc-receipt]');
    const receiptActionsStaticEl = query('.lc-receipt-actions');
    let retryPaymentBtn = query('[data-lc-retry-payment]');
    const successTitle = query('[data-lc-success-title]');
    const successMessage = query('[data-lc-success-message]');
    let lastReceiptData = null;

    const getRetryPayload = (buttonEl) => {
      const bookingId = parseInt(buttonEl?.dataset?.lcRetryBooking || (lastReceiptData ? lastReceiptData.id : 0), 10);
      const provider = (buttonEl?.dataset?.lcRetryProvider || (lastReceiptData ? lastReceiptData.payment_provider : '') || '').toLowerCase();
      const bookingToken = buttonEl?.dataset?.lcRetryToken || (lastReceiptData ? lastReceiptData.booking_token : '') || returnBookingToken;
      return { bookingId, provider, bookingToken };
    };

    const resumePayment = (buttonEl) => {
      const { bookingId, provider, bookingToken } = getRetryPayload(buttonEl);
      if (!bookingId || !provider) return;
      if (!bookingToken) {
        showToast('No se pudo validar la reserva para retomar el pago.', 'error');
        return;
      }
      if (buttonEl.dataset.lcProcessing === '1') return;
      buttonEl.dataset.lcProcessing = '1';
      fetch(`${window.litecal.restUrl}/payments/resume`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ booking_id: bookingId, provider, booking_token: bookingToken }),
      })
        .then((res) => res.json())
        .then((payload) => {
          if (payload && payload.payment_url) {
            window.location.href = payload.payment_url;
            return;
          }
          if (payload && (payload.message || payload.code)) {
            showToast(payload.message || 'No se pudo retomar el pago.', 'error');
          } else {
            showToast('No se pudo retomar el pago.', 'error');
          }
          buttonEl.dataset.lcProcessing = '0';
          buttonEl.disabled = false;
          buttonEl.style.opacity = '';
        })
        .catch(() => {
          showToast('No se pudo retomar el pago. Intenta nuevamente.', 'error');
          buttonEl.dataset.lcProcessing = '0';
          buttonEl.disabled = false;
          buttonEl.style.opacity = '';
        });
    };

    document.addEventListener('click', (e) => {
      const restart = e.target.closest('[data-lc-restart]');
      if (restart) {
        e.preventDefault();
        window.location.href = window.location.pathname;
        return;
      }
      const retry = e.target.closest('[data-lc-retry-payment]');
      if (retry) {
        e.preventDefault();
        resumePayment(retry);
      }
    });

    const formatMoneyLabel = (value, currency) => {
      const c = (currency || 'CLP').toUpperCase();
      const meta = {
        CLP: { symbol: '$', decimal: ',', thousand: '.' },
        USD: { symbol: '$', decimal: '.', thousand: ',' },
        EUR: { symbol: '€', decimal: ',', thousand: '.' },
      }[c] || { symbol: '$', decimal: ',', thousand: '.' };
      const zeroDecimals = ['CLP', 'JPY', 'HUF', 'TWD'];
      const decimals = zeroDecimals.includes(c) ? 0 : 2;
      const formatted = Number(value || 0).toLocaleString('es-CL', {
        minimumFractionDigits: decimals,
        maximumFractionDigits: decimals,
      });
      return `${meta.symbol}${formatted} ${c}`;
    };

    const fetchJson = (url) =>
      fetch(url, { credentials: 'same-origin' })
        .then((res) => res.json())
        .then((data) => {
          if (isRestErrorPayload(data)) {
            throw new Error(data?.message || 'No se pudo obtener la información.');
          }
          return data;
        });

    const refreshBookingState = (bookingId, bookingToken = returnBookingToken) => {
      if (!bookingId) return Promise.resolve();
      const payload = {};
      if (bookingToken) {
        payload.booking_token = bookingToken;
      }
      return fetch(`${window.litecal.restUrl}/booking/${bookingId}/refresh`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        credentials: 'same-origin',
        body: JSON.stringify(payload),
      })
        .then((res) => res.json())
        .then(() => {})
        .catch(() => {});
    };

    const fetchBookingDetails = (bookingId, bookingToken = returnBookingToken, eventIdOverride = 0) => {
      const base = `${window.litecal.restUrl}/booking/${bookingId}`;
      const eventId = Number(eventIdOverride || window.litecalEvent?.event?.id || 0);
      const receiptParams = new URLSearchParams({ receipt: '1' });
      if (bookingToken) {
        receiptParams.set('booking_token', bookingToken);
      }
      return refreshBookingState(bookingId, bookingToken).then(() =>
        fetchJson(`${base}?${receiptParams.toString()}`).catch(() => {
          if (!eventId) {
            throw new Error('receipt fetch failed');
          }
          const eventParams = new URLSearchParams({ event_id: String(eventId) });
          if (bookingToken) {
            eventParams.set('booking_token', bookingToken);
          }
          return fetchJson(`${base}?${eventParams.toString()}`);
        })
      );
    };

    const waitForStripeSettlement = (bookingId, bookingToken, attempts = 8, delayMs = 1500) =>
      new Promise((resolve) => {
        let attempt = 0;
        const run = () => {
          fetchBookingDetails(bookingId, bookingToken)
            .then((data) => {
              if (isRestErrorPayload(data)) {
                resolve(data);
                return;
              }
              const st = String(data?.payment_status || '').toLowerCase();
              if ((st === 'pending' || st === 'unpaid') && attempt < attempts) {
                attempt += 1;
                setTimeout(run, delayMs);
                return;
              }
              resolve(data);
            })
            .catch(() => {
              if (attempt < attempts) {
                attempt += 1;
                setTimeout(run, delayMs);
                return;
              }
              resolve(null);
            });
        };
        run();
      });

    const renderReceipt = (data, options = {}) => {
      if (!receiptEl) return;
      if (receiptActionsStaticEl) {
        receiptActionsStaticEl.style.display = 'none';
      }
      lastReceiptData = data;
      const renderMode = String(options?.mode || '');
      const hideSuccessHeader = !!options?.hideSuccessHeader;
      const manageActionResult = String(options?.action || '');
      const rawPaymentAmount = Number(data.payment_amount || 0);
      const providerMap = { mp: 'MercadoPago', webpay: 'Webpay Plus', flow: 'Flow', paypal: 'PayPal', stripe: 'Stripe', transfer: 'Transferencia bancaria', onsite: 'Pago presencial' };
      const provider = data.payment_provider ? (providerMap[data.payment_provider] || data.payment_provider.toUpperCase()) : '-';
      const ref = data.payment_reference || '-';
      const hostName = data.employee?.name || window.litecalEvent.host?.name || '';
      const fullName = (data.name || '').trim();
      const snapshotFirst = (data?.snapshot?.booking?.first_name || '').trim();
      const snapshotLast = (data?.snapshot?.booking?.last_name || '').trim();
      const firstName = (data.first_name || snapshotFirst || (fullName ? fullName.split(' ')[0] : '') || '').trim();
      const lastName = (data.last_name || snapshotLast || (fullName ? fullName.split(' ').slice(1).join(' ') : '') || '').trim();
      const status = (data.payment_status || '').toLowerCase();
      const bookingStatus = String(data.status || '').toLowerCase();
      const hasPaymentProvider = !!String(data.payment_provider || '').trim();
      const hasPaymentReference = !!String(data.payment_reference || '').trim();
      const hasPaymentAmount = rawPaymentAmount > 0;
      const hasPaymentContext = hasPaymentProvider || hasPaymentReference || hasPaymentAmount;
      const amount = hasPaymentContext && rawPaymentAmount > 0
        ? formatMoneyLabel(rawPaymentAmount, data.payment_currency)
        : '-';
      const statusLabelMap = {
        paid: 'Aprobado',
        completed: 'Aprobado',
        approved: 'Aprobado',
        confirmed: 'Aprobado',
        pending: 'Pendiente',
        unpaid: 'No pagado',
        rejected: 'Rechazado',
        failed: 'Rechazado',
        expired: 'Cancelado',
        cancelled: 'Cancelado',
        canceled: 'Cancelado',
      };
    const statusLabel = statusLabelMap[status] || (data.payment_status || data.status || '').toUpperCase();
    const bookingStatusLabelMap = {
      confirmed: 'Confirmada',
      pending: 'Pendiente',
      cancelled: 'Cancelada',
      canceled: 'Cancelada',
      rescheduled: 'Reagendada',
      completed: 'Completada',
    };
    const bookingStatusLabel = bookingStatusLabelMap[bookingStatus] || String(data.status || '').toUpperCase();
    const eventName = data?.snapshot?.event?.title || window.litecalEvent.event.title;
    const startDateRaw = String(data.start || '').split(' ')[0] || '';
    const startTimeRaw = String(data.start || '').split(' ')[1]?.slice(0, 5) || '00:00';
    const endDateRaw = String(data.end || '').split(' ')[0] || startDateRaw;
    const endTimeRaw = String(data.end || '').split(' ')[1]?.slice(0, 5) || startTimeRaw;
    const receiptStart = slotMoment(startDateRaw, startTimeRaw);
    const receiptEnd = slotMoment(endDateRaw, endTimeRaw);
    const targetTz = state.timezone || state.sourceTimezone || 'UTC';
    const dateText = formatDateInTimezone(receiptStart, targetTz);
    const timeText = `${formatTimeInTimezone(receiptStart, targetTz)} - ${formatTimeInTimezone(receiptEnd, targetTz)}`;
    const requiresPaymentGate = Boolean(data.payment_provider) || Number(data.payment_amount || 0) > 0;
    const canShowMeetingLink = !requiresPaymentGate || ['paid', 'completed', 'approved', 'confirmed'].includes(status);
      const statusCopy = {
        approved: { title: 'Pago aprobado', message: 'El pago se procesó correctamente.' },
        paid: { title: 'Pago aprobado', message: 'El pago se procesó correctamente.' },
        pending: { title: 'Pago no completado', message: 'No se ha registrado ningún pago hasta el momento.' },
        rejected: { title: 'Pago rechazado', message: 'El pago no se procesó y la orden fue cancelada.' },
        failed: { title: 'Pago rechazado', message: 'El pago no se procesó y la orden fue cancelada.' },
        expired: { title: 'Pago cancelado', message: 'La orden expiró por falta de confirmación.' },
        cancelled: { title: 'Pago cancelado', message: 'El pago fue cancelado.' },
        canceled: { title: 'Pago cancelado', message: 'El pago fue cancelado.' },
      };
      const copy = statusCopy[status] || { title: `Pago ${statusLabel.toLowerCase()}`, message: '' };
      if (renderMode === 'manage') {
        if (manageActionResult === 'cancel') {
          copy.title = 'Reserva cancelada correctamente';
          copy.message = 'Hemos actualizado el estado de tu reserva.';
        } else {
          copy.title = 'Reserva reagendada correctamente';
          copy.message = 'Hemos actualizado la nueva fecha y hora de tu reserva.';
        }
      } else if (renderMode === 'free_success') {
        copy.title = 'Reserva confirmada correctamente';
        copy.message = 'Hemos enviado un correo con los detalles de tu cita.';
      } else if (!hasPaymentContext) {
        copy.title = `Reserva ${String(bookingStatusLabel || 'confirmada').toLowerCase()}`;
        copy.message = 'Detalle actualizado de tu reserva.';
      }
      const isOnsitePayment = String(data.payment_provider || '').toLowerCase() === 'onsite';
      if (status === 'pending' && data.payment_provider === 'transfer') {
        copy.title = 'Pago pendiente por transferencia';
        copy.message = 'Tu reserva está confirmada como Pendiente hasta que validemos el pago.';
      } else if (isOnsitePayment) {
        copy.title = 'Reserva confirmada';
        copy.message = 'El pago se realizará de forma presencial al momento de la cita.';
      }
      const isTransferPending = status === 'pending' && String(data.payment_provider || '').toLowerCase() === 'transfer';
      if (renderMode !== 'manage') {
        if (hideSuccessHeader) {
          setSuccessHeaderVisibility(false);
        } else {
          setSuccessHeaderVisibility(!isTransferPending);
        }
      }
      const ticketLabel = renderMode === 'manage'
        ? 'Detalle de reserva'
        : (!hasPaymentContext ? 'Detalle de reserva' : (isTransferPending ? 'Transferencia bancaria' : 'Recibo de orden'));
      let statusClass = status === 'pending' ? 'is-warning' : status === 'approved' || status === 'paid' ? 'is-success' : 'is-danger';
      if (renderMode === 'manage') {
        statusClass = bookingStatus === 'cancelled' || bookingStatus === 'canceled' ? 'is-danger' : 'is-success';
      } else if (isOnsitePayment) {
        statusClass = bookingStatus === 'cancelled' || bookingStatus === 'canceled' ? 'is-danger' : 'is-success';
      } else if (!hasPaymentContext) {
        if (bookingStatus === 'cancelled' || bookingStatus === 'canceled') {
          statusClass = 'is-danger';
        } else if (bookingStatus === 'pending') {
          statusClass = 'is-warning';
        } else {
          statusClass = 'is-success';
        }
      }
      const iconChar = statusClass === 'is-success' ? '✓' : statusClass === 'is-warning' ? '!' : '✕';
      const formatFileSize = (bytes) => {
        const size = Number(bytes || 0);
        if (!size) return '-';
        const mb = size / (1024 * 1024);
        if (mb >= 1) return `${mb.toFixed(1)} MB`;
        const kb = size / 1024;
        return `${kb.toFixed(0)} KB`;
      };
      const files = data?.snapshot?.files || {};
      const filesList = [];
      Object.keys(files).forEach((key) => {
        const items = Array.isArray(files[key]) ? files[key] : [];
        items.forEach((file) => {
          if (!file || !file.url) return;
          filesList.push({
            name: file.original_name || file.stored_name || 'Archivo',
            mime: file.mime || '',
            size: formatFileSize(file.size),
            url: file.url,
          });
        });
      });
      const defs = data?.snapshot?.custom_fields_def || [];
      const values = data?.custom_fields || {};
      const customLines = [];
      const matchedKeys = new Set();
      if (Array.isArray(defs)) {
        defs.forEach((def) => {
          const key = def?.key;
          if (!key || values[key] == null) return;
          matchedKeys.add(key);
          if ((def?.type || '') === 'file') return;
          const label = def?.label || key;
          let val = values[key];
          if (Array.isArray(val)) {
            if (val.length && typeof val[0] === 'object') {
              return;
            }
            val = val.join(', ');
          } else if (typeof val === 'object' && val !== null && val.original_name) {
            return;
          }
          if (val !== '') {
            customLines.push({ label, value: val });
          }
        });
      }
      Object.keys(values || {}).forEach((key) => {
        if (matchedKeys.has(key)) return;
        const val = values[key];
        if (val && typeof val === 'object') return;
        if (Array.isArray(val) && val.length && typeof val[0] === 'object') return;
        if (val !== '' && val != null) {
          customLines.push({ label: key, value: Array.isArray(val) ? val.join(', ') : val });
        }
      });
      const guestValuesSource = Array.isArray(data?.guests)
        ? data.guests
        : (Array.isArray(data?.snapshot?.booking?.guests) ? data.snapshot.booking.guests : []);
      const guestValues = guestValuesSource
        .map((item) => (item || '').toString().trim())
        .filter(Boolean);
      const receiptEmail = data.email || data?.snapshot?.booking?.email || '';
      const receiptPhone = data.phone || data?.snapshot?.booking?.phone || '';
      const meetLogo = window.litecal?.logos?.googleMeet || '';
      const zoomLogo = window.litecal?.logos?.zoom || '';
      const teamsLogo = window.litecal?.logos?.teams || '';
      const snapshotLocationKey = String(data?.snapshot?.event?.location || '').trim().toLowerCase();
      const snapshotLocationRaw = String(data?.snapshot?.event?.location_details || '').trim();
      const snapshotLocationLink = /^https?:\/\//i.test(snapshotLocationRaw) ? snapshotLocationRaw : '';
      const normalizedMeetingProvider = String(
        data?.meeting_provider ||
        data?.video_provider ||
        data?.snapshot?.booking?.video_provider ||
        snapshotLocationKey
      ).trim().toLowerCase();
      const providerMeta = (() => {
        if (normalizedMeetingProvider === 'zoom') {
          return { label: 'Zoom', logo: zoomLogo };
        }
        if (normalizedMeetingProvider === 'teams' || normalizedMeetingProvider === 'microsoft_teams') {
          return { label: 'Microsoft Teams', logo: teamsLogo };
        }
        return { label: 'Google Meet', logo: meetLogo };
      })();
      const detailsRows = [];
      if (firstName) {
        detailsRows.push(`
          <div class="lc-ticket-detail-row">
            <dt>Nombre</dt>
            <dd>${escHtml(firstName)}</dd>
          </div>`);
      }
      if (lastName) {
        detailsRows.push(`
          <div class="lc-ticket-detail-row">
            <dt>Apellido</dt>
            <dd>${escHtml(lastName)}</dd>
          </div>`);
      }
      if (receiptEmail) {
        detailsRows.push(`
          <div class="lc-ticket-detail-row">
            <dt>Email</dt>
            <dd>${escHtml(receiptEmail)}</dd>
          </div>`);
      }
      if (receiptPhone) {
        detailsRows.push(`
          <div class="lc-ticket-detail-row">
            <dt>Teléfono</dt>
            <dd>${escHtml(receiptPhone)}</dd>
          </div>`);
      }
      detailsRows.push(`<div class="lc-ticket-detail-row"><dt>Profesional</dt><dd>${escHtml(hostName || '-')}</dd></div>`);
      if (guestValues.length) {
        detailsRows.push(`
          <div class="lc-ticket-detail-row">
            <dt>Invitados</dt>
            <dd>${escHtml(guestValues.join(', '))}</dd>
          </div>`);
      }
      customLines.forEach((row) => {
        detailsRows.push(
          `
          <div class="lc-ticket-detail-row">
            <dt>${escHtml(row.label)}</dt>
            <dd>${escHtml(row.value)}</dd>
          </div>`
        );
      });
      if (canShowMeetingLink && data.meet_link) {
        detailsRows.push(`
          <div class="lc-ticket-detail-row">
            <dt>${providerMeta.logo ? `<img class="lc-location-logo" src="${escUrl(providerMeta.logo)}" alt="${escHtml(providerMeta.label)}">` : ''} ${escHtml(providerMeta.label)}</dt>
            <dd><a class="lc-file-link" href="${escUrl(data.meet_link)}" target="_blank" rel="noopener">Abrir enlace</a></dd>
          </div>`);
      } else if (canShowMeetingLink && (snapshotLocationKey === 'zoom' || snapshotLocationKey === 'teams') && snapshotLocationLink) {
        const providerLabel = snapshotLocationKey === 'teams' ? 'Microsoft Teams' : 'Zoom';
        const providerLogo = snapshotLocationKey === 'teams' ? teamsLogo : zoomLogo;
        detailsRows.push(`
          <div class="lc-ticket-detail-row">
            <dt>${providerLogo ? `<img class="lc-location-logo" src="${escUrl(providerLogo)}" alt="${escHtml(providerLabel)}">` : ''} ${escHtml(providerLabel)}</dt>
            <dd><a class="lc-file-link" href="${escUrl(snapshotLocationLink)}" target="_blank" rel="noopener">Abrir enlace</a></dd>
          </div>`);
      }
      const detailsRowsHtml = detailsRows.join('');
      const detailsBlockHtml = detailsRowsHtml
        ? `<div class="lc-ticket-details-extra" data-lc-ticket-details hidden>${detailsRowsHtml}</div>`
        : '';
      const toggleHtml = detailsRowsHtml
        ? `<a href="#" class="lc-ticket-toggle" data-lc-ticket-toggle>+ Ver más detalles</a>`
        : '';
      const transferRows = Array.isArray(data?.transfer?.rows) ? data.transfer.rows : [];
      const transferInstructions = String(data?.transfer?.instructions || '').trim();
      const transferBlockHtml = hasPaymentContext && data?.payment_provider === 'transfer'
        ? `<div class="lc-ticket-files lc-ticket-transfer">
            <div class="lc-ticket-files-title">Datos para transferencia</div>
            <div class="lc-ticket-transfer-note">El horario está bloqueado y se liberará solo si confirmamos el pago o si la reserva se cancela.</div>
            <div class="lc-ticket-transfer-table">
              ${transferRows.length
                ? transferRows
                    .map(
                      (row) => `
                    <div class="lc-ticket-detail-row">
                      <dt>${escHtml(row.label || '')}</dt>
                      <dd>${escHtml(row.value || '')}</dd>
                    </div>
                  `
                    )
                    .join('')
                : '<div class="lc-ticket-detail-row"><dt>Datos bancarios</dt><dd>Configura los datos en Integraciones para mostrarlos aquí.</dd></div>'}
              ${transferInstructions ? `<div class="lc-ticket-detail-row"><dt>Instrucciones</dt><dd>${escHtml(transferInstructions)}</dd></div>` : ''}
              ${ref && ref !== '-' ? `<div class="lc-ticket-detail-row"><dt>Referencia obligatoria</dt><dd class="lc-mono">${escHtml(ref)}</dd></div>` : ''}
            </div>
          </div>`
        : '';
      const filesHtml = filesList.length
        ? `<div class="lc-ticket-files">
            <div class="lc-ticket-files-title">Archivos adjuntos</div>
            ${filesList
              .map(
                (f) => `
                <div class="lc-ticket-file">
                  <div class="lc-ticket-file-meta">
                    <a class="lc-file-link" href="${escUrl(f.url)}" target="_blank" rel="noopener">${escHtml(f.name)}</a>
                    <small>${escHtml(f.mime || '')} · ${escHtml(f.size)}</small>
                  </div>
                  <a class="lc-btn lc-btn-light lc-file-btn" href="${escUrl(f.url)}" target="_blank" rel="noopener">Ver/descargar</a>
                </div>
              `
              )
              .join('')}
          </div>`
        : '';

      receiptEl.innerHTML = `
        <section class="lc-ticket" aria-label="Recibo">
          <div class="lc-zigzag lc-zigzag--top" aria-hidden="true"></div>
          <header class="lc-ticket-header">
            <div class="lc-ticket-icon ${statusClass}"><span>${iconChar}</span></div>
            <p class="lc-ticket-label">${escHtml(ticketLabel)}</p>
            <h3 class="lc-ticket-status">${copy.title}</h3>
            <p class="lc-ticket-message">${copy.message}</p>
          </header>
          <div class="lc-ticket-event">
            <p class="lc-event-name">${escHtml(eventName)}</p>
            <p class="lc-event-date">${escHtml(dateText)} · ${escHtml(timeText)}</p>
          </div>
          <hr />
          <dl class="lc-ticket-details">
            <div><dt>ID de cita</dt><dd>#${escHtml(data.id)}</dd></div>
            ${hasPaymentContext
              ? `<div><dt>Monto</dt><dd>${escHtml(amount)}</dd></div>
            <div><dt>Método de pago</dt><dd>${escHtml(provider)}</dd></div>
            <div><dt>Estado pago</dt><dd class="lc-status-badge ${escHtml(statusClass)}">${escHtml(statusLabel)}</dd></div>
            <div><dt>Referencia</dt><dd class="lc-mono">${escHtml(ref)}</dd></div>`
              : `<div><dt>Estado reserva</dt><dd class="lc-status-badge ${escHtml(bookingStatus === 'cancelled' || bookingStatus === 'canceled' ? 'is-danger' : 'is-success')}">${escHtml(bookingStatusLabel || '-')}</dd></div>`}
          </dl>
          <div class="lc-ticket-extra-wrap" data-lc-ticket-extra-wrap>
            ${detailsBlockHtml}
            ${toggleHtml}
          </div>
          ${transferBlockHtml}
          ${filesHtml}
          <div class="lc-ticket-actions">
            <button class="lc-btn lc-btn-primary" type="button" data-lc-restart>Hacer otra reserva</button>
            <button class="lc-btn lc-btn-light" type="button" data-lc-copy-ref>Copiar referencia</button>
            <button class="lc-btn" type="button" data-lc-retry-payment style="display:none;">Retomar pago</button>
          </div>
          <div class="lc-zigzag lc-zigzag--bottom" aria-hidden="true"></div>
        </section>
      `;
      const copyBtn = receiptEl.querySelector('[data-lc-copy-ref]');
      if (copyBtn) {
        if (!ref || ref === '-' || (status === 'pending' && data.payment_provider !== 'transfer')) {
          copyBtn.style.display = 'none';
        } else {
          copyBtn.style.display = 'inline-flex';
          copyBtn.onclick = () => {
            if (!ref || ref === '-') return;
            const label = copyBtn.textContent;
            navigator.clipboard?.writeText(ref).then(() => {
              copyBtn.textContent = 'Copiado';
              copyBtn.classList.add('is-copied');
              window.setTimeout(() => {
                copyBtn.textContent = label;
                copyBtn.classList.remove('is-copied');
              }, 1200);
            }).catch(() => {});
          };
        }
      }
      const inlineRetry = receiptEl.querySelector('[data-lc-retry-payment]');
      if (inlineRetry) {
        inlineRetry.dataset.lcRetryProvider = data.payment_provider || '';
        inlineRetry.dataset.lcRetryBooking = data.id || '';
        inlineRetry.dataset.lcRetryToken = data.booking_token || returnBookingToken || '';
        inlineRetry.dataset.lcRetryCreatedAt = data.created_at || '';
        inlineRetry.dataset.lcRetryCreatedTs = data.created_at_ts || '';
        if ((status === 'pending' || status === 'unpaid') && !['transfer', 'onsite'].includes(String(data.payment_provider || '').toLowerCase())) {
          inlineRetry.style.display = 'inline-flex';
        } else {
          inlineRetry.style.display = 'none';
        }
      }
      const toggle = receiptEl.querySelector('[data-lc-ticket-toggle]');
      const details = receiptEl.querySelector('[data-lc-ticket-details]');
      const extraWrap = receiptEl.querySelector('[data-lc-ticket-extra-wrap]');
      if (toggle && details && extraWrap) {
        const setExpanded = (expanded) => {
          if (expanded) {
            details.removeAttribute('hidden');
            details.style.display = '';
            toggle.textContent = '- Ver menos';
            extraWrap.classList.add('is-open');
            extraWrap.appendChild(details);
            extraWrap.appendChild(toggle);
            return;
          }
          details.setAttribute('hidden', '');
          details.style.display = 'none';
          toggle.textContent = '+ Ver más detalles';
          extraWrap.classList.remove('is-open');
          extraWrap.appendChild(toggle);
          extraWrap.appendChild(details);
        };
        setExpanded(false);
        toggle.addEventListener('click', (e) => {
          e.preventDefault();
          const isHidden = details.hasAttribute('hidden');
          setExpanded(isHidden);
        });
      }
    };

    const setIconState = (state) => {
      const icon = query('.lc-success-icon');
      if (!icon) return;
      icon.classList.remove('is-error', 'is-warning');
      if (state === 'error') {
        icon.classList.add('is-error');
        icon.textContent = '✕';
      } else if (state === 'warning') {
        icon.classList.add('is-warning');
        icon.textContent = '!';
      } else {
        icon.textContent = '✓';
      }
    };

    const setSuccessHeaderVisibility = (visible) => {
      const icon = query('.lc-success-icon');
      const displayValue = visible ? '' : 'none';
      if (icon) {
        icon.style.display = displayValue;
      }
      if (successTitle) {
        successTitle.style.display = displayValue;
      }
      if (successMessage) {
        successMessage.style.display = displayValue;
      }
    };

    let retryCountdownTimer = null;
    const formatCountdown = (ms) => {
      const totalSec = Math.max(0, Math.floor(ms / 1000));
      const mins = String(Math.floor(totalSec / 60)).padStart(2, '0');
      const secs = String(totalSec % 60).padStart(2, '0');
      return `${mins}:${secs}`;
    };

    const toPositiveNumber = (value) => {
      const n = Number(value);
      if (!Number.isFinite(n) || n <= 0) return 0;
      return n;
    };

    const startRetryCountdown = (secondsLeft, bookingId, buttonEl) => {
      const btn = buttonEl || retryPaymentBtn;
      if (!btn) return;
      if (retryCountdownTimer) clearInterval(retryCountdownTimer);
      const initialSeconds = toPositiveNumber(secondsLeft);
      if (initialSeconds <= 0) {
        btn.textContent = 'Retomar pago';
        btn.disabled = false;
        btn.style.opacity = '';
        return;
      }
      const deadline = Date.now() + (initialSeconds * 1000);
      const reloadKey = bookingId ? `lc_retry_reload_${bookingId}` : null;
      const tick = () => {
        const remaining = deadline - Date.now();
        if (remaining <= 0) {
          btn.textContent = 'Retomar pago 00:00 min';
          btn.disabled = true;
          btn.style.opacity = '0.6';
          if (reloadKey && !localStorage.getItem(reloadKey)) {
            localStorage.setItem(reloadKey, '1');
            window.location.reload();
          }
          return;
        }
        btn.textContent = `Retomar pago ${formatCountdown(remaining)} min`;
      };
      tick();
      retryCountdownTimer = setInterval(tick, 1000);
    };

    const showRetry = (data) => {
      if (receiptEl) {
        retryPaymentBtn = receiptEl.querySelector('[data-lc-retry-payment]') || retryPaymentBtn;
      }
      if (!retryPaymentBtn) return;
      if (!data || !data.payment_provider || !['flow', 'paypal', 'mp', 'webpay', 'stripe'].includes(data.payment_provider)) {
        retryPaymentBtn.style.display = 'none';
        return;
      }
      const statusValue = (data.payment_status || '').toLowerCase();
      if (statusValue !== 'pending' && statusValue !== 'unpaid') {
        retryPaymentBtn.style.display = 'none';
        return;
      }
      let secondsLeft = toPositiveNumber(data.payment_retry_seconds_left);
      if (secondsLeft <= 0) {
        const pendingAtTs = toPositiveNumber(data.payment_pending_at_ts);
        if (pendingAtTs > 0) {
          const expiryMinutes = Math.max(10, Math.min(30, parseInt(String(data.payment_expiration_minutes || 10), 10) || 10));
          const expirySeconds = expiryMinutes * 60;
          const nowTs = Math.floor(Date.now() / 1000);
          secondsLeft = Math.max(0, expirySeconds - (nowTs - pendingAtTs));
        }
      }
      retryPaymentBtn.style.display = 'inline-flex';
      retryPaymentBtn.disabled = false;
      retryPaymentBtn.style.opacity = '';
      startRetryCountdown(secondsLeft, data.id, retryPaymentBtn);
      retryPaymentBtn.onclick = (e) => {
        e.preventDefault();
        resumePayment(retryPaymentBtn);
      };
    };

    const applyReturnStatusCopy = (data, rejectedMessage) => {
      if (isRestErrorPayload(data)) return;
      const status = (data.payment_status || '').toLowerCase();
      const isTransferPending = (status === 'pending' || status === 'unpaid') && String(data.payment_provider || '').toLowerCase() === 'transfer';
      if (status === 'rejected' || status === 'failed' || status === 'expired' || status === 'cancelled' || status === 'canceled') {
        if (successTitle) successTitle.textContent = 'Pago rechazado';
        if (successMessage) successMessage.textContent = rejectedMessage || 'El pago no se procesó. Vuelve a intentarlo.';
        setIconState('error');
      } else if (isTransferPending) {
        if (successTitle) successTitle.textContent = 'Pago pendiente por transferencia';
        if (successMessage) successMessage.textContent = 'Tu reserva está confirmada como Pendiente hasta que validemos el pago.';
        setIconState('warning');
      } else if (status === 'pending' || status === 'unpaid') {
        if (successTitle) successTitle.textContent = 'Pago no completado';
        if (successMessage) successMessage.textContent = 'No se ha registrado ningún pago hasta el momento. Para confirmar tu reserva, debes retomar el proceso de pago.';
        setIconState('warning');
      } else {
        if (successTitle) successTitle.textContent = 'Esta reunión está programada';
        if (successMessage) successMessage.textContent = 'Hemos enviado un correo con los detalles.';
        setIconState('ok');
      }
      if (successSummary) {
        successSummary.style.display = 'none';
      }
      renderReceipt(data);
      showRetry(data);
    };

    const applyManageStatusCopy = (data, action) => {
      if (isRestErrorPayload(data)) return;
      const isCancel = action === 'cancel';
      if (successTitle) {
        successTitle.textContent = isCancel
          ? 'Reserva cancelada con éxito'
          : 'Reserva reagendada con éxito';
      }
      if (successMessage) {
        successMessage.textContent = isCancel
          ? 'Tu reserva fue cancelada correctamente.'
          : 'Tu reserva fue reagendada correctamente.';
      }
      setSuccessHeaderVisibility(true);
      setIconState('ok');
      if (successSummary) {
        successSummary.style.display = 'none';
      }
      renderReceipt(data, { mode: 'manage', action: isCancel ? 'cancel' : 'reschedule' });
      showRetry(null);
    };

    if (returnProvider === 'transfer') {
      setSuccessHeaderVisibility(false);
    }

    if (returnProvider === 'paypal' && returnBookingId) {
      if (cardEl) {
        cardEl.classList.remove('is-form');
        cardEl.classList.add('is-success');
      }
      const applyFallbackIfAny = (message) => {
        if (!isRestErrorPayload(window.litecalReceiptData)) {
          applyReturnStatusCopy(window.litecalReceiptData, message);
        }
      };
      if (returnCancelled) {
        // Keep payment flow in pending state so user can retry during hold window.
        fetchBookingDetails(returnBookingId)
          .then((data) => applyReturnStatusCopy(data, 'El pago fue cancelado. Puedes intentar nuevamente cuando quieras.'))
          .catch(() => applyFallbackIfAny('El pago fue cancelado. Puedes intentar nuevamente cuando quieras.'));
      } else if (returnToken) {
        fetch(`${window.litecal.restUrl}/payments/paypal-capture`, {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({ booking_id: returnBookingId, token: returnToken, booking_token: returnBookingToken }),
        })
          .catch(() => {})
          .finally(() => {
            fetchBookingDetails(returnBookingId)
              .then((data) => applyReturnStatusCopy(data, 'El pago no se procesó. Vuelve a intentarlo.'))
              .catch(() => applyFallbackIfAny('El pago no se procesó. Vuelve a intentarlo.'));
          });
      } else {
        fetchBookingDetails(returnBookingId)
          .then((data) => applyReturnStatusCopy(data, 'El pago no se procesó. Vuelve a intentarlo.'))
          .catch(() => applyFallbackIfAny('El pago no se procesó. Vuelve a intentarlo.'));
      }
    }

    if ((returnProvider === 'flow' || returnProvider === 'mp' || returnProvider === 'webpay' || returnProvider === 'transfer' || returnProvider === 'stripe') && returnBookingId) {
      if (returnProvider === 'stripe' && !returnCancelled) {
        if (successTitle) successTitle.textContent = 'Estamos confirmando tu pago';
        if (successMessage) successMessage.textContent = 'Tu pago fue recibido. Estamos esperando la confirmación segura de Stripe.';
        setIconState('warning');
      }
      const detailsPromise = (returnProvider === 'stripe' && !returnCancelled)
        ? waitForStripeSettlement(returnBookingId, returnBookingToken, 10, 1500)
        : fetchBookingDetails(returnBookingId);
      detailsPromise
        .then((data) => {
          if (!data || isRestErrorPayload(data)) return;
          if (cardEl) {
            cardEl.classList.remove('is-form');
            cardEl.classList.add('is-success');
          }
          const startDateRaw = String(data.start || '').split(' ')[0] || '';
          const startTimeRaw = String(data.start || '').split(' ')[1]?.slice(0, 5) || '00:00';
          const endDateRaw = String(data.end || '').split(' ')[0] || startDateRaw;
          const endTimeRaw = String(data.end || '').split(' ')[1]?.slice(0, 5) || startTimeRaw;
          const flowStart = slotMoment(startDateRaw, startTimeRaw);
          const flowEnd = slotMoment(endDateRaw, endTimeRaw);
          const targetTz = state.timezone || state.sourceTimezone || 'UTC';
          const dateText = formatDateInTimezone(flowStart, targetTz);
          const timeText = `${formatTimeInTimezone(flowStart, targetTz)} - ${formatTimeInTimezone(flowEnd, targetTz)}`;
          const status = (data.payment_status || '').toLowerCase();
          const isTransferPending = (status === 'pending' || status === 'unpaid') && String(data.payment_provider || '').toLowerCase() === 'transfer';
          if (status === 'rejected' || status === 'failed' || status === 'expired' || status === 'cancelled' || status === 'canceled') {
            if (successTitle) successTitle.textContent = 'Pago rechazado';
            if (successMessage) successMessage.textContent = 'El pago no se procesó. La orden fue cancelada. Vuelve a intentarlo.';
            setIconState('error');
          } else if (isTransferPending) {
            if (successTitle) successTitle.textContent = 'Pago pendiente por transferencia';
            if (successMessage) successMessage.textContent = 'Tu reserva está confirmada como Pendiente hasta que validemos el pago.';
            setIconState('warning');
          } else if (status === 'pending' || status === 'unpaid') {
            if (successTitle) successTitle.textContent = 'Pago no completado';
            if (successMessage) {
              successMessage.textContent = returnCancelled
                ? 'El pago fue cancelado. Puedes intentar nuevamente cuando quieras.'
                : data.payment_provider === 'transfer'
                ? 'Tu reserva está confirmada como Pendiente hasta que validemos el pago.'
                : 'No se ha registrado ningún pago hasta el momento. Para confirmar tu reserva, debes retomar el proceso de pago.';
            }
            setIconState('warning');
          } else {
            if (successTitle) successTitle.textContent = 'Esta reunión está programada';
            if (successMessage) successMessage.textContent = 'Hemos enviado un correo con los detalles.';
            setIconState('ok');
          }
          if (successSummary) {
            successSummary.innerHTML = `
              <div><strong>${escHtml(window.litecalEvent.event.title)}</strong></div>
              <div>${escHtml(dateText)}</div>
              <div>${escHtml(timeText)}</div>
            `;
            successSummary.style.display = 'none';
          }
          renderReceipt(data);
          showRetry(data);
        });
    }

    if (returnProvider === 'receipt' && returnBookingId) {
      const applyReceiptView = (data) => {
        if (isRestErrorPayload(data)) return;
        if (cardEl) {
          cardEl.classList.remove('is-form');
          cardEl.classList.add('is-success');
        }
        if (successTitle) successTitle.textContent = 'Recibo de orden';
        if (successMessage) successMessage.textContent = '';
        if (successSummary) successSummary.style.display = 'none';
        renderReceipt(data);
        showRetry(data);
      };
      let renderedFromLocal = false;
      if (!isRestErrorPayload(window.litecalReceiptData)) {
        applyReceiptView(window.litecalReceiptData);
        renderedFromLocal = true;
      }
      fetchBookingDetails(returnBookingId)
        .then((data) => {
          if (isRestErrorPayload(data)) {
            if (!renderedFromLocal && !isRestErrorPayload(window.litecalReceiptData)) {
              applyReceiptView(window.litecalReceiptData);
            }
            return;
          }
          applyReceiptView(data);
        })
        .catch(() => {
          if (renderedFromLocal) return;
          if (isRestErrorPayload(window.litecalReceiptData)) return;
          applyReceiptView(window.litecalReceiptData);
        });
    }
    if (returnProvider !== 'receipt' && returnBookingId) {
      // keep receipt data available for debug or future hooks without rendering twice
      if (!isRestErrorPayload(window.litecalReceiptData)) {
        lastReceiptData = window.litecalReceiptData;
      }
    }

    if (window.intlTelInput) {
      const stripDialPrefix = (value) =>
        String(value || '')
          .replace(/^\+\d+\s*/, '')
          .trim();
      queryAll('.lc-phone-input').forEach((input) => {
        const iti = window.intlTelInput(input, {
          initialCountry: 'cl',
          separateDialCode: true,
          nationalMode: true,
          autoPlaceholder: 'aggressive',
          customPlaceholder: (placeholder) => {
            const normalized = stripDialPrefix(placeholder);
            return normalized || '9 1234 5678';
          },
          preferredCountries: ['cl', 'ar', 'pe', 'co', 'mx', 'es', 'us'],
          utilsScript: '',
        });
        const syncPlaceholder = () => {
          const next = stripDialPrefix(input.getAttribute('placeholder'));
          input.setAttribute('placeholder', next || '9 1234 5678');
        };
        syncPlaceholder();
        input.addEventListener('countrychange', () => {
          window.setTimeout(syncPlaceholder, 0);
        });
        input._lcIti = iti;
      });
    }

    if (window.FilePond) {
      if (window.FilePondPluginImagePreview) {
        window.FilePond.registerPlugin(window.FilePondPluginImagePreview);
      }
      formEl?.querySelectorAll('[data-lc-custom-file]').forEach((input) => {
        const maxFiles = Math.max(1, parseInt(input.dataset.lcFileMaxFiles || '1', 10) || 1);
        const maxBytes = parseInt(input.dataset.lcFileMaxBytes || '0', 10) || 0;
        const maxMb = maxBytes > 0 ? (maxBytes / (1024 * 1024)).toFixed(2) : null;
        const allowedExts = (input.dataset.lcFileExts || '')
          .split(',')
          .map((v) => v.trim().toLowerCase())
          .filter(Boolean)
          .map((ext) => `.${ext}`);
        const allowedMimes = (input.dataset.lcFileMimes || '')
          .split(',')
          .map((v) => v.trim().toLowerCase())
          .filter(Boolean);
        const acceptedTypes = Array.from(new Set([...allowedMimes, ...allowedExts]));
        const pond = window.FilePond.create(input, {
          allowMultiple: maxFiles > 1,
          maxFiles,
          storeAsFile: true,
          allowFileTypeValidation: acceptedTypes.length > 0,
          acceptedFileTypes: acceptedTypes,
          allowFileSizeValidation: maxBytes > 0,
          maxFileSize: maxMb ? `${maxMb}MB` : null,
          credits: false,
          labelIdle: 'Arrastra tu archivo o <span class="filepond--label-action">búscalo</span>',
        });
        input._lcPond = pond;
      });
    }

    const guestsWrap = query('[data-lc-guests]');
    if (guestsWrap && window.litecalEvent?.limits?.allow_guests) {
      const list = guestsWrap.querySelector('[data-lc-guest-list]');
      const addLink = guestsWrap.querySelector('[data-lc-guest-add]');
      const guestsLimitLabel = guestsWrap.querySelector('[data-lc-guests-limit]');
      const maxGuestsRaw = parseInt(guestsWrap.dataset.lcGuestsMax || '1', 10);
      const maxGuests = Number.isFinite(maxGuestsRaw) && maxGuestsRaw > 0 ? maxGuestsRaw : 1;
      let guestAction = 'add';
      const syncGuestsUI = () => {
        if (!addLink || !list) return;
        const count = list.querySelectorAll('[data-lc-guest-input]').length;
        if (count <= 0) {
          guestAction = 'add';
        } else if (count >= maxGuests) {
          guestAction = 'remove';
        }
        addLink.textContent = guestAction === 'remove' ? '- Quitar invitado' : '+ Añadir invitado';
        if (guestsLimitLabel) {
          guestsLimitLabel.textContent = `Máximo ${maxGuests} invitado${maxGuests === 1 ? '' : 's'}.`;
        }
      };
      const addInput = () => {
        if (!list) return;
        if (list.querySelectorAll('[data-lc-guest-input]').length >= maxGuests) {
          syncGuestsUI();
          return;
        }
        const input = document.createElement('input');
        input.type = 'email';
        input.placeholder = 'email@ejemplo.com';
        input.setAttribute('data-lc-guest-input', '1');
        list.appendChild(input);
        syncGuestsUI();
      };
      const removeInput = () => {
        if (!list) return;
        const inputs = list.querySelectorAll('[data-lc-guest-input]');
        if (!inputs.length) return;
        const last = inputs[inputs.length - 1];
        if (last && last.parentNode) {
          last.parentNode.removeChild(last);
        }
        syncGuestsUI();
      };
      if (addLink) {
        addLink.addEventListener('click', (e) => {
          e.preventDefault();
          if (!list) return;
          const count = list.querySelectorAll('[data-lc-guest-input]').length;
          if (count > 0 && guestAction === 'remove') {
            removeInput();
            return;
          }
          addInput();
        });
      }
      syncGuestsUI();
    }

    if (isManageView) {
      if (formEl) {
        formEl.style.display = 'none';
      }
      if (backBtn) {
        backBtn.style.display = 'none';
      }
      if (submitBtn) {
        submitBtn.style.display = 'none';
      }
      if (manageAction === 'reschedule' && nextBtn) {
        nextBtn.textContent = 'Confirmar reagenda';
        nextBtn.disabled = true;
      }
      if (manageAction === 'cancel') {
        const selectStage = query('[data-lc-stage="select"]');
        if (selectStage) {
          selectStage.style.display = 'none';
        }
        if (nextBtn) {
          nextBtn.style.display = 'none';
        }
      }
      if (manageCancelBtn) {
        manageCancelBtn.addEventListener('click', () => {
          setButtonLoading(manageCancelBtn, true);
          runManageAction('cancel', {
            reason: manageReasonEl ? String(manageReasonEl.value || '') : '',
          })
            .then(() => fetchBookingDetails(manageBookingId, state.manageToken, window.litecalEvent?.event?.id || 0))
            .then((bookingData) => {
              if (!bookingData) return;
              showToast('Reserva cancelada correctamente.', 'success');
              applyReturnViewLayout();
              applyManageStatusCopy(bookingData, 'cancel');
            })
            .catch((err) => {
              const blockedMessage = normalizeManageBlockedMessage(err?.message || 'No se pudo cancelar la reserva.', 'cancel');
              showToast(blockedMessage, 'error');
              const apiCode = String(err?.code || '');
              const apiStatus = Number(err?.httpStatus || 0);
              if (apiCode === 'invalid_token' || apiCode === 'cancel_blocked' || (err?.reasonCode || '') !== '' || apiStatus === 403 || apiStatus === 409) {
                renderManageBlockedState(blockedMessage);
              }
            })
            .finally(() => {
              loadManageState().finally(() => {
                setButtonLoading(manageCancelBtn, false);
                if (!state.manageActionAllowed) {
                  manageCancelBtn.disabled = true;
                }
              });
            });
        });
      }
      loadManageState().then((bookingData) => {
        if (!bookingData) return;
        const actionState = manageAction === 'cancel' ? bookingData?.manage?.cancel : bookingData?.manage?.reschedule;
        const policyAllowChange = bookingData?.manage?.policy?.allow_change_staff;
        manageCanChangeStaff = policyAllowChange == null ? true : Number(policyAllowChange) === 1;
        if (manageAction === 'reschedule') {
          const bookingEmployeeId = parseInt(
            bookingData?.employee?.id
            || bookingData?.snapshot?.employee?.id
            || bookingData?.snapshot?.booking?.employee_id
            || state.employeeId
            || '0',
            10,
          ) || 0;
          if (bookingEmployeeId > 0) {
            state.employeeId = bookingEmployeeId;
            if (employeeSelect) {
              employeeSelect.value = String(bookingEmployeeId);
            }
            setHostEmployee(findEmployee(bookingEmployeeId));
            syncEmployeeCards();
          }
          closeEmployeeStep();
        }
        state.manageActionAllowed = !actionState || Number(actionState.allowed || 0) === 1;
        if (changeEmployeeBtn && hasMultipleEmployees && manageAction === 'reschedule') {
          changeEmployeeBtn.hidden = !manageCanChangeStaff;
        }
        if (actionState && Number(actionState.allowed || 0) !== 1) {
          if (nextBtn) nextBtn.disabled = true;
          if (manageCancelBtn) manageCancelBtn.disabled = true;
          if (actionState.reason) {
            showToast(actionState.reason, 'error');
          }
          renderManageBlockedState(actionState.reason || defaultManageBlockedMessage);
          return;
        }
        if (manageAction === 'reschedule') {
          const currentStart = String(bookingData.start || '');
          const date = currentStart.slice(0, 10);
          if (date) {
            state.date = date;
            renderCalendar(new Date(`${date}T00:00:00`));
            loadSlots(date);
          }
        }
      });
    }

    setTimeFormat(state.timeFormat);
    refreshPricingUi();
    if (!isReturnView && !isManageView) {
      if (!hasMultipleEmployees || (parseInt(state.employeeId, 10) || 0) > 0) {
        renderCalendar(new Date());
      } else {
        resetBookingSelection();
      }
    }
  }

  window.litecalInitPublicServices = initPublicServices;
  window.litecalInit = init;

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', function () {
      initPublicServices(document);
      init();
    });
  } else {
    initPublicServices(document);
    init();
  }
})();
