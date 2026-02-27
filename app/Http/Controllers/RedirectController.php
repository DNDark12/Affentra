<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Services\Tracking\ClickIngestService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Symfony\Component\HttpFoundation\Response;

class RedirectController extends Controller
{
    public function __construct(
        private readonly ClickIngestService $clickIngestService,
    ) {}

    /**
     * Handle short link redirect.
     * Rate limited at route level: config('integrations.rate_limits.redirect') per minute.
     */
    public function handle(Request $request, string $code): Response
    {
        $destination = $this->clickIngestService->ingest($code, $request);

        if ($destination === null) {
            abort(404);
        }

        return redirect()->away($destination, 302);
    }
}
