<?php

namespace App\Domain\Finance\Services;

use App\Domain\Finance\Models\VendorCashRefund;
use App\Domain\Finance\Models\VendorCreditNote;
use App\Models\JournalEntry;
use App\Models\VendorBill;
use App\Models\VendorBillPayment;

class VendorRefundCapacity
{
    public function __construct(private InvoiceBalance $amounts) {}

    public function available(VendorBill $bill, string $date): int
    {
        $reversals = JournalEntry::where('organization_id', $bill->organization_id)->whereNotNull('reversal_of_id')->whereDate('posted_on', '<=', $date)->select('reversal_of_id');
        $postedPayments = JournalEntry::where('organization_id', $bill->organization_id)->where('event', 'vendor_bill.paid')->where('subject_type', (new VendorBillPayment)->getMorphClass())->where('currency', 'AED')->whereDate('posted_on', '<=', $date)->whereNotIn('id', $reversals)->select('subject_id');
        $cash = VendorBillPayment::where('organization_id', $bill->organization_id)->where('vendor_bill_id', $bill->id)->whereDate('paid_on', '<=', $date)->whereIn('id', $postedPayments)->sum('amount');
        $credits = VendorCreditNote::where('organization_id', $bill->organization_id)->where('vendor_bill_id', $bill->id)->whereNotNull('journal_entry_id')->whereDate('posted_on', '<=', $date)->where(fn ($q) => $q->whereNull('reversed_on')->orWhereDate('reversed_on', '>', $date))->sum('amount');

        return max(0, $this->amounts->cents($cash) + $this->amounts->cents($credits) - $this->amounts->cents($bill->total) - $this->received($bill, $date));
    }

    public function received(VendorBill $bill, ?string $date = null): int
    {
        $query = VendorCashRefund::where('organization_id', $bill->organization_id)->where('vendor_bill_id', $bill->id)->whereNotNull('journal_entry_id');
        if ($date !== null) {
            $query->whereDate('posted_on', '<=', $date)->where(fn ($q) => $q->whereNull('reversed_on')->orWhereDate('reversed_on', '>', $date));
        } else {
            $query->whereNull('reversal_journal_entry_id');
        }

        return $this->amounts->cents($query->sum('amount'));
    }

    public function hasActive(int $orgId, int $billId): bool
    {
        return VendorCashRefund::where('organization_id', $orgId)->where('vendor_bill_id', $billId)->whereNotNull('journal_entry_id')->whereNull('reversal_journal_entry_id')->exists();
    }
}
