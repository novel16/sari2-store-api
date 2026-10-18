<?php

namespace App\Services\Stores;

use App\Models\Account;
use App\Models\Store;
use Illuminate\Support\Facades\DB;
use LogicException;

class StoreWriteAccess
{
    public static function lock(Store $store): Store
    {
        if (DB::transactionLevel() === 0) {
            throw new LogicException('Store write access must be checked inside its write transaction.');
        }

        // Status changes use the same account -> store order. A request admitted
        // by middleware must recheck current availability after waiting for them.
        $account = Account::whereKey($store->account_id)->lockForUpdate()->firstOrFail();
        $locked = Store::whereKey($store->id)->where('account_id', $account->id)->lockForUpdate()->firstOrFail();

        abort_unless($account->status === 'active' && $locked->status === 'active', 403,
            'This store workspace is unavailable. Please contact support.');

        return $locked->setRelation('account', $account);
    }
}
