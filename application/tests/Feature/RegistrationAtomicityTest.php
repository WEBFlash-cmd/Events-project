<?php

namespace Tests\Feature;

use App\Data\Auth\RegistrationData;
use App\Mail\EmailVerification;
use App\Models\User;
use App\Services\AuthService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use RuntimeException;
use Tests\TestCase;

class RegistrationAtomicityTest extends TestCase
{
    use RefreshDatabase;

    private function register(): User
    {
        return app(AuthService::class)->register(new RegistrationData('atomic@example.test', 'secret-123'));
    }

    public function testRoleAssignmentFailureRollsBackUserAndQueuesNoEmail(): void
    {
        Queue::fake();
        Event::listen('eloquent.updating: '.User::class, function (User $user) {
            if ($user->email === 'atomic@example.test' && $user->isDirty('role_id')) {
                throw new RuntimeException('role assignment failed');
            }
        });
        try {
            $this->register();
            $this->fail('The role assignment must fail.');
        } catch (RuntimeException $error) {
            $this->assertSame('role assignment failed', $error->getMessage());
        }
        $this->assertDatabaseMissing('users', ['email' => 'atomic@example.test']);
        Queue::assertNothingPushed();
    }

    public function testVerificationIsQueuedOnlyAfterOuterCommit(): void
    {
        config(['queue.default' => 'database']);
        DB::transaction(function () {
            $this->register();
            $this->assertDatabaseCount('jobs', 0);
        });
        $this->assertDatabaseCount('jobs', 1);
        $this->assertDatabaseHas('jobs', ['queue' => 'notifications']);
        $this->assertDatabaseHas('users', ['email' => 'atomic@example.test']);
    }

    public function testOuterRollbackAlsoDiscardsVerificationJob(): void
    {
        config(['queue.default' => 'database']);
        try {
            DB::transaction(function () {
                $this->register();
                throw new RuntimeException('outer rollback');
            });
        } catch (RuntimeException $error) {
            $this->assertSame('outer rollback', $error->getMessage());
        }
        $this->assertDatabaseMissing('users', ['email' => 'atomic@example.test']);
        $this->assertDatabaseCount('jobs', 0);
    }

    public function testQueuedVerificationCanRetryMailFailureWithoutRepeatingRegistration(): void
    {
        config(['queue.default' => 'database']);
        Mail::fake();
        $user = $this->register();
        Mail::assertNothingSent();
        $queue = Queue::connection('database');
        $job = $queue->pop('notifications');
        $this->assertNotNull($job);
        $this->assertSame(3, $job->payload()['maxTries']);
        $mailer = Mail::getFacadeRoot();
        Mail::shouldReceive('to')->once()->andThrow(new RuntimeException('SMTP unavailable'));
        try {
            $job->fire();
            $this->fail('Mail failure must reach the queue worker.');
        } catch (RuntimeException $error) {
            $this->assertSame('SMTP unavailable', $error->getMessage());
            $job->release(0);
        } finally {
            Mail::swap($mailer);
        }
        $this->assertSame('participant', $user->fresh()->rbacRole->name);
        $retry = $queue->pop('notifications');
        $this->assertSame(2, $retry->attempts());
        $retry->fire();
        $this->assertDatabaseCount('users', 1);
        Mail::assertSent(EmailVerification::class, 1);
    }
}
