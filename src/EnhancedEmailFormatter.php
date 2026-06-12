<?php

declare(strict_types=1);

namespace Glueful\Extensions\EmailNotification;

use Glueful\Bootstrap\ApplicationContext;
use Symfony\Component\Mime\Email;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;

/**
 * Enhanced Email Formatter with Twig Support
 *
 * Extends the base EmailFormatter to add optional Twig template support
 * and enhanced Symfony Mailer features while maintaining backward compatibility
 *
 * @package Glueful\Extensions\EmailNotification
 */
class EnhancedEmailFormatter extends EmailFormatter
{
    /**
     * @var Environment|null Twig environment for template rendering
     */
    private ?Environment $twig = null;

    /**
     * @var bool Whether to use Twig for template rendering
     */
    private bool $useTwig = false;

    /**
     * @var ApplicationContext Application context (kept for default attachment-path confinement
     *                         when buildEmailFromTemplate() is called without an explicit validator)
     */
    private ApplicationContext $enhancedContext;

    /**
     * EnhancedEmailFormatter constructor
     *
     * @param ApplicationContext $context Application context (required by the base formatter)
     * @param array<string, array<string, mixed>|string> $templates Custom templates
     * @param array<string, mixed> $options Formatting options
     * @param bool $enableTwig Whether to enable Twig support
     */
    public function __construct(
        ApplicationContext $context,
        array $templates = [],
        array $options = [],
        bool $enableTwig = false
    ) {
        parent::__construct($context, $templates, $options);

        $this->enhancedContext = $context;

        if ($enableTwig) {
            $this->initializeTwig();
        }
    }

    /**
     * Initialize Twig environment
     */
    private function initializeTwig(): void
    {
        $templatesPath = $this->defaultOptions['templates_path'] ?? __DIR__ . '/Templates/twig';

        if (!is_dir($templatesPath)) {
            // Create twig templates directory if it doesn't exist
            mkdir($templatesPath, 0755, true);
        }

        $loader = new FilesystemLoader($templatesPath);
        $this->twig = new Environment($loader, [
            'cache' => false, // Disable cache for development
            'auto_reload' => true,
            'strict_variables' => false,
        ]);

        $this->useTwig = true;
    }

    /**
     * Format with Twig template
     *
     * @param string $template Template name (without .twig extension)
     * @param array<string, mixed> $data Template data
     * @return Email Symfony Email object
     */
    public function formatWithTwig(string $template, array $data): Email
    {
        if (!$this->twig) {
            throw new \RuntimeException('Twig is not initialized. Enable it in constructor.');
        }

        // Render HTML version
        $html = $this->twig->render($template . '.html.twig', $data);

        // Try to render text version if template exists
        $text = '';
        try {
            $text = $this->twig->render($template . '.text.twig', $data);
        } catch (\Exception $e) {
            // If text template doesn't exist, convert HTML to text
            $text = $this->htmlToText($html);
        }

        $email = new Email();
        $email->html($html);
        $email->text($text);

        return $email;
    }

    /**
     * Build an enhanced email with Symfony Mailer features
     *
     * Attachment and embedded-image paths originate from notification data (potentially
     * user-influenced) and are confined to an allowlist of base directories before they reach
     * Symfony. A rejected path throws {@see InvalidAttachmentException} (fail closed, not silently
     * skipped) -- {@see EmailChannel::sendNotification()} maps that to an `invalid_attachment`
     * failure. The validator is supplied by the channel so this branch and the channel's standard
     * branch share an identical check; when called directly without one, a default validator
     * confined to the application storage directory is built from the context.
     *
     * @param string $templateName Template name
     * @param array<string, mixed> $data Email data
     * @param AttachmentPathValidator|null $attachmentValidator Path confinement validator
     * @return Email Configured Email object
     * @throws InvalidAttachmentException If an attachment/embed path escapes the allowed dirs
     */
    public function buildEmailFromTemplate(
        string $templateName,
        array $data,
        ?AttachmentPathValidator $attachmentValidator = null
    ): Email {
        // Fail closed even on the direct-call path: default to a storage-confined validator.
        $attachmentValidator ??= AttachmentPathValidator::fromConfig($this->enhancedContext, []);

        // Use parent formatter to get HTML and text content
        $formatted = $this->format($data, $data['notifiable'] ?? new DummyNotifiable());

        $email = new Email();
        $email->subject($formatted['subject'] ?? '');
        $email->html($formatted['html_content'] ?? '');
        $email->text($formatted['text_content'] ?? '');

        // Enhanced features with Symfony Mailer

        // Set priority if specified
        if (isset($data['priority'])) {
            $priority = match ($data['priority']) {
                'highest' => Email::PRIORITY_HIGHEST,
                'high' => Email::PRIORITY_HIGH,
                'normal' => Email::PRIORITY_NORMAL,
                'low' => Email::PRIORITY_LOW,
                'lowest' => Email::PRIORITY_LOWEST,
                default => Email::PRIORITY_NORMAL,
            };
            $email->priority($priority);
        }

        // Embed images if specified. Confine every path to the allowed directories first: a
        // rejected path throws InvalidAttachmentException (fail closed, not silently skipped).
        if (isset($data['embedImages']) && is_array($data['embedImages'])) {
            foreach ($data['embedImages'] as $cid => $path) {
                $attachmentValidator->validate((string) $path);
                $email->embedFromPath((string) $path, (string) $cid);
            }
        }

        // Add custom headers if specified
        if (isset($data['headers']) && is_array($data['headers'])) {
            foreach ($data['headers'] as $name => $value) {
                $email->getHeaders()->addTextHeader($name, $value);
            }
        }

        // Set return path if specified
        if (isset($data['returnPath'])) {
            $email->returnPath($data['returnPath']);
        }

        // Add attachments. Each path is confined to the allowed directories before it reaches
        // Symfony -- a rejected path throws InvalidAttachmentException rather than being silently
        // dropped, so an exfiltration attempt becomes a loud, non-retryable failure.
        if (isset($data['attachments']) && is_array($data['attachments'])) {
            foreach ($data['attachments'] as $attachment) {
                if (is_string($attachment)) {
                    $attachmentValidator->validate($attachment);
                    $email->attachFromPath($attachment);
                } elseif (is_array($attachment) && isset($attachment['path'])) {
                    $attachmentValidator->validate((string) $attachment['path']);
                    $email->attachFromPath(
                        (string) $attachment['path'],
                        $attachment['name'] ?? null,
                        $attachment['contentType'] ?? null
                    );
                }
            }
        }

        return $email;
    }

    /**
     * Enable or disable Twig support
     *
     * @param bool $enable Whether to enable Twig
     * @return self
     */
    public function setUseTwig(bool $enable): self
    {
        if ($enable && !$this->twig) {
            $this->initializeTwig();
        }

        $this->useTwig = $enable;
        return $this;
    }

    /**
     * Get Twig environment
     *
     * @return Environment|null
     */
    public function getTwig(): ?Environment
    {
        return $this->twig;
    }

    /**
     * Whether Twig rendering is currently enabled.
     */
    public function isUsingTwig(): bool
    {
        return $this->useTwig && $this->twig !== null;
    }
}
