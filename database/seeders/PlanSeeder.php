<?php

namespace Database\Seeders;

use App\Models\Plan;
use App\Services\Admin\SavePlan;
use Illuminate\Database\Seeder;

class PlanSeeder extends Seeder
{
    public function run(): void
    {
        $plans = [
            ['code' => 'free', 'name' => 'Free', 'monthly_price_centavos' => 0, 'active_product_limit' => 100,
                'description' => 'Everyday essentials for your neighborhood store.', 'store_limit' => 1, 'staff_limit' => 0,
                'quota_scope' => 'store', 'catalog_status' => 'published', 'planned_features' => []],
            ['code' => 'basic', 'name' => 'Basic', 'monthly_price_centavos' => 4900, 'active_product_limit' => 500,
                'description' => 'More room for your growing tindahan.', 'store_limit' => 1, 'staff_limit' => 0,
                'quota_scope' => 'store', 'catalog_status' => 'published', 'planned_features' => []],
            ['code' => 'plus', 'name' => 'Plus', 'monthly_price_centavos' => 14900, 'active_product_limit' => 1000,
                'description' => 'For stores ready to work as a team.', 'store_limit' => 1, 'staff_limit' => 2,
                'quota_scope' => 'store', 'catalog_status' => 'coming_soon',
                'planned_features' => ['Staff access', 'Staff activity tracking', 'Advanced reports']],
            ['code' => 'pro', 'name' => 'Pro', 'monthly_price_centavos' => 24900, 'active_product_limit' => 3000,
                'description' => 'Bring your branches together in one workspace.', 'store_limit' => 3, 'staff_limit' => 5,
                'quota_scope' => 'account', 'catalog_status' => 'coming_soon',
                'planned_features' => ['Staff access', 'Multiple stores', 'Consolidated branch reports']],
        ];
        foreach ($plans as $index => $attributes) {
            $existing = Plan::where('code', $attributes['code'])->first();
            if ($existing && $existing->version > 0) {
                continue; // Reruns must preserve changes already managed through the catalog.
            }
            app(SavePlan::class)->handle([...$attributes, 'sort_order' => $index * 10, 'featured' => $attributes['code'] === 'basic',
                'ads_enabled' => false, 'is_active' => true, 'expected_version' => $existing?->version,
                'change_note' => 'Initial agreed launch catalog'], $existing);
        }
    }
}
