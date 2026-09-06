<?php

namespace App\Support;

/**
 * Holds the active tenant for the current request. Set by the SetTenant
 * middleware from the authenticated user; unset in CLI, queue, and tests — where
 * the BelongsToTenant global scope stays inert so nothing is filtered.
 */
class TenantContext
{
    private ?int $id = null;

    public function set(?int $id): void
    {
        $this->id = $id;
    }

    public function id(): ?int
    {
        return $this->id;
    }

    public function has(): bool
    {
        return $this->id !== null;
    }

    /** Run a callback as if scoped to $tenantId, restoring the previous tenant after. */
    public function actingAs(?int $tenantId, callable $callback): mixed
    {
        $previous = $this->id;
        $this->id = $tenantId;

        try {
            return $callback();
        } finally {
            $this->id = $previous;
        }
    }
}
