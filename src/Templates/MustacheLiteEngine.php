<?php

declare(strict_types=1);

namespace Glueful\Extensions\EmailNotification\Templates;

final class MustacheLiteEngine implements TemplateEngine
{
    /** Override-store key prefix for partials (partial.header, partial.styles, …). */
    public const PARTIAL_KEY_PREFIX = 'partial.';

    /**
     * @param list<string> $partialPaths
     */
    public function __construct(
        private readonly array $partialPaths = [__DIR__ . '/html/partials'],
        private readonly string $extension = '.html',
        /** DB-first partial resolution: an override row beats the shipped file. */
        private readonly ?OverrideRepository $overrides = null
    ) {
    }

    /**
     * @param array<string, mixed> $data
     */
    public function render(string $template, array $data): string
    {
        return $this->renderWith($template, $data, escape: true);
    }

    /**
     * @param array<string, mixed> $data
     */
    public function renderPlain(string $template, array $data): string
    {
        return $this->renderWith($template, $data, escape: false);
    }

    /**
     * @param array<string, mixed> $data
     */
    private function renderWith(string $template, array $data, bool $escape): string
    {
        $template = preg_replace_callback(
            '/\{\{>\s+([a-zA-Z0-9_\-\.\/]+)\}\}/',
            fn (array $matches): string => $this->includePartial(trim((string) $matches[1]), $data),
            $template
        ) ?? $template;

        $template = preg_replace_callback(
            '/\{\{#if\s+([a-zA-Z0-9_\.]+)\}\}(.*?)\{\{\/if\}\}/s',
            function (array $matches) use ($data): string {
                $variable = (string) $matches[1];
                $content = (string) $matches[2];

                return $this->truthy($variable, $data) ? $content : '';
            },
            $template
        ) ?? $template;

        $template = preg_replace_callback(
            '/\{\{\{\s*([a-zA-Z0-9_\.]+)\s*\}\}\}/',
            function (array $matches) use ($data): string {
                $value = $this->resolveValue(trim((string) $matches[1]), $data, null);

                return $value === null ? '' : $value;
            },
            $template
        ) ?? $template;

        return preg_replace_callback(
            '/\{\{([^}]+)\}\}/',
            function (array $matches) use ($data, $escape): string {
                $parts = explode('|', (string) $matches[1]);
                $key = trim($parts[0]);
                $default = isset($parts[1]) ? trim($parts[1]) : '';

                $value = $this->resolveValue($key, $data, $default);

                return $escape ? $this->escape($value) : ($value ?? '');
            },
            $template
        ) ?? $template;
    }

    /**
     * @return list<string>
     */
    public function violations(string $template): array
    {
        $violations = [];
        $stack = [];
        preg_match_all('/\{\{(#if\s+([a-zA-Z0-9_\.]+)|\/if)\}\}/', $template, $matches, PREG_SET_ORDER);

        foreach ($matches as $match) {
            $token = (string) $match[1];
            if (str_starts_with($token, '#if ')) {
                $stack[] = isset($match[2]) ? (string) $match[2] : '';
                continue;
            }

            if ($stack === []) {
                $violations[] = 'Unexpected conditional close: {{/if}}';
                continue;
            }

            array_pop($stack);
        }

        foreach (array_reverse($stack) as $variable) {
            $violations[] = "Unclosed conditional block: {{#if {$variable}}}";
        }

        return $violations;
    }

    /**
     * @param array<string, mixed> $data
     */
    private function includePartial(string $partialName, array $data): string
    {
        // Admin-overridden partial (partial.{name} in the template store) wins
        // over the shipped file — same precedence as templates themselves.
        $override = $this->overrides?->find(self::PARTIAL_KEY_PREFIX . $partialName);
        if ($override !== null) {
            return $this->render($override['body'], $data);
        }

        foreach ($this->partialPaths as $basePath) {
            $partialFile = rtrim($basePath, '/') . '/' . $partialName . $this->extension;
            if (!is_file($partialFile)) {
                continue;
            }

            $partialContent = file_get_contents($partialFile);
            if (is_string($partialContent)) {
                return $this->render($partialContent, $data);
            }
        }

        return '<!-- Partial not found: ' . $partialName . ' -->';
    }

    /**
     * @param array<string, mixed> $data
     */
    private function truthy(string $key, array $data): bool
    {
        $value = $this->resolveMixed($key, $data);

        return $value !== null && (bool) $value;
    }

    /**
     * @param array<string, mixed> $data
     */
    private function resolveValue(string $key, array $data, ?string $default): ?string
    {
        $value = $this->resolveMixed($key, $data);

        return is_scalar($value) ? (string) $value : $default;
    }

    /**
     * @param array<string, mixed> $data
     */
    private function resolveMixed(string $key, array $data): mixed
    {
        if (strpos($key, '.') === false) {
            return $data[$key] ?? null;
        }

        $value = $data;
        foreach (explode('.', $key) as $part) {
            if (!is_array($value) || !array_key_exists($part, $value)) {
                return null;
            }

            $value = $value[$part];
        }

        return $value;
    }

    private function escape(?string $value): string
    {
        return htmlspecialchars($value ?? '', ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }
}
