# Changelog

All notable changes to this project will be documented in this file.

The format is based on Keep a Changelog, and this project adheres to Semantic Versioning.

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
