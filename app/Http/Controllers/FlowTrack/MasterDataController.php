<?php

namespace App\Http\Controllers\FlowTrack;

use App\Http\Controllers\Controller;
use App\Http\Requests\FlowTrack\StoreMasterRecordRequest;
use App\Modules\OrderFlow\Business\Enums\MasterDataType;
use App\Modules\OrderFlow\Business\Services\ConfigurationCodeGenerator;
use App\Modules\OrderFlow\Business\Services\MasterRecordDeletionService;
use App\Modules\OrderFlow\Data\Models\MasterRecord;
use App\Modules\OrderFlow\Data\Repositories\MasterDataRepository;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class MasterDataController extends Controller
{
    public function index(Request $request, MasterDataRepository $records, ConfigurationCodeGenerator $codes): Response
    {
        $type = (string) $request->query('type', MasterDataType::ProductCategory->value);
        abort_unless(in_array($type, array_map(fn (MasterDataType $case) => $case->value, MasterDataType::cases()), true), 404);
        $masterType = MasterDataType::from($type);

        return Inertia::render('flowtrack/MasterData/Index', [
            'records' => $records->byType($request->user()->currentWorkspaceId(), $type),
            'type' => $type,
            'types' => collect(MasterDataType::cases())->map(fn (MasterDataType $case) => $case->catalogEntry()),
            'parents' => $type === MasterDataType::Product->value ? $records->byType($request->user()->currentWorkspaceId(), MasterDataType::ProductCategory->value, true) : [],
            'nextCode' => $codes->master($masterType, $request->user()->currentWorkspaceId()),
        ]);
    }

    public function store(StoreMasterRecordRequest $request, ConfigurationCodeGenerator $codes): RedirectResponse
    {
        $workspaceId = $request->user()->currentWorkspaceId();
        $data = $request->validated();
        $type = MasterDataType::from($data['type']);

        DB::transaction(function () use ($workspaceId, $data, $type, $codes): void {
            MasterRecord::create([
                ...$data,
                'workspace_id' => $workspaceId,
                'type' => $type->value,
                'code' => $codes->master($type, $workspaceId),
                'parent_id' => $type === MasterDataType::Product ? ($data['parent_id'] ?? null) : null,
                'sort_order' => $data['sort_order'] ?? (((int) MasterRecord::query()
                    ->where('workspace_id', $workspaceId)
                    ->where('type', $type->value)
                    ->max('sort_order')) + 1),
            ]);
        });

        return back()->with('success', 'Master data record created with an automatically generated code.');
    }

    public function update(StoreMasterRecordRequest $request, MasterRecord $record): RedirectResponse
    {
        abort_unless($record->workspace_id === $request->user()->currentWorkspaceId(), 404);
        $data = $request->validated();

        if ($data['type'] !== $record->type) {
            throw ValidationException::withMessages(['type' => 'A master-data record cannot be moved to another section.']);
        }

        unset($data['type']);
        $data['parent_id'] = $record->type === MasterDataType::Product->value ? ($data['parent_id'] ?? null) : null;

        // The code is immutable and is never taken from the request.
        $record->update($data);

        return back()->with('success', 'Master data record updated. Its generated code was preserved.');
    }

    public function toggle(Request $request, MasterRecord $record): RedirectResponse
    {
        abort_unless($record->workspace_id === $request->user()->currentWorkspaceId(), 404);
        $record->update(['status' => $record->status === 'active' ? 'inactive' : 'active']);

        return back()->with('success', 'Master data status updated.');
    }

    public function destroy(Request $request, MasterRecord $record, MasterRecordDeletionService $deletion): RedirectResponse
    {
        abort_unless($request->user()?->hasFlowAction('masterdata', 'delete'), 403);
        abort_unless($record->workspace_id === $request->user()->currentWorkspaceId(), 404);

        $deletion->delete($record);

        return back()->with('success', 'Master data record deleted. Existing operational records were preserved.');
    }
}
