<?php

declare(strict_types=1);

namespace Glueful\Extensions\EmailNotification;

use Glueful\Logging\LogManager;
use Glueful\Notifications\Services\ChannelManager;

/**
 * Email Notification Service Provider
 *
 * Registers all services for the EmailNotification extension
 */
class EmailNotificationServiceProvider extends \Glueful\Extensions\ServiceProvider
{
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
        return '1.0.0';
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
            EmailFormatter::class => [
                'class' => EmailFormatter::class,
                'shared' => true,
            ],
            EmailChannel::class => [
                'class' => EmailChannel::class,
                'shared' => true,
                // No DSL arguments; channel loads config internally and will
                // construct its own formatter if none provided.
            ],
            EmailNotificationProvider::class => [
                'class' => EmailNotificationProvider::class,
                'shared' => true,
                // Provider merges core + extension config internally; do not inject via %config%.
            ],
        ];
    }

    /**
     * Register the extension
     */
    public function register(): void
    {
        // Merge extension defaults under the emailnotification key
        $this->mergeConfig('emailnotification', require __DIR__ . '/../config/emailnotification.php');
    }

    /**
     * Boot the extension
     */
    public function boot(): void
    {
        // Register the email channel with the notification system
        if ($this->app->has(ChannelManager::class)) {
            $provider = $this->app->get(EmailNotificationProvider::class);
            if (method_exists($provider, 'initialize')) {
                $provider->initialize();
            }

            $channel = $this->app->get(EmailChannel::class);
            $this->app->get(ChannelManager::class)->registerChannel($channel);

            // If a dispatcher is available via DI, also register provider hooks
            if ($this->app->has(\Glueful\Notifications\Services\NotificationDispatcher::class)) {
                $dispatcher = $this->app->get(\Glueful\Notifications\Services\NotificationDispatcher::class);
                if (method_exists($dispatcher, 'registerExtension')) {
                    $dispatcher->registerExtension($provider);
                }
            }
        }

        // Register extension metadata for CLI and diagnostics
        try {
            $this->app->get(\Glueful\Extensions\ExtensionManager::class)->registerMeta(self::class, [
                'slug' => 'email-notification',
                'name' => 'EmailNotification',
                'version' => '1.1.1',
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
     */
    public function getDependencies(): array
    {
        // No dependencies on other extensions
        return [];
    }
}
