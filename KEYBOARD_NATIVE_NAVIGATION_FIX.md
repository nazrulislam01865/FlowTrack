# Keyboard and native browser navigation update

This update makes FlowTrack's workspace navigation use real browser links while preserving the existing in-place JavaScript navigation for a normal left click.

## Sidebar and mobile navigation

- Sidebar page items are now `<a href>` elements instead of button-only navigation controls.
- `Tab` moves through each available navigation item in normal document order.
- `Enter` on a focused navigation item activates it.
- Normal left click/Enter keeps FlowTrack's existing in-place navigation and loader behavior.
- Right click exposes the browser's normal link context menu, including Open link in new tab/window.
- Cmd/Ctrl-click, Shift-click and middle-click are not intercepted and use native browser behavior.
- Hidden/unauthorized navigation links are removed from keyboard tab order.
- The active page receives `aria-current="page"`.
- A visible focus ring is shown for keyboard users.

## Form keyboard behavior

No global Tab interception is installed. Native inputs, selects, textareas, links and buttons keep normal browser tab order. Native buttons/forms therefore retain Enter activation/submission behavior.

## Screenshot keyboard shortcuts

FlowTrack does not intercept `Print Screen`, Windows screenshot shortcuts, or macOS screenshot combinations (`Cmd+Shift+3/4/5`). No `contextmenu` suppression is installed, so operating-system/browser screenshot behavior remains available.
