<?php

declare(strict_types=1);

namespace Glueful\Extensions\EmailNotification\Tests;

use Glueful\Extensions\Contracts\Email\EmailTemplateDefinition;
use Glueful\Extensions\Contracts\Email\EmailTemplatePlaceholder;
use Glueful\Extensions\EmailNotification\Templates\BuiltInDefinitions;
use Glueful\Extensions\EmailNotification\Templates\DefinitionRegistry;
use PHPUnit\Framework\TestCase;

final class DefinitionRegistryTest extends TestCase
{
    public function test_same_owner_can_replace_a_definition(): void
    {
        $registry = new DefinitionRegistry();

        $registry->register(new EmailTemplateDefinition(
            key: 'lemma.comment-reply',
            label: 'Comment reply',
            description: 'Sent when a comment receives a reply.',
            defaultSubject: 'First',
            defaultBody: '<p>First</p>',
            placeholders: [],
            owner: 'glueful/lemma'
        ));
        $registry->register(new EmailTemplateDefinition(
            key: 'lemma.comment-reply',
            label: 'Comment reply',
            description: 'Sent when a comment receives a reply.',
            defaultSubject: 'Second',
            defaultBody: '<p>Second</p>',
            placeholders: [],
            owner: 'glueful/lemma'
        ));

        self::assertSame('Second', $registry->find('lemma.comment-reply')->defaultSubject);
        self::assertCount(1, $registry->all());
    }

    public function test_different_owner_collision_throws(): void
    {
        $registry = new DefinitionRegistry();

        $registry->register(new EmailTemplateDefinition(
            key: 'account.verify',
            label: 'Verify',
            description: 'Verify an account.',
            defaultSubject: 'Verify',
            defaultBody: '<p>Verify</p>',
            placeholders: [],
            owner: 'glueful/email-notification'
        ));

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('account.verify');
        $this->expectExceptionMessage('glueful/email-notification');
        $this->expectExceptionMessage('glueful/other');

        $registry->register(new EmailTemplateDefinition(
            key: 'account.verify',
            label: 'Verify',
            description: 'Verify an account.',
            defaultSubject: 'Verify',
            defaultBody: '<p>Verify</p>',
            placeholders: [],
            owner: 'glueful/other'
        ));
    }

    public function test_built_in_definitions_cover_all_shipped_html_templates(): void
    {
        $definitions = BuiltInDefinitions::all();
        $keys = array_map(static fn (EmailTemplateDefinition $definition): string => $definition->key, $definitions);
        sort($keys);

        self::assertSame([
            'alert',
            'default',
            'password-reset',
            'two-factor-pin',
            'verification',
            'welcome',
        ], $keys);

        foreach ($definitions as $definition) {
            self::assertSame('glueful/email-notification', $definition->owner);
            self::assertNotSame('', trim($definition->defaultSubject));
            self::assertStringContainsString('{{', $definition->defaultBody);
        }
    }

    public function test_built_in_placeholders_include_metadata_for_test_sends(): void
    {
        $definitions = [];
        foreach (BuiltInDefinitions::all() as $definition) {
            $definitions[$definition->key] = $definition;
        }

        $passwordReset = $definitions['password-reset'];
        $placeholders = array_combine(
            array_map(static fn (EmailTemplatePlaceholder $placeholder): string => $placeholder->name, $passwordReset->placeholders),
            $passwordReset->placeholders
        );

        self::assertArrayHasKey('otp', $placeholders);
        self::assertArrayHasKey('reset_url', $placeholders);
        self::assertSame('123456', $placeholders['otp']->sample);
        self::assertSame('https://example.com/reset', $placeholders['reset_url']->sample);
    }
}
