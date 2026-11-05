# Security policy

VVJ Core follows the Drupal Security Team's process for handling security issues.

## Reporting a vulnerability

**Do not** report security issues in the public issue queue. Report them privately through the Drupal Security Team:

- <https://www.drupal.org/security-team/report-issue>

Include the module version, the Drupal core version and clear steps to reproduce. See also:

- <https://www.drupal.org/security-team>
- <https://www.drupal.org/drupal-security-team/security-advisory-process-and-permissions-policy>

## Supported versions

Security coverage applies to the latest stable release once the project opts into Security Advisory coverage on
drupal.org. Development, alpha and beta releases are not covered by security advisories.

## Where this module handles untrusted input

- **Custom SVG icons** pasted by administrators into any VVJ format's settings go through
  `vvj_core.svg_sanitizer`, an allowlist-driven sanitizer, when the form is saved and again at render time.
- **Token text** (`[vvjX:field]`) is resolved by `vvj_core.token_resolver` from the view's own first row.
- **`vvj_core_ai`** (optional) asks an AI provider, through `drupal/ai`, to name a view and pick its fields. It creates
  the view disabled, as a block, and never edits an existing view or writes a format setting.

## Permissions

- **`use vvj views assistant`** (`vvj_core_ai`): shows the "Build a VVJ view with AI" page. It is not marked
  `restrict access`, and creating a view still requires "Administer views", which the route and the creator both check.
  Grant it only to roles that already build views.

`vvj_core` itself and `vvj_patterns` define no permissions.
