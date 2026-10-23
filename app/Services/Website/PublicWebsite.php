<?php

namespace App\Services\Website;

use App\Http\Resources\PlanResource;
use App\Models\Plan;
use App\Models\WebsiteDocument;
use App\Services\Payments\PayMongo\PayMongoConfiguration;

class PublicWebsite
{
    public function data(bool $preview = false): array
    {
        $document = WebsiteDocument::findOrFail(1);
        $plans = Plan::whereIn('catalog_status', ['published', 'coming_soon'])
            ->orderBy('sort_order')->orderBy('id')->get();
        $data = ['content' => $preview ? $document->draft : $document->published,
            'plans' => PlanResource::collection($plans)->resolve(),
            'billing_available' => app(PayMongoConfiguration::class)->ready(),
            'published_at' => $document->published_at->toIso8601String(),
            'updated_at' => collect([$document->published_at, ...$plans->pluck('updated_at')])->max()?->toIso8601String(),
            'version' => $preview ? $document->version : $document->published_version];
        $data['revision'] = hash('sha256', json_encode($data, JSON_THROW_ON_ERROR));

        return $data;
    }
}
