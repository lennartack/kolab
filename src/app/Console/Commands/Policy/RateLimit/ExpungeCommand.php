<?php

namespace App\Console\Commands\Policy\RateLimit;

use App\Policy\RateLimit;
use Carbon\Carbon;
use Illuminate\Console\Command;

class ExpungeCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'policy:ratelimit:expunge';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Expunge records from the policy ratelimit table';

    /**
     * Execute the console command.
     *
     * @return mixed
     */
    public function handle()
    {
        $months = config('policy.ratelimit.retention_months');
        RateLimit::where('updated_at', '<', Carbon::now()->subMonthsWithoutOverflow($months))->delete();
    }
}
