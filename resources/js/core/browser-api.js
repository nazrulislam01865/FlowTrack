import { createAsyncState } from '../components/async-feedback.js';
import { createInlineEdit } from '../components/inline-edit.js';
import { createOrderHoldGuard } from '../components/order-hold-guard.js';
import {
    createFloatingActionMenu,
    createLocalFilter,
    createMultiSelect,
    createRemoteFilter,
    createSearchSelect,
} from '../components/list-filters.js';
import { masterColor } from '../components/master-colors.js';
import { BROWSER_EVENTS, CONNECTION_STATES, LIVEWIRE_EVENTS, REALTIME_EVENTS } from './events.js';
import { getRealtimeClient } from './realtime.js';

/**
 * Minimal browser bridge intentionally retained for Alpine/Blade bindings.
 * Phase 15 removed the broad window.FlowTrack* compatibility aliases.
 */
export const installBrowserApi = () => {
    const existing = window.FlowTrack && typeof window.FlowTrack === 'object' ? window.FlowTrack : {};

    existing.ui = {
        ...(existing.ui || {}),
        inlineEdit: createInlineEdit,
        orderHoldGuard: createOrderHoldGuard,
        asyncState: createAsyncState,
        floatingActionMenu: createFloatingActionMenu,
        remoteFilter: createRemoteFilter,
        searchSelect: createSearchSelect,
        multiSelect: createMultiSelect,
        localFilter: createLocalFilter,
        masterColor,
    };
    existing.realtime = {
        ...(existing.realtime || {}),
        get client() { return getRealtimeClient(); },
    };
    existing.events = { REALTIME_EVENTS, LIVEWIRE_EVENTS, BROWSER_EVENTS, CONNECTION_STATES };

    window.FlowTrack = existing;
    return existing;
};
