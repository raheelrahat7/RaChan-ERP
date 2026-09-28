<?php

namespace App\Http\Controllers;

use App\Domain\Crm\Actions\RecordDemoRequest;
use App\Http\Requests\DemoRequest;
use App\Models\Organization;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Log;

class MarketingDemoRequestController extends Controller
{
    public function __invoke(DemoRequest $request, RecordDemoRequest $record): RedirectResponse
    {
        $slug = config('marketing.lead_organization');
        $organization = filled($slug) ? Organization::query()->where('slug', $slug)->first() : null;

        if (! $organization) {
            Log::warning('Marketing demo request is unavailable: lead organization is not configured.');

            return back()->withErrors(['demo' => 'Demo requests are temporarily unavailable. Please try again later.']);
        }

        $record->handle($organization, $request->validated());

        return back()->with('success', 'Thanks. We will contact you about a demo soon.');
    }
}
