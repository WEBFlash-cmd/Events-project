<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Event;
use App\Models\Order;
use App\Models\Permission;
use App\Models\Ticket;
use App\Models\User;
use App\Services\TicketCheckInService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Concerns\CreatesOrderContext;
use Tests\TestCase;

class TicketCheckInTest extends TestCase
{
    use RefreshDatabase, CreatesOrderContext;

    private Event $event;
    private Ticket $ticket;
    private Order $order;
    private User $buyer;
    private User $organizer;

    protected function setUp(): void
    {
        parent::setUp();
        [$this->buyer, $this->event, $ticketType] = $this->createOrderContext();
        $this->organizer = User::findOrFail($this->event->organizer_id);
        $this->order = Order::create([
            'user_id' => $this->buyer->id, 'event_id' => $this->event->id,
            'total_amount' => '100000.00', 'status' => 'paid',
        ]);
        $item = $this->order->items()->create([
            'ticket_type_id' => $ticketType->id, 'quantity' => 1, 'unit_price' => '100000.00',
        ]);
        $this->ticket = $this->order->tickets()->create([
            'number' => (string) Str::uuid(), 'user_id' => $this->buyer->id,
            'event_id' => $this->event->id, 'ticket_type_id' => $ticketType->id,
            'order_item_id' => $item->id, 'sequence' => 1, 'status' => 'active',
        ]);
    }

    public static function allowedActors(): array
    {
        return ['organizer before start' => ['organizer', false], 'organizer during event' => ['organizer', true], 'admin' => ['admin', false]];
    }

    #[DataProvider('allowedActors')]
    public function testAuthorizedCheckInRecordsServerTimeAndInspector(string $role, bool $started): void
    {
        $this->travelTo(now()->startOfSecond());
        if ($started) {
            $this->event->update(['starts_at' => now()->subHour(), 'ends_at' => now()->addHour()]);
        }
        $actor = $role === 'admin' ? User::factory()->create(['role' => UserRole::ADMIN]) : $this->organizer;
        Sanctum::actingAs($actor);

        $this->postJson($this->url())->assertOk()
            ->assertJsonPath('data.status', 'used')->assertJsonPath('data.checked_in_by', $actor->id)
            ->assertJsonPath('data.number', $this->ticket->number);
        $ticket = $this->ticket->fresh();
        $this->assertTrue($ticket->checked_in_at->equalTo(now()));
        $this->assertSame($actor->id, $ticket->checked_in_by);
        $this->assertSame('paid', $this->order->fresh()->status);
    }

    public function testSecondCheckInCannotChangeOriginalRegistration(): void
    {
        Sanctum::actingAs($this->organizer);
        $this->postJson($this->url())->assertOk();
        $original = $this->ticket->fresh();
        $this->travel(1)->minutes();
        Sanctum::actingAs(User::factory()->create(['role' => UserRole::ADMIN]));
        $this->postJson($this->url())->assertConflict();

        $ticket = $this->ticket->fresh();
        $this->assertSame('used', $ticket->status);
        $this->assertTrue($ticket->checked_in_at->equalTo($original->checked_in_at));
        $this->assertSame($this->organizer->id, $ticket->checked_in_by);
    }

    public function testStaleActiveModelCannotBeUsedTwice(): void
    {
        $service = app(TicketCheckInService::class);
        $service->checkIn($this->event, $this->ticket, $this->organizer);
        $this->assertSame('active', $this->ticket->status);

        try {
            $service->checkIn($this->event, $this->ticket, $this->organizer);
            $this->fail('A stale model must not bypass the database check.');
        } catch (HttpException $exception) {
            $this->assertSame(409, $exception->getStatusCode());
        }
    }

    public function testParticipantAndAnotherOrganizerCannotCheckIn(): void
    {
        foreach ([$this->buyer, User::factory()->create(['role' => UserRole::ORGANIZER])] as $actor) {
            Sanctum::actingAs($actor);
            $this->postJson($this->url())->assertForbidden();
        }
        $this->assertUnused();
    }

    public function testEventOwnerNeedsCheckInPermission(): void
    {
        $permission = Permission::where('name', 'tickets.check-in')->firstOrFail();
        $this->organizer->rbacRole->permissions()->detach($permission->id);
        Sanctum::actingAs($this->organizer);
        $this->postJson($this->url())->assertForbidden();
        $this->assertUnused();
    }

    public function testTicketFromAnotherEventReturns404EvenForAdmin(): void
    {
        [, $otherEvent] = $this->createOrderContext();
        Sanctum::actingAs(User::factory()->create(['role' => UserRole::ADMIN]));
        $this->postJson("/api/events/{$otherEvent->id}/tickets/{$this->ticket->id}/check-in")->assertNotFound();
        $this->assertUnused();
    }

    public static function unpaidOrders(): array
    {
        return ['pending' => ['pending'], 'cancelled' => ['cancelled'], 'expired' => ['expired']];
    }

    #[DataProvider('unpaidOrders')]
    public function testUnpaidOrderCannotBeCheckedIn(string $status): void
    {
        $this->order->update(['status' => $status]);
        Sanctum::actingAs($this->organizer);
        $this->postJson($this->url())->assertConflict();
        $this->assertUnused();
    }

    public static function closedEvents(): array
    {
        return ['draft' => ['draft'], 'cancelled' => ['cancelled'], 'finished' => ['finished']];
    }

    #[DataProvider('closedEvents')]
    public function testClosedEventCannotBeCheckedIn(string $status): void
    {
        $this->event->update(['status' => $status]);
        Sanctum::actingAs($this->organizer);
        $this->postJson($this->url())->assertConflict();
        $this->assertUnused();
    }

    public function testPublishedEventCannotBeCheckedInAtItsEndTime(): void
    {
        $this->travelTo($this->event->ends_at);
        Sanctum::actingAs($this->organizer);
        $this->postJson($this->url())->assertConflict();
        $this->assertUnused();
    }

    public function testCancelledTicketAndExistingTimestampCannotBeUsed(): void
    {
        Sanctum::actingAs($this->organizer);
        $this->ticket->update(['status' => 'cancelled']);
        $this->postJson($this->url())->assertConflict();
        $this->ticket->update(['status' => 'active']);
        $this->ticket->checked_in_at = now()->subMinute();
        $this->ticket->save();
        $this->postJson($this->url())->assertConflict();
    }

    public function testCheckInFieldsCannotBeProvidedByClient(): void
    {
        Sanctum::actingAs($this->organizer);
        $this->postJson($this->url(), [
            'status' => 'active', 'checked_in_at' => now()->subDay()->toIso8601String(),
            'checked_in_by' => $this->buyer->id,
        ])->assertUnprocessable()->assertJsonValidationErrors(['status', 'checked_in_at', 'checked_in_by']);
        $this->assertUnused();
    }

    public function testGuestCannotCheckInAndMissingTicketReturns404(): void
    {
        $this->postJson($this->url())->assertUnauthorized();
        Sanctum::actingAs($this->organizer);
        foreach (['999999', 'invalid', (string) Str::uuid()] as $id) {
            $this->postJson("/api/events/{$this->event->id}/tickets/{$id}/check-in")->assertNotFound();
        }
        $this->assertUnused();
    }

    private function url(): string
    {
        return "/api/events/{$this->event->id}/tickets/{$this->ticket->id}/check-in";
    }

    private function assertUnused(): void
    {
        $ticket = $this->ticket->fresh();
        $this->assertSame('active', $ticket->status);
        $this->assertNull($ticket->checked_in_at);
        $this->assertNull($ticket->checked_in_by);
    }
}
