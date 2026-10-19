    @if($group === 'product_category' && $categoryEditorLevel)
        <x-catalog.category-editor
            :level="$categoryEditorLevel"
            :editing="(bool) $categoryEditorId"
            :read-only="$categoryEditorReadOnly"
            :main-categories="$categoryMainCategories"
            :product-categories="$categoryProductCategories"
            :selected-parent-id="$categoryEditorParentId"
            :name-value="$categoryEditorName"
            :description-value="$categoryEditorDescription"
        />
    @endif
