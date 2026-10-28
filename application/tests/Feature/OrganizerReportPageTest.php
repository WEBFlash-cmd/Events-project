<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Order;
use App\Models\Permission;
use App\Models\User;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\Concerns\CreatesOrderContext;
use Tests\TestCase;

class OrganizerReportPageTest extends TestCase
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
        $order = Order::create(['user_id' => $buyer->id, 'event_id' => $event->id, 'status' => 'paid', 'total_amount' => '2.10']);
        $item = $order->items()->create(['ticket_type_id' => $type->id, 'quantity' => 2, 'unit_price' => '1.05']);
        $order->payment()->create(['reference' => (string) Str::uuid(), 'cashbox_id' => 1, 'amount' => 210, 'status' => 'paid', 'payment_url' => 'https://payments.test/private-secret']);
        foreach ([1, 2] as $sequence) {
            $order->tickets()->create(['number' => (string) Str::uuid(), 'user_id' => $buyer->id, 'event_id' => $event->id,
                'ticket_type_id' => $type->id, 'order_item_id' => $item->id, 'sequence' => $sequence, 'status' => 'active']);
        }
        $type->decrement('quantity', 2);

        return [$buyer, $event, $type, $order, $order->tickets()->firstOrFail()];
    }

    public function testGuestsParticipantsAndOtherOrganizersCannotSeeReportsOrCheckIn(): void
    {
        [$buyer, $event, , , $ticket] = $this->purchase();
        $base = '/organizer/events/'.$event->id.'/reports';
        foreach (['sales', 'participants', 'check-in'] as $page) {
            $this->get($base.'/'.$page)->assertRedirect('/login');
        }
        foreach ([$buyer, User::factory()->create(['role' => UserRole::ORGANIZER])] as $actor) {
            $this->actingAs($actor);
            foreach (['sales', 'participants', 'check-in'] as $page) {
                $this->get($base.'/'.$page)->assertForbidden();
            }
            $this->post($base.'/tickets/'.$ticket->id.'/check-in')->assertForbidden();
        }
        $this->assertSame('active', $ticket->fresh()->status);
    }

    public function testOwnerAndAdminCanReadPrivatePagesAndNavigation(): void
    {
        [, $event] = $this->purchase();
        foreach ([$event->organizer, User::factory()->create(['role' => UserRole::ADMIN])] as $actor) {
            $this->actingAs($actor);
            foreach (['sales', 'participants', 'check-in'] as $page) {
                $this->get('/organizer/events/'.$event->id.'/reports/'.$page)->assertOk()->assertSee($event->title)
                    ->assertHeader('Cache-Control', 'no-store, private');
            }
        }
        $this->actingAs($event->organizer)->get('/organizer/events')->assertSee('Отчёты и вход');
        $this->get('/organizer/events/'.$event->id.'/edit')->assertSee('Отчёты, участники и проверка входа');
    }

    public function testSalesShowConfirmedMoneyRefundObligationAndTicketCounts(): void
    {
        [$buyer, $event, , $order, $ticket] = $this->purchase();
        $ticket->forceFill(['status' => 'used', 'checked_in_at' => now()])->save();
        $order->tickets()->where('id', '!=', $ticket->id)->update(['status' => 'cancelled', 'refund_required' => true]);
        $order->payment->update(['status' => 'refund_required', 'refund_required' => true, 'refund_amount' => 105]);
        Order::create(['user_id' => $buyer->id, 'event_id' => $event->id, 'status' => 'pending', 'total_amount' => '1.05', 'expires_at' => now()->addMinutes(10)]);
        $this->actingAs($event->organizer)->get('/organizer/events/'.$event->id.'/reports/sales')->assertOk()
            ->assertSee('2,10 UZS')->assertSee('1,05 UZS')->assertSee('зафиксированное обязательство')
            ->assertViewHas('report', fn ($report) => $report['orders']['pending'] === 1 && $report['tickets']['sold'] === 2
                && $report['tickets']['checked_in'] === 1 && $report['tickets']['refund_required'] === 1
                && $report['money']['excluding_required_refunds'] === '1.05' && $report['buyers'] === 1)
            ->assertDontSee('private-secret');
    }

    public function testParticipantsAreScopedFilterableAndPaginated(): void
    {
        [$buyer, $event, , $order, $ticket] = $this->purchase();
        [, , , , $foreign] = $this->purchase();
        $order->tickets()->where('id', '!=', $ticket->id)->update(['status' => 'used', 'checked_in_at' => now()]);
        $base = '/organizer/events/'.$event->id.'/reports/participants';
        $this->actingAs($event->organizer)->get($base.'?per_page=1')->assertOk()->assertSee($buyer->email)
            ->assertDontSee($foreign->number)->assertDontSee('private-secret')->assertSee('Далее');
        $this->get($base.'?per_page=1&page=2')->assertSee('Назад');
        $this->get($base.'?status=active')->assertViewHas('participants', fn ($rows) => $rows->count() === 1 && $rows->first()->id === $ticket->id);
        $this->get($base.'?status=cancelled')->assertSee('Билеты не найдены');
    }

    public function testSearchDoesNotRegisterEntryAndNeverFindsAnotherEventsTicket(): void
    {
        [, $event, , , $ticket] = $this->purchase();
        [$otherBuyer, , , , $foreign] = $this->purchase();
        $base = '/organizer/events/'.$event->id.'/reports/check-in';
        $this->actingAs($event->organizer)->get($base.'?number='.$ticket->number)->assertOk()
            ->assertSee('Зарегистрировать вход')->assertSee($ticket->number);
        $this->assertNull($ticket->fresh()->checked_in_at);
        $this->get($base.'?number='.$foreign->number)->assertOk()->assertSee('для этого мероприятия не найден')->assertDontSee($otherBuyer->email);
        $this->get($base.'?number='.(string) Str::uuid())->assertOk()->assertSee('для этого мероприятия не найден');
    }

    public function testCheckInRecordsActorAndRepeatedRequestCannotChangeOriginalTime(): void
    {
        [, $event, , , $ticket] = $this->purchase();
        $this->travelTo(now()->startOfSecond());
        $url = '/organizer/events/'.$event->id.'/reports/tickets/'.$ticket->id.'/check-in';
        $this->actingAs($event->organizer)->post($url)->assertRedirect()->assertSessionHas('check_in_success');
        $first = $ticket->fresh();
        $this->assertSame('used', $first->status);
        $this->assertSame($event->organizer_id, $first->checked_in_by);
        $this->assertTrue($first->checked_in_at->equalTo(now()));
        $this->get('/organizer/events/'.$event->id.'/reports/check-in?number='.$ticket->number)
            ->assertOk()->assertSee('Вход зарегистрирован')->assertDontSee('>Зарегистрировать вход<', false);
        $this->travel(1)->minutes();
        $this->post($url)->assertRedirect()->assertSessionHas('check_in_error');
        $this->assertTrue($ticket->fresh()->checked_in_at->equalTo($first->checked_in_at));
    }

    public function testForeignTicketBindingCannotRegisterEntry(): void
    {
        [, $event] = $this->purchase();
        [, , , , $foreign] = $this->purchase();
        $this->actingAs($event->organizer)->post('/organizer/events/'.$event->id.'/reports/tickets/'.$foreign->id.'/check-in')->assertNotFound();
        $this->assertNull($foreign->fresh()->checked_in_at);
    }

    public function testUnpaidCancelledAndRefundFlaggedTicketsCannotEnter(): void
    {
        [, $event, , $order, $ticket] = $this->purchase();
        $url = '/organizer/events/'.$event->id.'/reports/tickets/'.$ticket->id.'/check-in';
        $lookup = '/organizer/events/'.$event->id.'/reports/check-in?number='.$ticket->number;
        $this->actingAs($event->organizer);
        $order->update(['status' => 'pending']);
        $this->get($lookup)->assertOk()->assertDontSee('>Зарегистрировать вход<', false);
        $this->post($url)->assertSessionHas('check_in_error');
        $order->update(['status' => 'paid']);
        $ticket->update(['status' => 'cancelled']);
        $this->post($url)->assertSessionHas('check_in_error');
        $ticket->forceFill(['status' => 'active', 'refund_required' => true])->save();
        $this->get($lookup)->assertOk()->assertDontSee('>Зарегистрировать вход<', false);
        $this->post($url)->assertSessionHas('check_in_error');
        $this->assertNull($ticket->fresh()->checked_in_at);
    }

    public function testClosedOrEndedEventRejectsEntryButRetainsReports(): void
    {
        [, $event, , , $ticket] = $this->purchase();
        $url = '/organizer/events/'.$event->id.'/reports/tickets/'.$ticket->id.'/check-in';
        $this->actingAs($event->organizer);
        foreach (['draft', 'cancelled', 'finished'] as $status) {
            $event->update(['status' => $status]);
            $this->post($url)->assertSessionHas('check_in_error');
            $this->get('/organizer/events/'.$event->id.'/reports/sales')->assertOk();
        }
        $event->update(['status' => 'published', 'starts_at' => now()->subHours(2), 'ends_at' => now()->subHour()]);
        $this->post($url)->assertSessionHas('check_in_error');
        $this->assertNull($ticket->fresh()->checked_in_at);
    }

    public function testReportPermissionAndCheckInPermissionAreEnforcedSeparately(): void
    {
        [, $event, , , $ticket] = $this->purchase();
        $organizer = $event->organizer;
        $organizer->rbacRole->permissions()->detach(Permission::where('name', 'tickets.check-in')->value('id'));
        $this->actingAs($organizer)->get('/organizer/events/'.$event->id.'/reports/check-in?number='.$ticket->number)
            ->assertOk()->assertSee('нет права регистрировать вход')->assertDontSee('>Зарегистрировать вход<', false);
        $this->post('/organizer/events/'.$event->id.'/reports/tickets/'.$ticket->id.'/check-in')->assertForbidden();
        $organizer->rbacRole->permissions()->detach(Permission::where('name', 'events.reports')->value('id'));
        $this->get('/organizer/events/'.$event->id.'/reports/sales')->assertForbidden();
    }

    public function testBlockedOrganizerCannotReadOrCheckIn(): void
    {
        [, $event, , , $ticket] = $this->purchase();
        $organizer = $event->organizer;
        $organizer->forceFill(['is_blocked' => true])->save();
        $this->actingAs($organizer)->get('/organizer/events/'.$event->id.'/reports/participants')->assertForbidden();
        $this->post('/organizer/events/'.$event->id.'/reports/tickets/'.$ticket->id.'/check-in')->assertForbidden();
    }

    public function testTextIsEscapedAndInvalidSearchAndFiltersAreRejected(): void
    {
        [, $event, $type] = $this->purchase();
        $event->update(['title' => '<script>bad()</script>']);
        $type->update(['name' => '<img src=x onerror=bad()>']);
        $base = '/organizer/events/'.$event->id.'/reports';
        $this->actingAs($event->organizer)->get($base.'/sales')->assertSee('&lt;script&gt;bad()&lt;/script&gt;', false)
            ->assertDontSee('<script>bad()</script>', false)->assertDontSee('<img src=x onerror=bad()>', false);
        $this->getJson($base.'/check-in?number[]=bad')->assertUnprocessable();
        $this->getJson($base.'/check-in?number=unknown-test-ticket')->assertUnprocessable()->assertJsonValidationErrors('number');
        $this->get($base.'/check-in?number=unknown-test-ticket')->assertRedirect(route('organizer.reports.check-in', $event))->assertSessionHasErrors('number');
        $this->getJson($base.'/check-in?number='.str_repeat('a', 256))->assertUnprocessable();
        $this->getJson($base.'/participants?status=invalid')->assertUnprocessable();
        $this->followingRedirects()->get($base.'/check-in?number[]=bad')->assertOk()->assertSee('Введите номер билета текстом.');
    }

    public function testForgedCheckInFieldsAndCsrfAreRejected(): void
    {
        [, $event, , , $ticket] = $this->purchase();
        $url = '/organizer/events/'.$event->id.'/reports/tickets/'.$ticket->id.'/check-in';
        $this->actingAs($event->organizer)->postJson($url, ['status' => 'used', 'checked_in_by' => 999])->assertUnprocessable();
        $this->app->instance('env', 'local');
        $this->withMiddleware(PreventRequestForgery::class)->post($url)->assertStatus(419);
        $this->assertNull($ticket->fresh()->checked_in_at);
    }

    public function testEmptyReportAndParticipantsRender(): void
    {
        [, $event] = $this->createOrderContext();
        $this->actingAs($event->organizer)->get('/organizer/events/'.$event->id.'/reports/sales')->assertOk()->assertSee('0,00 UZS');
        $this->get('/organizer/events/'.$event->id.'/reports/participants')->assertOk()->assertSee('Билеты не найдены');
        $this->get('/organizer/events/'.$event->id.'/reports/check-in')->assertOk()->assertSee('Найти билет');
    }
}
