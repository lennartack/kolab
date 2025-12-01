<?php

namespace App\Console\Commands\DB;

use App\Fs\Lock;
use App\Policy\Greylist\Connect;
use App\Policy\Greylist\Whitelist;
use App\Policy\RateLimit;
use App\SignupCode;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class ExpungeCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'db:expunge';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Expunge old records from the database tables';

    /**
     * Execute the console command.
     *
     * @return mixed
     */
    public function handle()
    {
        Connect::where('updated_at', '<', Carbon::now()->subMonthsWithoutOverflow(6))
            ->delete();

        Whitelist::where('updated_at', '<', Carbon::now()->subMonthsWithoutOverflow(6))
            ->delete();

        RateLimit::where('updated_at', '<', Carbon::now()->subMonthsWithoutOverflow(6))
            ->delete();

        SignupCode::where('created_at', '<', Carbon::now()->subMonthsWithoutOverflow(6))
            ->forceDelete();

        DB::table('failed_jobs')->where('failed_at', '<', Carbon::now()->subMonthsWithoutOverflow(6))
            ->delete();

        // Remove expired filesystem locks
        Lock::where('timeout', '>=', '0')->whereRaw('created_at > now() - interval timeout second')
            ->delete();

        // TODO: What else? Should we force-delete deleted "dummy/spammer" accounts?
    }
}
