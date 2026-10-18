<?php

namespace App\Http\Resources\Admin;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ActivityEntryResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $source = $this->resource['source'];
        $record = $this->resource['record'];
        $details = [];

        switch ($source) {
            case 'administrator_event':
                $module = 'administrators';
                $action = $record->action === 'created' ? 'created' : ($record->to_status === 'suspended' ? 'suspended' : 'reactivated');
                $target = $this->target('administrator', $record->administrator_user_id, $record->administrator_name_snapshot);
                break;
            case 'status_change':
                $module = $record->target_type === 'account' ? 'accounts' : 'stores';
                $action = $record->to_status === 'suspended' ? 'suspended' : 'reactivated';
                $target = $this->target($record->target_type, $record->target_type === 'account' ? $record->account_id : $record->store_id, $record->target_name_snapshot);
                break;
            case 'plan_version':
                $module = 'plans';
                // A historical version can be a legacy baseline, not a creation or edit. Preserve that distinction.
                $action = 'plan_version_recorded';
                $target = $this->target('plan', $record->plan_id, $record->terms['name'] ?? null);
                $details = ['plan_version' => $record->version];
                break;
            case 'subscription_activation':
                $module = 'subscriptions';
                $action = $record->action === 'activate' ? 'activated' : 'renewed';
                $target = $this->target('account', $record->account_id, $record->snapshot['account_name'] ?? null);
                $target['path'] .= '/subscription';
                $details = ['months' => $record->months];
                $planName = $record->snapshot['terms']['name'] ?? null;
                if (is_string($planName) && trim($planName) !== '') {
                    $details['plan_name'] = $planName;
                }
                $version = $record->snapshot['plan_version'] ?? null;
                if (is_int($version) && $version > 0) {
                    $details['plan_version'] = $version;
                }
                break;
        }

        $actorId = $record->actor_user_id === null ? null : (int) $record->actor_user_id;
        $actorName = $record->actor_name_snapshot;
        $nameRecorded = is_string($actorName) && trim($actorName) !== '';
        $statusEvent = in_array($source, ['administrator_event', 'status_change'], true);

        // This explicit allowlist deliberately omits request keys/hashes, credentials,
        // payment references and the complete result/proposal/plan JSON snapshots.
        return ['id' => $source.':'.$record->id, 'source' => $source, 'source_id' => (int) $record->id,
            'module' => $module, 'action' => $action, 'occurred_at' => $record->created_at?->toIso8601String(),
            'actor' => ['id' => $actorId, 'name' => $nameRecorded ? $actorName : ($actorId === null ? 'System' : 'Administrator #'.$actorId),
                'name_recorded' => $nameRecorded],
            'target' => $target, 'reason' => $source === 'plan_version' ? $record->change_note : $record->reason,
            'changes' => ['from_status' => $statusEvent ? $record->from_status : null,
                'to_status' => $statusEvent ? $record->to_status : null],
            'details' => (object) $details];
    }

    private function target(string $type, int $id, mixed $name): array
    {
        $directory = match ($type) {
            'administrator' => 'administrators', 'account' => 'accounts', 'store' => 'stores', 'plan' => 'plans',
        };

        return ['type' => $type, 'id' => $id,
            'name' => is_string($name) && trim($name) !== '' ? $name : ucfirst($type).' #'.$id,
            'path' => '/'.$directory.'/'.$id];
    }
}
