@props([
    'colspan' => 1,
    'name',
    'meta' => '',
    'openUrl',
    'downloadUrl',
])
@php
    $extension = strtoupper((string) pathinfo((string) $name, PATHINFO_EXTENSION));
    $extension = $extension !== '' ? $extension : 'FILE';
@endphp
<tr class="ft-invoice-document-row">
    <td colspan="{{ $colspan }}">
        <div class="ft-invoice-document">
            <span class="ft-invoice-document-type">{{ \Illuminate\Support\Str::limit($extension, 4, '') }}</span>
            <div class="ft-invoice-document-copy">
                <strong>{{ $name }}</strong>
                @if($meta !== '')
                    <small>{{ $meta }}</small>
                @endif
            </div>
            <div class="ft-invoice-document-actions">
                <a href="{{ $openUrl }}" target="_blank" rel="noopener">Open</a>
                <a href="{{ $downloadUrl }}">Download</a>
            </div>
        </div>
    </td>
</tr>
