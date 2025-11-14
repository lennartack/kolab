<?php

namespace Database\Seeds;

use App\Auth\SecondFactor;
use App\Domain;
use App\Entitlement;
use App\User;
use App\Sku;
use Carbon\Carbon;
use Illuminate\Database\Seeder;
use App\Wallet;

class UserSeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * @return void
     */
    public function run()
    {
        $domain = Domain::create(
            [
                'namespace' => 'kolab.org',
                'status' => Domain::STATUS_NEW
                    + Domain::STATUS_ACTIVE
                    + Domain::STATUS_CONFIRMED
                    + Domain::STATUS_VERIFIED,
                'type' => Domain::TYPE_EXTERNAL
            ]
        );

        $john = User::create(
            [
                'email' => 'john@kolab.org',
                'password' => \App\Utils::generatePassphrase()
            ]
        );

        $john->setSettings(
            [
                'first_name' => 'John',
                'last_name' => 'Doe',
                'currency' => 'USD',
                'country' => 'US',
                'billing_address' => "601 13th Street NW\nSuite 900 South\nWashington, DC 20005",
                'external_email' => 'john.doe.external@gmail.com',
                'organization' => 'Kolab Developers',
                'phone' => '+1 509-248-1111',
            ]
        );

        $john->setAliases(['john.doe@kolab.org']);

        $wallet = $john->wallets->first();

        $packageDomain = \App\Package::withEnvTenantContext()->where('title', 'domain-hosting')->first();
        $packageKolab = \App\Package::withEnvTenantContext()->where('title', 'kolab')->first();
        $packageLite = \App\Package::withEnvTenantContext()->where('title', 'lite')->first();

        $domain->assignPackage($packageDomain, $john);
        $john->assignPackage($packageKolab);


        $appDomain = \App\Domain::where(
            [
                'namespace' => \config('app.domain')
            ]
        )->first();

        $fred = User::create(
            [
                'email' => 'fred@' . \config('app.domain'),
                'password' => \App\Utils::generatePassphrase()
            ]
        );

        $fred->setSettings(
            [
                'first_name' => 'fred',
                'last_name' => 'Doe',
                'currency' => 'USD',
                'country' => 'US',
                'billing_address' => "601 13th Street NW\nSuite 900 South\nWashington, DC 20005",
                'external_email' => 'fred.doe.external@gmail.com',
                'organization' => 'Kolab Developers',
                'phone' => '+1 509-248-1111',
            ]
        );

        $appDomain->assignPackage($packageDomain, $fred);
        $fred->assignPackage($packageKolab);


        $jack = User::create(
            [
                'email' => 'jack@kolab.org',
                'password' => \App\Utils::generatePassphrase()
            ]
        );

        $jack->setSettings(
            [
                'first_name' => 'Jack',
                'last_name' => 'Daniels',
                'currency' => 'USD',
                'country' => 'US'
            ]
        );

        $jack->setAliases(['jack.daniels@kolab.org']);

        $john->assignPackage($packageKolab, $jack);

        foreach ($john->entitlements as $entitlement) {
            $entitlement->created_at = Carbon::now()->subMonthsWithoutOverflow(1);
            $entitlement->updated_at = Carbon::now()->subMonthsWithoutOverflow(1);
            $entitlement->save();
        }

        $ned = User::create(
            [
                'email' => 'ned@kolab.org',
                'password' => \App\Utils::generatePassphrase()
            ]
        );

        $ned->setSettings(
            [
                'first_name' => 'Edward',
                'last_name' => 'Flanders',
                'currency' => 'USD',
                'country' => 'US',
            ]
        );

        $john->assignPackage($packageKolab, $ned);

        $ned->assignSku(\App\Sku::withEnvTenantContext()->where('title', 'activesync')->first(), 1);

        // Ned is a controller on Jack's wallet
        $john->wallets()->first()->addController($ned);

        // Ned is also our 2FA test user
        $sku2fa = Sku::withEnvTenantContext()->where('title', '2fa')->first();

        $ned->assignSku($sku2fa);

        SecondFactor::seed('ned@kolab.org');

        $joe = User::create(
            [
                'email' => 'joe@kolab.org',
                'password' => \App\Utils::generatePassphrase()
            ]
        );

        $john->assignPackage($packageLite, $joe);

        //$john->assignSku(Sku::firstOrCreate(['title' => 'beta']));
        //$john->assignSku(Sku::firstOrCreate(['title' => 'meet']));

        $joe->setAliases(['joe.monster@kolab.org']);

        // This only exists so the user create job doesn't fail because the domain is not found
        Domain::create(
            [
                'namespace' => 'jeroen.jeroen',
                'status' => Domain::STATUS_NEW
                    + Domain::STATUS_ACTIVE
                    + Domain::STATUS_CONFIRMED
                    + Domain::STATUS_VERIFIED,
                'type' => Domain::TYPE_EXTERNAL
            ]
        );

        $jeroen = User::create(
            [
                'email' => 'jeroen@jeroen.jeroen',
                'password' => \App\Utils::generatePassphrase()
            ]
        );

        $jeroen->role = 'admin';
        $jeroen->save();

        $reseller = User::create(
            [
                'email' => 'reseller@' . \config('app.domain'),
                'password' => \App\Utils::generatePassphrase()
            ]
        );

        $reseller->role = 'reseller';
        $reseller->save();

        // for tenants that are not the configured tenant id
        $tenants = \App\Tenant::where('id', '!=', \config('app.tenant_id'))->get();

        foreach ($tenants as $tenant) {
            $domain = Domain::where('tenant_id', $tenant->id)->first();

            $packageKolab = \App\Package::where(
                [
                    'title' => 'kolab',
                    'tenant_id' => $tenant->id
                ]
            )->first();

            if ($domain) {
                $reseller = User::create(
                    [
                        'email' => 'reseller@' . $domain->namespace,
                        'password' => \App\Utils::generatePassphrase()
                    ]
                );

                $reseller->role = 'reseller';
                $reseller->tenant_id = $tenant->id;
                $reseller->save();

                $user = User::create(
                    [
                        'email' => 'user@' . $domain->namespace,
                        'password' => \App\Utils::generatePassphrase()
                    ]
                );

                $user->tenant_id = $tenant->id;
                $user->save();

                $user->assignPackage($packageKolab);
            }
        }
    }
}
