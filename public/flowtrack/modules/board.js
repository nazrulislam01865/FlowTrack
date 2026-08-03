/* FlowTrack runtime module: board. Build: f400609959067571. */
/* FlowTrack Job Board card-width refinement: slightly wider phase columns/cards for readability. */
(() => {
    if (document.getElementById('flowtrack-job-board-wider-cards')) return;
    
    /* CSS externalized: style. */
})();

/* FlowTrack Operations Board visual consistency: Job Board and Task Board share the same typography and filter sizing. */
(() => {
    if (document.getElementById('flowtrack-operations-board-consistency')) return;
    
    /* CSS externalized: style. */
})();

/* 2026-08-01 Board mobile usability + inline assignee editor final override.
   Keep this block last: older responsive audit styles intentionally preserve desktop
   board minimum widths and are overridden here only on small screens. */
(() => {
    
    /* CSS externalized: style. */
})();

/* 2026-08-01 Mobile Board vertical-scroll ownership fix.
   The stacked mobile Board must use the document/page as the vertical scroller.
   Older sticky-board rules used a contained viewport-height canvas; on touch devices
   that could swallow pan gestures after the canvas was changed to overflow:visible. */
(() => {
    if (document.getElementById('flowtrack-board-mobile-page-scroll')) return;
    
    /* CSS externalized: style. */
})();
