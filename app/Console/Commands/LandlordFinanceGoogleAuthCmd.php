<?php

namespace App\Console\Commands;

use App\Services\GoogleSheetsApi;
use Illuminate\Console\Command;
use Throwable;

class LandlordFinanceGoogleAuthCmd extends Command
{
    protected $signature = 'landlord-finance:google-auth
        {--code= : Authorization code (or full redirect URL with ?code=)}';

    protected $description = 'One-time Google OAuth login for landlord finance Sheets access';

    /**
     * Run interactive OAuth consent and store refresh token.
     */
    public function handle(GoogleSheetsApi $sheetsApi): int
    {
        $codeOption = trim((string) $this->option('code'));
        if ($codeOption === '') {
            $url = $sheetsApi->oauthAuthUrl();
            $this->info('1) Open this URL while logged in as admin@colife.ae:');
            $this->line($url);
            $this->newLine();
            $this->info('2) Allow access. Browser will open http://127.0.0.1:8765/?code=... (page may fail — это ок).');
            $this->info('3) Copy the FULL URL from the address bar (must contain code=), or only the code value.');
            $this->warn('Do not use http://localhost — local nginx may redirect to hasapchy.local and drop the code.');
            $this->newLine();

            $codeOption = trim((string) $this->ask('Paste code or full redirect URL'));
        }

        $code = $this->extractCode($codeOption);
        if ($code === '') {
            $this->error('Authorization code is empty.');

            return self::FAILURE;
        }

        try {
            $token = $sheetsApi->exchangeOauthCode($code);
        } catch (Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $path = (string) config('services.landlord_finance.oauth_token_path');
        $this->info('OAuth OK. Refresh token saved to: '.$path);
        $this->line('scope: '.((string) ($token['scope'] ?? GoogleSheetsApi::SCOPE)));
        $this->line('Next: LANDLORD_FINANCE_AUTH=oauth and test a spreadsheet read.');

        return self::SUCCESS;
    }

    /**
     * Extract OAuth code from raw code or redirect URL.
     */
    private function extractCode(string $input): string
    {
        $input = trim($input);
        if ($input === '') {
            return '';
        }

        if (str_contains($input, 'code=')) {
            $query = parse_url($input, PHP_URL_QUERY);
            if (is_string($query) && $query !== '') {
                parse_str($query, $params);
                if (! empty($params['code']) && is_string($params['code'])) {
                    return trim($params['code']);
                }
            }
            if (preg_match('/[?&]code=([^&]+)/', $input, $m) === 1) {
                return urldecode($m[1]);
            }
        }

        return $input;
    }
}
