<?php

namespace App\Domain\Platform\Actions;

use App\Domain\Identity\Actions\RecordOrganizationAuditLog;
use App\Domain\Platform\Models\PersonalReadToken;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class ManageReadTokens
{
    public const ABILITIES = ['jobs:read' => 'viewOperations', 'properties:read' => 'viewInventory', 'leads:read' => 'viewCrm', 'invoices:read' => 'viewFinance'];

    public function __construct(private RecordOrganizationAuditLog $audit) {}

    /** @param array<string,mixed> $input
     * @return array{record:PersonalReadToken,secret:string} */
    public function create(Organization $org, User $actor, array $input): array
    {
        abort_unless($actor->hasVerifiedEmail() && $actor->belongsToOrganization($org), 403);
        $data = Validator::make($input, ['name' => ['required', 'string', 'max:100'], 'days' => ['required', 'integer', 'between:1,90'], 'abilities' => ['required', 'array', 'min:1', 'max:4'], 'abilities.*' => ['required', 'distinct', Rule::in(array_keys(self::ABILITIES))]])->validate();
        $name = trim($data['name']);
        if ($name === '') {
            throw ValidationException::withMessages(['name' => 'Enter a token name.']);
        }
        foreach ($data['abilities'] as $ability) {
            abort_unless($actor->can(self::ABILITIES[$ability], $org), 403);
        }

        return DB::transaction(function () use ($org, $actor, $data, $name): array {
            Organization::whereKey($org->id)->lockForUpdate()->firstOrFail();
            abort_if(PersonalReadToken::where('organization_id', $org->id)->where('user_id', $actor->id)->whereNull('revoked_at')->where('expires_at', '>', now())->count() >= 20, 422, 'Revoke a token before creating more than 20.');
            $secret = bin2hex(random_bytes(32));
            $record = PersonalReadToken::create(['organization_id' => $org->id, 'user_id' => $actor->id, 'name' => $name, 'abilities' => $data['abilities'], 'token_hash' => hash('sha256', $secret), 'expires_at' => now()->addDays((int) $data['days'])]);
            $this->audit->handle($org, $actor, 'platform.api_token.created', $record, ['abilities' => $data['abilities'], 'expires_at' => $record->expires_at->toIso8601String()]);

            return ['record' => $record, 'secret' => $secret];
        });
    }

    public function revoke(Organization $org, User $actor, int $id): void
    {
        abort_unless($actor->belongsToOrganization($org), 403);
        DB::transaction(function () use ($org, $actor, $id): void {
            $token = PersonalReadToken::where('organization_id', $org->id)->where('user_id', $actor->id)->lockForUpdate()->findOrFail($id);
            if ($token->revoked_at !== null) {
                return;
            }
            $token->update(['revoked_at' => now()]);
            $this->audit->handle($org, $actor, 'platform.api_token.revoked', $token, []);
        });
    }
}
