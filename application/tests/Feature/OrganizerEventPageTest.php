<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Event;
use App\Models\Order;
use App\Models\User;
use App\Models\Venue;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Concerns\CreatesOrderContext;
use Tests\TestCase;

class OrganizerEventPageTest extends TestCase
{
    use RefreshDatabase, CreatesOrderContext;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    private function payload(Event $event): array
    {
        return ['title' => 'Новое мероприятие', 'description' => 'Подробное описание',
            'starts_at' => now()->addDays(5)->setTimezone('Asia/Tashkent')->format('Y-m-d\TH:i:s'),
            'ends_at' => now()->addDays(5)->addHours(2)->setTimezone('Asia/Tashkent')->format('Y-m-d\TH:i:s'),
            'venue_id' => $event->venue_id, 'category_id' => $event->category_id,
            'allows_ticket_cancellation' => '1'];
    }

    public function testGuestsMustLogInAndParticipantsCannotUseOrganizerPages(): void
    {
        [$buyer, $event] = $this->createOrderContext();
        foreach (['/organizer/events', '/organizer/events/create', '/organizer/events/'.$event->id.'/edit'] as $url) {
            $this->get($url)->assertRedirect('/login');
        }
        $this->actingAs($buyer);
        foreach (['/organizer/events', '/organizer/events/create', '/organizer/events/'.$event->id.'/edit'] as $url) {
            $this->get($url)->assertForbidden();
        }
        $this->post('/organizer/events', $this->payload($event))->assertForbidden();
        $this->patch('/organizer/events/'.$event->id, ['title' => 'Forbidden'])->assertForbidden();
        $this->get('/account')->assertDontSee('>Организатор</a>', false);
    }

    public function testOrganizerSeesOnlyOwnEventsInEveryStatus(): void
    {
        [, $event] = $this->createOrderContext();
        [, $other] = $this->createOrderContext();
        $other->update(['title' => 'Чужой черновик', 'status' => 'draft']);
        foreach (['draft', 'cancelled', 'finished'] as $status) {
            $copy = $event->replicate();
            $copy->title = 'Своё '.$status;
            $copy->status = $status;
            $copy->save();
        }
        $this->actingAs($event->organizer)->get('/organizer/events')->assertOk()
            ->assertSee($event->title)->assertSee('Своё draft')->assertSee('Своё cancelled')->assertSee('Своё finished')
            ->assertDontSee('Чужой черновик')->assertHeader('Cache-Control', 'no-store, private');
        $this->get('/account')->assertSee(route('organizer.events.index'));
    }

    public function testEmptyListAndCreateFormHaveReferenceOptions(): void
    {
        [, $event] = $this->createOrderContext();
        $organizer = User::factory()->create(['role' => UserRole::ORGANIZER]);
        $this->actingAs($organizer)->get('/organizer/events')->assertSee('Мероприятий пока нет');
        $this->get('/organizer/events/create')->assertOk()->assertSee($event->category->name)->assertSee($event->venue->name)
            ->assertSee('Создать черновик')->assertSee('Начало (Ташкент)')->assertHeader('Cache-Control', 'no-store, private');
    }

    public function testCreationAssignsOwnerAndDraftAndPreservesDatesAndCancellationSetting(): void
    {
        [, $event] = $this->createOrderContext();
        $payload = $this->payload($event);
        $this->actingAs($event->organizer)->post('/organizer/events', $payload)->assertRedirect();
        $created = Event::query()->latest('id')->firstOrFail();
        $this->assertSame($event->organizer_id, $created->organizer_id);
        $this->assertSame('draft', $created->status->value);
        $this->assertSame($payload['starts_at'], $created->starts_at->copy()->setTimezone('Asia/Tashkent')->format('Y-m-d\TH:i:s'));
        $this->assertTrue($created->allows_ticket_cancellation);
        $this->get('/organizer/events/'.$created->id.'/edit')->assertSee('Черновик мероприятия создан.')->assertSee('Новое мероприятие');
        $this->get('/events/'.$created->id)->assertNotFound();
    }

    public function testCreationRejectsInvalidDatesMissingReferencesAndForgedOwnership(): void
    {
        [, $event] = $this->createOrderContext();
        $this->actingAs($event->organizer)->from('/organizer/events/create')
            ->post('/organizer/events', array_replace($this->payload($event), [
                'title' => '', 'starts_at' => now()->subDay()->toDateTimeString(), 'ends_at' => now()->subDays(2)->toDateTimeString(),
                'category_id' => 999999, 'venue_id' => 999999, 'organizer_id' => 1, 'status' => 'published',
            ]))->assertRedirect('/organizer/events/create')
            ->assertSessionHasErrors(['title', 'starts_at', 'ends_at', 'category_id', 'venue_id', 'organizer_id', 'status']);
        $this->assertDatabaseCount('events', 1);
    }

    public function testValidationKeepsEnteredValues(): void
    {
        [, $event] = $this->createOrderContext();
        $this->actingAs($event->organizer)->followingRedirects()->from('/organizer/events/create')
            ->post('/organizer/events', array_replace($this->payload($event), ['description' => '']))
            ->assertSee('Не удалось сохранить мероприятие.')->assertSee('Новое мероприятие');
    }

    public function testOwnerCanEditAndUpdateWithoutPublishing(): void
    {
        [, $event] = $this->createOrderContext();
        $event->update(['status' => 'draft']);
        $this->actingAs($event->organizer)->get('/organizer/events/'.$event->id.'/edit')->assertOk()->assertSee($event->title);
        $this->patch('/organizer/events/'.$event->id, $this->payload($event))->assertRedirect(route('organizer.events.edit', $event))
            ->assertSessionHas('event_success');
        $this->assertSame('Новое мероприятие', $event->fresh()->title);
        $this->assertSame('draft', $event->fresh()->status->value);
    }

    public function testOtherOrganizerCannotReadEditFormOrUpdate(): void
    {
        [, $event] = $this->createOrderContext();
        $this->actingAs(User::factory()->create(['role' => UserRole::ORGANIZER]))
            ->get('/organizer/events/'.$event->id.'/edit')->assertForbidden();
        $this->patch('/organizer/events/'.$event->id, ['title' => 'Чужое'])->assertForbidden();
        $this->assertSame('Test event', $event->fresh()->title);
    }

    public function testAdminUpdateUsesExistingPolicy(): void
    {
        [, $event] = $this->createOrderContext();
        $this->actingAs(User::factory()->create(['role' => UserRole::ADMIN]))
            ->get('/organizer/events/'.$event->id.'/edit')->assertOk();
        $this->patch('/organizer/events/'.$event->id, ['title' => 'Администратор исправил'])->assertRedirect();
        $this->assertSame('Администратор исправил', $event->fresh()->title);
    }

    public function testSalesLockProtectedFieldsButAllowDescriptionChanges(): void
    {
        [$buyer, $event] = $this->createOrderContext();
        Order::create(['event_id' => $event->id, 'user_id' => $buyer->id, 'status' => 'paid', 'total_amount' => '1.00']);
        $this->actingAs($event->organizer)->get('/organizer/events/'.$event->id.'/edit')
            ->assertOk()->assertViewHas('locked', true)->assertSee('Билеты уже продавались.');
        $this->patch('/organizer/events/'.$event->id, ['title' => 'Обновлённое название', 'description' => 'Обновлённое описание',
            'category_id' => $event->category_id])->assertRedirect();
        $this->assertSame('Обновлённое название', $event->fresh()->title);
        $this->assertTrue($event->starts_at->equalTo($event->fresh()->starts_at));
        $this->patch('/organizer/events/'.$event->id, ['starts_at' => now()->addDays(9)->setTimezone('Asia/Tashkent')->format('Y-m-d\TH:i:s'),
            'ends_at' => now()->addDays(9)->addHour()->setTimezone('Asia/Tashkent')->format('Y-m-d\TH:i:s'), 'allows_ticket_cancellation' => '1'])
            ->assertSessionHasErrors(['starts_at', 'ends_at', 'allows_ticket_cancellation']);
    }

    public function testStartedEventCanStillSaveTextWhenDisabledStartIsOmitted(): void
    {
        [, $event] = $this->createOrderContext();
        $event->update(['starts_at' => now()->subHour(), 'ends_at' => now()->addHour()]);
        $this->actingAs($event->organizer)->get('/organizer/events/'.$event->id.'/edit')->assertSee('Начало уже наступило');
        $this->patch('/organizer/events/'.$event->id, ['title' => 'Исправлено', 'ends_at' => $event->ends_at->copy()->setTimezone('Asia/Tashkent')->format('Y-m-d\TH:i:s')])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame('Исправлено', $event->fresh()->title);
    }

    public function testSmallVenueAndInvalidDateOrderAreRejectedWithoutPartialSave(): void
    {
        [, $event] = $this->createOrderContext();
        $small = Venue::create(['name' => 'Small', 'address' => 'Tashkent', 'capacity' => 1]);
        $this->actingAs($event->organizer)->patch('/organizer/events/'.$event->id, ['title' => 'Не сохранять', 'venue_id' => $small->id])
            ->assertSessionHasErrors('venue_id');
        $this->assertSame('Test event', $event->fresh()->title);
        $this->patch('/organizer/events/'.$event->id, ['ends_at' => $event->starts_at->copy()->subHour()->setTimezone('Asia/Tashkent')->format('Y-m-d\TH:i:s')])
            ->assertSessionHasErrors('ends_at');
    }

    public function testBlockedOrganizerCannotReadOrSave(): void
    {
        [, $event] = $this->createOrderContext();
        $organizer = $event->organizer;
        $organizer->forceFill(['is_blocked' => true])->save();
        $this->actingAs($organizer)->get('/organizer/events')->assertForbidden();
        $this->post('/organizer/events', $this->payload($event))->assertForbidden();
        $this->patch('/organizer/events/'.$event->id, ['title' => 'Blocked'])->assertForbidden();
    }

    public function testTextIsEscapedInListAndForm(): void
    {
        [, $event] = $this->createOrderContext();
        $event->update(['title' => '<script>bad()</script>', 'description' => '</textarea><script>bad()</script>']);
        $this->actingAs($event->organizer);
        foreach (['/organizer/events', '/organizer/events/'.$event->id.'/edit'] as $url) {
            $this->get($url)->assertSee('&lt;script&gt;bad()&lt;/script&gt;', false)->assertDontSee('<script>bad()</script>', false);
        }
    }

    public function testEventsArePaginatedNewestFirst(): void
    {
        [, $event] = $this->createOrderContext();
        for ($i = 0; $i < 12; $i++) {
            $copy = $event->replicate();
            $copy->save();
        }
        $this->actingAs($event->organizer)->get('/organizer/events')->assertOk()->assertSee('Далее')
            ->assertViewHas('events', fn ($events) => $events->count() === 12 && $events->first()->id === $copy->id);
        $this->get('/organizer/events?page=2')->assertViewHas('events', fn ($events) => $events->count() === 1);
    }

    public function testCreateAndUpdateRequireCsrf(): void
    {
        [, $event] = $this->createOrderContext();
        $this->actingAs($event->organizer);
        $this->app->instance('env', 'local');
        $this->withMiddleware(PreventRequestForgery::class)->post('/organizer/events', $this->payload($event))->assertStatus(419);
        $this->patch('/organizer/events/'.$event->id, ['title' => 'CSRF'])->assertStatus(419);
        $this->assertDatabaseCount('events', 1);
    }

    public function testTashkentDatesConvertToUtcAcrossMidnightAndDoNotShiftOnResave(): void
    {
        $this->travelTo(Carbon::parse('2030-01-01 10:00:00', 'UTC'));
        [, $template] = $this->createOrderContext();
        $payload = array_replace($this->payload($template), ['starts_at' => '2030-01-02T01:00', 'ends_at' => '2030-01-02T03:00']);
        $this->actingAs($template->organizer)->post('/organizer/events', $payload)->assertRedirect()->assertSessionHasNoErrors();
        $event = Event::query()->latest('id')->firstOrFail();
        $this->assertSame('2030-01-01 20:00:00', $event->starts_at->format('Y-m-d H:i:s'));
        $this->assertSame('2030-01-01 22:00:00', $event->ends_at->format('Y-m-d H:i:s'));
        $this->get('/organizer/events/'.$event->id.'/edit')->assertOk()->assertSee('2030-01-02T01:00:00', false);
        $this->patch('/organizer/events/'.$event->id, $payload)->assertRedirect()->assertSessionHasNoErrors();
        $this->assertTrue($event->starts_at->equalTo($event->fresh()->starts_at));
        $this->assertTrue($event->ends_at->equalTo($event->fresh()->ends_at));
    }

    public function testPastTimeIsRejectedAfterConversionAndFlashKeepsLocalInput(): void
    {
        $this->travelTo(Carbon::parse('2030-01-01 10:00:00', 'UTC'));
        [, $event] = $this->createOrderContext();
        $payload = array_replace($this->payload($event), ['starts_at' => '2030-01-01T14:00:00', 'ends_at' => '2030-01-01T18:00:00']);
        $this->actingAs($event->organizer)->from('/organizer/events/create')->post('/organizer/events', $payload)
            ->assertRedirect('/organizer/events/create')->assertSessionHasErrors('starts_at')
            ->assertSessionHasInput('starts_at', '2030-01-01T14:00:00');
        $this->assertDatabaseCount('events', 1);
    }

    public function testPartialEndUpdateComparesUtcWithPersistedStart(): void
    {
        $this->travelTo(Carbon::parse('2030-01-01 10:00:00', 'UTC'));
        [, $event] = $this->createOrderContext();
        $event->update(['starts_at' => '2030-01-02 10:00:00', 'ends_at' => '2030-01-02 12:00:00']);
        $this->actingAs($event->organizer)->patch('/organizer/events/'.$event->id, ['ends_at' => '2030-01-02T14:00'])
            ->assertSessionHasErrors('ends_at');
        $this->assertSame('2030-01-02 12:00:00', $event->fresh()->ends_at->format('Y-m-d H:i:s'));
        $this->patch('/organizer/events/'.$event->id, ['ends_at' => '2030-01-02T16:00'])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame('2030-01-02 11:00:00', $event->fresh()->ends_at->format('Y-m-d H:i:s'));
    }
}
