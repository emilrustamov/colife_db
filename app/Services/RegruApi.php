<?php

namespace App\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class RegruApi
{
    /**
     * Fetch REG.RU domains and expiration dates.
     *
     * @return list<array{fqdn: string, expire: string|null}>
     */
    public function getDomains(): array
    {
        $username = trim((string) config('services.regru.username'));
        $password = (string) config('services.regru.password');
        $baseUrl = rtrim((string) config('services.regru.api_url'), '/');

        if ($username === '' || $password === '') {
            throw new RuntimeException('REG.RU API username or password is not configured.');
        }

        $response = Http::asForm()
            ->acceptJson()
            ->post($baseUrl.'/service/get_list', [
                'username' => $username,
                'password' => $password,
                'output_format' => 'json',
                'input_format' => 'json',
                'input_data' => json_encode(['servtype' => 'domain'], JSON_UNESCAPED_UNICODE),
            ])
            ->throw()
            ->json();

        if (! is_array($response) || ($response['result'] ?? '') !== 'success') {
            $message = is_array($response)
                ? (string) ($response['error_text'] ?? $response['error_code'] ?? 'unknown error')
                : 'invalid response';

            throw new RuntimeException('REG.RU service/get_list request failed: '.$message);
        }

        $services = is_array($response['answer']['services'] ?? null)
            ? $response['answer']['services']
            : [];
        $domains = [];

        foreach ($services as $service) {
            if (! is_array($service)) {
                continue;
            }

            $fqdn = trim((string) ($service['dname'] ?? ''));
            if ($fqdn === '') {
                continue;
            }

            $domains[] = [
                'fqdn' => $fqdn,
                'expire' => $this->expireDate($service['expiration_date'] ?? null),
            ];
        }

        return $domains;
    }

    /**
     * Normalize a REG.RU expiration value to Y-m-d.
     */
    private function expireDate(mixed $expire): ?string
    {
        if (is_string($expire) && $expire !== '' && $expire !== '0') {
            return Carbon::parse($expire)->format('Y-m-d');
        }

        if (is_numeric($expire) && (int) $expire > 1) {
            return Carbon::createFromTimestamp((int) $expire)->format('Y-m-d');
        }

        return null;
    }
}
