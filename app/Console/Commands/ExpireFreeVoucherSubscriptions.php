<?php

namespace App\Console\Commands;

use App\Services\PendingSubscriptionVoucher;
use Illuminate\Console\Command;

class ExpireFreeVoucherSubscriptions extends Command
{
    protected $signature = 'fokus:expire-free-voucher-subscriptions';
    protected $description = 'Suspende assinaturas quando termina o benefício do voucher gratuito.';

    public function handle(PendingSubscriptionVoucher $vouchers): int
    {
        $this->info(sprintf('Assinaturas suspensas: %d', $vouchers->suspendExpired()));
        return self::SUCCESS;
    }
}
