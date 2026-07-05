<?php

declare(strict_types=1);

namespace Glueful\Extensions\EmailNotification;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Database\Migrations\MigrationPriority;
use Glueful\Extensions\Contracts\Email\EmailTemplateRegistry;
use Glueful\Extensions\EmailNotification\Templates\BuiltInDefinitions;
use Glueful\Extensions\EmailNotification\Templates\DefinitionRegistry;
use Glueful\Extensions\EmailNotification\Templates\MustacheLiteEngine;
use Glueful\Extensions\EmailNotification\Templates\OverrideRepository;
use Glueful\Extensions\EmailNotification\Templates\TemplateEngine;
use Glueful\Extensions\EmailNotification\Templates\TemplateRenderer;

/**
 * Email Notification Service Provider
 *
 * Registers all services for the EmailNotification extension
 */
class EmailNotificationServiceProvider extends \Glueful\Extensions\ServiceProvider
{
    private static ?string $cachedVersion = null;

    /**
     * Read the extension version from composer.json (cached)
     */
    public static function composerVersion(): string
    {
        if (self::$cachedVersion === null) {
            $path = __DIR__ . '/../composer.json';
            $raw = file_get_contents($path);
            $composer = is_string($raw) ? json_decode($raw, true) : null;
            $composer = is_array($composer) ? $composer : [];
            $extra = is_array($composer['extra'] ?? null) ? $composer['extra'] : [];
            $glueful = is_array($extra['glueful'] ?? null) ? $extra['glueful'] : [];
            // Canonical version lives under extra.glueful.version (this is a library package
            // with no top-level "version" key); fall back to a top-level key then a sentinel.
            $version = $glueful['version'] ?? $composer['version'] ?? '0.0.0';
            self::$cachedVersion = is_string($version) ? $version : '0.0.0';
        }

        return self::$cachedVersion;
    }

    /**
     * Get the extension name
     */
    public function getName(): string
    {
        return 'EmailNotification';
    }

    /**
     * Get the extension version
     */
    public function getVersion(): string
    {
        return self::composerVersion();
    }

    /**
     * Get the extension description
     */
    public function getDescription(): string
    {
        return 'Provides email notification capabilities using Symfony Mailer';
    }

    /**
     * Compile-time service definitions
     */
    public static function services(): array
    {
        return [
            DefinitionRegistry::class => [
                'class' => DefinitionRegistry::class,
                'shared' => true,
                'autowire' => true,
                'alias' => [EmailTemplateRegistry::class],
            ],
            MustacheLiteEngine::class => [
                'class' => MustacheLiteEngine::class,
                'shared' => true,
                'autowire' => true,
                'alias' => [TemplateEngine::class],
            ],
            OverrideRepository::class => [
                'class' => OverrideRepository::class,
                'shared' => true,
                'autowire' => true,
            ],
            TemplateRenderer::class => [
                'class' => TemplateRenderer::class,
                'shared' => true,
                'autowire' => true,
            ],
            EmailFormatter::class => [
                'class' => EmailFormatter::class,
                'shared' => true,
                'autowire' => true,
            ],
            EmailChannel::class => [
                'class' => EmailChannel::class,
                'shared' => true,
                'autowire' => true,
                // No DSL arguments; channel loads config internally and will
                // construct its own formatter if none provided.
            ],
            EmailNotificationProvider::class => [
                'class' => EmailNotificationProvider::class,
                'shared' => true,
                'autowire' => true,
                // Provider merges core + extension config internally; do not inject via %config%.
            ],
        ];
    }

    /**
     * Register the extension
     */
    public function register(ApplicationContext $context): void
    {
        // Merge extension defaults under the emailnotification key
        $config = require __DIR__ . '/../config/emailnotification.php';
        $config['templates']['extension_variables']['extension_version'] = self::composerVersion();
        $this->mergeConfig('emailnotification', $config);

        // Framework 1.51.0 moved notification retry config to the channel-agnostic
        // `notifications.retry` key (formerly read from `emailnotification.retry`). Surface our
        // retry tuning there so the core retry service/command picks it up unchanged.
        if (isset($config['retry']) && is_array($config['retry'])) {
            $this->mergeConfig('notifications', ['retry' => $config['retry']]);
        }
    }

    /**
     * Boot the extension
     */
    public function boot(ApplicationContext $context): void
    {
        $this->loadMigrationsFrom(__DIR__ . '/../migrations', MigrationPriority::DEFAULT, 'glueful/email-notification');

        if ($this->app->has(EmailTemplateRegistry::class)) {
            $registry = $this->app->get(EmailTemplateRegistry::class);
            if ($registry instanceof EmailTemplateRegistry) {
                $registry->register(...BuiltInDefinitions::all());
            }
        }

        // Register the email channel and its before/after-send hooks through the framework's
        // extension helpers (1.51.0+). These resolve the shared container ChannelManager /
        // NotificationDispatcher and no-op if the notification subsystem isn't present — this is
        // now the only wiring path (the framework no longer hardcodes this provider).
        $this->registerNotificationChannel($this->app->get(EmailChannel::class));
        $this->registerNotificationExtension($this->app->get(EmailNotificationProvider::class));

        // Register extension metadata for CLI and diagnostics
        try {
            $this->app->get(\Glueful\Extensions\ExtensionManager::class)->registerMeta(self::class, [
                'slug' => 'email-notification',
                'name' => 'EmailNotification',
                'version' => self::composerVersion(),
                'description' => 'Provides email notification capabilities using Symfony Mailer',
            ]);
        } catch (\Throwable $e) {
            // Non-critical
            error_log('[EmailNotification] Failed to register extension metadata: ' . $e->getMessage());
        }
    }

    /**
     * Register extension routes
     */
    public function routes(): void
    {
        // Email notification extension doesn't have routes
    }

    /**
     * Get extension dependencies
     *
     * @return array<int, string>
     */
    public function getDependencies(): array
    {
        // No dependencies on other extensions
        return [];
    }
}
