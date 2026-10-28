<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Event;
use App\Models\Order;
use App\Models\Permission;
use App\Models\TicketType;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\CreatesOrderContext;
use Tests\TestCase;

class AdminPurchasePageTest extends TestCase
{
    use RefreshDatabase, CreatesOrderContext;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    public static function resources(): array
    {
        return ['orders' => ['orders'], 'tickets' => ['tickets']];
    }

    private function admin(): User
    {
        return User::factory()->create(['role' => UserRole::ADMIN]);
    }

    private function purchase(User $buyer, Event $event, TicketType $type, bool $refund = false): array
    {
        $order = Order::create(['user_id' => $buyer->id, 'event_id' => $event->id, 'status' => 'paid', 'total_amount' => '12.34']);
        $item = $order->items()->create(['ticket_type_id' => $type->id, 'quantity' => 1, 'unit_price' => '12.34']);
        $order->payment()->create(['reference' => (string) Str::uuid(), 'cashbox_id' => 1, 'amount' => 1234,
            'status' => $refund ? 'refund_required' : 'paid', 'refund_required' => $refund, 'refund_amount' => $refund ? 1234 : 0,
            'payment_url' => 'https://payments.test/private-secret', 'paid_at' => now()]);
        $ticket = $order->tickets()->create(['number' => (string) Str::uuid(), 'user_id' => $buyer->id,
            'event_id' => $event->id, 'ticket_type_id' => $type->id, 'order_item_id' => $item->id,
            'sequence' => 1, 'status' => $refund ? 'cancelled' : 'active']);
        $ticket->forceFill(['refund_required' => $refund])->save();

        return [$order, $ticket];
    }

    #[DataProvider('resources')]
    public function testGuestParticipantAndOrganizerCannotReadPurchases(string $resource): void
    {
        [$buyer, $event, $type] = $this->createOrderContext();
        $this->purchase($buyer, $event, $type);
        $base = '/admin/'.$resource;
        $this->get($base)->assertRedirect('/login');
        foreach ([$buyer, $event->organizer] as $actor) {
            $this->actingAs($actor)->get($base)->assertForbidden();
        }
    }

    #[DataProvider('resources')]
    public function testAdminReadsSafeInformationAndRequiresSpecificPermission(string $resource): void
    {
        [$buyer, $event, $type] = $this->createOrderContext();
        [$order, $ticket] = $this->purchase($buyer, $event, $type, true);
        $token = $buyer->createToken('secret')->plainTextToken;
        $admin = $this->admin();
        $this->actingAs($admin)->get('/admin/'.$resource)->assertOk()->assertHeader('Cache-Control', 'no-store, private')
            ->assertSee($buyer->email)->assertSee($event->title)->assertSee('возврат', false)
            ->assertDontSee('private-secret')->assertDontSee($buyer->password)->assertDontSee($token);
        if ($resource === 'orders') {
            $this->get('/admin/orders')->assertOk()->assertSee('12,34 UZS')->assertSee('12.34 UZS')
                ->assertSee(route('admin.purchases.tickets', ['order_id' => $order->id]));
        } else {
            $this->get('/admin/tickets')->assertOk()->assertSee($ticket->number)
                ->assertSee(route('admin.purchases.orders', ['order_id' => $order->id]));
        }
        $admin->rbacRole->permissions()->detach(Permission::where('name', $resource.'.view-any')->value('id'));
        $this->get('/admin/'.$resource)->assertForbidden();
    }

    #[DataProvider('resources')]
    public function testSearchAndFiltersCombineWithoutLeakingOtherPurchases(string $resource): void
    {
        [$buyer, $event, $type] = $this->createOrderContext();
        $buyer->update(['name' => 'Target Buyer', 'email' => 'target-buyer@example.test']);
        [$order, $ticket] = $this->purchase($buyer, $event, $type, true);
        $this->purchase(User::factory()->create(['name' => 'Other']), $event, $type);
        $status = $resource === 'orders' ? 'paid' : 'cancelled';
        $query = http_build_query(['search' => 'TARGET', 'user_id' => $buyer->id, 'event_id' => $event->id,
            'order_id' => $order->id, 'status' => $status, 'refund_required' => 1]);
        $expected = $resource === 'orders' ? $order : $ticket;
        $this->actingAs($this->admin())->get('/admin/'.$resource.'?'.$query)->assertOk()
            ->assertViewHas($resource, fn ($items) => $items->total() === 1 && $items->first()->id === $expected->id);
        $this->get('/admin/'.$resource.'?search=TARGET-BUYER')->assertOk()
            ->assertViewHas($resource, fn ($items) => $items->total() === 1);
        $this->get('/admin/'.$resource.'?search=TEST%20EVENT&refund_required=0')->assertOk()
            ->assertViewHas($resource, fn ($items) => $items->total() === 1 && $items->first()->user_id !== $buyer->id);
        $this->get('/admin/'.$resource.'?search=&status=&user_id=&event_id=&order_id=&refund_required=')->assertOk()
            ->assertViewHas($resource, fn ($items) => $items->total() === 2);
    }

    #[DataProvider('resources')]
    public function testStablePaginationKeepsSearchAndRefundFilters(string $resource): void
    {
        $this->freezeTime();
        [$buyer, $event, $type] = $this->createOrderContext();
        $last = null;
        foreach (range(1, 16) as $number) {
            $pair = $this->purchase($buyer, $event, $type);
            $last = $pair[$resource === 'orders' ? 0 : 1];
        }
        $this->actingAs($this->admin())->get('/admin/'.$resource.'?search=Test&refund_required=0')->assertOk()
            ->assertViewHas($resource, fn ($items) => $items->total() === 16 && $items->count() === 15 && $items->first()->id === $last->id)
            ->assertSee('search=Test&amp;refund_required=0&amp;page=2', false);
        $this->get('/admin/'.$resource.'?search=Test&refund_required=0&page=2')->assertOk()
            ->assertViewHas($resource, fn ($items) => $items->count() === 1);
        $this->get('/admin/'.$resource.'?search=missing-unique-value')->assertOk()
            ->assertSee($resource === 'orders' ? 'Заказы не найдены' : 'Билеты не найдены');
    }

    public function testExactUuidAndOrderFiltersAndInvalidUuidReturnReadableError(): void
    {
        [$buyer, $event, $type] = $this->createOrderContext();
        [$order, $ticket] = $this->purchase($buyer, $event, $type);
        $this->purchase($buyer, $event, $type);
        $this->actingAs($this->admin())->get('/admin/tickets?number='.$ticket->number.'&order_id='.$order->id)->assertOk()
            ->assertViewHas('tickets', fn ($items) => $items->total() === 1 && $items->first()->id === $ticket->id);
        $this->followingRedirects()->get('/admin/tickets?number=not-a-uuid')->assertOk()->assertSee('Номер билета должен быть UUID');
        $this->get('/admin/orders?order_id=999999')->assertOk()->assertSee('Заказы не найдены');
    }

    #[DataProvider('resources')]
    public function testInvalidFiltersEscapeSafelyAndKeepOrderAndTicketStatusesSeparate(string $resource): void
    {
        [$buyer, $event, $type] = $this->createOrderContext();
        $event->update(['title' => '<script>bad()</script>']);
        $this->purchase($buyer, $event, $type);
        $this->actingAs($this->admin())->get('/admin/'.$resource)->assertOk()
            ->assertSee(e($event->title), false)->assertDontSee($event->title, false);
        $this->followingRedirects()->get('/admin/'.$resource.'?search[]=bad')->assertOk()->assertSee('Введите поисковый запрос текстом.');
        $this->getJson('/admin/'.$resource.'?status='.($resource === 'orders' ? 'active' : 'paid'))->assertUnprocessable();
        $this->getJson('/admin/'.$resource.'?refund_required=2')->assertUnprocessable();
        $this->getJson('/admin/'.$resource.'?user_id=999999')->assertUnprocessable();
        $this->getJson('/admin/'.$resource.'?page=-1')->assertUnprocessable();
    }

    public function testLimitedReaderSeesOnlyAuthorizedNavigationAndBlockedReaderCannotRead(): void
    {
        $admin = $this->admin();
        $admin->rbacRole->permissions()->sync(Permission::where('name', 'orders.view-any')->pluck('id'));
        $this->actingAs($admin)->get('/account')->assertOk()->assertSee('href="'.route('admin.purchases.orders').'"', false);
        $this->get('/admin/orders')->assertOk()->assertDontSee('href="'.route('admin.purchases.tickets').'"', false);
        $this->get('/admin/tickets')->assertForbidden();
        $admin->forceFill(['is_blocked' => true])->save();
        $this->get('/admin/orders')->assertForbidden();
    }
}
