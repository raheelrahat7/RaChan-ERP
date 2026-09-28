<?php

namespace App\Http\Controllers;

use App\Domain\RealEstate\Actions\RecordListingInquiry;
use App\Models\Listing;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class PublicListingController extends Controller
{
    public function show(string $token): Response
    {
        $listing = $this->listing($token);
        $listing->load(['unit:id,property_id,number,type,area,area_unit', 'unit.property:id,name,city']);

        return Inertia::render('public/Listing', [
            'listing' => [
                ...$listing->only('reference', 'purpose', 'price', 'currency'),
                'unit' => $listing->unit?->only('number', 'type', 'area', 'area_unit'),
                'property' => $listing->unit?->property?->only('name', 'city'),
            ],
        ]);
    }

    public function inquire(Request $request, string $token, RecordListingInquiry $record): RedirectResponse
    {
        $listing = $this->listing($token);
        $input = $request->validate([
            'first_name' => ['required', 'string', 'max:100'],
            'last_name' => ['required', 'string', 'max:100'],
            'email' => ['nullable', 'email', 'max:255', 'required_without:phone'],
            'phone' => ['nullable', 'string', 'max:50', 'required_without:email'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'website' => ['nullable', 'max:0'],
        ]);
        unset($input['website']);
        $record->handlePublic($listing, $input);

        return back()->with('success', 'Thank you. Your inquiry has been received.');
    }

    private function listing(string $token): Listing
    {
        return Listing::where('public_token', $token)->where('status', 'active')->firstOrFail();
    }
}
