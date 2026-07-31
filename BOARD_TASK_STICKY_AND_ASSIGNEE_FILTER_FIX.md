# Board Task Sticky Header and Assignee Filter Fix

- Restored the Assignee filter on the Operations Board toolbar after the Client filter.
- Assignee filter now stays visible in both Job Board and Task Board modes, including responsive layouts.
- The filter uses existing board filtering logic for job owner/coordinator and task effective assignee.
- Removed the CSS rule that hid the assignee select in the compact board layout.
- Updated the Task Board status header so it remains sticky during vertical page scrolling while job-group task rows scroll underneath it.
- Preserved horizontal scrolling for status columns without hiding the sticky header.
