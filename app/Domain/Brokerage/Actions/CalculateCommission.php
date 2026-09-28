<?php

namespace App\Domain\Brokerage\Actions;

use App\Models\Broker;
use App\Models\CommissionPlan;
use App\Models\CommissionTransaction;
use Illuminate\Database\Eloquent\Model;

class CalculateCommission
{
    public function handle(Broker $broker, Model $source, float $baseAmount, CommissionPlan $plan): CommissionTransaction
    {
        $amount = $plan->basis === 'fixed'
            ? (float) $plan->rate
            : round($baseAmount * ((float) $plan->rate / 100), 2);

        return CommissionTransaction::create([
            'organization_id' => $broker->organization_id,
            'broker_id' => $broker->id,
            'commission_plan_id' => $plan->id,
            'source_type' => $source->getMorphClass(),
            'source_id' => $source->getKey(),
            'base_amount' => $baseAmount,
            'commission_amount' => $amount,
            'currency' => 'AED',
            'status' => 'calculated',
            'payable_on' => now()->toDateString(),
        ]);
    }
}
