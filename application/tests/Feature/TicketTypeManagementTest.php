<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Category;
use App\Models\Event;
use App\Models\TicketType;
use App\Models\User;
use App\Models\Venue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class TicketTypeManagementTest extends TestCase
{
    use RefreshDatabase;

    private function createEvent(User $owner, string $status = 'draft'): Event
    {
        return Event::create([
            'title' => 'Test event',
            'description' => 'Test description',
            'starts_at' => now()->addDays(2),
            'ends_at' => now()->addDays(2)->addHours(2),
            'organizer_id' => $owner->id,
            'category_id' => Category::create(['name' => fake()->unique()->word()])->id,
            'venue_id' => Venue::create(['name' => 'Hall', 'address' => 'Tashkent', 'capacity' => 300])->id,
            'status' => $status,
        ]);
    }

    private function payload(array $overrides = []): array
    {
        return array_merge(['name' => 'Standard', 'price' => '100000.00', 'quantity' => 200], $overrides);
    }

    public static function managers(): array
    {
        return ['owner' => ['organizer'], 'administrator' => ['admin']];
    }

    #[DataProvider('managers')]
    public function testAuthorizedUserCanCreateAndUpdate(string $role): void
    {
        $owner = User::factory()->create(['role' => UserRole::ORGANIZER]);
        $event = $this->createEvent($owner);
        Sanctum::actingAs($role === 'organizer' ? $owner : User::factory()->create(['role' => UserRole::ADMIN]));

        $response = $this->postJson("/api/events/{$event->id}/ticket-types", $this->payload())
            ->assertCreated()
            ->assertJsonPath('data.event_id', $event->id)
            ->assertJsonPath('data.price', '100000.00');
        $id = $response->json('data.id');

        $this->patchJson("/api/events/{$event->id}/ticket-types/{$id}", ['name' => 'VIP', 'quantity' => 300])
            ->assertOk()->assertJsonPath('data.quantity', 300);
        $this->assertDatabaseHas('ticket_types', ['id' => $id, 'name' => 'VIP', 'quantity' => 300]);
    }

    public static function unauthorizedUsers(): array
    {
        return ['other organizer' => ['organizer'], 'participant' => ['participant'], 'guest' => [null]];
    }

    #[DataProvider('unauthorizedUsers')]
    public function testUnauthorizedUserCannotWrite(?string $role): void
    {
        $owner = User::factory()->create(['role' => UserRole::ORGANIZER]);
        $event = $this->createEvent($owner);
        $ticketType = $event->ticketTypes()->create($this->payload());
        if ($role !== null) {
            Sanctum::actingAs(User::factory()->create(['role' => $role]));
        }

        $status = $role === null ? 401 : 403;
        $this->postJson("/api/events/{$event->id}/ticket-types", $this->payload())->assertStatus($status);
        $this->patchJson("/api/events/{$event->id}/ticket-types/{$ticketType->id}", ['quantity' => 1])->assertStatus($status);
        $this->assertDatabaseCount('ticket_types', 1);
        $this->assertSame(200, $ticketType->fresh()->quantity);
    }

    public function testGuestCanListOnlyTypesOfSelectedPublishedEvent(): void
    {
        $owner = User::factory()->create(['role' => UserRole::ORGANIZER]);
        $event = $this->createEvent($owner, 'published');
        $type = $event->ticketTypes()->create($this->payload());
        $this->createEvent($owner, 'published')->ticketTypes()->create($this->payload());

        $this->getJson("/api/events/{$event->id}/ticket-types")
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $type->id);
    }

    public static function hiddenStatuses(): array
    {
        return ['draft' => ['draft'], 'cancelled' => ['cancelled'], 'finished' => ['finished']];
    }

    #[DataProvider('hiddenStatuses')]
    public function testUnpublishedEventTypesAreHidden(string $status): void
    {
        $event = $this->createEvent(User::factory()->create(['role' => UserRole::ORGANIZER]), $status);
        $event->ticketTypes()->create($this->payload());
        $this->getJson("/api/events/{$event->id}/ticket-types")->assertNotFound();
    }

    public function testCreateRequiresNamePriceAndQuantity(): void
    {
        $owner = User::factory()->create(['role' => UserRole::ORGANIZER]);
        $event = $this->createEvent($owner);
        Sanctum::actingAs($owner);
        $this->postJson("/api/events/{$event->id}/ticket-types", [])
            ->assertUnprocessable()->assertJsonValidationErrors(['name', 'price', 'quantity']);
        $this->assertDatabaseCount('ticket_types', 0);
    }

    public static function invalidFields(): array
    {
        return [
            'empty name' => ['name', ''],
            'long name' => ['name', str_repeat('a', 256)],
            'negative price' => ['price', -1],
            'non-numeric price' => ['price', 'abc'],
            'price precision' => ['price', '10.123'],
            'price overflow' => ['price', '10000000000.00'],
            'negative quantity' => ['quantity', -1],
            'fractional quantity' => ['quantity', 1.5],
            'quantity overflow' => ['quantity', 2147483648],
            'custom event' => ['event_id', 999999],
        ];
    }

    #[DataProvider('invalidFields')]
    public function testInvalidFieldsAreRejectedOnCreateAndUpdate(string $field, mixed $value): void
    {
        $owner = User::factory()->create(['role' => UserRole::ORGANIZER]);
        $event = $this->createEvent($owner);
        $type = $event->ticketTypes()->create($this->payload());
        Sanctum::actingAs($owner);
        $this->postJson("/api/events/{$event->id}/ticket-types", $this->payload([$field => $value]))
            ->assertUnprocessable()->assertJsonValidationErrors($field);
        $this->patchJson("/api/events/{$event->id}/ticket-types/{$type->id}", [$field => $value])
            ->assertUnprocessable()->assertJsonValidationErrors($field);
        $this->assertDatabaseCount('ticket_types', 1);
        $this->assertSame(200, $type->fresh()->quantity);
        $this->assertSame('100000.00', $type->fresh()->price);
        $this->assertSame('Standard', $type->fresh()->name);
    }

    public function testTotalCapacityIsCheckedOnCreateAndUpdate(): void
    {
        $owner = User::factory()->create(['role' => UserRole::ORGANIZER]);
        $event = $this->createEvent($owner);
        $standard = $event->ticketTypes()->create($this->payload());
        Sanctum::actingAs($owner);

        $this->postJson("/api/events/{$event->id}/ticket-types", $this->payload(['name' => 'VIP', 'quantity' => 101]))
            ->assertUnprocessable()->assertJsonValidationErrors('quantity');
        $this->assertDatabaseCount('ticket_types', 1);

        $this->postJson("/api/events/{$event->id}/ticket-types", $this->payload(['name' => 'VIP', 'quantity' => 100]))
            ->assertCreated();
        $this->patchJson("/api/events/{$event->id}/ticket-types/{$standard->id}", ['quantity' => 201])
            ->assertUnprocessable()->assertJsonValidationErrors('quantity');
        $this->assertSame(200, $standard->fresh()->quantity);

        $this->patchJson("/api/events/{$event->id}/ticket-types/{$standard->id}", ['quantity' => 150])
            ->assertOk();
        $this->assertSame(250, (int) $event->ticketTypes()->sum('quantity'));
    }

    public function testZeroPriceAndQuantityAreAllowed(): void
    {
        $owner = User::factory()->create(['role' => UserRole::ORGANIZER]);
        $event = $this->createEvent($owner);
        Sanctum::actingAs($owner);
        $this->postJson("/api/events/{$event->id}/ticket-types", $this->payload(['price' => '0', 'quantity' => 0]))
            ->assertCreated()->assertJsonPath('data.price', '0.00')->assertJsonPath('data.quantity', 0);
    }

    public function testTypeFromAnotherEventReturnsNotFoundEvenForAdmin(): void
    {
        $owner = User::factory()->create(['role' => UserRole::ORGANIZER]);
        $event = $this->createEvent($owner);
        $type = $this->createEvent($owner)->ticketTypes()->create($this->payload());
        Sanctum::actingAs(User::factory()->create(['role' => UserRole::ADMIN]));
        $this->patchJson("/api/events/{$event->id}/ticket-types/{$type->id}", ['quantity' => 1])
            ->assertNotFound();
        $this->assertSame(200, $type->fresh()->quantity);
    }
}
