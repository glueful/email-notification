<?php

declare(strict_types=1);

namespace Glueful\Extensions\EmailNotification\Settings;

use Glueful\Database\Connection;
use Glueful\Encryption\EncryptionService;

final class SettingsRepository
{
    private const PASSWORD_AAD = 'email.smtp_password';

    public function __construct(
        private readonly Connection $connection,
        private readonly EncryptionService $encryption
    ) {
    }

    public function get(string $key): ?string
    {
        $statement = $this->connection->getPDO()
            ->prepare('SELECT value FROM email_settings WHERE setting_key = :setting_key LIMIT 1');
        $statement->execute(['setting_key' => $key]);
        $row = $statement->fetch(\PDO::FETCH_ASSOC);

        return is_array($row) ? (string) $row['value'] : null;
    }

    public function set(string $key, string $value): void
    {
        $stored = $key === 'password' && $value !== ''
            ? $this->encryption->encrypt($value, self::PASSWORD_AAD)
            : $value;
        $now = date('Y-m-d H:i:s');
        $pdo = $this->connection->getPDO();

        if ($this->get($key) !== null) {
            $statement = $pdo->prepare(
                'UPDATE email_settings SET value = :value, updated_at = :updated_at WHERE setting_key = :setting_key'
            );
            $statement->execute(['value' => $stored, 'updated_at' => $now, 'setting_key' => $key]);
            return;
        }

        $statement = $pdo->prepare(
            'INSERT INTO email_settings (setting_key, value, updated_at) VALUES (:setting_key, :value, :updated_at)'
        );
        $statement->execute(['setting_key' => $key, 'value' => $stored, 'updated_at' => $now]);
    }

    public function forget(string $key): bool
    {
        $statement = $this->connection->getPDO()
            ->prepare('DELETE FROM email_settings WHERE setting_key = :setting_key');
        $statement->execute(['setting_key' => $key]);

        return $statement->rowCount() > 0;
    }

    public function decryptPassword(string $ciphertext): string
    {
        return $this->encryption->decrypt($ciphertext, self::PASSWORD_AAD);
    }
}
