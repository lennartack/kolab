<?php

namespace Database\Seeds;

use App\SharedFolder;
use App\User;
use Illuminate\Database\Seeder;

class SharedFolderSeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * @return void
     */
    public function run()
    {
        $john = User::where('email', 'john@kolab.org')->first();
        $wallet = $john->wallets()->first();

        SharedFolder::create([
                'name' => 'Library',
                'email' => 'folder-mail@kolab.org',
                'type' => 'mail',
        ])->assignToWallet($wallet);

        if (in_array('event', \config('app.shared_folder_types'))) {
            SharedFolder::create([
                    'name' => 'Calendar',
                    'email' => 'folder-event@kolab.org',
                    'type' => 'event',
            ])->assignToWallet($wallet);
        }

        if (in_array('contact', \config('app.shared_folder_types'))) {
            SharedFolder::create([
                    'name' => 'Contacts',
                    'email' => 'folder-contact@kolab.org',
                    'type' => 'contact',
            ])->assignToWallet($wallet);
        }
    }
}
