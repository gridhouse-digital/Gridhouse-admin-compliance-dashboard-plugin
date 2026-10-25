# Beta 9 — external annual category correction

Fixes approved external training categorized as `odp_annual_training` being silently excluded before course details and certificate selection. Restores the catalog label and calculator bucket together. Broad-category hours go to Additional training hours, not the six specific 6100 topic rows. Existing approvals, immutable snapshots, annual date filters, orientation opt-in, evidence validation, and merge failure behavior are unchanged. Includes beta 8 changes.

No database migration, remote installation, messaging or Jotform operations. No automatic duplicate detection: separately approved LearnDash and external completions remain separate records. Agency must review whether uploads represent additional training or evidence for the same completion before relying on combined hours; matching titles alone are insufficient to remove credit.

Verification: `php tests/test-external-annual-category.php` exercises orientation, default annual and custom dates using five synthetic external records: 5 courses, 5 certificate references, 5.5 additional hours, no specific-topic award, opt-in/date/unknown-category exclusion and no mutation. Reuses 248 prior assertions plus 17 new assertions. Selection tests do not prove full live PDF merging or image rendering. Staging must regenerate both reported packets and inspect all certificate pages. No historical PDFs change automatically.

Changed: includes/class-audit-calculator.php, includes/class-audit-mapping.php, plugin version, regression test, release/acceptance documentation. No Git metadata in this work copy; release manifest provides hash parity.
