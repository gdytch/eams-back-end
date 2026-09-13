<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\LogEntryResource;
use App\Services\LogFileService;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;

class SystemLogController extends Controller
{
    public function __construct(private LogFileService $logFileService) {}

    /**
     * Get a list of available log dates.
     */
    public function dates()
    {
        return response()->json([
            'dates' => $this->logFileService->availableDates(),
        ]);
    }

    /**
     * Get paginated log entries for a specific date, filtered by level and search.
     */
    public function index(Request $request)
    {
        $request->validate([
            'date' => ['required', 'date_format:Y-m-d'],
            'level' => ['nullable', 'string', 'in:debug,info,notice,warning,error,critical,alert,emergency,DEBUG,INFO,NOTICE,WARNING,ERROR,CRITICAL,ALERT,EMERGENCY'],
            'search' => ['nullable', 'string', 'max:255'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $date = (string) $request->input('date');
        $level = $request->filled('level') ? strtolower((string) $request->input('level')) : null;
        $search = $request->filled('search') ? (string) $request->input('search') : null;
        $perPage = (int) $request->input('per_page', 15);
        $page = (int) $request->input('page', 1);

        // Get filtered entries from the service
        $allEntries = $this->logFileService->entriesForDate($date, $level, $search);

        // Manually paginate the array
        $total = count($allEntries);
        $perPage = min($perPage, 100);
        $entries = array_slice($allEntries, ($page - 1) * $perPage, $perPage);

        $paginator = new LengthAwarePaginator(
            $entries,
            $total,
            $perPage,
            $page,
            [
                'path' => $request->url(),
            ]
        );

        return LogEntryResource::collection($paginator);
    }
}
