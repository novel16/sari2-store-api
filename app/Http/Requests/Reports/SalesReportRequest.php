<?php

namespace App\Http\Requests\Reports;

use App\Http\Requests\Catalog\CatalogRequest;
use App\Services\Reports\StoreActivity;
use Carbon\CarbonImmutable;
use Illuminate\Validation\Validator;

class SalesReportRequest extends CatalogRequest
{
    public function rules(): array
    {
        return [
            'date_from' => ['bail', 'required', 'string', 'date_format:Y-m-d', 'after_or_equal:1970-01-01', 'before_or_equal:9998-12-31'],
            'date_to' => ['bail', 'required', 'string', 'date_format:Y-m-d', 'after_or_equal:1970-01-01', 'before_or_equal:9998-12-31'],
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator) {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }
            $from = CarbonImmutable::createFromFormat('!Y-m-d', $this->input('date_from'), StoreActivity::TIMEZONE);
            $to = CarbonImmutable::createFromFormat('!Y-m-d', $this->input('date_to'), StoreActivity::TIMEZONE);
            if ($to < $from) {
                $validator->errors()->add('date_to', 'The end date must be on or after the start date.');

                return;
            }
            if ($from->diffInDays($to) > 365) {
                $validator->errors()->add('date_to', 'Choose a date range of up to 366 days.');
            }
        }];
    }
}
