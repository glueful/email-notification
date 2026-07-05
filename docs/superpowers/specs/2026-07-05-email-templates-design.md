# Managed Email Templates & Settings — Design

**Date:** 2026-07-05
**Status:** Draft for review
**Repo:** `glueful/email-notification` (most of the work lands here)
**Consumers:** any Glueful app or extension — NOT tied to Lemma. Lemma's
Settings → Email rebuild is a separate follow-up that merely consumes this API.
**Compatibility:** none required — no live projects; the subject/rendering
changes below are breaking and deliberate.

## Goal

PocketBase-style email template management: every mail template is a DECLARED
definition (key, label, placeholders with descriptions and samples, default
subject, default body) that any extension can register; operators override
subject/body per template from an admin UI backed by a DB store; a send-test
endpoint mails any template with sample data. The engine stays the existing
Mustache-lite (`{{var}}`, `{{> partial}}`, `{{#if}}`) — extracted behind a
seam, NOT replaced with Twig (a DB-authored-Twig email engine would need the
whole sandbox/lint apparatus for near-zero editor value; the `{{var}}`
grammar maps 1:1 to placeholder chips and is already HTML-escaping).

## 1. The cross-extension contract (glueful/extension-contracts)

The "other extensions register their templates" future is exactly the seam
shape `glueful/extension-contracts` exists for — this feature is the second
consumer its out-of-scope list was waiting on. New `Email/` namespace:

- **`EmailTemplatePlaceholder`** — readonly VO: `string $name` — the engine
  variable name exactly as typed in templates (e.g. `app_name`; admin chips
  display `{{app_name}}` — ONE grammar everywhere, §3), `string $description`,
  `string $sample` (drives test-sends and future previews).
- **`EmailTemplateDefinition`** — readonly VO: `string $key` (unique,
  `[a-z0-9][a-z0-9._-]*` — dots allowed so owner-prefixed keys like
  `lemma.comment-reply` are representable), `string $label`, `string $description`,
  `string $defaultSubject`, `string $defaultBody` (the full Mustache-lite
  body — registrants read their shipped file into it),
  `list<EmailTemplatePlaceholder> $placeholders`,
  `string $owner` (package name, e.g. `glueful/email-notification` — the
  admin can group by it later).
- **`EmailTemplateRegistry`** — `register(EmailTemplateDefinition ...$defs): void`,
  `all(): list<EmailTemplateDefinition>`, `find(string $key): ?EmailTemplateDefinition`.
  **Collision rule (P1 pin — boot order must NEVER be a correctness
  boundary):** re-registering an existing key is allowed ONLY when the new
  definition's `owner` equals the existing one (idempotent boot re-runs);
  a different owner claiming an existing key throws loudly at boot — another
  extension can never silently replace `verification` or `password-reset`.
  Cross-extension keys are owner-prefixed by convention
  (`lemma.comment-reply`); the built-in five keep their bare names.
- **Soft-binding rule (the package's §2, verbatim):** email-notification is
  the ONLY binder of `EmailTemplateRegistry`. Registrant extensions do
  `has(EmailTemplateRegistry::class) ? get(...)->register(...) : skip` in
  `boot()` — no email-notification class names anywhere in registrants, and
  a missing email channel degrades to "templates simply aren't registered".

## 2. Storage & resolution (email-notification)

- New migration `email_templates`: `id`, `uuid` (12, unique), `template_key`
  (191, **unique**), `subject` (text), `body` (text), `updated_by` (12,
  nullable), timestamps. One row per OVERRIDDEN template; absence = the
  definition's defaults apply. No versioning in v1 (reset-to-default =
  delete the row); history can adopt the render-template pattern later if
  demanded.
- **Resolution is definition-first, on EVERY path (P1 pin):** a template
  renders ONLY if a definition exists for its key (unknown key = loud error
  — templates are declared, never ad-hoc file lookups). Effective
  subject/body = override row → definition defaults. The config
  `templates.extension_mappings` file-lookup path and the "template name
  guessing" behavior are REMOVED (breaking, sanctioned): the extension's own
  five templates become registered definitions like everyone else's.
  **The `EnhancedEmailFormatter` / `data['template']` branch in
  `EmailChannel::createEmail()` is retired** — caller payload shape must not
  select rendering behavior; ALL rendering funnels through ONE
  `TemplateRenderer::render(key, data)` entry point so the unknown-key rule
  is universal, not just true of the default path.
- **Subject becomes template-owned (breaking, sanctioned):** the effective
  subject is rendered from the template store with the same engine and data
  (placeholders work in subjects — `Verify your {{app_name}} email`).
  Callers stop passing subjects; they pass the template key + data. A
  caller-supplied subject is ignored.

## 3. The engine seam

- Extract the existing rendering out of `EmailFormatter` into
  `Templates\TemplateEngine` (interface) + `MustacheLiteEngine` (the current
  `{{var}}` / `{{> partial}}` / `{{#if}}` behavior, moved — not rewritten),
  with **pinned behavior tests**: escaping of every interpolated scalar
  (already implemented — keep it true), partial resolution, conditional
  truthiness, unknown-variable output. This is the "Twig later if ever"
  seam: a different engine is a binding swap, not a rewrite.
- Layout/partials (`header`/`footer`/`layout`) stay file-based furniture in
  v1 — the admin edits BODY content; the wrapper is the app's chrome
  (`logo_url` etc. already feed it). Editable layouts are out of scope.
- Placeholder chips in admin UIs come from definition METADATA, never from
  parsing bodies. Chip display shows `{{name}}` verbatim (no separate
  `{NAME}` grammar — one syntax everywhere).

## 3b. DB-backed transport settings (same treatment as templates)

Today the transport reads env-fed `config('services.mail')`, and Lemma's
admin page REWRITES `.env` via `EnvWriter` — web-process dotfile writes,
single-instance only, and a fresh-transport dance in its test-send because
the running process still holds the old env. Since templates get a DB store,
the config joins it:

- New migration `email_settings`: key/value rows for the transport fields
  (`mailer`, `host`, `port`, `username`, `password`, `encryption`, `from`,
  `from_name`, `bcc`, `logo_url`). Precedence per key: DB row →
  `services.mail` config/env default (the GeneralSettings model — explicit
  empty clears to fallback).
- **Secrets pin:** `password` is encrypted at rest with the framework's
  `EncryptionService` (AES-256-GCM, AAD `email.smtp_password`) and NEVER
  returned by the API — responses carry `password_set: bool` only (today's
  page contract, kept).
- New `EmailSettings` reader service resolves effective values;
  `EmailChannel`/`TransportFactory` read THROUGH it — a save applies on the
  next send, on every instance, no restart, no `.env` write, and the
  test-send dance disappears (stored settings ARE the live settings).
  **Per-send resolution (P1 pin):** `EmailChannel` currently captures config
  in its CONSTRUCTOR (`$this->config`) and `createTransport()`/availability
  checks read that snapshot — a shared channel would hold stale settings
  until a container rebuild. The channel stops caching transport settings at
  construction: `sendNotification()`/`createTransport()` resolve
  `EmailSettings::effectiveConfig()` per send.
- **`EmailSettings::effectiveConfig(): array` — the exact materialized shape
  (P2 pin):** the flat DB keys map into the SAME nested `services.mail`
  array `TransportFactory`/`EmailChannel` consume today, so neither grows a
  second config dialect:

  ```php
  [
      'default' => $mailer,                       // v1 vocabulary: 'smtp' (see below)
      'from' => ['address' => $from, 'name' => $fromName],
      'bcc' => $bcc,                              // '' = none (channel-level, not transport)
      'logo_url' => $logoUrl,                     // template chrome variable, not transport
      'mailers' => [
          'smtp' => [
              'host' => $host,
              'port' => (int) $port,
              'username' => $username,
              'password' => $decryptedPassword,   // decrypted HERE, never earlier
              'encryption' => $encryption,        // 'tls' | 'ssl' | ''
          ],
      ],
  ]
  ```

  Every key resolves DB row → the corresponding `services.mail` path;
  anything the DB doesn't model (failover, extra mailers, log/null/array
  test transports, provider bridges) passes through from config untouched.
  **Mailer vocabulary (P2 pin):** `TransportFactory` has NO sendmail support
  — v1's DB-settable `mailer` is `'smtp'` plus any mailer already configured
  in `services.mail.mailers` (validated at save time against that set); the
  admin UI's old smtp|sendmail select follows suit in the follow-up.
  Sendmail support is out of scope unless it earns its way in.
- **Policy config survives the rewire (P1 pin):** domain policy
  (`MAIL_ALLOWED_DOMAINS`/`MAIL_BLOCKED_DOMAINS`), attachment confinement,
  debug and logging live under `config('emailnotification')` and are
  DELIBERATELY not DB-managed — security posture stays deploy-owned.
  `EmailSettings::effectiveConfig()` covers ONLY the transport/services.mail
  shape; `EmailChannel` composes PER SEND:
  `effectiveConfig()` + `config('emailnotification')` (security/debug/…)
  before any policy or transport work — DB SMTP settings can never displace
  the domain/attachment policy. Regression test pinned: configure an
  allowed-domain policy, save DB SMTP settings, send to a disallowed
  recipient → still fails closed.
- API joins §4: `GET /email/settings` (effective values + `password_set`),
  `PUT /email/settings` (partial update; `password` only when supplied),
  `POST /email/settings/test` (send a plain test message with the stored
  settings). Lemma's `EnvWriter`-based controller RETIRES in the follow-up —
  its page becomes a client of these endpoints.

## 4. Admin API (email-notification owns it; any admin can consume)

The extension gains routes (it has none today) under `/email/templates`,
gated auth + permission. **Gate path (P1 pin — corrected):** the permission
is NOT a migration seed. `email.templates.manage` is declared through
`ServiceProvider::permissions()` with `Permission::define(...)` — the
established extension pattern; Aegis syncs the catalog and grants flow
through the app's usual role machinery. Fluent routes get no attribute
auto-attach, so the routes carry an explicit middleware: mirror the Audit
extension's own-alias pattern (`email_permission:email.templates.manage`)
unless plan-time verification finds a framework/aegis alias that already
fits (`aegis_permission:*`-style) — whichever exists, the spec requirement
is "every route explicitly gated on that permission":

- `GET /email/templates` — every registered definition with effective
  values: `{key, label, description, owner, placeholders: [{name,
  description, sample}], subject, body, overridden: bool}`.
- `PUT /email/templates/{key}` — save override `{subject, body}`; 404 for
  unregistered keys; 422 for empty subject/body or unbalanced `{{#if}}`
  blocks (engine-level syntax check — the ONLY validation; bodies are
  operator-trusted HTML, same tier as the transport settings).
- `DELETE /email/templates/{key}` — reset to defaults (delete the row).
- `POST /email/templates/{key}/test` — body `{to}`: renders subject+body
  with the definition's SAMPLE values and sends through the real channel
  (the PocketBase modal's backend). 422 when the transport is unconfigured.

## 5. Consumption (context, not scope)

- email-notification registers its own definitions (verification,
  password-reset, welcome, alert, default) with real placeholder metadata.
- Other extensions (glueful/users' flows, future Lemma notification mails)
  register via the contract — soft-bound, zero coupling.
- Lemma's Settings → Email page rebuild (transport card + Mail templates
  accordion + Send-test modal, per the PocketBase reference shots) is a
  SEPARATE follow-up spec'd in the lemma repo; it is purely a client of §4.

## Out of scope

- Twig or any engine change (the seam makes it possible later; nothing
  needs it now).
- Override versioning/history; editable layout/partials; WYSIWYG editing.
- Per-locale template variants (registry keys could grow a locale dimension
  later; the storage unique key makes room by design decision then).
- Plain-text alternative bodies; per-tenant templates.

## Testing

- Engine: pinned behavior matrix (escaping, partials, conditionals, unknown
  vars, unbalanced-`{{#if}}` detection used by the 422 path).
- Registry: same-owner re-register replaces; DIFFERENT-owner collision
  throws at boot; dotted keys accepted; unknown-key render is loud on EVERY
  path (including what was the enhanced/`data['template']` branch — a
  payload-supplied template name no longer selects anything).
- Resolution: default → override → reset round-trip; subject renders
  placeholders; caller-supplied subject ignored.
- API: list shape incl. placeholder metadata + overridden flags; PUT/DELETE
  lifecycle; test-send renders SAMPLES and 422s without transport; the
  permission gate is on every route.
- Settings: DB → env precedence per key; explicit-empty clears; password
  encrypted at rest (ciphertext in the row, decrypts through the service)
  and absent from every response; `effectiveConfig()` materializes the exact
  nested `services.mail` shape (mailer/from/mailers.smtp matrix); transport
  reads the effective values PER SEND — save a row, the very next send on
  the SAME channel instance uses it (the constructor-snapshot regression
  test); allowed/blocked-domain policy still fails closed AFTER DB SMTP
  settings are saved (the policy-survives-rewire regression); saving an
  unknown `mailer` value 422s against the configured-mailer set.
- Contracts package: VO shape tests (the existing pattern there).

## Files touched

extension-contracts: `src/Email/{EmailTemplatePlaceholder,EmailTemplateDefinition,EmailTemplateRegistry}.php` + tests.
email-notification: registry impl + binding, definitions for the built-in
five, `migrations/*_CreateEmailTemplatesTable.php` +
`*_CreateEmailSettingsTable.php`, `permissions()` declaration
(`email.templates.manage`) + the explicit route gate middleware,
`Templates/TemplateEngine.php` + `MustacheLiteEngine.php` (extraction) +
`TemplateRenderer` (the ONE render entry point), `EmailFormatter` rewire
(definition-first resolution, template-owned subject;
`EnhancedEmailFormatter`/`data['template']` branch retired), `EmailSettings`
reader (`effectiveConfig()`) + encrypted-password storage,
`EmailChannel` per-send resolution + `TransportFactory` rewire,
`routes.php` + `TemplatesController` + `SettingsController`, config cleanup
(`extension_mappings` removed), tests, README, CHANGELOG.
lemma (follow-up, separate spec): Settings → Email page rebuild consuming
§3b/§4; the `EnvWriter` controller retires.
