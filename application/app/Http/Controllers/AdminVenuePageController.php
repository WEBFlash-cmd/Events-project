<?php

namespace App\Http\Controllers;

use App\Http\Requests\Admin\IndexAdminCatalogsRequest;
use App\Http\Requests\Venue\StoreVenueRequest;
use App\Http\Requests\Venue\UpdateVenueRequest;
use App\Models\Venue;
use App\Services\VenueService;
use App\Http\Requests\ConfirmWebActionRequest;
use Symfony\Component\HttpKernel\Exception\HttpException;

class AdminVenuePageController extends Controller
{
    public function index(IndexAdminCatalogsRequest $request)
    {
        $filters = $request->validated();
        $search = $filters['search'] ?? '';
        $venues = Venue::query()->withCount('events')
            ->when($search !== '', fn ($query) => $query->where(fn ($query) =>
                $query->whereLike('name', '%'.$search.'%')->orWhereLike('address', '%'.$search.'%')))
            ->orderBy('name')->orderBy('id')->paginate(12)->withQueryString();

        return response()->view('admin.venues.index', compact('venues', 'filters'))->header('Cache-Control', 'private, no-store');
    }

    public function create()
    {
        return $this->form(new Venue());
    }

    public function store(StoreVenueRequest $request, VenueService $venues)
    {
        $venues->create($request->validated());

        return redirect()->route('admin.venues.index')->with('admin_success', 'Площадка создана.');
    }

    public function edit(Venue $venue)
    {
        return $this->form($venue);
    }

    public function update(UpdateVenueRequest $request, Venue $venue, VenueService $venues)
    {
        $venues->update($venue, $request->validated());

        return redirect()->route('admin.venues.index')->with('admin_success', 'Площадка обновлена.');
    }

    public function destroy(ConfirmWebActionRequest $request, Venue $venue, VenueService $venues)
    {
        try {
            $venues->delete($venue);
        } catch (HttpException $exception) {
            if ($exception->getStatusCode() !== 409) {
                throw $exception;
            }

            return redirect()->route('admin.venues.index')->with('admin_error',
                'Площадку нельзя удалить: она используется в мероприятиях.');
        }

        return redirect()->route('admin.venues.index')->with('admin_success', 'Площадка удалена.');
    }

    private function form(Venue $venue)
    {
        $locked = $venue->exists && $venue->events()->exists();

        return response()->view('admin.venues.form', compact('venue', 'locked'))->header('Cache-Control', 'private, no-store');
    }
}
