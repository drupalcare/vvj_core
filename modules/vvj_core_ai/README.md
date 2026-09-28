# VVJ Core AI

Optional. Adds **Build a VVJ view with AI** to the Views admin page: choose a VVJ
format and a content type, say what the view should show, and it creates the
view for you — **disabled**, as a block, for you to check.

It is a separate module on purpose. `vvj_core` and every format module stay
installable without `drupal/ai`; nothing here is referenced from them.

## How it works

1. **Structure › Views › Build a VVJ view with AI** (`/admin/structure/views/vvj-ai`).
2. Choose the **format** (Accordion, Basic Carousel, 3D Carousel, 3D Flipbox,
   Hero, Lightbox, Parallax, Reveal, Slideshow, Tabs) and the **content type**.
3. Choose how each item shows — **fields, or a view mode** where the format
   allows it (see below). Set **how many** (0 uses the format's best number).
4. Say what it should show: *"the newest articles, image and title"*.
5. Optionally tick **Also make a page** and give it a path.

The person decides the format, the content type, the row and the count. The
model only **names the view and picks its fields and sort** — inside a schema
built from this site's own fields, so it cannot invent one.

### Fields or a view mode

Decided by the format, never by the model. `VvjStylePluginBase` requires a
fields row and switches any other row back to fields, unless a format opts out:

| Format | Fields | View mode |
|---|---|---|
| Basic Carousel, 3D Carousel | yes | yes |
| Slideshow | yes | yes, except in hero mode |
| Accordion, 3D Flipbox, Lightbox, Reveal, Tabs | yes | no |
| Hero | yes — the first field must be an image | no |
| Parallax | yes — the first field an image with "URL to image" | no |

The module asks each format (`RowRules`), so this table cannot go stale in the
code. For Hero and Parallax it puts an image field first for you.

### What a new view is

- **Disabled.** Nothing appears on the site until you enable it.
- **A block**, plus a page only when you ask for one. No pager: a fixed number
  of items, because page links under a carousel or tabs break the effect.
- **Its features switched on, and named.** Each format's brief lists the
  settings a new view starts with — navigation, autoplay, looping, keyboard and
  swipe, progress and counters — and the page says which were switched on so
  you can turn any off in the format settings. Colours and sizes stay at the
  format's defaults.
- **Checked by Views before it is saved.** The finished view goes through
  Views' own validation, so a format's own rules decide; nothing invalid is
  written. An existing view is never overwritten.

## The briefs

`config/install/vvj_core_ai.briefs.yml` — one entry per format: what it is good
for, best practices, a default count, example prompts, and the features. One
file, easy to edit; `RealFormatsTest` fails if a feature names a setting or a
value a format does not have.

## Setting it up

1. `composer require drupal/ai` and enable it.
2. Choose a default **chat** provider and model at *Configuration › AI ›
   Settings* (the `ai` module stores the key, never this module).
3. Enable `vvj_core_ai`, and grant **Use the VVJ views assistant** to the roles
   that should have it. Creating a view also needs **Administer views**.

No chatbot, agent or assistant is needed. The chat tools this module used to
ship were removed on 2026-09-27, in favour of this page.

## Testing

```bash
vendor/bin/phpunit web/modules/contrib/vvj_core/modules/vvj_core_ai
```

The drafter is tested against a stub provider, so the suite makes no AI call.
`RealFormatsTest` runs against the ten real formats and skips where they are
absent.

## Requirements

Drupal ^11.3 and `drupal/ai`. Not `^12`: `drupal/ai` declares
`^10.5 || ^11.2`, so claiming `^12` here would offer the module on a site where
it cannot install.

## License

GPL-2.0-or-later
