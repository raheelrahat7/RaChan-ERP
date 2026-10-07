<?php

namespace App\Domain\Crm\Services;

class LeadRequirementSchema
{
    public const CHOICES = [
        'type' => ['buyer' => 'Buyer', 'tenant' => 'Tenant', 'investor' => 'Investor', 'seller' => 'Seller', 'landlord' => 'Landlord'],
        'purpose' => ['buy' => 'Buy', 'rent' => 'Rent', 'sell' => 'Sell', 'lease' => 'Lease'],
        'unit_category' => ['residential' => 'Residential', 'commercial' => 'Commercial', 'industrial' => 'Industrial', 'land' => 'Land'],
        'emirate' => ['abu_dhabi' => 'Abu Dhabi', 'dubai' => 'Dubai', 'sharjah' => 'Sharjah', 'ajman' => 'Ajman', 'umm_al_quwain' => 'Umm Al Quwain', 'ras_al_khaimah' => 'Ras Al Khaimah', 'fujairah' => 'Fujairah'],
        'property_type' => ['apartment' => 'Apartment', 'villa' => 'Villa', 'townhouse' => 'Townhouse', 'penthouse' => 'Penthouse', 'studio' => 'Studio', 'office' => 'Office', 'shop' => 'Shop', 'warehouse' => 'Warehouse', 'land' => 'Land'],
        'furnishing' => ['furnished' => 'Furnished', 'semi_furnished' => 'Semi-furnished', 'unfurnished' => 'Unfurnished'],
        'rent_frequency' => ['yearly' => 'Yearly', 'quarterly' => 'Quarterly', 'monthly' => 'Monthly', 'weekly' => 'Weekly', 'daily' => 'Daily'],
        'timeline' => ['immediate' => 'Immediate', 'one_to_three_months' => '1–3 months', 'three_to_six_months' => '3–6 months', 'six_to_twelve_months' => '6–12 months', 'flexible' => 'Flexible'],
        'completion_status' => ['ready' => 'Ready', 'off_plan' => 'Off-plan', 'under_construction' => 'Under construction'],
        'payment_method' => ['cash' => 'Cash', 'mortgage' => 'Mortgage', 'payment_plan' => 'Payment plan'],
        'financing_status' => ['not_started' => 'Not started', 'applied' => 'Applied', 'pre_approved' => 'Pre-approved', 'approved' => 'Approved', 'not_required' => 'Not required'],
        'language' => ['en' => 'English', 'ar' => 'Arabic', 'ur' => 'Urdu', 'hi' => 'Hindi', 'ru' => 'Russian', 'zh' => 'Chinese'],
        'amenities' => ['pool' => 'Pool', 'gym' => 'Gym', 'parking' => 'Parking', 'balcony' => 'Balcony', 'garden' => 'Garden', 'security' => 'Security'],
        'temperature' => ['cold' => 'Cold', 'warm' => 'Warm', 'hot' => 'Hot'],
    ];

    public const DECIMALS = ['size_min', 'size_max', 'budget_min', 'budget_max', 'down_payment_percent', 'roi_percent'];

    public const INTEGERS = ['bedrooms_min', 'bedrooms_max', 'bathrooms_min', 'lead_score'];

    /** @return array<string, mixed> */
    public function defaults(): array
    {
        return [...array_fill_keys([...array_keys(self::CHOICES), ...self::DECIMALS, ...self::INTEGERS, 'location', 'size_unit', 'budget_currency', 'handover_on', 'preferences'], null), 'amenities' => []];
    }

    /** @return array<string, list<array{value: string, label: string, active: bool}>> */
    public function defaultChoices(): array
    {
        $choices = [];
        foreach (self::CHOICES as $field => $options) {
            $choices[$field] = [];
            foreach ($options as $value => $label) {
                $choices[$field][] = ['value' => $value, 'label' => $label, 'active' => true];
            }
        }

        return $choices;
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        $rules = ['expected_version' => ['required', 'integer', 'min:0'], 'data' => ['required', 'array:'.implode(',', array_keys($this->defaults())), 'min:1']];
        foreach (array_keys(self::CHOICES) as $field) {
            $rules['data.'.$field] = ['sometimes', 'nullable', 'string', 'max:60'];
        }
        foreach (self::DECIMALS as $field) {
            $rules['data.'.$field] = ['sometimes', 'nullable', 'regex:/^\d{1,12}(?:\.\d{1,2})?$/'];
        }
        foreach (self::INTEGERS as $field) {
            $rules['data.'.$field] = ['sometimes', 'nullable', 'integer', 'between:0,100'];
        }
        $rules['data.down_payment_percent'][] = 'numeric';
        $rules['data.down_payment_percent'][] = 'between:0,100';
        $rules['data.roi_percent'][] = 'numeric';
        $rules['data.roi_percent'][] = 'between:0,1000';

        return [...$rules,
            'data.location' => ['sometimes', 'nullable', 'string', 'max:255'],
            'data.size_unit' => ['sometimes', 'nullable', 'in:sq_ft,sq_m'],
            'data.budget_currency' => ['sometimes', 'nullable', 'regex:/^[A-Z]{3}$/'],
            'data.handover_on' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'data.preferences' => ['sometimes', 'nullable', 'string', 'max:10000'],
            'data.amenities' => ['sometimes', 'array', 'list', 'max:50'],
            'data.amenities.*' => ['required', 'string', 'max:60', 'distinct'],
        ];
    }

    /** @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function normalize(array $data): array
    {
        foreach (self::DECIMALS as $field) {
            if (isset($data[$field])) {
                [$whole, $fraction] = array_pad(explode('.', (string) $data[$field], 2), 2, '');
                $data[$field] = (ltrim($whole, '0') ?: '0').'.'.str_pad($fraction, 2, '0');
            }
        }
        foreach (self::INTEGERS as $field) {
            if (isset($data[$field])) {
                $data[$field] = (int) $data[$field];
            }
        }

        return $data;
    }
}
