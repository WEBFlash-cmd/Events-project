<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Order;
use App\Services\OrderReservationService;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\CreatesOrderContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;
use App\Models\User;

class OrderManagementTest extends TestCase
{
    use RefreshDatabase, CreatesOrderContext;

    protected function setUp(): void
    {
        parent::setUp();
        $this->configurePaymentGateway();
        Http::fake(['payments.test/*' => Http::response([
            'data' => ['url' => 'http://payments.test/process-payment/100'],
        ], 201)]);
    }

    public function testParticipantCanCreateOrderForPublishedEvent(): void
    {
        [$participant, $event, $ticketType] = $this->createOrderContext();
        Sanctum::actingAs($participant);

        $response = $this->postJson("/api/events/{$event->id}/orders", [
            'items' => [['ticket_type_id' => $ticketType->id, 'quantity' => 2]],
        ]);

        $response->assertCreated();
        $orderId = $response->json('data.id');
        $this->assertDatabaseCount('orders', 1);
        $this->assertDatabaseHas('orders', [
            'id' => $orderId, 'user_id' => $participant->id, 'event_id' => $event->id,
            'total_amount' => '200000.00', 'status' => 'pending',
        ]);
        $this->assertDatabaseCount('order_items', 1);
        $this->assertDatabaseHas('order_items', [
            'order_id' => $orderId, 'ticket_type_id' => $ticketType->id,
            'quantity' => 2, 'unit_price' => '100000.00',
        ]);
        $this->assertSame(8, $ticketType->fresh()->quantity);
    }

    public function testOrderIsRolledBackWhenOneItemHasInsufficientStock(): void
    {
        [$participant, $event, $ticketType] = $this->createOrderContext();
        $secondType = $event->ticketTypes()->create([
            'name' => 'VIP', 'price' => '200000.00', 'quantity' => 1,
        ]);
        Sanctum::actingAs($participant);

        $this->postJson("/api/events/{$event->id}/orders", ['items' => [
            ['ticket_type_id' => $ticketType->id, 'quantity' => 2],
            ['ticket_type_id' => $secondType->id, 'quantity' => 2],
        ]])->assertUnprocessable()->assertJsonValidationErrors('items.1.quantity');

        $this->assertDatabaseCount('orders', 0);
        $this->assertDatabaseCount('order_items', 0);
        $this->assertSame(10, $ticketType->fresh()->quantity);
    }

    public function testCannotOrderTicketsFromAnotherEvent(): void
    {
        [$participant, $event] = $this->createOrderContext();
        [, , $otherTicketType] = $this->createOrderContext();
        Sanctum::actingAs($participant);

        $this->postJson("/api/events/{$event->id}/orders", ['items' => [
            ['ticket_type_id' => $otherTicketType->id, 'quantity' => 1],
        ]])->assertUnprocessable()->assertJsonValidationErrors('items.0.ticket_type_id');

        $this->assertDatabaseCount('orders', 0);
    }

    public function testCannotOrderForDraftEvent(): void
    {
        [$participant, $event, $ticketType] = $this->createOrderContext();
        $event->update(['status' => 'draft']);
        Sanctum::actingAs($participant);

        $this->postJson("/api/events/{$event->id}/orders", ['items' => [
            ['ticket_type_id' => $ticketType->id, 'quantity' => 1],
        ]])->assertUnprocessable()->assertJsonValidationErrors('event');

        $this->assertDatabaseCount('orders', 0);
        $this->assertSame(10, $ticketType->fresh()->quantity);
    }

    public function testGuestCannotCreateOrder(): void
    {
        [, $event] = $this->createOrderContext();
        $this->postJson("/api/events/{$event->id}/orders", [])->assertUnauthorized();
    }

    public function testOrderDeadlineUsesConfiguredReservationTime(): void
    {
        $this->travelTo(now()->startOfSecond());
        config(['orders.reservation_minutes' => 7]);
        [$order] = $this->createPendingOrder();
        $this->assertTrue($order->expires_at->equalTo(now()->addMinutes(7)));
    }

    public function testDeadlineCannotBeLaterThanEventStart(): void
    {
        $this->freezeTime();
        [$participant, $event, $ticketType] = $this->createOrderContext();
        $event->update(['starts_at' => now()->addMinutes(3)]);
        Sanctum::actingAs($participant);

        $response = $this->postJson("/api/events/{$event->id}/orders", [
            'items' => [['ticket_type_id' => $ticketType->id, 'quantity' => 1]],
        ])->assertCreated();

        $this->assertTrue(Order::findOrFail($response->json('data.id'))->expires_at->equalTo($event->starts_at));
    }

    public function testOwnerCanCancelOrderAndStockIsReturnedOnlyOnce(): void
    {
        [$order, $ticketType] = $this->createPendingOrder();

        $this->postJson("/api/orders/{$order->id}/cancel")
            ->assertOk()->assertJsonPath('data.status', 'cancelled');
        $this->postJson("/api/orders/{$order->id}/cancel")
            ->assertOk()->assertJsonPath('data.status', 'cancelled');

        $this->assertSame(10, $ticketType->fresh()->quantity);
        $this->assertDatabaseCount('orders', 1);
        $this->assertDatabaseCount('order_items', 1);
    }

    public function testAnotherParticipantCannotCancelOrder(): void
    {
        [$order, $ticketType] = $this->createPendingOrder();
        Sanctum::actingAs(User::factory()->create(['role' => UserRole::PARTICIPANT]));

        $this->postJson("/api/orders/{$order->id}/cancel")->assertForbidden();
        $this->assertSame('pending', $order->fresh()->status);
        $this->assertSame(8, $ticketType->fresh()->quantity);
    }

    public function testAdminCanCancelAnotherUsersOrder(): void
    {
        [$order, $ticketType] = $this->createPendingOrder();
        Sanctum::actingAs(User::factory()->create(['role' => UserRole::ADMIN]));

        $this->postJson("/api/orders/{$order->id}/cancel")
            ->assertOk()->assertJsonPath('data.status', 'cancelled');
        $this->assertSame(10, $ticketType->fresh()->quantity);
    }

    public function testPaidOrderCannotBeCancelledOrExpired(): void
    {
        [$order, $ticketType] = $this->createPendingOrder();
        $order->update(['status' => 'paid', 'expires_at' => now()->subMinute()]);

        $this->postJson("/api/orders/{$order->id}/cancel")->assertConflict();
        $this->artisan('orders:expire')->expectsOutput('Expired orders: 0')->assertSuccessful();
        $this->assertSame('paid', $order->fresh()->status);
        $this->assertSame(8, $ticketType->fresh()->quantity);
    }

    public function testExpiryReturnsStockExactlyOnceAtDeadline(): void
    {
        $this->freezeTime();
        [$order, $ticketType] = $this->createPendingOrder();
        $this->travelTo($order->expires_at);

        $this->artisan('orders:expire')->expectsOutput('Expired orders: 1')->assertSuccessful();
        $this->artisan('orders:expire')->expectsOutput('Expired orders: 0')->assertSuccessful();
        $this->postJson("/api/orders/{$order->id}/cancel")
            ->assertOk()->assertJsonPath('data.status', 'expired');
        $this->assertSame(10, $ticketType->fresh()->quantity);
    }

    public function testFreshAndCancelledOrdersAreNotExpired(): void
    {
        [$order, $ticketType] = $this->createPendingOrder();
        $this->artisan('orders:expire')->expectsOutput('Expired orders: 0')->assertSuccessful();
        $this->assertSame('pending', $order->fresh()->status);
        $this->assertSame(8, $ticketType->fresh()->quantity);

        $this->postJson("/api/orders/{$order->id}/cancel")->assertOk();
        $this->travel(16)->minutes();
        $this->artisan('orders:expire')->expectsOutput('Expired orders: 0')->assertSuccessful();
        $this->assertSame('cancelled', $order->fresh()->status);
        $this->assertSame(10, $ticketType->fresh()->quantity);
    }

    public function testCancellingOverdueOrderMarksItExpired(): void
    {
        [$order, $ticketType] = $this->createPendingOrder();
        $this->travelTo($order->expires_at);
        $this->postJson("/api/orders/{$order->id}/cancel")
            ->assertOk()->assertJsonPath('data.status', 'expired');
        $this->assertSame(10, $ticketType->fresh()->quantity);
    }

    public function testExpiryRechecksStatusInsteadOfUsingStaleModel(): void
    {
        [$order, $ticketType] = $this->createPendingOrder();
        $this->travelTo($order->expires_at);
        Order::whereKey($order->id)->update(['status' => 'paid']);

        $this->assertFalse(app(OrderReservationService::class)->expire($order));
        $this->assertSame(8, $ticketType->fresh()->quantity);
    }

    public function testReservedSeatsAreIncludedWhenOrganizerAddsStock(): void
    {
        [$order, $ticketType] = $this->createPendingOrder();
        $event = $order->event;
        Sanctum::actingAs(User::findOrFail($event->organizer_id));

        $this->patchJson("/api/events/{$event->id}/ticket-types/{$ticketType->id}", ['quantity' => 299])
            ->assertUnprocessable()->assertJsonValidationErrors('quantity');
        $this->postJson("/api/events/{$event->id}/ticket-types", [
            'name' => 'VIP', 'price' => '1.00', 'quantity' => 291,
        ])->assertUnprocessable()->assertJsonValidationErrors('quantity');

        $this->patchJson("/api/events/{$event->id}/ticket-types/{$ticketType->id}", ['quantity' => 298])
            ->assertOk();
        Sanctum::actingAs(User::findOrFail($order->user_id));
        $this->postJson("/api/orders/{$order->id}/cancel")->assertOk();
        $this->assertSame(300, $ticketType->fresh()->quantity);
    }

    public function testCannotCreateOrderForStartedEvent(): void
    {
        [$participant, $event, $ticketType] = $this->createOrderContext();
        $event->update(['starts_at' => now()->subMinute()]);
        Sanctum::actingAs($participant);
        $this->postJson("/api/events/{$event->id}/orders", [
            'items' => [['ticket_type_id' => $ticketType->id, 'quantity' => 1]],
        ])->assertUnprocessable()->assertJsonValidationErrors('event');
        $this->assertSame(10, $ticketType->fresh()->quantity);
    }

    private function createPendingOrder(): array
    {
        [$participant, $event, $ticketType] = $this->createOrderContext();
        Sanctum::actingAs($participant);
        $response = $this->postJson("/api/events/{$event->id}/orders", [
            'items' => [['ticket_type_id' => $ticketType->id, 'quantity' => 2]],
        ])->assertCreated();

        return [Order::findOrFail($response->json('data.id')), $ticketType];
    }

}
