<?php

declare(strict_types=1);

namespace PhpMiniCache\Storage;

/**
 * A stored value plus optional expiration metadata.
 */
final readonly class StoredValue
{
    public function __construct(
        public mixed $value,
        public ?float $expiresAt = null,
    ) {
    }

    public function isExpired(float $now): bool
    {
        return $this->expiresAt !== null && $now >= $this->expiresAt;
    }
}
