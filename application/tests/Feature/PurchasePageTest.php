<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\User;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\Concerns\CreatesOrderContext;
use Tests\TestCase;

class PurchasePageTest extends TestCase
{
    use RefreshDatabase, CreatesOrderContext;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Queue::fake();
    }

    private function purchase(): array
    {
        [$buyer, $event, $type] = $this->createOrderContext('1.05');
        $event->update(['allows_ticket_cancellation' => true]);
        $order = Order::create(['user_id' => $buyer->id, 'event_id' => $event->id, 'status' => 'paid', 'total_amount' => '1.05']);
        $item = $order->items()->create(['ticket_type_id' => $type->id, 'quantity' => 1, 'unit_price' => '1.05']);
        $order->payment()->create(['reference' => (string) Str::uuid(), 'cashbox_id' => 1, 'amount' => 105, 'status' => 'paid']);
        $type->decrement('quantity');
        $ticket = $order->tickets()->create(['number' => (string) Str::uuid(), 'user_id' => $buyer->id,
            'event_id' => $event->id, 'ticket_type_id' => $type->id, 'order_item_id' => $item->id, 'sequence' => 1, 'status' => 'active']);

        return [$buyer, $event, $type, $order, $ticket];
    }

    public function testGuestsMustLogIn(): void
    {
        foreach (['/orders', '/tickets', '/tickets/1'] as $url) {
            $this->get($url)->assertRedirect('/login');
        }
    }

    public function testEmptyListsAndNavigation(): void
    {
        $this->actingAs(User::factory()->create())->get('/orders')->assertOk()->assertSee('Заказов пока нет')->assertSee('Мои билеты');
        $this->get('/tickets')->assertOk()->assertSee('Билетов пока нет');
    }

    public function testOwnerSeesPurchaseAndTicketWithPrivateCaching(): void
    {
        [$buyer, $event, , $order, $ticket] = $this->purchase();
        $this->actingAs($buyer)->get('/orders')->assertOk()->assertSee($event->title)->assertSee('1,05 UZS')
            ->assertSee(route('checkout.order', $order))->assertHeader('Cache-Control', 'no-store, private');
        $this->get('/tickets')->assertOk()->assertSee($ticket->number)->assertSee(route('purchases.ticket', $ticket));
        $this->get('/tickets/'.$ticket->id)->assertOk()->assertSee('Действующий билет')->assertSee('Подтверждаю отмену билета')
            ->assertHeader('Cache-Control', 'no-store, private');
    }

    public function testOtherUsersPurchasesAreHiddenAndCannotBeCancelled(): void
    {
        [, $event, , , $ticket] = $this->purchase();
        $this->actingAs(User::factory()->create())->get('/orders')->assertDontSee($event->title);
        $this->get('/tickets')->assertDontSee($ticket->number);
        $this->get('/tickets/'.$ticket->id)->assertNotFound();
        $this->post('/tickets/'.$ticket->id.'/cancel')->assertNotFound();
        $this->assertSame('active', $ticket->fresh()->status);
    }

    public function testCancellationReleasesSeatAndRecordsRefundOnlyOnce(): void
    {
        [$buyer, , $type, $order, $ticket] = $this->purchase();
        $this->actingAs($buyer)->post('/tickets/'.$ticket->id.'/cancel')->assertRedirect(route('purchases.ticket', $ticket));
        $this->get('/tickets/'.$ticket->id)->assertSee('Билет отменён')->assertSee('деньги уже возвращены')->assertDontSee('Подтверждаю отмену билета');
        $this->post('/tickets/'.$ticket->id.'/cancel')->assertRedirect();
        $this->assertSame(10, $type->fresh()->quantity);
        $this->assertSame(105, $order->payment->fresh()->refund_amount);
        $this->get('/orders')->assertSee('Требуется возврат: 1,05 UZS');
    }

    public function testChangedConditionsReturnReadableErrorAndDoNotCancel(): void
    {
        [$buyer, $event, $type, , $ticket] = $this->purchase();
        $event->update(['starts_at' => now()->subMinute()]);
        $this->actingAs($buyer)->get('/tickets/'.$ticket->id)->assertDontSee('Подтверждаю отмену билета');
        $this->post('/tickets/'.$ticket->id.'/cancel')->assertRedirect()->assertSessionHas('purchase_error');
        $this->assertSame('active', $ticket->fresh()->status);
        $this->assertSame(9, $type->fresh()->quantity);
    }

    public function testCheckedInTicketDoesNotOfferCancellation(): void
    {
        [$buyer, , , , $ticket] = $this->purchase();
        $ticket->forceFill(['status' => 'used', 'checked_in_at' => now()])->save();
        $this->actingAs($buyer)->get('/tickets/'.$ticket->id)->assertSee('Вход зарегистрирован')->assertDontSee('Подтверждаю отмену билета');
    }

    public function testTextIsEscaped(): void
    {
        [$buyer, $event, , , $ticket] = $this->purchase();
        $event->update(['title' => '<script>alert(1)</script>']);
        foreach (['/orders', '/tickets', '/tickets/'.$ticket->id] as $url) {
            $this->actingAs($buyer)->get($url)->assertSee('&lt;script&gt;alert(1)&lt;/script&gt;', false)
                ->assertDontSee('<script>alert(1)</script>', false);
        }
    }

    public function testOrdersArePaginatedAndNewestFirst(): void
    {
        [$buyer, $event] = $this->createOrderContext();
        for ($i = 0; $i < 13; $i++) {
            $order = Order::create(['user_id' => $buyer->id, 'event_id' => $event->id, 'status' => 'cancelled', 'total_amount' => '1.00']);
        }
        $this->actingAs($buyer)->get('/orders')->assertOk()->assertViewHas('orders', fn ($orders) => $orders->count() === 12 && $orders->first()->id === $order->id)->assertSee('Далее');
        $this->get('/orders?page=2')->assertViewHas('orders', fn ($orders) => $orders->count() === 1)->assertSee('Назад');
    }

    public function testCancellationRequiresCsrf(): void
    {
        [$buyer, , , , $ticket] = $this->purchase();
        $this->actingAs($buyer);
        $this->app->instance('env', 'local');
        $this->withMiddleware(PreventRequestForgery::class)->post('/tickets/'.$ticket->id.'/cancel')->assertStatus(419);
        $this->assertSame('active', $ticket->fresh()->status);
    }
}
