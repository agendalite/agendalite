(() => {
  const boot = () => {
    const PickrApi = window.Pickr;
    if (!PickrApi || typeof PickrApi.create !== 'function') {
      return;
    }

    document.querySelectorAll('[data-lc-color-input]').forEach((input) => {
      const row = input.closest('.lc-color-row');
      if (!row || input.dataset.lcPickrReady === '1') {
        return;
      }

      const mount = row.querySelector('[data-lc-pickr-mount]');
      const trigger = row.querySelector('[data-lc-pickr-trigger]');
      const nativeInput = row.querySelector('[data-lc-pickr-native]');
      const valueEl = row.querySelector('[data-lc-color-value]');
      if (!mount || !trigger) {
        return;
      }

      const defaultValue = String(input.getAttribute('data-default-color') || '#083a53');
      const initialValue = String(input.value || defaultValue);

      const sync = (value) => {
        const next = String(value || defaultValue).toLowerCase();
        input.value = next;
        mount.style.setProperty('--lc-pickr-color', next);
        if (nativeInput) {
          nativeInput.value = next;
        }
        if (valueEl) {
          valueEl.textContent = next;
        }
      };

      sync(initialValue);

      if (nativeInput) {
        nativeInput.addEventListener('input', () => {
          sync(nativeInput.value || defaultValue);
        });
      }

      try {
        const picker = PickrApi.create({
          el: trigger,
          theme: 'nano',
          default: initialValue,
          useAsButton: true,
          comparison: false,
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

        picker.on('init', (instance) => {
          const color = instance.getColor();
          sync(color && typeof color.toHEXA === 'function' ? color.toHEXA().toString() : initialValue);
        });

        picker.on('change', (color) => {
          if (color && typeof color.toHEXA === 'function') {
            sync(color.toHEXA().toString());
          }
        });

        picker.on('save', (color, instance) => {
          if (color && typeof color.toHEXA === 'function') {
            sync(color.toHEXA().toString());
          }
          instance.hide();
        });

        picker.on('clear', (instance) => {
          sync(defaultValue);
          instance.hide();
        });

        input.addEventListener('input', () => {
          const typed = String(input.value || '').trim();
          if (/^#([0-9a-f]{3}|[0-9a-f]{6})$/i.test(typed)) {
            sync(typed);
            picker.setColor(typed, true);
          }
        });

        input.dataset.lcPickrReady = '1';
      } catch (_err) {
        if (nativeInput) {
          trigger.addEventListener('click', () => nativeInput.click());
        }
      }
    });
  };

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', boot, { once: true });
  } else {
    boot();
  }
})();
