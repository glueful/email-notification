<?php

declare(strict_types=1);

namespace Glueful\Extensions\EmailNotification\Tests;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Extensions\EmailNotification\EmailFormatter;
use Glueful\Extensions\EmailNotification\EnhancedEmailFormatter;
use PHPUnit\Framework\TestCase;

/**
 * Regression: EnhancedEmailFormatter must be instantiable (its constructor previously passed
 * an array where the base EmailFormatter requires an ApplicationContext, throwing a TypeError).
 */
final class EnhancedEmailFormatterTest extends TestCase
{
    public function test_is_instantiable_with_application_context(): void
    {
        $formatter = new EnhancedEmailFormatter(new ApplicationContext(sys_get_temp_dir()));

        self::assertInstanceOf(EmailFormatter::class, $formatter);
    }

    public function test_twig_is_disabled_by_default(): void
    {
        $formatter = new EnhancedEmailFormatter(new ApplicationContext(sys_get_temp_dir()));

        self::assertFalse($formatter->isUsingTwig());
        self::assertNull($formatter->getTwig());
    }
}
