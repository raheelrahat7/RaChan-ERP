<?php

namespace App\Http\Controllers;

use App\Domain\Crm\Jobs\ProcessMetaLead;
use App\Domain\Crm\Models\MetaImport;
use App\Domain\Crm\Models\MetaPage;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class MetaLeadWebhookController extends Controller
{
    public function verify(Request $request): Response
    {
        $token = config('services.meta.verify_token');
        abort_unless(is_string($token) && $token !== '', 503);
        $mode = $request->query('hub_mode', $request->query('hub.mode'));
        $supplied = $request->query('hub_verify_token', $request->query('hub.verify_token'));
        abort_unless($mode === 'subscribe' && is_string($supplied) && hash_equals($token, $supplied), 403);
        $challenge = $request->query('hub_challenge', $request->query('hub.challenge'));
        abort_unless(is_string($challenge), 400);

        return response($challenge, 200)->header('Content-Type', 'text/plain');
    }

    public function receive(Request $request): Response
    {
        $secret = config('services.meta.app_secret');
        abort_unless(is_string($secret) && $secret !== '', 503);
        $expected = 'sha256='.hash_hmac('sha256', $request->getContent(), $secret);
        abort_unless(hash_equals($expected, (string) $request->header('X-Hub-Signature-256')), 403);

        return $this->import($request);
    }

    public function verifyPage(Request $request, string $webhookKey): Response
    {
        $page = MetaPage::where('webhook_key', $webhookKey)->where('active', true)->firstOrFail();
        abort_unless(is_string($page->verify_token) && $page->verify_token !== '', 503);
        $mode = $request->query('hub_mode', $request->query('hub.mode'));
        $supplied = $request->query('hub_verify_token', $request->query('hub.verify_token'));
        abort_unless($mode === 'subscribe' && is_string($supplied) && hash_equals($page->verify_token, $supplied), 403);
        $challenge = $request->query('hub_challenge', $request->query('hub.challenge'));
        abort_unless(is_string($challenge), 400);

        return response($challenge, 200)->header('Content-Type', 'text/plain');
    }

    public function receivePage(Request $request, string $webhookKey): Response
    {
        $page = MetaPage::where('webhook_key', $webhookKey)->where('active', true)->firstOrFail();
        abort_unless(is_string($page->app_secret) && $page->app_secret !== '', 503);
        $expected = 'sha256='.hash_hmac('sha256', $request->getContent(), $page->app_secret);
        abort_unless(hash_equals($expected, (string) $request->header('X-Hub-Signature-256')), 403);

        return $this->import($request, $page);
    }

    private function import(Request $request, ?MetaPage $connectedPage = null): Response
    {
        $payload = $request->json()->all();
        abort_unless(($payload['object'] ?? null) === 'page' && is_array($payload['entry'] ?? null), 400);

        foreach ($payload['entry'] as $entry) {
            if (! is_array($entry)) {
                continue;
            }
            $pageId = (string) ($entry['id'] ?? '');
            $page = $connectedPage
                ? MetaPage::where('organization_id', $connectedPage->organization_id)->where('page_id', $pageId)->where('active', true)->first()
                : MetaPage::where('page_id', $pageId)->where('active', true)->whereNull('app_secret')->first();
            if ($connectedPage && $page && (! is_string($page->app_secret) || ! hash_equals($connectedPage->app_secret, $page->app_secret))) {
                $page = null;
            }
            if (! $page) {
                continue;
            }
            foreach ($entry['changes'] ?? [] as $change) {
                if (! is_array($change) || ($change['field'] ?? null) !== 'leadgen') {
                    continue;
                }
                $value = $change['value'] ?? [];
                $leadgenId = (string) ($value['leadgen_id'] ?? '');
                if (! preg_match('/^\d+$/', $leadgenId) || (string) ($value['page_id'] ?? $pageId) !== $pageId) {
                    continue;
                }
                $import = MetaImport::firstOrCreate(['leadgen_id' => $leadgenId], ['organization_id' => $page->organization_id, 'meta_page_id' => $page->id, 'form_id' => (string) ($value['form_id'] ?? '') ?: null]);
                $retry = $import->organization_id === $page->organization_id && $import->status === 'failed'
                    && MetaImport::whereKey($import->id)->where('status', 'failed')->update(['status' => 'pending', 'error' => null]) === 1;
                if ($import->organization_id === $page->organization_id && ($import->wasRecentlyCreated || $retry)) {
                    ProcessMetaLead::dispatch($import->id)->onConnection('redis')->afterCommit();
                }
            }
        }

        return response('OK', 200)->header('Content-Type', 'text/plain');
    }
}
