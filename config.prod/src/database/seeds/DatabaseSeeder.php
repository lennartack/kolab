<?php

use Database\Seeds;
use Illuminate\Database\Seeder;

// phpcs:ignore
class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run()
    {
        $this->call([
            Seeds\PowerDNSSeeder::class,
            Seeds\TenantSeeder::class,
            Seeds\AdminSeeder::class,
        ]);
    }
}
