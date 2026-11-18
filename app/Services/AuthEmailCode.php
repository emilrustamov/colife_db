<?php

namespace App\Services;

use App\Mail\AuthCodeMail;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use InvalidArgumentException;

class AuthEmailCode
{
    public const PURPOSE_PASSWORD_RESET = 'password_reset';

    public const PURPOSE_EMAIL_VERIFY = 'email_verify';

    private const TTL_SECONDS = 900;

    private const MAX_ATTEMPTS = 5;

    /**
     * Issue a 6-digit code and email it. Returns nothing — callers only need the side effect.
     */
    public function send(string $email, string $purpose): void
    {
        $this->assertPurpose($purpose);

        $email = $this->normalizeEmail($email);
        $code = (string) random_int(100000, 999999);

        Cache::put($this->cacheKey($email, $purpose), [
            'hash' => Hash::make($code),
            'attempts' => 0,
        ], self::TTL_SECONDS);

        Mail::to($email)->send(new AuthCodeMail($code, $purpose, $this->ttlMinutes()));
    }

    /**
     * Verify a code. On success the code is consumed. On too many attempts it is cleared.
     */
    public function verify(string $email, string $purpose, string $code): bool
    {
        $this->assertPurpose($purpose);

        $email = $this->normalizeEmail($email);
        $key = $this->cacheKey($email, $purpose);
        $payload = Cache::get($key);

        if (! is_array($payload) || ! isset($payload['hash'])) {
            return false;
        }

        if (! Hash::check($code, (string) $payload['hash'])) {
            $attempts = (int) ($payload['attempts'] ?? 0) + 1;
            if ($attempts >= self::MAX_ATTEMPTS) {
                Cache::forget($key);
            } else {
                $payload['attempts'] = $attempts;
                Cache::put($key, $payload, self::TTL_SECONDS);
            }

            return false;
        }

        Cache::forget($key);

        return true;
    }

    public function ttlMinutes(): int
    {
        return (int) (self::TTL_SECONDS / 60);
    }

    private function cacheKey(string $email, string $purpose): string
    {
        return 'auth_email_code:'.$purpose.':'.sha1($email);
    }

    private function normalizeEmail(string $email): string
    {
        return mb_strtolower(trim($email));
    }

    private function assertPurpose(string $purpose): void
    {
        if (! in_array($purpose, [self::PURPOSE_PASSWORD_RESET, self::PURPOSE_EMAIL_VERIFY], true)) {
            throw new InvalidArgumentException('Unknown auth email code purpose.');
        }
    }
}
