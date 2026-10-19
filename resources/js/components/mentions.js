const mentionState = { observer: null, livewireHookBound: false };

const parseMentionUsers = (input) => {
    try {
        const users = JSON.parse(input.dataset.mentionUsers || '[]');
        return Array.isArray(users) ? users : [];
    } catch (_) {
        return [];
    }
};

const mentionInputsWithin = (root) => {
    const inputs = [];
    if (root instanceof Element && root.matches('[data-mention-users]:not([data-rich-text])')) inputs.push(root);
    root.querySelectorAll?.('[data-mention-users]:not([data-rich-text])').forEach((input) => inputs.push(input));
    return inputs;
};

export const bootMentionInputs = (root = document) => {
    mentionInputsWithin(root).forEach((input) => {
        if (input.__flowtrackMentionBound) return;

        input.__flowtrackMentionBound = true;
        input.dataset.flowtrackMentionBound = '1';

        const host = input.closest('.ft-mention-host, .ft-inline-description-editor, .ft-editable-description, .ft-comment-composer') || input.parentElement;
        host?.classList.add('ft-mention-host');

        // Keep the popup outside Livewire-managed markup. Otherwise a morph can
        // remove the popup while leaving the input's event handlers attached.
        const menu = document.createElement('div');
        menu.className = 'ft-mention-menu';
        menu.hidden = true;
        menu.setAttribute('role', 'listbox');
        menu.style.position = 'fixed';
        // The attention dialog sits above the normal application shell. Keep
        // mention results above every modal even when an older stylesheet is
        // still cached by the browser.
        menu.style.zIndex = '2005';
        document.body.appendChild(menu);

        let matches = [];
        let selectedIndex = 0;
        let mentionStart = -1;
        let mentionEnd = -1;

        const close = () => {
            menu.hidden = true;
            menu.replaceChildren();
            matches = [];
            selectedIndex = 0;
            mentionStart = -1;
            mentionEnd = -1;
        };

        const positionMenu = () => {
            if (!input.isConnected || menu.hidden) return;

            const inputRect = input.getBoundingClientRect();
            const menuWidth = Math.min(Math.max(260, Math.min(inputRect.width, 380)), Math.max(260, window.innerWidth - 24));
            const left = Math.min(Math.max(12, inputRect.left), Math.max(12, window.innerWidth - menuWidth - 12));
            const estimatedHeight = Math.min(240, Math.max(48, matches.length * 38 + 10));
            const spaceBelow = window.innerHeight - inputRect.bottom;
            const top = spaceBelow >= estimatedHeight + 12
                ? inputRect.bottom + 5
                : Math.max(12, inputRect.top - estimatedHeight - 5);

            menu.style.left = `${left}px`;
            menu.style.top = `${top}px`;
            menu.style.width = `${menuWidth}px`;
        };

        const activeToken = () => {
            const caret = input.selectionStart ?? input.value.length;
            const before = input.value.slice(0, caret);
            const at = before.lastIndexOf('@');
            if (at < 0) return null;

            // Do not treat an @ inside an email/word as a mention. The user may
            // otherwise type @ at the start of a sentence or after whitespace/
            // punctuation, then continue searching with a multi-word name.
            const boundary = at > 0 ? before.charAt(at - 1) : '';
            if (boundary && /[\p{L}\p{N}._-]/u.test(boundary)) return null;

            const query = before.slice(at + 1);
            if (query.length > 90) return null;
            if (/[\r\n,;:()[\]{}]/u.test(query)) return null;
            if (!/^[\p{L}\p{N} ._'’\-]*$/u.test(query)) return null;

            return { query, start: at, end: caret };
        };

        const selectUser = (user) => {
            if (!user || mentionStart < 0) return;

            const before = input.value.slice(0, mentionStart);
            const after = input.value.slice(mentionEnd).replace(/^\s*/, '');
            const insertion = `@${user.handle} `;
            input.value = before + insertion + after;

            const caret = before.length + insertion.length;
            input.setSelectionRange(caret, caret);
            input.dispatchEvent(new Event('input', { bubbles: true }));
            close();
            input.focus();
        };

        const render = () => {
            menu.replaceChildren();

            matches.forEach((user, index) => {
                const button = document.createElement('button');
                button.type = 'button';
                button.className = `ft-mention-option${index === selectedIndex ? ' active' : ''}`;
                button.setAttribute('role', 'option');
                button.setAttribute('aria-selected', index === selectedIndex ? 'true' : 'false');

                const avatar = document.createElement('span');
                avatar.className = 'ft-mention-avatar';
                avatar.textContent = String(user.name || '?')
                    .split(/\s+/)
                    .filter(Boolean)
                    .slice(0, 2)
                    .map((part) => part[0])
                    .join('')
                    .toUpperCase();

                const copy = document.createElement('span');
                copy.className = 'ft-mention-copy';
                const name = document.createElement('strong');
                name.textContent = user.name || 'User';
                const handle = document.createElement('small');
                handle.textContent = `@${user.handle}`;
                copy.append(name, handle);
                button.append(avatar, copy);

                button.addEventListener('mousedown', (event) => {
                    event.preventDefault();
                    selectUser(user);
                });
                menu.appendChild(button);
            });

            menu.hidden = matches.length === 0;
            positionMenu();
        };

        const update = () => {
            const token = activeToken();
            if (!token) {
                close();
                return;
            }

            const users = parseMentionUsers(input);
            mentionStart = token.start;
            mentionEnd = token.end;
            const query = token.query.toLowerCase().replace(/[._-]+/g, ' ').replace(/\s+/g, ' ').trim();
            matches = users.filter((user) => {
                const haystack = `${user.name || ''} ${user.handle || ''}`.toLowerCase().replace(/[._-]+/g, ' ').replace(/\s+/g, ' ');
                return query === '' || haystack.includes(query);
            }).slice(0, 8);
            selectedIndex = Math.min(selectedIndex, Math.max(0, matches.length - 1));
            render();
        };

        const onKeydown = (event) => {
            if (menu.hidden) return;

            if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
                event.preventDefault();
                event.stopPropagation();
                event.stopImmediatePropagation();
                const direction = event.key === 'ArrowDown' ? 1 : -1;
                selectedIndex = (selectedIndex + direction + matches.length) % matches.length;
                render();
                return;
            }

            if ((event.key === 'Enter' || event.key === 'Tab') && matches[selectedIndex]) {
                event.preventDefault();
                event.stopPropagation();
                event.stopImmediatePropagation();
                selectUser(matches[selectedIndex]);
                return;
            }

            if (event.key === 'Escape') {
                event.preventDefault();
                event.stopPropagation();
                event.stopImmediatePropagation();
                close();
            }
        };

        const onInput = () => update();
        const onClick = () => update();
        const onFocus = () => update();
        const onKeyup = (event) => {
            if (!['ArrowUp', 'ArrowDown', 'Enter', 'Escape', 'Tab'].includes(event.key)) update();
        };
        const onBlur = () => window.setTimeout(close, 120);
        const onViewportChange = () => positionMenu();

        input.addEventListener('input', onInput);
        input.addEventListener('click', onClick);
        input.addEventListener('focus', onFocus);
        input.addEventListener('keyup', onKeyup);
        input.addEventListener('keydown', onKeydown, true);
        input.addEventListener('blur', onBlur);
        window.addEventListener('resize', onViewportChange);
        window.addEventListener('scroll', onViewportChange, true);

        input.__flowtrackMentionCleanup = () => {
            input.removeEventListener('input', onInput);
            input.removeEventListener('click', onClick);
            input.removeEventListener('focus', onFocus);
            input.removeEventListener('keyup', onKeyup);
            input.removeEventListener('keydown', onKeydown, true);
            input.removeEventListener('blur', onBlur);
            window.removeEventListener('resize', onViewportChange);
            window.removeEventListener('scroll', onViewportChange, true);
            menu.remove();
            delete input.__flowtrackMentionBound;
            delete input.__flowtrackMentionCleanup;
            delete input.dataset.flowtrackMentionBound;
        };
    });
};

const cleanupMentionInputs = (root) => {
    mentionInputsWithin(root).forEach((input) => input.__flowtrackMentionCleanup?.());
};

export const observeMentionInputs = () => {
    if (mentionState.observer || !document.body) return;

    mentionState.observer = new MutationObserver((mutations) => {
        mutations.forEach((mutation) => {
            mutation.removedNodes.forEach((node) => {
                if (node instanceof Element && !node.isConnected) cleanupMentionInputs(node);
            });
            mutation.addedNodes.forEach((node) => {
                if (node instanceof Element) bootMentionInputs(node);
            });
            if (mutation.type === 'attributes' && mutation.target instanceof Element) {
                bootMentionInputs(mutation.target);
            }
        });
    });

    mentionState.observer.observe(document.body, {
        childList: true,
        subtree: true,
        attributes: true,
        attributeFilter: ['data-mention-users'],
    });
};

export const bootLivewireMentionHooks = () => {
    if (mentionState.livewireHookBound || !window.Livewire?.hook) return;
    mentionState.livewireHookBound = true;

    try {
        window.Livewire.hook('morph.updated', (payload = {}) => bootMentionInputs(payload.el || document));
    } catch (_) {
        // MutationObserver remains the compatibility fallback.
    }
};
