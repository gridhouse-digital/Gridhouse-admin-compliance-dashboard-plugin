# Beta 10 — drawer completion checkbox

Unchecking Mark this course complete now submits explicit `0`. Missing fields do not revoke completion, malformed values are rejected, and the existing nonce, edit capability, visibility and enrolled-course checks remain in place. The incomplete branch runs before completion-date validation so a prefilled date cannot restore completion.

The handler preserves previous course progress, completion timestamp and course/reopened-lesson activity in append-only `ghca_acd_course_correction_history` user-meta entries, attributed to actor and time, before edits. This is correction history, not an immutable ledger. It removes the active completion date and updates the course activity/progress. If all lessons were complete, only the last lesson is reopened to avoid LearnDash immediately recompleting the course. Quiz attempts, timers, other courses and approved external evidence are untouched. Existing admin/employee cache invalidation runs after the batch.

Quiz-only courses with passed global quizzes are deliberately blocked with a specific review message rather than deleting historical quiz attempts or pretending they remain incomplete. Storage readback failures surface an error and retain the pre-edit snapshot; there is no claim of transactional rollback for this existing batch editor. No automatic correction of any employee is performed.

Tests: `php tests/test-course-incomplete.php` covers real handler branching with synthetic WordPress/LearnDash storage doubles: unchecked prefilled date, all-complete lessons, missing progress/orphan date, recheck, repeat saves, history attribution, nonces/capabilities/visibility/enrollment, malformed/omitted fields, history failure, activity failure, quiz-only block and unrelated history preservation. Full installed LearnDash/browser behavior remains a staging acceptance gate.

Acceptance: replace plugin with beta 10 on staging; reopen drawer; uncheck and save a completed synthetic course; reopen drawer and refresh course page; generate a NEW packet and verify the removed LearnDash course/certificate/hours are absent. Check last-lesson reopening and retained quiz attempts. Recheck with valid completion date and verify completion resumes. Previously downloaded PDFs remain unchanged. Independently approved external completions can still appear in packets.

Includes beta 9 external-category fix and prior updates. No Jotform calls, messages, live installs or real record writes were made. No Git metadata in work copy; use package manifest for parity.
