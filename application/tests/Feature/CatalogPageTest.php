<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Venue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CatalogPageTest extends TestCase
{
    use RefreshDatabase;

    public function testGuestCanOpenCatalogWithFilterOptions(): void
    {
        $category = Category::create(['name' => 'Конференции', 'description' => 'Встречи']);
        $venue = Venue::create(['name' => 'Большой зал', 'address' => 'Центр', 'capacity' => 100]);

        $this->withoutVite()->get('/')
            ->assertOk()
            ->assertViewIs('catalog')
            ->assertViewHas('categories', fn ($items) => $items->contains('id', $category->id))
            ->assertViewHas('venues', fn ($items) => $items->contains('id', $venue->id))
            ->assertSee('Афиша мероприятий')
            ->assertSee('Конференции')
            ->assertSee('Большой зал');
    }

    public function testCatalogRendersWithoutCategoriesOrVenuesAndEscapesNames(): void
    {
        $this->withoutVite()->get('/')->assertOk()->assertSee('Все категории');

        Category::create(['name' => '<script>alert(1)</script>']);
        $this->get('/')
            ->assertOk()
            ->assertSee('&lt;script&gt;alert(1)&lt;/script&gt;', false)
            ->assertDontSee('<script>alert(1)</script>', false);
    }
}
