<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;
use App\Models\User;
use App\Models\Venue;


class VenueManagementTest extends TestCase
{
    public function testAdminCanViewVenues(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => UserRole::ADMIN]));

        $this->getJson('/api/venues')->assertOk()->assertJsonCount(0, 'data');
    }

    public function testOrganizerCanViewSortedPaginatedVenues(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => UserRole::ORGANIZER]));

        foreach (range(16, 1) as $number) {
            Venue::create([
                'name' => sprintf('Venue %02d', $number),
                'address' => 'Tashkent',
                'capacity' => 100,
            ]);
        }

        $this->getJson('/api/venues')
            ->assertOk()
            ->assertJsonCount(15, 'data')
            ->assertJsonPath('total', 16)
            ->assertJsonPath('data.0.name', 'Venue 01');

        $this->getJson('/api/venues?page=2')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'Venue 16');
    }

    public function testParticipantCannotViewVenues(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => UserRole::PARTICIPANT]));

        $this->getJson('/api/venues')->assertForbidden();
    }

    public function testGuestCannotAccessVenues(): void
    {
        $this->getJson('/api/venues')->assertUnauthorized();
        $this->postJson('/api/admin/venues', [])->assertUnauthorized();
    }

    public function testOrganizerCannotCreateVenue(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => UserRole::ORGANIZER]));

        $this->postJson('/api/admin/venues', [
            'name' => 'Venue',
            'address' => 'Tashkent',
            'capacity' => 100,
        ])->assertForbidden();

        $this->assertDatabaseCount('venues', 0);
    }

    public function testAdminCannotCreateVenueWithoutRequiredFields(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => UserRole::ADMIN]));

        $this->postJson('/api/admin/venues', [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['name', 'address', 'capacity']);

        $this->assertDatabaseCount('venues', 0);
    }

    public function testAdminCanCreateVenueWithoutDescription(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => UserRole::ADMIN]));

        $this->postJson('/api/admin/venues', [
            'name' => 'Venue',
            'address' => 'Tashkent',
            'capacity' => 100,
        ])->assertCreated();

        $this->assertDatabaseHas('venues', ['name' => 'Venue', 'description' => null]);
    }

    /**
     * A basic feature test example.
     */
    use RefreshDatabase;

    public function testAdminCanCreateVenue(): void
    {
        $admin = User::factory()->create([
            "role" => UserRole::ADMIN
        ]);

        Sanctum::actingAs($admin);

        $response = $this->postJson("/api/admin/venues", [
            'name' => 'Venue 1',
            'address' => 'Venue district',
            'description' => 'Venue 1 description',
            'capacity' => 3,
        ]);

        $response
            ->assertCreated()
            ->assertJsonPath('data.name', 'Venue 1')
            ->assertJsonPath('data.address', 'Venue district')
            ->assertJsonPath('data.description', 'Venue 1 description')
            ->assertJsonPath('data.capacity', 3);

        $this->assertDatabaseHas("venues", [
            'name' => 'Venue 1',
            'address' => 'Venue district',
            'description' => 'Venue 1 description',
            'capacity' => 3,
        ]);
    }

    public function testParticipantCannotCreateVenue(): void
    {
        $participant = User::factory()->create([
            "role" => UserRole::PARTICIPANT
        ]);
        Sanctum::actingAs($participant);

        $response = $this->postJson("/api/admin/venues", [
            'name' => 'Venue 1',
            'address' => 'Venue district',
            'description' => 'Venue 1 description',
            'capacity' => 3,
        ]);
        $response->assertForbidden();

        $this->assertDatabaseCount("venues", 0);

    }

    public function testAdminCannotCreateVenueWithZeroCapacity(): void
    {
        $admin = User::factory()->create([
            "role" => UserRole::ADMIN
        ]);
        Sanctum::actingAs($admin);
        $response = $this->postJson("/api/admin/venues", [
            'name' => 'Venue 1',
            'address' => 'Venue district',
            'description' => 'Venue 1 description',
            'capacity' => 0,
        ]);
        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['capacity']);

        $this->assertDatabaseCount("venues", 0);
    }
}
