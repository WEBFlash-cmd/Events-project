<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Order;
use App\Models\User;
use App\Services\WaitlistService;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\Concerns\CreatesOrderContext;
use Tests\TestCase;

class OrganizerTicketTypePageTest extends TestCase
{
    use RefreshDatabase, CreatesOrderContext;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Queue::fake();
    }

    public function testOwnerCanCreateAndEditTypeOnDraft(): void
    {
        [, $event] = $this->createOrderContext();
        $event->update(['status' => 'draft']);
        $base = '/organizer/events/'.$event->id.'/ticket-types';
        $this->actingAs($event->organizer)->get($base)->assertOk()->assertSee('Standard')
            ->assertHeader('Cache-Control', 'no-store, private');
        $this->get($base.'/create')->assertOk()->assertSee('Новый тип билета');
        $this->post($base, ['name' => 'VIP', 'description' => 'Места у сцены', 'price' => '1.05', 'quantity' => 5])->assertRedirect();
        $type = $event->ticketTypes()->where('name', 'VIP')->firstOrFail();
        $this->get($base.'/'.$type->id.'/edit')->assertOk()->assertSee('1.05');
        $this->patch($base.'/'.$type->id, ['name' => 'VIP Plus', 'price' => '2.10'])->assertRedirect();
        $this->assertSame('VIP Plus', $type->fresh()->name);
        $this->assertSame('2.10', $type->fresh()->price);
        $this->assertSame(5, $type->fresh()->quantity);
    }

    public function testGuestsParticipantsAndOtherOrganizersAreDenied(): void
    {
        [$buyer, $event, $type] = $this->createOrderContext();
        $base = '/organizer/events/'.$event->id.'/ticket-types';
        $this->get($base)->assertRedirect('/login');
        foreach ([$buyer, User::factory()->create(['role' => UserRole::ORGANIZER])] as $actor) {
            $this->actingAs($actor)->get($base)->assertForbidden();
            $this->get($base.'/create')->assertForbidden();
            $this->get($base.'/'.$type->id.'/edit')->assertForbidden();
            $this->post($base, ['name' => 'Forbidden', 'price' => 1, 'quantity' => 1])->assertForbidden();
            $this->patch($base.'/'.$type->id, ['name' => 'Forbidden'])->assertForbidden();
            $this->patch($base.'/'.$type->id.'/quantity', ['quantity' => 1, 'expected_quantity' => 10])->assertForbidden();
            $this->post('/organizer/events/'.$event->id.'/publish')->assertForbidden();
            $this->post('/organizer/events/'.$event->id.'/cancel', ['confirm_cancel' => 1])->assertForbidden();
        }
    }

    public function testForeignTypeCannotBeBoundToAnotherEvent(): void
    {
        [, $event, $type] = $this->createOrderContext();
        $other = $event->replicate();
        $other->save();
        $base = '/organizer/events/'.$other->id.'/ticket-types/'.$type->id;
        $this->actingAs($event->organizer)->get($base.'/edit')->assertNotFound();
        $this->patch($base, ['name' => 'Forbidden'])->assertNotFound();
        $this->patch($base.'/quantity', ['quantity' => 1, 'expected_quantity' => 10])->assertNotFound();
    }

    public function testCapacityAndInvalidPriceAreRejectedWithRussianMessages(): void
    {
        [, $event] = $this->createOrderContext();
        $base = '/organizer/events/'.$event->id.'/ticket-types';
        $this->actingAs($event->organizer)->from($base.'/create')->post($base,
            ['name' => 'Too many', 'price' => '1.00', 'quantity' => 291])->assertSessionHasErrors('quantity');
        $this->assertSame(1, $event->ticketTypes()->count());
        $this->post($base, ['name' => 'Invalid', 'price' => '-0.01', 'quantity' => 0])->assertSessionHasErrors('price');
        $this->post($base, ['name' => 'Invalid', 'price' => '1.001', 'quantity' => 0])->assertSessionHasErrors('price');
        $this->post($base, ['name' => 'Invalid', 'price' => 1, 'quantity' => 0, 'event_id' => $event->id])->assertSessionHasErrors('event_id');
    }

    public function testMetadataFormDoesNotOverwriteStock(): void
    {
        [, $event, $type] = $this->createOrderContext();
        $type->update(['quantity' => 8]);
        $this->actingAs($event->organizer)->patch('/organizer/events/'.$event->id.'/ticket-types/'.$type->id,
            ['name' => 'New name', 'price' => '2.00', 'quantity' => 10])->assertRedirect();
        $this->assertSame(8, $type->fresh()->quantity);
        $this->assertSame('2.00', $type->fresh()->price);
    }

    public function testStaleStockIsRejectedAndFreshStockCanBeSaved(): void
    {
        [, $event, $type] = $this->createOrderContext();
        $url = '/organizer/events/'.$event->id.'/ticket-types/'.$type->id.'/quantity';
        $type->update(['quantity' => 9]);
        $this->actingAs($event->organizer)->patch($url, ['quantity' => 15, 'expected_quantity' => 10])
            ->assertSessionHasErrors('quantity');
        $this->assertSame(9, $type->fresh()->quantity);
        $this->patch($url, ['quantity' => 15, 'expected_quantity' => 9])->assertRedirect();
        $this->assertSame(15, $type->fresh()->quantity);
        $this->patch($url, ['quantity' => 20])->assertSessionHasErrors('expected_quantity');
    }

    public function testCapacityIncludesReservationsAndStockSaveIsAtomic(): void
    {
        [$buyer, $event, $type] = $this->createOrderContext('1.05');
        $event->venue->update(['capacity' => 10]);
        $order = Order::create(['user_id' => $buyer->id, 'event_id' => $event->id, 'status' => 'pending', 'total_amount' => '2.10', 'expires_at' => now()->addMinutes(10)]);
        $order->items()->create(['ticket_type_id' => $type->id, 'quantity' => 2, 'unit_price' => '1.05']);
        $type->update(['quantity' => 8]);
        $url = '/organizer/events/'.$event->id.'/ticket-types/'.$type->id.'/quantity';
        $this->actingAs($event->organizer)->patch($url, ['quantity' => 9, 'expected_quantity' => 8])->assertSessionHasErrors('quantity');
        $this->assertSame(8, $type->fresh()->quantity);
        $this->assertSame('pending', $order->fresh()->status);
    }

    public function testStockIncreaseImmediatelyOffersSeatToWaitingParticipant(): void
    {
        [$buyer, $event, $type] = $this->createOrderContext('1.05');
        $type->update(['quantity' => 0]);
        $entry = app(WaitlistService::class)->join($event, $type, $buyer);
        $this->actingAs($event->organizer)->patch('/organizer/events/'.$event->id.'/ticket-types/'.$type->id.'/quantity',
            ['quantity' => 1, 'expected_quantity' => 0])->assertRedirect();
        $this->assertSame('offered', $entry->fresh()->status);
        $this->assertSame(0, $type->fresh()->quantity);
    }

    public function testPublishDraftMakesItPublicAndRepeatDoesNotChangeState(): void
    {
        [, $event] = $this->createOrderContext();
        $event->update(['status' => 'draft']);
        $url = '/organizer/events/'.$event->id.'/publish';
        $this->actingAs($event->organizer)->get('/organizer/events/'.$event->id.'/edit')->assertSee('Опубликовать в афише');
        $this->post($url)->assertRedirect(route('organizer.events.edit', $event));
        $this->assertSame('published', $event->fresh()->status->value);
        $this->get('/events/'.$event->id)->assertOk();
        $this->post($url)->assertSessionHasErrors('status');
        $this->assertSame('published', $event->fresh()->status->value);
    }

    public function testPastDraftCannotBePublished(): void
    {
        [, $event] = $this->createOrderContext();
        $event->update(['status' => 'draft', 'starts_at' => now()->subMinute()]);
        $this->actingAs($event->organizer)->post('/organizer/events/'.$event->id.'/publish')->assertSessionHasErrors('starts_at');
        $this->assertSame('draft', $event->fresh()->status->value);
    }

    public function testCancellationRequiresConfirmationThenReleasesReservationsAndMarksRefund(): void
    {
        [$buyer, $event, $type] = $this->createOrderContext('1.05');
        $pending = Order::create(['user_id' => $buyer->id, 'event_id' => $event->id, 'status' => 'pending', 'total_amount' => '1.05', 'expires_at' => now()->addMinutes(10)]);
        $pending->items()->create(['ticket_type_id' => $type->id, 'quantity' => 1, 'unit_price' => '1.05']);
        $paid = Order::create(['user_id' => $buyer->id, 'event_id' => $event->id, 'status' => 'paid', 'total_amount' => '1.05']);
        $item = $paid->items()->create(['ticket_type_id' => $type->id, 'quantity' => 1, 'unit_price' => '1.05']);
        $paid->payment()->create(['reference' => (string) Str::uuid(), 'cashbox_id' => 1, 'amount' => 105, 'status' => 'paid']);
        $ticket = $paid->tickets()->create(['number' => (string) Str::uuid(), 'user_id' => $buyer->id, 'event_id' => $event->id,
            'ticket_type_id' => $type->id, 'order_item_id' => $item->id, 'sequence' => 1, 'status' => 'active']);
        $type->decrement('quantity', 2);
        $url = '/organizer/events/'.$event->id.'/cancel';
        $this->actingAs($event->organizer)->post($url)->assertSessionHasErrors('confirm_cancel');
        $this->assertSame('published', $event->fresh()->status->value);
        $this->post($url, ['confirm_cancel' => 1])->assertRedirect();
        $this->assertSame('cancelled', $event->fresh()->status->value);
        $this->assertSame('cancelled', $pending->fresh()->status);
        $this->assertSame(9, $type->fresh()->quantity);
        $this->assertSame(105, $paid->payment->fresh()->refund_amount);
        $this->assertTrue($ticket->fresh()->refund_required);
        $this->assertSame('cancelled', $ticket->fresh()->status);
        $this->post($url, ['confirm_cancel' => 1])->assertRedirect();
        $this->assertSame(9, $type->fresh()->quantity);
        $this->get('/events/'.$event->id)->assertNotFound();
    }

    public function testEndedEventCancellationHasReadableError(): void
    {
        [, $event] = $this->createOrderContext();
        $event->update(['starts_at' => now()->subHours(2), 'ends_at' => now()->subHour()]);
        $this->actingAs($event->organizer)->post('/organizer/events/'.$event->id.'/cancel', ['confirm_cancel' => 1])
            ->assertRedirect()->assertSessionHas('event_error');
        $this->assertSame('published', $event->fresh()->status->value);
    }

    public function testAdminCanManageAndBlockedOrganizerCannot(): void
    {
        [, $event, $type] = $this->createOrderContext();
        $url = '/organizer/events/'.$event->id.'/ticket-types/'.$type->id;
        $this->actingAs(User::factory()->create(['role' => UserRole::ADMIN]))->get($url.'/edit')->assertOk();
        $this->patch($url, ['price' => '9.99'])->assertRedirect();
        $organizer = $event->organizer;
        $organizer->forceFill(['is_blocked' => true])->save();
        $this->actingAs($organizer)->get($url.'/edit')->assertForbidden();
        $this->post('/organizer/events/'.$event->id.'/publish')->assertForbidden();
        $this->post('/organizer/events/'.$event->id.'/cancel', ['confirm_cancel' => 1])->assertForbidden();
    }

    public function testTextIsEscapedAndTypesArePaginated(): void
    {
        [, $event, $type] = $this->createOrderContext();
        $type->update(['name' => '<script>bad()</script>']);
        for ($i = 0; $i < 12; $i++) {
            $event->ticketTypes()->create(['name' => 'Type '.$i, 'price' => '1.00', 'quantity' => 0]);
        }
        $this->actingAs($event->organizer)->get('/organizer/events/'.$event->id.'/ticket-types')->assertOk()
            ->assertSee('&lt;script&gt;bad()&lt;/script&gt;', false)->assertDontSee('<script>bad()</script>', false)
            ->assertViewHas('types', fn ($types) => $types->count() === 12)->assertSee('Далее');
    }

    public function testAllMutationsRequireCsrf(): void
    {
        [, $event, $type] = $this->createOrderContext();
        $base = '/organizer/events/'.$event->id;
        $this->actingAs($event->organizer);
        $this->app->instance('env', 'local');
        $this->withMiddleware(PreventRequestForgery::class)->post($base.'/ticket-types', ['name' => 'Test', 'price' => 1, 'quantity' => 1])->assertStatus(419);
        $this->patch($base.'/ticket-types/'.$type->id, ['name' => 'Test'])->assertStatus(419);
        $this->patch($base.'/ticket-types/'.$type->id.'/quantity', ['quantity' => 1, 'expected_quantity' => 10])->assertStatus(419);
        $this->post($base.'/publish')->assertStatus(419);
        $this->post($base.'/cancel', ['confirm_cancel' => 1])->assertStatus(419);
    }
}
