<?php
if ( ! defined( 'ABSPATH' ) ) {
  exit;
}

final class GHCA_ACD_Table_UI {
  /** @return array<int,int> */
  public static function get_per_page_options(): array {
    return array(
      10 => 10,
      15 => 15,
      25 => 25,
      50 => 50,
    );
  }

  public static function normalize_per_page( int $value ): int {
    $allowed = self::get_per_page_options();
    return isset( $allowed[ $value ] ) ? $value : 15;
  }

  public static function normalize_page( int $page ): int {
    return max( 1, $page );
  }

  /** @param array<int,mixed> $rows @return array{rows:array<int,mixed>,total:int,page:int,per_page:int,total_pages:int,from:int,to:int} */
  public static function paginate( array $rows, int $page, int $per_page ): array {
    $total       = count( $rows );
    $per_page    = self::normalize_per_page( $per_page );
    $total_pages = max( 1, (int) ceil( $total / $per_page ) );
    $page        = min( self::normalize_page( $page ), $total_pages );
    $offset      = ( $page - 1 ) * $per_page;
    $slice       = array_slice( $rows, $offset, $per_page );

    return array(
      'rows'        => $slice,
      'total'       => $total,
      'page'        => $page,
      'per_page'    => $per_page,
      'total_pages' => $total_pages,
      'from'        => $total > 0 ? $offset + 1 : 0,
      'to'          => $total > 0 ? $offset + count( $slice ) : 0,
    );
  }

  public static function matches_search( string $needle, array $fields ): bool {
    $needle = strtolower( trim( $needle ) );
    if ( $needle === '' ) {
      return true;
    }

    foreach ( $fields as $field ) {
      if ( str_contains( strtolower( (string) $field ), $needle ) ) {
        return true;
      }
    }

    return false;
  }

  /**
   * @param array{total:int,page:int,per_page:int,total_pages:int,from:int,to:int} $meta
   * @param array<string,string>                                                 $page_field Map: prev/next use hidden page input name
   */
  public static function render_pagination( array $meta, string $page_field ): string {
    if ( $meta['total'] <= 0 ) {
      return '';
    }

    $current_page = (int) $meta['page'];
    $total_pages = (int) $meta['total_pages'];

    ob_start();
    ?>
    <div class="ghca-acd__pagination" data-ghca-pagination>
      <p class="ghca-acd__pagination-summary">
        <?php
        printf(
          /* translators: 1: first row number, 2: last row number, 3: total rows */
          esc_html__( 'Showing %1$d–%2$d of %3$d', 'ghca-acd' ),
          (int) $meta['from'],
          (int) $meta['to'],
          (int) $meta['total']
        );
        ?>
      </p>
      <div class="ghca-acd__pagination-controls">
        <button
          type="button"
          class="ghca-acd__page-btn"
          data-ghca-page="<?php echo esc_attr( (string) max( 1, $current_page - 1 ) ); ?>"
          data-ghca-page-field="<?php echo esc_attr( $page_field ); ?>"
          <?php disabled( $current_page <= 1 ); ?>
        >
          <?php esc_html_e( 'Previous', 'ghca-acd' ); ?>
        </button>
        <span class="ghca-acd__pagination-status">
          <?php
          /* translators: 1: current page, 2: total pages */
          echo esc_html( sprintf( __( 'Page %1$d of %2$d', 'ghca-acd' ), $current_page, $total_pages ) );
          ?>
        </span>
        <button
          type="button"
          class="ghca-acd__page-btn"
          data-ghca-page="<?php echo esc_attr( (string) min( $total_pages, $current_page + 1 ) ); ?>"
          data-ghca-page-field="<?php echo esc_attr( $page_field ); ?>"
          <?php disabled( $current_page >= $total_pages ); ?>
        >
          <?php esc_html_e( 'Next', 'ghca-acd' ); ?>
        </button>
      </div>
    </div>
    <?php
    return (string) ob_get_clean();
  }

  public static function render_per_page_select( string $name, int $current, string $label ): string {
    $current = self::normalize_per_page( $current );
    ob_start();
    ?>
    <label class="ghca-acd__filter-field">
      <span><?php echo esc_html( $label ); ?></span>
      <select name="<?php echo esc_attr( $name ); ?>">
        <?php foreach ( self::get_per_page_options() as $value ) : ?>
          <option value="<?php echo esc_attr( (string) $value ); ?>" <?php selected( $current, $value ); ?>><?php echo esc_html( (string) $value ); ?></option>
        <?php endforeach; ?>
      </select>
    </label>
    <?php
    return (string) ob_get_clean();
  }

  public static function render_search_field( string $name, string $value, string $label, string $placeholder = '' ): string {
    ob_start();
    ?>
    <label class="ghca-acd__filter-field ghca-acd__filter-field--search">
      <span><?php echo esc_html( $label ); ?></span>
      <input
        type="search"
        name="<?php echo esc_attr( $name ); ?>"
        value="<?php echo esc_attr( $value ); ?>"
        placeholder="<?php echo esc_attr( $placeholder ); ?>"
        autocomplete="off"
      />
    </label>
    <?php
    return (string) ob_get_clean();
  }

  public static function render_group_select( string $name, string $current, array $options, string $label ): string {
    ob_start();
    ?>
    <label class="ghca-acd__filter-field">
      <span><?php echo esc_html( $label ); ?></span>
      <select name="<?php echo esc_attr( $name ); ?>">
        <option value=""><?php esc_html_e( 'All groups', 'ghca-acd' ); ?></option>
        <?php foreach ( $options as $gid => $option_label ) : ?>
          <option value="<?php echo esc_attr( (string) $gid ); ?>" <?php selected( $current, (string) $gid ); ?>><?php echo esc_html( $option_label ); ?></option>
        <?php endforeach; ?>
      </select>
    </label>
    <?php
    return (string) ob_get_clean();
  }

  public static function render_filter_actions( bool $show_reset = true ): string {
    ob_start();
    ?>
    <div class="ghca-acd__filter-actions">
      <button type="submit" class="ghca-acd__btn ghca-acd__btn--secondary"><?php esc_html_e( 'Apply', 'ghca-acd' ); ?></button>
      <?php if ( $show_reset ) : ?>
        <button type="button" class="ghca-acd__btn ghca-acd__btn--ghost" data-ghca-filter-reset><?php esc_html_e( 'Reset', 'ghca-acd' ); ?></button>
      <?php endif; ?>
    </div>
    <?php
    return (string) ob_get_clean();
  }

  public static function render_sortable_header( string $column_key, string $label, string $current_orderby, string $current_order ): string {
    $is_active  = $current_orderby === $column_key;
    $next_order = $is_active && $current_order === 'asc' ? 'desc' : 'asc';
    
    $class = 'ghca-acd__sort-header';
    if ( $is_active ) {
      $class .= ' ghca-acd__sort-header--active ghca-acd__sort-header--' . $current_order;
    }

    ob_start();
    ?>
    <th class="<?php echo esc_attr( $class ); ?>" data-ghca-sort="<?php echo esc_attr( $column_key ); ?>" data-ghca-sort-order="<?php echo esc_attr( $next_order ); ?>" role="button" tabindex="0">
      <div class="ghca-acd__sort-inner">
        <span><?php echo esc_html( $label ); ?></span>
        <span class="ghca-acd__sort-icon" aria-hidden="true">
          <?php if ( $is_active && $current_order === 'asc' ) : ?>
            <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M12 19V5M5 12l7-7 7 7"/></svg>
          <?php elseif ( $is_active && $current_order === 'desc' ) : ?>
            <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M12 5v14M19 12l-7 7-7-7"/></svg>
          <?php else : ?>
            <svg width="12" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M7 15l5 5 5-5M7 9l5-5 5 5"/></svg>
          <?php endif; ?>
        </span>
      </div>
    </th>
    <?php
    return (string) ob_get_clean();
  }
}
