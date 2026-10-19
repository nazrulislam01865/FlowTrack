(function () {
    'use strict';

    var script = document.currentScript;
    var fallbackSrc = script && script.dataset ? script.dataset.fallbackSrc : '';

    if (!fallbackSrc) {
        fallbackSrc = '/images/flowtrack-image-fallback.svg';
    }

    var fallbackUrl = new URL(fallbackSrc, document.baseURI).href;

    // Critical styles are installed before BODY parsing. This prevents a
    // component's display:flex/grid rule from overriding the hidden fallback
    // state and avoids a flash of the native browser broken-image glyph even
    // when an older pre-built Vite bundle is still present during deployment.
    var criticalStyle = document.createElement('style');
    criticalStyle.setAttribute('data-flowtrack-image-fallback-style', '');
    criticalStyle.textContent = [
        'img.ft-image-fallback{object-fit:contain!important;object-position:center!important}',
        'img[data-ft-image-fallback="sibling"][hidden]{display:none!important}',
        'img[data-ft-image-fallback="sibling"] + [hidden]{display:none!important}',
        '.ft-client-logo-fallback[hidden]{display:none!important}'
    ].join('');
    (document.head || document.documentElement).appendChild(criticalStyle);

    function imageUrl(image) {
        return image.currentSrc || image.src || '';
    }

    function showSiblingFallback(image) {
        image.hidden = true;
        image.setAttribute('aria-hidden', 'true');
        image.dataset.ftFallbackActive = 'sibling';

        var sibling = image.nextElementSibling;
        if (sibling) {
            sibling.hidden = false;
            sibling.removeAttribute('aria-hidden');
        }
    }

    function restoreSuccessfulImage(image) {
        if (!(image instanceof HTMLImageElement)) return;
        if (imageUrl(image) === fallbackUrl) return;

        image.hidden = false;
        image.classList.remove('ft-image-fallback');
        delete image.dataset.ftFallbackActive;
        delete image.dataset.ftOriginalSrc;
        image.removeAttribute('aria-hidden');

        if ((image.dataset.ftImageFallback || '') === 'sibling') {
            var sibling = image.nextElementSibling;
            if (sibling) sibling.hidden = true;
        }
    }

    function applyFallback(image) {
        if (!(image instanceof HTMLImageElement)) return;

        var mode = image.dataset.ftImageFallback || 'icon';

        // Components with a framework-owned error state (for example Alpine)
        // keep their own fallback behavior and are not intercepted here.
        if (mode === 'managed' || mode === 'none') return;

        // If the shared fallback asset itself cannot load, hide the element so
        // the browser's native broken-image glyph can never appear.
        if (imageUrl(image) === fallbackUrl) {
            image.hidden = true;
            image.dataset.ftFallbackActive = 'hidden';
            return;
        }

        image.classList.add('ft-image-fallback');

        if (mode === 'sibling') {
            showSiblingFallback(image);
            return;
        }

        if (mode === 'hide') {
            image.hidden = true;
            image.dataset.ftFallbackActive = 'hidden';
            return;
        }

        var originalSrc = imageUrl(image);
        if (originalSrc) image.dataset.ftOriginalSrc = originalSrc;

        image.dataset.ftFallbackActive = 'icon';
        image.removeAttribute('srcset');
        image.removeAttribute('sizes');
        image.src = fallbackUrl;
    }

    function repairAlreadyBrokenImages(root) {
        var scope = root && root.querySelectorAll ? root : document;
        scope.querySelectorAll('img').forEach(function (image) {
            if (!image.src) return;
            if (image.complete && image.naturalWidth === 0) applyFallback(image);
        });
    }

    // Capture failures before target-level handlers can leave a native broken
    // image glyph visible. This also covers Livewire/Alpine DOM added later.
    window.addEventListener('error', function (event) {
        if (event.target instanceof HTMLImageElement) {
            applyFallback(event.target);
        }
    }, true);

    // If a previously broken image gets a new valid URL (for example after a
    // Livewire upload/preview refresh), restore it without requiring a reload.
    window.addEventListener('load', function (event) {
        if (event.target instanceof HTMLImageElement) {
            restoreSuccessfulImage(event.target);
        }
    }, true);

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', function () {
            repairAlreadyBrokenImages(document);
        }, { once: true });
    } else {
        repairAlreadyBrokenImages(document);
    }

    window.FlowTrackImageFallback = Object.freeze({
        repair: repairAlreadyBrokenImages
    });
}());
