<?php

declare(strict_types=1);

namespace Glueful\Extensions\EmailNotification\Http;

use Glueful\Extensions\Contracts\Email\EmailTemplateDefinition;
use Glueful\Extensions\Contracts\Email\EmailTemplateRegistry;
use Glueful\Extensions\EmailNotification\EmailChannel;
use Glueful\Extensions\EmailNotification\Templates\MustacheLiteEngine;
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

    /**
     * Editable layout furniture (NOT registry templates): shipped files under
     * Templates/html/partials, overridable via partial.{name} rows in the same
     * store. `styles` is the clean CSS-injection point — the layout includes it
     * inside its <style> block, so overriding it restyles every email without
     * touching the layout's structure.
     *
     * @var array<string, array{label:string, description:string, language:string}>
     */
    private const PARTIALS = [
        'partial.layout' => [
            'label' => 'Layout',
            'description' => 'The outer HTML document every email is wrapped in.'
                . ' Variables: {{subject}}, {{{content}}}, {{logo_url}}, {{> styles}}, {{> header}}, {{> footer}}.',
            'language' => 'html',
        ],
        'partial.header' => [
            'label' => 'Header',
            'description' => 'Rendered above the message content ({{> header}}).',
            'language' => 'html',
        ],
        'partial.footer' => [
            'label' => 'Footer',
            'description' => 'Rendered below the message content ({{> footer}}).',
            'language' => 'html',
        ],
        'partial.styles' => [
            'label' => 'Styles (CSS)',
            'description' => 'Stylesheet injected into the layout\'s <style> block — override to restyle every email.',
            'language' => 'css',
        ],
    ];

    public function index(Request $request): Response
    {
        $templates = array_map(
            fn (EmailTemplateDefinition $definition): array => $this->templatePayload($definition),
            $this->registry->all()
        );

        $partials = [];
        foreach (self::PARTIALS as $key => $meta) {
            $partials[] = $this->partialPayload($key, $meta);
        }

        return Response::success([
            'templates' => $templates,
            'partials' => $partials,
        ], 'Email templates retrieved.');
    }

    /**
     * @param array{label:string, description:string, language:string} $meta
     * @return array<string, mixed>
     */
    private function partialPayload(string $key, array $meta): array
    {
        $override = $this->overrides->find($key);
        return [
            'key' => $key,
            'label' => $meta['label'],
            'description' => $meta['description'],
            'language' => $meta['language'],
            'body' => $override['body'] ?? $this->partialDefault($key),
            'overridden' => $override !== null,
        ];
    }

    private function partialDefault(string $key): string
    {
        $name = substr($key, strlen(MustacheLiteEngine::PARTIAL_KEY_PREFIX));
        $file = dirname(__DIR__) . '/Templates/html/partials/' . $name . '.html';
        return is_file($file) ? (string) file_get_contents($file) : '';
    }

    public function save(Request $request, string $key): Response
    {
        // Partials: body-only overrides (no subject, no registry definition).
        if (isset(self::PARTIALS[$key])) {
            $data = RequestHelper::getRequestData($request);
            $body = isset($data['body']) ? (string) $data['body'] : '';
            $errors = [];
            if (trim($body) === '') {
                $errors['body'] = 'Body is required.';
            }
            foreach ($this->engine->violations($body) as $violation) {
                $errors['body'][] = $violation;
            }
            if ($errors !== []) {
                return Response::validation($errors);
            }
            $this->overrides->save($key, '', $body, null);
            return Response::success(
                $this->partialPayload($key, self::PARTIALS[$key]),
                'Partial saved.'
            );
        }

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
        if (!isset(self::PARTIALS[$key]) && $this->registry->find($key) === null) {
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

        // A REAL send through the NORMAL formatter path — template_name +
        // sample data, so the channel renders THIS template (including any
        // saved override) exactly as production mail would. Passing
        // pre-rendered content would be discarded: EmailFormatter::format()
        // always renders template_name ?? default.
        $result = $this->channel->sendNotification(new TestRecipient($to), [
            'template_name' => $key,
            'template_data' => $samples,
            'type' => 'email_template_test',
        ]);

        // Echo the rendered subject for the UI (same renderer, same values).
        $rendered = $this->renderer->render($key, $samples);

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
