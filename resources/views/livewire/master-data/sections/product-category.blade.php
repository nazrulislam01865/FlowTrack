        @if(!$recordsReady)
            @include('livewire.shared.table-rows-placeholder', ['columns' => 9, 'rows' => 8])
        @else
            <x-catalog.category-list
                :main-page="$categoryMainPage"
                :product-children="$categoryProductChildren"
                :subcategory-children="$categorySubcategoryChildren"
                :main-categories="$categoryMainCategories"
                :product-categories="$categoryProductCategories"
                :parent-options="$categoryParentOptions"
                :counts="$categoryCounts"
                :product-counts="$categoryProductCounts"
                :main-product-counts="$categoryMainProductCounts"
                :subcategory-product-counts="$categorySubcategoryProductCounts"
                :product-child-totals="$categoryProductChildTotals"
                :subcategory-child-totals="$categorySubcategoryChildTotals"
                :expanded-main-ids="$expandedMainCategoryIds"
                :expanded-product-ids="$expandedProductCategoryIds"
                :can-create="$canCreateMaster"
                :can-edit="$canEditMaster"
                :can-delete="$canDeleteMaster"
                :display-timezone="$displayTimezone"
                :search="$search"
                :level-filter="$categoryLevelFilter"
                :parent-filter="$categoryParentFilter"
                :status-filter="$categoryStatusFilter"
                :per-page="$categoryPerPage"
                :selected-category-keys="$selectedCategoryKeys"
                :selection-count="$categorySelectionCount"
            />

            @if($showCategoryDeleteConfirm)
                <x-catalog.category-delete-modal :preview="$categoryDeletePreview" />
            @endif
        @endif
