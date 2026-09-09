<?php

declare(strict_types=1);

namespace App\Storage;

final class InMemoryStore implements Store
{
    /** @var array<string, mixed> */
    private array $data = [];

    public function set(string $key, mixed $value): void
    {
        $this->data[$key] = $value;
    }

    public function get(string $key): mixed
    {
        return $this->data[$key] ?? null;
    }

    public function has(string $key): bool
    {
        return array_key_exists($key, $this->data);
    }

    public function delete(string $key): bool
    {
        if (!array_key_exists($key, $this->data)) {
            return false;
        }

        unset($this->data[$key]);

        return true;
    }
}
