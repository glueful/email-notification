<?php

declare(strict_types=1);

namespace Glueful\Extensions\EmailNotification\Http;

use Glueful\Extensions\Contracts\Email\EmailTemplateDefinition;
use Glueful\Extensions\Contracts\Email\EmailTemplateRegistry;
use Glueful\Extensions\EmailNotification\EmailChannel;
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
        private readonly TemplateRenderer $renderer,
        private readonly EmailChannel $channel
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

        $body = (array) json_decode((string) $request->getContent(), true);
        $to = is_string($body['to'] ?? null) ? trim($body['to']) : '';
        if ($to === '' || filter_var($to, FILTER_VALIDATE_EMAIL) === false) {
            return Response::error('A valid `to` email address is required.', 422);
        }

        $samples = [];
        foreach ($definition->placeholders as $placeholder) {
            $samples[$placeholder->name] = $placeholder->sample;
        }

        $rendered = $this->renderer->render($key, $samples);

        // A REAL send — not a render preview: sample data through the live
        // channel, with the domain policy and transport state applying exactly
        // as they would for production mail.
        $result = $this->channel->sendNotification(new TestRecipient($to), [
            'subject' => $rendered['subject'],
            'html_content' => $rendered['html'],
            'text_content' => strip_tags($rendered['html']),
            'type' => 'email_template_test',
        ]);

        if (!$result->success) {
            $status = $result->errorCode === 'transport_misconfigured' ? 422 : 502;
            return Response::error(
                $result->errorMessage ?? 'Test send failed.',
                $status,
            );
        }

        return Response::success([
            'sent_to' => $to,
            'subject' => $rendered['subject'],
        ], 'Test email sent.');
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
