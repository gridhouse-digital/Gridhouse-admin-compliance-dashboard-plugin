# Rolling Expirations & Traffic Light System — Implementation Plan (Phase 1)

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add per-course rolling expirations with a 🟢/🟡/🔴 traffic-light model to the admin compliance dashboard, configurable via an Options-API settings UI, with a daily-rolling cache.

**Architecture:** A new pure-logic class `GHCA_Course_Lifespans` owns the expiration policy (evaluate one course, roll up many). The main plugin attaches per-course state in `get_user_courses()`, rolls it up to an employee status in `build_employee_record()`, and surfaces new counts/filters/KPIs in `get_aggregate()`, `render_kpis()`, and the status filter. The settings page gains a course-lifespan repeater + warning-window field. The aggregate cache key gains a date stamp so overnight 🟡→🔴 transitions recompute with no DB write.

**Tech Stack:** PHP 8.3 (WordPress plugin), LearnDash, WordPress Options API + Transients. No build step. Vanilla JS for the settings repeater.

## Global Constraints

- **Plugin version:** bump `GHCA_Admin_Compliance_Dashboard::VERSION` from `1.0.0` → `1.1.0` (string, used in the cache key).
- **Text domain:** `ghca-acd` for every user-facing string via `__()` / `esc_html__()` / `esc_html_e()`.
- **Capability gate:** all settings UI stays under the existing `manage_options` page; dashboard reads stay behind `can_view_dashboard()` / `GHCA_ACD_Roles::user_can_view()`.
- **No new dependencies.** Options API + transients only.
- **Class file guard:** every PHP file starts with `if ( ! defined( 'ABSPATH' ) ) { exit; }`.
- **Default warning window:** `90` days. **Lifespan bounds:** `1`–`3650` days.
- **Expiration is read-time only** — never write to LearnDash completion data.
- **PHP CLI for tests (no PHPUnit in this repo):**
  `"/c/laragon/bin/php/php-8.3.30-Win32-vs16-x64/php.exe"` — referred to below as `PHP`.

## File Structure

- **New:** `includes/class-course-lifespans.php` — expiration policy (pure `evaluate`/`rollup` + option accessors + map sanitizer).
- **New:** `tests/bootstrap.php`, `tests/test-course-lifespans.php` — standalone CLI tests for the pure logic.
- **Modify:** `gridhouse-admin-compliance-dashboard.php` — require new class, VERSION, cache key, per-course state, employee rollup, aggregate counters, KPI card, status options + filter, status/course rendering.
- **Modify:** `includes/class-settings.php` — register two options, render the new settings section, bust cache on save.
- **Modify:** `includes/class-compliance-program.php` — attach per-course state to new-hire course cards (display parity).
- **Modify:** `assets/dashboard.css` — `expiring_soon` / `expired` status + course-pill styling.

---

### Task 1: `GHCA_Course_Lifespans` pure policy core (`evaluate` + `rollup`)

**Files:**
- Create: `includes/class-course-lifespans.php`
- Create: `tests/bootstrap.php`
- Create: `tests/test-course-lifespans.php`

**Interfaces:**
- Produces:
  - `GHCA_Course_Lifespans::evaluate( bool $completed, int $completed_ts, int $lifespan_days, int $warning_days, int $now ): array` → `['state' => 'incomplete'|'current'|'expiring_soon'|'expired', 'expiration_ts' => int]`
  - `GHCA_Course_Lifespans::rollup( array $states ): string` → `'current'|'expiring_soon'|'expired'`
  - Consts: `OPTION_LIFESPANS = 'ghca_acd_course_lifespans'`, `OPTION_WARNING_DAYS = 'ghca_acd_warning_days'`, `DEFAULT_WARNING_DAYS = 90`.

- [ ] **Step 1: Write the failing tests**

Create `tests/bootstrap.php`:

```php
<?php
// Standalone bootstrap so pure logic can run without WordPress.
if ( ! defined( 'ABSPATH' ) ) { define( 'ABSPATH', __DIR__ . '/' ); }
if ( ! defined( 'DAY_IN_SECONDS' ) ) { define( 'DAY_IN_SECONDS', 86400 ); }
require_once __DIR__ . '/../includes/class-course-lifespans.php';
```

Create `tests/test-course-lifespans.php`:

```php
<?php
require __DIR__ . '/bootstrap.php';

$fails = 0;
function check( bool $cond, string $msg ): void {
  global $fails;
  if ( $cond ) { echo "PASS: $msg\n"; } else { echo "FAIL: $msg\n"; $fails++; }
}

$now = 1000000000;
$day = 86400;

check( GHCA_Course_Lifespans::evaluate( false, 0, 365, 90, $now )['state'] === 'incomplete', 'not completed => incomplete' );
check( GHCA_Course_Lifespans::evaluate( true, $now - 100 * $day, 0, 90, $now )['state'] === 'current', 'completed, no lifespan => current (complete-once)' );
check( GHCA_Course_Lifespans::evaluate( true, 0, 365, 90, $now )['state'] === 'current', 'completed, no timestamp => current (safe default)' );
check( GHCA_Course_Lifespans::evaluate( true, $now - 400 * $day, 365, 90, $now )['state'] === 'expired', 'past lifespan => expired' );
check( GHCA_Course_Lifespans::evaluate( true, $now - 300 * $day, 365, 90, $now )['state'] === 'expiring_soon', 'within warning window => expiring_soon' );
check( GHCA_Course_Lifespans::evaluate( true, $now - 10 * $day, 365, 90, $now )['state'] === 'current', 'far from expiry => current' );

$exp = GHCA_Course_Lifespans::evaluate( true, $now - 10 * $day, 365, 90, $now );
check( $exp['expiration_ts'] === $now + 355 * $day, 'expiration_ts = completed_ts + lifespan' );

check( GHCA_Course_Lifespans::rollup( array( 'current', 'expiring_soon', 'expired' ) ) === 'expired', 'rollup => worst is expired' );
check( GHCA_Course_Lifespans::rollup( array( 'current', 'expiring_soon', 'incomplete' ) ) === 'expiring_soon', 'rollup => expiring_soon' );
check( GHCA_Course_Lifespans::rollup( array( 'current', 'incomplete' ) ) === 'current', 'rollup => current' );

echo $fails === 0 ? "\nALL PASS\n" : "\n$fails FAILED\n";
exit( $fails === 0 ? 0 : 1 );
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `"/c/laragon/bin/php/php-8.3.30-Win32-vs16-x64/php.exe" tests/test-course-lifespans.php`
Expected: FAIL — fatal error, class `GHCA_Course_Lifespans` not found (file doesn't exist yet).

- [ ] **Step 3: Write the minimal class with `evaluate` + `rollup`**

Create `includes/class-course-lifespans.php`:

```php
<?php
if ( ! defined( 'ABSPATH' ) ) {
  exit;
}

/**
 * Rolling expiration ("traffic light") policy for required courses.
 *
 * Pure decision functions live here so they can be reasoned about and tested
 * in isolation, mirroring how GHCA_Compliance_Program isolates new-hire logic.
 */
final class GHCA_Course_Lifespans {
  const OPTION_LIFESPANS     = 'ghca_acd_course_lifespans';
  const OPTION_WARNING_DAYS  = 'ghca_acd_warning_days';
  const DEFAULT_WARNING_DAYS = 90;

  /**
   * Classify one course's compliance state.
   *
   * @return array{state:string,expiration_ts:int}
   *   state ∈ incomplete | current (🟢) | expiring_soon (🟡) | expired (🔴)
   */
  public static function evaluate( bool $completed, int $completed_ts, int $lifespan_days, int $warning_days, int $now ): array {
    if ( ! $completed ) {
      return array( 'state' => 'incomplete', 'expiration_ts' => 0 );
    }

    // Complete-once: no lifespan configured, or no anchor timestamp to count
    // from (legacy/imported completions stay green until HR sets a date).
    if ( $lifespan_days <= 0 || $completed_ts <= 0 ) {
      return array( 'state' => 'current', 'expiration_ts' => 0 );
    }

    $expiration_ts = $completed_ts + ( $lifespan_days * DAY_IN_SECONDS );

    if ( $now >= $expiration_ts ) {
      $state = 'expired';
    } elseif ( $now >= ( $expiration_ts - ( $warning_days * DAY_IN_SECONDS ) ) ) {
      $state = 'expiring_soon';
    } else {
      $state = 'current';
    }

    return array( 'state' => $state, 'expiration_ts' => $expiration_ts );
  }

  /**
   * Worst-wins rollup across a user's required course states.
   *
   * @param array<int,string> $states
   */
  public static function rollup( array $states ): string {
    if ( in_array( 'expired', $states, true ) ) {
      return 'expired';
    }
    if ( in_array( 'expiring_soon', $states, true ) ) {
      return 'expiring_soon';
    }
    return 'current';
  }
}
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `"/c/laragon/bin/php/php-8.3.30-Win32-vs16-x64/php.exe" tests/test-course-lifespans.php`
Expected: every line `PASS:` then `ALL PASS` (exit 0).

- [ ] **Step 5: Commit** (skip if not a git repo — this plugin currently is not; otherwise:)

```bash
git add includes/class-course-lifespans.php tests/
git commit -m "feat: add GHCA_Course_Lifespans traffic-light policy core"
```

> **Learning-mode note for the executor:** `evaluate()` is the policy heart (the green/yellow/red thresholds and the complete-once / no-timestamp defaults). Before pasting the reference body, offer the user the chance to write it themselves against the tests above — same spirit as the existing owner-implemented `validate_course_edit()` stub.

---

### Task 2: Option accessors + map sanitizer + wire into plugin load

**Files:**
- Modify: `includes/class-course-lifespans.php`
- Modify: `tests/test-course-lifespans.php` (add sanitizer cases)
- Modify: `gridhouse-admin-compliance-dashboard.php:14-24` (require the new file)

**Interfaces:**
- Produces:
  - `GHCA_Course_Lifespans::get_lifespan_map(): array<int,int>`
  - `GHCA_Course_Lifespans::get_lifespan_days( int $course_id ): int`
  - `GHCA_Course_Lifespans::get_warning_days(): int`
  - `GHCA_Course_Lifespans::sanitize_lifespan_map( $value ): array<int,int>`

- [ ] **Step 1: Add sanitizer tests (failing)**

Append to `tests/test-course-lifespans.php` *before* the final echo/exit:

```php
$m = GHCA_Course_Lifespans::sanitize_lifespan_map( array( '10' => '730', '20' => '0', 'x' => '5', '30' => '99999', '-4' => '12' ) );
check( $m === array( 10 => 730, 30 => 3650 ), 'sanitize: drops 0/invalid/negative ids, clamps to 3650' );
```

- [ ] **Step 2: Run to verify failure**

Run: `"/c/laragon/bin/php/php-8.3.30-Win32-vs16-x64/php.exe" tests/test-course-lifespans.php`
Expected: FAIL on the new line (`sanitize_lifespan_map` not defined → fatal). 

- [ ] **Step 3: Implement accessors + sanitizer**

Add these methods inside the class in `includes/class-course-lifespans.php`:

```php
  /** @return array<int,int> course_id => lifespan days */
  public static function get_lifespan_map(): array {
    $raw = get_option( self::OPTION_LIFESPANS, array() );
    return self::sanitize_lifespan_map( $raw );
  }

  public static function get_lifespan_days( int $course_id ): int {
    $map = self::get_lifespan_map();
    return isset( $map[ $course_id ] ) ? (int) $map[ $course_id ] : 0;
  }

  public static function get_warning_days(): int {
    $days = (int) get_option( self::OPTION_WARNING_DAYS, self::DEFAULT_WARNING_DAYS );
    return max( 7, min( 365, $days ) );
  }

  /**
   * Normalize the saved map to {positive int course_id: 1..3650 days}.
   * Pure: stray course IDs are harmless (a course not in a user's enrollment
   * is never evaluated), so no WP lookups happen here.
   *
   * @param mixed $value
   * @return array<int,int>
   */
  public static function sanitize_lifespan_map( $value ): array {
    $out = array();
    if ( ! is_array( $value ) ) {
      return $out;
    }
    foreach ( $value as $course_id => $days ) {
      $course_id = (int) $course_id;
      $days      = (int) $days;
      if ( $course_id <= 0 || $days <= 0 ) {
        continue;
      }
      $out[ $course_id ] = max( 1, min( 3650, $days ) );
    }
    return $out;
  }
```

> `get_lifespan_map` / `get_warning_days` call `get_option`, so they are only exercised in WordPress, not the CLI test. The CLI test covers only the pure `sanitize_lifespan_map`.

- [ ] **Step 4: Run pure tests to verify pass**

Run: `"/c/laragon/bin/php/php-8.3.30-Win32-vs16-x64/php.exe" tests/test-course-lifespans.php`
Expected: `ALL PASS` (exit 0).

- [ ] **Step 5: Require the class at plugin load**

In `gridhouse-admin-compliance-dashboard.php`, add after line 16 (`require_once __DIR__ . '/includes/class-compliance-program.php';`):

```php
require_once __DIR__ . '/includes/class-course-lifespans.php';
```

- [ ] **Step 6: Verify no PHP syntax errors**

Run: `"/c/laragon/bin/php/php-8.3.30-Win32-vs16-x64/php.exe" -l includes/class-course-lifespans.php`
Expected: `No syntax errors detected in includes/class-course-lifespans.php`.

- [ ] **Step 7: Commit** (if git): `git commit -am "feat: course lifespan option accessors + sanitizer"`

---

### Task 3: Register settings + bust cache on save

**Files:**
- Modify: `includes/class-settings.php:7-18` (consts + init hooks)
- Modify: `includes/class-settings.php:32-91` (register_settings)

**Interfaces:**
- Consumes: `GHCA_Course_Lifespans::OPTION_LIFESPANS`, `OPTION_WARNING_DAYS`, `DEFAULT_WARNING_DAYS`, `sanitize_lifespan_map`.
- Produces: two registered settings in the `ghca_acd_settings` group; cache busts when either changes.

- [ ] **Step 1: Add cache-bust hooks**

In `includes/class-settings.php`, inside `init()` (after line 15, the branding bust hook), add:

```php
    add_action( 'update_option_' . GHCA_Course_Lifespans::OPTION_LIFESPANS, array( __CLASS__, 'bust_dashboard_cache' ) );
    add_action( 'update_option_' . GHCA_Course_Lifespans::OPTION_WARNING_DAYS, array( __CLASS__, 'bust_dashboard_cache' ) );
```

- [ ] **Step 2: Register the two settings**

In `register_settings()`, after the `OPTION_CACHE_TTL` block (after line 57), add:

```php
    register_setting(
      'ghca_acd_settings',
      GHCA_Course_Lifespans::OPTION_LIFESPANS,
      array(
        'type'              => 'array',
        'sanitize_callback' => array( 'GHCA_Course_Lifespans', 'sanitize_lifespan_map' ),
        'default'           => array(),
      )
    );

    register_setting(
      'ghca_acd_settings',
      GHCA_Course_Lifespans::OPTION_WARNING_DAYS,
      array(
        'type'              => 'integer',
        'sanitize_callback' => static function ( $value ): int {
          $value = (int) $value;
          return max( 7, min( 365, $value ) );
        },
        'default'           => GHCA_Course_Lifespans::DEFAULT_WARNING_DAYS,
      )
    );
```

- [ ] **Step 3: Verify syntax**

Run: `"/c/laragon/bin/php/php-8.3.30-Win32-vs16-x64/php.exe" -l includes/class-settings.php`
Expected: `No syntax errors detected`.

- [ ] **Step 4: Commit** (if git): `git commit -am "feat: register rolling-expiration settings"`

---

### Task 4: Settings UI — course lifespan repeater + warning window

**Files:**
- Modify: `includes/class-settings.php:138-279` (`render_page` — add a section + helper to list courses)

**Interfaces:**
- Consumes: `GHCA_Course_Lifespans::get_lifespan_map()`, `get_warning_days()`.
- Produces: form fields posting `ghca_acd_course_lifespans[<course_id>]=<days>` and `ghca_acd_warning_days`.

- [ ] **Step 1: Add a published-courses helper**

In `includes/class-settings.php`, add a private method next to `get_learndash_groups()` (after line 136):

```php
  /** @return array<int,\WP_Post> */
  private static function get_published_courses(): array {
    $posts = get_posts(
      array(
        'post_type'              => 'sfwd-courses',
        'post_status'            => 'publish',
        'posts_per_page'         => 500,
        'orderby'                => 'title',
        'order'                  => 'ASC',
        'no_found_rows'          => true,
        'update_post_meta_cache' => false,
        'update_post_term_cache' => false,
      )
    );
    return is_array( $posts ) ? $posts : array();
  }
```

- [ ] **Step 2: Load values in `render_page`**

In `render_page()`, after line 152 (`$option_name = ...;`), add:

```php
    $lifespan_map   = GHCA_Course_Lifespans::get_lifespan_map();
    $warning_days   = GHCA_Course_Lifespans::get_warning_days();
    $all_courses    = self::get_published_courses();
    $lifespan_opt   = GHCA_Course_Lifespans::OPTION_LIFESPANS;
    $warning_opt    = GHCA_Course_Lifespans::OPTION_WARNING_DAYS;
```

- [ ] **Step 3: Render the section**

In `render_page()`, immediately before the `<?php submit_button(); ?>` line (line 274), insert:

```php
        <h2><?php esc_html_e( 'Rolling Expirations & Traffic Light', 'ghca-acd' ); ?></h2>
        <p><?php esc_html_e( 'Define how long each course stays valid after completion. A completed course turns yellow inside the warning window and red once it passes its lifespan. Courses with no lifespan never expire.', 'ghca-acd' ); ?></p>
        <table class="form-table" role="presentation">
          <tr>
            <th scope="row"><label for="ghca_acd_warning_days"><?php esc_html_e( 'Warning window (days)', 'ghca-acd' ); ?></label></th>
            <td>
              <input type="number" min="7" max="365" id="ghca_acd_warning_days" name="<?php echo esc_attr( $warning_opt ); ?>" value="<?php echo esc_attr( (string) $warning_days ); ?>" class="small-text" />
              <p class="description"><?php esc_html_e( 'How many days before expiry a course is flagged "Expiring Soon". Default: 90.', 'ghca-acd' ); ?></p>
            </td>
          </tr>
          <tr>
            <th scope="row"><?php esc_html_e( 'Course lifespans', 'ghca-acd' ); ?></th>
            <td>
              <?php if ( empty( $all_courses ) ) : ?>
                <p><?php esc_html_e( 'No published LearnDash courses found.', 'ghca-acd' ); ?></p>
              <?php else : ?>
                <div id="ghca-lifespan-rows">
                  <?php foreach ( $lifespan_map as $cid => $days ) : ?>
                    <div class="ghca-lifespan-row" style="margin:0 0 8px;display:flex;gap:8px;align-items:center;">
                      <select class="ghca-lifespan-course">
                        <option value="0"><?php esc_html_e( '— Select course —', 'ghca-acd' ); ?></option>
                        <?php foreach ( $all_courses as $course ) : ?>
                          <option value="<?php echo esc_attr( (string) $course->ID ); ?>" <?php selected( (int) $cid, (int) $course->ID ); ?>><?php echo esc_html( $course->post_title ); ?> (#<?php echo esc_html( (string) $course->ID ); ?>)</option>
                        <?php endforeach; ?>
                      </select>
                      <input type="number" min="1" max="3650" class="ghca-lifespan-days small-text" value="<?php echo esc_attr( (string) $days ); ?>" placeholder="<?php esc_attr_e( 'days', 'ghca-acd' ); ?>" />
                      <button type="button" class="button ghca-lifespan-remove"><?php esc_html_e( 'Remove', 'ghca-acd' ); ?></button>
                    </div>
                  <?php endforeach; ?>
                </div>
                <p><button type="button" class="button" id="ghca-lifespan-add"><?php esc_html_e( '+ Add course lifespan', 'ghca-acd' ); ?></button></p>
                <p class="description"><?php esc_html_e( 'Example: CPR = 730 days, HIPAA = 365 days. Rows with no course or 0 days are ignored on save.', 'ghca-acd' ); ?></p>

                <template id="ghca-lifespan-template">
                  <div class="ghca-lifespan-row" style="margin:0 0 8px;display:flex;gap:8px;align-items:center;">
                    <select class="ghca-lifespan-course">
                      <option value="0"><?php esc_html_e( '— Select course —', 'ghca-acd' ); ?></option>
                      <?php foreach ( $all_courses as $course ) : ?>
                        <option value="<?php echo esc_attr( (string) $course->ID ); ?>"><?php echo esc_html( $course->post_title ); ?> (#<?php echo esc_html( (string) $course->ID ); ?>)</option>
                      <?php endforeach; ?>
                    </select>
                    <input type="number" min="1" max="3650" class="ghca-lifespan-days small-text" placeholder="<?php esc_attr_e( 'days', 'ghca-acd' ); ?>" />
                    <button type="button" class="button ghca-lifespan-remove"><?php esc_html_e( 'Remove', 'ghca-acd' ); ?></button>
                  </div>
                </template>

                <input type="hidden" id="ghca-lifespan-name-base" value="<?php echo esc_attr( $lifespan_opt ); ?>" />
              <?php endif; ?>
            </td>
          </tr>
        </table>

        <script>
          (function () {
            var rows = document.getElementById('ghca-lifespan-rows');
            if (!rows) return;
            var base = document.getElementById('ghca-lifespan-name-base').value;
            var tpl = document.getElementById('ghca-lifespan-template');
            var addBtn = document.getElementById('ghca-lifespan-add');
            var form = rows.closest('form');

            function wire(row) {
              row.querySelector('.ghca-lifespan-remove').addEventListener('click', function () { row.remove(); });
            }
            Array.prototype.forEach.call(rows.querySelectorAll('.ghca-lifespan-row'), wire);

            addBtn.addEventListener('click', function () {
              var clone = tpl.content.firstElementChild.cloneNode(true);
              rows.appendChild(clone);
              wire(clone);
            });

            // On submit, materialize each row into name="base[courseId]" = days.
            form.addEventListener('submit', function () {
              Array.prototype.forEach.call(rows.querySelectorAll('.ghca-lifespan-row'), function (row) {
                var cid = parseInt(row.querySelector('.ghca-lifespan-course').value, 10);
                var days = parseInt(row.querySelector('.ghca-lifespan-days').value, 10);
                if (!cid || !days || days < 1) return;
                var hidden = document.createElement('input');
                hidden.type = 'hidden';
                hidden.name = base + '[' + cid + ']';
                hidden.value = days;
                form.appendChild(hidden);
              });
            });
          })();
        </script>
```

- [ ] **Step 4: Verify syntax**

Run: `"/c/laragon/bin/php/php-8.3.30-Win32-vs16-x64/php.exe" -l includes/class-settings.php`
Expected: `No syntax errors detected`.

- [ ] **Step 5: Manual test**

In WP admin → Settings → "Compliance Admin": add a row (pick a course, enter `365`), set warning to `90`, Save. Reload — the row persists and the warning field shows `90`. Add a second row, Remove it before saving — it does not persist.

- [ ] **Step 6: Commit** (if git): `git commit -am "feat: rolling-expiration settings UI"`

---

### Task 5: Daily-rolling cache key + VERSION bump

**Files:**
- Modify: `gridhouse-admin-compliance-dashboard.php:27` (VERSION)
- Modify: `gridhouse-admin-compliance-dashboard.php:2072-2074` (`get_cache_key`)
- Modify: `gridhouse-admin-compliance-dashboard.php:5` (plugin header `Version:`)

**Interfaces:**
- Produces: a cache key that varies by site-local day.

- [ ] **Step 1: Bump the version constant**

Change line 27 from:

```php
  const VERSION         = '1.0.0';
```
to:
```php
  const VERSION         = '1.1.0';
```

Also update the header comment on line 5 `Version: 1.0.0` → `Version: 1.1.0`.

- [ ] **Step 2: Add the date stamp to the cache key**

Replace `get_cache_key()` (lines 2072-2074):

```php
  private static function get_cache_key(): string {
    // Date stamp (site timezone) makes the aggregate self-invalidate at local
    // midnight, so a course flipping 🟡→🔴 overnight recomputes with no DB write.
    return 'ghca_acd_agg_' . self::VERSION . '_' . get_current_user_id() . '_' . wp_date( 'Ymd' );
  }
```

- [ ] **Step 3: Verify syntax**

Run: `"/c/laragon/bin/php/php-8.3.30-Win32-vs16-x64/php.exe" -l gridhouse-admin-compliance-dashboard.php`
Expected: `No syntax errors detected`.

- [ ] **Step 4: Manual test**

Load the dashboard. In the DB (or via `wp transient list`), confirm a transient named `ghca_acd_agg_1.1.0_<uid>_<YYYYMMDD>` exists. The existing `bust_dashboard_cache()` LIKE on `_transient_ghca_acd_agg_%` still matches (prefix unchanged).

- [ ] **Step 5: Commit** (if git): `git commit -am "feat: daily-rolling aggregate cache key; v1.1.0"`

---

### Task 6: Per-course traffic-light state in course lists

**Files:**
- Modify: `gridhouse-admin-compliance-dashboard.php:2395-2409` (`get_user_courses`)
- Modify: `includes/class-compliance-program.php:145-159` (`get_user_courses` — new-hire parity)

**Interfaces:**
- Consumes: `GHCA_Course_Lifespans::get_lifespan_days()`, `get_warning_days()`, `evaluate()`.
- Produces: each course array gains `lifespan_days:int`, `expiration_ts:int`, `expiration_label:string`, `compliance_state:string`.

- [ ] **Step 1: Augment the main `get_user_courses`**

In `gridhouse-admin-compliance-dashboard.php`, inside the `foreach` of `get_user_courses()`, after `$completed_ts = self::get_course_completed_timestamp( $user_id, $course_id );` (line 2393) add:

```php
      $lifespan_days = GHCA_Course_Lifespans::get_lifespan_days( $course_id );
      $eval          = GHCA_Course_Lifespans::evaluate(
        $completed,
        $completed_ts,
        $lifespan_days,
        GHCA_Course_Lifespans::get_warning_days(),
        time()
      );
```

Then extend the `$items[]` array literal (the one starting line 2395) with these keys (add before the closing `);`):

```php
        'lifespan_days'      => $lifespan_days,
        'expiration_ts'      => (int) $eval['expiration_ts'],
        'expiration_label'   => $eval['expiration_ts'] > 0 ? wp_date( 'M j, Y', (int) $eval['expiration_ts'] ) : '',
        'compliance_state'   => (string) $eval['state'],
```

- [ ] **Step 2: Mirror in the new-hire course list**

In `includes/class-compliance-program.php`, inside the `foreach` of `get_user_courses()`, after `$completed_ts = self::get_course_completed_timestamp( $user_id, $course_id );` (line 143) add:

```php
      $lifespan_days = GHCA_Course_Lifespans::get_lifespan_days( $course_id );
      $eval          = GHCA_Course_Lifespans::evaluate(
        $completed,
        $completed_ts,
        $lifespan_days,
        GHCA_Course_Lifespans::get_warning_days(),
        time()
      );
```

And add the same four keys to the `$items[]` array (before its closing `);`):

```php
        'lifespan_days'      => $lifespan_days,
        'expiration_ts'      => (int) $eval['expiration_ts'],
        'expiration_label'   => $eval['expiration_ts'] > 0 ? wp_date( 'M j, Y', (int) $eval['expiration_ts'] ) : '',
        'compliance_state'   => (string) $eval['state'],
```

- [ ] **Step 3: Verify syntax**

Run:
`"/c/laragon/bin/php/php-8.3.30-Win32-vs16-x64/php.exe" -l gridhouse-admin-compliance-dashboard.php`
`"/c/laragon/bin/php/php-8.3.30-Win32-vs16-x64/php.exe" -l includes/class-compliance-program.php`
Expected: `No syntax errors detected` for both.

- [ ] **Step 4: Commit** (if git): `git commit -am "feat: attach per-course traffic-light state"`

---

### Task 7: Employee-level rollup → expired / expiring_soon status

**Files:**
- Modify: `gridhouse-admin-compliance-dashboard.php:2324-2342` (regular-path status block in `build_employee_record`)

**Interfaces:**
- Consumes: per-course `compliance_state` (Task 6), `GHCA_Course_Lifespans::rollup()`.
- Produces: `status_slug` may now be `'expired'` or `'expiring_soon'`; record gains `expiry_state:string`. These slugs feed Tasks 8–10.

> Context: this is the **regular** (non-active-new-hire) path. A fully-complete former new hire already falls through to here (`build_employee_record` only takes the new-hire branch when `active && ! complete`), so all "100% complete then expires" cases land here.

- [ ] **Step 1: Compute the rollup and override status**

In `build_employee_record()`, replace the status block (lines 2329-2341) — currently:

```php
    if ( $all_complete ) {
      $status_slug  = 'completed';
      $status_label = __( 'Completed', 'ghca-acd' );
    } elseif ( $is_overdue ) {
      $status_slug  = 'overdue';
      $status_label = __( 'Overdue', 'ghca-acd' );
    } elseif ( $started_count > 0 || $completed_count > 0 ) {
      $status_slug  = 'in_progress';
      $status_label = __( 'In Progress', 'ghca-acd' );
    } else {
      $status_slug  = 'not_started';
      $status_label = __( 'Not Started', 'ghca-acd' );
    }
```

with:

```php
    // Roll up per-course traffic-light state (completed courses only react here).
    $course_states = array();
    foreach ( $courses as $c ) {
      $course_states[] = (string) ( $c['compliance_state'] ?? 'incomplete' );
    }
    $expiry_state = GHCA_Course_Lifespans::rollup( $course_states );

    if ( 'expired' === $expiry_state ) {
      // Finished but past its rolling lifespan → 🔴 Expired. Distinct row label
      // from incomplete "Overdue", but both roll into the Overdue KPI bucket.
      $status_slug  = 'expired';
      $status_label = __( 'Expired', 'ghca-acd' );
    } elseif ( $all_complete ) {
      if ( 'expiring_soon' === $expiry_state ) {
        $status_slug  = 'expiring_soon';
        $status_label = __( 'Expiring Soon', 'ghca-acd' );
      } else {
        $status_slug  = 'completed';
        $status_label = __( 'Completed', 'ghca-acd' );
      }
    } elseif ( $is_overdue ) {
      $status_slug  = 'overdue';
      $status_label = __( 'Overdue', 'ghca-acd' );
    } elseif ( $started_count > 0 || $completed_count > 0 ) {
      $status_slug  = 'in_progress';
      $status_label = __( 'In Progress', 'ghca-acd' );
    } else {
      $status_slug  = 'not_started';
      $status_label = __( 'Not Started', 'ghca-acd' );
    }
```

- [ ] **Step 2: Expose `expiry_state` on the record**

In the `return array( ... )` of the regular path (around line 2345-2367), add after `'status_label' => $status_label,`:

```php
      'expiry_state'         => $expiry_state,
```

- [ ] **Step 3: Verify syntax**

Run: `"/c/laragon/bin/php/php-8.3.30-Win32-vs16-x64/php.exe" -l gridhouse-admin-compliance-dashboard.php`
Expected: `No syntax errors detected`.

- [ ] **Step 4: Manual test**

With CPR=730 / warning=90 configured: use **Edit Records** to set a user's CPR completion date to 800 days ago → dashboard shows that employee as **Expired**. Set it to 700 days ago → **Expiring Soon**. Set to 100 days ago → **Completed**.

- [ ] **Step 5: Commit** (if git): `git commit -am "feat: roll up course expiry into employee status"`

---

### Task 8: Aggregate counters + Expiring Soon KPI card

**Files:**
- Modify: `gridhouse-admin-compliance-dashboard.php:2144-2219` (`get_aggregate` counters + return)
- Modify: `gridhouse-admin-compliance-dashboard.php:2092-2110` (`render_kpis` cards)

**Interfaces:**
- Consumes: `status_slug` values `'expired'` / `'expiring_soon'` (Task 7).
- Produces: aggregate keys `expiring_soon_employees:int`; Overdue bucket now includes `expired`; completion rate counts expiring as compliant.

- [ ] **Step 1: Add the expiring counter + fold expired into overdue**

In `get_aggregate()`, after `$next_due_ts = PHP_INT_MAX;` (line 2153) add:

```php
    $expiring          = 0;
```

Replace the bucket `if` (lines 2156-2162):

```php
      if ( 'completed' === $employee['status_slug'] || 'new_hire_completed' === $employee['status_slug'] ) {
        ++$completed;
      } elseif ( 'overdue' === $employee['status_slug'] || 'new_hire_overdue' === $employee['status_slug'] ) {
        ++$overdue;
      } elseif ( in_array( $employee['status_slug'], array( 'in_progress', 'new_hire_in_progress', 'new_hire_not_started' ), true ) ) {
        ++$in_progress;
      }
```

with:

```php
      if ( 'completed' === $employee['status_slug'] || 'new_hire_completed' === $employee['status_slug'] ) {
        ++$completed;
      } elseif ( 'expiring_soon' === $employee['status_slug'] ) {
        ++$expiring;
      } elseif ( in_array( $employee['status_slug'], array( 'overdue', 'new_hire_overdue', 'expired' ), true ) ) {
        ++$overdue;
      } elseif ( in_array( $employee['status_slug'], array( 'in_progress', 'new_hire_in_progress', 'new_hire_not_started' ), true ) ) {
        ++$in_progress;
      }
```

- [ ] **Step 2: Count Yellow as compliant in the rate + expose the count**

Change the rate line (2191) from:

```php
    $rate = $total > 0 ? (int) round( ( $completed / $total ) * 100 ) : 0;
```
to:
```php
    // Yellow (expiring soon) employees are still compliant for now.
    $rate = $total > 0 ? (int) round( ( ( $completed + $expiring ) / $total ) * 100 ) : 0;
```

In the `self::$aggregate = array( ... )` literal, add after `'overdue_employees_label' => ...,` (line 2210):

```php
      'expiring_soon_employees'      => $expiring,
      'expiring_soon_employees_label' => sprintf( _n( '%d expiring soon', '%d expiring soon', $expiring, 'ghca-acd' ), $expiring ),
```

- [ ] **Step 2b: Verify the in-progress/next-due guard still excludes Yellow**

The `next_due_ts` guard at line 2186 keys off `'completed'`; it does not need to change (Yellow employees keep a real `due_timestamp`). No edit — just confirm during review.

- [ ] **Step 3: Add the Expiring Soon KPI card**

In `render_kpis()`, in the `$kpis` array, insert a new card right after the **Overdue** card (after line 1097, the block ending the Overdue array entry):

```php
      array(
        'label' => __( 'Expiring Soon', 'ghca-acd' ),
        'value' => $data['expiring_soon_employees'] ?? 0,
        'icon'  => 'time',
        'sub'   => __( 'Within warning window', 'ghca-acd' ),
      ),
```

- [ ] **Step 4: Verify syntax**

Run: `"/c/laragon/bin/php/php-8.3.30-Win32-vs16-x64/php.exe" -l gridhouse-admin-compliance-dashboard.php`
Expected: `No syntax errors detected`.

- [ ] **Step 5: Manual test**

On the dashboard, the KPI strip now shows an **Expiring Soon** card. With one expired and one expiring employee configured, Overdue count includes the expired one; Expiring Soon shows 1; Completion Rate counts the expiring employee as compliant.

- [ ] **Step 6: Commit** (if git): `git commit -am "feat: expiring-soon counter + KPI card"`

---

### Task 9: Status filter options + filter logic

**Files:**
- Modify: `gridhouse-admin-compliance-dashboard.php:2868-2875` (`get_status_options`)
- Modify: `gridhouse-admin-compliance-dashboard.php:2753-2775` (status filter block)

**Interfaces:**
- Consumes: `status_slug` `'expired'` / `'expiring_soon'`.
- Produces: dropdown options and matching filter behavior. Overdue option returns the combined lapsed cohort.

- [ ] **Step 1: Add dropdown options**

Replace `get_status_options()` (lines 2868-2875):

```php
  private static function get_status_options(): array {
    return array(
      'completed'     => __( 'Completed', 'ghca-acd' ),
      'expiring_soon' => __( 'Expiring Soon', 'ghca-acd' ),
      'in_progress'   => __( 'In Progress', 'ghca-acd' ),
      'not_started'   => __( 'Not Started', 'ghca-acd' ),
      'overdue'       => __( 'Overdue (incl. expired)', 'ghca-acd' ),
      'expired'       => __( 'Expired', 'ghca-acd' ),
    );
  }
```

- [ ] **Step 2: Update the filter matcher**

In the status filter closure (lines 2753-2775), replace the inner branches:

```php
            $status = $row['status_slug'];
            if ( $filters['status'] === 'completed' ) {
              return in_array( $status, array( 'completed', 'new_hire_completed' ), true );
            }
            if ( $filters['status'] === 'overdue' ) {
              return in_array( $status, array( 'overdue', 'new_hire_overdue' ), true );
            }
            if ( $filters['status'] === 'in_progress' ) {
              return in_array( $status, array( 'in_progress', 'new_hire_in_progress', 'new_hire_not_started' ), true );
            }
            if ( $filters['status'] === 'not_started' ) {
              return $status === 'not_started';
            }
            return $status === $filters['status'];
```

with:

```php
            $status = $row['status_slug'];
            if ( $filters['status'] === 'completed' ) {
              return in_array( $status, array( 'completed', 'new_hire_completed' ), true );
            }
            if ( $filters['status'] === 'expiring_soon' ) {
              return $status === 'expiring_soon';
            }
            if ( $filters['status'] === 'expired' ) {
              return $status === 'expired';
            }
            if ( $filters['status'] === 'overdue' ) {
              // "Everyone lapsed": never-finished overdue + expired re-certs.
              return in_array( $status, array( 'overdue', 'new_hire_overdue', 'expired' ), true );
            }
            if ( $filters['status'] === 'in_progress' ) {
              return in_array( $status, array( 'in_progress', 'new_hire_in_progress', 'new_hire_not_started' ), true );
            }
            if ( $filters['status'] === 'not_started' ) {
              return $status === 'not_started';
            }
            return $status === $filters['status'];
```

- [ ] **Step 3: Verify syntax**

Run: `"/c/laragon/bin/php/php-8.3.30-Win32-vs16-x64/php.exe" -l gridhouse-admin-compliance-dashboard.php`
Expected: `No syntax errors detected`.

- [ ] **Step 4: Manual test**

In the Employees tab: the Status dropdown now lists Expiring Soon and Expired. Selecting **Expiring Soon** returns only yellow employees; **Expired** returns only lapsed-re-cert employees; **Overdue (incl. expired)** returns both expired and never-finished-overdue.

- [ ] **Step 5: Commit** (if git): `git commit -am "feat: expiring/expired status filters"`

---

### Task 10: Traffic-light rendering (table pills, drawer course cards, CSS)

**Files:**
- Modify: `gridhouse-admin-compliance-dashboard.php:323-352` (drawer course-card status)
- Modify: `assets/dashboard.css` (status + course-pill colors)

**Interfaces:**
- Consumes: row `status_slug` (`expired`/`expiring_soon`) and per-course `compliance_state`.
- Produces: visible yellow/red treatment; course-row distinction between "Expired" (finished, lapsed) and "Overdue" (never finished).

> The employee table pills at lines 1340 / 1414 already emit `ghca-acd__status--<?php echo $row['status_slug']; ?>`, so `--expired` / `--expiring_soon` are produced automatically once CSS exists — no PHP change there.

- [ ] **Step 1: Make drawer course cards reflect expiry**

In `ajax_get_employee_drawer()`, replace the course-status derivation (lines 324-338) — currently begins:

```php
          $course_status = ! empty( $course['completed'] ) ? 'completed' : (string) ( $course['status'] ?? 'not_started' );
```

with logic that lets a completed-but-lapsed course show its traffic-light state:

```php
          $cstate = (string) ( $course['compliance_state'] ?? '' );
          if ( 'expired' === $cstate ) {
            $course_status = 'expired';
          } elseif ( 'expiring_soon' === $cstate ) {
            $course_status = 'expiring_soon';
          } else {
            $course_status = ! empty( $course['completed'] ) ? 'completed' : (string) ( $course['status'] ?? 'not_started' );
          }
```

Then extend `$bar_class` (lines 326-330) and `$status_labels` (lines 332-337):

```php
          $bar_class     = 'info';
          if ( $course_status === 'completed' ) { $bar_class = 'success'; }
          elseif ( $course_status === 'expired' )       { $bar_class = 'danger'; }
          elseif ( $course_status === 'expiring_soon' ) { $bar_class = 'warning'; }
          elseif ( $course_status === 'overdue' )       { $bar_class = 'danger'; }
          elseif ( $course_pct > 0 )                    { $bar_class = 'info'; }
          else                                          { $bar_class = 'danger'; }

          $status_labels = array(
            'completed'     => __( 'Compliant', 'ghca-acd' ),
            'expiring_soon' => __( 'Expiring Soon', 'ghca-acd' ),
            'expired'       => __( 'Expired', 'ghca-acd' ),
            'in_progress'   => __( 'In Progress', 'ghca-acd' ),
            'overdue'       => __( 'Overdue', 'ghca-acd' ),
            'not_started'   => __( 'Not Started', 'ghca-acd' ),
          );
```

- [ ] **Step 2: Add CSS**

Append to `assets/dashboard.css`:

```css
/* Rolling expiration traffic-light states */
.ghca-acd__status--expiring_soon { background: #fef3c7; color: #92400e; }
.ghca-acd__status--expired { background: #fee2e2; color: #991b1b; }

.ghca-acd__drawer-course-status--expiring_soon { background: #fef3c7; color: #92400e; }
.ghca-acd__drawer-course-status--expired { background: #fee2e2; color: #991b1b; }
```

> If existing status pills derive their palette from CSS variables rather than literal hex, match the established pattern instead — grep `assets/dashboard.css` for `__status--overdue` and mirror whatever that rule does (variables vs. hex). Keep yellow distinct from red.

- [ ] **Step 3: Verify syntax**

Run: `"/c/laragon/bin/php/php-8.3.30-Win32-vs16-x64/php.exe" -l gridhouse-admin-compliance-dashboard.php`
Expected: `No syntax errors detected`.

- [ ] **Step 4: Manual test**

Open the employee drawer for an employee with a lapsed CPR: the CPR course card reads **Expired** with a red bar; a course inside its warning window reads **Expiring Soon** with an amber bar. The employee's table status pill is red ("Expired") / amber ("Expiring Soon") accordingly, and visually distinct from a never-started "Overdue" employee.

- [ ] **Step 5: Commit** (if git): `git commit -am "feat: traffic-light rendering for courses + employees"`

---

## Phase 1 Acceptance (run after all tasks)

1. `PHP tests/test-course-lifespans.php` → `ALL PASS`.
2. Settings: CPR=730, HIPAA=365, warning=90 save and persist.
3. Employee 100% complete, HIPAA finished 300 days ago → **Expiring Soon** (yellow), in the Expiring Soon KPI + filter, still counted compliant in the rate.
4. Same employee at 366+ days → **Expired** (red); row label "Expired", drops into the Overdue KPI bucket; happens with **no DB write** (advance the completion date via Edit Records to simulate, or compare across a date boundary).
5. A never-started required course past its due date → row label **Overdue**; appears under "Overdue (incl. expired)" but not under "Expired".
6. Completed course with no `completed_ts` stays 🟢 (no false red).
7. Aggregate transient key includes today's `Ymd` and changes across a date boundary.

## Self-Review Notes (author)

- **Spec coverage:** Settings UI (Tasks 3–4) ✓; warning window (Tasks 3–4) ✓; overall status drops to Overdue on red (Task 7, via `expired`→overdue bucket Task 8) ✓; Expiring Soon filter (Task 9) ✓; KPI rolling logic (Task 8) ✓; daily cache (Task 5) ✓; distinct Expired vs Overdue labels (Tasks 7, 9, 10) ✓; no-timestamp safe default (Task 1) ✓.
- **Type consistency:** `compliance_state` strings (`incomplete`/`current`/`expiring_soon`/`expired`) are produced in Task 1/6 and consumed in 7/10; `status_slug` additions (`expired`/`expiring_soon`) produced in Task 7 and consumed in 8/9/10; aggregate key `expiring_soon_employees` produced in Task 8, consumed in Task 8's KPI card.
- **Line numbers** are from the 1.0.0 source and will drift as edits land; each step also quotes the surrounding code so the anchor is unambiguous.
