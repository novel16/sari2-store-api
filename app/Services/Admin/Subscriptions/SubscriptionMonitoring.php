<?php

namespace App\Services\Admin\Subscriptions;

use App\Models\Plan;
use App\Models\Subscription;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class SubscriptionMonitoring
{
    // Only a SQL comparison boundary. Resources convert open-ended coverage back to null.
    private const OPEN_END = '9999-12-31 23:59:59';

    public function read(array $filters, int $perPage, int $page): array
    {
        $at = CarbonImmutable::now('UTC');

        return DB::transaction(function () use ($filters, $perPage, $page, $at) {
            $query = DB::query()->fromSub($this->accounts($at, $filters['expiry_days']), 'monitor');
            if ($filters['q'] !== '') {
                $term = '%'.$filters['q'].'%';
                $query->where(fn (Builder $search) => $search->where('account_name', 'like', $term)
                    ->orWhere('owner_name', 'like', $term)->orWhere('owner_email', 'like', $term));
            }
            if ($filters['account_status'] !== 'all') {
                $query->where('account_status', $filters['account_status']);
            }

            // Cards follow the search/account restriction, independent of state filter and page.
            $summary = (clone $query)->selectRaw("COUNT(*) AS total,
                COALESCE(SUM(CASE WHEN state = 'active_basic' THEN 1 ELSE 0 END), 0) AS active_basic,
                COALESCE(SUM(is_expiring), 0) AS expiring,
                COALESCE(SUM(CASE WHEN state = 'expired_paid' THEN 1 ELSE 0 END), 0) AS expired_paid,
                COALESCE(SUM(CASE WHEN state = 'free' THEN 1 ELSE 0 END), 0) AS free,
                COALESCE(SUM(CASE WHEN state = 'other_paid' THEN 1 ELSE 0 END), 0) AS other_paid")->first();
            if ($filters['status'] === 'expiring') {
                $query->where('is_expiring', 1);
            } elseif ($filters['status'] !== 'all') {
                $query->where('state', $filters['status']);
            }

            $results = $query->orderByDesc('account_id')->paginate($perPage, ['*'], 'page', $page);

            return ['entries' => $this->hydrate($results->getCollection()),
                'summary' => array_map(fn ($count) => (int) $count, (array) $summary),
                'meta' => ['current_page' => $results->currentPage(), 'last_page' => $results->lastPage(),
                    'total' => $results->total(), 'per_page' => $results->perPage(),
                    'from' => $results->firstItem(), 'to' => $results->lastItem(),
                    'filters' => $filters, 'time_zone' => 'Asia/Manila', 'generated_at' => $at->toIso8601String()]];
        });
    }

    private function accounts(CarbonImmutable $at, int $days): Builder
    {
        // Use the same half-open validity scope and highest-ID rule as EffectivePlan/Account.
        $current = Subscription::query()->coveringAt($at)->selectRaw('account_id, MAX(id) AS subscription_id')
            ->groupBy('account_id')->toBase();
        $ended = $this->paidPeriods()->where('paid.ends_at', '<=', $at->toDateTimeString())
            ->selectRaw('paid.account_id, MAX(paid.ends_at) AS last_paid_ended_at')->groupBy('paid.account_id');
        $scheduled = $this->paidPeriods()->where('paid.status', 'active')
            ->where('paid.starts_at', '>', $at->toDateTimeString());
        $scheduledCounts = (clone $scheduled)->selectRaw('paid.account_id, COUNT(*) AS scheduled_periods')->groupBy('paid.account_id');
        $nextId = (clone $scheduled)->whereColumn('paid.account_id', 'accounts.id')->select('paid.id')
            ->orderBy('paid.starts_at')->orderBy('paid.id')->limit(1);

        $paid = "current_plan.code IS NOT NULL AND current_plan.code <> 'free'";
        $cutoff = $at->setTimezone('Asia/Manila')->addDays($days)->utc()->toDateTimeString();

        return DB::table('accounts')->join('users as owner', 'owner.id', '=', 'accounts.owner_user_id')
            ->leftJoinSub($current, 'current_period', 'current_period.account_id', '=', 'accounts.id')
            ->leftJoin('subscriptions as current_subscription', 'current_subscription.id', '=', 'current_period.subscription_id')
            ->leftJoin('plans as current_plan', 'current_plan.id', '=', 'current_subscription.plan_id')
            ->leftJoinSub($this->coverage($at), 'coverage', 'coverage.account_id', '=', 'accounts.id')
            ->leftJoinSub($ended, 'ended', 'ended.account_id', '=', 'accounts.id')
            ->leftJoinSub($scheduledCounts, 'scheduled', 'scheduled.account_id', '=', 'accounts.id')
            ->select(['accounts.id as account_id', 'accounts.name as account_name', 'accounts.status as account_status',
                'owner.id as owner_id', 'owner.name as owner_name', 'owner.email as owner_email', 'owner.status as owner_status',
                'current_period.subscription_id as current_subscription_id', 'ended.last_paid_ended_at'])
            ->selectSub($nextId, 'next_subscription_id')
            ->selectRaw("COALESCE(scheduled.scheduled_periods, 0) AS scheduled_periods,
                CASE WHEN {$paid} THEN 1 ELSE 0 END AS is_paid,
                CASE WHEN current_plan.code = 'basic' THEN 'active_basic'
                    WHEN {$paid} THEN 'other_paid'
                    WHEN ended.last_paid_ended_at IS NOT NULL THEN 'expired_paid' ELSE 'free' END AS state,
                CASE WHEN {$paid} THEN coverage.coverage_ends_at ELSE NULL END AS coverage_ends_at,
                CASE WHEN {$paid} AND coverage.coverage_ends_at > ? AND coverage.coverage_ends_at <= ?
                    THEN 1 ELSE 0 END AS is_expiring", [$at->toDateTimeString(), $cutoff]);
    }

    private function paidPeriods(): Builder
    {
        return DB::table('subscriptions as paid')->join('plans as paid_plan', 'paid_plan.id', '=', 'paid.plan_id')
            ->where('paid_plan.code', '!=', 'free')
            ->where(fn (Builder $valid) => $valid->whereNull('paid.ends_at')->orWhereColumn('paid.ends_at', '>', 'paid.starts_at'));
    }

    private function coverage(CarbonImmutable $at): Builder
    {
        $periods = $this->paidPeriods()->where('paid.status', 'active')
            ->where(fn (Builder $remaining) => $remaining->whereNull('paid.ends_at')->orWhere('paid.ends_at', '>', $at->toDateTimeString()))
            ->select(['paid.id', 'paid.account_id', 'paid.starts_at'])
            ->selectRaw('COALESCE(paid.ends_at, ?) AS end_boundary', [self::OPEN_END]);
        $ordered = DB::query()->fromSub($periods, 'periods')->select('*')
            ->selectRaw('MAX(end_boundary) OVER (PARTITION BY account_id ORDER BY starts_at, id
                ROWS BETWEEN UNBOUNDED PRECEDING AND 1 PRECEDING) AS previous_end');
        $marked = DB::query()->fromSub($ordered, 'ordered_periods')->select('*')
            ->selectRaw('CASE WHEN previous_end IS NULL OR starts_at > previous_end THEN 1 ELSE 0 END AS begins_island');
        $islands = DB::query()->fromSub($marked, 'marked_periods')->select('*')
            ->selectRaw('SUM(begins_island) OVER (PARTITION BY account_id ORDER BY starts_at, id
                ROWS UNBOUNDED PRECEDING) AS island');

        // Running MAX handles nested overlaps; a plain LAG(end) would invent gaps.
        // Only the first island can cover now, because ended periods were excluded above.
        return DB::query()->fromSub($islands, 'islands')->where('island', 1)->groupBy('account_id')
            ->havingRaw('MIN(starts_at) <= ?', [$at->toDateTimeString()])->select('account_id')
            ->selectRaw('NULLIF(MAX(end_boundary), ?) AS coverage_ends_at', [self::OPEN_END]);
    }

    private function hydrate(Collection $rows): Collection
    {
        $ids = $rows->flatMap(fn (object $row) => [$row->current_subscription_id, $row->next_subscription_id])->filter()->unique();
        $periods = $ids->isEmpty() ? collect() : Subscription::query()
            ->select(['id', 'plan_id', 'plan_version_id', 'starts_at', 'ends_at'])
            ->with(['plan', 'planVersion'])->whereIn('id', $ids)->get()->keyBy('id');
        $free = Plan::query()->where('code', 'free')->first();
        $freePlan = ['code' => 'free', 'name' => $free?->name ?? 'Free'];

        return $rows->map(function (object $row) use ($periods, $freePlan) {
            return ['row' => $row, 'current' => $periods->get($row->current_subscription_id),
                'next' => $periods->get($row->next_subscription_id), 'free_plan' => $freePlan];
        });
    }
}
