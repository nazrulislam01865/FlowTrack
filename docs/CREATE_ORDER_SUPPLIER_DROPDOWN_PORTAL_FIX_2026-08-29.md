# Create Order supplier dropdown portal fix - 2026-08-29

## Problem

The Default supplier dropdown in the Create Product modal opened, but the menu was clipped by the modal's rounded `overflow: hidden` container and the scrollable `overflow-y: auto` body.

Using `position: fixed` alone does not escape overflow clipping when the dropdown remains a descendant of those containers.

## Fix

The shared `x-ui.search-select` component now teleports menus configured with `fixed-menu` to `document.body` using Alpine `x-teleport`.

The existing positioning code continues to anchor the menu to the trigger and constrain it to the viewport. Because the menu is rendered under `body`, it is no longer clipped by modal/card overflow boundaries.

Non-fixed search-select menus keep their existing DOM structure and behavior.

## Create Product result

The Create Order Default supplier picker already uses `:fixed-menu="true"`, so its supplier list can now extend outside the Create Product modal while remaining positioned beside the field.
