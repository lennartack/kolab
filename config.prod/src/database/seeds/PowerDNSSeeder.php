<?php

namespace Database\Seeds;

use Illuminate\Database\Seeder;

class PowerDNSSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run()
    {
        \App\PowerDNS\Domain::create(
            [
                'name' => '_woat.' . \config('app.domain'),
            ]
        );
    }
}
