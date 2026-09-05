# VVJ — brief for `vvj_core_ai`

Written 2026-09-04, before any code, because nobody starts work unbriefed.
This document and the agent description below are the two things that must
exist in writing before the first file is created.

Companion to `paragraphs_bundles/docs/PB-AI-BRIEF.md`, which this follows in
structure and in every hard rule. Read that one's §5 too: the tool-enforced
refusal pattern is the same, and the reasons behind it have not changed.

---

## 0 · What this looks like for the person using it

**Corrected 2026-09-04, after the owner pointed out the module had no purpose.**
The first version of this brief made every tool read-only. That was wrong, and
the reason it was wrong is worth writing down: the owner's standing rule is
*"the AI should not change any form SETTINGS — the user sets those."* That is a
rule about not rewriting a person's configuration. It was misread as "never
write anything", and applied to a module whose entire job is to produce a view.
The result recommended, explained and diagnosed, and could not do the one thing
anybody would want. `paragraphs_bundles_ai` creates paragraphs; this creates
views. The two must be consistent.

**The distinction that matters:**

| | The AI should |
|---|---|
| Theme and module **settings** | help configure, and explain — as `apex_ai_bridge` does |
| **Content and views** | **create them** |

`vvj_core_ai` is an optional sub-module. With it, a person building a view can
describe what they want:

> *"make a list of case studies as a slideshow, showing the title and the
> image, with the title as the button"*

and get a working view using the right VVJ format, with the right fields.

**It negotiates before it writes.** It will not guess the content type, the
fields, or the format. If any of those is missing or ambiguous it asks, and it
asks for everything it is missing at once rather than one question per turn.

**It never overwrites a person's work.** A new view is created **disabled**, so
nothing appears on the site until the person looks at it and enables it — the
same safety shape as `create_sections` leaving a new page unpublished. It never
modifies an existing view's settings; that is the "user sets those" rule, and
it still holds.

## 1 · Scope

**Build** an optional submodule, `vvj_core/modules/vvj_core_ai/`.

**Why in `vvj_core` and not in each format.** `vvj_core` is already a hard
dependency of all ten format modules — none of them functions without it — so
one AI submodule there serves whichever formats a site has installed. Ten
copies would be ten copies of one fact, which is the bug the owner's "one
source of truth" prior names, and each copy would need to know about the other
nine to answer "which format should I use".

**This does not weaken independence.** `vvja` still installs and runs with only
`vvj_core`; `vvj_core_ai` is optional on top, adds nothing to any format's
dependencies, and every tool reports on whichever formats are actually present.

**Naming** follows the parent, as the owner set out for `paragraphs_bundles_ai`:
`vvj_core` + `_ai` = `vvj_core_ai`. **Confirmed by the owner 2026-09-04.**

| | Tool | Writes? |
|---|---|---|
| V1 | `recommend_format` — which VVJ formats this site has, and what each does | never |
| V2 | `explain_settings` — a format's real settings and the values they accept | never |
| V3 | `diagnose_view` — why an existing view is not showing what was expected | never |
| V4 | `resolve_view` — negotiate content, fields and format; return every unmet precondition at once | never |
| V5 | `create_view` — create the view, re-checking every precondition itself | **yes** |

**Exactly one tool writes, and only by creating.** `create_view` creates a new
view. Nothing in this module edits an existing view, and nothing changes a
setting a person has chosen.

**May create or edit:** files under `vvj_core/modules/vvj_core_ai/` and this
brief. Nothing else.

**Must NOT touch:**

- Any other file in `vvj_core`, and none of the ten format modules — not their
  plugins, `phpstan.neon`, `phpcs.xml.dist` or `.gitlab-ci.yml`.
- `paragraphs_bundles` or `utilikit`. They are separate drupal.org projects and
  **must keep working with no knowledge of this module and no shared code.**
  There is no shared "AI kit", and `paragraphs_bundles_ai` is a reference to
  read, never a dependency to import.
- `sites/default`, the other sites in the `demo` multisite, or any vhost.

**Test site: `sts.local`.** Resolved — see §7.

**Never push to drupal.org.** Commits are for the owner to review; pushing is
theirs alone.

---

## 2 · Evidence standard

**Measure with the project's own config, never an ad-hoc one.** Twice in the
session that produced the PB brief, an ad-hoc analyser config produced
confident nonsense: 32 phantom Drupal 12 failures (the real number was 1) and
1200 phantom PHPCS errors (the real number was 0). Run each project's
`phpstan.neon` and `phpcs.xml.dist` as they ship.

**Read the settings from the plugins, never from a list in this module.** A
hardcoded table of 143 settings is a second copy of a fact the plugins already
own, and it will drift the first time a format gains an option.

**The API to use, measured on sts.local 2026-09-04 — and the obvious choice was
wrong:**

| | |
|---|---|
| `defineOptions()` | **protected** — cannot be called from outside |
| `->options` | public, but **empty** until a view initialises the plugin |
| `buildOptionsForm()` | **public** — this is the route |

`buildOptionsForm()` is also the better source. `defineOptions()` yields machine
names and defaults; the form yields the human **label**, the **description**,
the field **type** and the **valid values** for each setting — which is what a
person asking "what does `deeplink_identifier` do?" actually needs. It requires
a throwaway in-memory view to call.

**Discovery is by base class, not by name.** Measured the same day with all ten
formats enabled: filtering the Views style plugin definitions on
`is_subclass_of($class, VvjStylePluginBase::class)` returns exactly the ten
(`views_vvja` … `views_vvjt`). No list of format names appears anywhere in this
module, so a site with three formats is served correctly and an eleventh format
added later needs no change here.

**A clean local run is evidence about this machine, not about the gate.**
drupal.org CI passes `.` on the command line, overriding `paths`, and runs
PHPStan 2.x where `level: max` means 10. The CI log is the verdict.

**Write "needs checking" rather than assert.** An unverified belief stated as
fact is worse than an admitted gap, because it stops anyone else looking.

---

## 3 · Deliverable shape

A working submodule with kernel tests, not a proposal. Done means:

- Three `FunctionCall` plugins, each with a service behind it that can be
  tested without the AI stack present.
- Kernel tests asserting **the sentences**, not just booleans — the wording is
  what a person reads, and a test that only checks a boolean lets the wording
  rot silently.
- A `README.md` that tells a site builder how to turn it on, including the
  step that is easy to miss (§5).
- Zero errors from each project's own PHPStan and PHPCS.

---

## 4 · Refusal conditions — stop and ask, do not guess

- The test-site question in §7 is unresolved.
- A tool would need to write anything to answer well. Report the limitation;
  do not add a write path.
- A format's settings cannot be read from its plugin, so answering would mean
  hardcoding them.
- The work would require editing a format module, `vvj_core` itself, or
  another project.
- Anything about `utilikit` — a different brief, not yet written.

**Refusal is success, not failure.** An agent that stops here and asks has done
its job correctly.

---

## 5 · The rules this module enforces

**The preconditions, negotiated before anything is written.** `resolve_view`
returns EVERY unmet one at once, so the bot asks a single combined question
instead of interrogating turn by turn:

1. What content — an entity type and bundle that exist on this site.
2. Which VVJ format — installed here, named as absent if it is not.
3. Which fields to show — they must exist on that bundle.
4. A name for the view, and a machine name that is not already taken.
5. The user may administer views.

`create_view` **re-checks all five itself.** It must never trust that
`resolve_view` was called, because the model may skip it. That redundancy is
the safety model, not belt-and-braces — the same rule, for the same reason, as
`create_sections` in `paragraphs_bundles_ai`.

**A created view is DISABLED.** Not a preference: a view that appears on a live
site the moment a machine invents it is the failure this guards against. The
person enables it after looking, and the answer says so.

**It creates; it does not edit.** There is no path that modifies an existing
view, and no path that changes a format's settings. Those are the site
builder's, and that is the owner's rule.

**Rules the tools enforce, not the prompt.** A system prompt saying "ask which
fields" fails intermittently; a tool that cannot proceed, and whose refusal
names what is missing, forces the conversation. It is also what makes the
behaviour testable — you cannot unit-test "the bot felt careful".

**Answer from the installed site, never from general knowledge.** "VVJS is the
slideshow" is a fact about the project; "VVJS is installed here" is a fact about
this site, and only the second is safe to act on.

## 6 · How this will be verified — and it will be

1. **Kernel tests**, asserting the exact sentence each refusal produces.
2. **A read-only probe**: the whole module's services instantiated and every
   tool run against a real site, with a config and content count taken before
   and after, proving both are unchanged. Read-only is a claim, and an
   unmeasured claim is a guess.
3. **`phpstan.neon` at level max** and `phpcs.xml.dist`, both as they ship.
4. **A live run against the configured provider**, through an assistant wired
   to an agent per §5, exercising all three tools.
5. **A browser check** with the **console read and reported**, including
   "console clean" when it is. A page that renders is not a page that works.
6. **The drupal.org pipeline**, once the owner pushes. Local green is
   provisional until then.

Nothing is "done" on the strength of reasoning about the code.

---

## 7 · The test site — resolved

**Answered 2026-09-04.** This section recorded a blocker: `sts.local` carried
composer copies of the VVJ modules dated 9 July and 1 August, behind the
development tree, so building against them would have meant developing against
code that was not what shipped.

That is no longer true. The Phase 0 and Drupal 12 work was committed and pushed
to drupal.org, and `sts.local` was updated with `composer update`. Verified on
the site: `vvj_core` there now carries the pushed fix
(`protected ?int $cachedUniqueId`), and all thirteen packages resolve to the
exact commits that were pushed. `sts.local` now runs the shipped code, which is
the condition this section was waiting for.

All ten format modules plus `vvj_core` are enabled there.

The `src/Element/` directory that appeared to be missing from `sts` was chased
down: it is an **empty, untracked directory** in the development tree. Nothing
was lost and nothing needed pushing.

---

## Agent description

**Who does this work.** A single agent with write access limited to
`vvj_core/modules/vvj_core_ai/` and this brief, read access to the whole VVJ
family and to `paragraphs_bundles_ai` as the reference implementation, and
shell access to whichever site §7 resolves to.

**The priors it is given, in full, every time** — these are not to be inferred
from the code:

- **Better safe than sorry.** Aggressive on architecture, conservative on
  process. Verify before commit. Validate at trust boundaries. When uncertain,
  hold and ask rather than guess.
- **One source of truth.** Every fact has exactly one owner and everything else
  derives from it. Two copies of a fact IS the bug — which is why the settings
  are read from the plugins and why this module lives in `vvj_core` rather than
  ten times over.
- **Build once and done.** No shortcuts, no "we will fix it in v2".
- **Understand before you change.** Never accept a change without understanding
  why the code is the way it is. A plausible fix to code you do not understand
  turns a design into a regression.
- **Measure, do not assume.** Report what was measured and how. Say plainly
  when a number is a guess. A red result from your own tooling is as likely to
  be a bad measurement as a bad module — check the tool before blaming the work.
- **No leftovers.** A rule without a harness, a decision that lives only in a
  conversation, a pattern discussed but not written down: each one costs the
  next session the same work again.
