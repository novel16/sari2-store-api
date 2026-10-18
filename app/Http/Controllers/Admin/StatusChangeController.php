<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StatusChangeRequest;
use App\Http\Requests\Admin\StatusHistoryRequest;
use App\Http\Requests\Admin\StatusPreviewRequest;
use App\Http\Resources\Admin\StatusChangeResource;
use App\Models\Account;
use App\Models\Store;
use App\Services\Admin\Status\StatusChangeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class StatusChangeController extends Controller
{
    public function __construct(private StatusChangeService $changes) {}

    public function accountHistory(StatusHistoryRequest $request, Account $account): AnonymousResourceCollection
    {
        return $this->history($request, $account);
    }

    public function storeHistory(StatusHistoryRequest $request, Store $store): AnonymousResourceCollection
    {
        return $this->history($request, $store);
    }

    public function accountPreview(StatusPreviewRequest $request, Account $account): JsonResponse
    {
        return response()->json(['data' => $this->changes->preview($account, $request->user(), $request->validated())]);
    }

    public function storePreview(StatusPreviewRequest $request, Store $store): JsonResponse
    {
        return response()->json(['data' => $this->changes->preview($store, $request->user(), $request->validated())]);
    }

    public function accountChange(StatusChangeRequest $request, Account $account): JsonResponse
    {
        return $this->result($this->changes->change($account, $request->user(), $request->validated()));
    }

    public function storeChange(StatusChangeRequest $request, Store $store): JsonResponse
    {
        return $this->result($this->changes->change($store, $request->user(), $request->validated()));
    }

    public function accountByKey(Request $request, Account $account, string $key): JsonResponse
    {
        return $this->result($this->changes->byKey($account, $request->user(), $key));
    }

    public function storeByKey(Request $request, Store $store, string $key): JsonResponse
    {
        return $this->result($this->changes->byKey($store, $request->user(), $key));
    }

    private function history(StatusHistoryRequest $request, Account|Store $target): AnonymousResourceCollection
    {
        return StatusChangeResource::collection($this->changes->history($target)->paginate($request->integer('per_page', 20)));
    }

    private function result(array $result): JsonResponse
    {
        return response()->json(['data' => new StatusChangeResource($result['change']),
            'meta' => ['replayed' => $result['replayed']]], $result['replayed'] ? 200 : 201);
    }
}
