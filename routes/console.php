<?php

use App\Domain\Crm\Actions\EscalateOverdueFollowUps;
use App\Domain\Crm\Actions\ExecuteAutomationRule;
use App\Domain\Crm\Actions\ManageDealAutomation;
use App\Domain\Crm\Actions\RetryHeldLeads;
use App\Domain\Crm\Actions\SendFollowUpReminders;
use App\Domain\Notifications\Actions\GenerateDailyNotifications;
use App\Domain\Operations\Actions\GenerateDuePreventiveMaintenance;
use App\Domain\Operations\Actions\GeneratePrivateReports;
use App\Domain\Operations\Actions\NotifySlaBreaches;
use App\Models\Organization;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('notifications:generate-daily', function (GenerateDailyNotifications $generator) {
    $this->info('Created '.$generator->handle().' notifications.');
})->purpose('Generate organization-scoped daily in-app notifications');

Schedule::command('notifications:generate-daily')->dailyAt('08:00')->withoutOverlapping();

Artisan::command('crm:retry-held-leads', function (RetryHeldLeads $retry) {
    Organization::query()->orderBy('id')->chunkById(100, function ($organizations) use ($retry): void {
        foreach ($organizations as $organization) {
            $retry->handle($organization);
        }
    });
})->purpose('Retry CRM assignment holds in arrival order');

Schedule::command('crm:retry-held-leads')->everyMinute()->withoutOverlapping();

Artisan::command('crm:send-follow-up-reminders', function (SendFollowUpReminders $reminders) {
    Organization::query()->whereNotNull('crm_follow_up_reminder_days')->orderBy('id')->chunkById(100, function ($organizations) use ($reminders): void {
        foreach ($organizations as $organization) {
            $reminders->handle($organization);
        }
    });
})->purpose('Send configured CRM follow-up reminders to current assignees');

Schedule::command('crm:send-follow-up-reminders')->everyFifteenMinutes()->withoutOverlapping();

Artisan::command('crm:escalate-overdue-follow-ups', function (EscalateOverdueFollowUps $escalation) {
    Organization::query()->where('crm_follow_up_escalation_enabled', true)->orderBy('id')->chunkById(100, function ($organizations) use ($escalation): void {
        foreach ($organizations as $organization) {
            $escalation->handle($organization);
        }
    });
})->purpose('Notify scoped CRM managers and organization administrators about overdue follow-ups');

Schedule::command('crm:escalate-overdue-follow-ups')->everyFifteenMinutes()->withoutOverlapping();

Artisan::command('operations:generate-preventive', function (GenerateDuePreventiveMaintenance $generation) {
    $result = $generation->handle();
    $this->info('Created '.$result['created'].' preventive jobs; '.$result['failed'].' plans failed.');

    return $result['failed'] === 0 ? 0 : 1;
})->purpose('Generate each missed preventive occurrence for opted-in active plans');

Schedule::useCache('redis');
Schedule::command('operations:generate-preventive')->everyFifteenMinutes()->withoutOverlapping()->onOneServer();

Artisan::command('operations:notify-sla-breaches', function (NotifySlaBreaches $notifications) {
    $this->info('Created '.$notifications->handle().' SLA breach notifications.');
})->purpose('Notify operations managers once per breached SLA target and cycle');

Schedule::command('operations:notify-sla-breaches')->everyFifteenMinutes()->withoutOverlapping()->onOneServer();

Artisan::command('reports:generate-private', function (GeneratePrivateReports $reports) {
    $result = $reports->handle();
    $this->info('Generated '.$result['generated'].' private reports; '.$result['failed'].' failed or blocked.');

    return $result['failed'] === 0 ? 0 : 1;
})->purpose('Deliver opted-in private operations reports to their creators');
Schedule::command('reports:generate-private')->everyFifteenMinutes()->withoutOverlapping()->onOneServer();

Artisan::command('crm:run-due-automation', function (ExecuteAutomationRule $automation) {
    $result = $automation->due();
    $this->info('Processed '.$result['processed'].' automation executions; '.$result['failed'].' failed.');

    return $result['failed'] === 0 ? 0 : 1;
})->purpose('Execute bounded internal CRM automation with stale-event and replay protection');
Schedule::command('crm:run-due-automation')->everyMinute()->withoutOverlapping()->onOneServer();

Artisan::command('crm:run-due-deal-automation', function (ManageDealAutomation $automation) {
    $result = $automation->due();
    $this->info('Processed '.$result['processed'].' deal automation executions; '.$result['failed'].' failed.');

    return $result['failed'] === 0 ? 0 : 1;
})->purpose('Execute bounded internal deal automation with stale-event and replay protection');
Schedule::command('crm:run-due-deal-automation')->everyMinute()->withoutOverlapping()->onOneServer();
