# VVJ Core (`vvj_core`): agent brief

**Who this is for:** whoever works on `vvj_core` next, human or agent, with no memory of how it got this way.

**Scope:** `vvj_core` and its three sub-modules (`vvj_patterns`, `vvj_patterns_demo`, `vvj_core_ai`). The ten format
modules (`vvja` to `vvjt`) are separate drupal.org projects that depend on this one.

## Before you change anything

1. Read [README.md](./README.md) and [CHANGELOG.md](./CHANGELOG.md). Every format module consumes what lives here, so a
   change here reaches ten projects.
2. Check "What must not change" below. A change to anything on it needs a changelog entry and, for stored data, an
   update path in each format module.
3. `vvj_core_ai` has its own README and `docs/VVJ-AI-BRIEF.md`; read them before touching it.

## Where the facts live

| Fact | Home |
|---|---|
| Abstract Views style plugin | `src/Plugin/views/style/VvjStylePluginBase.php` |
| Shared services (SVG sanitizer, token resolver, breakpoints, unique ids, upgrade auditor) | `src/Service/`, registered in `vvj_core.services.yml` |
| `safe_html` Twig filter | `src/Twig/VvjCoreTwigExtension.php` |
| Typed constants (breakpoints, easing, animation, bounds) | `src/Constants/` |
| Hooks (theme, preprocess, help) | `src/Hook/` |
| `drush vvj:upgrade` | `src/Commands/VvjUpgradeCommands.php`, `drush.services.yml` |
| Shared JavaScript (`Drupal.Vvj` namespace) and CSS contract | `js/`, `css/`, `vvj_core.libraries.yml` |
| Patterns base: reading `pattern.yml`, the "Pattern" select | `modules/vvj_patterns/src/` (`PatternRepository`, `StylePatterns`) |
| Shared Pattern scripts and stylesheet | `modules/vvj_patterns/js/`, `modules/vvj_patterns/css/` |
| Demo content type, slides, images, example views | `modules/vvj_patterns_demo/` |
| AI view builder | `modules/vvj_core_ai/` |
| Requirements | `vvj_core.info.yml` and `composer.json` |

## Commands that check a change

From the Drupal project root, with Drupal's Coder and PHPStan installed through Composer:

```bash
vendor/bin/phpcs --standard=web/modules/contrib/vvj_core/phpcs.xml.dist web/modules/contrib/vvj_core
vendor/bin/phpstan analyse -c web/modules/contrib/vvj_core/phpstan.neon
```

How to run the PHPUnit suites is in [CONTRIBUTING.md](./CONTRIBUTING.md).

CSS cascade (static, no site): `python3 -I scripts/check-vvj-cascade.py` from this repo or the lane root. It reads all
eleven VVJ repos beside this one, Solo and Olivero, and fails (exit 1) when a winning declaration changed against
`--rev` (default `HEAD`), a new Pattern selector is over 0,2,0, or a P4/P5 contract check gets worse. `--list` prints
each change; `--vvj DIR` points at another checkout of the repos. It is the one copy; the format modules call this one.

## What must not change

- The four hooks every format implements on the base class (`getModuleSlug()`, `getCustomElementTag()`,
  `definePatternOptions()`, `buildPatternSections()`) and the service IDs (`vvj_core.svg_sanitizer`,
  `vvj_core.token_resolver`, `vvj_core.breakpoint_registry`, `vvj_core.unique_id_generator`,
  `vvj_core.twig_extension`, `vvj_core.upgrade_auditor`). Format modules call them by name.
- The `Drupal.Vvj` JavaScript namespace and the library names in `vvj_core.libraries.yml`. Formats list them as
  dependencies. No native ES module imports.
- `vvj_core` must not reference `vvj_core_ai` or `drupal/ai`. The AI sub-module is optional, and its info file keeps
  `core_version_requirement: ^11.3` until `drupal/ai` declares `^12`.
- The Patterns select must keep appearing only while `vvj_patterns` is enabled.
- Every update hook docblock is one summary line. Put explanation in `//` comments inside the function body.
- A failing test is fixed in the code, not by editing the test to pass.
