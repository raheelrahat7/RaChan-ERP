<?php

namespace App\Domain\Crm\Actions;

use App\Models\CrmLead;
use App\Models\Organization;

class RecordDemoRequest
{
    public function __construct(private ManageLeadPipeline $pipeline) {}

    /** @param array<string, mixed> $data */
    public function handle(Organization $organization, array $data): CrmLead
    {
        $notes = collect([
            filled($data['role'] ?? null) ? 'Role: '.$data['role'] : null,
            filled($data['portfolio_size'] ?? null) ? 'Portfolio size: '.$data['portfolio_size'] : null,
            filled($data['preferred_language'] ?? null) ? 'Preferred language: '.$data['preferred_language'] : null,
            filled($data['message'] ?? null) ? 'Message: '.$data['message'] : null,
        ])->filter()->implode("\n");

        return $this->pipeline->createPublicInquiry($organization, [
            'first_name' => $data['first_name'],
            'last_name' => $data['last_name'],
            'email' => $data['email'],
            'phone' => $data['phone'] ?? null,
            'company' => $data['company'],
            'source' => 'Website demo request',
            'campaign_name' => 'Marketing website',
            'notes' => $notes ?: null,
        ], 'Submitted through the marketing demo request form.');
    }
}
