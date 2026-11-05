# Contributing to VVJ Core (`vvj_core`)

Thank you for helping. VVJ Core is a Drupal module for Drupal `^11.3 || ^12` and PHP 8.3 or later. Ten format modules depend on it.

- Report bugs and propose changes in the [issue queue](https://www.drupal.org/project/issues/vvj_core).
- Source: <https://git.drupalcode.org/project/vvj_core>.
- Security problems are never reported in the public queue; see [SECURITY.md](./SECURITY.md).
- Read [AGENTS.md](./AGENTS.md) first: it says where each fact lives and what must not change.

## Code style

- Drupal and DrupalPractice coding standards (`phpcs.xml.dist`).
- `declare(strict_types=1);` in every PHP file.
- Hooks are OOP `#[Hook]` classes under `src/Hook/`; services take their dependencies through the constructor.
- PHPStan level `max` with strict array shapes (`phpstan.neon`).
- JavaScript attaches with `Drupal.behaviors` and `once()`. ESLint (`.eslintrc.json`) and cspell (`.cspell.json`)
  configs ship in the repository.

## Local checks

```bash
./vendor/bin/phpcs --standard=phpcs.xml.dist
./vendor/bin/phpcbf --standard=phpcs.xml.dist
./vendor/bin/phpstan analyse -c phpstan.neon
```

The same checks run as `composer lint`, `composer phpcs`, `composer phpcbf` and `composer phpstan`.

## Running the tests

This module ships no `phpunit.xml` of its own, deliberately: its suites are Kernel, Functional and
FunctionalJavascript, which need a real Drupal, so a module-local config would have to hard-code a relative path to
`core` and would break the moment the module is not physically inside a docroot (a symlinked checkout, for instance).
Drupal core's runner already does the job.

Run the fast suites from the docroot:

```bash
cd /path/to/drupal/web
export SIMPLETEST_DB="mysql://user:pass@localhost/dbname"
../vendor/bin/phpunit -c core modules/contrib/vvj_core/tests/src/Unit
../vendor/bin/phpunit -c core modules/contrib/vvj_core/tests/src/Kernel
```

The Functional and FunctionalJavascript suites also need `SIMPLETEST_BASE_URL` pointing at a running web server, and
the JavaScript ones need chromedriver. Without those they error rather than fail, and the errors name missing
infrastructure rather than anything about this module. The drupal.org pipeline provides both.

## Pull request checklist

- [ ] The change is described in `CHANGELOG.md` under the unreleased version.
- [ ] A change to stored options has an update hook with a one-line docblock.
- [ ] Service IDs, the base class hooks, library names and the `Drupal.Vvj` namespace are unchanged, or the change is
      called out and the ten format modules are checked.
- [ ] PHPCS and PHPStan pass.
- [ ] Settings or Patterns that changed are described in `README.md`.
