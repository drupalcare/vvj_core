# VVJ Core

Foundation module for the **VVJ Views renderer family** — required by every
`drupal/vvj*` pattern module v2.x.

| Module | Views format |
|--------|----------------|
| [VVJA](https://www.drupal.org/project/vvja) | Accordion |
| [VVJB](https://www.drupal.org/project/vvjb) | Basic carousel |
| [VVJC](https://www.drupal.org/project/vvjc) | 3D carousel |
| [VVJF](https://www.drupal.org/project/vvjf) | 3D flip box |
| [VVJH](https://www.drupal.org/project/vvjh) | Hero |
| [VVJL](https://www.drupal.org/project/vvjl) | Lightbox |
| [VVJP](https://www.drupal.org/project/vvjp) | Parallax |
| [VVJR](https://www.drupal.org/project/vvjr) | Reveal |
| [VVJS](https://www.drupal.org/project/vvjs) | Slideshow |
| [VVJT](https://www.drupal.org/project/vvjt) | Tabs |

VVJ Core is **not useful on its own** — it provides the abstract base class,
shared services, JS Custom Element base, and CSS contract that every VVJ pattern
module consumes. Composer auto-installs it when you add any VVJ pattern module.

---

## Installation

### Composer (recommended)

Install any pattern module — `vvj_core` is pulled automatically:

```bash
composer require drupal/vvja:^2.0
drush en vvja -y
drush cr
```

Multiple patterns:

```bash
composer require drupal/vvja:^2.0 drupal/vvjs:^2.0 drupal/vvjt:^2.0
drush en vvja vvjs vvjt -y
drush cr
```

### Manual download (drupal.org tarball)

1. Download and extract **`vvj_core`** to `modules/contrib/vvj_core`
2. Download pattern module(s) to `modules/contrib/`
3. Enable at `/admin/modules` — pattern modules require VVJ Core

**Do not** enable a VVJ pattern v2.x module without `vvj_core` on disk.

### How the JavaScript is shared

Pattern element JavaScript reaches `vvj_core`'s shared code through the
**`Drupal.Vvj` namespace** — Drupal core's own cross-file sharing pattern
(cf. `Drupal.Ajax`, `Drupal.Message`), **not** native ES module
`import`/`export`. `vvj_core` attaches the base class and helpers to
`Drupal.Vvj.*`; each pattern's `*.libraries.yml` lists the relevant
`vvj_core/*` libraries under `dependencies:`, which guarantees they load
first, and the pattern then reads them off the namespace:

```js
((Drupal) => {
  'use strict';
  const { ElementBase, handleArrowKeyNav } = Drupal.Vvj;
  class VvjaAccordionElement extends ElementBase { /* … */ }
  customElements.define('vvja-accordion', VvjaAccordionElement);
})(Drupal);
```

There are **no file paths, no import specifiers, no import map, and no build
step**. This is location-agnostic (survives subdirectory installs, custom
docroots, non-standard module paths) and aggregation-safe, because nothing in
the source resolves a URL. It matches how Drupal core itself shares JS and is
the pattern that has survived every Drupal version.

> Why not native ES modules? Drupal has no first-class cross-module ES
> `import` resolution yet — the core import-map API (issue
> [#3398525](https://www.drupal.org/project/drupal/issues/3398525)) is
> unmerged, and core ships zero `type: module` / `import` in hand-authored JS.
> Web Components (`customElements.define`, `class extends`) are still used —
> they're authored in the `Drupal.Vvj` namespace instead of via ESM `import`.

---

## Upgrading from VVJ 1.x

For sites on **Drupal 11.3+ (or 12)** and **PHP 8.3+** (the requirement each
module's `info.yml` enforces):

```bash
composer update drupal/vvja --with-dependencies
drush updb -y
drush cr
```

- Composer installs `drupal/vvj_core`
- Update hooks (`vvja_update_10001`, etc.) enable `vvj_core` automatically
- **No view config migration** for v2.0 — existing Views keep working

Upgrade **all** installed VVJ modules to 2.x together. Mixed 1.x + 2.x on one
site is not supported.

**Stay on 1.x** if you are on Drupal 10, 11.0–11.2, or PHP 8.1/8.2 — v2
requires Drupal 11.3+ and PHP 8.3+.

### Manual / tarball upgrade (no Composer)

Composer users get `vvj_core` automatically. If you install from drupal.org
tarballs, **you must download `vvj_core` yourself** — the pattern modules
require it and will not enable without it.

1. Download the new pattern tarball(s) **and** the `vvj_core` tarball.
2. Extract `vvj_core` to `modules/contrib/vvj_core` (keep it under the same
   parent as the pattern modules — see "How the JavaScript is shared" above).
3. Overwrite each pattern module with its 2.x files.
4. `drush updb -y` — the `*_update_10001()` hooks enable `vvj_core`.
5. `drush cr`.

If you cannot run `drush updb`, enable `vvj_core` manually first
(`drush en vvj_core -y` or via **Extend**), then clear caches.

### How the `vvj_core` requirement is handled

v2 adds a hard dependency on `vvj_core` (declared in every pattern's
`info.yml`). The upgrade is engineered so the standard `composer update` →
`drush updb` → `drush cr` flow "just works" across the window where the v2
code is on disk but `vvj_core` is not yet enabled:

- Each pattern ships a `*_update_10001()` update hook that enables `vvj_core`
  during `drush updb`.
- Pattern services inject `vvj_core` services **nullably** (`@?vvj_core.*`),
  so the container still compiles during that window instead of throwing a
  "service not found" error. Once `updb` enables `vvj_core` and rebuilds the
  container, the real services are injected.

You therefore never have to enable `vvj_core` by hand on a Composer upgrade —
but doing so early (before `updb`) is harmless.

### `drush vvj:upgrade` — migration audit

`vvj_core` ships a Drush command that scans every View using a VVJ style
plugin and reports option-key drift between the installed configuration and
the current schema:

```bash
drush vvj:upgrade --dry-run          # report only; make no changes
drush vvj:upgrade                    # report, then prompt to apply
drush vvj:upgrade --module=vvja,vvjs # limit the scan to specific patterns
```

Run it after updating a site from v1 to v2 to confirm every VVJ view is
recognized and on the current schema. Because the **v2.0 drop-in upgrade has
no breaking renames**, the command reports *"No migration needed — all VVJ
views are on the v2.0 schema."* for every existing v1 view. The scan/drift
logic lives in the Drush-independent
`\Drupal\vvj_core\Service\VvjUpgradeAuditor` service (unit tested); the
command is a thin wrapper.

### Verify the upgrade

After `drush cr`, confirm:

1. `drush pm:list --status=enabled | grep vvj` — `vvj_core` **and** every
   pattern module are enabled.
2. `drush vvj:upgrade --dry-run` — reports *"No migration needed"*.
3. Each existing VVJ view still renders on the front end (the wrapper element
   is now `<vvja-accordion>` etc.; every v1 CSS class is unchanged).
4. Interactions work in the browser (open/close, next/prev, play/pause…).
5. Turn **JS aggregation** on (`/admin/config/development/performance`) and
   re-check — shared code loads via the `Drupal.Vvj` namespace + library
   dependency order, so aggregation is safe.

---

## Troubleshooting

| Symptom | Fix |
|---------|-----|
| `Class "Drupal\vvja\Twig\VVJATwigExtension… | Stale cache after v2 code deploy
| Pattern enabled but format missing | Install and enable `vvj_core` |
| JS error loading `vvj-element-base.js` | Confirm `vvj_core` exists at `modules
| Composer did not install vvj_core | Pattern `composer.json` must require `drup
| `updb` errors before `vvj_core` is enabled | Enable it first: `drush en vvj_co
| Dependency error: pattern needs `vvj_core` | Manual install |

---

## What VVJ Core provides

### PHP

| Class / service | Purpose |
|---|---|
| `Drupal\vvj_core\Plugin\views\style\VvjSty… | Abstract Views Style plugin base
| `vvj_core.svg_sanitizer` | Whitelist-driven SVG sanitizer for admin-p… |
| `vvj_core.token_resolver` | `[vvjX:field]` and `[vvjX:field:plain]` to… |
| `vvj_core.breakpoint_registry` | Canonical breakpoint values + translatable… |
| `vvj_core.unique_id_generator` | `random_int`-based 8-digit unique IDs. |
| `vvj_core.twig_extension` | Registers the `safe_html` Twig filter . |
| `Drupal\vvj_core\Hook\VvjCorePreprocessHook` | Shared `preprocess_views_view`:
| `Drupal\vvj_core\Hook\VvjCoreHelpHook` | `hook_help` implementation |

### Constants enums (typed PHP 8.3 const classes)

| Class | Purpose |
|---|---|
| `Drupal\vvj_core\Constants\Breakpoints` | Canonical responsive breakpoint valu
| `Drupal\vvj_core\Constants\Easing` | CSS easing keywords . |
| `Drupal\vvj_core\Constants\AnimationDirection` | Animation presets . |
| `Drupal\vvj_core\Constants\ValidationBounds` | Min/max bounds, token pattern, 

### JS

| Module | Purpose |
|---|---|
| `js/vvj-element-base.js` | `VvjElementBase extends HTMLElement` |
| `js/vvj-keyboard-nav.js` | `handleArrowKeyNav` |
| `js/vvj-focus-trap.js` | `trapFocus` |
| `js/vvj-deeplink-bridge.js` | `wireDeeplink` + `writeDeeplinkHash` |
| `js/vvj-token-bridge.js` | `registerVvjBehavior` |

### CSS

| File | Purpose |
|---|---|
| `css/vvj-tokens.css` | APEX `--apex-*` and `--r-*` token bridge.… |
| `css/vvj-base.css` | Cascade-layer ladder , container-query set… |
| `css/vvj-a11y.css` | AAA-level focus indicators + visually-hidd… |

---

## What each pattern module adds (beyond vvj_core)

Every `drupal/vvj*` package layers these on top of the foundation (see each
module’s `README.md` for specifics):

| Surface | Typical contents |
|--------|------------------|
| `src/Hook/*ThemeHook.php` | `hook_theme` |
| `src/Hook/*PreprocessHooks.php` | Style preprocess + row/field preprocess vi… 
| `src/Hook/*TokenHooks.php` | `hook_token_info` / `hook_tokens` |
| `src/Hook/*HelpHook.php` | `hook_help` |
| `config/optional/views.view.*_example.yml` | Optional starter View . |

---

## How a pattern module consumes VVJ Core

Sketch — `modules/v2/vvja/src/Plugin/views/style/Accordion.php`:

```php
namespace Drupal\vvja\Plugin\views\style;

use Drupal\vvj_core\Plugin\views\style\VvjStylePluginBase;

/**
 * @ViewsStyle(
 *   id = "views_vvja",
 *   title = @Translation("Views Vanilla JavaScript Accordion"),
 *   theme = "views_view_vvja",
 *   display_types = { "normal" }
 * )
 */
class Accordion extends VvjStylePluginBase {

  public function getModuleSlug(): string { return 'vvja'; }
  public function getCustomElementTag(): string { return 'vvja-accordion'; }

  protected function definePatternOptions(array $options): array {
    $options['single_toggle'] = ['default' => TRUE];
    $options['global_toggle'] = ['default' => TRUE];
    // ... rest of pattern-specific options
    return $options;
  }

  protected function buildPatternSections(array &$form): void {
    // Pattern-specific sections (Behavior, Animation, Layout, Custom Icons).
    $form['behavior_section'] = [...];
    // VvjStylePluginBase adds: warning message, advanced (enable_css),
    // deep linking, token documentation — automatically.
  }

}
```

That's typically ~250 LOC vs the ~760 LOC the v1 plugin needed.

Sketch — `modules/v2/vvja/js/vvja-accordion-element.js`:

```javascript
// The vvja library declares:
//   dependencies: [vvj_core/element-base, vvj_core/keyboard-nav,
//                  vvj_core/deeplink-bridge]
// so Drupal.Vvj.* is populated before this file runs.
((Drupal) => {
  'use strict';

  const {
    ElementBase, handleArrowKeyNav, wireDeeplink, writeDeeplinkHash,
  } = Drupal.Vvj;

  class VvjaAccordionElement extends ElementBase {
    static get patternSlug() { return 'vvja'; }

    onHydrate() {
      const triggers = this.querySelectorAll('.vvja-button');
      triggers.forEach((t, i) => {
        t.addEventListener(
          'click', () => this._togglePanel(t), { signal: this.signal }
        );
        t.addEventListener(
          'keydown', (e) => handleArrowKeyNav(e, triggers, i),
          { signal: this.signal }
        );
      });

      if (this.dataset.deeplinkId) {
        wireDeeplink(
          this, 'accordion', this.dataset.deeplinkId, this.signal, (n) => {
            this._openPanel(n);
          }
        );
      }
    }

    _togglePanel(trigger) {
      this.withTransition(() => {
        // toggle classes / aria attributes
        this.emit('toggle', { trigger });
      });
    }
  }

  customElements.define('vvja-accordion', VvjaAccordionElement);

})(Drupal);
```

---

## Drop-in upgrade contract

Every VVJ v2 module — including this foundation module — preserves the v1
contract surfaces:

- Plugin IDs: `views_vvja`, `views_vvjb`, etc.
- Theme hooks: `views_view_vvja`, etc.
- Twig template names
- All option keys per pattern
- CSS class names rendered on HTML
- Library names: `vvja/vvja`, `vvja/vvja-style`, `vvja/vvja-admin`
- JS behavior keys: `Drupal.behaviors.VVJAccordion`, etc.
- Public JS APIs: `Drupal.vvja.openPanel(...)`, etc.

`vvj_core` is the **only** new dependency added in v2. Composer resolves it
automatically.

The drop-in contract preserves all v1 plugin IDs, theme hooks, Twig template
names, library names, option keys, CSS classes on rendered HTML, and public
`Drupal.vvj*` JavaScript APIs.

---

## Requirements

- Drupal `^11.3 || ^12`
- PHP `>=8.3`
- `core/views`, `core/filter`

---

## License

GPL-2.0-or-later. See [LICENSE.txt](./LICENSE.txt).

## Maintainer

Alaa Haddad —
[drupal.org/u/flashwebcenter](https://www.drupal.org/u/flashwebcenter)

Issues:
[drupal.org/project/issues/vvj_core](https://www.drupal.org/project/issues/vvj_core)
