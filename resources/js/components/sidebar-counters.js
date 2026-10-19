export const setNotificationUnreadCount = (count) => {
    const unread = Math.max(0, Number.parseInt(String(count ?? 0), 10) || 0);
    const bell = document.getElementById('flowtrackNotificationBell');
    const existingDot = document.getElementById('flowtrackNotificationDot');

    if (unread > 0 && bell && !existingDot) {
        const dot = document.createElement('span');
        dot.id = 'flowtrackNotificationDot';
        dot.className = 'dot';
        bell.appendChild(dot);
    } else if (unread === 0) {
        existingDot?.remove();
    }

    const notificationLink = [...document.querySelectorAll('#sidebar .nav-btn')]
        .find((link) => link.getAttribute('href')?.includes('/notifications'));
    if (!notificationLink) return;

    let badge = notificationLink.querySelector('.nav-badge');
    if (unread === 0) {
        badge?.remove();
        return;
    }
    if (!badge) {
        badge = document.createElement('span');
        badge.className = 'nav-badge';
        notificationLink.appendChild(badge);
    }
    badge.textContent = String(unread);
};

export const setMyWorkCount = (count) => {
    const value = Math.max(0, Number.parseInt(String(count ?? 0), 10) || 0);
    const myWorkLink = [...document.querySelectorAll('#sidebar .nav-btn')]
        .find((link) => link.getAttribute('href')?.includes('/my-work'));
    if (!myWorkLink) return;

    let badge = myWorkLink.querySelector('.nav-badge');
    if (value === 0) {
        badge?.remove();
        return;
    }
    if (!badge) {
        badge = document.createElement('span');
        badge.className = 'nav-badge';
        myWorkLink.appendChild(badge);
    }
    badge.textContent = String(value);
};

export const setCancelledOrderCount = (count) => {
    const value = Math.max(0, Number.parseInt(String(count ?? 0), 10) || 0);
    const cancelledLink = [...document.querySelectorAll('#sidebar .nav-btn')]
        .find((link) => link.getAttribute('href')?.includes('/orders/cancelled'));
    if (!cancelledLink) return;

    let badge = cancelledLink.querySelector('.nav-badge');
    if (value === 0) {
        badge?.remove();
        return;
    }
    if (!badge) {
        badge = document.createElement('span');
        badge.className = 'nav-badge';
        cancelledLink.appendChild(badge);
    }
    badge.textContent = String(value);
};

