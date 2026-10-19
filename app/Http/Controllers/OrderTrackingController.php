<?php

namespace App\Http\Controllers;

use App\Queries\Orders\PublicOrderTrackingQuery;
use App\Queries\Orders\PublicOrderTrackingTokenQuery;
use App\Support\PublicOrderTrackingPresenter;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;

final class OrderTrackingController extends Controller
{
    public function index(Request $request): Response
    {
        return $this->page('tracking.index', [
            'lookupType' => (string) $request->query('type', 'order'),
            'identifier' => (string) $request->query('number', ''),
            'email' => (string) $request->query('email', ''),
            'lookupError' => session('tracking_link_error'),
        ]);
    }

    public function lookup(
        Request $request,
        PublicOrderTrackingQuery $orders,
        PublicOrderTrackingPresenter $presenter,
    ): Response {
        $data = $request->validate([
            'lookup_type' => ['required', Rule::in(['order', 'reference'])],
            'identifier' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email:rfc', 'max:255'],
        ]);

        $order = $orders->find($data['lookup_type'], $data['identifier'], $data['email']);

        if (! $order) {
            return $this->page('tracking.index', [
                'lookupType' => $data['lookup_type'],
                'identifier' => $data['identifier'],
                'email' => '',
                'lookupError' => 'We could not find a matching order. Check your number and email address.',
            ]);
        }

        return $this->page('tracking.index', [
            'tracking' => $presenter->present($order),
            'lookupType' => $data['lookup_type'],
            'identifier' => $data['identifier'],
            'email' => '',
            'lookupError' => null,
        ]);
    }

    public function show(
        string $token,
        PublicOrderTrackingTokenQuery $orders,
        PublicOrderTrackingPresenter $presenter,
    ): Response|RedirectResponse {
        $order = $orders->find($token);

        if (! $order) {
            return redirect()
                ->route('order.track')
                ->with('tracking_link_error', 'This tracking link is unavailable. Use your order or reference number and email address.');
        }

        $tracking = $presenter->present($order);

        return $this->page('tracking.index', [
            'tracking' => $tracking,
            'lookupType' => 'order',
            'identifier' => (string) ($tracking['order_number'] ?? ''),
            'email' => '',
            'lookupError' => null,
        ]);
    }

    /** @param array<string,mixed> $data */
    private function page(string $view, array $data = []): Response
    {
        return response()->view($view, $data)->withHeaders([
            'Cache-Control' => 'private, no-store, no-cache, must-revalidate, max-age=0',
            'Pragma' => 'no-cache',
            'Expires' => '0',
            'X-Robots-Tag' => 'noindex, nofollow',
            'X-Content-Type-Options' => 'nosniff',
            'X-Frame-Options' => 'DENY',
            'Referrer-Policy' => 'no-referrer',
        ]);
    }
}
