<?php

declare(strict_types=1);

namespace Glueful\Extensions\EmailNotification\Tests;

use Glueful\Database\Connection;
use Glueful\Extensions\EmailNotification\Database\Migrations\CreateEmailSettingsTable;
use Glueful\Extensions\EmailNotification\Database\Migrations\CreateEmailTemplatesTable;
use PHPUnit\Framework\TestCase;

final class MigrationsTest extends TestCase
{
    private function connection(): Connection
    {
        return new Connection([
            'engine' => 'sqlite',
            'sqlite' => ['primary' => ':memory:'],
            'pooling' => ['enabled' => false],
        ]);
    }

    private function migrate(Connection $connection): void
    {
        $schema = $connection->getSchemaBuilder();
        (new CreateEmailTemplatesTable())->up($schema);
        (new CreateEmailSettingsTable())->up($schema);
    }

    public function test_migrations_create_and_drop_email_tables(): void
    {
        $connection = $this->connection();
        $schema = $connection->getSchemaBuilder();

        $this->migrate($connection);

        self::assertTrue($schema->hasTable('email_templates'));
        self::assertTrue($schema->hasTable('email_settings'));

        (new CreateEmailSettingsTable())->down($schema);
        (new CreateEmailTemplatesTable())->down($schema);

        self::assertFalse($schema->hasTable('email_templates'));
        self::assertFalse($schema->hasTable('email_settings'));
    }

    public function test_template_and_setting_keys_are_unique(): void
    {
        $connection = $this->connection();
        $this->migrate($connection);
        $pdo = $connection->getPDO();

        $pdo->exec(
            "INSERT INTO email_templates (uuid, template_key, subject, body) " .
            "VALUES ('tpl000000001', 'welcome', 'Subject', 'Body')"
        );
        $pdo->exec(
            "INSERT INTO email_settings (setting_key, value) VALUES ('smtp.host', 'smtp.example.com')"
        );

        $this->expectException(\PDOException::class);
        $pdo->exec(
            "INSERT INTO email_templates (uuid, template_key, subject, body) " .
            "VALUES ('tpl000000002', 'welcome', 'Other', 'Body')"
        );
    }

    public function test_setting_key_is_unique(): void
    {
        $connection = $this->connection();
        $this->migrate($connection);
        $pdo = $connection->getPDO();

        $pdo->exec(
            "INSERT INTO email_settings (setting_key, value) VALUES ('smtp.host', 'smtp.example.com')"
        );

        $this->expectException(\PDOException::class);
        $pdo->exec(
            "INSERT INTO email_settings (setting_key, value) VALUES ('smtp.host', 'smtp2.example.com')"
        );
    }
}
