<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Purchase\IndexAdminPurchasesRequest;
use App\Services\AdminPurchaseService;
use Illuminate\Http\JsonResponse;

class PurchaseController extends Controller
{
    public function __construct(private AdminPurchaseService $purchases)
    {
    }

    public function orders(IndexAdminPurchasesRequest $request): JsonResponse
    {
        return response()->json($this->purchases->orders($request->validated()));
    }

    public function tickets(IndexAdminPurchasesRequest $request): JsonResponse
    {
        return response()->json($this->purchases->tickets($request->validated()));
    }
}
