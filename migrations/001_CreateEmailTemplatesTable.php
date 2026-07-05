<?php

declare(strict_types=1);

namespace Glueful\Extensions\EmailNotification\Database\Migrations;

use Glueful\Database\Migrations\MigrationInterface;
use Glueful\Database\Schema\Interfaces\SchemaBuilderInterface;

final class CreateEmailTemplatesTable implements MigrationInterface
{
    public function up(SchemaBuilderInterface $schema): void
    {
        if ($schema->hasTable('email_templates')) {
            return;
        }

        $schema->createTable('email_templates', function ($table): void {
            $table->bigInteger('id')->primary()->autoIncrement();
            $table->string('uuid', 12);
            $table->string('template_key', 191);
            $table->text('subject');
            $table->text('body');
            $table->string('updated_by', 12)->nullable();
            $table->timestamp('created_at')->default('CURRENT_TIMESTAMP');
            $table->timestamp('updated_at')->nullable();

            $table->unique('uuid');
            $table->unique('template_key');
        });
    }

    public function down(SchemaBuilderInterface $schema): void
    {
        $schema->dropTableIfExists('email_templates');
    }

    public function getDescription(): string
    {
        return 'Creates email template override rows.';
    }
}
