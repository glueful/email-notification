<?php

declare(strict_types=1);

namespace Glueful\Extensions\EmailNotification\Templates;

use Glueful\Extensions\Contracts\Email\EmailTemplateDefinition;
use Glueful\Extensions\Contracts\Email\EmailTemplatePlaceholder;

final class BuiltInDefinitions
{
    private const OWNER = 'glueful/email-notification';

    /**
     * @return list<EmailTemplateDefinition>
     */
    public static function all(): array
    {
        return [
            self::definition(
                'alert',
                'Alert',
                'Security alerts, important notices, and other action-oriented messages.',
                'Important alert from {{app_name}}',
                [
                    self::placeholder('message', 'Primary alert message.', 'We detected a new sign-in.'),
                    self::placeholder('details', 'Optional supporting details.', 'IP address: 203.0.113.10'),
                    self::placeholder('action_url', 'Optional action URL.', 'https://example.com/account/security'),
                    self::placeholder('app_name', 'Application name.', 'Glueful'),
                ]
            ),
            self::definition(
                'default',
                'Default notification',
                'General-purpose notification template for simple messages, OTPs, and calls to action.',
                'Notification from {{app_name}}',
                [
                    self::placeholder('name', 'Recipient display name.', 'Ada'),
                    self::placeholder('otp', 'Optional one-time code.', '123456'),
                    self::placeholder('expiry_minutes', 'Optional code expiry in minutes.', '15'),
                    self::placeholder('message', 'Optional message body.', 'Your account was updated.'),
                    self::placeholder('details', 'Optional supporting details.', 'Changed from Settings.'),
                    self::placeholder('action_url', 'Optional action URL.', 'https://example.com'),
                    self::placeholder('action_text', 'Optional action label.', 'Open'),
                    self::placeholder('app_name', 'Application name.', 'Glueful'),
                ]
            ),
            self::definition(
                'password-reset',
                'Password reset',
                'Password reset OTP with an optional reset link.',
                'Reset your {{app_name}} password',
                [
                    self::placeholder('name', 'Recipient display name.', 'Ada'),
                    self::placeholder('otp', 'Password reset one-time code.', '123456'),
                    self::placeholder('expiry_minutes', 'Code expiry in minutes.', '15'),
                    self::placeholder('reset_url', 'Optional password reset URL.', 'https://example.com/reset'),
                    self::placeholder('app_name', 'Application name.', 'Glueful'),
                ]
            ),
            self::definition(
                'two-factor-pin',
                'Two-factor PIN',
                'Two-factor authentication code for completing sign-in.',
                'Your {{app_name}} sign-in code',
                [
                    self::placeholder('pin', 'Two-factor sign-in code.', '123456'),
                    self::placeholder('ttl_minutes', 'Code expiry in minutes.', '10'),
                    self::placeholder('app_name', 'Application name.', 'Glueful'),
                ]
            ),
            self::definition(
                'verification',
                'Verification',
                'Email verification or account confirmation OTP.',
                'Verify your {{app_name}} email',
                [
                    self::placeholder('otp', 'Verification one-time code.', '123456'),
                    self::placeholder('expiry_minutes', 'Code expiry in minutes.', '15'),
                    self::placeholder('app_name', 'Application name.', 'Glueful'),
                ]
            ),
            self::definition(
                'welcome',
                'Welcome',
                'User onboarding and welcome emails.',
                'Welcome to {{app_name}}',
                [
                    self::placeholder('name', 'Recipient display name.', 'Ada'),
                    self::placeholder('message', 'Optional welcome message.', 'Thank you for joining us.'),
                    self::placeholder('action_url', 'Optional getting-started URL.', 'https://example.com/start'),
                    self::placeholder('action_text', 'Optional action label.', 'Get Started'),
                    self::placeholder('app_name', 'Application name.', 'Glueful'),
                ]
            ),
        ];
    }

    /**
     * @param list<EmailTemplatePlaceholder> $placeholders
     */
    private static function definition(
        string $key,
        string $label,
        string $description,
        string $subject,
        array $placeholders
    ): EmailTemplateDefinition {
        return new EmailTemplateDefinition(
            key: $key,
            label: $label,
            description: $description,
            defaultSubject: $subject,
            defaultBody: self::templateBody($key),
            placeholders: $placeholders,
            owner: self::OWNER
        );
    }

    private static function placeholder(string $name, string $description, string $sample): EmailTemplatePlaceholder
    {
        return new EmailTemplatePlaceholder($name, $description, $sample);
    }

    private static function templateBody(string $key): string
    {
        $path = __DIR__ . '/html/' . $key . '.html';
        $body = file_get_contents($path);
        if (!is_string($body)) {
            throw new \RuntimeException("Built-in email template '{$key}' is missing.");
        }

        return $body;
    }
}
