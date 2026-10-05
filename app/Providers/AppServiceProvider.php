<?php

namespace App\Providers;

use App\Domain\Chat\Services\ClamAvScanner;
use App\Domain\Chat\Services\NullScanner;
use App\Domain\Chat\Services\VirusScanner;
use App\Domain\Crm\Events\LeadStageChanged;
use App\Domain\Crm\Listeners\CreateStageEntryFollowUp;
use App\Domain\Crm\Listeners\DeliverStageEntryNotification;
use App\Domain\Crm\Listeners\RunAutomationRules;
use App\Domain\Crm\Observers\LeadPipelineObserver;
use App\Domain\Crm\Observers\OrganizationPipelineObserver;
use App\Models\CrmLead;
use App\Models\Organization;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Unknown driver names fall back to the real scanner, so a typo never disables scanning.
        $this->app->bind(VirusScanner::class, fn (): VirusScanner => config('chat.virus_scan.driver') === 'off'
            ? new NullScanner
            : new ClamAvScanner((string) config('chat.virus_scan.host'), (int) config('chat.virus_scan.port'), (int) config('chat.virus_scan.timeout')));
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureDefaults();
        Organization::observe(OrganizationPipelineObserver::class);
        CrmLead::observe(LeadPipelineObserver::class);
        Event::listen(LeadStageChanged::class, DeliverStageEntryNotification::class);
        Event::listen(LeadStageChanged::class, CreateStageEntryFollowUp::class);
        Event::listen(LeadStageChanged::class, RunAutomationRules::class);
    }

    /**
     * Configure default behaviors for production-ready applications.
     */
    protected function configureDefaults(): void
    {
        Date::use(CarbonImmutable::class);

        DB::prohibitDestructiveCommands(
            app()->isProduction(),
        );

        Password::defaults(fn (): ?Password => app()->isProduction()
            ? Password::min(12)
                ->mixedCase()
                ->letters()
                ->numbers()
                ->symbols()
                ->uncompromised()
            : null,
        );
    }
}
