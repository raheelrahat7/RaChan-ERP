<?php

namespace App\Http\Middleware;

use App\Domain\Platform\Actions\ManageReadTokens;
use App\Domain\Platform\Models\PersonalReadToken;
use App\Models\Organization;
use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AuthenticateReadToken
{
    public function handle(Request $request, Closure $next, string $ability): Response
    {
        $secret = $request->bearerToken();
        abort_unless(is_string($secret) && preg_match('/^[a-f0-9]{64}$/D', $secret) === 1, 401);
        $token = PersonalReadToken::where('token_hash', hash('sha256', $secret))->whereNull('revoked_at')->where('expires_at', '>', now())->first();
        abort_unless($token !== null, 401);
        $actor = User::find($token->user_id);
        $org = Organization::find($token->organization_id);
        abort_unless($actor !== null && $org !== null && $actor->hasVerifiedEmail() && $actor->belongsToOrganization($org), 401);
        abort_unless(in_array($ability, $token->abilities, true) && isset(ManageReadTokens::ABILITIES[$ability]) && $actor->can(ManageReadTokens::ABILITIES[$ability], $org), 403);
        $request->setUserResolver(fn () => $actor);
        $request->attributes->set('api_organization', $org);
        $response = $next($request);
        $response->headers->set('Cache-Control', 'private, no-store');

        return $response;
    }
}
