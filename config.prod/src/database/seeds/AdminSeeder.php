<?php

namespace Database\Seeds;

use App\Domain;
use App\Sku;
use App\Package;
use App\User;
use Illuminate\Database\Seeder;

class AdminSeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * Create a default user with dependencies
     */
    public function run()
    {
        $skus = [
            [
                'title' => 'mailbox',
                'name' => 'User Mailbox',
                'description' => 'Just a mailbox',
                'cost' => 0,
                'units_free' => 0,
                'period' => 'monthly',
                'handler_class' => 'App\Handlers\Mailbox',
                'active' => true,
            ],
            [
                'title' => 'domain',
                'name' => 'Hosted Domain',
                'description' => 'Somewhere to place a mailbox',
                'cost' => 0,
                'period' => 'monthly',
                'handler_class' => 'App\Handlers\Domain',
                'active' => false,
            ],
            [
                'title' => 'domain-hosting',
                'name' => 'External Domain',
                'description' => 'Host a domain that is externally registered',
                'cost' => 0,
                'units_free' => 1,
                'period' => 'monthly',
                'handler_class' => 'App\Handlers\DomainHosting',
                'active' => true,
            ],
            [
                'title' => 'storage',
                'name' => 'Storage Quota',
                'description' => 'Some wiggle room',
                'cost' => 0,
                'units_free' => 5,
                'period' => 'monthly',
                'handler_class' => 'App\Handlers\Storage',
                'active' => true,
            ],
            [
                'title' => 'groupware',
                'name' => 'Groupware Features',
                'description' => 'Groupware functions like Calendar, Tasks, Notes, etc.',
                'cost' => 0,
                'units_free' => 0,
                'period' => 'monthly',
                'handler_class' => 'App\Handlers\Groupware',
                'active' => true,
            ],
            [
                'title' => 'resource',
                'name' => 'Resource',
                'description' => 'Reservation taker',
                'cost' => 0,
                'period' => 'monthly',
                'handler_class' => 'App\Handlers\Resource',
                'active' => true,
            ],
            [
                'title' => 'shared-folder',
                'name' => 'Shared Folder',
                'description' => 'A shared folder',
                'cost' => 0,
                'period' => 'monthly',
                'handler_class' => 'App\Handlers\SharedFolder',
                'active' => true,
            ],
            [
                'title' => '2fa',
                'name' => '2-Factor Authentication',
                'description' => 'Two factor authentication for webmail and administration panel',
                'cost' => 0,
                'units_free' => 0,
                'period' => 'monthly',
                'handler_class' => 'App\Handlers\Auth2F',
                'active' => true,
            ],
            [
                'title' => 'activesync',
                'name' => 'Activesync',
                'description' => 'Mobile synchronization',
                'cost' => 0,
                'units_free' => 0,
                'period' => 'monthly',
                'handler_class' => 'App\Handlers\Activesync',
                'active' => true,
            ],
            [
                'title' => 'group',
                'name' => 'Distribution list',
                'description' => 'Mail distribution list',
                'cost' => 0,
                'units_free' => 0,
                'period' => 'monthly',
                'handler_class' => 'App\Handlers\Group',
                'active' => true,
            ],
            [
                'title' => 'group-room',
                'name' => 'Group conference room',
                'description' => 'Shareable audio & video conference room',
                'cost' => 0,
                'units_free' => 0,
                'period' => 'monthly',
                'handler_class' => 'App\Handlers\GroupRoom',
                'active' => true,
            ],
            [
                'title' => 'room',
                'name' => 'Standard conference room',
                'description' => 'Audio & video conference room',
                'cost' => 0,
                'units_free' => 0,
                'period' => 'monthly',
                'handler_class' => 'App\Handlers\Room',
                'active' => true,
            ],
        ];

        foreach ($skus as $sku) {
            // Check existence because migration might have added this already
            if (!Sku::where('title', $sku['title'])->where('tenant_id', \config('app.tenant_id'))->first()) {
                Sku::create($sku);
            }
        }

        $skuDomain = Sku::where(['title' => 'domain-hosting', 'tenant_id' => \config('app.tenant_id')])->first();
        $skuGroupware = Sku::where(['title' => 'groupware', 'tenant_id' => \config('app.tenant_id')])->first();
        $skuMailbox = Sku::where(['title' => 'mailbox', 'tenant_id' => \config('app.tenant_id')])->first();
        $skuStorage = Sku::where(['title' => 'storage', 'tenant_id' => \config('app.tenant_id')])->first();
        // $skuGroup = Sku::where(['title' => 'group', 'tenant_id' => \config('app.tenant_id')])->first();

        $userPackage = Package::create(
            [
                'title' => 'kolab',
                'name' => 'Groupware Account',
                'description' => 'A fully functional groupware account.',
                'discount_rate' => 0,
            ]
        );

        $userPackage->skus()->saveMany([
            $skuMailbox,
            $skuGroupware,
            $skuStorage
        ]);

        // This package contains 2 units of the storage SKU, which just so happens to also
        // be the number of SKU free units.
        $userPackage->skus()->updateExistingPivot(
            $skuStorage,
            ['qty' => 5],
            false
        );



        // Create admin user
        $admin = User::create(
            [
                'email' => 'admin@' . \config('app.domain'),
                'password' => \App\Utils::generatePassphrase(),
            ]
        );

        $admin->setSettings(
            [
                'first_name' => 'Admin',
            ]
        );
        $admin->role = 'admin';

        $admin->assignPackage($userPackage);

        // Create a default file collection
        $item = $admin->fsItems()->create(['type' => \App\Fs\Item::TYPE_COLLECTION]);
        $item->setProperties([
            'name' => "Files",
        ]);


        // Create primary domain
        $domain = Domain::create(
            [
                'namespace' => \config('app.domain'),
                'status' => DOMAIN::STATUS_CONFIRMED + Domain::STATUS_ACTIVE,
                'type' => Domain::TYPE_EXTERNAL,
            ]
        );

        $domainPackage = Package::create(
            [
                'title' => 'domain-hosting',
                'name' => 'Domain',
                'description' => 'Domain.',
                'discount_rate' => 0,
            ]
        );
        $domainPackage->skus()->saveMany([$skuDomain]);

        $domain->assignPackage($domainPackage, $admin);
    }
}
