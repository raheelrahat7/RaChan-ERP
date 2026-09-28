<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class SetLocale
{
    public function handle(Request $request, Closure $next): Response
    {
        $actor = $request->user();
        $locale = $actor === null ? $request->session()->get('locale', 'en') : $actor->locale;
        app()->setLocale(in_array($locale, ['en', 'ar'], true) ? $locale : 'en');

        return $next($request);
    }
}
