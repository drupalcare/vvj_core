# Changelog

All notable changes to the VVJ Core module are documented here.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/)
and the project follows [Semantic
Versioning](https://semver.org/spec/v2.0.0.html).

---

## [2.0.0] — UNRELEASED

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
  - `vvj_core.svg_sanitizer` — whitelist-driven SVG sanitizer (defense in depth)
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
