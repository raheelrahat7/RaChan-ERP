<?php

namespace App\Providers;

use App\Domain\Crm\Events\LeadStageChanged;
use App\Domain\Crm\Listeners\CreateStageEntryFollowUp;
use App\Domain\Crm\Listeners\DeliverStageEntryNotification;
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
        //
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
