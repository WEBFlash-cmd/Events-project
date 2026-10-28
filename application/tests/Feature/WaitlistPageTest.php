<?php

namespace Tests\Feature;

use App\Mail\WaitlistOffer;
use App\Models\User;
use App\Services\WaitlistService;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\Concerns\CreatesOrderContext;
use Tests\TestCase;

class WaitlistPageTest extends TestCase
{
    use RefreshDatabase, CreatesOrderContext;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->configurePaymentGateway();
        Queue::fake();
        Http::fake(['payments.test/*' => Http::response(['data' => ['url' => 'http://payments.test/process-payment/100']], 201)]);
    }

    private function context(): array
    {
        [$buyer, $event, $type] = $this->createOrderContext('1.05');
        $type->update(['quantity' => 0]);

        return [$buyer, $event, $type];
    }

    private function offer(): array
    {
        [$buyer, $event, $type] = $this->context();
        $entry = app(WaitlistService::class)->join($event, $type, $buyer);
        $type->update(['quantity' => 1]);
        app(WaitlistService::class)->process($event);

        return [$buyer, $event, $type, $entry->fresh()];
    }

    public function testGuestSeesLoginLinkForSoldOutPaidTicketsAndMustAuthenticate(): void
    {
        [, $event, $type] = $this->context();
        $this->get('/events/'.$event->id)->assertOk()->assertSee('Войти для записи в очередь');
        $this->get('/waitlist')->assertRedirect('/login');
        foreach (['join', 'leave', 'accept'] as $action) {
            $this->post(route('waitlist.'.$action, [$event, $type]))->assertRedirect('/login');
        }
    }

    public function testEmptyPageAndEventDoNotOfferQueueForAvailableFreeOrStartedTickets(): void
    {
        [$buyer, $event, $type] = $this->createOrderContext();
        $this->actingAs($buyer)->get('/waitlist')->assertOk()->assertSee('Вы пока не в очереди');
        $this->get('/events/'.$event->id)->assertDontSee('Встать в очередь');
        $type->update(['quantity' => 0, 'price' => '0.00']);
        $this->get('/events/'.$event->id)->assertDontSee('Встать в очередь');
        $type->update(['price' => '1.05']);
        $event->update(['starts_at' => now()->subMinute()]);
        $this->get('/events/'.$event->id)->assertDontSee('Встать в очередь');
    }

    public function testJoinIsIdempotentAndEventShowsExistingApplication(): void
    {
        [$buyer, $event, $type] = $this->context();
        $url = route('waitlist.join', [$event, $type]);
        $this->actingAs($buyer)->get('/events/'.$event->id)->assertSee('Встать в очередь');
        $this->post($url)->assertRedirect(route('waitlist.index'))->assertSessionHas('waitlist_success');
        $this->post($url)->assertRedirect();
        $this->assertDatabaseCount('waitlist_entries', 1);
        $this->get('/events/'.$event->id)->assertSee('Посмотреть заявку')->assertDontSee('Встать в очередь')
            ->assertHeader('Cache-Control', 'no-store, private');
        $this->get('/waitlist')->assertSee('Ожидаем свободное место')->assertHeader('Cache-Control', 'no-store, private');
    }

    public function testOfferUsesReservedPriceAndRepeatedAcceptanceUsesSameOrderAndPayment(): void
    {
        [$buyer, $event, $type, $entry] = $this->offer();
        $type->update(['price' => '999.00']);
        $this->actingAs($buyer)->get('/waitlist')->assertOk()->assertSee('1,05 UZS')->assertSee('Принять и перейти к оплате');
        $this->get('/orders/'.$entry->order_id)->assertSee('Открыть лист ожидания');
        $url = route('waitlist.accept', [$event, $type]);
        $this->post($url)->assertRedirect(route('checkout.order', $entry->order_id));
        $this->post($url)->assertRedirect(route('checkout.order', $entry->order_id));
        $this->assertSame($entry->order_id, session('checkout_order_id'));
        $this->assertDatabaseCount('orders', 1);
        $this->assertDatabaseCount('payments', 1);
        $this->assertSame(0, $type->fresh()->quantity);
        $this->assertSame(105, $entry->order->payment->amount);
        Http::assertSentCount(1);
    }

    public function testLeavingOfferTransfersSeatToNextWaitingUser(): void
    {
        [$buyer, $event, $type, $entry] = $this->offer();
        $second = app(WaitlistService::class)->join($event, $type, User::factory()->create());
        $url = route('waitlist.leave', [$event, $type]);
        $this->actingAs($buyer)->post($url)->assertRedirect(route('waitlist.index'));
        $this->post($url)->assertRedirect();
        $this->assertSame('cancelled', $entry->fresh()->status);
        $this->assertSame('cancelled', $entry->order->fresh()->status);
        $this->assertSame('offered', $second->fresh()->status);
        $this->assertSame(0, $type->fresh()->quantity);
        Http::assertNothingSent();
    }

    public function testOtherUserCannotSeeOrAcceptOrLeaveAnOffer(): void
    {
        [, $event, $type, $entry] = $this->offer();
        $this->actingAs(User::factory()->create())->get('/waitlist')->assertDontSee($event->title);
        $this->post(route('waitlist.accept', [$event, $type]))->assertNotFound();
        $this->post(route('waitlist.leave', [$event, $type]))->assertNotFound();
        $this->assertSame('offered', $entry->fresh()->status);
        Http::assertNothingSent();
    }

    public function testExpiredOfferIsHiddenAndCannotBeAccepted(): void
    {
        [$buyer, $event, $type, $entry] = $this->offer();
        $entry->order->update(['expires_at' => now()->subSecond()]);
        $this->actingAs($buyer)->get('/waitlist')->assertSee('Срок предложения истёк')->assertDontSee('Принять и перейти к оплате');
        $this->post(route('waitlist.accept', [$event, $type]))->assertRedirect()->assertSessionHas('waitlist_error');
        $this->assertSame('expired', $entry->fresh()->status);
        $this->assertSame(1, $type->fresh()->quantity);
        $this->assertDatabaseCount('payments', 0);
        Http::assertNothingSent();
    }

    public function testClosedEventHidesOfferAndRejectsAcceptance(): void
    {
        [$buyer, $event, $type] = $this->offer();
        $event->update(['status' => 'cancelled']);
        $this->actingAs($buyer)->get('/waitlist')->assertSee('Заявка закрыта')->assertDontSee('Принять и перейти к оплате');
        $this->post(route('waitlist.accept', [$event, $type]))->assertRedirect()->assertSessionHas('waitlist_error');
        Http::assertNothingSent();
    }

    public function testPaidOfferShowsPurchasedAndRedirectsToExistingOrder(): void
    {
        [$buyer, $event, $type, $entry] = $this->offer();
        app(WaitlistService::class)->accept($event, $type, $buyer);
        $entry->refresh();
        $entry->order->update(['status' => 'paid']);
        $entry->order->payment->update(['status' => 'paid']);
        $this->actingAs($buyer)->get('/waitlist')->assertSee('Билет куплен')->assertDontSee('Подтверждаю выход');
        $this->post(route('waitlist.accept', [$event, $type]))->assertRedirect(route('checkout.order', $entry->order_id));
        Http::assertNothingSent();
    }

    public function testInvalidJoinAndUnconfiguredPaymentHaveReadableMessages(): void
    {
        [$buyer, $event, $type, $entry] = $this->offer();
        config(['payments.secret' => '']);
        $this->actingAs($buyer)->post(route('waitlist.accept', [$event, $type]))->assertRedirect()
            ->assertSessionHas('waitlist_error', 'Оплата временно недоступна. Попробуйте принять предложение позже, пока действует резерв.');
        $this->assertSame('pending', $entry->order->fresh()->status);
        $this->assertDatabaseCount('payments', 0);
        [$otherBuyer, $otherEvent, $otherType] = $this->createOrderContext();
        $this->actingAs($otherBuyer)->post(route('waitlist.join', [$otherEvent, $otherType]))->assertRedirect()->assertSessionHas('waitlist_error');
    }

    public function testForeignTypeAndForgedFieldsAreRejected(): void
    {
        [$buyer, $event, $type] = $this->context();
        [, $otherEvent] = $this->createOrderContext();
        $this->actingAs($buyer)->post(route('waitlist.join', [$otherEvent, $type]))->assertNotFound();
        $this->postJson(route('waitlist.join', [$event, $type]), ['user_id' => 99, 'status' => 'offered', 'price' => 1])->assertUnprocessable();
        $this->assertDatabaseCount('waitlist_entries', 0);
    }

    public function testBlockedUserCannotReadOrChangeWaitlist(): void
    {
        [$buyer, $event, $type] = $this->context();
        $buyer->forceFill(['is_blocked' => true])->save();
        $this->actingAs($buyer)->get('/waitlist')->assertForbidden();
        $this->post(route('waitlist.join', [$event, $type]))->assertForbidden();
    }

    public function testEscapedTextAndOfferEmailLink(): void
    {
        [$buyer, $event, , $entry] = $this->offer();
        $event->update(['title' => '<script>bad()</script>']);
        $this->actingAs($buyer)->get('/waitlist')->assertSee('&lt;script&gt;bad()&lt;/script&gt;', false)
            ->assertDontSee('<script>bad()</script>', false);
        $mail = (new WaitlistOffer($entry->fresh()->load(['event', 'ticketType', 'order'])))->render();
        $this->assertStringContainsString(route('waitlist.index'), $mail);
    }

    public function testApplicationsArePaginated(): void
    {
        [$buyer] = $this->context();
        for ($i = 0; $i < 13; $i++) {
            [, $event, $type] = $this->context();
            app(WaitlistService::class)->join($event, $type, $buyer);
        }
        $this->actingAs($buyer)->get('/waitlist')->assertOk()->assertViewHas('entries', fn ($entries) => $entries->count() === 12)->assertSee('Далее');
        $this->get('/waitlist?page=2')->assertViewHas('entries', fn ($entries) => $entries->count() === 1)->assertSee('Назад');
    }

    public function testAllActionsRequireCsrf(): void
    {
        [$buyer, $event, $type] = $this->context();
        $this->actingAs($buyer);
        $this->app->instance('env', 'local');
        foreach (['join', 'leave', 'accept'] as $action) {
            $this->withMiddleware(PreventRequestForgery::class)->post(route('waitlist.'.$action, [$event, $type]))->assertStatus(419);
        }
        $this->assertDatabaseCount('waitlist_entries', 0);
    }
}
