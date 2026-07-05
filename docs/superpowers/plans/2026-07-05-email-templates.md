# Managed Email Templates & Settings Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Declared, registrable email templates with DB overrides, DB-backed transport settings (encrypted secrets), and the admin API for both — all in `glueful/email-notification`, consumable by any Glueful app.

**Architecture:** `Email/` contracts in `glueful/extension-contracts` (definition VOs + registry interface); email-notification binds the registry with the owner-collision rule, stores overrides/settings in two new tables, extracts the Mustache-lite engine behind a `TemplateEngine` seam with ONE `TemplateRenderer` entry point (enhanced/`data['template']` branch retired), resolves transport settings per send merged with deploy-owned policy config, and gains its first routes gated by an `email_permission` middleware mirroring Audit.

**Tech Stack:** PHP 8.3, Glueful framework, PHPUnit (the pack's existing flat `tests/` suite), PSR-12, PHPStan.

**Spec:** `docs/superpowers/specs/2026-07-05-email-templates-design.md`

## Global Constraints

- NO backward compatibility required (sanctioned breaking: template-owned subjects, `extension_mappings` removal, enhanced-branch retirement).
- Registry collision rule: same-owner re-register replaces; different-owner collision THROWS at boot. Key grammar `[a-z0-9][a-z0-9._-]*`.
- One render entry point: `TemplateRenderer::render(key, data)`; unknown key = loud error on EVERY path.
- `EmailChannel` never caches transport settings at construction — per-send `EmailSettings::effectiveConfig()` merged with `config('emailnotification')` (security/debug stay deploy-owned; the domain policy must fail closed after DB settings are saved).
- SMTP password encrypted at rest (`EncryptionService`, AAD `email.smtp_password`), never in API responses (`password_set` only).
- v1 mailer vocabulary: `smtp` + mailers already configured in `services.mail.mailers` (save-time validated). No sendmail.
- Permission via `permissions()` + `Permission::define('email.templates.manage')`; every route explicitly gated `email_permission:email.templates.manage` (the Audit alias pattern).
- Unreleased contracts package wires via composer path repository; pin real versions at release.
- Commit per task; no attribution trailers; releases are the user's.

## Repos touched

| Task | Repo |
| --- | --- |
| 1 | `extensions/contracts` (glueful/extension-contracts) |
| 2–7 | `extensions/email-notification` |

---

### Task 1: `Email/` contracts (extension-contracts repo)

**Files:**
- Create: `src/Email/EmailTemplatePlaceholder.php`, `src/Email/EmailTemplateDefinition.php`, `src/Email/EmailTemplateRegistry.php`
- Test: `tests/Unit/Email/ValueObjectsTest.php`

**Interfaces (verbatim):**

```php
final class EmailTemplatePlaceholder
{
    public function __construct(
        public readonly string $name,        // engine variable name, e.g. 'app_name'
        public readonly string $description,
        public readonly string $sample,      // drives test-sends/previews
    ) {
    }
}

final class EmailTemplateDefinition
{
    /** @param list<EmailTemplatePlaceholder> $placeholders */
    public function __construct(
        public readonly string $key,            // [a-z0-9][a-z0-9._-]* — VALIDATED HERE (throws)
        public readonly string $label,
        public readonly string $description,
        public readonly string $defaultSubject,
        public readonly string $defaultBody,    // full Mustache-lite body
        public readonly array $placeholders = [],
        public readonly string $owner = '',     // package name, e.g. 'glueful/email-notification'
    ) {
        if (preg_match('/\A[a-z0-9][a-z0-9._-]*\z/', $key) !== 1) {
            throw new \InvalidArgumentException(
                "Email template key '{$key}' must match [a-z0-9][a-z0-9._-]*.",
            );
        }
    }
}

/**
 * Collision rule (spec §1 P1): implementations MUST allow re-registering a key
 * only when the new definition's owner equals the existing one; a different
 * owner claiming an existing key throws at boot. Boot order is never a
 * correctness boundary.
 */
interface EmailTemplateRegistry
{
    public function register(EmailTemplateDefinition ...$definitions): void;

    /** @return list<EmailTemplateDefinition> */
    public function all(): array;

    public function find(string $key): ?EmailTemplateDefinition;
}
```

- [ ] **Step 1: Failing tests** — VO construction/defaults; key grammar (dots accepted: `lemma.comment-reply`; `Bad`, `-x`, `a b`, `''` throw).
- [ ] **Step 2–4: fail → implement → pass** (`vendor/bin/phpunit && composer run phpcs && composer run analyze`).
- [ ] **Step 5: Commit** — `git add -A && git commit -m "feat: Email contracts — template definition/placeholder VOs + registry seam"`

---

### Task 2: Registry implementation + built-in definitions

**Repo:** `extensions/email-notification` (this and all following tasks)

**Files:**
- Modify: `composer.json` (path repo `../contracts` + require `glueful/extension-contracts: *@dev`)
- Create: `src/Templates/DefinitionRegistry.php`, `src/Templates/BuiltInDefinitions.php`
- Modify: `src/EmailNotificationServiceProvider.php` (bind `EmailTemplateRegistry` → `DefinitionRegistry`, register built-ins in `boot()`; `use` imports)
- Test: `tests/DefinitionRegistryTest.php`

**Interfaces:**
- `DefinitionRegistry implements EmailTemplateRegistry` — the collision rule:

```php
public function register(EmailTemplateDefinition ...$definitions): void
{
    foreach ($definitions as $def) {
        $existing = $this->definitions[$def->key] ?? null;
        if ($existing !== null && $existing->owner !== $def->owner) {
            throw new \RuntimeException(
                "Email template key '{$def->key}' is owned by '{$existing->owner}'"
                . " — '{$def->owner}' cannot replace it. Prefix cross-extension keys"
                . " with your package name (e.g. 'myext.notice')."
            );
        }
        $this->definitions[$def->key] = $def;
    }
}
```

- `BuiltInDefinitions::all(): list<EmailTemplateDefinition>` — the five (`verification`, `password-reset`, `welcome`, `alert`, `default`), `owner: 'glueful/email-notification'`, `defaultBody` = the existing `src/Templates/html/*.html` file contents (read once), `defaultSubject` per template (e.g. `Verify your {{app_name}} email`), real placeholder metadata (`app_name`, `app_url`, `otp`, `expiry_minutes`, `token`, … with descriptions + samples — read each html file and enumerate its actual variables).

- [ ] **Step 1: Failing tests** — same-owner replace; different-owner collision throws with the key + owners in the message; `all()`/`find()`; built-ins registered after provider boot (or via `BuiltInDefinitions::all()` directly in the unit test — match the pack's existing test harness style, see `EmailNotificationProviderTest`).
- [ ] **Step 2–4: fail → implement → pass.**
- [ ] **Step 5: Commit.**

---

### Task 3: Migrations — overrides + settings tables

**Files:**
- Create: `migrations/001_CreateEmailTemplatesTable.php`, `migrations/002_CreateEmailSettingsTable.php`
- Modify: `src/EmailNotificationServiceProvider.php` (`loadMigrationsFrom(__DIR__ . '/../migrations', …)` in `boot()`, OUTSIDE any enable gate — pack convention), `composer.json` (classmap `migrations/`)
- Test: `tests/MigrationsTest.php`

**Schemas (the subscriptions migration shape — `MigrationInterface`, `hasTable` guard, `down()`, `getDescription()`):**

```php
// email_templates — one row per OVERRIDDEN template
$table->bigInteger('id')->primary()->autoIncrement();
$table->string('uuid', 12);
$table->string('template_key', 191);
$table->text('subject');
$table->text('body');
$table->string('updated_by', 12)->nullable();
$table->timestamp('created_at')->default('CURRENT_TIMESTAMP');
$table->timestamp('updated_at')->nullable();
$table->unique('uuid');
$table->unique('template_key');

// email_settings — key/value transport overrides ('password' value is ciphertext)
$table->bigInteger('id')->primary()->autoIncrement();
$table->string('setting_key', 64);
$table->text('value');
$table->timestamp('created_at')->default('CURRENT_TIMESTAMP');
$table->timestamp('updated_at')->nullable();
$table->unique('setting_key');
```

- [ ] **Step 1: Failing test** — tables exist; duplicate `template_key`/`setting_key` rejected. (Build the pack a minimal SQLite harness if none exists: the `CommerceTestCase` in-memory pattern — `new Connection(['engine' => 'sqlite', …])` + run the migration classes; add `tests/Support/DbTestCase.php`.)
- [ ] **Step 2–4: fail → implement → pass.**
- [ ] **Step 5: Commit.**

---### Task 4: Engine seam + the ONE renderer + enhanced-branch retirement

**Files:**
- Create: `src/Templates/TemplateEngine.php` (interface), `src/Templates/MustacheLiteEngine.php`, `src/Templates/TemplateRenderer.php`, `src/Templates/OverrideRepository.php`
- Modify: `src/EmailFormatter.php` (delegate to the engine; remove `renderTemplate` internals + template file lookup), `src/EmailChannel.php` (delete the `data['template']`/enhanced branch — createEmail funnels through `TemplateRenderer`), `src/EmailNotificationServiceProvider.php` (bindings; retire `EnhancedEmailFormatter` service)
- Delete: `src/EnhancedEmailFormatter.php` (and its config hooks)
- Test: `tests/MustacheLiteEngineTest.php`, `tests/TemplateRendererTest.php`; delete/replace `tests/EnhancedEmailFormatterTest.php`, `tests/EnhancedTemplatePathTest.php`; keep `tests/EmailFormatterEscapingTest.php` GREEN (escaping behavior must survive the move)

**Interfaces:**
- `TemplateEngine::render(string $template, array $data): string` and `TemplateEngine::violations(string $template): list<string>` (unbalanced `{{#if}}`/`{{/if}}` detection — the save-time 422 source).
- `MustacheLiteEngine` — the CURRENT `replaceVariables` behavior MOVED (escaping of every interpolated scalar, `{{> partial}}` from the shipped partials dir, `{{#if}}` truthiness, unknown vars render empty) — not rewritten; the pinned-behavior tests are written against the OLD formatter first, then the extraction must keep them green.
- `OverrideRepository::find(string $key): ?array{subject:string,body:string}`, `save(key, subject, body, ?updatedBy): void`, `delete(key): bool` — over `email_templates`.
- `TemplateRenderer::render(string $key, array $data): array{subject:string, html:string}`:

```php
public function render(string $key, array $data): array
{
    $definition = $this->registry->find($key);
    if ($definition === null) {
        throw new \RuntimeException("Unknown email template '{$key}' — templates must be registered.");
    }
    $override = $this->overrides->find($key);
    $subject = $this->engine->render($override['subject'] ?? $definition->defaultSubject, $data);
    $body = $this->engine->render($override['body'] ?? $definition->defaultBody, $data);
    return ['subject' => $subject, 'html' => $this->layout->wrap($body, $data + ['subject' => $subject])];
}
```

  (`$this->layout` = the existing applyLayout/partials furniture, extracted alongside. Caller-supplied subjects are IGNORED — subject is template-owned.)

- [ ] **Step 1:** Write the pinned-behavior engine tests against current behavior; run GREEN before touching anything.
- [ ] **Step 2:** Extract `MustacheLiteEngine` + layout; tests stay green. Add `violations()` + its cases.
- [ ] **Step 3:** `TemplateRenderer` + failing tests (unknown key throws; override beats default; subject renders placeholders; caller subject ignored).
- [ ] **Step 4:** Rewire `EmailFormatter`/`EmailChannel`; DELETE the enhanced branch + `EnhancedEmailFormatter`; full pack suite green (`AttachmentConfinementTest`, `EmailChannelDomainPolicyTest`, escaping tests untouched and passing).
- [ ] **Step 5: Commit.**

---

### Task 5: DB-backed settings + per-send resolution

**Files:**
- Create: `src/Settings/EmailSettings.php`, `src/Settings/SettingsRepository.php`
- Modify: `src/EmailChannel.php` (no constructor config snapshot — per-send `effective()`), `src/TransportFactory.php` (consume the materialized array), provider bindings
- Test: `tests/EmailSettingsTest.php`, additions to `tests/EmailChannelDomainPolicyTest.php`

**Interfaces:**
- `SettingsRepository` — kv get/set/forget over `email_settings`; `password` stored as `EncryptionService::encrypt($value, aad: 'email.smtp_password')` ciphertext, decrypted only inside `effectiveConfig()`.
- `EmailSettings::effectiveConfig(): array` — EXACTLY the spec §3b shape: per key DB row → `services.mail` path; unmodeled keys pass through; explicit-empty row clears to fallback; `mailer` values outside `['smtp', ...array_keys(config('services.mail.mailers'))]` are rejected at SAVE time (the API layer 422s; the reader treats an impossible stored value as fallback + log).
- `EmailChannel`: `sendNotification()`/`createTransport()`/availability compose per send:

```php
$config = array_replace_recursive(
    (array) config($this->context, 'emailnotification'),   // security/debug/logging — deploy-owned
    ['mail' => $this->settings->effectiveConfig()],          // transport — DB-overridable
);
```

  (Adapt the exact merge point to how `$this->config` is consumed today — read `EmailChannel::__construct` first; the REQUIREMENT is: no transport field is read from a constructor-time snapshot, and `emailnotification.security` is present in every policy check.)

- [ ] **Step 1: Failing tests** — precedence per key; explicit-empty clears; password ciphertext at rest + decrypts through `effectiveConfig()`; **constructor-snapshot regression**: build the channel, save a DB row, next send uses it; **policy-survives-rewire regression** (in `EmailChannelDomainPolicyTest`): allowed-domain policy configured, DB SMTP row saved, send to disallowed recipient still fails closed.
- [ ] **Step 2–4: fail → implement → pass** (full suite).
- [ ] **Step 5: Commit.**

---

### Task 6: Permission + routes + controllers

**Files:**
- Create: `src/Http/RequireEmailPermission.php` (mirror `audit/src/.../RequireAuditPermission` — read it first), `src/Http/TemplatesController.php`, `src/Http/SettingsController.php`, `routes.php`
- Modify: `src/EmailNotificationServiceProvider.php` (`permissions()` with `Permission::define('email.templates.manage')->label('Manage email templates & settings')->category('Email')->resource('email')->managedBy('glueful/email-notification')`; middleware alias `email_permission`; `loadRoutesFrom`)
- Test: `tests/EmailAdminApiTest.php`

**Routes (`routes.php`):**

```php
$router->group(['prefix' => '/email', 'middleware' => ['auth']], function (Router $router): void {
    $gate = 'email_permission:email.templates.manage';
    $router->get('/templates', [TemplatesController::class, 'index'])->middleware([$gate]);
    $router->put('/templates/{key}', [TemplatesController::class, 'save'])
        ->where('key', '[a-z0-9][a-z0-9._-]*')->middleware([$gate]);
    $router->delete('/templates/{key}', [TemplatesController::class, 'reset'])
        ->where('key', '[a-z0-9][a-z0-9._-]*')->middleware([$gate]);
    $router->post('/templates/{key}/test', [TemplatesController::class, 'testSend'])
        ->where('key', '[a-z0-9][a-z0-9._-]*')->middleware([$gate]);
    $router->get('/settings', [SettingsController::class, 'show'])->middleware([$gate]);
    $router->put('/settings', [SettingsController::class, 'save'])->middleware([$gate]);
    $router->post('/settings/test', [SettingsController::class, 'testSend'])->middleware([$gate]);
});
```

**Controller behavior (spec §4/§3b):**
- `TemplatesController::index` — every definition: key/label/description/owner/placeholders (name+description+sample)/effective subject+body/`overridden`.
- `save` — 404 unregistered key; 422 empty subject/body or `TemplateEngine::violations()` non-empty; writes the override.
- `reset` — delete row (404 when none).
- `testSend` — `{to}`: `TemplateRenderer::render($key, samplesFrom($definition))` (each placeholder's `sample`), send through the real channel; 422 when transport unconfigured/unavailable.
- `SettingsController::show` — effective values + `password_set`, NEVER the password; `save` — partial update, password only when supplied non-empty, `mailer` validated against the configured set; `testSend` — plain test message via stored settings.

- [ ] **Step 1: Failing tests** — list shape; save/reset lifecycle; unknown-key 404; unbalanced-`{{#if}}` 422; settings round-trip + `password_set` + no-password-in-response; every route rejects without the permission (drive the middleware directly, matching how the audit pack tests its gate — read those tests first).
- [ ] **Step 2–4: fail → implement → pass.**
- [ ] **Step 5: Commit.**

---

### Task 7: Config cleanup + docs + gates

- [ ] Remove `templates.extension_mappings` (and dead template-path config) from `config/emailnotification.php`; keep `security`/`retry`/`debug`/`logging` untouched.
- [ ] README: the registry (how another extension registers — the soft-binding snippet), the API surface, the trust model (operator permission), the settings precedence (DB → env; password encrypted; policy stays deploy-owned).
- [ ] CHANGELOG `[Unreleased]`: everything above, flagged breaking (template-owned subjects, enhanced branch removed, `extension_mappings` gone).
- [ ] Full gates: `vendor/bin/phpunit && composer run phpcs && composer run analyze`.
- [ ] Commit. (Release + version pins: the user's, later — contracts package releases first, then email-notification pins it.)

---

## Self-Review Notes (completed)

- **Spec coverage:** §1 contracts + collision P1 + dotted keys → Tasks 1–2; §2 storage/definition-first/enhanced-retirement P1 → Tasks 3–4; §3 engine seam + pinned escaping → Task 4 (tests-first extraction); §3b settings incl. per-send P1, policy-merge P1, materialized shape P2, mailer-vocabulary P2, encrypted password → Task 5 (+ API validation in Task 6); §4 API + permission-path P1 (permissions() + `email_permission` alias, no migration seed) → Task 6; §5 consumption — README snippet in Task 7; out-of-scope respected (no versioning, no Twig, no locales, no sendmail).
- **Verify-points for the executor:** `RequireAuditPermission` internals + how audit tests its gate (Task 6); `EmailChannel::__construct`/`getConfig` exact consumption before the Task 5 merge rewrite; each built-in html file's actual variable set for placeholder metadata (Task 2); whether the pack has a DB test harness already (Task 3 creates one if not).
- **Type consistency:** `TemplateRenderer::render(key, data): {subject, html}` consumed by both `EmailChannel` (Task 4) and `TemplatesController::testSend` (Task 6); `EmailSettings::effectiveConfig()` consumed by channel + settings controller; registry FQCNs from the contracts package everywhere.
