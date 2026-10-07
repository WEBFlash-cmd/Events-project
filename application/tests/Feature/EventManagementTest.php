<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Enums\EventStatus;
use App\Events\EventPublished;
use App\Models\Category;
use App\Models\Event;
use App\Models\User;
use App\Models\Venue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event as EventBus;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class EventManagementTest extends TestCase
{
    use RefreshDatabase;

    public function testGuestCanListOnlyPublishedEventsWithRelations(): void
    {
        $owner = User::factory()->create(['role' => UserRole::ORGANIZER]);
        $published = $this->createEventFor($owner);
        $published->update(['status' => EventStatus::PUBLISHED]);

        foreach (['draft', 'cancelled', 'finished'] as $status) {
            $this->createEventFor($owner)->update(['status' => $status]);
        }

        $this->getJson('/api/events')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('total', 1)
            ->assertJsonPath('data.0.id', $published->id)
            ->assertJsonPath('data.0.venue.id', $published->venue_id)
            ->assertJsonPath('data.0.category.id', $published->category_id);
    }

    public function testPublicEventListIsSortedAndPaginated(): void
    {
        $this->freezeTime();
        $owner = User::factory()->create(['role' => UserRole::ORGANIZER]);
        $ids = [];

        foreach (range(16, 1) as $day) {
            $event = $this->createEventFor($owner);
            $event->update([
                'status' => EventStatus::PUBLISHED,
                'starts_at' => now()->addDays($day),
                'ends_at' => now()->addDays($day)->addHours(2),
            ]);
            $ids[$day] = $event->id;
        }

        $this->getJson('/api/events')
            ->assertOk()
            ->assertJsonCount(15, 'data')
            ->assertJsonPath('total', 16)
            ->assertJsonPath('per_page', 15)
            ->assertJsonPath('data.0.id', $ids[1])
            ->assertJsonPath('data.14.id', $ids[15]);

        $this->getJson('/api/events?page=2')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $ids[16]);
    }

    public function testGuestCanViewPublishedEventWithRelations(): void
    {
        $event = $this->createEventFor(User::factory()->create(['role' => UserRole::ORGANIZER]));
        $event->update(['status' => EventStatus::PUBLISHED]);

        $this->getJson("/api/events/{$event->id}")
            ->assertOk()
            ->assertJsonPath('data.id', $event->id)
            ->assertJsonPath('data.status', 'published')
            ->assertJsonPath('data.venue.id', $event->venue_id)
            ->assertJsonPath('data.category.id', $event->category_id);
    }

    public static function hiddenPublicStatuses(): array
    {
        return [
            'draft' => ['draft'],
            'cancelled' => ['cancelled'],
            'finished' => ['finished'],
        ];
    }

    #[DataProvider('hiddenPublicStatuses')]
    public function testGuestCannotViewUnpublishedEvent(string $status): void
    {
        $event = $this->createEventFor(User::factory()->create(['role' => UserRole::ORGANIZER]));
        $event->update(['status' => $status]);

        $this->getJson("/api/events/{$event->id}")->assertNotFound();
    }

    public function testMissingPublicEventReturnsNotFound(): void
    {
        $this->getJson('/api/events/999999')->assertNotFound();
    }

    public function testEmptyPublicEventListReturnsEmptyPage(): void
    {
        $this->getJson('/api/events')
            ->assertOk()
            ->assertJsonCount(0, 'data')
            ->assertJsonPath('total', 0);
    }

    #[DataProvider('creatorRoles')]
    public function testAuthorizedUserCanPublishEvent(UserRole $role): void
    {
        $owner = User::factory()->create(['role' => UserRole::ORGANIZER]);
        $event = $this->createEventFor($owner);
        $actor = $role === UserRole::ORGANIZER
            ? $owner
            : User::factory()->create(['role' => $role]);
        Sanctum::actingAs($actor);
        EventBus::fake([EventPublished::class]);

        $this->postJson("/api/events/{$event->id}/publish")
            ->assertOk()->assertJsonPath('data.status', 'published');

        $this->assertDatabaseHas('events', [
            'id' => $event->id, 'status' => 'published', 'organizer_id' => $owner->id,
        ]);
        EventBus::assertDispatched(EventPublished::class, function (EventPublished $message) use ($event) {
            return $message->event->id === $event->id
                && $message->event->status === EventStatus::PUBLISHED;
        });
        EventBus::assertDispatchedTimes(EventPublished::class, 1);
    }

    public static function unauthorizedPublisherRoles(): array
    {
        return [
            'another organizer' => [UserRole::ORGANIZER],
            'participant' => [UserRole::PARTICIPANT],
        ];
    }

    #[DataProvider('unauthorizedPublisherRoles')]
    public function testUnauthorizedUserCannotPublishEvent(UserRole $role): void
    {
        $owner = User::factory()->create(['role' => UserRole::ORGANIZER]);
        $event = $this->createEventFor($owner);
        Sanctum::actingAs(User::factory()->create(['role' => $role]));
        EventBus::fake([EventPublished::class]);

        $this->postJson("/api/events/{$event->id}/publish")->assertForbidden();

        $this->assertDatabaseHas('events', ['id' => $event->id, 'status' => 'draft']);
        EventBus::assertNotDispatched(EventPublished::class);
    }

    public function testGuestCannotPublishEvent(): void
    {
        $event = $this->createEventFor(User::factory()->create(['role' => UserRole::ORGANIZER]));
        EventBus::fake([EventPublished::class]);

        $this->postJson("/api/events/{$event->id}/publish")->assertUnauthorized();

        $this->assertDatabaseHas('events', ['id' => $event->id, 'status' => 'draft']);
        EventBus::assertNotDispatched(EventPublished::class);
    }

    public static function nonDraftStatuses(): array
    {
        return [
            'published' => ['published'],
            'cancelled' => ['cancelled'],
            'finished' => ['finished'],
        ];
    }

    #[DataProvider('nonDraftStatuses')]
    public function testCannotPublishNonDraftEvent(string $status): void
    {
        $owner = User::factory()->create(['role' => UserRole::ORGANIZER]);
        $event = $this->createEventFor($owner);
        $event->update(['status' => $status]);
        Sanctum::actingAs($owner);
        EventBus::fake([EventPublished::class]);

        $this->postJson("/api/events/{$event->id}/publish")
            ->assertUnprocessable()->assertJsonValidationErrors(['status']);

        $this->assertDatabaseHas('events', ['id' => $event->id, 'status' => $status]);
        EventBus::assertNotDispatched(EventPublished::class);
    }

    public static function startedEventOffsets(): array
    {
        return ['in past' => [-1], 'right now' => [0]];
    }

    #[DataProvider('startedEventOffsets')]
    public function testCannotPublishEventThatHasStarted(int $hours): void
    {
        $this->freezeTime();
        $owner = User::factory()->create(['role' => UserRole::ORGANIZER]);
        $event = $this->createEventFor($owner);
        $event->update(['starts_at' => now()->addHours($hours)]);
        Sanctum::actingAs($owner);
        EventBus::fake([EventPublished::class]);

        $this->postJson("/api/events/{$event->id}/publish")
            ->assertUnprocessable()->assertJsonValidationErrors(['starts_at']);

        $this->assertDatabaseHas('events', ['id' => $event->id, 'status' => 'draft']);
        EventBus::assertNotDispatched(EventPublished::class);
    }

    public function testRepeatedPublishDispatchesEventOnlyOnce(): void
    {
        $owner = User::factory()->create(['role' => UserRole::ORGANIZER]);
        $event = $this->createEventFor($owner);
        Sanctum::actingAs($owner);
        EventBus::fake([EventPublished::class]);

        $this->postJson("/api/events/{$event->id}/publish")->assertOk();
        $this->postJson("/api/events/{$event->id}/publish")
            ->assertUnprocessable()->assertJsonValidationErrors(['status']);

        $this->assertDatabaseHas('events', ['id' => $event->id, 'status' => 'published']);
        EventBus::assertDispatchedTimes(EventPublished::class, 1);
    }

    private function createEventFor(User $organizer): Event
    {
        return Event::create(array_merge($this->validPayload(), [
            'organizer_id' => $organizer->id,
            'status' => 'draft',
        ]));
    }

    public function testOrganizerCanUpdateOwnEvent(): void
    {
        $organizer = User::factory()->create(['role' => UserRole::ORGANIZER]);
        $event = $this->createEventFor($organizer);
        $originalStart = $event->starts_at->toDateTimeString();
        Sanctum::actingAs($organizer);

        $this->patchJson("/api/events/{$event->id}", ['title' => 'Updated conference'])
            ->assertOk()
            ->assertJsonPath('data.title', 'Updated conference');

        $this->assertDatabaseHas('events', [
            'id' => $event->id,
            'title' => 'Updated conference',
            'organizer_id' => $organizer->id,
            'starts_at' => $originalStart,
            'status' => 'draft',
        ]);
    }

    public function testOrganizerCannotUpdateAnotherOrganizersEvent(): void
    {
        $owner = User::factory()->create(['role' => UserRole::ORGANIZER]);
        $event = $this->createEventFor($owner);
        Sanctum::actingAs(User::factory()->create(['role' => UserRole::ORGANIZER]));

        $this->patchJson("/api/events/{$event->id}", ['title' => 'Unauthorized change'])
            ->assertForbidden();

        $this->assertDatabaseHas('events', ['id' => $event->id, 'title' => $event->title]);
    }

    public function testAdminCanUpdateAnotherUsersEvent(): void
    {
        $owner = User::factory()->create(['role' => UserRole::ORGANIZER]);
        $event = $this->createEventFor($owner);
        Sanctum::actingAs(User::factory()->create(['role' => UserRole::ADMIN]));

        $this->patchJson("/api/events/{$event->id}", ['title' => 'Admin change'])
            ->assertOk()->assertJsonPath('data.title', 'Admin change');

        $this->assertDatabaseHas('events', [
            'id' => $event->id, 'title' => 'Admin change', 'organizer_id' => $owner->id,
        ]);
    }

    public function testParticipantCannotUpdateEvenOwnEvent(): void
    {
        $participant = User::factory()->create(['role' => UserRole::PARTICIPANT]);
        $event = $this->createEventFor($participant);
        Sanctum::actingAs($participant);

        $this->patchJson("/api/events/{$event->id}", ['title' => 'Unauthorized change'])
            ->assertForbidden();

        $this->assertDatabaseHas('events', ['id' => $event->id, 'title' => $event->title]);
    }

    public function testGuestCannotUpdateEvent(): void
    {
        $event = $this->createEventFor(User::factory()->create(['role' => UserRole::ORGANIZER]));

        $this->patchJson("/api/events/{$event->id}", ['title' => 'Unauthorized change'])
            ->assertUnauthorized();

        $this->assertDatabaseHas('events', ['id' => $event->id, 'title' => $event->title]);
    }

    public static function invalidPartialDates(): array
    {
        return [
            'start equals stored end' => ['starts_at', 'ends_at', 0],
            'start after stored end' => ['starts_at', 'ends_at', 1],
            'end equals stored start' => ['ends_at', 'starts_at', 0],
            'end before stored start' => ['ends_at', 'starts_at', -1],
        ];
    }

    #[DataProvider('invalidPartialDates')]
    public function testInvalidPartialDateUpdateIsRejected(string $field, string $reference, int $hours): void
    {
        $owner = User::factory()->create(['role' => UserRole::ORGANIZER]);
        $event = $this->createEventFor($owner);
        $original = $event->{$field}->toDateTimeString();
        Sanctum::actingAs($owner);

        $this->patchJson("/api/events/{$event->id}", [
            $field => $event->{$reference}->copy()->addHours($hours)->toDateTimeString(),
        ])->assertUnprocessable()->assertJsonValidationErrors(['ends_at']);

        $this->assertDatabaseHas('events', ['id' => $event->id, $field => $original]);
    }

    public function testOrganizerCanUpdateBothDatesTogether(): void
    {
        $owner = User::factory()->create(['role' => UserRole::ORGANIZER]);
        $event = $this->createEventFor($owner);
        Sanctum::actingAs($owner);
        $dates = [
            'starts_at' => now()->addDays(4)->toDateTimeString(),
            'ends_at' => now()->addDays(4)->addHours(2)->toDateTimeString(),
        ];

        $this->patchJson("/api/events/{$event->id}", $dates)->assertOk();

        $this->assertDatabaseHas('events', array_merge(['id' => $event->id], $dates));
    }

    private function validPayload(): array
    {
        $venue = Venue::create([
            'name' => 'Conference Hall',
            'address' => 'Tashkent',
            'capacity' => 300,
        ]);
        $category = Category::factory()->create();

        return [
            'title' => 'PHP Conference',
            'description' => 'Laravel conference',
            'starts_at' => now()->addDays(2)->toDateTimeString(),
            'ends_at' => now()->addDays(2)->addHours(3)->toDateTimeString(),
            'venue_id' => $venue->id,
            'category_id' => $category->id,
        ];
    }

    public static function creatorRoles(): array
    {
        return [
            'organizer' => [UserRole::ORGANIZER],
            'admin' => [UserRole::ADMIN],
        ];
    }

    #[DataProvider('creatorRoles')]
    public function testAuthorizedUserCanCreateDraftEvent(UserRole $role): void
    {
        $user = User::factory()->create(['role' => $role]);
        Sanctum::actingAs($user);
        $payload = $this->validPayload();

        $response = $this->postJson('/api/events', $payload)
            ->assertCreated()
            ->assertJsonPath('data.title', $payload['title'])
            ->assertJsonPath('data.organizer_id', $user->id)
            ->assertJsonPath('data.status', 'draft');

        $this->assertDatabaseHas('events', array_merge($payload, [
            'id' => $response->json('data.id'),
            'organizer_id' => $user->id,
            'status' => 'draft',
        ]));
    }

    public function testParticipantCannotCreateEvent(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => UserRole::PARTICIPANT]));

        $this->postJson('/api/events', $this->validPayload())->assertForbidden();

        $this->assertDatabaseCount('events', 0);
    }

    public function testGuestCannotCreateEvent(): void
    {
        $this->postJson('/api/events', $this->validPayload())->assertUnauthorized();

        $this->assertDatabaseCount('events', 0);
    }

    public function testCannotCreateEventWithoutRequiredFields(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => UserRole::ORGANIZER]));

        $this->postJson('/api/events', [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors([
                'title', 'description', 'starts_at', 'ends_at', 'venue_id', 'category_id',
            ]);

        $this->assertDatabaseCount('events', 0);
    }

    public static function invalidFields(): array
    {
        return [
            'title too long' => ['title', str_repeat('a', 256)],
            'invalid start date' => ['starts_at', 'not-a-date'],
            'invalid end date' => ['ends_at', 'not-a-date'],
            'unknown venue' => ['venue_id', 999999],
            'unknown category' => ['category_id', 999999],
            'custom status' => ['status', 'published'],
            'custom organizer' => ['organizer_id', 999999],
        ];
    }

    #[DataProvider('invalidFields')]
    public function testCannotCreateEventWithInvalidField(string $field, mixed $value): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => UserRole::ORGANIZER]));
        $payload = $this->validPayload();
        $payload[$field] = $value;

        $this->postJson('/api/events', $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors([$field]);

        $this->assertDatabaseCount('events', 0);
    }

    public function testCannotCreateEventStartingInPast(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => UserRole::ORGANIZER]));
        $payload = $this->validPayload();
        $payload['starts_at'] = now()->subDay()->toDateTimeString();

        $this->postJson('/api/events', $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['starts_at']);

        $this->assertDatabaseCount('events', 0);
    }

    public static function invalidEndOffsets(): array
    {
        return ['same time' => [0], 'before start' => [-1]];
    }

    #[DataProvider('invalidEndOffsets')]
    public function testEndMustBeAfterStart(int $hours): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => UserRole::ORGANIZER]));
        $payload = $this->validPayload();
        $start = now()->addDays(2)->startOfMinute();
        $payload['starts_at'] = $start->toDateTimeString();
        $payload['ends_at'] = $start->copy()->addHours($hours)->toDateTimeString();

        $this->postJson('/api/events', $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['ends_at']);

        $this->assertDatabaseCount('events', 0);
    }
}
