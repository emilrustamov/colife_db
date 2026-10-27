<?php

namespace App\Services\AlmaBridge;

use Illuminate\Http\Request;
use InvalidArgumentException;

class ProjectResolver
{
    /**
     * Resolve Dubai / HongKong project from webhook auth.domain.
     */
    public function fromRequest(Request $request, ?int $entityTypeId = null): string
    {
        $domain = $this->normalizeDomain((string) (
            data_get($request->all(), 'auth.domain')
            ?: $request->input('domain', '')
        ));

        if ($domain === '') {
            throw new InvalidArgumentException('Alma bridge webhook auth.domain is required');
        }

        return $this->fromDomain($domain);
    }

    /**
     * Resolve project name by Bitrix portal domain.
     */
    public function fromDomain(string $domain): string
    {
        $domain = $this->normalizeDomain($domain);
        if ($domain === '') {
            throw new InvalidArgumentException('Alma bridge domain is empty');
        }

        foreach (config('alma_bridge.projects', []) as $projectKey => $project) {
            if (! is_array($project)) {
                continue;
            }

            $projectDomain = $this->normalizeDomain((string) ($project['domain'] ?? ''));
            if ($projectDomain !== '' && strcasecmp($projectDomain, $domain) === 0) {
                return (string) ($project['name'] ?? $projectKey);
            }
        }

        throw new InvalidArgumentException('Unknown Alma bridge portal domain: '.$domain);
    }

    private function normalizeDomain(string $domain): string
    {
        $domain = (string) preg_replace('#^https?://#i', '', $domain);

        return rtrim(trim($domain), '/');
    }
}
