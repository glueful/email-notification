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
     * @return list<string>
     */
    public function violations(string $template): array;
}
