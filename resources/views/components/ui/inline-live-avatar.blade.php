@props(['size' => 24])
@php
    $size = max(18, (int) $size);
    $fontSizeRem = max(9, round($size / 3.4)) / 16;
    $style = "width:{$size}px;height:{$size}px;font-size:{$fontSizeRem}rem";
@endphp
<span {{ $attributes->class(['avatar', 'ft-inline-live-avatar'])->merge(['style' => $style]) }}>
    <template x-if="avatarUrl">
        <img
            :src="avatarUrl"
            alt=""
            aria-hidden="true"
            decoding="async"
            data-ft-image-fallback="managed"
            x-on:error="avatarUrl = ''; savedAvatarUrl = ''"
        >
    </template>
    <span
        class="avatar-initials"
        x-show="!avatarUrl"
        x-text="initials(display)"
        aria-hidden="true"
    ></span>
</span>
