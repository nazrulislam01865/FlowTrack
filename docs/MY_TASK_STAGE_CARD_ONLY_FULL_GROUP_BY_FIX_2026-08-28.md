# My Tasks stage-card ONLY_FULL_GROUP_BY fix — 2026-08-28

## Symptom

Opening `/my-work` failed on MySQL with error 1055 (`tasks.id isn't in GROUP BY`) while building the stage cards.

## Cause

`personalTaskQuery()` intentionally selects `tasks.*` because it feeds the My Tasks table. `orderPhaseCards()` reused that same active-task scope for an aggregate query and appended `stage_key` plus `COUNT(tasks.id)`, leaving `tasks.*` in the projection. With `ONLY_FULL_GROUP_BY` enabled, MySQL correctly rejected the grouped query.

## Fix

The stage-card aggregate now clears the inherited projection with `select([])` before selecting only `stage_key` and `COUNT(tasks.id)`. All joins and filters from the structural single-active-task scope are preserved, so the stage counts still match My Tasks exactly without weakening strict SQL mode.

No migration or data repair is required.
