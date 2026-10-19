# My Tasks assignee dropdown portal fix — 2026-08-29

## Problem

The inline assignee editor on **My Tasks** is rendered inside grouped Order rows. Those rows and their ancestors use table layout, containment/content-visibility, and overflow rules. A `position: fixed` dropdown that remains inside that DOM subtree can therefore be clipped or positioned relative to a containing block instead of the viewport.

The visible symptom was that the assignee control entered edit mode, but its searchable user dropdown did not appear in the expected position or could be hidden by the task table.

## Fix

The reusable `x-ui.inline-remote-user` component now follows the same fixed-menu contract already used by `x-ui.search-select`:

- when `fixedMenu` is enabled, the dropdown is teleported to `document.body` with Alpine `x-teleport`;
- its existing viewport-positioning logic still anchors the menu to the assignee control;
- the menu is explicitly kept hidden until a valid positioned style exists, preventing a one-frame flash at the bottom of the page;
- non-fixed inline user pickers keep their existing in-place behavior.

This removes dependence on My Tasks table overflow/containment rules and keeps the solution reusable for other inline assignee/owner editors.

## Scope

No task-assignment permissions, Livewire actions, user search API, task versions, or save behavior were changed.
