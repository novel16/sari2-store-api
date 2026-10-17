<?php

namespace App\Services\Admin;

use App\Models\Account;
use App\Models\Store;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

class PlatformOverview
{
    public function get(): array
    {
        return DB::transaction(function () {
            $today = CarbonImmutable::now('Asia/Manila')->startOfDay();
            $from = $today->subDays(29);

            return [
                'summary' => [
                    'accounts' => $this->counts(Account::query()),
                    'stores' => $this->counts(Store::query()),
                    'users' => $this->counts(User::query()),
                    'administrators' => $this->counts(User::where('platform_role', 'platform_admin')),
                ],
                'registrations' => $this->registrations($from, $today),
                'timezone' => 'Asia/Manila',
                'date_from' => $from->toDateString(), 'date_to' => $today->toDateString(),
                'recent_accounts' => Account::with('owner')->withCount(['stores', 'memberships'])->latest()->orderByDesc('id')->limit(5)->get(),
                'recent_stores' => Store::with('account.owner')->latest()->orderByDesc('id')->limit(5)->get(),
                'generated_at' => now()->toIso8601String(),
            ];
        });
    }

    private function registrations(CarbonImmutable $from, CarbonImmutable $today): array
    {
        $days = [];
        $cases = [];
        $bindings = [];

        // Bind UTC day boundaries so MySQL and SQLite use the same Philippine calendar days.
        for ($day = $from; $day <= $today; $day = $day->addDay()) {
            $date = $day->toDateString();
            $days[$date] = ['date' => $date, 'accounts' => 0];
            $cases[] = 'WHEN created_at < ? THEN ?';
            array_push($bindings, $day->addDay()->utc()->toDateTimeString(), $date);
        }

        $daily = Account::query()->where('created_at', '>=', $from->utc()->toDateTimeString())
            ->where('created_at', '<', $today->addDay()->utc()->toDateTimeString())
            ->selectRaw('CASE '.implode(' ', $cases).' END AS business_date, COUNT(*) AS total', $bindings)
            ->groupBy('business_date')->get();
        foreach ($daily as $row) {
            $days[$row->business_date]['accounts'] = (int) $row->total;
        }

        return array_values($days);
    }

    private function counts(Builder $query): array
    {
        $row = $query->selectRaw("COUNT(*) AS total,
            COALESCE(SUM(CASE WHEN status = 'active' THEN 1 ELSE 0 END),0) AS active,
            COALESCE(SUM(CASE WHEN status = 'suspended' THEN 1 ELSE 0 END),0) AS suspended")->first();

        return ['total' => (int) $row->total, 'active' => (int) $row->active, 'suspended' => (int) $row->suspended];
    }
}
