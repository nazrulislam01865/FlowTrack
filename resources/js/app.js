import { installBrowserApi } from './core/browser-api.js';
import { bootAttachmentAutoUpload } from './components/attachment-auto-upload.js';
import { bootArtworkChunkUpload } from './components/artwork-chunk-upload.js';
import { bootAsyncFeedback, resetAsyncFeedback } from './components/async-feedback.js';
import { bootFileDropzones } from './components/file-dropzones.js';
import { bootLocalFileActions } from './components/local-file-actions.js';
import { resetInlineEditState } from './components/inline-edit.js';
import { bootMasterColors } from './components/master-colors.js';
import { bootLivewireMentionHooks, bootMentionInputs, observeMentionInputs } from './components/mentions.js';
import { bootOrderHoldRequestFeedback } from './components/order-hold-request-feedback.js';
import {
    bootLivewireRichTextHooks,
    bootRichTextEditors,
    bootRichTextImageViewer,
    observeRichTextEditors,
    scheduleRichTextRefresh,
} from './components/rich-text.js';
import { bindNavigationLifecycle } from './core/navigation.js';
import { bootRealtimeClient } from './core/realtime.js';
import { bootSessionRecovery } from './core/session-recovery.js';
import { bootSessionSafety } from './core/session-safety.js';
import { bootShell } from './core/shell.js';
import { syncBrowserTimezone } from './core/timezone.js';
import { bootLivewireNotificationEvents, bootNotifications, syncUnreadCount } from './features/notifications.js';
import { bootRealtimeTelemetry } from './features/realtime-telemetry.js';
import { bootRouteFeatures } from './features/route-loader.js';
import { bootWorkspaceRefresh, subscribeWorkspace, syncWorkspaceState } from './features/workspace-refresh.js';

installBrowserApi();

const bootShared = () => {
    syncBrowserTimezone();
    bootShell();
    bootAsyncFeedback();
    bootOrderHoldRequestFeedback();

    const realtime = bootRealtimeClient();
    bootNotifications(realtime);
    bootRealtimeTelemetry(realtime);
    bootWorkspaceRefresh(realtime);

    bootSessionSafety();
    bootSessionRecovery();
    bootRichTextEditors();
    observeRichTextEditors();
    bootLivewireRichTextHooks();
    bootRichTextImageViewer();
    bootMentionInputs();
    observeMentionInputs();
    bootLivewireMentionHooks();
    bootFileDropzones();
    bootLocalFileActions();
    bootAttachmentAutoUpload();
    bootArtworkChunkUpload();
    bootMasterColors();
    bootRouteFeatures();
};

const bootLivewire = () => {
    bootSessionRecovery();
    bootLivewireNotificationEvents();
    bootLivewireRichTextHooks();
    bootLivewireMentionHooks();
    bootAsyncFeedback();
    bootOrderHoldRequestFeedback();
    bootMasterColors();

    const realtime = bootRealtimeClient();
    bootNotifications(realtime);
    bootRealtimeTelemetry(realtime);
    subscribeWorkspace(realtime);
};

bindNavigationLifecycle({
    boot: bootShared,
    livewireInit: () => {
        bootRichTextEditors();
        observeRichTextEditors();
        bootRichTextImageViewer();
        bootMentionInputs();
        observeMentionInputs();
        bootLivewire();
        bootRouteFeatures();
    },
    navigating: (event) => {
        resetInlineEditState();
        resetAsyncFeedback();
        event.detail?.onSwap?.(() => {
            scheduleRichTextRefresh();
            observeRichTextEditors();
            bootRichTextImageViewer();
        });
    },
    navigated: () => {
        scheduleRichTextRefresh();
        observeRichTextEditors();
        bootRichTextImageViewer();
        syncBrowserTimezone();
        bootShell();
        bootLivewire();
        syncWorkspaceState();
        bootMentionInputs();
        observeMentionInputs();
        bootFileDropzones();
        bootLocalFileActions();
        bootAttachmentAutoUpload();
        bootMasterColors();
        bootRouteFeatures();
    },
    loaded: () => {
        const realtime = bootRealtimeClient();
        bootNotifications(realtime);
        bootLivewireNotificationEvents();
        bootWorkspaceRefresh(realtime);
        syncUnreadCount();
    },
});
