<?php

namespace App\Http\Controllers;

use App\Services\MasterDataService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class MasterDataController extends Controller
{
    public function __invoke(Request $request): View|RedirectResponse
    {
        $financialPage = $request->routeIs('financial-master-data');
        if ($financialPage && !$request->has('group')) {
            return redirect()->route('financial-master-data', [
                'group' => MasterDataService::FINANCIAL_TYPES[0],
            ]);
        }

        $defaultGroup = $financialPage ? MasterDataService::FINANCIAL_TYPES[0] : 'product';
        $group = (string) $request->query('group', $defaultGroup);

        abort_unless(array_key_exists($group, MasterDataService::LABELS), 404);
        if ($financialPage) {
            abort_unless(in_array($group, MasterDataService::FINANCIAL_TYPES, true), 404);
        }

        $module = MasterDataService::permissionModuleForType($group);
        abort_unless($request->user()?->canModule($module, 'view'), 403);

        return view('pages.master-data');
    }
}
