<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\Admin\DashboardResource;
use App\Services\Admin\PlatformOverview;

class DashboardController extends Controller
{
    public function __invoke(PlatformOverview $overview): DashboardResource
    {
        return new DashboardResource($overview->get());
    }
}
