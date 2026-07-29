<?php

namespace App\Http\Controllers\FlowTrack;

use App\Http\Controllers\Controller;
use App\Modules\OrderFlow\Business\Services\PusherChannelsClient;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use RuntimeException;

class PusherAuthController extends Controller
{
    public function __invoke(Request $request, PusherChannelsClient $pusher): JsonResponse
    {
        $data = $request->validate([
            'socket_id' => ['required', 'string', 'max:100'],
            'channel_name' => ['required', 'string', 'max:180'],
        ]);

        $expected = 'private-flowtrack.user.'.$request->user()->id;
        abort_unless(hash_equals($expected, $data['channel_name']), 403);

        try {
            return response()->json($pusher->authenticatePrivateChannel($data['socket_id'], $data['channel_name']));
        } catch (RuntimeException $exception) {
            throw ValidationException::withMessages(['pusher' => $exception->getMessage()]);
        }
    }
}
