<?php

namespace Tests\Concerns;

use App\Enums\UserRole;
use App\Models\Category;
use App\Models\Event;
use App\Models\User;
use App\Models\Venue;

trait CreatesOrderContext
{
    protected function configurePaymentGateway(): void
    {
        config([
            'payments.base_url' => 'http://payments.test',
            'payments.cashbox_id' => 1,
            'payments.secret' => 'test-secret',
            'payments.timeout' => 1,
        ]);
    }

    protected function createOrderContext(string $price = '100000.00'): array
    {
        $participant = User::factory()->create(['role' => UserRole::PARTICIPANT]);
        $organizer = User::factory()->create(['role' => UserRole::ORGANIZER]);
        $category = Category::firstOrCreate(['name' => 'Conference']);
        $venue = Venue::create(['name' => 'Hall', 'address' => 'Tashkent', 'capacity' => 300]);
        $event = Event::create([
            'title' => 'Test event', 'description' => 'Test description',
            'starts_at' => now()->addDays(2), 'ends_at' => now()->addDays(2)->addHours(2),
            'organizer_id' => $organizer->id, 'category_id' => $category->id,
            'venue_id' => $venue->id, 'status' => 'published',
        ]);
        $ticketType = $event->ticketTypes()->create([
            'name' => 'Standard', 'price' => $price, 'quantity' => 10,
        ]);

        return [$participant, $event, $ticketType];
    }
}
