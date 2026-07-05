<?php

declare(strict_types=1);

namespace Glueful\Extensions\EmailNotification\Templates;

interface TemplateEngine
{
    /**
     * @param array<string, mixed> $data
     */
    public function render(string $template, array $data): string;

    /**
     * Render for PLAIN-TEXT surfaces (subject lines): identical pipeline but
     * interpolated values are NOT HTML-escaped — a subject header is not
     * HTML, and `Q&A Hub` must never arrive as `Q&amp;A Hub`.
     *
     * @param array<string, mixed> $data
     */
    public function renderPlain(string $template, array $data): string;

    /**
     * @return list<string>
     */
    public function violations(string $template): array;
}
