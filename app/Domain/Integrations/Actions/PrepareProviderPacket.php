<?php

namespace App\Domain\Integrations\Actions;

use App\Domain\Documents\Services\DocumentAccess;
use App\Domain\Finance\Services\InvoiceBalance;
use App\Domain\Identity\Actions\RecordOrganizationAuditLog;
use App\Domain\Integrations\Data\ProviderPacket;
use App\Domain\Integrations\Services\LocalOutboundProvider;
use App\Models\Document;
use App\Models\Invoice;
use App\Models\Listing;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class PrepareProviderPacket
{
    /** @param array<string, mixed> $input
     * @return array<string, mixed> */
    public function handle(Organization $org, User $actor, array $input): array
    {
        Gate::forUser($actor)->authorize('manageSettings', $org);
        $data = Validator::make($input, ['profile_id' => ['required', 'integer'], 'operation_key' => ['required', 'uuid'], 'payload' => ['required', 'array']])->validate();

        return DB::transaction(function () use ($org, $actor, $data): array {
            Organization::whereKey($org->id)->lockForUpdate()->firstOrFail();
            $profile = DB::table('organization_provider_profiles')->where('organization_id', $org->id)->where('id', $data['profile_id'])->first();
            abort_unless($profile !== null, 404);
            $validated = app(LocalOutboundProvider::class)->validate(new ProviderPacket($profile->capability, $org->id, $data['operation_key'], $data['payload']));
            if ($profile->capability === 'payment') {
                Gate::forUser($actor)->authorize('manageFinance', $org);
                $invoice = Invoice::where('organization_id', $org->id)->findOrFail((int) $data['payload']['invoice_id']);
                if ($data['payload']['reference'] !== $invoice->reference || $data['payload']['currency'] !== $invoice->currency || ! in_array($invoice->status, ['posted', 'partial'], true) || app(InvoiceBalance::class)->cents($data['payload']['amount']) > app(InvoiceBalance::class)->outstandingCents($invoice)) {
                    throw ValidationException::withMessages(['payload' => 'Use an outstanding posted invoice, matching reference/currency and an amount within its balance.']);
                }
            }
            if ($profile->capability === 'signature') {
                $document = Document::where('organization_id', $org->id)->findOrFail((int) $data['payload']['document_id']);
                app(DocumentAccess::class)->authorize($org, $actor, $document, true);
                if ($document->archived_at || $document->version_number !== (int) $data['payload']['version_number'] || $document->content_hash !== $data['payload']['sha256']) {
                    throw ValidationException::withMessages(['payload' => 'Use an active document with its current version and content hash.']);
                }
            }
            if ($profile->capability === 'property_portal') {
                Gate::forUser($actor)->authorize('manageTransactions', $org);
                $listing = Listing::where('organization_id', $org->id)->findOrFail((int) $data['payload']['listing_id']);
                if ($listing->reference !== $data['payload']['reference'] || $listing->purpose !== $data['payload']['purpose'] || $listing->currency !== $data['payload']['currency'] || (string) $listing->price !== $data['payload']['price']) {
                    throw ValidationException::withMessages(['payload' => 'Use the current listing reference, purpose, price and currency.']);
                }
            }
            $existing = DB::table('organization_provider_packets')->where('organization_id', $org->id)->where('operation_key', $data['operation_key'])->first();
            if ($existing) {
                if ($existing->profile_id !== $profile->id || $existing->sha256 !== $validated['sha256']) {
                    throw ValidationException::withMessages(['operation_key' => 'This key already belongs to different packet details.']);
                }

                return ['id' => $existing->id, ...$validated, 'deliveryEnabled' => false];
            }
            $settings = json_decode($profile->settings ?? '{}', true);
            $limit = $settings['daily_limit'] ?? null;
            if ($profile->capability === 'sms' && $limit !== null && DB::table('organization_provider_packets')->where('organization_id', $org->id)->where('profile_id', $profile->id)->whereBetween('created_at', [now($org->timezone)->startOfDay()->utc(), now($org->timezone)->endOfDay()->utc()])->count() >= $limit) {
                throw ValidationException::withMessages(['profile_id' => 'The local daily preparation limit has been reached.']);
            }
            $id = DB::table('organization_provider_packets')->insertGetId(['organization_id' => $org->id, 'profile_id' => $profile->id, 'created_by' => $actor->id, 'operation_key' => $data['operation_key'], 'sha256' => $validated['sha256'], 'payload' => json_encode($validated['packet']), 'created_at' => now(), 'updated_at' => now()]);
            app(RecordOrganizationAuditLog::class)->handle($org, $actor, 'integrations.packet.local_prepared', null, ['packet_id' => $id, 'capability' => $profile->capability]);

            return ['id' => $id, ...$validated, 'deliveryEnabled' => false];
        });
    }
}
