<?php

namespace App\Http\Requests\Dashboard;

use App\Http\Requests\Catalog\CatalogRequest;
use Illuminate\Validation\Rule;

class DashboardRequest extends CatalogRequest
{
    public function rules(): array
    {
        return ['period' => ['sometimes', Rule::in(['this_week', 'last_week', 'this_month'])]];
    }
}
