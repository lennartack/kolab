<?php

namespace App\Console\Commands\Wallet;

use App\Console\Command;

class MandateCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'wallet:mandate {wallet} {--disable}{--enable}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Show wallet auto-payment mandate information.';

    /**
     * Execute the console command.
     *
     * @return mixed
     */
    public function handle()
    {
        $wallet = $this->getWallet($this->argument('wallet'));

        if (!$wallet) {
            $this->error("Wallet not found.");
            return 1;
        }

        $mandate = $wallet->getMandate();

        if (!empty($mandate['id'])) {
            $disabled = $mandate['isDisabled'] ? 'Yes' : 'No';
            $status = 'invalid';

            if ($mandate['isPending']) {
                $status = 'pending';
            } elseif ($mandate['isValid']) {
                $status = 'valid';
            }

            if ($this->option('disable') && $disabled == 'No') {
                $wallet->setSetting('mandate_disabled', '1');
                $disabled = 'Yes';
            } elseif ($this->option('enable') && $disabled == 'Yes') {
                $wallet->setSetting('mandate_disabled', null);
                $disabled = 'No';
            }

            $this->info("Auto-payment: {$mandate['method']}");
            $this->info("    id: {$mandate['id']}");
            $this->info("    status: {$status}");
            $this->info("    amount: {$mandate['amount']} {$wallet->currency}");
            $this->info("    min-balance: {$mandate['balance']} {$wallet->currency}");
            $this->info("    disabled: {$disabled}");
        } else {
            $this->info("Auto-payment: none");
        }
    }
}
