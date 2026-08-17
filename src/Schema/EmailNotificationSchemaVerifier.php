<?php

declare(strict_types=1);

namespace Glueful\Extensions\EmailNotification\Schema;

use Glueful\Database\Connection;
use Glueful\Extensions\Schema\StructuralVerifierInterface;

/**
 * Structural verifier for glueful/email-notification (schema policy spec B7): each create migration proves
 * every table it creates with its load-bearing columns. Unknown basenames are never adoptable.
 */
final class EmailNotificationSchemaVerifier implements StructuralVerifierInterface
{
    public function source(): string
    {
        return 'glueful/email-notification';
    }

    /** @return list<string> */
    public function migrationBasenames(): array
    {
        return [
            '001_CreateEmailTemplatesTable.php',
            '002_CreateEmailSettingsTable.php',
        ];
    }

    public function verify(Connection $db, string $migrationBasename): bool
    {
        return match ($migrationBasename) {
            '001_CreateEmailTemplatesTable.php' => $this->tablesWithColumns($db, [
                'email_templates' => ['uuid', 'template_key'],
            ]),
            '002_CreateEmailSettingsTable.php' => $this->tablesWithColumns($db, [
                'email_settings' => ['setting_key'],
            ]),
            default => false,
        };
    }

    /** @param array<string, list<string>> $expectations */
    private function tablesWithColumns(Connection $db, array $expectations): bool
    {
        $schema = $db->getSchemaBuilder();
        foreach ($expectations as $table => $columns) {
            if (!$schema->hasTable($table)) {
                return false;
            }
            foreach ($columns as $column) {
                if (!$schema->hasColumn($table, $column)) {
                    return false;
                }
            }
        }
        return true;
    }
}
