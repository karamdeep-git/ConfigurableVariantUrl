# Kinex_ConfigurableVariantUrl

Makes a simple product's own SEO URL (e.g. `/erika-running-short-29-green.html`) work as
an alternate entry point to its parent configurable product's PDP, with the matching
options pre-selected — without changing the simple product's Visibility away from
**Not Visible Individually**, and without a real simple-product PDP ever rendering.

## Behavior

- **`/product/shirt.html`** — normal configurable PDP, unchanged.
- **Customer selects Red/Small** — browser address bar updates to
  `/red-shirt-small.html` via `history.pushState` (no reload). Changing to Blue/Small
  updates it again. Back/Forward re-syncs both the URL and the visual selection.
- **Customer opens `/red-shirt-small.html` directly** — the configurable PDP for
  "Shirt" loads, with Red/Small already selected. The simple product is never loaded
  as its own page.
- Simple products stay Visibility = Not Visible Individually. Nothing about them
  changes in the admin.

## How it works

1. **URL routing** — a custom `url_rewrite` row is generated per eligible simple
   product, under a dedicated `entity_type` (`kinex_configurable_variant`) so core's
   own visibility-driven rewrite generate/delete logic never touches or collides with
   it. Its `target_path` encodes both ids:
   `catalog/product/view/id/{parentId}/sp_id/{childId}`. Magento's own router already
   parses trailing path segments as key/value params (the same mechanism it uses for
   `.../category/45`), so the controller loads the **parent** directly — there's no
   moment where the simple product is loaded as a PDP, so no controller change was
   needed at all.
2. **Data to the frontend** — a plugin on
   `Magento\ConfigurableProduct\Block\Product\View\Type\Configurable::getJsonConfig()`
   adds two keys to the same JSON payload the storefront JS already receives:
   `childUrls` (child product id → its alias URL) and `preselectedChildId` (the child
   id the request resolved through, validated against this parent's own option map
   before being trusted).
3. **Frontend** — this store uses `Magento_Swatches`, which replaces
   `Magento_ConfigurableProduct`'s own widget entirely with `mage.SwatchRenderer`. A
   RequireJS mixin on that widget:
   - extends its existing `_getSelectedAttributes()` hook (already wired to its own
     `_EmulateSelected()` pre-selection call) with our `preselectedChildId` data, so
     direct URL loads show the right options selected using the widget's own native
     mechanism — no selection logic was duplicated.
   - after a selection completes (`_OnClick`/`_OnChange`, once `getProductId()`
     resolves to a single product), pushes that product's URL via
     `history.pushState`.
   - a `popstate` listener re-applies the matching selection on Back/Forward.
   - a second mixin exists for `Magento_ConfigurableProduct/js/configurable` (the
     plain-dropdown widget) for stores/products that don't use swatches.

## Files

```
Kinex/ConfigurableVariantUrl/
├── registration.php
├── etc/
│   ├── module.xml
│   ├── events.xml                  # catalog_product_save_after / catalog_product_delete_after
│   ├── di.xml                      # plugin on ConfigurableProduct's SaveHandler
│   └── frontend/di.xml             # plugin on the Configurable block's getJsonConfig()
├── Model/
│   └── VariantUrlRewriteResolver.php   # owns entity_type + row generation/lookup/cleanup
├── Observer/
│   ├── ProductSaveAfter.php         # keeps a simple product's own alias row in sync
│   └── ProductDeleteAfter.php       # cleans up on delete
├── Plugin/
│   ├── RegenerateChildUrlsAfterConfigurableSave.php  # regenerates children's rows when a configurable's variant list changes
│   └── AddVariantDataToConfigurableJsonConfig.php    # adds childUrls + preselectedChildId to spConfig
└── view/frontend/
    ├── requirejs-config.js
    └── web/js/
        ├── swatch-renderer-variant-url-sync-mixin.js   # active widget for this store
        └── variant-url-sync-mixin.js                   # fallback for non-swatch configurables
```

No core files modified. No database schema changes (reuses the existing `url_rewrite`
table with a new `entity_type` value).

## Safety / fail-open design

Every hook (both plugins, both observers, and the JS) wraps its logic in try/catch and
falls back to doing nothing rather than letting a failure surface as a broken save,
delete, or page render. The underlying Magento operation (save/delete/render) always
completes correctly even if our logic hits an unexpected error — confirmed this
explicitly since it was a hard requirement (existing configurable/swatch/cart/wishlist
functionality must never break).

## Bugs found and fixed during implementation

- **PHP opcache staleness (Apache)** — code edits weren't always reflected across
  Apache's worker processes. Fixed by running `apachectl graceful` after PHP changes.
- **Stale published static files** — once Magento publishes a JS file to
  `pub/static/`, it serves that exact copy on every future request without
  rechecking the source. After editing JS, the published copies had to be deleted
  (`find pub/static -path "*Kinex_ConfigurableVariantUrl*" -delete`) to force
  regeneration.
- **Wrong widget targeted (the main bug)** — this store uses `Magento_Swatches`,
  which completely replaces `Magento_ConfigurableProduct`'s block/template and JS
  widget. The initial mixin targeted `mage.configurable`, which is never
  instantiated here. Fixed by writing a second mixin targeting
  `Magento_Swatches/js/swatch-renderer` (`mage.SwatchRenderer`) instead.
- **`mappedAttributes` vs `attributes`** — `SwatchRenderer._init()` reindexes
  `jsonConfig.attributes` into a plain array (via underscore's `_.sortBy`) as part of
  its own normal startup, losing the attribute-id-keyed structure. The original,
  id-keyed copy survives under `jsonConfig.mappedAttributes` (the same property the
  widget's own internal `_getAttributeCodeById()` reads from). The mixin now reads
  from `mappedAttributes` first.
- **ngrok free-tier browser interstitial** — real browser testing initially saw
  ngrok's "You are about to visit..." warning page instead of the site; `curl`
  bypasses it but a real browser doesn't. Fixed by sending the
  `ngrok-skip-browser-warning` header in test tooling.

## Verified (Playwright, real Chromium, on `erika-running-short` / SKU WSH12)

- Direct variant URL → parent PDP renders, Size and Color both show selected,
  matching the URL.
- Selecting Size then Color → address bar updates to the matching variant URL only
  once the selection is unambiguous; changing Color again updates it further.
- Browser Back → URL and visual selection both revert correctly, no page reload.
- No JS errors from this module (one pre-existing, unrelated Braintree/PayPal
  console warning was observed, present regardless of this module).

## Known limitations / follow-ups

- **Existing catalog needs a one-time backfill.** Alias rows are only generated on
  product save going forward; products saved before this module was installed have
  no alias row yet. Not yet run.
- **Non-swatch configurable products** (plain dropdowns, no color/size swatches) use
  the other mixin (`variant-url-sync-mixin.js`), which is logically consistent with
  the verified swatch path but hasn't itself been browser-tested end-to-end.
- **Multi-parent products** — if a simple product is linked to more than one
  configurable, the resolver currently picks the first parent found and logs a
  warning. No business rule has been defined for this case yet.
- **Canonical/SEO strategy** — canonical tag currently defaults to the parent
  configurable's URL (the free, zero-code behavior). Self-canonicalizing variant
  URLs instead is possible but costs more (the relevant core method is `private`,
  not plugin-able) — not needed unless the SEO team specifically wants it.
- **Deleting a configurable parent** doesn't cascade-clean its former children's
  alias rows; a stale alias row then 404s cleanly via core's normal noroute handling
  (same as any dangling product URL) rather than causing an error.
- Magento cache was disabled on this environment for debugging
  (`bin/magento cache:enable` before going live).

## Before deploying to a live environment

1. `bin/magento cache:enable`
2. Run the backfill for existing products (script not yet written — ask if needed).
3. Test on at least one non-swatch configurable product if the catalog has any.
4. Deploy to staging first, not directly to production.
