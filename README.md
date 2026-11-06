# VVJ Core

Foundation module for the **VVJ Views renderer family**: required by every
`drupal/vvj*` pattern module v2.x.

- Project page:
  [drupal.org/project/vvj_core](https://www.drupal.org/project/vvj_core)
- Issue queue:
  [drupal.org/project/issues/vvj_core](https://www.drupal.org/project/issues/vvj_core)


## Table of contents

- Overview
- Requirements
- Installation
- Configuration
- Usage
- Patterns
- Upgrading from VVJ 1.x
- Troubleshooting
- Changelog
- Maintainers
- License


## Overview

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

VVJ Core is **not useful on its own**. It provides the abstract base class,
shared services, JS Custom Element base, and CSS contract that every VVJ pattern
module consumes. Composer auto-installs it when you add any VVJ pattern module.


## Requirements

- Drupal core `^11.3 || ^12`.
- PHP 8.3 or later.
- Drupal core modules Views and Filter.
- The optional `vvj_core_ai` sub-module also needs `drupal/ai` and supports
  Drupal `^11.3` only.


## Installation


### Composer (recommended)

Install any pattern module. `vvj_core` is pulled automatically:

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
1. Download pattern module(s) to `modules/contrib/`
1. Enable at `/admin/modules`. Pattern modules require VVJ Core

**Do not** enable a VVJ pattern v2.x module without `vvj_core` on disk.


### How the JavaScript is shared

Pattern element JavaScript reaches `vvj_core`'s shared code through the
**`Drupal.Vvj` namespace**: Drupal core's own cross-file sharing pattern
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
> `import` resolution yet. The core import-map API (issue
> [#3398525](https://www.drupal.org/project/drupal/issues/3398525)) is
> unmerged, and core ships zero `type: module` / `import` in hand-authored JS.
> Web Components (`customElements.define`, `class extends`) are still used —
> they're authored in the `Drupal.Vvj` namespace instead of via ESM `import`.


## Configuration

VVJ Core has no settings page of its own. Each format's settings live in the
Views UI format settings of the view. Pattern choices are made there (see
Patterns).


## Usage


### What VVJ Core provides


#### PHP

| Class / service | Purpose |
|---|---|
| `Drupal\vvj_core\Plugin\views\style\VvjStylePluginBase` | Abstract Views Style plugin base. Pattern modules subclass and implement four small hooks (`getModuleSlug()`, `getCustomElementTag()`, `definePatternOptions()`, `buildPatternSections()`). Everything else (form weights, warning, advanced/deeplinking/token-doc sections, library list, render(), validate()) is inherited. |
| `vvj_core.svg_sanitizer` | Allowlist-driven SVG sanitizer for admin-pasted icons. |
| `vvj_core.token_resolver` | `[vvjX:field]` and `[vvjX:field:plain]` token resolution. Pattern modules call `$this->tokenResolver->resolve($namespace, $tokens, $data, $bubbleable, $expectedClass)` from their `hook_tokens()` implementation. |
| `vvj_core.breakpoint_registry` | Canonical breakpoint values + translatable labels. |
| `vvj_core.unique_id_generator` | `random_int`-based 8-digit unique IDs. |
| `vvj_core.twig_extension` | Registers the `safe_html` Twig filter . |
| `Drupal\vvj_core\Hook\VvjCorePreprocessHook` | Shared `preprocess_views_view`: adds `.vvj-component` and `.vvj-{slug}` on the view wrapper for every display using `VvjStylePluginBase`, so global CSS/a11y in `vvj-a11y.css` applies to all patterns. |
| `Drupal\vvj_core\Hook\VvjCoreHelpHook` | `hook_help` implementation |


#### Constants enums (typed PHP 8.3 const classes)

| Class | Purpose |
|---|---|
| `Drupal\vvj_core\Constants\Breakpoints` | Canonical responsive breakpoint values and labels for every VVJ pattern. |
| `Drupal\vvj_core\Constants\Easing` | CSS easing keywords. |
| `Drupal\vvj_core\Constants\AnimationDirection` | Animation presets. |
| `Drupal\vvj_core\Constants\ValidationBounds` | Min/max bounds shared across pattern modules: transition speed, slide timing and the rest. |


#### JS

| Module | Purpose |
|---|---|
| `js/vvj-element-base.js` | `VvjElementBase extends HTMLElement` |
| `js/vvj-keyboard-nav.js` | `handleArrowKeyNav` |
| `js/vvj-focus-trap.js` | `trapFocus` |
| `js/vvj-deeplink-bridge.js` | `wireDeeplink` + `writeDeeplinkHash` |
| `js/vvj-token-bridge.js` | `registerVvjBehavior` |


#### CSS

| File | Purpose |
|---|---|
| `css/vvj-tokens.css` | APEX `--apex-*` and `--r-*` token bridge. Aliases them as `--vvj-*` with system-color-keyword fallbacks for non-APEX sites. |
| `css/vvj-base.css` | Cascade-layer ladder (`vvj.foundation, .tokens, .base, .pattern, .theme`), container-query setup, reduced-motion baseline, FOUC-prevention for unupgraded custom elements. |
| `css/vvj-a11y.css` | AAA-level focus indicators + visually-hidden utility, with forced-colors-mode fallbacks. |


### What each format module adds

Every `drupal/vvj*` package layers these on top of the foundation (see each
module's `README.md`):

| Surface | Typical contents |
|--------|------------------|
| `src/Hook/*ThemeHook.php` | `hook_theme` |
| `src/Hook/*PreprocessHooks.php` | Style preprocess + row/field preprocess via core `ViewsThemeHooks` (Drupal 12-safe). |
| `src/Hook/*TokenHooks.php` | `hook_token_info` / `hook_tokens` |
| `src/Hook/*HelpHook.php` | `hook_help` |
| `config/optional/views.view.*_example.yml` | Optional starter View . |


### How a format module consumes VVJ Core

Sketch of `vvja/src/Plugin/views/style/Accordion.php` (abridged):

```php
namespace Drupal\vvja\Plugin\views\style;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\views\Attribute\ViewsStyle;
use Drupal\vvj_core\Plugin\views\style\VvjStylePluginBase;

#[ViewsStyle(
  id: 'views_vvja',
  title: new TranslatableMarkup('Views Vanilla JavaScript Accordion'),
  help: new TranslatableMarkup('Render items in an Accordion using vanilla JavaScript.'),
  theme: 'views_view_vvja',
  display_types: ['normal'],
)]
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

Sketch of `vvja/js/vvja-accordion-element.js` (abridged):

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


### Drop-in upgrade contract

Every VVJ v2 module, including this foundation module, preserves the v1
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


## Patterns

A Pattern is a ready-made design for a VVJ format. Choosing one in the Views
format settings applies its layout,
animation, styling and deep linking settings to the view. Each format module
ships its Patterns in
`<module>/patterns/<id>/pattern.yml`; VVJ Core ships none of its own, and
carries the code that offers them.

| Format | Patterns | Names |
|---|---|---|
| Accordion (`vvja`) | 5 | Spotlight, Stage, Answers, Cards, Agenda |
| Basic carousel (`vvjb`) | 5 | Dockrail, Ledger, Switchboard, Swatch, Nightstory |
| 3D carousel (`vvjc`) | 5 | Story reel, Orbit dock, Index split, Thumb rail, Ambient stage |
| 3D flip box (`vvjf`) | 5 | Hand, Board, Rail, Figures, Plans |
| Hero (`vvjh`) | 4 | Aperture, Bento, Panels, Stack |
| Lightbox (`vvjl`) | 5 | Mosaic, Contact sheet, Reel, Prints, Index |
| Parallax (`vvjp`) | 5 | Horizon, Skyline, Showroom, Shoreline, Field book |
| Reveal (`vvjr`) | 5 | Lens, Blinds, Index, Cover, Nocturne |
| Slideshow (`vvjs`) | 5 | Iris, Deck, Filmstrip, Ledger, Nightfall |
| Tabs (`vvjt`) | 5 | Dock, Contents, Swatch, Folder, Glossary |

VVJ Core carries three sub-modules:

| Sub-module | What it does |
|---|---|
| `vvj_patterns` | The shared base for Patterns. It reads each format's `pattern.yml` files and adds the "Patterns" section with a "Pattern" select to the format settings. The select shows only while `vvj_patterns` is enabled. It also ships the shared Pattern scripts and stylesheet. Depends on `vvj_core`. |
| `vvj_patterns_demo` | Optional example content. It creates a "VVJ Slide" content type, five demo slides with pictures and five image styles, plus example views titled "VVJ demo: <format>" with one page per Pattern at `vvj/<module>/<pattern>`. Uninstalling it removes the slides and pictures it created. |
| `vvj_core_ai` | Optional. Adds "Build a VVJ view with AI" to the Views admin page. It creates the view disabled, as a block, and never edits an existing view. Requires `drupal/ai`; the parent module does not. See its own [README](./modules/vvj_core_ai/README.md). |


### Choosing a Pattern

1. Enable the format module and `vvj_patterns`: `drush en vvja vvj_patterns`.
1. Edit a view that uses the format and open the format settings.
1. In the "Patterns" section, pick a Pattern in the "Pattern" select and save. A
   list titled
   "To finish the <name> Pattern" shows what the view must supply (fields,
   markup classes, item count).
1. Pick "None" to keep your own settings.


### Example views

```bash
drush en vvj_patterns_demo -y
```

Visit `vvj/<module>/<pattern>`, for example `vvj/vvja/stage`. Example views are
created for the format modules that are
enabled.


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
- **No view config migration** for v2.0: existing Views keep working

Upgrade **all** installed VVJ modules to 2.x together. Mixed 1.x + 2.x on one
site is not supported.

**Stay on 1.x** if you are on Drupal 10, 11.0–11.2, or PHP 8.1/8.2: v2
requires Drupal 11.3+ and PHP 8.3+.


### Manual / tarball upgrade (no Composer)

Composer users get `vvj_core` automatically. If you install from drupal.org
tarballs, **you must download `vvj_core` yourself**. The pattern modules
require it and will not enable without it.

1. Download the new pattern tarball(s) **and** the `vvj_core` tarball.
1. Extract `vvj_core` to `modules/contrib/vvj_core` (keep it under the same
   parent as the pattern modules, see "How the JavaScript is shared" above).
1. Overwrite each pattern module with its 2.x files.
1. `drush updb -y`: the `*_update_10001()` hooks enable `vvj_core`.
1. `drush cr`.

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


### `drush vvj:upgrade`: migration audit

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
no breaking renames**, the command reports *"No migration needed: all VVJ
views are on the v2.0 schema."* for every existing v1 view. The scan/drift
logic lives in the Drush-independent
`\Drupal\vvj_core\Service\VvjUpgradeAuditor` service (unit tested); the
command is a thin wrapper.


### Verify the upgrade

After `drush cr`, confirm:

1. `drush pm:list --status=enabled | grep vvj`: `vvj_core` **and** every
   pattern module are enabled.
1. `drush vvj:upgrade --dry-run`: reports *"No migration needed"*.
1. Each existing VVJ view still renders on the front end (the wrapper element
   is now `<vvja-accordion>` etc.; every v1 CSS class is unchanged).
1. Interactions work in the browser (open/close, next/prev, play/pause…).
1. Turn **JS aggregation** on (`/admin/config/development/performance`) and
   re-check. Shared code loads via the `Drupal.Vvj` namespace + library
   dependency order, so aggregation is safe.


## Troubleshooting

| Symptom | Fix |
|---------|-----|
| `Class "Drupal\vvja\Twig\VVJATwigExtension" not found` | Stale cache after v2 code deploy: `drush cr`; if Drush won't bootstrap, clear `cache_container` in the database, then retry |
| Pattern enabled but format missing | Install and enable `vvj_core` |
| JS error loading `vvj-element-base.js` | Confirm `vvj_core` exists at `modules/contrib/vvj_core` |
| Composer did not install vvj_core | Pattern `composer.json` must require `drupal/vvj_core:^2.0` |
| `updb` errors before `vvj_core` is enabled | Enable it first: `drush en vvj_core -y`, then `drush updb -y && drush cr` |
| Dependency error: pattern needs `vvj_core` | Manual install |

Report bugs in the
[issue queue](https://www.drupal.org/project/issues/vvj_core).


## Changelog

See [CHANGELOG.md](./CHANGELOG.md).


## Maintainers

- Alaa Haddad - [flashwebcenter](https://www.drupal.org/u/flashwebcenter)


## License

GPL-2.0-or-later. See the
[GNU General Public License, version 2 or later](https://www.gnu.org/licenses/gpl-2.0.html).
