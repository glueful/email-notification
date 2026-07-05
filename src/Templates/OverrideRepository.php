<?php

declare(strict_types=1);

namespace Glueful\Extensions\EmailNotification\Templates;

use Glueful\Database\Connection;

class OverrideRepository
{
    public function __construct(private readonly ?Connection $connection = null)
    {
    }

    /**
     * @return array{subject:string,body:string}|null
     */
    public function find(string $key): ?array
    {
        if ($this->connection === null) {
            return null;
        }

        $statement = $this->connection->getPDO()
            ->prepare('SELECT subject, body FROM email_templates WHERE template_key = :template_key LIMIT 1');
        $statement->execute(['template_key' => $key]);
        $row = $statement->fetch(\PDO::FETCH_ASSOC);

        if (!is_array($row)) {
            return null;
        }

        return [
            'subject' => (string) $row['subject'],
            'body' => (string) $row['body'],
        ];
    }

    public function save(string $key, string $subject, string $body, ?string $updatedBy): void
    {
        if ($this->connection === null) {
            throw new \LogicException('Email template override storage is not configured.');
        }

        $pdo = $this->connection->getPDO();
        $existing = $this->find($key);
        $now = date('Y-m-d H:i:s');

        if ($existing !== null) {
            $statement = $pdo->prepare(
                'UPDATE email_templates SET subject = :subject, body = :body, updated_by = :updated_by, ' .
                'updated_at = :updated_at WHERE template_key = :template_key'
            );
            $statement->execute([
                'subject' => $subject,
                'body' => $body,
                'updated_by' => $updatedBy,
                'updated_at' => $now,
                'template_key' => $key,
            ]);
            return;
        }

        $statement = $pdo->prepare(
            'INSERT INTO email_templates (uuid, template_key, subject, body, updated_by, updated_at) ' .
            'VALUES (:uuid, :template_key, :subject, :body, :updated_by, :updated_at)'
        );
        $statement->execute([
            'uuid' => bin2hex(random_bytes(6)),
            'template_key' => $key,
            'subject' => $subject,
            'body' => $body,
            'updated_by' => $updatedBy,
            'updated_at' => $now,
        ]);
    }

    public function delete(string $key): bool
    {
        if ($this->connection === null) {
            return false;
        }

        $statement = $this->connection->getPDO()
            ->prepare('DELETE FROM email_templates WHERE template_key = :template_key');
        $statement->execute(['template_key' => $key]);

        return $statement->rowCount() > 0;
    }
}
