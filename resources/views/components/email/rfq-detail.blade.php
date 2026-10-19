@props(['title', 'meta' => null])
<table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="width:100%;margin:8px 0;border:1px solid #d9e2ec;border-radius:9px;background:#ffffff">
<tr><td style="padding:13px 14px">
    <div style="color:#152238;font-size:13px;font-weight:700;line-height:1.35">{{ $title }}</div>
    @if($meta)<div style="margin-top:4px;color:#6b7e96;font-size:11px;line-height:1.45">{{ $meta }}</div>@endif
</td></tr>
</table>
