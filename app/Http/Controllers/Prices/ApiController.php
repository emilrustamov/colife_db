<?php

namespace App\Http\Controllers\Prices;

use App\Http\Controllers\Controller;
use App\Services\Prices\ReportQueryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Throwable;

class ApiController extends Controller
{
    public function __invoke(Request $request, ReportQueryService $reports): JsonResponse|BinaryFileResponse
    {
        $action = (string) $request->query('action', '');
        $input = $request->all();

        try {
            return match ($action) {
                'meta' => response()->json(['success' => true, 'data' => $reports->meta()], JSON_UNESCAPED_UNICODE),
                'units' => response()->json(['success' => true, 'data' => $reports->units($input)], JSON_UNESCAPED_UNICODE),
                'stats' => response()->json(['success' => true, 'data' => $reports->stats()]),
                'month_contracts' => response()->json(['success' => true, 'data' => $reports->monthContracts($input)], JSON_UNESCAPED_UNICODE),
                'report' => response()->json(['success' => true, 'data' => $reports->report($input)], JSON_UNESCAPED_UNICODE),
                'export' => $this->export($reports, $input),
                default => response()->json(['success' => false, 'error' => 'Unknown action'], 400),
            };
        } catch (InvalidArgumentException $e) {
            return response()->json(['success' => false, 'error' => $e->getMessage()], 422);
        } catch (Throwable $e) {
            report($e);

            return response()->json([
                'success' => false,
                'error' => app()->hasDebugModeEnabled() ? $e->getMessage() : 'Internal error',
            ], 500);
        }
    }

    /**
     * @param  array<string, mixed>  $input
     */
    private function export(ReportQueryService $reports, array $input): BinaryFileResponse
    {
        $file = $reports->export($input);

        return response()
            ->download($file['path'], $file['filename'], [
                'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                'Cache-Control' => 'no-store',
            ])
            ->deleteFileAfterSend(true);
    }
}
