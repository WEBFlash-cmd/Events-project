<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Venue\StoreVenueRequest;
use App\Models\Venue;
use Illuminate\Http\JsonResponse;

class VenueController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json(
            Venue::query()->orderBy('name')->orderBy('id')->paginate(15)
        );
    }

    public function store(StoreVenueRequest $request): JsonResponse
    {
        $venue = Venue::create($request->validated());

        return response()->json(['data' => $venue], 201);
    }
}
