# VVJ Core AI

Optional. Adds an assistant to the Views administration pages that can **build
a view using a VVJ display format** — and that answers questions about the
formats, their settings, and why a view is not rendering.

It is a separate module on purpose. `vvj_core` and every format module stay
installable without `drupal/ai`; nothing here is referenced from them.

## What it can do

| Tool | Writes? | What it is for |
|---|---|---|
| `vvj_recommend_format` | no | Which of the ten formats suits what the person described |
| `vvj_explain_settings` | no | What a format's own settings do, read from its settings form |
| `vvj_diagnose_view` | no | Why a view using a VVJ format is showing nothing |
| `vvj_resolve_view` | no | Turns a vague request into the exact values that exist on **this** site, or into the questions to ask |
| `vvj_create_view` | **yes** | Creates a new, **disabled** view using a VVJ format |

### The three rules the writing tool cannot be configured out of

1. **A new view is created DISABLED.** A view that appears on a live site the
   moment a machine invents it is the failure this guards against. The person
   looks at it and enables it.
2. **An existing view is never touched.** A machine name already in use is a
   refusal, not an overwrite.
3. **Every precondition is re-checked by the writer itself.** `create_view`
   never assumes `resolve_view` was called, because a model can skip it. That
   redundancy is the safety model, not belt-and-braces.

**It never changes a format's settings.** A new view gets the format's
defaults; adjusting them is the site builder's job, in the Views UI. Rewriting
someone's configured slideshow speed is exactly what this module is built not
to do.

Everything a tool does is also checked against the **person's own permissions**
on every call, so the assistant can never do something they could not do by
hand. Creating a view requires `administer views`.

## Setting it up

1. Enable `vvj_core_ai`.
2. Enable `ai_assistant_api`, `ai_agents` and `ai_chatbot` (all part of
   `drupal/ai`). They are deliberately **not** dependencies — each adds only a
   surface, and requiring them would make a site take modules it did not ask
   for.
3. Create an assistant at **Configuration › AI › AI Assistants**. None is
   shipped: an assistant names an `llm_provider` and an `llm_model` that exist
   on one site only, so a shipped default would look configured and fail on
   first use.
4. **Create an agent, and this is the step people miss.** An assistant built
   with `ai_assistant_api` alone builds its tool list from `actions_enabled`,
   which is a *different plugin type*. The tools here are `FunctionCall`
   plugins, and an assistant reaches those **only through an AI Agent**:
   - create an agent at **Configuration › AI › AI Agents**,
   - enable the five `vvj_formats:*` tools on it,
   - set the assistant's **AI agent** to that agent.

   Without this the assistant answers questions and never creates anything.
5. Choose the assistant at **Configuration › Content authoring › VVJ Views
   AI**. That page also prints *why* the chat panel is not showing, when it is
   not.
6. Grant **Use the VVJ views assistant** to the roles that should see it.

## Where the panel appears

The Views listing, the add wizard, and the view/display edit screens. Not on
the delete, disable or break-lock confirmations — a confirmation is not a place
to start a conversation.

Five things must be true before it renders: the permission, `ai_chatbot`,
`ai_assistant_api`, at least one VVJ format installed, and a chosen assistant
that still loads. None of them is visible from the Views UI, so **the settings
page names the missing one in a sentence** rather than leaving a person staring
at a page where nothing happened.

### Two things about the chat widget that are not this module's to fix

Both measured on a running site, and both will otherwise look like bugs here:

- **The panel's open/closed state comes from `localStorage`, not from
  configuration.** `ai_chatbot`'s sticky widget initialises from
  `deepChatState_<chatId>` and does not read the `toggle_state` setting at this
  placement. A first-time visitor correctly gets a collapsed bubble.
- **That key is not namespaced per block** — it is `deepChatState_bot1` for
  every DeepChat block on the site. Opening another module's chat bar leaves
  this one open too.

## Testing

```bash
vendor/bin/phpunit web/modules/contrib/vvj_core/modules/vvj_core_ai
```

The kernel tests cover each tool, each refusal, and a mutation probe on
`create_view`'s independent re-validation — stub that guard out and the probe
fails, which is the point of having it.

## Requirements

Drupal ^11.3 and `drupal/ai`. Not `^12`: `drupal/ai` declares
`^10.5 || ^11.2`, so claiming `^12` here would offer the module on a site where
it cannot install. A Drupal 12 site installs `vvj_core` and every format, and
simply does not get this one. That is the point of shipping the AI features
separately.

## License

GPL-2.0-or-later
