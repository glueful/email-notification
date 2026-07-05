<?php

declare(strict_types=1);

namespace Glueful\Extensions\EmailNotification\Templates;

use Glueful\Extensions\Contracts\Email\EmailTemplateRegistry;

final class TemplateRenderer
{
    public function __construct(
        private readonly EmailTemplateRegistry $registry,
        private readonly OverrideRepository $overrides,
        private readonly TemplateEngine $engine,
        private readonly string $layoutPath = __DIR__ . '/html/partials/layout.html'
    ) {
    }

    /**
     * @param array<string, mixed> $data
     * @return array{subject:string,html:string}
     */
    public function render(string $key, array $data): array
    {
        $definition = $this->registry->find($key);
        if ($definition === null) {
            throw new \RuntimeException("Unknown email template '{$key}' — templates must be registered.");
        }

        $override = $this->overrides->find($key);
        // Subjects are plain-text headers, not HTML — raw interpolation
        // (render() would deliver 'Q&amp;A Hub' to inboxes).
        $subject = $this->engine->renderPlain($override['subject'] ?? $definition->defaultSubject, $data);
        $body = $this->engine->render($override['body'] ?? $definition->defaultBody, $data);

        return [
            'subject' => $subject,
            'html' => $this->wrap($body, $data + ['subject' => $subject, 'title' => $subject]),
        ];
    }

    /**
     * @param array<string, mixed> $data
     */
    private function wrap(string $body, array $data): string
    {
        if (str_contains($body, '<!DOCTYPE html>')) {
            return $body;
        }

        // An admin-overridden layout (partial.layout) beats the shipped file.
        $override = $this->overrides->find(MustacheLiteEngine::PARTIAL_KEY_PREFIX . 'layout');
        $layout = $override['body'] ?? null;

        if ($layout === null) {
            if (!is_file($this->layoutPath)) {
                return $body;
            }
            $fileLayout = file_get_contents($this->layoutPath);
            if (!is_string($fileLayout)) {
                return $body;
            }
            $layout = $fileLayout;
        }

        return $this->engine->render($layout, $data + ['content' => $body]);
    }
}
