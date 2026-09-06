# Changelog

All notable changes to the VVJ Core module are documented here.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/)
and the project follows [Semantic
Versioning](https://semver.org/spec/v2.0.0.html).

---

## [2.0.1] — UNRELEASED

### Added

- **`vvj_core_ai`** — optional AI sub-module. Five read/act tools for building
  and troubleshooting VVJ views: `vvj_recommend_format`, `vvj_explain_settings`,
  `vvj_resolve_view`, `vvj_create_view`, `vvj_diagnose_view`. It reads what is
  installed on the site rather than assuming, refuses with the exact question to
  ask when it cannot proceed, and a created view is always **disabled**.
  Requires `drupal/ai`; the parent module does not.
- **`ai_agents.ai_agent.vvj_core_agent`** in `config/optional/`. A FunctionCall
  plugin reaches an assistant only through `ai_agents`, so without this the
  tools were unreachable by anything on the site. It installs the moment
  `ai_agents` is present and stays absent otherwise.

### Fixed

- **Twenty FunctionalJavascript failures across ten pattern modules**, all on
  `assert($view !== NULL)` in the shared `VvjPatternJavascriptTestBase`. Each
  pattern ships its example view as *optional* config, which installs only once
  its dependencies exist; the view depends on `node.type.article`, which nothing
  supplies at module-install time. The base class created the type afterwards —
  too late — so the view was never there. It now re-runs
  `installOptionalConfig()` once the type exists.
- **`statusCodeEquals()` in JavaScript tests.** A WebDriver test drives a real
  browser through Selenium, which cannot report an HTTP status code, so the
  assertion threw rather than failed. Replaced with a check that works there.
- **Kernel tests on Drupal 12.** `text_with_summary` was removed from core's
  text module; the `body` field these tests create for a config dependency now
  uses `text_long`.
- Four README table rows that had been truncated mid-sentence at exactly 80
  characters, restored from git history.

### Changed

- Lint backlog cleared: stylelint, eslint and cspell all pass. `@media
  (prefers-contrast: high)` was corrected to `more` — `high` is not a value the
  spec defines, so those accessibility rules had never applied in any current
  browser.
- `composer.json` now suggests `drupal/ai`, naming the sub-module that needs it.

---

## [2.0.0] — 2026-08-30

**Initial release.** VVJ Core is the foundation module for the VVJ Views
renderer family — required by `drupal/vvja`, `drupal/vvjb`, `drupal/vvjc`,
`drupal/vvjf`, `drupal/vvjh`, `drupal/vvjl`, `drupal/vvjp`, `drupal/vvjr`,
`drupal/vvjs`, `drupal/vvjt`.

### Added

- **`VvjStylePluginBase`** abstract Views Style plugin base class providing
  standardized form-section construction (`buildAdvancedSection`,
  `buildDeepLinkingSection`, `buildBreakpointSelect`, `buildAnimationSelect`,
  `buildTokenDocumentation`, `buildWarningMessage`, `setDefaultElementWeights`),
  unique-id generation, library list construction, validate(), render(), and a
  `requiresFieldsRow()` hook (default TRUE — patterns that can render arbitrary
  row plugins, like vvjs's regular slideshow, override it to relax the
  Fields-row requirement/enforcement) — pattern modules subclass and only
  implement pattern-specific concerns.
- **Shared services**:
  - `vvj_core.svg_sanitizer` — allowlist-driven SVG sanitizer (defense in depth)
  - `vvj_core.token_resolver` — `[vvjX:field]` and `[vvjX:field:plain]` token
    replacement
  - `vvj_core.breakpoint_registry` — canonical responsive-breakpoint values +
    labels
  - `vvj_core.unique_id_generator` — random_int-based stable IDs
- **Constants enums**: `Breakpoints`, `Easing`, `AnimationDirection`,
  `ValidationBounds` — typed PHP 8.3 constants shared across all VVJ modules.
- **OOP `#[Hook]` classes** under `src/Hook/`:
  - `VvjCoreHelpHook` — README rendering on `help.page.vvj_core`
  - `VvjCoreThemeHook` — placeholder for future shared theme hooks
  - `VvjCorePreprocessHook` — base preprocess primitives consumable by pattern
    modules
- **`safe_html` Twig filter** via `VvjCoreTwigExtension` — replaces the
  per-module duplicates that previous v1 VVJ modules each shipped.
- **`drush vvj:upgrade`** post-upgrade audit command (backed by the
  Drush-independent, unit-tested `vvj_core.upgrade_auditor` service): scans
  every View using a VVJ style plugin and confirms it is on the current schema,
  reporting any option-key drift. Run after updating a site from v1 to v2.
  Because the v2.0 drop-in upgrade has no breaking renames, it reports "No
  migration needed". Supports `--dry-run` and `--module=vvja,vvjs`.
- **JS Custom Element base class** at `js/vvj-element-base.js`:
  - `VvjElementBase extends HTMLElement` with lazy IntersectionObserver
    hydration (lighthouse-100 by default)
  - AbortController-tracked listeners (clean disconnect)
  - Reduced-motion awareness via `prefers-reduced-motion` media query
  - `withTransition()` helper for `document.startViewTransition()` with graceful
    fallback
  - `emit()` helper for `vvj:*` CustomEvent dispatch on `document`
- **JS helper modules**:
  - `vvj-deeplink-bridge.js` — URL-hash sync + `history.replaceState` for any
    pattern that supports deep-linking
  - `vvj-keyboard-nav.js` — Up/Down/Home/End primitives shared by accordion /
    tabs / carousels
  - `vvj-focus-trap.js` — modal focus trap for lightbox-style patterns
  - `vvj-token-bridge.js` — Drupal.behaviors → custom-element handoff
- **CSS architecture**:
  - `vvj-tokens.css` — APEX `--apex-*` and `--r-*` token bridge with
    system-color-keyword fallbacks for non-APEX sites
  - `vvj-base.css` — cascade-layer ladder (`vvj.foundation, .tokens, .base,
    .pattern, .theme`), container-query setup, reduced-motion baseline
  - `vvj-a11y.css` — AAA-level focus indicators, forced-colors fallbacks

### Drupal core / PHP

- Requires Drupal `^11.3` or `^12`
- Requires PHP `>=8.3`

### Planned for a later 2.0.x release

- `drush vvj:upgrade [--dry-run]` — audit helper for future option-key
  migrations (not in 2.0.0-alpha1).
