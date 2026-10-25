<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Permission;
use App\Models\Order;
use App\Models\User;
use App\Services\AdminEventService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\CreatesOrderContext;
use Tests\TestCase;

class AdminEventsTest extends TestCase
{
    use RefreshDatabase, CreatesOrderContext;

    #[DataProvider('eventStatuses')]
    public function testAdminCanDeleteEventWithoutOrdersAndItsTicketTypes(string $status): void
    {
        [, $event, $type] = $this->createOrderContext();
        $event->update(['status' => $status]);
        Sanctum::actingAs(User::factory()->create(['role' => UserRole::ADMIN]));
        $this->deleteJson("/api/admin/events/{$event->id}")->assertNoContent();
        $this->assertDatabaseMissing('events', ['id' => $event->id]);
        $this->assertDatabaseMissing('ticket_types', ['id' => $type->id]);
        $this->assertDatabaseHas('venues', ['id' => $event->venue_id]);
        $this->assertDatabaseHas('categories', ['id' => $event->category_id]);
        $this->assertDatabaseHas('users', ['id' => $event->organizer_id]);
        $this->getJson("/api/admin/events/{$event->id}")->assertNotFound();
        $this->deleteJson("/api/admin/events/{$event->id}")->assertNotFound();
    }

    public static function orderStatuses(): array
    {
        return [['pending'], ['paid'], ['cancelled'], ['expired']];
    }

    #[DataProvider('orderStatuses')]
    public function testAnyOrderPreventsDeletionAndPreservesHistory(string $status): void
    {
        [$buyer, $event, $type] = $this->createOrderContext();
        $event->update(['status' => 'cancelled']);
        $order = Order::create(['user_id' => $buyer->id, 'event_id' => $event->id,
            'status' => $status, 'total_amount' => '1.00']);
        $item = $order->items()->create(['ticket_type_id' => $type->id, 'quantity' => 1, 'unit_price' => '1.00']);
        $payment = $order->payment()->create(['reference' => (string) Str::uuid(), 'cashbox_id' => 1,
            'amount' => 100, 'status' => $status === 'paid' ? 'paid' : 'pending']);
        if ($status === 'paid') {
            $order->tickets()->create(['number' => (string) Str::uuid(), 'user_id' => $buyer->id,
                'event_id' => $event->id, 'ticket_type_id' => $type->id, 'order_item_id' => $item->id,
                'sequence' => 1, 'status' => 'cancelled']);
        }
        Sanctum::actingAs(User::factory()->create(['role' => UserRole::ADMIN]));
        $this->deleteJson("/api/admin/events/{$event->id}")->assertConflict();
        $this->assertDatabaseHas('events', ['id' => $event->id, 'status' => 'cancelled']);
        $this->assertDatabaseHas('ticket_types', ['id' => $type->id]);
        $this->assertDatabaseHas('orders', ['id' => $order->id, 'status' => $status]);
        $this->assertDatabaseHas('payments', ['id' => $payment->id, 'amount' => 100]);
        $this->assertSame($status === 'paid' ? 1 : 0, $order->tickets()->count());
    }

    public function testOnlyUserWithDeletePermissionCanDeleteEvent(): void
    {
        [$buyer, $event] = $this->createOrderContext();
        $this->deleteJson("/api/admin/events/{$event->id}")->assertUnauthorized();
        foreach ([$buyer, $event->organizer] as $actor) {
            Sanctum::actingAs($actor);
            $this->deleteJson("/api/admin/events/{$event->id}")->assertForbidden();
        }
        $admin = User::factory()->create(['role' => UserRole::ADMIN]);
        $admin->rbacRole->permissions()->detach(Permission::where('name', 'events.delete')->value('id'));
        Sanctum::actingAs($admin);
        $this->getJson("/api/admin/events/{$event->id}")->assertOk();
        $this->deleteJson("/api/admin/events/{$event->id}")->assertForbidden();
        $this->assertDatabaseHas('events', ['id' => $event->id]);
    }

    public function testDeletionChecksNewOrdersEvenWithStaleEventModel(): void
    {
        [$buyer, $event] = $this->createOrderContext();
        Order::create(['user_id' => $buyer->id, 'event_id' => $event->id,
            'status' => 'pending', 'total_amount' => '1.00']);
        $admin = User::factory()->create(['role' => UserRole::ADMIN]);
        try {
            app(AdminEventService::class)->delete($event, $admin);
            $this->fail('An order created after loading the event must prevent deletion.');
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $exception) {
            $this->assertSame(409, $exception->getStatusCode());
        }
        $this->assertDatabaseHas('events', ['id' => $event->id]);
    }

    public function testAdminCanListAllStatusesWithPaginationAndSafeOrganizerData(): void
    {
        $this->freezeTime();
        $ids = [];
        foreach (['draft', 'published', 'cancelled', 'finished'] as $status) {
            [, $event] = $this->createOrderContext();
            $event->update(['status' => $status]);
            $ids[] = $event->id;
        }
        Sanctum::actingAs(User::factory()->create(['role' => UserRole::ADMIN]));
        $response = $this->getJson('/api/admin/events?per_page=2')->assertOk()
            ->assertJsonPath('total', 4)->assertJsonPath('last_page', 2)->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.id', $ids[3])->assertJsonPath('data.1.id', $ids[2]);
        $this->assertSame(['id', 'name', 'email'], array_keys($response->json('data.0.organizer')));
        $this->assertStringNotContainsString('remember_token', $response->getContent());
        $this->assertArrayNotHasKey('password', $response->json('data.0.organizer'));
        $this->getJson('/api/admin/events?per_page=2&page=2')->assertOk()->assertJsonPath('data.0.id', $ids[1]);
        $this->getJson('/api/admin/events?page=99')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson('/api/events')->assertOk()->assertJsonPath('total', 1)->assertJsonPath('data.0.id', $ids[1]);
    }

    public static function eventStatuses(): array
    {
        return [['draft'], ['published'], ['cancelled'], ['finished']];
    }

    #[DataProvider('eventStatuses')]
    public function testAdminCanViewAnyEventWithRelations(string $status): void
    {
        [, $event, $type] = $this->createOrderContext();
        $event->update(['status' => $status]);
        Sanctum::actingAs(User::factory()->create(['role' => UserRole::ADMIN]));
        $this->getJson("/api/admin/events/{$event->id}")->assertOk()
            ->assertJsonPath('data.status', $status)->assertJsonPath('data.ticket_types.0.id', $type->id)
            ->assertJsonPath('data.venue.id', $event->venue_id)
            ->assertJsonPath('data.category.id', $event->category_id)
            ->assertJsonPath('data.organizer.id', $event->organizer_id);
        if ($status !== 'published') {
            $this->getJson("/api/events/{$event->id}")->assertNotFound();
        }
    }

    public function testFiltersCombineAndSearchIsCaseInsensitive(): void
    {
        [, $event] = $this->createOrderContext();
        $event->update(['title' => 'PHP Meetup', 'status' => 'draft']);
        $this->createOrderContext();
        Sanctum::actingAs(User::factory()->create(['role' => UserRole::ADMIN]));
        $this->getJson("/api/admin/events?status=draft&organizer_id={$event->organizer_id}&category_id={$event->category_id}&venue_id={$event->venue_id}&search=php")
            ->assertOk()->assertJsonPath('total', 1)->assertJsonPath('data.0.id', $event->id);
        $this->getJson('/api/admin/events?search=does-not-exist')->assertOk()->assertJsonPath('total', 0);
    }

    public function testGuestsParticipantsAndOrganizersCannotUseAdminViews(): void
    {
        [$buyer, $event] = $this->createOrderContext();
        foreach (['/api/admin/events', "/api/admin/events/{$event->id}"] as $url) {
            $this->getJson($url)->assertUnauthorized();
        }
        foreach ([$buyer, $event->organizer] as $actor) {
            Sanctum::actingAs($actor);
            $this->getJson('/api/admin/events')->assertForbidden();
            $this->getJson("/api/admin/events/{$event->id}")->assertForbidden();
        }
    }

    public function testAdminNeedsReadPermissionAndUnknownIdsReturn404(): void
    {
        [, $event] = $this->createOrderContext();
        $admin = User::factory()->create(['role' => UserRole::ADMIN]);
        Sanctum::actingAs($admin);
        $this->getJson('/api/admin/events/99999999')->assertNotFound();
        $this->getJson('/api/admin/events/invalid')->assertNotFound();
        $admin->rbacRole->permissions()->detach(Permission::where('name', 'events.view-any')->value('id'));
        $this->getJson('/api/admin/events')->assertForbidden();
        $this->getJson("/api/admin/events/{$event->id}")->assertForbidden();
    }

    public static function invalidFilters(): array
    {
        return [['page=0'], ['page=abc'], ['per_page=101'], ['per_page=0'], ['status=invalid'],
            ['organizer_id=99999999'], ['venue_id=99999999'], ['category_id=99999999'], ['search[]=php']];
    }

    #[DataProvider('invalidFilters')]
    public function testInvalidFiltersAreRejected(string $query): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => UserRole::ADMIN]));
        $this->getJson('/api/admin/events?'.$query)->assertUnprocessable();
    }
}
