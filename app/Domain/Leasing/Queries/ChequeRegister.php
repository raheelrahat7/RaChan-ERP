<?php

namespace App\Domain\Leasing\Queries;

use App\Models\Organization;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

class ChequeRegister
{
    /** @return LengthAwarePaginator<int, \stdClass> */
    public function for(Organization $org, ?string $status, ?string $from, ?string $to): LengthAwarePaginator
    {
        return DB::table('lease_cheques as cheque')->join('leases as lease', 'lease.id', '=', 'cheque.lease_id')
            ->where('cheque.organization_id', $org->id)->where('lease.organization_id', $org->id)
            ->when($status, fn ($query) => $query->where('cheque.status', $status))
            ->when($from, fn ($query) => $query->where('cheque.due_on', '>=', $from))
            ->when($to, fn ($query) => $query->where('cheque.due_on', '<=', $to))
            ->select('cheque.*', 'lease.reference as lease_reference')
            ->orderBy('cheque.due_on')->paginate(30)->withQueryString();
    }
}
