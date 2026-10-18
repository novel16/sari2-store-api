<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Administrators\AdministratorCreationPreviewRequest;
use App\Http\Requests\Admin\Administrators\AdministratorCreationRequest;
use App\Http\Requests\Admin\Administrators\AdministratorStatusChangeRequest;
use App\Http\Requests\Admin\Administrators\AdministratorStatusPreviewRequest;
use App\Http\Requests\Admin\DirectoryRequest;
use App\Http\Requests\Admin\StatusHistoryRequest;
use App\Http\Resources\Admin\AdministratorEventResource;
use App\Http\Resources\Admin\AdministratorResource;
use App\Models\Admin\AdministratorEvent;
use App\Models\User;
use App\Services\Admin\Administrators\AdministratorService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class AdministratorController extends Controller
{
    public function __construct(private AdministratorService $administrators) {}

    public function index(DirectoryRequest $request): AnonymousResourceCollection
    {
        $query = User::where('platform_role', 'platform_admin');
        if ($request->input('status', 'all') !== 'all') {
            $query->where('status', $request->validated('status'));
        }
        if ($request->filled('q')) {
            $term = '%'.$request->validated('q').'%';
            $query->where(fn (Builder $q) => $q->where('name', 'like', $term)->orWhere('email', 'like', $term));
        }

        return AdministratorResource::collection($query->latest()->orderByDesc('id')->paginate($request->integer('per_page', 20))
            ->withQueryString())->additional(['meta' => ['active_administrators' => $this->activeCount()]]);
    }

    public function show(User $administrator): JsonResponse
    {
        $this->requireAdministrator($administrator);

        return response()->json(['data' => new AdministratorResource($administrator),
            'meta' => ['active_administrators' => $this->activeCount()]]);
    }

    public function history(StatusHistoryRequest $request, User $administrator): AnonymousResourceCollection
    {
        $this->requireAdministrator($administrator);

        return AdministratorEventResource::collection(AdministratorEvent::where('administrator_user_id', $administrator->id)
            ->orderByDesc('id')->paginate($request->integer('per_page', 20)));
    }

    public function creationPreview(AdministratorCreationPreviewRequest $request): JsonResponse
    {
        return response()->json(['data' => $this->administrators->previewCreate($request->user(), $request->validated())]);
    }

    public function store(AdministratorCreationRequest $request): JsonResponse
    {
        return $this->result($this->administrators->create($request->user(), $request->validated()));
    }

    public function statusPreview(AdministratorStatusPreviewRequest $request, User $administrator): JsonResponse
    {
        $this->requireAdministrator($administrator);

        return response()->json(['data' => $this->administrators->previewStatus($administrator, $request->user(), $request->validated())]);
    }

    public function changeStatus(AdministratorStatusChangeRequest $request, User $administrator): JsonResponse
    {
        $this->requireAdministrator($administrator);

        return $this->result($this->administrators->changeStatus($administrator, $request->user(), $request->validated()));
    }

    public function byKey(Request $request, string $key): JsonResponse
    {
        return $this->result($this->administrators->byKey($request->user(), $key));
    }

    private function result(array $result): JsonResponse
    {
        return response()->json(['data' => $result['event']->result_snapshot,
            'event' => new AdministratorEventResource($result['event']), 'meta' => ['replayed' => $result['replayed']]],
            $result['replayed'] ? 200 : 201);
    }

    private function activeCount(): int
    {
        return User::where('platform_role', 'platform_admin')->where('status', 'active')->count();
    }

    private function requireAdministrator(User $user): void
    {
        abort_unless($user->isPlatformAdmin(), 404);
    }
}
