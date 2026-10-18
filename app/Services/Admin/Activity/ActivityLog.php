<?php

namespace App\Services\Admin\Activity;

use App\Models\Admin\AdministratorEvent;
use App\Models\Admin\StatusChange;
use App\Models\Admin\SubscriptionActivation;
use App\Models\PlanVersion;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Pagination\Cursor;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class ActivityLog
{
    private const SOURCES = [
        'administrator_event' => ['rank' => 1, 'table' => 'admin_administrator_events', 'modules' => ['administrators']],
        'status_change' => ['rank' => 2, 'table' => 'admin_status_changes', 'modules' => ['accounts', 'stores']],
        'plan_version' => ['rank' => 3, 'table' => 'plan_versions', 'modules' => ['plans']],
        'subscription_activation' => ['rank' => 4, 'table' => 'subscription_activations', 'modules' => ['subscriptions']],
    ];

    public function read(array $filters, int $perPage, ?Cursor $cursor): array
    {
        $from = CarbonImmutable::createFromFormat('!Y-m-d', $filters['date_from'], 'Asia/Manila')->utc();
        $until = CarbonImmutable::createFromFormat('!Y-m-d', $filters['date_to'], 'Asia/Manila')->addDay()->utc();
        $union = null;

        foreach (self::SOURCES as $source => $definition) {
            if ($filters['module'] !== 'all' && ! in_array($filters['module'], $definition['modules'], true)) {
                continue;
            }
            $query = $this->source($source, $definition, $filters);
            if ($query === null) {
                continue;
            }

            // Date, actor and search predicates apply inside each indexed branch, before the union.
            $query->where('created_at', '>=', $from->format('Y-m-d H:i:s'))
                ->where('created_at', '<', $until->format('Y-m-d H:i:s'));
            if ($filters['actor_id'] === 0) {
                $query->whereNull('actor_user_id');
            } elseif ($filters['actor_id'] !== null) {
                $query->where('actor_user_id', $filters['actor_id']);
            }
            $union = $union === null ? $query : $union->unionAll($query);
        }

        if ($union === null) {
            return ['entries' => collect(), 'next_cursor' => null, 'prev_cursor' => null];
        }

        // A union of tiny headers keeps large JSON terms/result snapshots out of sorting and pagination.
        // The stable tuple also prevents newer insertions from shifting the next page.
        $page = DB::query()->fromSub($union, 'activity_headers')->select('id', 'source', 'source_rank', 'created_at')
            ->orderByDesc('created_at')->orderByDesc('source_rank')->orderByDesc('id')
            ->cursorPaginate($perPage, ['*'], 'cursor', $cursor);

        return ['entries' => $this->hydrate($page->getCollection()),
            'next_cursor' => $page->nextCursor()?->encode(),
            'prev_cursor' => $page->previousCursor()?->encode()];
    }

    private function source(string $source, array $definition, array $filters): ?Builder
    {
        $query = DB::table($definition['table'])->select('id', 'created_at')
            ->selectRaw("'{$source}' as source, {$definition['rank']} as source_rank");
        $action = $filters['action'];

        switch ($source) {
            case 'administrator_event':
                if (! in_array($action, ['all', 'created', 'suspended', 'reactivated'], true)) {
                    return null;
                }
                $query->whereIn('action', ['created', 'status_changed']);
                if ($action === 'created') {
                    $query->where('action', 'created');
                } elseif ($action !== 'all') {
                    $query->where('action', 'status_changed')->where('to_status', $action === 'suspended' ? 'suspended' : 'active');
                }
                $search = ['administrator_name_snapshot', 'actor_name_snapshot', 'reason'];
                break;
            case 'status_change':
                if (! in_array($action, ['all', 'suspended', 'reactivated'], true)) {
                    return null;
                }
                $query->whereIn('target_type', ['account', 'store'])->whereIn('to_status', ['active', 'suspended']);
                if ($filters['module'] !== 'all') {
                    $query->where('target_type', $filters['module'] === 'accounts' ? 'account' : 'store');
                }
                if ($action !== 'all') {
                    $query->where('to_status', $action === 'suspended' ? 'suspended' : 'active');
                }
                $search = ['target_name_snapshot', 'actor_name_snapshot', 'reason'];
                break;
            case 'plan_version':
                if (! in_array($action, ['all', 'plan_version_recorded'], true)) {
                    return null;
                }
                $search = ['terms->name', 'actor_name_snapshot', 'change_note'];
                break;
            case 'subscription_activation':
                if (! in_array($action, ['all', 'activated', 'renewed'], true)) {
                    return null;
                }
                $query->whereIn('action', ['activate', 'renew']);
                if ($action !== 'all') {
                    $query->where('action', $action === 'activated' ? 'activate' : 'renew');
                }
                $search = ['snapshot->account_name', 'actor_name_snapshot', 'reason'];
                break;
        }

        if ($filters['q'] !== '') {
            $term = '%'.$filters['q'].'%';
            $query->where(function (Builder $searchQuery) use ($search, $term) {
                foreach ($search as $column) {
                    $searchQuery->orWhere($column, 'like', $term);
                }
            });
        }

        return $query;
    }

    private function hydrate(Collection $headers): Collection
    {
        $records = [];
        $selections = [
            'administrator_event' => [AdministratorEvent::class, ['id', 'administrator_user_id', 'administrator_name_snapshot', 'action', 'from_status', 'to_status', 'reason', 'actor_user_id', 'actor_name_snapshot', 'created_at']],
            'status_change' => [StatusChange::class, ['id', 'account_id', 'store_id', 'target_type', 'target_name_snapshot', 'from_status', 'to_status', 'reason', 'actor_user_id', 'actor_name_snapshot', 'created_at']],
            'plan_version' => [PlanVersion::class, ['id', 'plan_id', 'version', 'terms', 'change_note', 'actor_user_id', 'actor_name_snapshot', 'created_at']],
            'subscription_activation' => [SubscriptionActivation::class, ['id', 'account_id', 'action', 'months', 'snapshot', 'reason', 'actor_user_id', 'actor_name_snapshot', 'created_at']],
        ];

        foreach ($headers->groupBy('source') as $source => $sourceHeaders) {
            [$model, $columns] = $selections[$source];
            // At most one query per source, regardless of page size. No mutable user/account/plan joins.
            $records[$source] = $model::query()->select($columns)->whereIn('id', $sourceHeaders->pluck('id'))->get()->keyBy('id');
        }

        return $headers->map(fn (object $header) => ['source' => $header->source,
            'record' => $records[$header->source]->get($header->id)])
            ->filter(fn (array $entry) => $entry['record'] !== null)->values();
    }
}
