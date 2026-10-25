<?php

namespace Tests\Feature;

use App\Enums\EventStatus;
use App\Events\EventStarted;
use App\Services\EventLifecycleService;
use App\Services\EventService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\CreatesOrderContext;
use Tests\TestCase;

class EventLifecycleTest extends TestCase
{
    use RefreshDatabase, CreatesOrderContext;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(now()->startOfSecond());
        Event::fake([EventStarted::class]);
    }

    public function testStartIsRecordedOnceAtExactStartBoundary(): void
    {
        [, $event] = $this->createOrderContext();
        $event->update(['starts_at' => now(), 'ends_at' => now()->addHour()]);
        $this->artisan('events:sync-statuses')->assertSuccessful();
        $this->artisan('events:sync-statuses')->assertSuccessful();
        $this->assertTrue($event->fresh()->started_at->equalTo($event->starts_at));
        $this->assertSame(EventStatus::PUBLISHED, $event->fresh()->status);
        Event::assertDispatchedTimes(EventStarted::class, 1);
        Event::assertDispatched(EventStarted::class, fn ($signal) => $signal->event->id === $event->id);
    }

    public function testExactEndBoundaryFinishesStartedEventWithoutRepeatingStart(): void
    {
        [, $event] = $this->createOrderContext();
        $event->update(['starts_at' => now()->subHour(), 'ends_at' => now()->addMinute()]);
        $service = app(EventLifecycleService::class);
        $this->assertTrue($service->synchronize($event));
        $this->travelTo($event->ends_at);
        $this->artisan('events:sync-statuses')->assertSuccessful();
        $this->assertSame(EventStatus::FINISHED, $event->fresh()->status);
        $this->assertFalse($service->synchronize($event));
        Event::assertDispatchedTimes(EventStarted::class, 1);
    }

    public function testSchedulerCatchesUpAfterMissingTheWholeEvent(): void
    {
        [, $event] = $this->createOrderContext();
        $event->update(['starts_at' => now()->subHours(2), 'ends_at' => now()->subHour()]);
        $this->artisan('events:sync-statuses')->assertSuccessful();
        $this->assertSame(EventStatus::FINISHED, $event->fresh()->status);
        $this->assertTrue($event->fresh()->started_at->equalTo($event->starts_at));
        $this->artisan('events:sync-statuses')->assertSuccessful();
        Event::assertDispatchedTimes(EventStarted::class, 1);
    }

    public static function ignoredEvents(): array
    {
        return [['draft'], ['cancelled'], ['finished'], ['future']];
    }

    #[DataProvider('ignoredEvents')]
    public function testNonPublishedAndFutureEventsAreNotStarted(string $status): void
    {
        [, $event] = $this->createOrderContext();
        if ($status !== 'future') {
            $event->update(['status' => $status, 'starts_at' => now()->subHours(2), 'ends_at' => now()->subHour()]);
        }
        $this->artisan('events:sync-statuses')->assertSuccessful();
        $this->assertNull($event->fresh()->started_at);
        Event::assertNotDispatched(EventStarted::class);
    }

    public function testStateIsRecheckedAfterLoadingStaleModel(): void
    {
        [, $event] = $this->createOrderContext();
        $event->update(['starts_at' => now()->subHour(), 'ends_at' => now()->addHour()]);
        $event->newQuery()->whereKey($event->id)->update(['status' => 'cancelled']);
        $this->assertFalse(app(EventLifecycleService::class)->synchronize($event));
        $this->assertSame(EventStatus::CANCELLED, $event->fresh()->status);
        Event::assertNotDispatched(EventStarted::class);
    }

    public function testDeletedEventIsSkippedSafely(): void
    {
        [, $event] = $this->createOrderContext();
        $event->ticketTypes()->delete();
        $event->delete();
        $this->assertFalse(app(EventLifecycleService::class)->synchronize($event));
        Event::assertNotDispatched(EventStarted::class);
    }

    public function testRollbackDoesNotRecordStartOrDispatchEvent(): void
    {
        [, $event] = $this->createOrderContext();
        $event->update(['starts_at' => now(), 'ends_at' => now()->addHour()]);
        try {
            DB::transaction(function () use ($event) {
                app(EventLifecycleService::class)->synchronize($event);
                Event::assertNotDispatched(EventStarted::class);
                throw new \RuntimeException('rollback');
            });
        } catch (\RuntimeException $exception) {
            $this->assertSame('rollback', $exception->getMessage());
        }
        $this->assertNull($event->fresh()->started_at);
        Event::assertNotDispatched(EventStarted::class);
    }

    public function testRescheduledEventWithoutSalesCanStartAtItsNewTime(): void
    {
        [, $event] = $this->createOrderContext();
        $event->update(['starts_at' => now(), 'ends_at' => now()->addHour()]);
        app(EventLifecycleService::class)->synchronize($event);
        $newStart = now()->addDay();
        app(EventService::class)->updateEvent($event, ['starts_at' => $newStart, 'ends_at' => $newStart->copy()->addHour()]);
        $this->assertNull($event->fresh()->started_at);
        $this->assertFalse(app(EventLifecycleService::class)->synchronize($event));
        $this->travelTo($newStart);
        $this->assertTrue(app(EventLifecycleService::class)->synchronize($event));
        Event::assertDispatchedTimes(EventStarted::class, 2);
    }
}
