<?php

namespace Tests\Feature\Platform;

use App\Modules\Identity\Models\User;
use App\Modules\Notifications\Channels\PhoneChannel;
use App\Modules\Notifications\Jobs\DeliverNotification;
use App\Modules\Notifications\Models\NotificationMessage;
use App\Modules\Notifications\Models\NotificationTemplate;
use App\Modules\Notifications\NotificationDispatcher;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/** NOTIF-001, NOTIF-002 */
class NotificationDispatcherTest extends TestCase
{
    private function send(User $user, string $event = 'task:1:assigned'): array
    {
        return app(NotificationDispatcher::class)->send('workflow.task_assigned', [$user], [
            'instance' => ['reference' => 'IT/2026/00001', 'title' => 'Email account', 'initiator' => 'Karan'],
            'task' => ['name' => 'HoD approval', 'due' => '13 Oct 2026'],
        ], $event, '/tasks/1');
    }

    public function test_templates_render_per_channel_and_mail_is_delivered(): void
    {
        Mail::fake();
        $user = User::factory()->create(['name' => 'Priya']);

        $messages = $this->send($user);

        $this->assertEqualsCanonicalizing(['mail', 'in_app'], array_map(fn ($m) => $m->channel, $messages), 'inactive SMS template is not used');
        $mail = NotificationMessage::query()->where('channel', 'mail')->sole();
        $this->assertSame('sent', $mail->status, (string) $mail->last_error);
        $this->assertStringContainsString('Dear Priya', $mail->body);
        $this->assertStringContainsString('IT/2026/00001', $mail->subject);
        $this->assertSame('sent', NotificationMessage::query()->where('channel', 'in_app')->value('status'));
    }

    public function test_the_same_event_is_never_sent_twice(): void
    {
        Mail::fake();
        $user = User::factory()->create();

        $this->send($user);
        $this->assertSame([], $this->send($user));
        $this->assertSame(2, NotificationMessage::query()->count());
    }

    public function test_recipients_without_an_address_are_recorded_as_skipped(): void
    {
        NotificationTemplate::query()->where('channel', 'sms')->update(['is_active' => true]);
        $user = User::factory()->create(['phone' => null]);

        $this->send($user);

        $sms = NotificationMessage::query()->where('channel', 'sms')->sole();
        $this->assertSame('skipped', $sms->status);
    }

    public function test_an_unconfigured_provider_fails_visibly(): void
    {
        $this->app->instance('notifications.channels', array_merge(app('notifications.channels'), [
            'sms' => new PhoneChannel('sms', 'acme-sms'),
        ]));
        NotificationTemplate::query()->where('channel', 'sms')->update(['is_active' => true]);
        NotificationTemplate::query()->where('channel', '!=', 'sms')->update(['is_active' => false]);
        $user = User::factory()->create(['phone' => '+91 98765 43210']);

        $this->send($user);

        $sms = NotificationMessage::query()->sole();
        $this->assertSame('failed', $sms->status);
        $this->assertStringContainsString('No sms provider', $sms->last_error);
    }

    public function test_delivery_job_does_not_resend_sent_messages(): void
    {
        Mail::fake();
        $user = User::factory()->create();
        $this->send($user);
        $mail = NotificationMessage::query()->where('channel', 'mail')->sole();

        (new DeliverNotification($mail->id))->handle();

        Mail::assertSentCount(0); // Mail::raw is not a Mailable; count stays via the message row instead
        $this->assertSame(1, $mail->fresh()->attempts);
    }

    public function test_inactive_users_are_not_notified(): void
    {
        $user = User::factory()->inactive()->create();
        $this->assertSame([], $this->send($user));
    }
}
