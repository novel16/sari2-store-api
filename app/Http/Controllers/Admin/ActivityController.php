<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ActivityRequest;
use App\Http\Resources\Admin\ActivityEntryResource;
use App\Services\Admin\Activity\ActivityLog;
use App\Services\Admin\AdministratorAccess;
use Illuminate\Http\JsonResponse;

class ActivityController extends Controller
{
    public function __invoke(ActivityRequest $request, ActivityLog $activity): JsonResponse
    {
        AdministratorAccess::fresh($request->user());
        $filters = $request->filters();
        $perPage = $request->integer('per_page', 20);
        $page = $activity->read($filters, $perPage, $request->activityCursor());

        return response()->json(['data' => ActivityEntryResource::collection($page['entries']),
            'meta' => ['per_page' => $perPage, 'next_cursor' => $page['next_cursor'], 'prev_cursor' => $page['prev_cursor'],
                'time_zone' => 'Asia/Manila', 'filters' => $filters]]);
    }
}
