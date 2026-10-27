<?php

namespace App\Http\Controllers;

use App\Models\Category;
use App\Models\Venue;
use Illuminate\View\View;

class CatalogController extends Controller
{
    public function __invoke(): View
    {
        return view('catalog', [
            'categories' => Category::query()->orderBy('name')->get(['id', 'name']),
            'venues' => Venue::query()->orderBy('name')->get(['id', 'name']),
        ]);
    }
}
