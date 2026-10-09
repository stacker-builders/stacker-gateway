<?php

namespace App\Http\Controllers;

use App\Services\DeliverableAccessLinkService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class DeliverableAccessController extends Controller
{
    public function show(Request $request, string $ref, DeliverableAccessLinkService $links): View
    {
        $resolved = $links->resolveRef($ref);
        if ($resolved === null) {
            abort(404);
        }

        return view('deliverable.redirecting', [
            'productName' => $resolved['product']->name,
            'continueUrl' => route('deliverable.access.go', ['ref' => strtolower($ref)]),
        ]);
    }

    public function go(Request $request, string $ref, DeliverableAccessLinkService $links): RedirectResponse
    {
        $resolved = $links->resolveRef($ref);
        if ($resolved === null) {
            abort(404);
        }

        try {
            $links->recordClick($resolved['user'], $resolved['product'], $request);
        } catch (\Throwable $e) {
            report($e);
        }

        return redirect()->away($resolved['destination']);
    }
}
