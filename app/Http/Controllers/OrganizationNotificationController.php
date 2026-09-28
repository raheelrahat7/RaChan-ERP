<?php

namespace App\Http\Controllers;

use App\Domain\Identity\Actions\RecordOrganizationAuditLog;
use App\Domain\Notifications\Models\NotificationPreference;
use App\Domain\Notifications\Models\OrganizationNotification;
use App\Support\CurrentOperationalAlerts;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class OrganizationNotificationController extends Controller
{
    public function index(Request $request, CurrentOperationalAlerts $alerts): Response
    {
        $organization = $request->user()->currentOrganization;
        abort_unless($organization !== null, 404);
        $this->authorize('view', $organization);
        $preferences = NotificationPreference::where('organization_id', $organization->id)->first();

        return Inertia::render('notifications/Index', [
            'notifications' => OrganizationNotification::where('organization_id', $organization->id)->where('user_id', $request->user()->id)->latest()->paginate(20),
            'unreadCount' => OrganizationNotification::where('organization_id', $organization->id)->where('user_id', $request->user()->id)->whereNull('read_at')->count(),
            'preferences' => ['daily_digest_enabled' => $preferences->daily_digest_enabled ?? true, 'enabled_categories' => $preferences->enabled_categories ?? $alerts->categories()],
            'categories' => $alerts->categories(),
            'canManageSettings' => $request->user()->can('update', $organization),
        ]);
    }

    public function updatePreferences(Request $request, CurrentOperationalAlerts $alerts, RecordOrganizationAuditLog $audit): RedirectResponse
    {
        $organization = $request->user()->currentOrganization;
        abort_unless($organization !== null, 404);
        $this->authorize('update', $organization);
        $input = $request->validate(['daily_digest_enabled' => ['required', 'boolean'], 'enabled_categories' => ['present', 'array'], 'enabled_categories.*' => ['string', 'distinct', Rule::in($alerts->categories())]]);
        $preference = NotificationPreference::updateOrCreate(['organization_id' => $organization->id], $input);
        $audit->handle($organization, $request->user(), 'notifications.preferences.updated', $preference);

        return back();
    }

    public function markRead(Request $request, OrganizationNotification $notification): RedirectResponse
    {
        $organization = $request->user()->currentOrganization;
        abort_unless($organization && $notification->organization_id === $organization->id && $notification->user_id === $request->user()->id, 404);
        $notification->update(['read_at' => $notification->read_at ?? now()]);

        return back();
    }

    public function markAllRead(Request $request): RedirectResponse
    {
        $organization = $request->user()->currentOrganization;
        abort_unless($organization !== null, 404);
        OrganizationNotification::where('organization_id', $organization->id)->where('user_id', $request->user()->id)->whereNull('read_at')->update(['read_at' => now()]);

        return back();
    }
}
