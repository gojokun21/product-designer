/**
 * Cart bridge (Flow A only) — owns the jQuery / server-rendered-DOM contract so
 * the Vue app stays clean. Ported from designer.js:1262-1288, 1146-1167.
 *
 * The five hidden inputs (`.pd-design-id` etc.) stay server-rendered inside
 * `form.cart`; Vue writes their values through `writeHidden`. The theme does
 * AJAX add-to-cart, so the `adding_to_cart` jQuery hook is load-bearing.
 *
 * The `.pd-frontend-wrap` block has three states (`data-state`):
 *   empty — no design yet: the add-to-cart button is dimmed and opens the editor;
 *   saved — a design is attached to the add-to-cart;
 *   stale — the design was saved for another print size and must be re-saved.
 *
 * @param {object} cb
 *   cb.onRequireDesign() — called when the form is submitted with no saved design
 *   cb.onVariation(variation) — WC found_variation (object with variation_id + attributes)
 *   cb.onVariationReset()     — WC reset_data (restore default mockup)
 *   cb.onClear()              — user clicked "Elimină design"
 * @param {object} i18n  config.i18n (customize, editDesign, needDesign, staleDesign)
 */
export function installCartBridge(cb = {}, i18n = {}) {
  const $ = window.jQuery;
  const get = (sel) => document.querySelector(sel);
  const val = (sel) => {
    const el = get(sel);
    return el ? el.value : '';
  };

  // The theme adds the second-side price to its "Total" line.
  function announceExtraPrice(amount) {
    document.dispatchEvent(new CustomEvent('pd:extra-price', { detail: { amount: amount || 0 } }));
  }

  function setState(state) {
    const wrap = get('.pd-frontend-wrap');
    if (!wrap) {
      return;
    }
    wrap.setAttribute('data-state', state);
    const form = wrap.closest('form');
    if (form) {
      form.classList.toggle('pd-needs-design', state !== 'saved');
    }
    const label = wrap.querySelector('.pd-open-designer__label');
    if (label) {
      label.textContent =
        state === 'saved' ? i18n.editDesign || 'Editează designul' : i18n.customize || 'Personalizează';
    }
    const hint = wrap.querySelector('.pd-frontend-hint');
    if (hint) {
      hint.hidden = state === 'saved';
      hint.textContent =
        state === 'stale'
          ? i18n.staleDesign || ''
          : i18n.needDesign || 'Creează-ți designul, apoi îl poți adăuga în coș.';
    }
  }

  /**
   * @param {object} res   REST save response (design_id, preview_url, ...)
   * @param {object} info  { sides: 'Față + spate (+75 lei)', extra: 75 }
   */
  function writeHidden(res, info = {}) {
    const set = (sel, v) => {
      const el = get(sel);
      if (el) {
        el.value = v || '';
      }
    };
    set('.pd-design-id', res.design_id);
    set('.pd-preview-url', res.preview_url);
    set('.pd-preview-back-url', res.preview_back_url);
    set('.pd-json-url', res.json_url);
    set('.pd-mockup-color-value', res.mockup_color);

    const prev = get('.pd-selected-preview');
    if (prev) {
      prev.hidden = !(res.preview_url || res.preview_back_url);
      prev.querySelectorAll('.pd-selected-preview__img').forEach((img) => {
        const url = img.getAttribute('data-side') === 'back' ? res.preview_back_url : res.preview_url;
        img.hidden = !url;
        img.src = url || '';
      });
      const sides = prev.querySelector('.pd-selected-preview__sides');
      if (sides) {
        sides.textContent = info.sides || '';
      }
    }
    setState('saved');
    announceExtraPrice(info.extra);
  }

  function clearHidden(state = 'empty') {
    ['.pd-design-id', '.pd-preview-url', '.pd-preview-back-url', '.pd-json-url', '.pd-mockup-color-value'].forEach(
      (sel) => {
        const el = get(sel);
        if (el) {
          el.value = '';
        }
      }
    );
    const prev = get('.pd-selected-preview');
    if (prev) {
      prev.hidden = true;
      prev.querySelectorAll('.pd-selected-preview__img').forEach((img) => {
        img.hidden = true;
        img.src = '';
      });
    }
    setState(state);
    announceExtraPrice(0);
  }

  setState('empty');

  // "Elimină design" button (server-rendered).
  document.addEventListener('click', (e) => {
    const btn = e.target.closest && e.target.closest('.pd-clear-design');
    if (!btn) {
      return;
    }
    e.preventDefault();
    clearHidden();
    if (cb.onClear) {
      cb.onClear();
    }
  });

  // No design → no add-to-cart: open the editor instead. Capture phase on the
  // document runs before the theme's own submit listener on the form (AJAX
  // add-to-cart), and stopping here keeps the event from ever reaching it.
  document.addEventListener(
    'submit',
    (e) => {
      const form = e.target;
      if (!form || !form.querySelector || !form.querySelector('.pd-design-id') || val('.pd-design-id')) {
        return;
      }
      e.preventDefault();
      e.stopPropagation();
      if (cb.onRequireDesign) {
        cb.onRequireDesign();
      }
    },
    true
  );

  if ($) {
    // AJAX add-to-cart (theme path): inject pd_* fields.
    $(document.body).on('adding_to_cart', function (event, $button, data) {
      const id = val('.pd-design-id');
      if (!id) {
        return;
      }
      data.pd_design_id = id;
      data.pd_preview_url = val('.pd-preview-url');
      data.pd_preview_back_url = val('.pd-preview-back-url');
      data.pd_json_url = val('.pd-json-url');
      data.pd_mockup_color = val('.pd-mockup-color-value');
    });

    // Swap the editor mockup to the chosen variation's per-color image.
    $(document.body).on('found_variation', 'form.variations_form', function (e, variation) {
      if (!variation) {
        return;
      }
      if (cb.onVariation) {
        cb.onVariation(variation);
      }
    });
    $(document.body).on('reset_data', 'form.variations_form', function () {
      if (cb.onVariationReset) {
        cb.onVariationReset();
      }
    });
  }

  return { writeHidden, clearHidden };
}
