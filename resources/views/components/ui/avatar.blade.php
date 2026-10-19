@props(['name' => null, 'user' => null, 'src' => null, 'dark' => false, 'size' => null])
@php
    $displayName = $user?->name ?? $name ?? 'FlowTrack';
    $imagePath = $user?->profile_image_path;

    // Serve stored avatars through an application route instead of relying on
    // public/storage. This works consistently in local/dev and production even
    // when a filesystem symlink is missing, while the random stored filename
    // gives us a stable cache-busting URL whenever the photo is replaced.
    $imageUrl = $src;

    if (! $imageUrl && $user?->id) {
        $imageUrl = $user->profileImageUrl();
    }

    $initials = collect(preg_split('/\s+/', trim($displayName)))
        ->filter()
        ->map(fn ($part) => mb_substr($part, 0, 1))
        ->take(2)
        ->implode('');
    $fontSizeRem = $size ? max(9, round($size / 3.4)) / 16 : null;
    $style = $size ? "width:{$size}px;height:{$size}px;font-size:{$fontSizeRem}rem" : null;
@endphp
<span {{ $attributes->class(['avatar', 'dark' => $dark])->merge(['style' => $style]) }}>
    @if($imageUrl)
        <img
            src="{{ $imageUrl }}"
            alt=""
            aria-hidden="true"
            loading="lazy"
            decoding="async"
            data-ft-image-fallback="sibling"
        >
        <span class="avatar-initials" hidden aria-hidden="true">{{ $initials ?: 'FT' }}</span>
    @else
        <span class="avatar-initials" aria-hidden="true">{{ $initials ?: 'FT' }}</span>
    @endif
</span>
