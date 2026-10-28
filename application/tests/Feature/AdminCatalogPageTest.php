<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Category;
use App\Models\Permission;
use App\Models\User;
use App\Models\Venue;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\CreatesOrderContext;
use Tests\TestCase;

class AdminCatalogPageTest extends TestCase
{
    use RefreshDatabase, CreatesOrderContext;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    private function admin(): User
    {
        return User::factory()->create(['role' => UserRole::ADMIN]);
    }

    private function venue(array $attributes = []): Venue
    {
        return Venue::create(array_merge(['name' => 'Test Hall', 'address' => 'Tashkent', 'capacity' => 100], $attributes));
    }

    public static function catalogs(): array
    {
        return ['categories' => ['categories'], 'venues' => ['venues']];
    }

    #[DataProvider('catalogs')]
    public function testGuestsAndParticipantsCannotAccessCatalogs(string $catalog): void
    {
        $item = $catalog === 'categories' ? Category::factory()->create() : $this->venue();
        $base = '/admin/'.$catalog;
        $this->get($base)->assertRedirect('/login');
        $this->post($base, [])->assertRedirect('/login');
        $this->actingAs(User::factory()->create());
        $this->get($base)->assertForbidden();
        $this->get($base.'/create')->assertForbidden();
        $this->get($base.'/'.$item->id.'/edit')->assertForbidden();
        $this->post($base, ['name' => 'Forbidden'])->assertForbidden();
        $this->patch($base.'/'.$item->id, ['name' => 'Forbidden'])->assertForbidden();
        $this->delete($base.'/'.$item->id, ['confirm_delete' => 1])->assertForbidden();
        $this->assertDatabaseHas($catalog, ['id' => $item->id, 'name' => $item->name]);
    }

    public function testOrganizerCanOnlyReadVenues(): void
    {
        $venue = $this->venue();
        $this->actingAs(User::factory()->create(['role' => UserRole::ORGANIZER]));
        $this->get('/admin/venues')->assertOk()->assertSee('Test Hall')->assertDontSee('Добавить площадку')
            ->assertDontSee('Редактировать')->assertDontSee('Удалить площадку');
        $this->get('/admin/categories')->assertForbidden();
        $this->get('/admin/venues/create')->assertForbidden();
        $this->get('/admin/venues/'.$venue->id.'/edit')->assertForbidden();
        $this->post('/admin/venues', [])->assertForbidden();
        $this->patch('/admin/venues/'.$venue->id, ['name' => 'Forbidden'])->assertForbidden();
        $this->delete('/admin/venues/'.$venue->id, ['confirm_delete' => 1])->assertForbidden();
    }

    #[DataProvider('catalogs')]
    public function testReadPermissionDoesNotGrantCreationEditingOrDeletion(string $catalog): void
    {
        $admin = $this->admin();
        $item = $catalog === 'categories' ? Category::factory()->create() : $this->venue();
        $admin->rbacRole->permissions()->detach(Permission::whereIn('name', [
            $catalog.'.create', $catalog.'.update', $catalog.'.delete',
        ])->pluck('id'));
        $base = '/admin/'.$catalog;
        $this->actingAs($admin)->get($base)->assertOk()->assertDontSee('Редактировать')
            ->assertDontSee('name="confirm_delete"', false)->assertDontSee('href="'.url($base.'/create').'"', false);
        $this->get($base.'/create')->assertForbidden();
        $this->get($base.'/'.$item->id.'/edit')->assertForbidden();
        $this->post($base, [])->assertForbidden();
        $this->patch($base.'/'.$item->id, ['name' => 'Forbidden'])->assertForbidden();
        $this->delete($base.'/'.$item->id, ['confirm_delete' => 1])->assertForbidden();
        $admin->rbacRole->permissions()->detach(Permission::where('name', $catalog.'.view')->value('id'));
        $this->get($base)->assertForbidden();
    }

    public function testAdminCanCreateAndUpdateCategoryAndExtraFieldsAreIgnored(): void
    {
        $this->actingAs($this->admin())->get('/admin/categories/create')->assertOk()->assertSee('Новая категория');
        $this->post('/admin/categories', ['name' => 'Выставки', 'description' => 'Работы художников', 'id' => 999999])
            ->assertRedirect(route('admin.categories.index'))->assertSessionHas('admin_success');
        $category = Category::where('name', 'Выставки')->firstOrFail();
        $this->assertNotEquals(999999, $category->id);
        $this->get('/admin/categories/'.$category->id.'/edit')->assertOk()->assertSee('Работы художников');
        $this->patch('/admin/categories/'.$category->id, ['name' => 'Выставки', 'description' => null])
            ->assertRedirect(route('admin.categories.index'));
        $this->assertNull($category->fresh()->description);
        $this->get('/admin/users')->assertOk()->assertSee(route('admin.categories.index'))->assertSee(route('admin.venues.index'));
    }

    public function testCategoryValidationKeepsInputAndRejectsDuplicateNames(): void
    {
        $category = Category::factory()->create(['name' => 'Existing']);
        $other = Category::factory()->create(['name' => 'Another']);
        $this->actingAs($this->admin())->from('/admin/categories/create')->followingRedirects()
            ->post('/admin/categories', ['name' => 'Existing', 'description' => 'Сохранённый текст'])
            ->assertOk()->assertSee('Категория с таким названием уже существует.')->assertSee('Сохранённый текст');
        $this->patch('/admin/categories/'.$other->id, ['name' => $category->name])->assertSessionHasErrors('name');
        $this->assertSame('Another', $other->fresh()->name);
        $this->post('/admin/categories', ['name' => '', 'description' => []])->assertSessionHasErrors('name');
    }

    public function testAdminCanCreateAndEditUnusedVenue(): void
    {
        $this->actingAs($this->admin())->get('/admin/venues/create')->assertOk()->assertSee('Новая площадка');
        $this->post('/admin/venues', ['name' => 'New Hall', 'address' => 'First Street', 'capacity' => 80, 'id' => 999999])
            ->assertRedirect(route('admin.venues.index'));
        $venue = Venue::where('name', 'New Hall')->firstOrFail();
        $this->assertNotEquals(999999, $venue->id);
        $this->get('/admin/venues/'.$venue->id.'/edit')->assertOk()->assertViewHas('locked', false);
        $this->patch('/admin/venues/'.$venue->id, ['name' => 'Updated Hall', 'address' => 'Second Street', 'capacity' => 60, 'description' => 'Description'])
            ->assertRedirect(route('admin.venues.index'));
        $this->assertDatabaseHas('venues', ['id' => $venue->id, 'name' => 'Updated Hall', 'address' => 'Second Street', 'capacity' => 60]);
    }

    public function testVenueValidationKeepsInputAndRejectsInvalidCapacity(): void
    {
        $this->actingAs($this->admin())->from('/admin/venues/create')->followingRedirects()
            ->post('/admin/venues', ['name' => 'Entered Hall', 'address' => 'Entered Address', 'capacity' => 0])
            ->assertOk()->assertSee('Вместимость должна быть не меньше 1.')->assertSee('Entered Hall')->assertSee('Entered Address');
        foreach ([-1, '1.5', 2147483648, 'abc'] as $capacity) {
            $this->post('/admin/venues', ['name' => 'Invalid', 'address' => 'Address', 'capacity' => $capacity])
                ->assertSessionHasErrors('capacity');
        }
        $this->assertDatabaseCount('venues', 0);
    }

    #[DataProvider('catalogs')]
    public function testDeleteRequiresConfirmationAndDeletesOnlySelectedUnusedItem(string $catalog): void
    {
        $item = $catalog === 'categories' ? Category::factory()->create() : $this->venue();
        $other = $catalog === 'categories' ? Category::factory()->create() : $this->venue();
        $base = '/admin/'.$catalog.'/'.$item->id;
        $this->actingAs($this->admin())->delete($base)->assertSessionHasErrors('confirm_delete');
        $this->delete($base, ['confirm_delete' => 0])->assertSessionHasErrors('confirm_delete');
        $this->assertDatabaseHas($catalog, ['id' => $item->id]);
        $this->delete($base, ['confirm_delete' => 1])->assertRedirect(route('admin.'.$catalog.'.index'))->assertSessionHas('admin_success');
        $this->assertDatabaseMissing($catalog, ['id' => $item->id]);
        $this->assertDatabaseHas($catalog, ['id' => $other->id]);
        $this->delete($base, ['confirm_delete' => 1])->assertNotFound();
    }

    public function testLinkedCatalogItemsCannotBeDeletedAndVenueDetailsAreProtected(): void
    {
        [, $event, $type] = $this->createOrderContext();
        $venue = $event->venue;
        $this->actingAs($this->admin());
        foreach (['categories' => $event->category, 'venues' => $venue] as $catalog => $item) {
            $this->get('/admin/'.$catalog)->assertOk()->assertSee('Удаление недоступно:');
            $this->delete('/admin/'.$catalog.'/'.$item->id, ['confirm_delete' => 1])->assertRedirect()->assertSessionHas('admin_error');
            $this->assertDatabaseHas($catalog, ['id' => $item->id]);
        }
        $this->get('/admin/venues/'.$venue->id.'/edit')->assertOk()->assertViewHas('locked', true)->assertSee('Адрес и вместимость менять нельзя');
        $this->patch('/admin/venues/'.$venue->id, ['name' => 'Must roll back', 'address' => 'Different', 'capacity' => 1])
            ->assertSessionHasErrors(['address', 'capacity']);
        $this->assertSame($venue->name, $venue->fresh()->name);
        $this->assertSame(300, $venue->fresh()->capacity);
        $this->assertSame(10, $type->fresh()->quantity);
        $this->patch('/admin/venues/'.$venue->id, ['name' => 'New Name', 'description' => 'New Description'])
            ->assertRedirect(route('admin.venues.index'));
        $this->assertSame('New Name', $venue->fresh()->name);
        $this->assertSame($venue->id, $event->fresh()->venue_id);
    }

    #[DataProvider('catalogs')]
    public function testListsSearchSortPaginateAndEscapeText(string $catalog): void
    {
        foreach (range(13, 1) as $number) {
            $attributes = ['name' => sprintf('Search Hall %02d', $number)];
            $catalog === 'categories' ? Category::factory()->create($attributes) : $this->venue($attributes);
        }
        $dangerous = '<script>alert(1)</script>';
        $catalog === 'categories' ? Category::factory()->create(['name' => $dangerous]) : $this->venue(['name' => $dangerous]);
        $this->actingAs($this->admin())->get('/admin/'.$catalog.'?search=SEARCH')->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertViewHas($catalog, fn ($items) => $items->total() === 13 && $items->count() === 12 && $items->first()->name === 'Search Hall 01')
            ->assertSee('search=SEARCH&amp;page=2', false);
        $this->get('/admin/'.$catalog.'?search=SEARCH&page=2')->assertOk()->assertSee('Search Hall 13')->assertDontSee('Search Hall 01');
        $this->get('/admin/'.$catalog.'?search=0')->assertOk()->assertViewHas($catalog, fn ($items) => $items->total() === 10);
        $this->get('/admin/'.$catalog.'?search=unmatched')->assertOk()->assertViewHas($catalog, fn ($items) => $items->total() === 0);
        $this->get('/admin/'.$catalog)->assertOk()->assertSee(e($dangerous), false)->assertDontSee($dangerous, false);
        if ($catalog === 'venues') {
            $this->get('/admin/venues?search=TASHKENT')->assertOk()->assertViewHas('venues', fn ($items) => $items->total() === 14);
        }
    }

    #[DataProvider('catalogs')]
    public function testMalformedFiltersAndFormArraysDoNotBreakPages(string $catalog): void
    {
        $this->actingAs($this->admin());
        $base = '/admin/'.$catalog;
        $this->followingRedirects()->get($base.'?search[]=bad')->assertOk()->assertSee('Поисковый запрос должен быть текстом.');
        $this->from($base.'/create')->followingRedirects()->post($base, ['name' => ['bad'], 'description' => ['bad'], 'address' => ['bad'], 'capacity' => ['bad']])
            ->assertOk()->assertSee('Название должно быть текстом.');
        $this->getJson($base.'?page=-1')->assertUnprocessable();
        $this->getJson($base.'?search='.str_repeat('a', 151))->assertUnprocessable();
    }

    #[DataProvider('catalogs')]
    public function testBlockedAdminAndMissingRecordsAndCsrf(string $catalog): void
    {
        $admin = $this->admin();
        $item = $catalog === 'categories' ? Category::factory()->create() : $this->venue();
        $base = '/admin/'.$catalog;
        $admin->forceFill(['is_blocked' => true])->save();
        $this->actingAs($admin)->get($base)->assertForbidden();
        $this->delete($base.'/'.$item->id, ['confirm_delete' => 1])->assertForbidden();
        $admin->forceFill(['is_blocked' => false])->save();
        $this->get($base.'/999999/edit')->assertNotFound();
        $this->get($base.'/bad/edit')->assertNotFound();
        $this->app->instance('env', 'local');
        $this->withMiddleware(PreventRequestForgery::class)->post($base, ['name' => 'Forbidden'])->assertStatus(419);
        $this->patch($base.'/'.$item->id, ['name' => 'Forbidden'])->assertStatus(419);
        $this->delete($base.'/'.$item->id, ['confirm_delete' => 1])->assertStatus(419);
        $this->assertDatabaseHas($catalog, ['id' => $item->id, 'name' => $item->name]);
    }
}
