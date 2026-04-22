<?php

namespace App\Traits;

use Illuminate\Support\Facades\Auth;

trait VendorStatusTrait
{
    /**
     * Suspend vendor
     */
    public function suspend(string $reason = null): self
    {
        $this->update([
            'status' => 'suspended',
        ]);

        return $this;
    }

    /**
     * Activate vendor
     */
    public function activate(): self
    {
        $this->update([
            'status' => 'active',
        ]);

        return $this;
    }

    /**
     * Terminate vendor (ban)
     */
    public function terminate(string $reason = null): self
    {
        $this->update([
            'status' => 'banned',
        ]);

        return $this;
    }

    /**
     * Check if vendor is suspended
     */
    public function isSuspended(): bool
    {
        return $this->status === 'suspended';
    }

    /**
     * Check if vendor is active
     */
    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    /**
     * Check if vendor is banned
     */
    public function isBanned(): bool
    {
        return $this->status === 'banned';
    }

    /**
     * Check if vendor is pending
     */
    public function isPending(): bool
    {
        return $this->status === 'pending';
    }
}