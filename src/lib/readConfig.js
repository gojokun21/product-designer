/**
 * Read the JSON config a PHP mount root carries in `data-pd-config`.
 * Returns {} on a missing/invalid attribute so callers can fail soft.
 *
 * @param {HTMLElement} el
 * @returns {object}
 */
export function readConfig(el) {
  if (!el || !el.dataset || !el.dataset.pdConfig) {
    return {};
  }
  try {
    return JSON.parse(el.dataset.pdConfig);
  } catch (err) {
    if (window.console) {
      console.error('[PD] data-pd-config is not valid JSON:', err);
    }
    return {};
  }
}
