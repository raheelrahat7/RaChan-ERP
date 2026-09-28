<?php

namespace App\Http\Controllers;

use Inertia\Inertia;
use Inertia\Response;

class StyleguideController extends Controller
{
    public function __invoke(): Response
    {
        abort_unless(app()->environment(['local', 'testing']), 404);

        return Inertia::render('Styleguide');
    }
}
