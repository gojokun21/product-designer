# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## What this is

A WooCommerce plugin (`Product Designer for WooCommerce`) that lets shoppers personalize products (apparel / print-on-demand) with a Fabric.js editor. Vanilla PHP plugin — no Composer, no build step, no test suite. UI strings are in Romanian (text domain `product-designer`).

Runs inside a Local by Flywheel WordPress install at `app/public/wp-content/plugins/product-designer/`. There is nothing to build or compile: edit PHP / JS / CSS, then reload the page. Asset URLs use `filemtime()` for cache-busting (`asset_version()` helper in each enqueueing class), so changes are picked up immediately without bumping `PD_VERSION`.

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
- Per-product meta — `_pd_enabled`, `_pd_mockup_image_id`. Per-variation override — `_pd_mockup_image_id` on the variation post.
- Per-template meta — `_pd_is_template`. Taxonomy `pd_template_cat`.
- Term meta — `pd_icon_id` (attachment id) for category cards in the constructor wizard.

## Conventions worth knowing

- All public-facing strings are Romanian; translation should target `product-designer` text domain (no `.po` files yet, so source-language only).
- `Frontend`, `Admin`, `Templates_Admin`, `Constructor_Shortcode` all reimplement the same `asset_version( $relative_path )` helper that returns `filemtime()` with fallback to `PD_VERSION`. If you add a new enqueueing class, copy that helper rather than relying on `PD_VERSION` directly.
- Fabric.js is **vendored** at `assets/vendor/fabric.min.js` (5.3.1) — not loaded from CDN. Reason: works offline (Local by Flywheel) and not subject to CDN outages. Don't switch back to CDN.
- `enableRetinaScaling: false` in `designer.js` is a deliberate workaround for a Fabric 5.x bug where mouse coords miss objects on HiDPI screens. Do not re-enable.
- The constructor template `templates/constructor-shell.php` is overridable from the active theme at `theme/product-designer/constructor-shell.php` (handled by `Constructor_Shortcode::render()` via `locate_template`).
- `DONOTCACHEPAGE` is defined in the Constructor template to keep page caches off the wizard.

## Debugging

Enable in `wp-config.php`:

```php
define( 'WP_DEBUG', true );
define( 'WP_DEBUG_LOG', true );
```

Then tail `wp-content/debug.log`. Cart/order/REST modules log under the `[Product Designer]` prefix. The most informative trace is `Rest_Api::handle_save_design` → `Cart::capture_design` → `Order::transfer_to_order` for the design-flow path.
