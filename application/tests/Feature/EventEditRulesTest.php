<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Order;
use App\Models\User;
use App\Models\Venue;
use App\Services\EventService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\CreatesOrderContext;
use Tests\TestCase;

class EventEditRulesTest extends TestCase
{
    use RefreshDatabase, CreatesOrderContext;

    public static function protectedFields(): array
    {
        return [
            'organizer start' => ['organizer', 'starts_at'], 'organizer end' => ['organizer', 'ends_at'],
            'organizer venue' => ['organizer', 'venue_id'], 'admin start' => ['admin', 'starts_at'],
            'admin end' => ['admin', 'ends_at'], 'admin venue' => ['admin', 'venue_id'],
        ];
    }

    #[DataProvider('protectedFields')]
    public function testDatesAndVenueCannotChangeAfterSale(string $role, string $field): void
    {
        [$buyer, $event] = $this->createOrderContext();
        Order::create(['event_id' => $event->id, 'user_id' => $buyer->id,
            'status' => 'paid', 'total_amount' => '100000.00']);
        $actor = $role === 'admin' ? User::factory()->create(['role' => UserRole::ADMIN]) : $event->organizer;
        Sanctum::actingAs($actor);
        $value = $field === 'venue_id'
            ? Venue::create(['name' => 'Other hall', 'address' => 'Tashkent', 'capacity' => 500])->id
            : $event->{$field}->copy()->addMinutes(30)->toDateTimeString();
        $before = $event->fresh()->getRawOriginal();
        $this->patchJson("/api/events/{$event->id}", [$field => $value, 'title' => 'Must not be saved'])
            ->assertUnprocessable()->assertJsonValidationErrors([$field]);
        $this->assertSame($before, $event->fresh()->getRawOriginal());
    }

    public function testTitleAndDescriptionCanChangeWithoutChangingDates(): void
    {
        [$buyer, $event] = $this->createOrderContext();
        Order::create(['event_id' => $event->id, 'user_id' => $buyer->id,
            'status' => 'paid', 'total_amount' => '100000.00']);
        Sanctum::actingAs($event->organizer);
        $this->patchJson("/api/events/{$event->id}", ['title' => 'New title', 'description' => 'New description'])
            ->assertOk()->assertJsonPath('data.title', 'New title');
        $this->assertTrue($event->starts_at->equalTo($event->fresh()->starts_at));
        $this->assertSame($event->venue_id, $event->fresh()->venue_id);
    }

    public function testUnchangedProtectedFieldsAreAllowedAfterSale(): void
    {
        [$buyer, $event] = $this->createOrderContext();
        Order::create(['event_id' => $event->id, 'user_id' => $buyer->id,
            'status' => 'paid', 'total_amount' => '100000.00']);
        Sanctum::actingAs($event->organizer);
        $this->patchJson("/api/events/{$event->id}", ['title' => 'New title',
            'starts_at' => $event->starts_at->toDateTimeString(),
            'ends_at' => $event->ends_at->toDateTimeString(), 'venue_id' => (string) $event->venue_id])
            ->assertOk()->assertJsonPath('data.title', 'New title');
    }

    public function testDatesAndVenueCanChangeBeforeFirstSale(): void
    {
        [, $event] = $this->createOrderContext();
        $venue = Venue::create(['name' => 'New hall', 'address' => 'Tashkent', 'capacity' => 10]);
        Sanctum::actingAs($event->organizer);
        $this->patchJson("/api/events/{$event->id}", ['venue_id' => $venue->id,
            'starts_at' => now()->addDays(4)->toDateTimeString(),
            'ends_at' => now()->addDays(4)->addHours(2)->toDateTimeString()])
            ->assertOk()->assertJsonPath('data.venue_id', $venue->id);
    }

    public function testVenueCapacityIncludesAvailableTickets(): void
    {
        [, $event] = $this->createOrderContext();
        $venue = Venue::create(['name' => 'Small hall', 'address' => 'Tashkent', 'capacity' => 9]);
        Sanctum::actingAs($event->organizer);
        $this->patchJson("/api/events/{$event->id}", ['venue_id' => $venue->id])
            ->assertUnprocessable()->assertJsonValidationErrors(['venue_id']);
        $this->assertSame($event->venue_id, $event->fresh()->venue_id);
    }

    public function testVenueCapacityIncludesUnpaidReservations(): void
    {
        [$buyer, $event, $type] = $this->createOrderContext();
        $order = Order::create(['event_id' => $event->id, 'user_id' => $buyer->id,
            'status' => 'pending', 'total_amount' => '200000.00', 'expires_at' => now()->addMinutes(15)]);
        $order->items()->create(['ticket_type_id' => $type->id, 'quantity' => 2, 'unit_price' => '100000.00']);
        $type->decrement('quantity', 2);
        $venue = Venue::create(['name' => 'Small hall', 'address' => 'Tashkent', 'capacity' => 9]);
        Sanctum::actingAs($event->organizer);
        $this->patchJson("/api/events/{$event->id}", ['venue_id' => $venue->id])
            ->assertUnprocessable()->assertJsonValidationErrors(['venue_id']);
        $venue->update(['capacity' => 10]);
        $this->patchJson("/api/events/{$event->id}", ['venue_id' => $venue->id])->assertOk();
        $this->assertSame('pending', $order->fresh()->status);
        $this->assertSame(8, $type->fresh()->quantity);
    }

    public function testCancelledAndExpiredUnpaidOrdersDoNotFreezeDates(): void
    {
        [$buyer, $event] = $this->createOrderContext();
        foreach (['cancelled', 'expired'] as $status) {
            Order::create(['event_id' => $event->id, 'user_id' => $buyer->id,
                'status' => $status, 'total_amount' => '100000.00']);
        }
        Sanctum::actingAs($event->organizer);
        $this->patchJson("/api/events/{$event->id}", ['starts_at' => $event->starts_at->copy()->addMinutes(15)->toDateTimeString()])
            ->assertOk();
    }

    public function testHistoricalTicketKeepsDatesFrozenEvenAfterEventCancellation(): void
    {
        [$buyer, $event, $type] = $this->createOrderContext();
        $order = Order::create(['event_id' => $event->id, 'user_id' => $buyer->id,
            'status' => 'cancelled', 'total_amount' => '100000.00']);
        $item = $order->items()->create(['ticket_type_id' => $type->id, 'quantity' => 1, 'unit_price' => '100000.00']);
        $order->tickets()->create(['number' => (string) Str::uuid(), 'user_id' => $buyer->id,
            'event_id' => $event->id, 'ticket_type_id' => $type->id, 'order_item_id' => $item->id,
            'sequence' => 1, 'status' => 'cancelled']);
        $event->update(['status' => 'cancelled']);
        Sanctum::actingAs($event->organizer);
        $this->patchJson("/api/events/{$event->id}", ['ends_at' => $event->ends_at->copy()->addHour()->toDateTimeString()])
            ->assertUnprocessable()->assertJsonValidationErrors(['ends_at']);
    }

    public function testPartialDateUpdateRechecksFreshDatabaseState(): void
    {
        [, $event] = $this->createOrderContext();
        $staleStart = $event->starts_at->copy();
        $event->newQuery()->whereKey($event->id)->update(['starts_at' => $event->ends_at->copy()->subMinute()]);
        try {
            app(EventService::class)->updateEvent($event, ['ends_at' => $staleStart->copy()->addHour()->toDateTimeString()]);
            $this->fail('Current dates must be checked inside the transaction.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('ends_at', $exception->errors());
        }
        $this->assertTrue($event->ends_at->equalTo($event->fresh()->ends_at));
    }

    public function testSaleOfAnotherEventDoesNotFreezeThisEvent(): void
    {
        [, $event] = $this->createOrderContext();
        [$buyer, $other] = $this->createOrderContext();
        Order::create(['event_id' => $other->id, 'user_id' => $buyer->id,
            'status' => 'paid', 'total_amount' => '100000.00']);
        Sanctum::actingAs($event->organizer);
        $this->patchJson("/api/events/{$event->id}", ['ends_at' => $event->ends_at->copy()->addHour()->toDateTimeString()])
            ->assertOk();
    }
}
