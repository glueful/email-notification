<?php

declare(strict_types=1);

namespace Glueful\Extensions\EmailNotification;

use Glueful\Bootstrap\ApplicationContext;

/**
 * Confines attachment / embedded-image paths to an allowlist of base directories.
 *
 * Attachment and embed paths originate from notification data, which may carry user-influenced
 * input. Passing them straight to Symfony's attachFromPath()/embedFromPath() (with at most a
 * file_exists() check) lets a caller attach arbitrary host files (/etc/passwd, .env, private keys)
 * and exfiltrate them to a recipient of their choosing. This validator is the single chokepoint
 * that {@see EmailChannel} routes every path through.
 *
 * A path is accepted only when realpath() resolves it AND the resolved path sits inside one of the
 * allowed base directories. Allowed bases come from config key `security.attachment_allowed_paths`
 * (an array of directories); when unset/empty the default is the application's storage directory
 * ({@see storage_path()}). Each base is itself normalized through realpath(), and the comparison
 * appends DIRECTORY_SEPARATOR so a sibling directory cannot masquerade as a child (e.g.
 * `/app/storage-evil` must NOT pass for base `/app/storage`). Anything that does not resolve
 * cleanly inside a base is rejected -- fail closed.
 *
 * @package Glueful\Extensions\EmailNotification
 */
final class AttachmentPathValidator
{
    /**
     * @var array<int, string> Allowed base directories, each a realpath() with a trailing
     *                         DIRECTORY_SEPARATOR. Bases that could not be resolved are dropped.
     */
    private array $allowedBases;

    /**
     * @param array<int, string> $allowedBases Raw (unresolved) allowed base directories.
     */
    public function __construct(array $allowedBases)
    {
        $resolved = [];
        foreach ($allowedBases as $base) {
            if (!is_string($base) || $base === '') {
                continue;
            }

            // Normalize the base through realpath() too -- a base that does not exist on disk
            // cannot confine anything and is silently dropped (it will simply confine nothing).
            $real = realpath($base);
            if ($real !== false) {
                $resolved[] = rtrim($real, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
            }
        }

        $this->allowedBases = $resolved;
    }

    /**
     * Build a validator from the channel config + application context.
     *
     * Reads `security.attachment_allowed_paths` (array of directories). When it is missing or
     * empty the sole allowed base is the application's storage directory, resolved via the
     * framework's {@see storage_path()} helper.
     *
     * @param array<string, mixed> $config Channel configuration (the merged mail config).
     */
    public static function fromConfig(ApplicationContext $context, array $config): self
    {
        /** @var array<string, mixed> $security */
        $security = is_array($config['security'] ?? null) ? $config['security'] : [];

        $configured = $security['attachment_allowed_paths'] ?? null;
        if (is_array($configured) && $configured !== []) {
            $bases = $configured;
        } else {
            // Default: the application's storage directory (framework helper, context-first).
            $bases = [storage_path($context)];
        }

        return new self(array_values($bases));
    }

    /**
     * Ensure $path resolves inside one of the allowed base directories.
     *
     * @throws InvalidAttachmentException When the path cannot be resolved or escapes every base.
     */
    public function validate(string $path): void
    {
        $real = realpath($path);

        // realpath() returns false for a non-existent path (covers most traversal attempts whose
        // resolved target does not exist) -- reject rather than hand an unresolved path to Symfony.
        if ($real === false) {
            throw new InvalidAttachmentException(
                $path,
                "Attachment path '{$path}' could not be resolved and is not permitted."
            );
        }

        foreach ($this->allowedBases as $base) {
            // Append DIRECTORY_SEPARATOR to both sides so a sibling dir cannot pass for a child:
            // '/app/storage-evil/' does not start with '/app/storage/'. The file itself is
            // compared with a trailing separator appended so an exact-base match (the dir itself)
            // is not mistaken for a contained file -- only real children pass.
            if (str_starts_with($real . DIRECTORY_SEPARATOR, $base)) {
                return;
            }
        }

        throw new InvalidAttachmentException(
            $path,
            "Attachment path '{$path}' resolves outside the allowed attachment directories."
        );
    }
}
