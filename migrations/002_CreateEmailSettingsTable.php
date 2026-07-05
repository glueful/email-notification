<?php

declare(strict_types=1);

namespace Glueful\Extensions\EmailNotification\Database\Migrations;

use Glueful\Database\Migrations\MigrationInterface;
use Glueful\Database\Schema\Interfaces\SchemaBuilderInterface;

final class CreateEmailSettingsTable implements MigrationInterface
{
    public function up(SchemaBuilderInterface $schema): void
    {
        if ($schema->hasTable('email_settings')) {
            return;
        }

        $schema->createTable('email_settings', function ($table): void {
            $table->bigInteger('id')->primary()->autoIncrement();
            $table->string('setting_key', 64);
            $table->text('value');
            $table->timestamp('created_at')->default('CURRENT_TIMESTAMP');
            $table->timestamp('updated_at')->nullable();

            $table->unique('setting_key');
        });
    }

    public function down(SchemaBuilderInterface $schema): void
    {
        $schema->dropTableIfExists('email_settings');
    }

    public function getDescription(): string
    {
        return 'Creates email notification settings override rows.';
    }
}
