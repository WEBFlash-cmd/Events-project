<?php

namespace App\Http\Controllers;

use App\Http\Requests\Purchase\IndexAdminPurchasePagesRequest;
use App\Services\AdminPurchaseService;

class AdminPurchasePageController extends Controller
{
    public function orders(IndexAdminPurchasePagesRequest $request, AdminPurchaseService $purchases)
    {
        $filters = $this->filters($request);
        $orders = $purchases->orders($filters);

        return response()->view('admin.purchases.orders', compact('orders', 'filters'))->header('Cache-Control', 'private, no-store');
    }

    public function tickets(IndexAdminPurchasePagesRequest $request, AdminPurchaseService $purchases)
    {
        $filters = $this->filters($request);
        $tickets = $purchases->tickets($filters);

        return response()->view('admin.purchases.tickets', compact('tickets', 'filters'))->header('Cache-Control', 'private, no-store');
    }

    private function filters(IndexAdminPurchasePagesRequest $request): array
    {
        // Preserve "0": it selects purchases that do not require a refund.
        return array_filter($request->validated(), fn ($value) => $value !== null && $value !== '');
    }
}
