<?php

namespace App\Http\Controllers;

use App\Models\Task;
use App\Services\ArtworkUploadStagingService;
use App\Support\AttachmentUpload;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Small-request transport for 400MB Order Artwork files.
 *
 * The final file is still persisted only by DocumentService; these endpoints
 * merely stage authenticated chunks so a slow browser upload never depends on
 * one long Livewire/PHP request.
 */
class ArtworkChunkUploadController extends Controller
{
    public function start(Request $request, ArtworkUploadStagingService $staging): JsonResponse
    {
        $data = $request->validate([
            'task_id' => ['required', 'integer', 'min:1'],
            'name' => ['required', 'string', 'max:255'],
            'size' => ['required', 'integer', 'min:1', 'max:'.AttachmentUpload::ARTWORK_MAX_BYTES],
            'revision_document_id' => ['nullable', 'integer', 'min:1'],
        ]);

        $task = Task::query()->findOrFail((int) $data['task_id']);
        $descriptor = $staging->start(
            $task,
            $request->user(),
            (string) $data['name'],
            (int) $data['size'],
            isset($data['revision_document_id']) ? (int) $data['revision_document_id'] : null,
        );

        $token = $descriptor['token'];
        return response()->json($descriptor + [
            'chunk_bytes' => $staging->chunkBytes(),
            'chunk_concurrency' => $staging->chunkConcurrency(),
            'chunk_url' => route('orders.artwork-uploads.chunk', ['token' => $token], false),
            'complete_url' => route('orders.artwork-uploads.complete', ['token' => $token], false),
            'cancel_url' => route('orders.artwork-uploads.destroy', ['token' => $token], false),
        ]);
    }

    public function chunk(Request $request, string $token, ArtworkUploadStagingService $staging): JsonResponse
    {
        $maxChunkKilobytes = (int) ceil($staging->chunkBytes() / 1024);
        $data = $request->validate([
            'index' => ['required', 'integer', 'min:0'],
            'chunk' => ['required', 'file', 'max:'.$maxChunkKilobytes],
        ]);

        return response()->json($staging->appendChunk(
            $token,
            $request->user(),
            $data['chunk'],
            (int) $data['index'],
        ));
    }

    public function complete(Request $request, string $token, ArtworkUploadStagingService $staging): JsonResponse
    {
        return response()->json($staging->complete($token, $request->user()));
    }

    public function destroy(Request $request, string $token, ArtworkUploadStagingService $staging): JsonResponse
    {
        $staging->discard($token, $request->user());
        return response()->json(['ok' => true]);
    }
}
