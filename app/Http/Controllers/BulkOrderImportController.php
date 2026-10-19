<?php

namespace App\Http\Controllers;

use App\Services\BulkOrderImportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use RuntimeException;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Throwable;

class BulkOrderImportController extends Controller
{
    public function index(BulkOrderImportService $service): View
    {
        abort_unless(auth()->user()->canAccess('jobs.create'), 403);

        return view('pages.bulk-order-import', [
            'title' => 'Import Orders',
            ...$service->uploadOptions(auth()->user()),
        ]);
    }

    public function validateUpload(Request $request, BulkOrderImportService $service): JsonResponse
    {
        abort_unless($request->user()->canAccess('jobs.create'), 403);
        $request->validate([
            'file' => ['required', 'file', 'max:20480', 'mimes:xlsx,xls,csv'],
            'duplicate_policy' => ['nullable', 'in:skip,update,separate'],
            'display_filename' => ['nullable', 'string', 'max:255'],
            'source_fingerprint' => ['nullable', 'regex:/^[a-f0-9]{64}$/i'],
            'manual_workflows' => ['nullable', 'array'],
            'manual_workflows.*' => ['nullable', 'integer'],
        ]);

        try {
            $prepared = $service->prepareUpload(
                $request->file('file'),
                $request->user(),
                trim((string) $request->input('display_filename', '')) ?: null,
                trim((string) $request->input('source_fingerprint', '')) ?: null,
            );
            $review = $service->validateToken($prepared['token'], $request->only([
                'duplicate_policy', 'manual_workflows',
            ]), $request->user());

            return response()->json($review);
        } catch (RuntimeException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }
    }

    public function revalidate(Request $request, BulkOrderImportService $service): JsonResponse
    {
        abort_unless($request->user()->canAccess('jobs.create'), 403);
        $data = $request->validate([
            'token' => ['required', 'uuid'],
            'duplicate_policy' => ['required', 'in:skip,update,separate'],
            'manual_workflows' => ['nullable', 'array'],
            'manual_workflows.*' => ['nullable', 'integer'],
        ]);

        try {
            return response()->json($service->validateToken($data['token'], $data, $request->user()));
        } catch (RuntimeException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }
    }

    public function import(Request $request, BulkOrderImportService $service): JsonResponse
    {
        abort_unless($request->user()->canAccess('jobs.create'), 403);
        $data = $request->validate([
            'token' => ['required', 'uuid'],
            'duplicate_policy' => ['required', 'in:skip,update,separate'],
            'manual_workflows' => ['nullable', 'array'],
            'manual_workflows.*' => ['nullable', 'integer'],
        ]);

        try {
            $result = $service->import($data['token'], $data, $request->user());
            $result['view_orders_url'] = route('jobs.index', [
                'import' => $result['import_id'],
            ]);

            return response()->json($result);
        } catch (RuntimeException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        } catch (Throwable $exception) {
            report($exception);
            return response()->json(['message' => 'The import could not be completed. No failed row was silently created.'], 500);
        }
    }

    public function template(): BinaryFileResponse
    {
        abort_unless(auth()->user()->canAccess('jobs.create'), 403);
        $trackedTemplate = resource_path('templates/FlowTrack_Bulk_Order_Import_Template_v4.xlsx');
        $storageTemplate = storage_path('app/templates/FlowTrack_Bulk_Order_Import_Template_v4.xlsx');
        $path = is_file($trackedTemplate) ? $trackedTemplate : $storageTemplate;

        abort_unless(is_file($path), 404);

        return response()->download($path, 'FlowTrack_Bulk_Order_Import_Template_v4.xlsx', [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ]);
    }
}
