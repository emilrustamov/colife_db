<?php

namespace App\Support;

class BitrixSync
{
    private bool $noPush = false;

    private bool $force = false;

    /**
     * Run callback while contact observer push to Bitrix is suspended.
     */
    public function withoutPush(callable $callback): mixed
    {
        $previous = $this->noPush;
        $this->noPush = true;

        try {
            return $callback();
        } finally {
            $this->noPush = $previous;
        }
    }

    /**
     * Whether contact push to Bitrix is currently suspended.
     */
    public function pushPaused(): bool
    {
        return $this->noPush;
    }

    /**
     * Run callback with freshness skip disabled (full upsert like nightly sync).
     */
    public function forceUpsert(callable $callback): mixed
    {
        $previous = $this->force;
        $this->force = true;

        try {
            return $callback();
        } finally {
            $this->force = $previous;
        }
    }

    /**
     * Whether sync profiles must upsert even if bitrix_updated_at is not newer.
     */
    public function forcing(): bool
    {
        return $this->force;
    }
}
