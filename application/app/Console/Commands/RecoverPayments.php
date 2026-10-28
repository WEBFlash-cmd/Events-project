<?php

namespace App\Console\Commands;

use App\Models\Payment;
use App\Services\PaymentService;
use Illuminate\Console\Command;
use Throwable;

class RecoverPayments extends Command
{
    protected $signature = 'payments:recover';
    protected $description = 'Resume abandoned payment initialization using the original reference';

    public function handle(PaymentService $payments): int
    {
        $failed = false;
        Payment::query()->whereIn('status', ['created', 'creating'])
            ->where('updated_at', '<=', now()->subSeconds($payments->recoveryDelay()))
            ->chunkById(100, function ($batch) use ($payments, &$failed) {
                foreach ($batch as $payment) {
                    try {
                        $payments->initialize($payment, recoverStalled: true);
                    } catch (Throwable $error) {
                        report($error);
                        $failed = true;
                    }
                }
            });

        $this->info('Abandoned payment attempts processed.');

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
