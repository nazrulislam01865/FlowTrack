@props(['label','value','subline'=>null,'tone'=>'blue','icon'=>'document','danger'=>false])
<article class="ft-finance-metric {{ $danger ? 'is-danger' : '' }}">
    <div>
        <span>{{ $label }}</span>
        <strong>{{ $value }}</strong>
        @if($subline)<small>{{ $subline }}</small>@endif
    </div>
    <span class="ft-finance-metric-icon {{ $tone }}" aria-hidden="true">
        @if($icon === 'money')
            <svg viewBox="0 0 24 24"><path d="M7 3.5h8l4 4v13H7z"></path><path d="M15 3.5v4h4"></path><path d="M12.2 9v7M14.8 10.4c-.5-.8-1.4-1.2-2.6-1.2-1.5 0-2.5.7-2.5 1.8 0 2.9 5.2 1.2 5.2 3.9 0 1.2-1 2-2.7 2-1.3 0-2.4-.5-3-1.4"></path></svg>
        @elseif($icon === 'collect')
            <svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="8"></circle><path d="M12 7v10M8.5 13.5 12 17l3.5-3.5"></path></svg>
        @elseif($icon === 'outstanding')
            <svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="8"></circle><path d="M12 7v10M14.6 9.1c-.6-.7-1.4-1.1-2.5-1.1-1.4 0-2.4.7-2.4 1.7 0 2.7 5 1.2 5 3.8 0 1.2-1 2-2.7 2-1.2 0-2.3-.5-2.9-1.4"></path></svg>
        @elseif($icon === 'warning')
            <svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="8"></circle><path d="M12 7.5v5.7M12 16.3h.01"></path></svg>
        @else
            <svg viewBox="0 0 24 24"><path d="M7 3.5h8l4 4v13H7z"></path><path d="M15 3.5v4h4M10 11h6M10 14h6M10 17h4"></path></svg>
        @endif
    </span>
</article>
