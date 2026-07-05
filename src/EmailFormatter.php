<?php

declare(strict_types=1);

namespace Glueful\Extensions\EmailNotification;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Extensions\EmailNotification\Templates\BuiltInDefinitions;
use Glueful\Extensions\EmailNotification\Templates\DefinitionRegistry;
use Glueful\Extensions\EmailNotification\Templates\MustacheLiteEngine;
use Glueful\Extensions\EmailNotification\Templates\OverrideRepository;
use Glueful\Extensions\EmailNotification\Templates\TemplateRenderer;
use Glueful\Notifications\Contracts\Notifiable;

/**
 * Email Formatter
 *
 * Responsible for formatting notification data into email-friendly format
 * with both HTML and plain text versions.
 *
 * @package Glueful\Extensions\EmailNotification
 */
class EmailFormatter
{
    /**
     * @var array<string, array<string, mixed>|string> Formatter templates keyed by notification type
     */
    private array $templates = [];

    private ApplicationContext $context;
    private TemplateRenderer $renderer;

    /**
     * @var array<string, mixed> Default formatting options
     */
    private array $defaultOptions = [
        'include_footer' => true,
        'include_header' => true,
        'default_template' => 'default',
        'built_in_template_root' => __DIR__ . '/Templates/html',
    ];

    /**
     * EmailFormatter constructor
     *
     * @param array<string, array<string, mixed>|string> $templates Custom templates
     * @param array<string, mixed> $options Formatting options
     */
    public function __construct(
        ApplicationContext $context,
        array $templates = [],
        array $options = [],
        ?TemplateRenderer $renderer = null
    ) {
        $this->context = $context;
        // Load template configuration from services
        $this->loadTemplateConfiguration();

        // Merge provided options with loaded config
        $this->defaultOptions = array_merge($this->defaultOptions, $options);

        $this->renderer = $renderer ?? $this->defaultRenderer();

        // Register any provided custom templates
        foreach ($templates as $name => $template) {
            $this->registerTemplate($name, $template);
        }
    }

    /**
     * Load template configuration from services.php
     */
    protected function loadTemplateConfiguration(): void
    {
        // Get extension configuration
        $extensionConfig = config($this->context, 'emailnotification', []);
        $extensionTemplates = $extensionConfig['templates'] ?? [];

        // Store global variables (merge framework and extension variables)
        $mailConfig = config($this->context, 'services.mail', []);
        $templateConfig = $mailConfig['templates'] ?? [];
        $this->defaultOptions['global_variables'] = array_merge(
            $extensionTemplates['extension_variables'] ?? [],
            $templateConfig['global_variables'] ?? []
        );

        $this->defaultOptions['partials_directory'] = 'partials';
        $this->defaultOptions['extension'] = '.html';
    }

    private function defaultRenderer(): TemplateRenderer
    {
        $registry = new DefinitionRegistry();
        $registry->register(...BuiltInDefinitions::all());

        $partialsDir = $this->defaultOptions['partials_directory'] ?? 'partials';
        $extension = $this->defaultOptions['extension'] ?? '.html';
        $templateRoot = rtrim((string) $this->defaultOptions['built_in_template_root'], '/');
        $partialPaths = [$templateRoot . '/' . $partialsDir];

        // One override store shared by renderer AND engine, so admin-edited
        // partials (partial.header/styles/…) apply on this fallback path too.
        $overrides = new OverrideRepository();

        return new TemplateRenderer(
            $registry,
            $overrides,
            new MustacheLiteEngine($partialPaths, (string) $extension, $overrides),
            $templateRoot . '/' . $partialsDir . '/layout' . $extension
        );
    }

    /**
     * Format notification data for email delivery
     *
     * @param array<string, mixed> $data The notification data
     * @param Notifiable $notifiable The entity receiving the notification
     * @return array<string, mixed> Formatted email data with subject, content, etc.
     */
    public function format(array $data, Notifiable $notifiable): array
    {

        $templateName = $data['template_name'] ?? $this->defaultOptions['default_template'];

        // Start with basic email structure
        $result = [
            'subject' => '',
            'text_content' => '',
            'html_content' => '',
            'attachments' => $data['attachments'] ?? []
        ];

        // Optional CC and BCC
        if (!empty($data['cc'])) {
            $result['cc'] = $data['cc'];
        }

        if (!empty($data['bcc'])) {
            $result['bcc'] = $data['bcc'];
        }

        // Set notification data for template rendering
        $templateData = $data['template_data'] ?? $data;

        // Neutralise URLs whose scheme isn't http(s) before they reach href slots
        // (e.g. javascript:/data: payloads in action_url / reset_url).
        foreach (['action_url', 'reset_url'] as $urlKey) {
            if (isset($templateData[$urlKey]) && !$this->isSafeUrl((string) $templateData[$urlKey])) {
                $templateData[$urlKey] = '';
            }
        }

        // Ensure logo_url is always available, using global_variables from config
        // The global_variables are loaded from services.mail.templates.global_variables
        if (!isset($templateData['logo_url'])) {
            $globalVars = $this->defaultOptions['global_variables'] ?? [];
            $templateData['logo_url'] = $globalVars['logo_url']
                ?? config($this->context, 'services.mail.templates.global_variables.logo_url')
                ?? 'https://brand.glueful.com/logo.png';
        }

        // Add notifiable information
        $templateData['notifiable_id'] = $notifiable->getNotifiableId();
        $templateData['notifiable_type'] = $notifiable->getNotifiableType();

        $rendered = $this->renderer->render((string) $templateName, $templateData);
        $result['subject'] = $rendered['subject'];
        $result['html_content'] = $rendered['html'];

        // Generate plain text version
        $result['text_content'] = $this->htmlToText($result['html_content']);

        return $result;
    }

    /**
     * Register a template for a notification type
     *
     * @param string $name Template name
     * @param array<string, mixed>|string $template Template data or path
     * @return self
     */
    public function registerTemplate(string $name, $template): self
    {
        $this->templates[$name] = $template;
        return $this;
    }

    /**
     * Get a template for the notification type
     *
     * @param string $type Notification type
     * @param string $name Template name
     * @return array<string, mixed>|string Template data
     */
    public function getTemplate(string $type, string $name = 'default')
    {
        // First try to find a template specific to this notification type
        $typedTemplateName = $type . '.' . $name;

        if (isset($this->templates[$typedTemplateName])) {
            return $this->templates[$typedTemplateName];
        }

        // Fall back to the named template regardless of type
        if (isset($this->templates[$name])) {
            return $this->templates[$name];
        }

        // Last resort: use the default template
        return $this->templates['default'];
    }

    /**
     * Render a template with provided data
     *
     * @param array<string, mixed>|string $template Template data or path
     * @param array<string, mixed> $data Variables for template
     * @return string Rendered template
     */
    protected function renderTemplate($template, array $data): string
    {
        // Merge global variables with template data
        $data = array_merge($this->defaultOptions['global_variables'] ?? [], $data);

        // If template is a file path, load the file
        if (is_string($template) && file_exists($template)) {
            $fileContent = file_get_contents($template);
            if ($fileContent !== false) {
                // Process the template content with variables
                $rendered = $this->replaceVariables($fileContent, $data);

                // Apply the layout if it's not already a complete HTML document
                if (strpos($rendered, '<!DOCTYPE html>') === false) {
                    $rendered = $this->applyLayout($rendered, $data);
                }

                return $rendered;
            } else {
                error_log("EmailFormatter: Failed to load template file");
            }
        }

        // If template is a string, substitute variables
        if (is_string($template)) {
            $rendered = $this->replaceVariables($template, $data);

            // Apply the layout if it's not already a complete HTML document
            if (strpos($rendered, '<!DOCTYPE html>') === false) {
                $rendered = $this->applyLayout($rendered, $data);
            }

            return $rendered;
        }

        // Otherwise $template is a structured array with header, body, footer
        // (the string forms returned above).
        $html = '';

        // Add header if requested
        if ($this->defaultOptions['include_header'] && isset($template['header'])) {
            $html .= $this->replaceVariables($template['header'], $data);
        }

        // Add body (required)
        if (isset($template['body'])) {
            $html .= $this->replaceVariables($template['body'], $data);
        } else {
            error_log("EmailFormatter: Template body is missing!");
        }

        // Add footer if requested
        if ($this->defaultOptions['include_footer'] && isset($template['footer'])) {
            $html .= $this->replaceVariables($template['footer'], $data);
        }

        // Apply the layout if it's not already a complete HTML document
        if (strpos($html, '<!DOCTYPE html>') === false) {
            $html = $this->applyLayout($html, $data);
        }

        return $html;
    }

    /**
     * Apply layout template to content
     *
     * @param string $content The template content
     * @param array<string, mixed> $data Variables for substitution
     * @return string Content wrapped in layout
     */
    protected function applyLayout(string $content, array $data): string
    {
        $partialsDir = $this->defaultOptions['partials_directory'] ?? 'partials';
        $extension = $this->defaultOptions['extension'] ?? '.html';

        $searchPaths = [$this->defaultOptions['built_in_template_root'] . '/' . $partialsDir];

        foreach ($searchPaths as $basePath) {
            $layoutPath = rtrim($basePath, '/') . '/layout' . $extension;
            if (file_exists($layoutPath)) {
                $layout = file_get_contents($layoutPath);
                if ($layout !== false) {
                    // Add the content to the data for the layout template
                    $layoutData = array_merge($data, ['content' => $content]);
                    return $this->replaceVariables($layout, $layoutData);
                }
            }
        }

        // If layout doesn't exist, just return the content
        return $content;
    }

    /**
     * Replace variables in a template string with support for conditional blocks
     *
     * @param string $template Template string
     * @param array<string, mixed> $data Variables for substitution
     * @return string Template with variables replaced
     */
    protected function replaceVariables(string $template, array $data): string
    {
        // Process template includes in the form {{> partial_name}}
        $template = preg_replace_callback(
            '/\{\{>\s+([a-zA-Z0-9_\-\.\/]+)\}\}/',
            function ($matches) use ($data) {
                $partialName = trim($matches[1]);
                return $this->includePartial($partialName, $data);
            },
            $template
        ) ?? $template;

        // Process conditional blocks {{#if variable}}...content...{{/if}}
        $template = preg_replace_callback(
            '/\{\{#if\s+([a-zA-Z0-9_\.]+)\}\}(.*?)\{\{\/if\}\}/s',
            function ($matches) use ($data) {
                $variable = $matches[1];
                $content = $matches[2];

                // Check if variable exists and is truthy
                if (isset($data[$variable]) && $data[$variable]) {
                    return $content;
                }

                return ''; // Remove block if condition fails
            },
            $template
        ) ?? $template;

        // Replace raw variables in the form {{{variable}}} WITHOUT escaping.
        // Reserved for slots that intentionally receive pre-rendered HTML (e.g. the
        // layout's {{{content}}}). Must run before the {{variable}} pass so the triple
        // braces are consumed first.
        $template = preg_replace_callback(
            '/\{\{\{\s*([a-zA-Z0-9_\.]+)\s*\}\}\}/',
            function ($matches) use ($data) {
                $value = $this->resolveValue(trim($matches[1]), $data, null);
                return $value === null ? '' : (string) $value;
            },
            $template
        ) ?? $template;

        // Replace simple variables in the form {{variable}} or {{variable|default}}.
        // Every interpolated scalar is HTML-escaped to prevent notification data
        // (display names, messages, …) from injecting markup into outgoing email.
        $result = preg_replace_callback(
            '/\{\{([^}]+)\}\}/',
            function ($matches) use ($data) {
                $parts = explode('|', $matches[1]);
                $key = trim($parts[0]);
                $default = isset($parts[1]) ? trim($parts[1]) : '';

                $value = $this->resolveValue($key, $data, $default);

                // Template-author-controlled default literals are escaped too.
                return $this->escape($value);
            },
            $template
        );

        return $result ?? $template;
    }

    /**
     * Resolve a (possibly dot-notated) template key against the data set.
     *
     * @param array<string, mixed> $data
     * @param string|null $default Fallback when the key is missing or non-scalar.
     */
    private function resolveValue(string $key, array $data, ?string $default): ?string
    {
        if (strpos($key, '.') !== false) {
            $value = $data;
            foreach (explode('.', $key) as $part) {
                if (is_array($value) && isset($value[$part])) {
                    $value = $value[$part];
                } else {
                    return $default;
                }
            }

            return is_scalar($value) ? (string) $value : $default;
        }

        return isset($data[$key]) && is_scalar($data[$key]) ? (string) $data[$key] : $default;
    }

    /**
     * HTML-escape an interpolated value for safe inclusion in markup/attributes.
     */
    private function escape(?string $value): string
    {
        return htmlspecialchars($value ?? '', ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }

    /**
     * Whether a URL is safe to place in an href slot (http/https only).
     * Relative URLs (no scheme) are allowed; anything with a non-http(s)
     * scheme (javascript:, data:, …) is rejected.
     */
    private function isSafeUrl(string $url): bool
    {
        $url = trim($url);
        if ($url === '') {
            return false;
        }

        // Reject anything parse_url can't make sense of — malformed URLs (embedded
        // whitespace/control characters) are how scheme filters get smuggled past.
        if (parse_url($url) === false) {
            return false;
        }

        $scheme = parse_url($url, PHP_URL_SCHEME);
        if ($scheme === null || $scheme === false) {
            return true; // relative URL, no scheme to abuse
        }

        return in_array(strtolower($scheme), ['http', 'https'], true);
    }

    /**
     * Include a partial template
     *
     * @param string $partialName Name of the partial to include
     * @param array<string, mixed> $data Variables for substitution
     * @return string Rendered partial content
     */
    protected function includePartial(string $partialName, array $data): string
    {
        $partialsDir = $this->defaultOptions['partials_directory'] ?? 'partials';
        $extension = $this->defaultOptions['extension'] ?? '.html';

        $searchPaths = [$this->defaultOptions['built_in_template_root'] . '/' . $partialsDir];

        foreach ($searchPaths as $basePath) {
            $partialFile = rtrim($basePath, '/') . '/' . $partialName . $extension;
            if (file_exists($partialFile)) {
                $partialContent = file_get_contents($partialFile);
                if ($partialContent !== false) {
                    // Process the partial content (allows nested includes)
                    return $this->replaceVariables($partialContent, $data);
                }
            }
        }

        return '<!-- Partial not found: ' . $partialName . ' -->';
    }

    /**
     * Convert HTML to plain text
     *
     * @param string $html HTML content
     * @return string Plain text version
     */
    protected function htmlToText(string $html): string
    {
        // Replace common HTML elements with plain text equivalents
        $text = strip_tags(str_replace(
            ['<br>', '<br/>', '<br />', '<p>', '</p>', '<div>', '</div>', '&nbsp;'],
            ["\n", "\n", "\n", "\n", "\n", "\n", "\n", ' '],
            $html
        ));

        // Decode HTML entities
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        // Remove excess whitespace
        $text = preg_replace('/\s+/', ' ', $text) ?? $text;
        $text = preg_replace('/\n\s*\n/', "\n\n", $text) ?? $text;

        return trim($text);
    }

    /**
     * Set default formatting options
     *
     * @param array<string, mixed> $options Formatting options
     * @return self
     */
    public function setOptions(array $options): self
    {
        $this->defaultOptions = array_merge($this->defaultOptions, $options);
        return $this;
    }
}
