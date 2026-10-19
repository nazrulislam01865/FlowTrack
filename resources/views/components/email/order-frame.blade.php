@props(['brand' => [], 'label' => 'Order workflow', 'footerNote' => null])
@php
    $brandName = trim((string) ($brand['name'] ?? 'Company')) ?: 'Company';
    $legalName = trim((string) ($brand['legal_name'] ?? ''));
    $addressLines = collect($brand['address_lines'] ?? [])->filter(fn ($value) => filled($value))->values();
    $contactParts = collect([
        $brand['billing_email'] ?? null,
        $brand['phone'] ?? null,
        $brand['website'] ?? null,
    ])->filter(fn ($value) => filled($value))->map(fn ($value) => trim((string) $value))->values();
@endphp
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <meta name="color-scheme" content="light only">
</head>
<body style="margin:0;padding:0;background:#f4f7fa;color:#152238;font-family:Arial,Helvetica,sans-serif;line-height:1.5;-webkit-text-size-adjust:100%">
<table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="width:100%;background:#f4f7fa">
<tr><td align="center" style="padding:32px 16px">
    <table role="presentation" width="640" cellspacing="0" cellpadding="0" border="0" style="width:100%;max-width:640px;border-collapse:separate;background:#ffffff;border:1px solid #d9e2ec;border-radius:14px;overflow:hidden;box-shadow:0 8px 28px rgba(15,35,65,.07)">
        <tr>
            <td style="padding:20px 24px;background:#13263d;color:#ffffff">
                <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0"><tr>
                    <td style="vertical-align:middle">
                        <div style="font-size:20px;font-weight:800;letter-spacing:-.35px;color:#35c0b1">{{ $brandName }}</div>
                        @if($legalName !== '' && strcasecmp($legalName, $brandName) !== 0)
                            <div style="margin-top:3px;font-size:10px;color:#c5d3df">{{ $legalName }}</div>
                        @endif
                    </td>
                    <td align="right" style="vertical-align:middle;font-size:11px;font-weight:700;letter-spacing:.08em;text-transform:uppercase;color:#b9c8d8">{{ $label }}</td>
                </tr></table>
            </td>
        </tr>
        <tr><td style="padding:30px 28px">{{ $slot }}</td></tr>
        <tr>
            <td style="padding:16px 28px;background:#f8fafc;border-top:1px solid #e7edf2;color:#6b7e96;font-size:11px;line-height:1.5">
                <div style="color:#4f6279;font-weight:700">{{ $legalName !== '' ? $legalName : $brandName }}</div>
                @if($addressLines->isNotEmpty())<div style="margin-top:2px">{{ $addressLines->implode(' · ') }}</div>@endif
                @if($contactParts->isNotEmpty())<div style="margin-top:2px">{{ $contactParts->implode(' · ') }}</div>@endif
                <div style="margin-top:7px;color:#8a98aa">{{ filled($footerNote) ? $footerNote : 'Internal order workflow notification from '.$brandName.'.' }}</div>
            </td>
        </tr>
    </table>
</td></tr>
</table>
</body>
</html>
