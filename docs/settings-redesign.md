# Settings screen: design boards "WP admin settings" (S10)

Requested: "update the settings page using the new design in the design system" (design-system artifact, page "WP admin settings": `WP-Settings-*.dc.html`). It ships behind the existing redesign switch (`ghca_acd_ui_redesign`). With the switch off, the plugin's admin pages and the previous settings console are unchanged.

## What is built

The work is in `includes/class-redesign-settings.php`, `assets/settings-redesign.css` and `assets/settings-redesign.js`.

- **One shell for every plugin admin page:**
  - pages: Settings, Agency Profile, Permissions, Messaging, Audit Mapping, Jotform, Training Catalog, External Training Review, OLTL Readiness, and the new Training year page;
  - eyebrow "Gridhouse Compliance · GHCA ACD", the title "Settings", and the agency line;
  - the board's grouped section list (Compliance rules, Agency, Messaging, Integrations, System), with the current section marked as on the boards;
  - a "Section" picker instead of the list below 1024 px;
  - design-system cards, fields, buttons and alerts, in the agency's redesign theme;
  - the WordPress chrome around it is untouched.
- **The main Settings page shows one section at a time** (`?section=`): Training rules, Course validity & packets (lifespans and the agency-selected packet courses), Branding, Employment types & roles, and Performance. The other blocks stay in the same form, so one Save still saves the page's settings together, exactly as before.
- **Unsaved-changes bar** (board "State · unsaved changes"): it appears on the first edit with "Unsaved changes in {section}", Discard (reload without saving) and Save changes (submits the existing form).
- **Save result** (board "State · save failed"): "Settings saved", or "Settings not saved" with each reason the settings sanitisers report. For example, an invalid annual cycle is refused and the stored policy is kept.
- **Unchanged:** every form, field name, nonce, capability check, sanitiser and save handler. Each section is visible only to users who can open its page (menu capability; the messaging roles for Messaging). Without JavaScript the page content stays where WordPress prints it, still inside the styled admin.

## Not built yet (differences from the boards)

These need new controls or data, not just presentation. They are listed here, not approximated:

- **Segmented controls and switches:** the boards' segmented "Cycle starts on" and on/off switches are still the existing select and checkboxes, styled.
- **Branding:** the board's proposed new inputs (surface tint, dark-background logo, heading font) and the live preview with contrast checks.
- **Permissions:** the staff × permission matrix; the existing user-ID lists remain.
- **Messaging:** the separate Email, SMS (Twilio) and Message templates sections; they remain one Messaging page.
- **Other boards:** the Audit mapping, Jotform, Catalog, OLTL and Agency profile boards' own layouts. Those pages are inside the shell with design-system styling, but keep their current structure.
- **Plugin logo:** the logo sent in chat arrived as an inline image only, not as a file in this environment, so it is not in the shell yet.

## Tests

`tests/browser-settings-redesign.cjs`: 15 checks, disposable WordPress site, Chromium, 1440 / 834 / 390 px.
- The shell, groups, current section, card styling and no sideways scrolling.
- The Training rules section only, with its Save.
- Each main-page section shows only its own blocks.
- Permissions, Audit mapping, Agency profile and Training year open inside the shell.
- The section picker at 390 px.
- The unsaved-changes bar; a real save ("Settings saved", value stored, same section shown); a rejected save ("Settings not saved" with the reason, stored policy unchanged); Discard.
- With the switch off: the previous console, no shell and no stylesheet.
- Every setting it changes is restored.
