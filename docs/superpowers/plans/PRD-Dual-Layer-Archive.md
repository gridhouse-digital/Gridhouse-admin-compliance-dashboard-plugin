# Product Requirements Document (PRD): Dual-Layer Compliance Archive System

## 1. Context & Problem Statement
Gridhouse Healthcare Academy uses LearnDash (a WordPress LMS plugin) to manage employee compliance training. However, LearnDash is fundamentally designed as a standard LMS, not a strict healthcare compliance vault. 

**The Problem:** When an agency needs to put an employee through their annual compliance renewal, the standard procedure is to "reset" the user's LearnDash progress so they can retake the courses. Doing this permanently overwrites or deletes their historical completion dates. If a state auditor (OLTL/ODP) arrives and requests proof of an employee's compliance from two years ago, the data no longer exists in LearnDash. Furthermore, because LearnDash generates certificates dynamically, if a certificate design changes in 2025, any dynamically rendered 2024 certificate will incorrectly show the 2025 design.

State-level healthcare audits require **immutable, historical proof** of compliance that cannot be overwritten, silently edited, or dynamically altered.

## 2. Product Vision
To solve this, we are building a **Dual-Layer Archive System** natively inside the Gridhouse Admin Compliance Dashboard plugin. It decouples the "live progress" (LearnDash) from the "audit ledger."

- **Layer 1 (The Digital Ledger):** A custom database table that scrapes and stores granular completion data (dates, time spent, quiz scores) at the exact moment a cycle ends. This allows agency owners to query and report on historical data.
- **Layer 2 (The Hard Copy):** An asynchronous backend engine that compiles a multi-page PDF packet containing the employee's matrix cover sheet and all earned certificates, freezing them in time. 

## 3. Key Personas
1. **Compliance Lead (Admin):** Needs to review an employee's training, lock it as "Compliant" for the year, and safely reset their progress for the next cycle without losing historical proof.
2. **Agency Owner:** Needs to pull aggregate historical data (e.g., "Total training hours across the agency in 2024").
3. **State Auditor:** Demands undeniable, tamper-proof physical (PDF) evidence that a specific employee was fully compliant on a specific past date.

## 4. Core Features & User Stories

### Feature 1: The "Mark Reviewed" Archive Trigger
* **User Story:** As a Compliance Lead, when I click "Mark Reviewed" on an employee's dashboard, I want the system to automatically generate a permanent historical archive of their current progress.
* **Requirements:** 
  * The frontend triggers an asynchronous PDF generation queue.
  * The resulting PDF is saved to a permanent, secure directory (`wp-content/uploads/ghca_compliance_archives/{year}/`).
  * Granular data (Course IDs, Time Spent, Quiz Scores) is scraped from LearnDash and written to a custom `wp_ghca_course_history` table.

### Feature 2: Strict Append-Only Record Locking
* **User Story:** As an Auditor, I need assurance that an agency cannot silently edit an employee's completion dates after they have been certified as compliant for a given year.
* **Requirements:**
  * Once a cycle is archived, the "Edit Records" UI is locked for that employee.
  * If an admin must make a correction, they must click **"Unlock"**. This action explicitly changes the archive's database status to `revoked`. 
  * When the record is corrected and re-reviewed, a new archive is generated. A `superseded_by_archive_id` column explicitly links the new archive to the revoked one, leaving a transparent audit trail of the correction.

### Feature 3: The Automated Reset Safety Net
* **User Story:** As a System Admin, when my automated cron jobs or plugins (e.g., WooNinjas) wipe an employee's LearnDash progress on Jan 1st, I need a guarantee that an archive was captured even if a human forgot to click "Mark Reviewed".
* **Requirements:**
  * The archiving engine hooks directly into the core `learndash_delete_course_progress` developer hook. Before the data is destroyed, the system forces a final snapshot to the Digital Ledger.

### Feature 4: The Archive Vault UI
* **User Story:** As a Compliance Lead, I want a dedicated tab to view and download past compliance packets for an employee.
* **Requirements:**
  * A new tab in the Employee Drawer lists all historical archives.
  * Files are streamed securely via a PHP endpoint to ensure `.htaccess` directory protections are not bypassed.

## 5. Technical & Security Requirements

### Database Schema (Custom Tables)
To avoid LearnDash data-loss, the plugin will introduce two isolated tables:
1. **`wp_ghca_archives`**: Tracks the archive event.
   * Key columns: `user_id`, `cycle_id` (VARCHAR for flexibility, e.g., '2026-A'), `packet_file_path`, `file_hash`, `status` (locked/revoked), `superseded_by_archive_id`.
2. **`wp_ghca_course_history`**: Tracks granular course data for querying.
   * Key columns: `archive_id`, `course_id`, `completion_date`, `time_spent_seconds`, `quiz_score_percentage`.

### Cryptographic Immutability
* During PDF generation, the system will use `hash_file('sha256')` to generate a cryptographic hash of the PDF packet and store it in the database. 
* This provides undeniable mathematical proof to auditors that the PDF on the server has not been tampered with since the exact moment it was generated.

### Secure File Streaming
* The `ghca_compliance_archives` directory will contain an `.htaccess` file denying all direct HTTP access (`Require all denied`).
* Authorized admins will download archives via a secure AJAX endpoint (`wp_ajax_ghca_vault_download`) that verifies capabilities and nonces before using `readfile()` to stream the bytes to the browser.

## 6. Success Criteria
- [ ] Admins can generate a frozen PDF and Ledger entry by clicking "Mark Reviewed".
- [ ] Attempting to edit a locked record forces the creation of a transparent "revoked" audit trail.
- [ ] Resetting a user's LearnDash progress via any method (manual, CSV, WooNinjas) triggers a safety-net database snapshot.
- [ ] The generated PDF hashes perfectly match the database hashes.
