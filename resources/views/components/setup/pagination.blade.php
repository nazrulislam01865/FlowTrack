@props(['paginator', 'pageName' => 'page', 'label' => null])
@if($paginator && method_exists($paginator, 'hasPages') && $paginator->hasPages())
    <x-ui.pagination
        :page="$paginator->currentPage()"
        :last-page="$paginator->lastPage()"
        :previous-action="\"previousPage('{$pageName}')\""
        :next-action="\"nextPage('{$pageName}')\""
        :label="$label"
    />
@endif
