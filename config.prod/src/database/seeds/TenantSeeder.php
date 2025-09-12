<?php

namespace Database\Seeds;

use App\Tenant;
use Illuminate\Database\Seeder;

class TenantSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run()
    {
        if ($tenantId = \config('app.tenant_id')) {
            $tenant = Tenant::where(['id' => $tenantId])->first();
            if (!$tenant) {
                Tenant::create(['title' => 'Default Tenant', 'id' => $tenantId]);
            }
        }
    }
}
