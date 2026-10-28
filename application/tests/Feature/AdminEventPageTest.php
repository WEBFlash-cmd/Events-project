<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Event;
use App\Models\Order;
use App\Models\Permission;
use App\Models\User;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesOrderContext;
use Tests\TestCase;

class AdminEventPageTest extends TestCase
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

    public function testGuestsParticipantsAndOrganizersCannotAccessAdminEvents(): void
    {
        [$buyer, $event] = $this->createOrderContext();
        $base = '/admin/events/'.$event->id;
        $this->get('/admin/events')->assertRedirect('/login');
        $this->get($base)->assertRedirect('/login');
        $this->delete($base, ['confirm_delete' => 1])->assertRedirect('/login');
        foreach ([$buyer, $event->organizer] as $actor) {
            $this->actingAs($actor)->get('/admin/events')->assertForbidden();
            $this->get($base)->assertForbidden();
            $this->delete($base, ['confirm_delete' => 1])->assertForbidden();
        }
        $this->assertDatabaseHas('events', ['id' => $event->id]);
    }

    public function testAdminSeesDetailsAndEscapedTextWithoutPrivateAccountFields(): void
    {
        [, $event] = $this->createOrderContext();
        $event->update(['title' => '<script>bad()</script>', 'description' => '<b>Plain text</b>', 'status' => 'draft']);
        $organizer = $event->organizer;
        $token = $organizer->createToken('secret')->plainTextToken;
        $this->actingAs($this->admin());
        foreach (['/admin/events', '/admin/events/'.$event->id] as $url) {
            $this->get($url)->assertOk()->assertHeader('Cache-Control', 'no-store, private')
                ->assertSee(e($event->title), false)->assertDontSee($event->title, false)
                ->assertSee($organizer->email)->assertDontSee($organizer->password)->assertDontSee($token);
        }
        $this->get('/admin/events/'.$event->id)->assertOk()->assertSee(e($event->description), false)
            ->assertSee('Standard')->assertSee('Удалить мероприятие')->assertDontSee('Открыть в афише');
    }

    public function testFiltersWorkTogetherAndEmptyNativeFieldsAreAccepted(): void
    {
        [, $match] = $this->createOrderContext();
        $match->update(['title' => 'Target Event', 'status' => 'draft']);
        [, $other] = $this->createOrderContext();
        $other->update(['title' => 'Target Other', 'status' => 'published']);
        $this->actingAs($this->admin());
        $query = http_build_query(['search' => 'TARGET', 'status' => 'draft', 'organizer_id' => $match->organizer_id,
            'category_id' => $match->category_id, 'venue_id' => $match->venue_id]);
        $this->get('/admin/events?'.$query)->assertOk()
            ->assertViewHas('events', fn ($events) => $events->total() === 1 && $events->first()->id === $match->id);
        $this->get('/admin/events?search=&status=&organizer_id=&category_id=&venue_id=')->assertOk()
            ->assertViewHas('events', fn ($events) => $events->total() === 2);
        $this->get('/admin/events?search=unmatched')->assertOk()->assertSee('Мероприятия не найдены');
    }

    public function testAllStatusesAreShownAndFiltersSurvivePagination(): void
    {
        [, $template] = $this->createOrderContext();
        foreach (range(1, 16) as $number) {
            Event::create(array_merge($template->only(['description', 'starts_at', 'ends_at', 'organizer_id', 'category_id', 'venue_id']), [
                'title' => sprintf('Paging %02d', $number), 'status' => ['draft', 'published', 'cancelled', 'finished'][$number % 4],
            ]));
        }
        $this->actingAs($this->admin())->get('/admin/events?search=Paging')->assertOk()
            ->assertViewHas('events', fn ($events) => $events->total() === 16 && $events->count() === 15)
            ->assertSee('search=Paging&amp;page=2', false);
        $this->get('/admin/events?search=Paging&page=2')->assertOk()
            ->assertViewHas('events', fn ($events) => $events->count() === 1);
    }

    public function testDeletionRequiresConfirmationAndRemovesOnlyEventAndTicketTypes(): void
    {
        [, $event, $type] = $this->createOrderContext();
        $base = '/admin/events/'.$event->id;
        $this->actingAs($this->admin())->from($base)->delete($base)->assertSessionHasErrors('confirm_delete');
        $this->assertDatabaseHas('events', ['id' => $event->id]);
        $this->delete($base, ['confirm_delete' => 0])->assertSessionHasErrors('confirm_delete');
        $this->delete($base, ['confirm_delete' => 1])->assertRedirect(route('admin.events.index'))->assertSessionHas('admin_success');
        $this->assertDatabaseMissing('events', ['id' => $event->id]);
        $this->assertDatabaseMissing('ticket_types', ['id' => $type->id]);
        $this->assertDatabaseHas('users', ['id' => $event->organizer_id]);
        $this->assertDatabaseHas('venues', ['id' => $event->venue_id]);
        $this->assertDatabaseHas('categories', ['id' => $event->category_id]);
        $this->get($base)->assertNotFound();
    }

    public function testNewCancelledOrderPreventsDeletionEvenAfterOpeningThePage(): void
    {
        [$buyer, $event, $type] = $this->createOrderContext();
        $base = '/admin/events/'.$event->id;
        $this->actingAs($this->admin())->get($base)->assertOk()->assertSee('name="confirm_delete"', false);
        $order = Order::create(['user_id' => $buyer->id, 'event_id' => $event->id, 'status' => 'cancelled', 'total_amount' => '1.00']);
        $this->delete($base, ['confirm_delete' => 1])->assertRedirect(route('admin.events.show', $event))->assertSessionHas('admin_error');
        $this->get($base)->assertOk()->assertSee('Удаление недоступно')->assertDontSee('name="confirm_delete"', false);
        $this->assertDatabaseHas('orders', ['id' => $order->id]);
        $this->assertDatabaseHas('events', ['id' => $event->id]);
        $this->assertDatabaseHas('ticket_types', ['id' => $type->id]);
    }

    public function testViewPermissionDoesNotGrantDeleteAndHeaderSupportsEventOnlyAccess(): void
    {
        [, $event] = $this->createOrderContext();
        $admin = $this->admin();
        $admin->rbacRole->permissions()->sync(Permission::where('name', 'events.view-any')->pluck('id'));
        $this->actingAs($admin)->get('/admin/events/'.$event->id)->assertOk()->assertDontSee('Удаление мероприятия');
        $this->get('/account')->assertOk()->assertSee('href="'.route('admin.events.index').'"', false);
        $this->delete('/admin/events/'.$event->id, ['confirm_delete' => 1])->assertForbidden();
        $admin->rbacRole->permissions()->detach();
        $this->get('/admin/events')->assertForbidden();
    }

    public function testMalformedFiltersRedirectSafelyAndMissingEventsReturn404(): void
    {
        $this->actingAs($this->admin())->followingRedirects()->get('/admin/events?search[]=bad')
            ->assertOk()->assertSee('Поисковый запрос должен быть текстом.');
        $this->followingRedirects()->get('/admin/events?status=invalid')->assertOk()->assertSee('Выберите статус из списка.');
        $this->getJson('/admin/events?organizer_id=999999')->assertUnprocessable();
        $this->getJson('/admin/events?page=-1')->assertUnprocessable();
        $this->get('/admin/events/999999')->assertNotFound();
        $this->get('/admin/events/bad')->assertNotFound();
    }

    public function testBlockedAdminAndCsrfProtectDeletion(): void
    {
        [, $event] = $this->createOrderContext();
        $admin = $this->admin();
        $admin->forceFill(['is_blocked' => true])->save();
        $this->actingAs($admin)->get('/admin/events')->assertForbidden();
        $this->delete('/admin/events/'.$event->id, ['confirm_delete' => 1])->assertForbidden();
        $admin->forceFill(['is_blocked' => false])->save();
        $this->app->instance('env', 'local');
        $this->withMiddleware(PreventRequestForgery::class)->delete('/admin/events/'.$event->id, ['confirm_delete' => 1])->assertStatus(419);
        $this->assertDatabaseHas('events', ['id' => $event->id]);
    }
}
