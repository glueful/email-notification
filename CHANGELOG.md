# Changelog

All notable changes to this project will be documented in this file.

The format is based on Keep a Changelog, and this project adheres to Semantic Versioning.

## [Unreleased]

### Added

- **Recipient domain policy.** `EmailChannel` now enforces an optional allow-list /
  block-list on the recipient's domain (`security.allowed_domains` / `security.blocked_domains`,
  each a comma-separated string or array) **before** sending. A disallowed recipient yields a
  non-retryable `NotificationResult` failure (`blocked_domain`) and no mail is sent. With
  neither configured, all domains are allowed (prior behavior).
- **A test suite** (the extension previously had none). Covers the domain policy
  (allow/block/no-policy/no-recipient), `sendNotification()` success/failure result mapping via
  Symfony's null transport, `TransportFactory` DSN/transport building, and `EnhancedEmailFormatter`
  instantiation. Adds `phpunit.xml`.

### Changed

- **`EnhancedEmailFormatter` is now the default formatter** for `EmailChannel` (and registered
  in `services()`), so the enhanced-template path (priority, embedded images, attachments,
  custom headers) is actually reachable. Twig remains opt-in (off by default), so no
  optional `twig/twig` dependency is required.
- **Config honesty.** Removed config blocks that were defined but never enforced anywhere
  (`rate_limit.*`, `monitoring.*`, `performance.*`, `security.content_scanning`/`verify_ssl`,
  and the dead `events.listeners` entry pointing at a removed class). The README's
  corresponding "Performance Monitoring / Rate limiting" and over-broad "Enhanced Security"
  claims were removed/replaced with the real recipient-domain-policy docs; fixed the stale
  "Version 1.0.0 / Glueful 1.22.0" header.

### Fixed

- **`EnhancedEmailFormatter` was uninstantiable.** Its constructor called
  `parent::__construct($templates, $options)`, but the base `EmailFormatter` requires an
  `ApplicationContext` first -- so constructing it threw a `TypeError`, and the `EmailChannel`
  enhanced-template path (priority, embedded images, attachments, custom headers) could never
  run. The constructor now accepts and forwards `ApplicationContext`, and exposes
  `isUsingTwig()` (the Twig-enabled flag was previously write-only).
- **PHPStan (`composer run analyze`) is green again** (was 16 errors): fixed the constructor
  type error, removed an unreachable statement in `EmailFormatter::renderTemplate()` and five
  dead duplicate transport methods in `TransportFactory`, and added a `phpstan.neon.dist`
  (level 5) that ignores the optional `twig/twig` (`suggest`) class references.

## [1.8.0] - 2026-06-06 — Notification Subsystem Refinement (Framework 1.51)

### Added

- **Structured channel results.** `EmailChannel` now implements `Glueful\Notifications\Contracts\RichNotificationChannel` and returns a `NotificationResult` from `sendNotification()` — surfacing the Symfony provider message id, send latency, and stable error codes (`no_recipient` → non-retryable, `transport_exception` → retryable). The framework dispatcher (1.51.0+) records these per channel; the legacy `send(): bool` contract is preserved by delegating to `sendNotification()`.

### Changed

- **Minimum framework requirement raised to `glueful/framework >=1.51.0`** (`require-dev` pinned to `^1.51.0`).
- **Channel/hook registration migrated to the framework's extension helpers.** `EmailNotificationServiceProvider::boot()` now calls `registerNotificationChannel()` / `registerNotificationExtension()` instead of reaching into the container by hand. This is now the **only** wiring path — framework 1.51.0 stopped hardcoding the `EmailNotification` provider in its notification jobs, so an extension that doesn't register from `boot()` won't auto-wire into the shared dispatcher used by async dispatch/retries.
- **Retry config moved to the channel-agnostic key.** Framework 1.51.0 reads notification retry options from `notifications.retry` (was `emailnotification.retry`). The provider now merges its `retry` block under `notifications.retry` in `register()`, so existing `MAIL_RETRY_*` env tuning keeps working with no app change.

### Fixed

- **Extension version reporting.** `composerVersion()` read a non-existent top-level `version` key (returning `0.0.0` to the CLI/diagnostics); it now reads the canonical `extra.glueful.version`.

### Notes

- The active email-delivery path (formatting, transports, failover) is unchanged. The rich result is captured by sending through the configured transport directly (equivalent to the prior `Mailer::send()` path, which used no Messenger bus).

## [1.7.0] - 2026-06-05 — Framework 1.50 Compatibility

### Changed

- **Minimum framework requirement raised to `glueful/framework >=1.50.1`** (`require-dev` pinned to `^1.50.1`).
- Widened the `symfony/http-client` suggestion to `^6.3 || ^7.0` (the framework ships Symfony 7.4).

### Removed

- **`EmailNotificationListener`** (`src/Listeners/EmailNotificationListener.php`). It implemented `Glueful\Events\EventListener`, an interface **removed** in the framework's current event system (now `EventSubscriberInterface` registered via `EventService::subscribe()`). The listener was never registered (dead code) and broke static analysis against 1.50. If email-event retry/metrics are wanted, wire them as an `EventSubscriberInterface` subscriber.

### Notes

- Compatibility + cleanup release — **no change to the active email-delivery path** (channel registration, formatting, transports all unchanged). Requires Glueful Framework 1.50.1+.

## [1.6.0] - 2026-05-28

### Added
- **`two-factor-pin` email template** (`src/Templates/html/two-factor-pin.html`): Renders the 6-digit PIN for the framework's core email-PIN 2FA feature (Glueful Framework 1.45.0 "Fomalhaut"). Mirrors the existing `password-reset`/`verification` templates (`{{> header}}` / `{{> footer}}` partials) and uses the `{{pin}}` and `{{ttl_minutes}}` variables dispatched by `TwoFactorService`.

### Notes
- No breaking changes; no API changes. Purely additive — the template is only used when paired with Glueful Framework 1.45.0+ and `TWO_FACTOR_ENABLED=true`. Older framework versions simply ignore the unused template.
- Framework requirement unchanged (`>=1.30.0`).

## [1.5.0] - 2026-02-09

### Changed
- **Framework Compatibility**: Updated minimum framework requirement to Glueful 1.30.0 (Diphda release)
- **Exception Imports**: Migrated from deleted legacy bridge class to modern exception namespace
  - `Glueful\Exceptions\BusinessLogicException` → `Glueful\Http\Exceptions\Domain\BusinessLogicException` in `EmailFormatter`
- **composer.json**: Updated `extra.glueful.requires.glueful` to `>=1.30.0`, version bumped to `1.5.0`

### Notes
- No breaking changes to extension API. Import path change is internal.
- Requires Glueful Framework 1.30.0+ due to removal of legacy exception bridge classes.

## [1.4.0] - 2026-02-06

### Changed
- **EmailFormatter**: `applyLayout()` now searches `custom_paths` first, then falls back to the default templates path, consistent with partial and template resolution.
- **EmailFormatter**: `includePartial()` now searches `custom_paths` first, then extension defaults.
- **EmailFormatter**: `registerDefaultTemplates()` now loads built-in templates first, then overrides/extends from `custom_paths`.

- **Version Management**: Version is now read from `composer.json` at runtime via `EmailNotificationServiceProvider::composerVersion()`.
  - `getVersion()`, `registerMeta()`, and `NotivaProvider::getExtensionInfo()` all use `composerVersion()` instead of hardcoded strings.
  - Config `extension_version` is injected dynamically in `register()` from `composer.json`.
  - Future releases only require updating `composer.json` and `CHANGELOG.md`.

### Notes
- No breaking changes. Custom paths configured via `services.mail.templates.custom_paths` now consistently override built-in templates, partials, and layouts.

## [1.3.0] - 2026-01-31

### Changed
- **Framework Compatibility**: Updated minimum framework requirement to Glueful 1.22.0
  - Compatible with the new `ApplicationContext` dependency injection pattern
  - No code changes required in extension - framework handles context propagation
- **composer.json**: Updated `extra.glueful.requires.glueful` to `>=1.22.0`

### Notes
- This release ensures compatibility with Glueful Framework 1.22.0's context-based dependency injection
- All existing functionality remains unchanged
- Run `composer update` after upgrading

## [1.2.1] - 2026-01-24

### Changed
- **EmailChannel**: Improved configuration loading to merge core mail settings from `services.mail` with extension-specific config.
  - Core framework mail settings now take precedence for mail transport configuration.
  - Allows centralized mail configuration in the main application while extension-specific settings remain customizable.
- **EmailFormatter**: Updated logo URL resolution to use `global_variables` from config.
  - Now reads from `services.mail.templates.global_variables.logo_url` for consistency with core framework.
  - Falls back to extension default if not configured.

### Notes
- No breaking changes. Existing configurations continue to work.
- For centralized mail configuration, define settings in `config/services.php` under the `mail` key.

## [1.2.0] - 2026-01-17

### Breaking Changes
- **PHP 8.3 Required**: Minimum PHP version raised from 8.2 to 8.3.
- **Glueful 1.9.0 Required**: Minimum framework version raised to 1.9.0.

### Changed
- Updated `composer.json` PHP requirement to `^8.3`.
- Updated `extra.glueful.requires.glueful` to `>=1.9.0`.

### Notes
- Ensure your environment runs PHP 8.3 or higher before upgrading.
- Run `composer update` after upgrading.

## [1.0.0] - 2025-09-13

### Added
- Modern ServiceProvider-based integration (`Glueful\\Extensions\\ServiceProvider`).
- Composer-based provider discovery via `extra.glueful.provider`.
- New config file at `config/email-notification.php` merged via `register()`.
- Symfony Mailer transports with failover and provider bridges.

### Changed
- Composer metadata updated to reflect Symfony Mailer and modern discovery.
- Provider `getVersion()` now returns `1.0.0`.
- Minimum Glueful compatibility raised to `>=1.0.0` in `extra.glueful.requires`.

### Removed
- Legacy `manifest.json` (replaced by Composer discovery).
- Legacy top-level `EmailNotification` class.
- Legacy `src/config.php` (config moved to `config/email-notification.php`).

### Migration Notes
- Ensure your app uses the modern extensions system and runs `php glueful extensions:cache` after install.
- If overriding templates, update paths per README guidance.
- Install provider bridge packages as needed (e.g., `symfony/brevo-mailer`, `symfony/sendgrid-mailer`).

[1.0.0]: https://github.com/glueful/email-notification/releases/tag/v1.0.0
