<?php

declare(strict_types=1);

namespace Glueful\Extensions\EmailNotification;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Logging\LogManager;
use Glueful\Notifications\Contracts\Notifiable;
use Glueful\Notifications\Contracts\NotificationExtension;
use Glueful\Notifications\Services\ChannelManager;

/**
 * Email Notification Provider
 *
 * Registers the email notification channel with the notification system.
 *
 * @package Glueful\Extensions\EmailNotification
 */
class EmailNotificationProvider implements NotificationExtension
{
    /**
     * @var array<string, mixed> Configuration settings
     */
    private array $config;
    /**
     * @var bool Whether the extension has been initialized
     */
    private bool $initialized = false;
    /**
     * @var EmailChannel|null The email channel instance
     */
    private ?EmailChannel $channel = null;

    /**
     * @var LogManager Logger instance
     */
    private LogManager $logger;
    private ApplicationContext $context;

    /**
     * Provider constructor
     *
     * @param EmailChannel|null $channel The shared, DI-built email channel. This is the SAME
     *        instance the framework registers as the live `email` notification channel (see
     *        {@see EmailNotificationServiceProvider::boot()}); the provider never constructs its
     *        own channel. Autowired by the container; nullable so the provider remains directly
     *        instantiable in unit tests.
     * @param array<string, mixed> $config Optional configuration to override defaults
     */
    public function __construct(
        ApplicationContext $context,
        ?EmailChannel $channel = null,
        array $config = []
    ) {
        $this->context = $context;
        $this->channel = $channel;

        // Load core mail configuration from services.php
        $coreMailConfig = config($this->context, 'services.mail') ?? [];

        // Load extension-specific configuration (modern location)
        $extensionConfig = config($this->context, 'emailnotification') ?? [];

        // Transform core mail config to match EmailChannel expectations
        $transformedCoreConfig = $this->transformCoreMailConfig($coreMailConfig);

        // Merge configurations: transformed core config + extension features + provided overrides.
        // NOTE: the injected EmailChannel owns the authoritative transport/security configuration
        // (it loads and merges its own copy with core-wins precedence). This merged copy exists
        // only for the provider's hook logic (app_name injection in beforeSend(), the debug/logging
        // flags) and for diagnostics (getExtensionInfo()/isEmailProviderConfigured()). Do not use
        // it to construct a transport -- that path lives solely in EmailChannel.
        $this->config = array_merge($transformedCoreConfig, $extensionConfig, $config);

        // Initialize logger
        $this->logger = new LogManager('email_notification');
    }

    /**
     * Transform core mail config to match EmailChannel expectations
     *
     * @param array<string, mixed> $coreConfig Core mail config from services.php
     * @return array<string, mixed> Transformed config for EmailChannel
     */
    private function transformCoreMailConfig(array $coreConfig): array
    {
        if (empty($coreConfig)) {
            return [];
        }

        $transformed = [];

        // Use the multi-mailer configuration structure
        $transformed = $coreConfig;

        return $transformed;
    }

    /**
     * Get the extension name
     *
     * @return string The name of the notification extension
     */
    public function getExtensionName(): string
    {
        return 'email_notification';
    }
    /**
     * Initialize the extension.
     *
     * Uses the shared, DI-built {@see EmailChannel} injected via the constructor -- it does NOT
     * build a second channel/formatter. (The framework's NotificationDispatcher only stores this
     * provider for beforeSend/afterSend hooks and never calls this method on the live path; the
     * live channel is the one registered in EmailNotificationServiceProvider::boot(). This method
     * remains for the NotificationExtension contract and for direct register() callers.)
     *
     * @param array<string, mixed> $config Configuration options for the extension (hook/diagnostics)
     * @return bool Whether the initialization was successful
     */
    public function initialize(array $config = []): bool
    {
        if (!empty($config)) {
            $this->config = array_merge($this->config, $config);
        }

        try {
            // No channel was injected (e.g. notification subsystem unavailable): nothing to wire.
            if ($this->channel === null) {
                $this->logger->error(
                    'Failed to initialize email notification extension: no EmailChannel was injected'
                );
                return false;
            }

            // Check if the (shared) channel is available
            if (!$this->channel->isAvailable()) {
                return false;
            }

            $this->initialized = true;
            return true;
        } catch (\Exception $e) {
            // Log the error using LogManager (config KEYS only -- values may carry credentials)
            $this->logger->error('Failed to initialize email notification extension: ' . $e->getMessage(), [
                'exception' => $e,
                'config_keys' => array_keys($this->config)
            ]);

            return false;
        }
    }

    /**
     * Get the supported notification types
     *
     * @return array<int, string> List of notification types supported by this extension
     */
    public function getSupportedNotificationTypes(): array
    {
        // Email channel can handle all notification types
        return [
            '*', // Wildcard to indicate support for all types
            'welcome',
            'password_reset',
            'account_verification',
            'security_alert',
            'system_notification',
            'user_mention'
        ];
    }

    /**
     * Process the notification before it's sent
     *
     * @param array<string, mixed> $data The notification data
     * @param Notifiable $notifiable The entity receiving the notification
     * @param string $channel The notification channel
     * @return array<string, mixed> The processed notification data
     */
    public function beforeSend(array $data, Notifiable $notifiable, string $channel): array
    {
        // Only process if this is for the email channel
        if ($channel !== 'email') {
            return $data;
        }

        // Add app name to the data for template rendering if not present
        if (!isset($data['app_name'])) {
            $data['app_name'] = $this->config['app_name'] ?? 'Glueful Application';
        }

        // Add current year if not present (useful for copyright notices in templates)
        if (!isset($data['current_year'])) {
            $data['current_year'] = date('Y');
        }

        // Check debug mode
        if (!empty($this->config['debug']['enabled'])) {
            // In debug mode, log the email but don't modify the data
            $this->logger->debug('Email notification to be sent', [
                'recipient' => $notifiable->routeNotificationFor('email'),
                'subject' => $data['subject'] ?? '',
                'notification_type' => $data['type'] ?? 'unknown',
            ]);
        }

        return $data;
    }

    /**
     * Process after a notification has been sent
     *
     * @param array<string, mixed> $data The notification data
     * @param Notifiable $notifiable The entity that received the notification
     * @param string $channel The notification channel
     * @param bool $success Whether the notification was sent successfully
     * @return void
     */
    public function afterSend(array $data, Notifiable $notifiable, string $channel, bool $success): void
    {
        // Only process if this is for the email channel
        if ($channel !== 'email') {
            return;
        }

        // Log the result if logging is enabled
        if (!empty($this->config['logging']['enabled'])) {
            if ($success) {
                $this->logger->info('Email notification sent successfully', [
                    'recipient' => $notifiable->routeNotificationFor('email'),
                    'subject' => $data['subject'] ?? '',
                    'notification_type' => $data['type'] ?? 'unknown'
                ]);
            } else {
                $this->logger->error('Failed to send email notification', [
                    'recipient' => $notifiable->routeNotificationFor('email'),
                    'subject' => $data['subject'] ?? '',
                    'notification_type' => $data['type'] ?? 'unknown'
                ]);
            }
        }
    }

    /**
     * Register the notification channel with the channel manager.
     *
     * Registers the SAME shared {@see EmailChannel} instance that was injected into this provider
     * (and that the framework registers as the live `email` channel) -- there is no second channel.
     *
     * @param ChannelManager $channelManager The notification channel manager
     * @return void
     */
    public function register(ChannelManager $channelManager): void
    {
        // Initialize the extension if not already initialized
        if (!$this->initialized) {
            $this->initialize($this->config);
        }

        // Register the shared channel instance with the channel manager
        if ($this->channel !== null) {
            $channelManager->registerChannel($this->channel);
        }
    }

    /**
     * Get extension information
     *
     * @return array<string, mixed> Extension metadata
     */
    public function getExtensionInfo(): array
    {
        return [
            'name' => 'Email Notification Channel',
            'version' => EmailNotificationServiceProvider::composerVersion(),
            'description' => 'Provides email notification capabilities using Symfony Mailer',
            'author' => 'Glueful',
            'channels' => ['email'],
            // Safe diagnostics ONLY. The merged $this->config carries SMTP/API credentials
            // (password/key/secret/token/username/dsn); returning it raw leaked those to any
            // diagnostic surface. Expose non-sensitive metadata instead: the default mailer's
            // transport type, the from address (public-facing -- it appears on every sent email),
            // and boolean feature flags. No credential VALUES are ever included here.
            'config' => $this->safeConfigSummary(),
        ];
    }

    /**
     * Build a credential-free summary of the mail configuration for diagnostics.
     *
     * Returns the default mailer name and its transport type (identifiers, not secrets), the
     * from address (public-facing), and boolean feature flags. Deliberately excludes every
     * credential field (host, port, username, password, key, secret, token, dsn, ...).
     *
     * @return array<string, mixed> Safe, value-free-of-credentials config summary
     */
    private function safeConfigSummary(): array
    {
        $defaultMailer = is_string($this->config['default'] ?? null) ? $this->config['default'] : 'smtp';

        $transport = null;
        $mailers = $this->config['mailers'] ?? null;
        if (is_array($mailers) && isset($mailers[$defaultMailer]) && is_array($mailers[$defaultMailer])) {
            $candidate = $mailers[$defaultMailer]['transport'] ?? $defaultMailer;
            $transport = is_string($candidate) ? $candidate : $defaultMailer;
        }

        $from = $this->config['from'] ?? null;
        $fromAddress = (is_array($from) && is_string($from['address'] ?? null)) ? $from['address'] : null;

        $security = is_array($this->config['security'] ?? null) ? $this->config['security'] : [];

        return [
            'default_mailer' => $defaultMailer,
            'transport' => $transport,
            // From address is public-facing (it appears in the headers of every email this channel
            // sends), so exposing it in diagnostics reveals nothing a recipient cannot already see.
            'from_address' => $fromAddress,
            'features' => [
                'debug_enabled' => !empty($this->config['debug']['enabled']),
                'logging_enabled' => !empty($this->config['logging']['enabled']),
                'domain_policy_configured' =>
                    !empty($security['allowed_domains']) || !empty($security['blocked_domains']),
                'attachment_confinement_configured' => !empty($security['attachment_allowed_paths']),
            ],
        ];
    }

    /**
     * Check if email provider is properly configured
     *
     * @return bool True if email provider is properly configured
     */
    public function isEmailProviderConfigured(): bool
    {
        try {
            // Use the merged configuration from the provider instead of loading fresh from config
            $mailConfig = $this->config;
            if (empty($mailConfig) || !is_array($mailConfig)) {
                $this->logger->error("Mail configuration is missing or invalid");
                return false;
            }

            // Get the default mailer (smtp, ses, mailgun, etc.)
            $defaultMailer = $mailConfig['default'] ?? 'smtp';

            // Check multi-mailer configuration
            if (!isset($mailConfig['mailers'][$defaultMailer])) {
                $this->logger->error("Mail driver '{$defaultMailer}' configuration is missing");
                return false;
            }

            $driverConfig = $mailConfig['mailers'][$defaultMailer];
            $transport = $driverConfig['transport'] ?? $defaultMailer;

            // Check specific driver requirements. Transport strings must match the values the
            // TransportFactory actually switches on (provider bridges are suffixed `+api`/`+smtp`).
            // Credential keys mirror exactly what each TransportFactory factory method requires.
            switch ($transport) {
                case 'smtp':
                    if (empty($driverConfig['host'])) {
                        $this->logger->error("SMTP host is not configured");
                        return false;
                    }
                    if (empty($driverConfig['port'])) {
                        $this->logger->error("SMTP port is not configured");
                        return false;
                    }
                    break;

                case 'ses+api':
                    if (empty($driverConfig['key']) || empty($driverConfig['secret'])) {
                        $this->logger->error("Amazon SES credentials are missing");
                        return false;
                    }
                    break;

                case 'mailgun+api':
                    if (empty($driverConfig['domain']) || empty($driverConfig['key'])) {
                        $this->logger->error("Mailgun credentials are missing");
                        return false;
                    }
                    break;

                case 'sendgrid+api':
                    if (empty($driverConfig['key'])) {
                        $this->logger->error("SendGrid API key is missing");
                        return false;
                    }
                    break;

                case 'postmark+api':
                    if (empty($driverConfig['token'])) {
                        $this->logger->error("Postmark server token is missing");
                        return false;
                    }
                    break;

                case 'brevo+api':
                    if (empty($driverConfig['key'])) {
                        $this->logger->error("Brevo API key is missing");
                        return false;
                    }
                    break;

                case 'brevo+smtp':
                    if (empty($driverConfig['username']) || empty($driverConfig['password'])) {
                        $this->logger->error("Brevo SMTP credentials are missing");
                        return false;
                    }
                    break;

                // No-credential sinks: explicitly supported, nothing to validate.
                case 'null':
                case 'log':
                case 'array':
                    break;

                default:
                    // An unrecognized transport must not silently pass -- credential validation
                    // would never run for it. Fail closed.
                    $this->logger->error("Unknown mail transport '{$transport}'; cannot validate credentials");
                    return false;
            }

            // Check from address is configured
            if (empty($mailConfig['from']['address'])) {
                $this->logger->error("From email address is not configured");
                return false;
            }

            // Check if the channel is initialized and available
            if (!$this->initialized || $this->channel === null) {
                $this->logger->error("Email provider is not initialized");
                return false;
            }

            return $this->channel->isAvailable();
        } catch (\Exception $e) {
            $this->logger->error("Error checking email provider configuration: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Get the configuration
     *
     * @return array<string, mixed> Configuration settings
     */
    public function getConfig(): array
    {
        return $this->config;
    }

    /**
     * Set configuration option
     *
     * @param string $key Configuration key
     * @param mixed $value Configuration value
     * @return self
     */
    public function setConfig(string $key, $value): self
    {
        $this->config[$key] = $value;
        return $this;
    }

    /**
     * Get resource metrics for this provider
     *
     * Returns information about email sending metrics like:
     * - Emails sent count
     * - Success rate
     * - Average delivery time
     *
     * @return array<string, mixed> Email provider metrics
     */
    public function getMetrics(): array
    {
        // Default metrics
        $metrics = [
            'emails_sent' => 0,
            'emails_failed' => 0,
            'success_rate' => 100,
            'avg_delivery_time' => 0,
            'last_email_sent' => null,
            'email_queue_size' => 0,
            'most_common_types' => [],
            'read_rate' => 0
        ];

        try {
            // Use fluent QueryBuilder interface
            /** @var \Glueful\Database\Connection $db */
            $db = app($this->context, \Glueful\Database\Connection::class);

            // Count total emails sent through email channel
            $sentEmails = $db
                ->table('notifications')
                ->whereJsonContains('data', 'email', '$.channels')
                ->whereNotNull('sent_at')
                ->count();

            $metrics['emails_sent'] = $sentEmails;

            // Count failed emails (those with error data)
            $failedEmails = $db
                ->table('notifications')
                ->whereJsonContains('data', 'email', '$.channels')
                ->whereJsonContains('data', 'true', '$.error')
                ->count();

            $metrics['emails_failed'] = $failedEmails;

            // Calculate success rate if we have data
            if (($metrics['emails_sent'] + $metrics['emails_failed']) > 0) {
                $metrics['success_rate'] = round(
                    ($metrics['emails_sent'] / ($metrics['emails_sent'] + $metrics['emails_failed'])) * 100,
                    2
                );
            }

            // Get last sent email timestamp
            $lastSentEmail = $db
                ->table('notifications')
                ->select(['sent_at'])
                ->whereJsonContains('data', 'email', '$.channels')
                ->whereNotNull('sent_at')
                ->orderBy(['sent_at' => 'DESC'])
                ->limit(1)
                ->get();

            $metrics['last_email_sent'] = !empty($lastSentEmail) ? $lastSentEmail[0]['sent_at'] : null;

            // Calculate read rate
            $readEmails = $db
                ->table('notifications')
                ->whereJsonContains('data', 'email', '$.channels')
                ->whereNotNull('read_at')
                ->count();

            if ($metrics['emails_sent'] > 0) {
                $metrics['read_rate'] = round(($readEmails / $metrics['emails_sent']) * 100, 2);
            }

            // Get most common notification types for emails
            // Use database-agnostic aggregation query builder
            $whereClause = new \Glueful\Database\Query\WhereClause($db->getDriver());
            $aggregationQuery = $whereClause->buildAggregationQuery(
                'notifications',
                'type, COUNT(*) as count',
                'type',
                'count',
                'DESC',
                5,
                [['data', 'email', '$.channels']]
            );

            $commonTypesStmt = $db->getPDO()->prepare($aggregationQuery['query']);
            $commonTypesStmt->execute($aggregationQuery['bindings']);
            $commonTypesResult = $commonTypesStmt->fetchAll(\PDO::FETCH_ASSOC);

            $metrics['most_common_types'] = array_column($commonTypesResult, 'count', 'type');

            // Get email queue size - scheduled emails not yet sent
            $queueSize = $db
                ->table('notifications')
                ->whereJsonContains('data', 'email', '$.channels')
                ->whereNotNull('scheduled_at')
                ->whereNull('sent_at')
                ->count();

            $metrics['email_queue_size'] = $queueSize;
        } catch (\Exception $e) {
            $this->logger->error("Error getting email metrics from database: " . $e->getMessage());
            // Return default metrics on error
        }

        return $metrics;
    }
}
