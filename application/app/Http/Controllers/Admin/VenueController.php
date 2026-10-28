<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Venue\StoreVenueRequest;
use App\Http\Requests\Venue\UpdateVenueRequest;
use App\Models\Venue;
use App\Services\VenueService;
use Illuminate\Http\JsonResponse;

class VenueController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json(
            Venue::query()->orderBy('name')->orderBy('id')->paginate(15)
        );
    }

    public function store(StoreVenueRequest $request, VenueService $venues): JsonResponse
    {
        $venue = $venues->create($request->validated());

        return response()->json(['data' => $venue], 201);
    }

    public function update(UpdateVenueRequest $request, Venue $venue, VenueService $venues): JsonResponse
    {
        return response()->json(['data' => $venues->update($venue, $request->validated())]);
    }

    public function destroy(Venue $venue, VenueService $venues): JsonResponse
    {
        $venues->delete($venue);

        return response()->json(null, 204);
    }
}
