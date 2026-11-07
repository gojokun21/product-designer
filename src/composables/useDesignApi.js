/**
 * Build the endpoint URL, carrying the Polylang language along.
 *
 * Polylang resolves the language of a REST request *only* from the `lang` query
 * parameter (PLL_REST_Request::set_language) — it never infers it from the
 * referer. Without it every call from /ru/ would run in the default language:
 * the wizard would list the Romanian templates and pll__() would return the
 * Romanian category names.
 *
 * @param {object} rest  config.rest = { root, nonce, lang, ... }
 * @param {string} path  Endpoint path relative to the namespace root.
 * @param {object} [params]
 * @returns {URL}
 */
function endpoint(rest, path, params) {
  const url = new URL(rest.root + path, window.location.origin);
  if (rest.lang) {
    url.searchParams.set('lang', rest.lang);
  }
  if (params) {
    Object.keys(params).forEach((k) => {
      if (params[k] !== undefined && params[k] !== null) {
        url.searchParams.set(k, params[k]);
      }
    });
  }
  return url;
}

/**
 * REST client for the editor: image upload + design save.
 * Mirrors the contract from designer.js (POST /upload, POST /design) with the
 * X-WP-Nonce header. Plain fetch — no jQuery.
 *
 * @param {object} rest  config.rest = { root, nonce, lang, uploadPath, designPath }
 */
export function useDesignApi(rest) {
  async function uploadImage(file) {
    const fd = new FormData();
    fd.append('file', file);
    const res = await fetch(endpoint(rest, rest.uploadPath).toString(), {
      method: 'POST',
      headers: { 'X-WP-Nonce': rest.nonce },
      body: fd,
    });
    const data = await res.json().catch(() => null);
    if (!res.ok) {
      throw new Error((data && data.message) || 'HTTP ' + res.status);
    }
    if (!data || !data.url) {
      throw new Error('invalid-response');
    }
    return data;
  }

  async function saveDesign(payload) {
    const res = await fetch(endpoint(rest, rest.designPath).toString(), {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        'X-WP-Nonce': rest.nonce,
      },
      body: JSON.stringify(payload),
    });
    const data = await res.json().catch(() => null);
    if (!res.ok) {
      throw new Error((data && data.message) || 'HTTP ' + res.status);
    }
    return data;
  }

  return { uploadImage, saveDesign };
}

/**
 * Generic GET for the constructor REST endpoints (public, but we still send the
 * nonce — harmless). Returns parsed JSON or throws.
 */
export async function apiGet(rest, path, params) {
  const url = endpoint(rest, path, params);
  const res = await fetch(url.toString(), {
    method: 'GET',
    headers: { 'X-WP-Nonce': rest.nonce },
  });
  const data = await res.json().catch(() => null);
  if (!res.ok) {
    throw new Error((data && data.message) || 'HTTP ' + res.status);
  }
  return data;
}
