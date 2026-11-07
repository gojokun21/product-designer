# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## What this is

A WooCommerce plugin (`Product Designer for WooCommerce`) that lets shoppers personalize products (apparel / print-on-demand) with a Fabric.js editor. UI strings are in Romanian (text domain `product-designer`).

Runs inside a Local by Flywheel WordPress install at `app/public/wp-content/plugins/product-designer/`.

**Frontend is a Vue 3 + Vite app** (since the migration off vanilla JS). Source lives in `src/`; the build outputs hashed bundles to `assets/dist/` with a Vite `manifest.json`. The PHP `ProductDesigner\Core\Vite` helper (`includes/core/class-vite.php`) reads the manifest and enqueues the right JS/CSS. **After editing anything under `src/` you must rebuild**: `npm run build` (or `npm run dev` for HMR — gate it with `define( 'PD_VITE_DEV', true )` in `wp-config.php`). The PHP backend (REST, cart, order, admin) is still vanilla PHP with no Composer/build.

- Two Vite entries share one editor: `src/main-product.js` (Flow A, mounts `#pd-designer-app`) and `src/main-constructor.js` (Flow B, mounts `#pd-constructor-app`). The Fabric core lives in `src/composables/useFabric.js`; the wizard state in `src/stores/wizard.js`.
- Fabric.js is an npm dep (`fabric@5.3.0`), bundled by Vite — no longer vendored as a global.
- The CSS (`designer.css`, `constructor.css`) is moved to `src/styles/` and imported into the build; it keeps the original class names because the responsive/mobile-sheet rules are coupled to Fabric's generated DOM and global `html` classes.
- The cart→order DOM contract is preserved: `class-frontend.php` still server-renders the five hidden `pd_*` inputs inside `form.cart`; `src/bridge/cartBridge.js` writes them and keeps the jQuery `adding_to_cart` / `found_variation` / `reset_data` hooks. `jquery` stays a script dep for this reason.

Requires WooCommerce active — `product-designer.php` short-circuits with an admin notice otherwise. HPOS (custom order tables) compatibility is declared.

## Autoloader convention (read before adding a class)

`includes/class-autoloader.php` is a custom PSR-4-ish loader. The mapping is opinionated and breaks if you don't follow it:

- `ProductDesigner\Foo\Bar_Baz` → `includes/foo/class-bar-baz.php`
- Underscores in the short class name become hyphens; the whole filename is lowercased.
- Interfaces (`Interface_X` prefix or `_Interface` suffix) use `interface-` prefix instead of `class-`.

Sub-namespaces map to lowercased subdirectories (`Admin`, `Api`, `Core`, `Frontend`, `Woocommerce`). When adding a new file, create it in the matching subdir and the loader picks it up automatically — no registration needed.

## Boot sequence

1. `product-designer.php` — defines constants (`PD_VERSION`, `PD_REST_NS = 'product-designer/v1'`, `PD_UPLOAD_SUBDIR = 'product-designer'`, `PD_CONSTRUCTOR_SLUG`, `PD_CONSTRUCTOR_SHORTCODE`), registers autoloader + activation/deactivation hooks, declares HPOS compatibility.
2. `Plugin::boot()` (in `includes/class-plugin.php`) is the single composition root. It builds three shared singletons (`Validator`, `Image_Handler`, `Design_Storage`) and wires every module: `Templates`, `Product_Metabox`, `Templates_Admin`, `Order_Admin`, `Admin`, `Frontend`, `Constructor_Shortcode`, `Cart`, `Order`, `Rest_Api`, `Rest_Constructor`. **All wiring lives here** — do not call `register()` from anywhere else.
3. `Activator::activate()` seeds `pd_settings` defaults and creates the upload dir with an `.htaccess`/`index.php` guard. It also calls `Page_Installer::install()`.
4. `admin_init` re-runs `Page_Installer::install()` defensively if the Constructor page (option `pd_constructor_page_id`) is missing — covers code-update upgrades (no re-fire of activation hook) and accidental admin deletion. **Do not assume activation alone is enough** for new install-time state.

## Two parallel customer flows

Both flows share the same Fabric.js editor (`assets/js/designer.js`), the same REST `/upload` + `/design` endpoints, and the same `Design_Storage`. They differ in entry point and orchestration.

**Flow A — single product page** (`Frontend` + `class-frontend.php`):
- "Personalizează" button injected before the WC add-to-cart button on any product where `_pd_enabled = yes`.
- `wp_localize_script('pd-designer', 'PDData', ...)` ships the bootstrap on page load.
- `designer.js` auto-mounts when `PDData` is present.

**Flow B — Constructor wizard** (`Constructor_Shortcode` + `assets/js/constructor.js` + `templates/constructor-shell.php`):
- Shortcode `[pd_constructor]` rendered by an auto-created page at slug `constructor` (managed by `Page_Installer`). The shortcode only works inside `is_singular()`.
- 3-step wizard: category → product → designer. `constructor.js` calls REST endpoints under `/constructor/*` to populate categories/products/variations dynamically, then dispatches `pd:mount` event with a `PDData` payload to remount `designer.js` per-product.
- Categories / products shown here are *templates*, not regular shop products (see "Templates layer" below).

When modifying `designer.js`, remember it must work in **both** modes: auto-boot from window-level `PDData` (Flow A) and re-mount on `pd:mount` event with fresh selectors (Flow B replaces the modal DOM each time the user switches product). `cacheSelectors()` is re-run each boot for this reason.

## Templates layer

Templates are regular WC products with two markers:
- `_pd_is_template = yes` (post meta, see `Templates::META_IS_TEMPLATE`)
- A term in custom taxonomy `pd_template_cat` (see `Templates::TAXONOMY`)

The `Templates` class hides them from the shop frontend and from the standard `edit.php?post_type=product` admin list via `pre_get_posts` meta-query merging. They have their own admin UI under **Product Designer → Templates** (`Templates_Admin`). The "Add new template" button creates an auto-draft pre-filled with `_pd_is_template`, `_pd_enabled`, and the `exclude-from-catalog` / `exclude-from-search` `product_visibility` terms. `save_template_meta` re-asserts catalog exclusion on every save.

Override the template list view with `?pd_show_templates=1`.

## Multilingual (Polylang)

`ProductDesigner\Core\I18n_Polylang` (`includes/core/class-i18n-polylang.php`) is the single
Polylang bridge; everything in it is a no-op when Polylang is absent. It is registered
**first** in `Plugin::boot()` because its `pll_copy_*` filters must be in place before
Polylang copies content into a new translation.

Four Polylang facts the plugin has to work around — check these before changing anything here:

- **Protected metas are never copied.** `PLL_Sync_Post_Metas::get_metas_to_copy()` drops every
  key that passes `is_protected_meta()`, i.e. everything starting with `_`. All of `_pd_*`
  qualifies. `I18n_Polylang::product_metas()` is the explicit copy list — **add any new `_pd_*`
  product meta to it**, otherwise translations silently lose that setting.
- **`pd_template_cat` is not in the default copy list** (it is `public => false`, so Polylang
  neither translates it nor shows it in Settings). It is added via `pll_copy_taxonomies`, and
  stays *untranslated on purpose*: the same terms are shared across languages so `tax_query`
  behaves identically. Category names are translated as Polylang strings instead — registered
  in admin via `pll_register_string`, read back through `I18n_Polylang::term_label()`.
- **REST requests only know their language from `?lang=`.** `PLL_REST_Request::set_language()`
  reads nothing else — not the referer, not the origin. `PLL_REST_Post::can_filter_query()`
  therefore filters our `WP_Query` only when that parameter is present. Both bootstraps ship
  `rest.lang`, `endpoint()` in `src/composables/useDesignApi.js` appends it to every call, and
  `Rest_Constructor::with_language()` falls back to an explicit language `tax_query` when it is
  missing — without that fallback the wizard lists every translation of every template.
- **Polylang does not filter `locale` in REST**, so `__()` would answer in the default language
  even for `?lang=ru`. `I18n_Polylang::switch_rest_locale()` switches it for
  `product-designer/v1` routes only, and restores it on `rest_post_dispatch`.

Two more spots depend on language and are easy to miss:

- `Design_Storage::config_source_id()` reads the designer config from whichever translation
  actually holds `_pd_enabled`, so a translation created outside Polylang (import, duplicate)
  still works. An explicit `_pd_enabled = 'no'` is respected.
- `_pd_mockup_by_color` is keyed by attribute-term slug, and PLLWC translates all `pa_*`
  taxonomies — a variation in another language carries a different slug for the same colour.
  The map is synced verbatim across translations (keys included), so its key language is not
  the product's language. `resolve_config_for_variation()` finds the key among all translations
  of the variation's term via `I18n_Polylang::match_term_slug()`.

`Page_Installer` keeps the canonical (default-language) page id in `pd_constructor_page_id` and
creates/links the missing translations on `admin_init`; `get_page_id( $lang )` resolves it.

Translations live in `languages/`: `product-designer.pot` is the full catalogue,
`product-designer-ru_RU.po/.mo` covers the customer-facing strings only — admin strings are
left empty on purpose so gettext falls back to the Romanian original.

## Cart → Order data flow (the load-bearing fragile bit)

The design must travel from REST save → cart line item → order item meta. There are **three independent pickup paths** because different add-to-cart mechanisms (classic form POST, AJAX, WooCommerce Blocks / Store API) deliver `$_POST` differently or not at all:

1. **`$_POST['pd_design_id']` (+ `pd_preview_url`, `pd_json_url`)** — hidden inputs in the classic add-to-cart form. Read by `Cart::capture_design()` and `Cart::validate_cart_add()`.
2. **WC Session key `pd_design_for_<product_id>`** — written by `Rest_Api::handle_save_design()` immediately after the design persists (it bootstraps WC session if not already initialized in REST context, then calls `WC()->session->save_data()` to flush to DB without waiting for shutdown). Read as fallback by `Cart` and `Order`.
3. **Final fallback inside `Order::transfer_to_order()`** — at order creation, if the cart item has no `pd_design` array, re-read from session keyed by `product_id`.

When debugging "design didn't make it to the order", check `wp-content/debug.log` — both `Rest_Api`, `Cart`, and `Order` emit `[Product Designer]` lines under `WP_DEBUG`. The diagnostic in `Order_Admin::render_itemmeta()` shows a "missing design notice" for any order item whose product has the designer enabled but no `_pd_design_id` — that's the visible signal of a broken transfer.

Cart items get a `unique_key` (md5 of design_id) so two of the same product with different designs become separate line items.

## Design persistence model

`Design_Storage::persist_submission()` writes two files to `wp-uploads/product-designer/<design_id>.{json,png}`:
- JSON = Fabric.js canvas serialization
- PNG = base64 preview from `data:image/png;base64,...` data URI

Order/cart meta stores **only** the design id + URLs (`ITEM_META_DESIGN_ID`, `ITEM_META_PREVIEW_URL`, `ITEM_META_JSON_URL`) — never the JSON itself, to keep DB rows small. The uploads dir is intentionally **not** deleted by `uninstall.php` (preserves order history).

`design_id` is `pd-<product_id>-<16char_random>`. `Image_Handler::design_paths()` strips anything that isn't `[A-Za-z0-9_-]` before joining paths — do not bypass this when constructing paths from request input.

## REST API

Two route classes, both registered under namespace `PD_REST_NS` (`product-designer/v1`):

- `Rest_Api` — write endpoints. `POST /upload` and `POST /design` require a valid `X-WP-Nonce` for `wp_rest` action (guests included — checked in `Validator::verify_rest_nonce`). `GET /design/<id>` requires `manage_woocommerce`.
- `Rest_Constructor` — read endpoints for the constructor wizard. `__return_true` permission (public, no nonce) — they only expose template metadata that's visible in the shop anyway.

Validator enforces 512 KB JSON cap and 4 MB preview cap on `/design`. `Image_Handler` enforces MIME allowlist + `getimagesize()` second check + `is_uploaded_file()` on `/upload`.

## Settings storage

- `pd_settings` (option) — `max_upload_size_mb`, `allowed_mime_types`, `canvas_width`, `canvas_height`. Read via `Validator::settings()`.
- `pd_constructor_page_id` (option) — auto-installed Constructor page. Owned by `Page_Installer`.
- `pd_constructor_settings` (option) — currently only carries `title`. Free-form.
- Per-product meta — `_pd_enabled`, `_pd_mockup_image_id`, `_pd_custom_color` (opt-in for the free mockup-recolor picker; mockup must be white/light because the tint is a multiply blend). Per-variation override — `_pd_mockup_image_id` on the variation post.
- Custom mockup color flow — the editor tints the mockup client-side (Fabric `BlendColor` multiply filter), so the chosen color is baked into the preview PNGs. The hex travels REST `/design` (`mockup_color` param, accepted only when the product opted in) → design JSON (`mockup_color` root key) + WC session → `pd_mockup_color` hidden input / POST → cart item (`pd_design.mockup_color`) → order item meta `_pd_design_mockup_color` (internal) plus a customer-visible "Culoare produs" meta.
- Auto-tint from variation swatches — `Design_Storage::get_variation_term_colors()` builds `[attr → [term slug → hex]]` by applying the `pd_variation_term_color` filter per term (the THEME provides the hex from its swatch term meta, e.g. midy-template hooks it to `midy_swatch_color`); terms with a dedicated per-color mockup are skipped. Flow A ships the map as `PDData.product.variation_colors` and designer.js listens to `found_variation`/`reset_data`; Flow B resolves `auto_tint_color` server-side in `/constructor/product-config`. Both gated by the same `_pd_custom_color` opt-in.
- Per-template meta — `_pd_is_template`. Taxonomy `pd_template_cat`.
- Term meta — `pd_icon_id` (attachment id) for category cards in the constructor wizard.

## Conventions worth knowing

- All public-facing strings are Romanian; translation should target `product-designer` text domain (no `.po` files yet, so source-language only). Strings reach Vue via the `data-pd-config` JSON on the mount root (`i18n` key) or the `constructor/product-config` REST `i18n`.
- Frontend assets are enqueued via `Core\Vite::enqueue_entry()` (manifest-driven, hashed — no `filemtime`). `Admin` / `Templates_Admin` still use a local `asset_version()` filemtime helper for the **admin** CSS/JS, which is untouched by the Vue migration.
- Fabric.js is an npm dep bundled by Vite (`src/composables/useFabric.js` does `import { fabric } from 'fabric'`). The old vendored `assets/vendor/fabric.min.js` and the `pd-fabric` enqueue are gone. Pin stays in the v5 line (`5.3.0`) — the editor relies on the v5 API (`fabric.Image.filters.BlendColor`, `c.toJSON`, `c.wrapperEl`, `enableRetinaScaling`).
- `enableRetinaScaling: false` (now in `useFabric.js`) is a deliberate workaround for a Fabric 5.x bug where mouse coords miss objects on HiDPI screens. Do not re-enable. Several other porting notes in `useFabric.js` are bug fixes, not cleanups — see the file header.
- `templates/constructor-shell.php` is now just the Vue mount root (`#pd-constructor-app`), still overridable from the theme at `theme/product-designer/constructor-shell.php` — but only the root div matters now; the wizard UI is Vue.
- `DONOTCACHEPAGE` is defined in the Constructor template to keep page caches off the wizard.
- Editor UI is an Adobe-style workspace: full-height panels split by hairlines, a grey stage, one accent (`--pd-select`, the Fabric selection blue). Tokens live at the top of `src/styles/designer.css`, line icons in `src/components/editor/PdIcon.vue`. On desktop `.pd-canvas-wrap` fills the whole stage and Fabric's `.canvas-container` is the artboard — the wrap's padding (room for the info chips and the bottom dock) is read by `fitCanvasToWrap`. On mobile the wrap keeps `--pd-mockup-aspect`.
- The Constructor page renders inside the theme's `.description_content`, which styles `h3`, `p` and `img` at specificity (0,1,1). Editor rules for those elements need two classes (e.g. `.pd-tools .pd-panel-title`) or the theme wins.

## Debugging

Enable in `wp-config.php`:

```php
define( 'WP_DEBUG', true );
define( 'WP_DEBUG_LOG', true );
```

Then tail `wp-content/debug.log`. Cart/order/REST modules log under the `[Product Designer]` prefix. The most informative trace is `Rest_Api::handle_save_design` → `Cart::capture_design` → `Order::transfer_to_order` for the design-flow path.
