<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\User;
use App\Models\Venue;
use App\Services\VenueService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\Concerns\CreatesOrderContext;
use Tests\TestCase;

class VenueEditingTest extends TestCase
{
    use RefreshDatabase, CreatesOrderContext;

    public function testAdminCanPartiallyUpdateAndDeleteUnusedVenueThroughApi(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => UserRole::ADMIN]));
        $venue = Venue::create(['name' => 'Hall', 'address' => 'Original', 'capacity' => 10]);
        $this->patchJson('/api/admin/venues/'.$venue->id, ['address' => 'New', 'capacity' => 5])
            ->assertOk()->assertJsonPath('data.name', 'Hall')->assertJsonPath('data.capacity', 5);
        $this->patchJson('/api/admin/venues/'.$venue->id, ['capacity' => null])->assertUnprocessable();
        $this->deleteJson('/api/admin/venues/'.$venue->id)->assertNoContent();
        $this->assertDatabaseMissing('venues', ['id' => $venue->id]);
    }

    public function testApiMutationPermissionsAreNotGrantedToOrganizersOrParticipants(): void
    {
        $venue = Venue::create(['name' => 'Hall', 'address' => 'Original', 'capacity' => 10]);
        $base = '/api/admin/venues/'.$venue->id;
        $this->patchJson($base, ['capacity' => 5])->assertUnauthorized();
        $this->deleteJson($base)->assertUnauthorized();
        foreach ([UserRole::ORGANIZER, UserRole::PARTICIPANT] as $role) {
            Sanctum::actingAs(User::factory()->create(['role' => $role]));
            $this->patchJson($base, ['capacity' => 5])->assertForbidden();
            $this->deleteJson($base)->assertForbidden();
        }
        $this->assertDatabaseHas('venues', ['id' => $venue->id, 'capacity' => 10]);
    }

    public static function eventStates(): array
    {
        return ['draft' => ['draft'], 'published' => ['published'], 'cancelled' => ['cancelled']];
    }

    #[DataProvider('eventStates')]
    public function testAllLinkedEventsProtectVenueAddressCapacityAndDeletion(string $state): void
    {
        [, $event] = $this->createOrderContext();
        $event->update(['status' => $state]);
        $venue = $event->venue;
        Sanctum::actingAs(User::factory()->create(['role' => UserRole::ADMIN]));
        $base = '/api/admin/venues/'.$venue->id;
        $this->patchJson($base, ['name' => 'Must not save', 'address' => 'Different', 'capacity' => 1])
            ->assertUnprocessable()->assertJsonValidationErrors(['address', 'capacity']);
        $this->assertSame($venue->getRawOriginal(), $venue->fresh()->getRawOriginal());
        $this->deleteJson($base)->assertStatus(409);
        $this->assertDatabaseHas('events', ['id' => $event->id, 'venue_id' => $venue->id]);
        $this->patchJson($base, ['name' => 'Updated', 'address' => $venue->address, 'capacity' => (string) $venue->capacity])
            ->assertOk()->assertJsonPath('data.name', 'Updated');
    }

    public function testServiceChecksFreshLinksEvenWhenFormLoadedAnUnusedVenue(): void
    {
        [, $event] = $this->createOrderContext();
        $venue = Venue::create(['name' => 'New Hall', 'address' => 'Original', 'capacity' => 100]);
        $venue->load('events');
        $this->assertCount(0, $venue->events);
        $event->update(['venue_id' => $venue->id]);
        try {
            app(VenueService::class)->update($venue, ['capacity' => 1]);
            $this->fail('A newly linked event must protect the venue.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('capacity', $exception->errors());
        }
        $this->assertSame(100, $venue->fresh()->capacity);
    }

    public function testFailureAfterVenueSaveRollsBackTheEntireUpdate(): void
    {
        $venue = Venue::create(['name' => 'Original', 'address' => 'Address', 'capacity' => 100]);
        Event::listen('eloquent.updated: '.Venue::class, function () {
            throw new RuntimeException('Injected save failure');
        });
        try {
            app(VenueService::class)->update($venue, ['name' => 'Changed', 'capacity' => 50]);
            $this->fail('The failure must propagate.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Injected save failure', $exception->getMessage());
        }
        $this->assertDatabaseHas('venues', ['id' => $venue->id, 'name' => 'Original', 'capacity' => 100]);
    }
}
