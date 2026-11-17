<div class="ft-supplier-product-settings">
    <div class="ft-supplier-product-settings-heading">
        <div><h1>Supplier Portal · Product module access</h1><p>Manage supplier permissions from FlowTracker Settings. Permissions are verified by FlowTracker on every request.</p></div>
    </div>
    @if(session('success'))<div class="flash success" role="status">{{ session('success') }}</div>@endif
    <section class="ft-supplier-product-settings-card">
        <div class="ft-supplier-product-settings-toggle">
            <div><strong>All suppliers</strong><p>This one permission matrix applies to every active supplier in the current workspace. Each supplier can still access only its own tagged products.</p></div>
        </div>
            <div class="ft-supplier-product-settings-toggle">
                <div><h2>Product module permissions</h2><p>Access remains disabled until the main switch is enabled and saved. Manage selects all supported actions in its row. Unchecking any action removes Manage, and write actions require View.</p></div>
                <label><input type="checkbox" wire:model="portalProductModuleEnabled"> Enable Product module</label>
            </div>
            <div class="ft-supplier-product-settings-table-wrap">
                <table class="ft-supplier-product-settings-table">
                    <thead><tr><th scope="col">Section</th>
                        @foreach(['view','create','edit','delete','manage'] as $action)<th scope="col">{{ ucfirst($action) }}</th>@endforeach
                    </tr></thead><tbody>
                        @foreach(\App\Services\SupplierBridge\SupplierProductModuleAccess::SECTIONS as $section => $actions)
                            <tr><th scope="row">{{ ucwords(str_replace('_',' ',$section)) }}</th>
                                @foreach(['view','create','edit','delete','manage'] as $action)
                                    <td>@if(in_array($action, $actions, true))
                                        <input type="checkbox" wire:model.live="portalProductSectionAccess.{{ $section }}.{{ $action }}"
                                            aria-label="{{ ucwords(str_replace('_',' ',$section)) }} {{ $action }}">
                                        @else<span aria-hidden="true">—</span>@endif</td>
                                @endforeach
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            <div class="ft-supplier-product-settings-footer">
                <p>Global permissions apply to all suppliers. Every product remains restricted to its tagged suppliers; ungranted permissions are denied.</p>
                <button type="button" class="ft-supplier-primary-button" wire:click="save" wire:loading.attr="disabled" wire:target="save">Save Product module permissions</button>
            </div>
    </section>
</div>
