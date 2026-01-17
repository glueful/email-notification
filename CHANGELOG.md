# Changelog

All notable changes to this project will be documented in this file.

The format is based on Keep a Changelog, and this project adheres to Semantic Versioning.

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
