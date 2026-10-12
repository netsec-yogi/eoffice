<?php

namespace App\Providers;

use App\Modules\ComputerCentre\Integrations\LdapGroupProvisioner;
use App\Modules\ComputerCentre\Integrations\MailboxProvisioner;
use App\Modules\ComputerCentre\Integrations\NetworkAccessProvisioner;
use App\Modules\ComputerCentre\Integrations\NotConfiguredMailbox;
use App\Modules\ComputerCentre\Integrations\NotConfiguredNetwork;
use App\Modules\ComputerCentre\Integrations\NotConfiguredVm;
use App\Modules\ComputerCentre\Integrations\ProxmoxProvisioner;
use App\Modules\ComputerCentre\Integrations\VirtualMachineProvisioner;
use App\Modules\Documents\DocumentPolicy;
use App\Modules\Documents\Models\Document;
use App\Modules\Documents\Scanning\ClamAvScanner;
use App\Modules\Documents\Scanning\MalwareScanner;
use App\Modules\Documents\Scanning\NullScanner;
use App\Modules\EFile\Models\Correspondence;
use App\Modules\EFile\Models\EFile;
use App\Modules\EFile\Services\EFilePolicy;
use App\Modules\EFile\Services\RecordWorkflowOutcome;
use App\Modules\Identity\Directory\DirectoryService;
use App\Modules\Identity\Directory\LdapDirectoryService;
use App\Modules\Notifications\Channels\InAppChannel;
use App\Modules\Notifications\Channels\MailChannel;
use App\Modules\Notifications\Channels\PhoneChannel;
use App\Modules\Notifications\NotificationDispatcher;
use App\Modules\Notifications\TemplateRenderer;
use App\Modules\OfficialDocuments\Services\WorkflowListener;
use App\Modules\Registrar\Services\ReleaseOnFinish;
use App\Modules\Workflow\Engine\WorkflowFinished;
use App\Modules\Workflow\Engine\WorkflowSubmitted;
use App\Modules\Workflow\Models\WorkflowInstance;
use App\Modules\Workflow\Policies\WorkflowInstancePolicy;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(DirectoryService::class, LdapDirectoryService::class);

        $this->app->singleton(MalwareScanner::class, fn () => match (config('documents.scanner')) {
            'clamav' => new ClamAvScanner(...config('documents.clamav')),
            default => new NullScanner,
        });

        $this->app->singleton('notifications.channels', fn () => [
            'mail' => new MailChannel,
            'in_app' => new InAppChannel,
            'sms' => new PhoneChannel('sms', (string) env('SMS_DRIVER', 'log')),
            'whatsapp' => new PhoneChannel('whatsapp', (string) env('WHATSAPP_DRIVER', 'log')),
        ]);
        // Computer Centre integrations: "none" makes the step a manual task.
        $this->app->bind(MailboxProvisioner::class, NotConfiguredMailbox::class);
        $this->app->bind(NetworkAccessProvisioner::class, fn () => config('computer_centre.network.driver') === 'ldap'
            ? new LdapGroupProvisioner
            : new NotConfiguredNetwork);
        $this->app->bind(VirtualMachineProvisioner::class, fn () => config('computer_centre.vm.driver') === 'proxmox'
            ? new ProxmoxProvisioner
            : new NotConfiguredVm);

        $this->app->singleton(NotificationDispatcher::class, fn ($app) => new NotificationDispatcher(
            $app->make('notifications.channels'),
            $app->make(TemplateRenderer::class),
        ));
    }

    public function boot(): void
    {
        // Stable names in polymorphic columns (audit subjects, attachments).
        // Every model stored in a morph column must be listed.
        Relation::enforceMorphMap(config('morph'));

        // Fail loudly in development on lazy loading and unknown attributes.
        Model::shouldBeStrict(! $this->app->isProduction());

        Gate::policy(Document::class, DocumentPolicy::class);
        Gate::policy(WorkflowInstance::class, WorkflowInstancePolicy::class);
        Gate::policy(EFile::class, EFilePolicy::class);
        Gate::policy(Correspondence::class, EFilePolicy::class);

        // Modules react to finished requests whose subject they own.
        Event::listen(WorkflowFinished::class, RecordWorkflowOutcome::class);
        Event::listen(WorkflowFinished::class, ReleaseOnFinish::class);
        Event::listen(WorkflowFinished::class, \App\Modules\Finance\Services\ReleaseOnFinish::class);
        Event::listen(WorkflowFinished::class, [WorkflowListener::class, 'finished']);
        Event::listen(WorkflowSubmitted::class, [WorkflowListener::class, 'submitted']);

        RateLimiter::for('login', fn (Request $request) => [
            Limit::perMinute(5)->by(strtolower((string) $request->input('username')).'|'.$request->ip()),
            Limit::perMinute(30)->by($request->ip()),
        ]);
        RateLimiter::for('verify', fn (Request $request) => Limit::perMinute(20)->by($request->ip()));
        RateLimiter::for('uploads', fn (Request $request) => Limit::perMinute(30)->by((string) $request->user()?->id));
    }
}
