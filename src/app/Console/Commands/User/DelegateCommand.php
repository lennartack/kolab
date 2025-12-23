<?php

namespace App\Console\Commands\User;

use App\Console\Command;
use App\Delegation;
use Carbon\Carbon;

class DelegateCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'user:delegate {delegator} {delegatee}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Create delegation for user.';

    /**
     * Execute the console command.
     *
     * @return mixed
     */
    public function handle()
    {
        $delegator = $this->getUser($this->argument('delegator'));
        $delegatee = $this->getUser($this->argument('delegatee'));

        if (!$delegator || !$delegatee) {
            $this->error("User not found.");
            return 1;
        }

        $delegation = new Delegation();
        $delegation->user_id = $delegator->id;
        $delegation->delegatee_id = $delegatee->id;
        $delegation->save();
    }
}
