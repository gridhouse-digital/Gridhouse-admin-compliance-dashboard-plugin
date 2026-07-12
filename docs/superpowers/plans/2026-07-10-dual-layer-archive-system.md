# Dual-Layer Archive System Implementation Plan (v2 - Expanded Parity)

## Overview
Transform the "Mark Reviewed" process into a Dual-Layer Archive generation system. When an admin marks an employee's records as reviewed, the system will:
1. Generate an immutable PDF packet (Hard Copy) and save it permanently.
2. Snapshot all granular course completion data (Digital Ledger) into a custom database table.
3. Lock the employee's records to prevent unauthorized silent edits, ensuring strict compliance.

## Answers to Strategic Questions

**1. Cycle Identification: Can this be flexible, and how?**
Yes. Instead of an integer `cycle_year`, we will use a `cycle_id` `VARCHAR(32)` column. This allows you to tag archives with highly flexible strings like `"2025"`, `"Oct 2025 - Oct 2026"`, or `"2026-AMBULATORY"`. This future-proofs the system against state policy changes regarding how cycles are defined.

**2. Unlocking Process: Keep them marked as "revoked"**
We will use a **Strict Append-Only (Immutable Ledger)** approach. We will never delete rows or PDFs. When an admin unlocks a record, the current archive's status flips to `revoked`. When they re-review it, a new archive is generated, and a `superseded_by_archive_id` column links the old record to the new one. This proves to an auditor exactly when and why a correction was made, leaving no gaps in the database IDs.

**3. Progress Reset: Plugins (WooNinjas) vs Core Functions**
**Recommendation:** We should hook directly into the core LearnDash function: `learndash_delete_course_progress`. 
**Why:** Hooking into the lowest-level database operation acts as an absolute safety net. Whether an admin clicks a WooNinjas button, runs a CSV import, or uses custom code, the `learndash_delete_course_progress` hook will *always* fire before the data is destroyed. By attaching our archiving automation to this hook, we guarantee a snapshot is captured right before the data is wiped, making it agnostic to *how* the reset was triggered.

---

## Proposed Architecture

### 1. Database Schema (The Digital Ledger)
We will introduce two new custom tables via `dbDelta()` in a new activation hook `class-install.php`.

**Table: `wp_ghca_archives`**
```sql
CREATE TABLE wp_ghca_archives (
  id BIGINT(20) NOT NULL AUTO_INCREMENT,
  user_id BIGINT(20) NOT NULL,
  cycle_id VARCHAR(32) NOT NULL,          -- Highly flexible string (e.g. '2026-A')
  packet_file_path VARCHAR(255) NOT NULL,
  file_hash VARCHAR(64) NOT NULL,         -- SHA-256 hash for cryptographic proof of immutability
  status ENUM('locked', 'unlocked', 'revoked') NOT NULL DEFAULT 'locked',
  superseded_by_archive_id BIGINT(20) DEFAULT NULL, -- Audit trail linkage
  reviewed_by BIGINT(20) NOT NULL,
  created_at DATETIME NOT NULL,
  PRIMARY KEY (id),
  KEY user_id (user_id),
  KEY cycle_id (cycle_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

**Table: `wp_ghca_course_history`**
```sql
CREATE TABLE wp_ghca_course_history (
  id BIGINT(20) NOT NULL AUTO_INCREMENT,
  archive_id BIGINT(20) NOT NULL,
  course_id BIGINT(20) NOT NULL,
  course_title VARCHAR(255) NOT NULL,
  completion_date DATETIME NOT NULL,
  time_spent_seconds INT(11) NOT NULL,
  quiz_score_percentage DECIMAL(5,2) DEFAULT NULL, -- Crucial for proving a passing grade
  PRIMARY KEY (id),
  KEY archive_id (archive_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

---

### 2. The PDF Builder Adaptation (The Hard Copy)
- **Permanent Storage:** Create a secure directory `wp-content/uploads/ghca_compliance_archives/{year}/` protected by an `.htaccess` file denying direct access.
- **Cryptographic Hashing:** During the PDF generation, the system will use PHP's `hash_file('sha256', $file_path)` to generate a cryptographic hash of the PDF and store it in `file_hash`. This allows auditors to verify the file hasn't been altered on the disk.
- **API Change:** Add a flag `action_type=archive` to the existing async PDF builder endpoints. When present, the final `merge` step will move the PDF to permanent storage, write the hash, and insert the rows into the database.

---

### 3. Record Locking (Strict Compliance)
- **Backend Guard:** In `class-ajax-handlers.php` inside `ajax_save_employee_records()`, verify if the user's current cycle is "locked". Reject any save attempts with an explicit WP_Error if locked.
- **Frontend UI (DOM-Tamper Proof):** In the "Edit Records" modal, we will render a robust, absolute-positioned overlay (`z-index`) banner across the form fields. This prevents admins from simply removing the `disabled` attribute in browser DevTools to bypass the UI lock. 
- **Unlock Action:** An "Unlock" button updates the active archive status to `revoked` and removes the `ghca_acd_reviewed_at` meta, unblocking the form.

---

### 4. "Archive Vault" Secure Streaming
We will build a dedicated, secure download endpoint (e.g., `wp_ajax_ghca_vault_download`) to stream the PDFs. This ensures the `.htaccess` block on the uploads folder remains unbroken.

**Security Flow:**
1. Verify Nonce and Admin/Group Leader capability over the requested `user_id`.
2. Fetch the path from `wp_ghca_archives`.
3. Use `ob_end_clean()` and `readfile()` to stream the PDF bytes directly to the browser with `Content-Disposition: attachment`.

---

### Execution Plan (Steps to Code)
1. **Database & Install:** Create `class-install.php` with `dbDelta()` logic and hook it into plugin activation.
2. **Backend API:** Build `class-archive-manager.php` to handle the `learndash_delete_course_progress` hook, DB insertions, file hashing, and the vault streamer.
3. **PDF Generator Updates:** Modify `class-audit-pdf.php` to accept the `action_type=archive` flag and handle permanent file moving.
4. **UI & AJAX:** Update `dashboard.js` and `class-ajax-handlers.php` to inject the Lock banner, build the Archive Vault tab in the Employee Drawer, and handle the Unlock button.
