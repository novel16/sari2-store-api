<?php

namespace App\Services\Auth;

use App\Models\Account;
use App\Models\AccountMembership;
use App\Models\Plan;
use App\Models\Store;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class RegisterStoreOwner
{
    public function handle(array $attributes): User
    {
        return DB::transaction(function () use ($attributes): User {
            $user = User::create([
                'name' => $attributes['name'],
                'email' => $attributes['email'],
                'password' => Hash::make($attributes['password']),
            ]);

            $account = Account::create([
                'name' => $attributes['store_name'],
                'owner_user_id' => $user->id,
                'status' => 'active',
            ]);

            AccountMembership::create([
                'account_id' => $account->id,
                'user_id' => $user->id,
                'role' => 'owner',
                'status' => 'active',
            ]);

            Store::create([
                'account_id' => $account->id,
                'name' => $attributes['store_name'],
                'slug' => Str::slug($attributes['store_name']) ?: 'store',
                'status' => 'active',
            ]);

            $plan = Plan::firstOrCreate(['code' => 'free'], [
                'name' => 'Free',
                'monthly_price_centavos' => 0,
                'active_product_limit' => config('saas.free_product_limit', 100),
                'store_limit' => 1,
                'staff_limit' => 0,
                'ads_enabled' => false,
                'is_active' => true,
                'catalog_status' => 'published', 'quota_scope' => 'store',
            ]);

            Subscription::create([
                'account_id' => $account->id,
                'plan_id' => $plan->id,
                'status' => 'active',
                'starts_at' => now(),
            ]);

            return $user->refresh();
        });
    }
}
