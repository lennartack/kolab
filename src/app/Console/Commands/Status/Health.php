<?php

namespace App\Console\Commands\Status;

use App\Http\Controllers\API\AuthController;
use App\Meet\Service;
use App\Providers\Payment\Mollie;
use App\Support\Facades\DAV;
use App\Support\Facades\IMAP;
use App\Support\Facades\LDAP;
use App\Support\Facades\OpenExchangeRates;
use App\Support\Facades\Roundcube;
use App\Support\Facades\Storage;
use App\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;

// TODO stripe
// TODO firebase

class Health extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'status:health
        {--check=* : One of DB, Redis, IMAP, LDAP, Roundcube, Meet, DAV, Mollie, OpenExchangeRates, Storage, Auth, SMTP}
        {--user= : Test user (for Auth test)}
        {--password= : Password of test user}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Check health of backends';

    private function checkDB()
    {
        try {
            DB::select("SELECT 1");
            return true;
        } catch (\Exception $exception) {
            $this->line($exception);
            return false;
        }
    }

    private function checkOpenExchangeRates()
    {
        try {
            OpenExchangeRates::healthcheck();
            return true;
        } catch (\Exception $exception) {
            $this->line($exception);
            return false;
        }
    }

    private function checkMollie()
    {
        try {
            return Mollie::healthcheck();
        } catch (\Exception $exception) {
            $this->line($exception);
            return false;
        }
    }

    private function checkDAV()
    {
        try {
            return DAV::healthcheck($this->option('user'), $this->option('password'));
        } catch (\Exception $exception) {
            $this->line($exception);
            return false;
        }
    }

    private function checkLDAP()
    {
        try {
            LDAP::healthcheck();
            return true;
        } catch (\Exception $exception) {
            $this->line($exception);
            return false;
        }
    }

    private function checkIMAP()
    {
        try {
            IMAP::healthcheck();
            return true;
        } catch (\Exception $exception) {
            $this->line($exception);
            return false;
        }
    }

    private function checkSMTP()
    {
        try {
            \App\Mail\Helper::sendMail(
                new \App\Mail\Test(),
                null,
                ["to" => [$this->option('user')]]
            );
            return true;
        } catch (\Exception $exception) {
            $this->line($exception);
            return false;
        }
    }

    private function checkAuth()
    {
        try {
            $user = User::findByEmail($this->option('user'));
            $response = AuthController::logonResponse($user, $this->option('password'));
            return $response->response()->getData()->status == 'success';
        } catch (\Exception $exception) {
            $this->line($exception);
            return false;
        }
    }

    private function checkRoundcube()
    {
        try {
            Roundcube::healthcheck();
            return true;
        } catch (\Exception $exception) {
            $this->line($exception);
            return false;
        }
    }

    private function checkRedis()
    {
        try {
            Redis::connection();
            return true;
        } catch (\Exception $exception) {
            $this->line($exception);
            return false;
        }
    }

    private function checkStorage()
    {
        try {
            Storage::healthcheck();
            return true;
        } catch (\Exception $exception) {
            $this->line($exception);
            return false;
        }
    }

    private function checkMeet()
    {
        $urls = \config('meet.api_urls');
        $success = true;

        foreach ($urls as $url) {
            $this->line("Checking {$url}");

            try {
                $response = Service::client($url)->get('ping');
                if (!$response->ok()) {
                    $success = false;
                    $this->line("Backend {$url} not available. Status: " . $response->status());
                }
            } catch (\Exception $exception) {
                $success = false;
                $this->line("Backend {$url} not available. Error: " . $exception->getMessage());
            }
        }

        return $success;
    }

    /**
     * Execute the console command.
     *
     * @return mixed
     */
    public function handle()
    {
        $result = 0;
        $steps = $this->option('check');
        if (empty($steps)) {
            $steps = [
                'DB', 'Redis', 'Roundcube', 'Meet',
            ];
            if (!empty($this->option('user'))) {
                array_unshift($steps, 'Auth');
                array_unshift($steps, 'DAV');
                array_unshift($steps, 'SMTP');
            }
            if (\config('app.with_ldap')) {
                array_unshift($steps, 'LDAP');
            }
            if (\config('app.with_imap')) {
                array_unshift($steps, 'IMAP');
            }
            if (\config('app.with_files')) {
                array_unshift($steps, 'Storage');
            }
            if (\config('services.mollie.key')) {
                array_unshift($steps, 'Mollie');
            }
            if (\config('services.openexchangerates.api_key')) {
                array_unshift($steps, 'OpenExchangeRates');
            }
        }

        foreach ($steps as $step) {
            $func = "check{$step}";

            $this->line("Checking {$step}...");

            if ($this->{$func}()) {
                $this->info("OK");
            } else {
                $this->error("Error while checking: {$step}");
                $result = 1;
            }
        }

        return $result;
    }
}
