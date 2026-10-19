import { syncUnreadCount } from '../features/notifications.js';

const flowtrackSessionState = { lastHumanActivity: Date.now(), statusTimer: null, idleTimer: null, bound: false, ownerChecked: false };
const flowtrackSessionTimeoutMs = () => (Number.parseInt(document.querySelector('meta[name="flowtrack-session-timeout"]')?.content || '1800', 10) || 1800) * 1000;
const flowtrackRedirectToLogin = (reason = 'timeout') => { if (window.location.pathname !== '/login') window.location.assign(`/login?reason=${encodeURIComponent(reason)}`); };
const flowtrackLogoutForTimeout = async () => {
    const url = document.querySelector('meta[name="flowtrack-logout-url"]')?.content;
    const csrf = document.querySelector('meta[name="csrf-token"]')?.content;
    if (url && csrf) {
        try {
            await fetch(url, {method: 'POST', headers: {'X-CSRF-TOKEN': csrf, 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json'}, credentials: 'same-origin'});
        } catch (_) {}
    }
    flowtrackRedirectToLogin('timeout');
};
const checkFlowtrackSessionOwner = async () => {
    const url = document.querySelector('meta[name="flowtrack-session-status-url"]')?.content;
    if (!url || document.hidden) return;
    try {
        const response = await fetch(url, {headers: {'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest', 'X-FlowTrack-Background': '1'}, credentials: 'same-origin', cache: 'no-store', redirect: 'manual'});
        if (response.status === 409 || response.status === 401 || response.type === 'opaqueredirect') flowtrackRedirectToLogin('other-device');
    } catch (_) {}
};
const checkFlowtrackIdle = () => { if (Date.now() - flowtrackSessionState.lastHumanActivity >= flowtrackSessionTimeoutMs()) flowtrackLogoutForTimeout(); };
export const bootSessionSafety = () => {
    if (!document.querySelector('meta[name="flowtrack-session-status-url"]')) return;
    if (!flowtrackSessionState.bound) {
        flowtrackSessionState.bound = true;
        const mark = () => { flowtrackSessionState.lastHumanActivity = Date.now(); };
        ['pointerdown', 'keydown', 'touchstart', 'wheel'].forEach((name) => window.addEventListener(name, mark, {passive: true}));
        document.addEventListener('visibilitychange', () => {
            if (!document.hidden) {
                checkFlowtrackIdle();
                checkFlowtrackSessionOwner();
                syncUnreadCount();
            }
        });
        window.addEventListener('focus', () => {
            checkFlowtrackSessionOwner();
            syncUnreadCount();
        });
    }
    if (!flowtrackSessionState.ownerChecked) {
        flowtrackSessionState.ownerChecked = true;
        checkFlowtrackSessionOwner();
    }
    if (!flowtrackSessionState.statusTimer) {
        flowtrackSessionState.statusTimer = window.setInterval(checkFlowtrackSessionOwner, 300000);
    }
    if (!flowtrackSessionState.idleTimer) flowtrackSessionState.idleTimer = window.setInterval(checkFlowtrackIdle, 30000);
};
