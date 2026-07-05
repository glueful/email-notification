<?php

declare(strict_types=1);

namespace Glueful\Extensions\EmailNotification\Http;

use Glueful\Extensions\Contracts\Email\EmailTemplateDefinition;
use Glueful\Extensions\Contracts\Email\EmailTemplateRegistry;
use Glueful\Extensions\EmailNotification\Templates\OverrideRepository;
use Glueful\Extensions\EmailNotification\Templates\TemplateEngine;
use Glueful\Extensions\EmailNotification\Templates\TemplateRenderer;
use Glueful\Helpers\RequestHelper;
use Glueful\Http\Response;
use Symfony\Component\HttpFoundation\Request;

final class TemplatesController
{
    public function __construct(
        private readonly EmailTemplateRegistry $registry,
        private readonly OverrideRepository $overrides,
        private readonly TemplateEngine $engine,
        private readonly TemplateRenderer $renderer
    ) {
    }

    public function index(Request $request): Response
    {
        $templates = array_map(
            fn (EmailTemplateDefinition $definition): array => $this->templatePayload($definition),
            $this->registry->all()
        );

        return Response::success(['templates' => $templates], 'Email templates retrieved.');
    }

    public function save(Request $request, string $key): Response
    {
        $definition = $this->registry->find($key);
        if ($definition === null) {
            return Response::notFound('Email template not found.');
        }

        $data = RequestHelper::getRequestData($request);
        $subject = isset($data['subject']) ? trim((string) $data['subject']) : '';
        $body = isset($data['body']) ? (string) $data['body'] : '';

        $errors = [];
        if ($subject === '') {
            $errors['subject'] = 'Subject is required.';
        }
        if (trim($body) === '') {
            $errors['body'] = 'Body is required.';
        }
        foreach ($this->engine->violations($subject) as $violation) {
            $errors['subject'][] = $violation;
        }
        foreach ($this->engine->violations($body) as $violation) {
            $errors['body'][] = $violation;
        }
        if ($errors !== []) {
            return Response::validation($errors);
        }

        $this->overrides->save($key, $subject, $body, null);

        return Response::success($this->templatePayload($definition), 'Email template saved.');
    }

    public function reset(Request $request, string $key): Response
    {
        if ($this->registry->find($key) === null) {
            return Response::notFound('Email template not found.');
        }

        if (!$this->overrides->delete($key)) {
            return Response::notFound('Email template override not found.');
        }

        return Response::success(null, 'Email template reset.');
    }

    public function testSend(Request $request, string $key): Response
    {
        $definition = $this->registry->find($key);
        if ($definition === null) {
            return Response::notFound('Email template not found.');
        }

        $samples = [];
        foreach ($definition->placeholders as $placeholder) {
            $samples[$placeholder->name] = $placeholder->sample;
        }

        $rendered = $this->renderer->render($key, $samples);

        return Response::success($rendered, 'Email template rendered.');
    }

    /**
     * @return array<string, mixed>
     */
    private function templatePayload(EmailTemplateDefinition $definition): array
    {
        $override = $this->overrides->find($definition->key);

        return [
            'key' => $definition->key,
            'label' => $definition->label,
            'description' => $definition->description,
            'owner' => $definition->owner,
            'placeholders' => array_map(static fn ($placeholder): array => [
                'name' => $placeholder->name,
                'description' => $placeholder->description,
                'sample' => $placeholder->sample,
            ], $definition->placeholders),
            'subject' => $override['subject'] ?? $definition->defaultSubject,
            'body' => $override['body'] ?? $definition->defaultBody,
            'overridden' => $override !== null,
        ];
    }
}
