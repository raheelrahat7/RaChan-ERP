<?php

namespace App\Domain\Accounting\Actions;

use App\Domain\Accounting\Models\VatReturn;
use App\Domain\Accounting\Models\VatReturnAdjustment;
use App\Domain\Accounting\Models\VatSettlement;
use App\Domain\Identity\Actions\RecordOrganizationAuditLog;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class ManageVatReturn
{
    public function __construct(private RecordOrganizationAuditLog $audit) {}

    /**
     * @param  array<string, mixed>  $report
     */
    public function prepare(Organization $organization, User $actor, array $report): VatReturn
    {
        return DB::transaction(function () use ($organization, $actor, $report) {
            $return = VatReturn::where('organization_id', $organization->id)->where('starts_on', $report['from'])->where('ends_on', $report['to'])->lockForUpdate()->first();
            abort_if($return?->status === 'filed', 422, 'A filed VAT return snapshot cannot be replaced.');
            $return ??= new VatReturn(['organization_id' => $organization->id, 'starts_on' => $report['from'], 'ends_on' => $report['to']]);
            $return->fill(['status' => 'prepared', 'snapshot' => $report, 'prepared_by' => $actor->id])->save();
            $this->audit->handle($organization, $actor, 'accounting.vat_return.prepared', $return);

            return $return;
        });
    }

    /**
     * @param  array<string, mixed>  $input
     */
    public function file(Organization $organization, User $actor, VatReturn $return, array $input): void
    {
        abort_unless($return->organization_id === $organization->id && $return->status === 'prepared', 422);
        $return->update(['status' => 'filed', 'filed_by' => $actor->id, 'filed_on' => $input['filed_on'], 'fta_reference' => trim($input['fta_reference'])]);
        $this->audit->handle($organization, $actor, 'accounting.vat_return.filed', $return, $input);
    }

    /**
     * @param  array<string, mixed>  $input
     */
    public function adjust(Organization $organization, User $actor, VatReturn $return, array $input): VatReturnAdjustment
    {
        abort_unless($return->organization_id === $organization->id && $return->status === 'filed', 422, 'Adjustments require a filed VAT return.');
        abort_if(VatSettlement::where('vat_return_id', $return->id)->whereNull('reversal_journal_entry_id')->exists(), 422, 'Reverse the active VAT settlement before recording an adjustment.');
        $effect = round((float) $input['output_vat_delta'] - (float) $input['input_vat_delta'], 2);
        abort_if(abs($effect) > 10000 && $input['correction_method'] !== 'voluntary_disclosure', 422, 'VAT effect above AED 10,000 requires voluntary disclosure.');
        abort_if($effect === 0.0, 422, 'Adjustment must change net VAT.');
        $adjustment = VatReturnAdjustment::create(['organization_id' => $organization->id, 'vat_return_id' => $return->id, ...$input, 'recorded_by' => $actor->id]);
        $this->audit->handle($organization, $actor, 'accounting.vat_return.adjustment_recorded', $adjustment, ['net_vat_effect' => $effect, 'voluntary_disclosure_required' => abs($effect) > 10000]);

        return $adjustment;
    }
}
