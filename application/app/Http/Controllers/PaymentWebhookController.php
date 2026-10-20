<?php

namespace App\Http\Controllers;

use App\Http\Requests\Payment\WebhookRequest;
use App\Services\PaymentService;
use Illuminate\Http\JsonResponse;

class PaymentWebhookController extends Controller
{
    public function __invoke(WebhookRequest $request, PaymentService $payments): JsonResponse
    {
        $payments->processWebhook($request->validated());

        return response()->json(['success' => true]);
    }
}
