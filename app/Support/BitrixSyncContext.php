<?php

namespace App\Support;

class BitrixSyncContext
{
    private bool $suspendContactPush = false;

    public function runWithoutContactPush(callable $callback): mixed
    {
        $previous = $this->suspendContactPush;
        $this->suspendContactPush = true;

        try {
            return $callback();
        } finally {
            $this->suspendContactPush = $previous;
        }
    }

    public function isContactPushSuspended(): bool
    {
        return $this->suspendContactPush;
    }
}
