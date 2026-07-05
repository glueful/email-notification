<?php

declare(strict_types=1);

namespace Glueful\Extensions\EmailNotification\Templates;

use Glueful\Extensions\Contracts\Email\EmailTemplateDefinition;
use Glueful\Extensions\Contracts\Email\EmailTemplateRegistry;

final class DefinitionRegistry implements EmailTemplateRegistry
{
    /** @var array<string, EmailTemplateDefinition> */
    private array $definitions = [];

    public function register(EmailTemplateDefinition ...$definitions): void
    {
        foreach ($definitions as $definition) {
            $existing = $this->definitions[$definition->key] ?? null;
            if ($existing !== null && $existing->owner !== $definition->owner) {
                throw new \LogicException(sprintf(
                    "Email template key '%s' is already owned by '%s'; '%s' cannot replace it.",
                    $definition->key,
                    $existing->owner,
                    $definition->owner
                ));
            }

            $this->definitions[$definition->key] = $definition;
        }
    }

    /**
     * @return list<EmailTemplateDefinition>
     */
    public function all(): array
    {
        $definitions = $this->definitions;
        ksort($definitions);

        return array_values($definitions);
    }

    public function find(string $key): ?EmailTemplateDefinition
    {
        return $this->definitions[$key] ?? null;
    }
}
