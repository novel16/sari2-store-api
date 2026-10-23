<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\WebsiteRequest;
use App\Models\WebsiteDocument;
use App\Models\WebsiteRevision;
use App\Services\Admin\AdministratorAccess;
use App\Services\Admin\Website\SaveWebsite;
use App\Services\Website\PublicWebsite;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class WebsiteController extends Controller
{
    private function document(WebsiteDocument $document): array
    {
        return ['version' => $document->version, 'published_version' => $document->published_version,
            'published_at' => $document->published_at->toIso8601String(), 'draft' => $document->draft,
            'published' => $document->published, 'site_url' => rtrim(config('app.frontend_url'), '/')];
    }

    public function show(Request $request): JsonResponse
    {
        AdministratorAccess::fresh($request->user());

        return response()->json(['data' => $this->document(WebsiteDocument::findOrFail(1))])->header('Cache-Control', 'no-store');
    }

    public function update(WebsiteRequest $request, SaveWebsite $save): JsonResponse
    {
        return response()->json(['data' => $this->document($save->handle($request->user(), $request->validated(), 'saved'))]);
    }

    public function publish(WebsiteRequest $request, SaveWebsite $save): JsonResponse
    {
        return response()->json(['data' => $this->document($save->handle($request->user(), $request->validated(), 'published'))]);
    }

    public function restore(WebsiteRequest $request, int $revision, SaveWebsite $save): JsonResponse
    {
        return response()->json(['data' => $this->document($save->handle($request->user(), $request->validated(), 'restored', $revision))]);
    }

    public function preview(Request $request, PublicWebsite $website): JsonResponse
    {
        AdministratorAccess::fresh($request->user());

        return response()->json(['data' => $website->data(true)])->header('Cache-Control', 'no-store')->header('X-Robots-Tag', 'noindex, nofollow');
    }

    public function history(Request $request): JsonResponse
    {
        AdministratorAccess::fresh($request->user());
        $page = WebsiteRevision::where('website_document_id', 1)->select([
            'id', 'version', 'action', 'actor_name_snapshot', 'change_note', 'created_at',
        ])->orderByDesc('version')->paginate(10);

        return response()->json($page)->header('Cache-Control', 'no-store');
    }

    public function upload(Request $request): JsonResponse
    {
        AdministratorAccess::fresh($request->user());
        $input = $request->validate(['image' => ['required', 'file', 'image', 'mimes:jpg,jpeg,png,webp', 'max:5120', 'dimensions:max_width=6000,max_height=6000']]);
        $path = Storage::disk('public')->putFile('website', $input['image']);
        abort_unless($path, 503, 'The image could not be saved.');

        return response()->json(['data' => ['url' => Storage::disk('public')->url($path)]], 201);
    }
}
