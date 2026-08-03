<?php

namespace App\Http\Controllers\FlowTrack;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class CsrfTokenController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        return response()
            ->json([
                'token' => $request->session()->token(),
            ])
            ->header(
                'Cache-Control',
                'private, no-store, no-cache, must-revalidate'
            );
    }
}
