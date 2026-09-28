<?php

namespace App\Http\Controllers;

use App\Domain\Platform\Actions\ManageReadTokens;
use App\Domain\Platform\Models\PersonalReadToken;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ReadTokenController extends Controller
{
    public function index(Request $request): Response
    {
        $org = $request->user()->currentOrganization;
        abort_unless($org !== null && $request->user()->belongsToOrganization($org), 403);

        return Inertia::render('organization/ApiTokens', ['tokens' => PersonalReadToken::where('organization_id', $org->id)->where('user_id', $request->user()->id)->latest()->paginate(20), 'abilities' => array_keys(array_filter(ManageReadTokens::ABILITIES, fn ($permission): bool => $request->user()->can($permission, $org))), 'secret' => $request->session()->get('api_token_secret')]);
    }

    public function store(Request $request, ManageReadTokens $tokens): RedirectResponse
    {
        $org = $request->user()->currentOrganization;
        abort_unless($org !== null, 403);
        $result = $tokens->create($org, $request->user(), $request->only(['name', 'days', 'abilities']));

        return back()->with('api_token_secret', $result['secret']);
    }

    public function revoke(Request $request, int $token, ManageReadTokens $tokens): RedirectResponse
    {
        $org = $request->user()->currentOrganization;
        abort_unless($org !== null, 403);
        $tokens->revoke($org, $request->user(), $token);

        return back();
    }
}
