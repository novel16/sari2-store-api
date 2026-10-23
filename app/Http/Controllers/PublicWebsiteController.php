<?php

namespace App\Http\Controllers;

use App\Services\Website\PublicWebsite;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PublicWebsiteController extends Controller
{
    public function __invoke(Request $request, PublicWebsite $website): JsonResponse
    {
        $data = $website->data();
        $response = response()->json(['data' => $data])->setEtag($data['revision'])
            ->header('Cache-Control', 'public, max-age=0, must-revalidate')->header('X-Robots-Tag', 'noindex');
        $response->isNotModified($request);

        return $response;
    }
}
