<?php
// Add/Edit Employee modal layout contract (GRI-95 follow-up). Source/CSS assertions only;
// the rendered layout is checked separately in a browser (see the PR description).
$root = dirname( __DIR__ );
$php  = file_get_contents( $root . '/includes/class-manage-users-ui.php' );
$css  = file_get_contents( $root . '/assets/dashboard.css' );
$main = file_get_contents( $root . '/gridhouse-admin-compliance-dashboard.php' );
$js   = file_get_contents( $root . '/assets/dashboard.js' );

$fails = 0;
function mu_check( bool $ok, string $m ): void { global $fails; echo ( $ok ? 'PASS' : 'FAIL' ) . ": $m\n"; $ok || $fails++; }
function mu_rule( string $css, string $selector ): string {
	return preg_match( '/(?:^|\})\s*' . preg_quote( $selector, '/' ) . '\s*\{([^}]*)\}/m', $css, $m ) ? $m[1] : '';
}

mu_check( false === strpos( $php, '<style' ), 'the modal no longer ships an inline <style> block' );
mu_check( 0 === preg_match( '/id="ghca-acd-role"[^>]*style=/', $php ), 'the Role select has no inline style' );
mu_check( false !== strpos( $main, "'admin_compliance_manage_users'" ) && false !== strpos( $main, "plugin_dir_url( __FILE__ ) . 'assets/dashboard.css'" ), 'dashboard.css is enqueued on any page that renders [admin_compliance_manage_users]' );

foreach ( array( 'id="ghca-acd-user-modal"', 'id="ghca-acd-user-modal-title"', 'class="ghca-acd-modal__close"', 'class="ghca-acd-modal__body"', 'id="ghca-acd-user-form"', 'id="ghca-acd-user-id"', 'id="ghca-acd-first-name"', 'id="ghca-acd-last-name"', 'id="ghca-acd-email"', 'id="ghca-acd-phone"', 'id="ghca-acd-employment-type"', 'id="ghca-acd-role"', 'class="ghca-acd-checkbox-list"', 'ghca-acd-modal__cancel', 'id="ghca-acd-user-submit"', 'ghca-acd-btn-text' ) as $hook ) {
	mu_check( false !== strpos( $php, $hook ), "JS/markup hook kept: $hook" );
}
mu_check( 1 === preg_match( '/class="ghca-acd-form-group ghca-acd-form-group--full">\s*<label><\?php esc_html_e\( \'LearnDash Groups\'/', $php ), 'the LearnDash Groups field uses the --full modifier (no :has())' );
$block = substr( $css, (int) strpos( $css, 'Manage Users: Add / Edit Employee modal' ) );
mu_check( '' !== $block && false === strpos( $block, ':has(' ), 'the modal rules do not rely on :has()' );
$order = array( 'ghca-acd-first-name', 'ghca-acd-last-name', 'ghca-acd-email', 'ghca-acd-phone', 'ghca-acd-employment-type', 'ghca-acd-role' );
$pos   = array_map( static function ( $id ) use ( $php ) { return strpos( $php, 'id="' . $id . '"' ); }, $order );
$sorted = $pos; sort( $sorted );
mu_check( $pos === $sorted && ! in_array( false, $pos, true ), 'field order gives the pairs First/Last, Email/Phone, Employment Type/Role in a 2-column grid' );

$overlay = mu_rule( $css, '.ghca-acd-modal' );
mu_check( false !== strpos( $overlay, 'padding: 16px' ) && false !== strpos( $overlay, 'box-sizing: border-box' ) && false !== strpos( $overlay, 'position: fixed' ), 'overlay: fixed, padding 16px, border-box' );
$content = mu_rule( $css, '.ghca-acd-modal__content' );
mu_check( false !== strpos( $content, 'max-width: 760px' ) && false !== strpos( $content, 'display: flex' ) && false !== strpos( $content, 'flex-direction: column' ) && false !== strpos( $content, 'overflow: hidden' ), 'content: 760px max, flex column, overflow hidden' );
mu_check( false !== strpos( $content, 'max-height: calc(100vh - 32px)' ) && strpos( $content, 'max-height: calc(100dvh - 32px)' ) > strpos( $content, 'max-height: calc(100vh - 32px)' ), 'content: max-height calc(100vh - 32px) with a later 100dvh override' );
mu_check( false !== strpos( mu_rule( $css, '.ghca-acd-modal__header' ), 'flex: 0 0 auto' ), 'header does not shrink' );
$body = mu_rule( $css, '.ghca-acd-modal__body' );
mu_check( false !== strpos( $body, 'flex: 1 1 auto' ) && false !== strpos( $body, 'min-height: 0' ) && false !== strpos( $body, 'overflow-y: auto' ) && false !== strpos( $body, 'overscroll-behavior: contain' ), 'body: flex 1 1 auto, min-height 0, scrolls, overscroll contained' );
$form = mu_rule( $css, '#ghca-acd-user-form' );
mu_check( false !== strpos( $form, 'display: grid' ) && false !== strpos( $form, 'grid-template-columns: 1fr 1fr' ) && false !== strpos( $form, 'gap: 16px' ), 'form: 2-column grid, 16px gap' );
mu_check( false !== strpos( mu_rule( $css, '.ghca-acd-form-group--full' ), 'grid-column: 1 / -1' ), 'the --full modifier spans both columns' );
$actions = mu_rule( $css, '.ghca-acd-form-actions' );
mu_check( false !== strpos( $actions, 'grid-column: 1 / -1' ) && false !== strpos( $actions, 'position: sticky' ) && false !== strpos( $actions, 'bottom: 0' ) && false !== strpos( $actions, 'background: #fff' ) && false !== strpos( $actions, 'border-top' ) && false !== strpos( $actions, 'calc(var(--ghca-modal-pad) * -1)' ), 'actions: full width, sticky at the bottom, white, top border, negative margins matching the body padding' );
mu_check( 1 === preg_match( '/@media \(max-width: 640px\) \{\s*\.ghca-acd-modal \{ --ghca-modal-pad: 16px; padding: 8px; \}\s*\.ghca-acd-modal__content \{ max-height: calc\(100vh - 16px\); max-height: calc\(100dvh - 16px\); \}\s*#ghca-acd-user-form \{ grid-template-columns: 1fr; \}/', $css ), 'below 640px: one column, overlay padding 8px, max-height calc(100dvh - 16px)' );
mu_check( false !== strpos( $js, "document.body.style.overflow = 'hidden'" ) && false !== strpos( $js, "modal.style.display = 'flex'" ), 'JS still opens the modal as a flex overlay and locks page scroll (unchanged)' );

// Role dropdown must never default to a privileged role (e.g. Administrator listed first).
mu_check( 1 === preg_match( '/<select id="ghca-acd-role" name="role" required>\s*<option value="" selected disabled>/', $php ), 'the Role dropdown starts on a required "Select role" placeholder (empty value, selected, disabled)' );
mu_check( 1 === preg_match_all( '/<option[^>]*\bselected\b/', substr( $php, (int) strpos( $php, 'id="ghca-acd-role"' ), 900 ) ), 'no real role option is pre-selected' );
mu_check( false !== strpos( $js, "form.elements['role'].value = btn.getAttribute('data-role') || 'subscriber'" ) && false !== strpos( $js, 'form.reset();' ), 'Edit still sets the saved role; closing the modal resets the form back to the placeholder' );
$ajax = file_get_contents( $root . '/includes/class-ajax-handlers.php' );
mu_check( false !== strpos( $ajax, 'if ( ! array_key_exists( $role, $manageable_roles ) )' ), 'the server still rejects an empty or unknown role' );

echo 0 === $fails ? "\nALL PASS\n" : "\n$fails FAILED\n";
exit( 0 === $fails ? 0 : 1 );
