<?php

namespace App\Console\Commands\Data;

use App\Console\Command;
use App\User;
use Laravel\Passport\Passport;

class InitCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'data:init';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Initialization for some expected db entries. Rerunnable to apply latest config changes.';

    /**
     * Execute the console command.
     *
     * @return mixed
     */
    public function handle()
    {
        $this->createImapAdmin();
        $this->createNoreplyUser();
        $this->createPassportClients();
    }

    private function createImapAdmin()
    {
        $user = User::where(['email' => \config('services.imap.admin_login')])->first();
        if (!$user) {
            $user = new User();
            $user->email = \config('services.imap.admin_login');
            $user->password = \config('services.imap.admin_password');
            $user->role = User::ROLE_SERVICE;
        } else {
            $user->password = \config('services.imap.admin_password');
            $user->role = User::ROLE_SERVICE;
        }
        $user->save();
    }

    private function createNoreplyUser()
    {
        if (!empty(\config('mail.mailers.smtp.username'))) {
            $user = User::where(['email' => \config('mail.mailers.smtp.username')])->first();
            if (!$user) {
                $user = new User();
                $user->email = \config('mail.mailers.smtp.username');
                $user->password = \config('mail.mailers.smtp.password');
                $user->role = User::ROLE_SERVICE;
            } else {
                $user->password = \config('mail.mailers.smtp.password');
                $user->role = User::ROLE_SERVICE;
            }
            $user->save();
        }
    }

    /**
     * Execute the console command.
     *
     * @return mixed
     */
    private function createPassportClients()
    {
        $domain = \config('app.website_domain');

        // Create a password grant client for the webapp
        if (
            !empty(\config('auth.proxy.client_secret'))
            && !Passport::client()->where('id', \config('auth.proxy.client_id'))->exists()
        ) {
            $client = Passport::client()->forceFill([
                'user_id' => null,
                'name' => "Kolab Password Grant Client",
                'secret' => \config('auth.proxy.client_secret'),
                'provider' => 'users',
                'redirect' => "https://{$domain}",
                'personal_access_client' => 0,
                'password_client' => 1,
                'revoked' => false,
            ]);
            $client->id = \config('auth.proxy.client_id');
            $client->save();
        }

        // Create a client for Webmail SSO
        if (
            !empty(\config('auth.sso.client_secret'))
            && !Passport::client()->where('id', \config('auth.sso.client_id'))->exists()
        ) {
            $client = Passport::client()->forceFill([
                'user_id' => null,
                'name' => 'Webmail SSO client',
                'secret' => \config('auth.sso.client_secret'),
                'provider' => 'users',
                'redirect' => (str_starts_with(\config('app.webmail_url'), 'http') ? '' : 'https://' . $domain)
                    . \config('app.webmail_url') . 'index.php/login/oauth',
                'personal_access_client' => 0,
                'password_client' => 0,
                'revoked' => false,
                'allowed_scopes' => ['email', 'auth.token'],
            ]);
            $client->id = \config('auth.sso.client_id');
            $client->save();
        }

        // Create a client for synapse oauth
        if (
            !empty(\config('auth.synapse.client_secret'))
            && !Passport::client()->where('id', \config('auth.synapse.client_id'))->exists()
        ) {
            $client = Passport::client()->forceFill([
                'user_id' => null,
                'name' => "Synapse oauth client",
                'secret' => \config('auth.synapse.client_secret'),
                'provider' => 'users',
                'redirect' => "https://{$domain}/_synapse/client/oidc/callback",
                'personal_access_client' => 0,
                'password_client' => 0,
                'revoked' => false,
                'allowed_scopes' => ['email'],
            ]);
            $client->id = \config('auth.synapse.client_id');
            $client->save();
        }

        // Inject extra passport clients
        if (!empty(\config('auth.extra_passport_clients'))) {
            foreach (\config('auth.extra_passport_clients') as $clientConfig) {
                if (!Passport::client()->where('id', $clientConfig['id'])->exists()) {
                    \Log::info("Creating client " . $clientConfig['id']);
                    $client = Passport::client()->forceFill([
                        'user_id' => null,
                        'name' => $clientConfig['name'],
                        'secret' => $clientConfig['secret'],
                        'provider' => $clientConfig['provider'],
                        'redirect' => $clientConfig['redirect'],
                        'personal_access_client' => $clientConfig['personal_access_client'],
                        'password_client' => $clientConfig['password_client'],
                        'revoked' => $clientConfig['revoked'],
                        'allowed_scopes' => $clientConfig['allowed_scopes'],
                    ]);
                    $client->id = $clientConfig['id'];
                    $client->save();
                }
            }
        }
    }
}
