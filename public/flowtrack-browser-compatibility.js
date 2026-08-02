/* FlowTrack browser compatibility layer.
 * Keep this file ES5-compatible because it runs before the application assets.
 * The main generated assets are also syntax-transpiled during npm run build.
 */
(function () {
    'use strict';

    if (!String.prototype.replaceAll) {
        Object.defineProperty(String.prototype, 'replaceAll', {
            configurable: true,
            writable: true,
            value: function (search, replacement) {
                var source = String(this);
                if (search instanceof RegExp) {
                    if (!search.global) throw new TypeError('replaceAll requires a global RegExp');
                    return source.replace(search, replacement);
                }
                var escaped = String(search).replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
                return source.replace(new RegExp(escaped, 'g'), replacement);
            }
        });
    }

    if (!window.queueMicrotask) {
        window.queueMicrotask = function (callback) {
            Promise.resolve()
                .then(callback)
                .catch(function (error) { setTimeout(function () { throw error; }, 0); });
        };
    }

    if (!Object.fromEntries) {
        Object.fromEntries = function (entries) {
            var result = {};
            Array.from(entries).forEach(function (entry) {
                result[entry[0]] = entry[1];
            });
            return result;
        };
    }

    if (window.NodeList && !NodeList.prototype.forEach) {
        NodeList.prototype.forEach = Array.prototype.forEach;
    }

    if (window.Element && !Element.prototype.matches) {
        Element.prototype.matches = Element.prototype.msMatchesSelector || Element.prototype.webkitMatchesSelector;
    }

    if (window.Element && !Element.prototype.closest) {
        Element.prototype.closest = function (selector) {
            var element = this;
            while (element && element.nodeType === 1) {
                if (element.matches(selector)) return element;
                element = element.parentElement || element.parentNode;
            }
            return null;
        };
    }

    if (typeof window.CustomEvent !== 'function') {
        var CustomEventPolyfill = function (eventName, params) {
            var options = params || { bubbles: false, cancelable: false, detail: null };
            var event = document.createEvent('CustomEvent');
            event.initCustomEvent(eventName, Boolean(options.bubbles), Boolean(options.cancelable), options.detail);
            return event;
        };
        CustomEventPolyfill.prototype = window.Event.prototype;
        window.CustomEvent = CustomEventPolyfill;
    }
}());
