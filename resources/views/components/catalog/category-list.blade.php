@props([
    'mainPage',
    'productChildren' => collect(),
    'subcategoryChildren' => collect(),
    'mainCategories' => collect(),
    'productCategories' => collect(),
    'parentOptions' => collect(),
    'counts' => ['main'=>0,'product'=>0,'sub'=>0],
    'productCounts' => collect(),
    'mainProductCounts' => collect(),
    'subcategoryProductCounts' => collect(),
    'productChildTotals' => collect(),
    'subcategoryChildTotals' => collect(),
    'expandedMainIds' => [],
    'expandedProductIds' => [],
    'canCreate' => false,
    'canEdit' => false,
    'canDelete' => false,
    'displayTimezone' => 'UTC',
    'search' => '',
    'levelFilter' => '',
    'parentFilter' => '',
    'statusFilter' => '',
    'perPage' => 6,
    'selectedCategoryKeys' => [],
    'selectionCount' => 0,
])
@php
    $expandedMain = collect($expandedMainIds)->map(fn($id)=>(int)$id);
    $expandedProduct = collect($expandedProductIds)->map(fn($id)=>(int)$id);
    $searchNeedle = mb_strtolower(trim((string)$search));
    [$parentType,$parentId] = str_contains((string)$parentFilter, ':') ? array_pad(explode(':',(string)$parentFilter,2),2,'') : ['',''];
    $parentId = (int)$parentId;
    $levelOptions = collect([
        ['id'=>'main','label'=>'Main category'],
        ['id'=>'product','label'=>'Product category'],
        ['id'=>'sub','label'=>'Subcategory'],
    ]);
    $statusOptions = collect([
        ['id'=>'active','label'=>'Active'],
        ['id'=>'inactive','label'=>'Inactive'],
    ]);
    $parentSelectOptions = collect($parentOptions)->map(fn($item)=>['id'=>$item['value'],'label'=>$item['label'],'meta'=>$item['meta']]);
    $selectedCategoryKeySet = collect($selectedCategoryKeys)->map(fn($key)=>(string)$key);
    $visibleCategoryKeys = collect($mainPage?->items() ?? [])->map(fn($main)=>'main:'.$main->id);
    foreach(collect($productChildren) as $children){
        foreach(collect($children) as $category){
            $visibleCategoryKeys->push('product:'.$category->id);
            foreach(collect($subcategoryChildren)->get((int)$category->id, collect()) as $sub){
                $visibleCategoryKeys->push('sub:'.$sub->id);
            }
        }
    }
    $visibleCategoryKeys = $visibleCategoryKeys->unique()->values();
    $allVisibleCategoriesSelected = $visibleCategoryKeys->isNotEmpty() && $visibleCategoryKeys->every(fn($key)=>$selectedCategoryKeySet->contains($key));
    $matches = function($record,string $level,?int $mainId=null,?int $productId=null) use($searchNeedle,$levelFilter,$parentType,$parentId,$statusFilter){
        if($levelFilter!=='' && $levelFilter!==$level) return false;
        if($statusFilter!=='' && $record->status!==$statusFilter) return false;
        if($searchNeedle!=='' && !str_contains(mb_strtolower($record->name.' '.$record->code),$searchNeedle)) return false;
        if($parentType==='main' && $parentId>0){
            if($level==='main') return (int)$record->id===$parentId;
            if((int)$mainId!==$parentId) return false;
        }
        if($parentType==='product' && $parentId>0){
            if($level==='main') return false;
            if($level==='product') return (int)$record->id===$parentId;
            if((int)$productId!==$parentId) return false;
        }
        return true;
    };
@endphp
<div class="ft-category-page">
    <header class="ft-category-page-head">
        <div>
            <h1>Product Categories</h1>
            <p>Manage the hierarchy used to organize and find products.</p>
        </div>
        <div class="ft-category-head-right">
            @if($canCreate)
                <button type="button" class="ft-category-add" wire:click="openCategoryEditor('main')">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 5v14M5 12h14"/></svg>
                    <span>Add category</span>
                </button>
            @endif
        </div>
    </header>

    @if(session('success'))<div class="flash success ft-master-flash">{{ session('success') }}</div>@endif
    @error('record')<div class="flash error ft-master-flash">{{ $message }}</div>@enderror

    <section class="ft-category-toolbar">
        <label class="ft-category-search">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><circle cx="11" cy="11" r="7"/><path d="m20 20-3.5-3.5"/></svg>
            <input type="search" wire:model.live.debounce.300ms="search" placeholder="Search category name or code" aria-label="Search category name or code">
        </label>
        <x-ui.search-select
            class="ft-category-filter-select"
            label="Category level"
            property="categoryLevelFilter"
            :value="$levelFilter"
            placeholder="Category level"
            :options="$levelOptions"
            :fixed-menu="true"
            :menu-width="250"
            search-placeholder="Search level…"
        />
        <x-ui.search-select
            class="ft-category-filter-select"
            label="Parent category"
            property="categoryParentFilter"
            :value="$parentFilter"
            placeholder="Parent category"
            :options="$parentSelectOptions"
            :fixed-menu="true"
            :menu-width="320"
            search-placeholder="Search parent category…"
        />
        <x-ui.search-select
            class="ft-category-filter-select"
            label="Status"
            property="categoryStatusFilter"
            :value="$statusFilter"
            placeholder="Status"
            :options="$statusOptions"
            :fixed-menu="true"
            :menu-width="220"
            search-placeholder="Search status…"
        />
        <button type="button" class="ft-category-clear" wire:click="clearCategoryFilters">Clear</button>
    </section>

    @if($selectionCount > 0)
        <x-catalog.category-bulk-actions
            :count="$selectionCount"
            :can-edit="$canEdit"
            :can-delete="$canDelete"
        />
    @endif

    <div class="ft-category-subbar ft-category-subbar-actions-only">
        <div class="ft-category-expand-controls">
            <button type="button" class="ft-category-expand-btn" wire:click="collapseAllCategories" title="Collapse all" aria-label="Collapse all categories">
                <svg viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                    <path d="m5.5 10.5 4.5-4 4.5 4"/>
                    <path d="m5.5 15 4.5-4 4.5 4"/>
                </svg>
            </button>
            <button type="button" class="ft-category-expand-btn is-teal" wire:click="expandAllCategories" title="Expand all" aria-label="Expand all categories">
                <svg viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                    <path d="m5.5 5 4.5 4 4.5-4"/>
                    <path d="m5.5 9.5 4.5 4 4.5-4"/>
                </svg>
            </button>
        </div>
    </div>

    <section class="ft-category-card">
        <div class="ft-category-table-scroll">
            <div class="ft-category-tree-grid ft-category-tree-grid-head">
                <span class="ft-category-check"><input type="checkbox" aria-label="Select all visible categories" @checked($allVisibleCategoriesSelected) x-on:change="$wire.toggleCategoryPageSelection(@js($visibleCategoryKeys->all()), $event.target.checked)"></span>
                <span>Category</span><span>Code</span><span>Level</span><span>Parent</span><span>Products</span><span>Status</span><span>Updated</span><span></span>
            </div>

            @forelse(($mainPage?->items() ?? []) as $main)
                @php
                    $mainProducts = collect($productChildren)->get((int)$main->id, collect());
                    $mainIsExpanded = $expandedMain->contains((int)$main->id);
                    $mainChildTotal = (int)(collect($productChildTotals)->get((int)$main->id, 0));
                    $mainCount = (int)($mainProductCounts[mb_strtolower($main->name)] ?? 0);
                    $mainUpdated = $main->updated_at?->copy()->timezone($displayTimezone);
                @endphp
                <div @class(['ft-category-tree-grid ft-category-row is-main', 'is-selected' => $selectedCategoryKeySet->contains('main:'.$main->id)]) wire:key="cat-main-{{ $main->id }}">
                    <span class="ft-category-check"><input class="ft-category-row-check" type="checkbox" aria-label="Select {{ $main->name }}" @checked($selectedCategoryKeySet->contains('main:'.$main->id)) wire:change="toggleCategorySelection('main', {{ $main->id }})"></span>
                    <div class="ft-category-name">
                        @if($mainChildTotal > 0)
                            <button type="button" class="ft-category-chevron" wire:click="toggleCategoryExpansion('main', {{ $main->id }})" aria-label="{{ $mainIsExpanded ? 'Collapse' : 'Expand' }} {{ $main->name }}">{{ $mainIsExpanded ? '⌄' : '›' }}</button>
                        @else<span class="ft-category-chevron is-placeholder"></span>@endif
                        <span class="ft-category-tag-icon">◇</span><strong>{{ $main->name }}</strong>
                    </div>
                    <span>{{ $main->code }}</span>
                    <span><b class="ft-category-level is-main">Main category</b></span>
                    <span class="ft-category-muted">—</span>
                    <strong>{{ number_format($mainCount) }}</strong>
                    <span><b class="ft-category-status {{ $main->status==='active'?'':'is-off' }}">{{ $main->status==='active'?'Active':'Inactive' }}</b></span>
                    <span class="ft-category-updated" title="{{ $mainUpdated?->format('M j, Y g:i A') }} {{ $displayTimezone }}">{{ $mainUpdated?->diffForHumans() ?? '—' }}</span>
                    <x-catalog.category-action-menu level="main" :record-id="$main->id" :is-active="$main->status==='active'" :can-edit="$canEdit" :can-delete="$canDelete" :can-create="$canCreate" />
                </div>

                @if($mainIsExpanded && $levelFilter !== 'main')
                    @foreach($mainProducts as $category)
                        @php
                            $subs = collect($subcategoryChildren)->get((int)$category->id, collect());
                            $subcategoryTotal = (int)(collect($subcategoryChildTotals)->get((int)$category->id, 0));
                            $categoryIsExpanded = $expandedProduct->contains((int)$category->id);
                            $categoryUpdated = $category->updated_at?->copy()->timezone($displayTimezone);
                        @endphp
                            <div @class(['ft-category-tree-grid ft-category-row', 'is-selected' => $selectedCategoryKeySet->contains('product:'.$category->id)]) wire:key="cat-product-{{ $category->id }}">
                                <span class="ft-category-check"><input class="ft-category-row-check" type="checkbox" aria-label="Select {{ $category->name }}" @checked($selectedCategoryKeySet->contains('product:'.$category->id)) wire:change="toggleCategorySelection('product', {{ $category->id }})"></span>
                                <div class="ft-category-name is-indent-1">
                                    @if($subcategoryTotal > 0)
                                        <button type="button" class="ft-category-chevron" wire:click="toggleCategoryExpansion('product', {{ $category->id }})" aria-label="{{ $categoryIsExpanded ? 'Collapse' : 'Expand' }} {{ $category->name }}">{{ $categoryIsExpanded ? '⌄' : '›' }}</button>
                                    @else<span class="ft-category-chevron is-placeholder"></span>@endif
                                    <strong>{{ $category->name }}</strong>
                                </div>
                                <span>{{ $category->code }}</span>
                                <span><b class="ft-category-level">Product category</b></span>
                                <span class="ft-category-muted">{{ $main->name }}</span>
                                <strong>{{ number_format((int)($productCounts[$category->id] ?? 0)) }}</strong>
                                <span><b class="ft-category-status {{ $category->status==='active'?'':'is-off' }}">{{ $category->status==='active'?'Active':'Inactive' }}</b></span>
                                <span class="ft-category-updated" title="{{ $categoryUpdated?->format('M j, Y g:i A') }} {{ $displayTimezone }}">{{ $categoryUpdated?->diffForHumans() ?? '—' }}</span>
                                <x-catalog.category-action-menu level="product" :record-id="$category->id" :is-active="$category->status==='active'" :can-edit="$canEdit" :can-delete="$canDelete" :can-create="$canCreate" />
                            </div>

                            @if($categoryIsExpanded && in_array($levelFilter,['','sub'],true))
                                @foreach($subs as $sub)
                                    @php
                                        $subUpdated = $sub->updated_at?->copy()->timezone($displayTimezone);
                                        $subCountKey = $category->id.'|'.mb_strtolower($sub->name);
                                    @endphp
                                    <div @class(['ft-category-tree-grid ft-category-row', 'is-selected' => $selectedCategoryKeySet->contains('sub:'.$sub->id)]) wire:key="cat-sub-{{ $sub->id }}">
                                        <span class="ft-category-check"><input class="ft-category-row-check" type="checkbox" aria-label="Select {{ $sub->name }}" @checked($selectedCategoryKeySet->contains('sub:'.$sub->id)) wire:change="toggleCategorySelection('sub', {{ $sub->id }})"></span>
                                        <div class="ft-category-name is-indent-2"><span class="ft-category-chevron is-placeholder"></span><span>{{ $sub->name }}</span></div>
                                        <span>{{ $sub->code }}</span>
                                        <span><b class="ft-category-level">Subcategory</b></span>
                                        <span>{{ $category->name }}</span>
                                        <strong>{{ number_format((int)($subcategoryProductCounts[$subCountKey] ?? 0)) }}</strong>
                                        <span><b class="ft-category-status {{ $sub->status==='active'?'':'is-off' }}">{{ $sub->status==='active'?'Active':'Inactive' }}</b></span>
                                        <span class="ft-category-updated" title="{{ $subUpdated?->format('M j, Y g:i A') }} {{ $displayTimezone }}">{{ $subUpdated?->diffForHumans() ?? '—' }}</span>
                                        <x-catalog.category-action-menu level="sub" :record-id="$sub->id" :is-active="$sub->status==='active'" :can-edit="$canEdit" :can-delete="$canDelete" :can-create="$canCreate" />
                                    </div>
                                @endforeach
                                @php
                                    $loadedSubcategories = $subs->count();
                                    $subProgress = $subcategoryTotal > 0 ? min(100, (int) round(($loadedSubcategories / $subcategoryTotal) * 100)) : 100;
                                @endphp
                                @if($loadedSubcategories < $subcategoryTotal)
                                    <div class="ft-category-load-row is-sub" wire:key="cat-product-more-{{ $category->id }}">
                                        <span>Showing {{ number_format($loadedSubcategories) }} of {{ number_format($subcategoryTotal) }} subcategories</span>
                                        <span class="ft-category-load-progress" aria-hidden="true"><i style="width:{{ $subProgress }}%"></i></span>
                                        <button type="button" wire:click="loadMoreCategorySubcategories({{ $category->id }})" wire:loading.attr="disabled" wire:target="loadMoreCategorySubcategories({{ $category->id }})">Load 3 more</button>
                                    </div>
                                @endif
                            @endif
                    @endforeach

                    @php
                        $loadedMainChildren = $mainProducts->count();
                        $mainProgress = $mainChildTotal > 0 ? min(100, (int) round(($loadedMainChildren / $mainChildTotal) * 100)) : 100;
                    @endphp
                    @if($loadedMainChildren < $mainChildTotal)
                        <div class="ft-category-load-row" wire:key="cat-main-more-{{ $main->id }}">
                            <span>Showing {{ number_format($loadedMainChildren) }} of {{ number_format($mainChildTotal) }} product categories</span>
                            <span class="ft-category-load-progress" aria-hidden="true"><i style="width:{{ $mainProgress }}%"></i></span>
                            <button type="button" wire:click="loadMoreCategoryProducts({{ $main->id }})" wire:loading.attr="disabled" wire:target="loadMoreCategoryProducts({{ $main->id }})">Load 4 more</button>
                        </div>
                    @endif
                @endif
            @empty
                <div class="ft-category-empty">No categories found.</div>
            @endforelse
        </div>

        @if($mainPage)
            <footer class="ft-category-footer">
                <div class="ft-category-footer-left">
                    <span class="ft-category-footer-count">
                        @if($mainPage->total())
                            Showing <b>{{ $mainPage->firstItem() }}–{{ $mainPage->lastItem() }}</b> of <b>{{ number_format($mainPage->total()) }}</b> main categories
                        @else
                            Showing <b>0</b> main categories
                        @endif
                    </span>
                    <label>Rows per page
                        <select wire:model.live="categoryPerPage">
                            @foreach([6,10,20,50] as $size)<option value="{{ $size }}">{{ $size }}</option>@endforeach
                        </select>
                    </label>
                </div>
                <span>Page {{ $mainPage->currentPage() }} of {{ max(1,$mainPage->lastPage()) }}</span>
                <div class="ft-category-pages">
                    <button type="button" wire:click="setPage(1, 'masterPage')" @disabled($mainPage->onFirstPage()) aria-label="First page">|‹</button>
                    <button type="button" wire:click="previousPage('masterPage')" @disabled($mainPage->onFirstPage()) aria-label="Previous page">‹</button>
                    @php
                        $start=max(1,$mainPage->currentPage()-1); $end=min($mainPage->lastPage(),$start+3); $start=max(1,$end-3);
                    @endphp
                    @for($page=$start;$page<=$end;$page++)
                        <button type="button" wire:click="setPage({{ $page }}, 'masterPage')" @class(['is-active'=>$page===$mainPage->currentPage()])>{{ $page }}</button>
                    @endfor
                    <button type="button" wire:click="nextPage('masterPage')" @disabled(!$mainPage->hasMorePages()) aria-label="Next page">›</button>
                    <button type="button" wire:click="setPage({{ max(1,$mainPage->lastPage()) }}, 'masterPage')" @disabled(!$mainPage->hasMorePages()) aria-label="Last page">›|</button>
                </div>
            </footer>
        @endif
    </section>
</div>
