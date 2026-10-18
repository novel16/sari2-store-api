<?php

namespace App\Http\Requests\Admin;

use Carbon\CarbonImmutable;
use Closure;
use DateTimeImmutable;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Pagination\Cursor;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class ActivityRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // The route requires an active platform administrator.
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('q'))) {
            $this->merge(['q' => trim($this->input('q'))]);
        }
    }

    public function rules(): array
    {
        return [
            'q' => ['sometimes', 'nullable', 'string', 'max:120'],
            'module' => ['sometimes', Rule::in(['all', 'administrators', 'accounts', 'stores', 'plans', 'subscriptions'])],
            'action' => ['sometimes', Rule::in(['all', 'created', 'suspended', 'reactivated', 'plan_version_recorded', 'activated', 'renewed'])],
            'actor_id' => ['sometimes', 'integer', 'min:0', 'max:'.PHP_INT_MAX],
            'date_from' => ['required_with:date_to', 'date_format:Y-m-d'],
            'date_to' => ['required_with:date_from', 'date_format:Y-m-d', 'after_or_equal:date_from'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'cursor' => ['sometimes', 'nullable', 'string', 'max:2048', function (string $attribute, mixed $value, Closure $fail) {
                if (is_string($value) && $this->decodeCursor($value) === null) {
                    $fail('The activity cursor is invalid. Restart from the latest activity.');
                }
            }],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            if ($validator->errors()->hasAny(['date_from', 'date_to']) || ! $this->filled('date_from') || ! $this->filled('date_to')) {
                return;
            }

            $from = CarbonImmutable::createFromFormat('!Y-m-d', $this->input('date_from'), 'Asia/Manila');
            $to = CarbonImmutable::createFromFormat('!Y-m-d', $this->input('date_to'), 'Asia/Manila');
            if ($from->diffInDays($to) > 365) {
                $validator->errors()->add('date_to', 'Choose a date range of no more than 366 days.');
            }
        });
    }

    public function filters(): array
    {
        $input = $this->validated();
        $today = CarbonImmutable::now('Asia/Manila')->startOfDay();

        return [
            'q' => $input['q'] ?? '',
            'module' => $input['module'] ?? 'all',
            'action' => $input['action'] ?? 'all',
            'actor_id' => array_key_exists('actor_id', $input) ? (int) $input['actor_id'] : null,
            'date_from' => $input['date_from'] ?? $today->subDays(29)->toDateString(),
            'date_to' => $input['date_to'] ?? $today->toDateString(),
        ];
    }

    public function activityCursor(): ?Cursor
    {
        $encoded = $this->validated('cursor');

        return is_string($encoded) ? $this->decodeCursor($encoded) : null;
    }

    /** Validate before calling Laravel's decoder: malformed cursor JSON must produce 422, never a warning or 500. */
    private function decodeCursor(string $encoded): ?Cursor
    {
        if (! preg_match('/\A[A-Za-z0-9_-]+\z/', $encoded)) {
            return null;
        }

        $decoded = base64_decode(strtr($encoded, '-_', '+/'), true);
        $values = $decoded === false ? null : json_decode($decoded, true);
        $keys = ['created_at', 'source_rank', 'id', '_pointsToNextItems'];
        if (! is_array($values) || count($values) !== count($keys) || array_diff($keys, array_keys($values)) !== []) {
            return null;
        }
        if (! is_bool($values['_pointsToNextItems']) || ! is_string($values['created_at'])
            || (! is_int($values['id']) && ! is_string($values['id']))
            || (! is_int($values['source_rank']) && ! is_string($values['source_rank']))
            || ! preg_match('/\A\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}(?:\.\d{1,6})?\z/', $values['created_at'])) {
            return null;
        }

        $date = DateTimeImmutable::createFromFormat(str_contains($values['created_at'], '.') ? '!Y-m-d H:i:s.u' : '!Y-m-d H:i:s', $values['created_at']);
        $dateErrors = DateTimeImmutable::getLastErrors();
        $id = filter_var($values['id'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => PHP_INT_MAX]]);
        $rank = filter_var($values['source_rank'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 4]]);
        if ($date === false || ($dateErrors !== false && ($dateErrors['warning_count'] > 0 || $dateErrors['error_count'] > 0)) || $id === false || $rank === false) {
            return null;
        }

        return new Cursor(['created_at' => $values['created_at'], 'source_rank' => $rank, 'id' => $id], $values['_pointsToNextItems']);
    }
}
