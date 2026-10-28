<?php

namespace App\Services;

use App\Enums\EventStatus;
use App\Events\OrderPaid;
use App\Events\EventAvailabilityChanged;
use App\Events\TicketPurchased;
use App\Models\Event;
use App\Models\Order;
use App\Models\Payment;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class PaymentService
{
    public function __construct(
        private PaymentSignature $signature,
        private OrderReservationService $reservations,
    ) {
    }

    public function prepare(Order $order): Payment
    {
        abort_if((string) config('payments.secret') === '' || config('payments.cashbox_id') < 1
            || ! filter_var(config('payments.base_url'), FILTER_VALIDATE_URL),
            503, 'Payment system is not configured.');

        return $order->payment()->create([
            'reference' => (string) Str::uuid(),
            'cashbox_id' => config('payments.cashbox_id'),
            'amount' => (int) str_replace('.', '', $order->total_amount),
            'status' => 'created',
        ]);
    }

    public function initialize(Payment $payment, bool $recoverStalled = false): Payment
    {
        // Commit the reference before contacting the gateway: its webhook may arrive immediately.
        $claimed = DB::transaction(function () use ($payment, $recoverStalled) {
            $event = Event::query()->lockForUpdate()->findOrFail($payment->order->event_id);
            $order = Order::query()->lockForUpdate()->findOrFail($payment->order_id);
            $payment = Payment::query()->lockForUpdate()->findOrFail($payment->id);

            $stalled = $recoverStalled && $payment->status === 'creating'
                && $payment->updated_at->lessThanOrEqualTo(now()->subSeconds($this->recoveryDelay()));
            if ($payment->status !== 'created' && !$stalled) {
                return false;
            }

            if ($order->status !== 'pending' || $order->expires_at?->lessThanOrEqualTo(now())
                || $event->status !== EventStatus::PUBLISHED || $event->starts_at->lessThanOrEqualTo(now())) {
                $this->reservations->expire($order);
                if ($order->fresh()->status === 'pending') {
                    $this->reservations->cancel($order);
                }
                $payment->update(['status' => 'creation_failed', 'error_code' => 'order_closed']);

                return false;
            }

            // Reuse the reference: the gateway has a unique (cashbox_id, order_id) constraint.
            $payment->status = 'creating';
            $payment->updated_at = now();
            $payment->save();

            return true;
        }, 3);

        if (! $claimed) {
            return $payment->fresh();
        }

        $payload = [
            'cashbox_id' => $payment->cashbox_id,
            'order_id' => $payment->reference,
            'description' => "Order #{$payment->order_id}",
            'amount' => $payment->amount,
        ];
        $url = null;
        $error = null;
        $status = 'unknown';

        try {
            $response = Http::acceptJson()
                ->connectTimeout(5)
                ->timeout(config('payments.timeout'))
                ->withHeaders(['X-Signature' => $this->signature->sign($payload, config('payments.secret'))])
                ->post(rtrim(config('payments.base_url'), '/').'/api/create-payment', $payload);

            $url = $response->json('data.url');
            if ($response->successful() && is_string($url)
                && filter_var($url, FILTER_VALIDATE_URL)
                && in_array(parse_url($url, PHP_URL_SCHEME), ['http', 'https'], true)) {
                $status = 'pending';
            } else {
                $url = null;
                // A timeout/5xx/duplicate response may hide a successfully created remote payment.
                $status = $response->clientError() && $response->status() !== 422 ? 'creation_failed' : 'unknown';
                $error = 'gateway_response_invalid';
            }
        } catch (ConnectionException) {
            $error = 'gateway_unavailable';
        }

        return DB::transaction(function () use ($payment, $status, $url, $error) {
            $payment = Payment::query()->lockForUpdate()->findOrFail($payment->id);
            if ($payment->status === 'creating') {
                $payment->status = $status;
                $payment->error_code = $error;
            }
            // Never overwrite a terminal status saved by a webhook while HTTP was in flight.
            if ($url !== null) {
                $payment->payment_url = $url;
            }
            $payment->save();

            return $payment;
        }, 3);
    }

    public function recoveryDelay(): int
    {
        // Never reclaim an attempt that may still be within the configured HTTP timeout.
        return max(120, (int) config('payments.recovery_after_seconds', 120),
            (int) config('payments.timeout') + 60);
    }

    public function processWebhook(array $data): void
    {
        $payment = Payment::query()->where('reference', $data['order_id'])->firstOrFail();

        DB::transaction(function () use ($payment, $data) {
            $event = Event::query()->lockForUpdate()->findOrFail($payment->order->event_id);
            $order = Order::query()->lockForUpdate()->findOrFail($payment->order_id);
            $payment = Payment::query()->lockForUpdate()->findOrFail($payment->id);

            abort_unless($payment->cashbox_id === config('payments.cashbox_id'), 422, 'Cashbox mismatch.');
            abort_unless($payment->amount === (int) $data['amount']
                && $payment->amount === (int) str_replace('.', '', $order->total_amount),
                422, 'Payment amount mismatch.');

            if (in_array($payment->status, ['paid', 'refund_required', 'failed'], true)) {
                $previousStatus = $payment->status === 'failed' ? 2 : 1;
                abort_unless($previousStatus === (int) $data['status'], 409, 'Conflicting payment notification.');

                return;
            }

            if ((int) $data['status'] === 2) {
                $payment->update(['status' => 'failed', 'error_code' => null]);
                if ($order->status === 'pending') {
                    $this->reservations->cancel($order);
                }

                return;
            }

            $this->reservations->expire($order);
            $order->refresh();
            if ($order->status === 'pending'
                && ($event->status !== EventStatus::PUBLISHED || $event->starts_at->lessThanOrEqualTo(now()))) {
                $this->reservations->cancel($order);
                $order->refresh();
            }

            if ($order->status !== 'pending') {
                $payment->update([
                    'status' => 'refund_required', 'refund_required' => true,
                    'refund_amount' => $payment->amount,
                    'paid_at' => now(), 'error_code' => null,
                ]);

                return;
            }

            $order->update(['status' => 'paid']);
            $payment->update(['status' => 'paid', 'paid_at' => now(), 'error_code' => null]);

            foreach ($order->items as $item) {
                for ($sequence = 1; $sequence <= $item->quantity; $sequence++) {
                    $ticket = $order->tickets()->create([
                        'number' => (string) Str::uuid(),
                        'user_id' => $order->user_id,
                        'event_id' => $order->event_id,
                        'ticket_type_id' => $item->ticket_type_id,
                        'order_item_id' => $item->id,
                        'sequence' => $sequence,
                        'status' => 'active',
                    ]);
                    TicketPurchased::dispatch($ticket);
                }
            }

            EventAvailabilityChanged::dispatch($event);
            OrderPaid::dispatch($order);
        }, 3);
    }
}
