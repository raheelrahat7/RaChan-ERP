<?php

namespace App\Domain\Crm\Jobs;

use App\Domain\Crm\Actions\ManageLeadPipeline;
use App\Domain\Crm\Models\MetaImport;
use App\Domain\Crm\Models\MetaPage;
use App\Models\CrmLead;
use App\Models\Organization;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class ProcessMetaLead implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public function __construct(public int $importId) {}

    public function handle(ManageLeadPipeline $manage): void
    {
        $import = MetaImport::findOrFail($this->importId);
        if ($import->status === 'processed') {
            return;
        }
        $page = MetaPage::where('organization_id', $import->organization_id)->where('active', true)->findOrFail($import->meta_page_id);
        $version = $page->graph_version ?: config('services.meta.graph_version');
        if (! is_string($version) || ! preg_match('/^v\d+\.\d+$/', $version)) {
            $import->update(['status' => 'failed', 'error' => 'Enter a Graph API version for this Page before processing Meta leads.']);

            return;
        }
        $leadData = Http::withToken($page->page_access_token)->timeout(15)->retry(2, 300)
            ->get('https://graph.facebook.com/'.$version.'/'.$import->leadgen_id, ['fields' => 'id,field_data,form_id,campaign_name,ad_id'])
            ->throw()->json();
        if (! is_array($leadData) || (string) ($leadData['id'] ?? '') !== $import->leadgen_id) {
            throw new \RuntimeException('Meta lead ID did not match the webhook.');
        }
        $formId = (string) ($leadData['form_id'] ?? $import->form_id ?? '');
        $formName = null;
        if ($formId !== '' && preg_match('/^\d+$/', $formId)) {
            $response = Http::withToken($page->page_access_token)->timeout(15)->get('https://graph.facebook.com/'.$version.'/'.$formId, ['fields' => 'name']);
            if ($response->successful()) {
                $formName = $response->json('name');
            }
        }
        $fields = [];
        foreach ($leadData['field_data'] ?? [] as $item) {
            if (is_array($item) && isset($item['name']) && is_array($item['values'] ?? null)) {
                $fields[mb_strtolower((string) $item['name'])] = (string) ($item['values'][0] ?? '');
            }
        }
        $fullName = trim($fields['full_name'] ?? '');
        $parts = $fullName === '' ? [] : preg_split('/\s+/', $fullName, 2);
        $first = trim($fields['first_name'] ?? ($parts[0] ?? ''));
        $last = trim($fields['last_name'] ?? ($parts[1] ?? ''));
        $data = [
            'first_name' => $first !== '' ? Str::limit($first, 100, '') : 'Meta',
            'last_name' => $last !== '' ? Str::limit($last, 100, '') : 'Lead '.Str::limit($import->leadgen_id, 90, ''),
            'email' => ($fields['email'] ?? '') ?: null,
            'phone' => ($fields['phone_number'] ?? $fields['phone'] ?? '') ?: null,
            'company' => ($fields['company_name'] ?? '') ?: null,
            'source' => 'Meta',
            'project_name' => ($fields['project_name'] ?? $fields['project'] ?? '') ?: null,
            'campaign_name' => ($leadData['campaign_name'] ?? '') ?: null,
            'meta_form_id' => $formId ?: null,
            'meta_form_name' => is_string($formName) ? $formName : null,
            'meta_page_id' => $page->page_id,
            'meta_lead_id' => $import->leadgen_id,
        ];
        $existing = CrmLead::where('meta_lead_id', $import->leadgen_id)->first();
        $lead = $existing ?? $manage->createImported(Organization::findOrFail($import->organization_id), $data);
        $import->update(['status' => 'processed', 'error' => null, 'lead_id' => $lead->id, 'form_id' => $formId ?: null]);
    }

    public function failed(\Throwable $exception): void
    {
        MetaImport::whereKey($this->importId)->where('status', '!=', 'processed')->update([
            'status' => 'failed',
            'error' => Str::limit($exception->getMessage(), 500),
        ]);
    }
}
