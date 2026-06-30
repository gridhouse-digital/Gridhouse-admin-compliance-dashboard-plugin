<?php
if ( ! defined( 'ABSPATH' ) ) {
  exit;
}

if ( ! class_exists( 'GHCA_UI_Icons', false ) ) {

final class GHCA_UI_Icons {
  /** @return array<string,string> */
  private static function paths(): array {
    return array(
      'status'       => '<path d="M9 12.75 11.25 15 15 9.75M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" fill="none"/>',
      'courses'      => '<path d="M4.5 6.75A2.25 2.25 0 0 1 6.75 4.5h10.5A2.25 2.25 0 0 1 19.5 6.75v10.5A2.25 2.25 0 0 1 17.25 19.5H6.75A2.25 2.25 0 0 1 4.5 17.25V6.75Z" stroke="currentColor" stroke-width="1.75" fill="none"/><path d="M8.25 8.25h7.5M8.25 12h7.5M8.25 15.75H12" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" fill="none"/>',
      'calendar'     => '<path d="M6.75 3v2.25M17.25 3v2.25M3 8.25h18M5.25 5.25h13.5c1 0 1.5.5 1.5 1.5v12c0 1-.5 1.5-1.5 1.5H5.25c-1 0-1.5-.5-1.5-1.5v-12c0-1 .5-1.5 1.5-1.5Z" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" fill="none"/>',
      'due'          => '<path d="M12 6v6l4 2" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" fill="none"/><path d="M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z" stroke="currentColor" stroke-width="1.75" fill="none"/>',
      'certificate'  => '<path d="M9 12.75 11.25 15 15 9.75" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" fill="none"/><path d="M7.5 19.5 9 17.25l1.5 2.25L12 17.25l1.5 2.25L15 17.25l1.5 2.25" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" fill="none"/><path d="M5.25 5.25h13.5v9H5.25v-9Z" stroke="currentColor" stroke-width="1.75" stroke-linejoin="round" fill="none"/>',
      'handbook'     => '<path d="M12 6.75v14.25M5.25 5.25h11.25c1 0 1.5.5 1.5 1.5v12.75c0 .6-.7.95-1.2.6L12 17.25l-4.55 3.1c-.5.35-1.2 0-1.2-.6V6.75c0-1 .5-1.5 1.5-1.5Z" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" fill="none"/>',
      'policies'     => '<path d="M8.25 4.5h7.5l3 3v12.75c0 1-.5 1.5-1.5 1.5h-9c-1 0-1.5-.5-1.5-1.5V6c0-1 .5-1.5 1.5-1.5Z" stroke="currentColor" stroke-width="1.75" stroke-linejoin="round" fill="none"/><path d="M15.75 4.5V9H19.5" stroke="currentColor" stroke-width="1.75" stroke-linejoin="round" fill="none"/><path d="M9 13.5h6M9 16.5h4.5" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" fill="none"/>',
      'support'      => '<path d="M8.25 9.75h7.5M8.25 12.75h4.5" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" fill="none"/><path d="M6.75 4.5h10.5c1 0 1.5.5 1.5 1.5v8.25c0 1-.5 1.5-1.5 1.5H12l-3 3v-3H6.75c-1 0-1.5-.5-1.5-1.5V6c0-1 .5-1.5 1.5-1.5Z" stroke="currentColor" stroke-width="1.75" stroke-linejoin="round" fill="none"/>',
      'download'     => '<path d="M12 4.5v9" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" fill="none"/><path d="M8.25 11.25 12 15l3.75-3.75" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" fill="none"/><path d="M5.25 19.5h13.5" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" fill="none"/>',
      'compliance'   => '<path d="M4.5 16.5v-6l7.5-4.5 7.5 4.5v6" stroke="currentColor" stroke-width="1.75" stroke-linejoin="round" fill="none"/><path d="M9 19.5V12l3-1.8 3 1.8v7.5" stroke="currentColor" stroke-width="1.75" stroke-linejoin="round" fill="none"/>',
      'users'        => '<path d="M15 19.5a4.5 4.5 0 1 0-6 0" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" fill="none"/><path d="M9 10.5a3 3 0 1 0 0-6 3 3 0 0 0 0 6Zm7.5 1.5a2.25 2.25 0 1 0 0-4.5 2.25 2.25 0 0 0 0 4.5Z" stroke="currentColor" stroke-width="1.75" fill="none"/><path d="M18.75 19.5a3.75 3.75 0 0 0-7.5 0" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" fill="none"/>',
      'progress'     => '<path d="M4.5 12a7.5 7.5 0 0 1 13.35-4.72" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" fill="none"/><path d="M19.5 12a7.5 7.5 0 0 1-13.35 4.72" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" fill="none"/><path d="M19.5 6V3h-3" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" fill="none"/>',
      'alert'        => '<path d="M12 9v4.5" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" fill="none"/><path d="M12 17.25h.007" stroke="currentColor" stroke-width="2.25" stroke-linecap="round" fill="none"/><path d="M10.29 4.86 2.82 17.25A1.5 1.5 0 0 0 4.11 19.5h15.78a1.5 1.5 0 0 0 1.29-2.25L13.71 4.86a1.5 1.5 0 0 0-2.58 0Z" stroke="currentColor" stroke-width="1.75" stroke-linejoin="round" fill="none"/>',
      'groups'       => '<path d="M7.5 10.5a2.25 2.25 0 1 0 0-4.5 2.25 2.25 0 0 0 0 4.5Zm9 0a2.25 2.25 0 1 0 0-4.5 2.25 2.25 0 0 0 0 4.5ZM3 18.75a4.5 4.5 0 0 1 9 0M12 18.75a4.5 4.5 0 0 1 9 0" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" fill="none"/>',
      'reports'      => '<path d="M6 19.5V10.5M10.5 19.5V6M15 19.5v-7.5M19.5 19.5V4.5" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" fill="none"/>',
      'dashboard'    => '<path d="M4.5 9.75h6v9.75h-6v-9.75Zm9 0h6v5.25h-6V9.75Zm0 7.5h6v2.25h-6v-2.25Z" stroke="currentColor" stroke-width="1.75" stroke-linejoin="round" fill="none"/>',
      'user-plus'    => '<path d="M15 19.5a4.5 4.5 0 1 0-6 0" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" fill="none"/><path d="M9 10.5a3 3 0 1 0 0-6 3 3 0 0 0 0 6Z" stroke="currentColor" stroke-width="1.75" fill="none"/><path d="M18 7.5v3M16.5 9h3" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" fill="none"/>',
      'file-report'  => '<path d="M8.25 4.5h7.5l3 3v12.75c0 1-.5 1.5-1.5 1.5h-9c-1 0-1.5-.5-1.5-1.5V6c0-1 .5-1.5 1.5-1.5Z" stroke="currentColor" stroke-width="1.75" stroke-linejoin="round" fill="none"/><path d="M15.75 4.5V9H19.5" stroke="currentColor" stroke-width="1.75" stroke-linejoin="round" fill="none"/><path d="M9 13.5h6M9 16.5h4.5" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" fill="none"/>',
      'book'         => '<path d="M4.5 18.75A2.25 2.25 0 0 1 6.75 16.5H19.5" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" fill="none"/><path d="M6.75 3h12.75v18H6.75A2.25 2.25 0 0 1 4.5 18.75V5.25A2.25 2.25 0 0 1 6.75 3Z" stroke="currentColor" stroke-width="1.75" stroke-linejoin="round" fill="none"/>',
      'time'         => '<path d="M12 7.5v4.5l3 1.5" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" fill="none"/><path d="M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z" stroke="currentColor" stroke-width="1.75" fill="none"/>',
      'chart'        => '<path d="M4.5 4.5v15h15" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" fill="none"/><path d="M7.5 13.5l3.75-3.75 2.25 2.25 4.5-5.25" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" fill="none"/>',
      'mail'         => '<path d="M4.5 6h15c.83 0 1.5.67 1.5 1.5v9c0 .83-.67 1.5-1.5 1.5h-15A1.5 1.5 0 0 1 3 16.5v-9C3 6.67 3.67 6 4.5 6Z" stroke="currentColor" stroke-width="1.75" stroke-linejoin="round" fill="none"/><path d="m3.75 7.5 8.25 6 8.25-6" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" fill="none"/>',
      'shield'       => '<path d="M12 3 5.25 5.25V11c0 4.5 3 7.5 6.75 9 3.75-1.5 6.75-4.5 6.75-9V5.25L12 3Z" stroke="currentColor" stroke-width="1.75" stroke-linejoin="round" fill="none"/><path d="m9.75 12 1.5 1.5 3-3.75" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" fill="none"/>',
      'megaphone'    => '<path d="M4.5 9.75v4.5a1.5 1.5 0 0 0 1.5 1.5h1.5l1.2 3.6a1.05 1.05 0 0 0 1 .72h.3a1.05 1.05 0 0 0 1.05-1.05V16.5l6 3.75V3.75L7.5 8.25H6a1.5 1.5 0 0 0-1.5 1.5Z" stroke="currentColor" stroke-width="1.6" stroke-linejoin="round" fill="none"/><path d="M18 9a3 3 0 0 1 0 6" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" fill="none"/>',
      'bell'         => '<path d="M6.75 9.75a5.25 5.25 0 0 1 10.5 0c0 4.2 1.5 5.55 1.5 5.55H5.25s1.5-1.35 1.5-5.55Z" stroke="currentColor" stroke-width="1.6" stroke-linejoin="round" fill="none"/><path d="M10.2 18.75a2.1 2.1 0 0 0 3.6 0" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" fill="none"/>',
      'sync'         => '<path d="M19.5 7.5a7.5 7.5 0 0 0-13-1.5M4.5 16.5a7.5 7.5 0 0 0 13 1.5" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" fill="none"/><path d="M19.5 3.75V7.5h-3.75M4.5 20.25V16.5h3.75" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" fill="none"/>',
      'edit'         => '<path d="M16.5 4.5 19.5 7.5 9 18l-3.75.75L6 15l10.5-10.5Z" stroke="currentColor" stroke-width="1.6" stroke-linejoin="round" fill="none"/><path d="m14.25 6.75 3 3" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" fill="none"/>',
      'trash'        => '<path d="M5.25 6.75h13.5M9.75 6.75V5.25c0-.6.4-1 1-1h2.5c.6 0 1 .4 1 1v1.5M6.75 6.75l.75 12c0 .6.4 1 1 1h7c.6 0 1-.4 1-1l.75-12" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" fill="none"/><path d="M10.5 10.5v6M13.5 10.5v6" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" fill="none"/>',
      'award'        => '<path d="M12 3.25a5.25 5.25 0 1 0 0 10.5 5.25 5.25 0 0 0 0-10.5Z" stroke="currentColor" stroke-width="1.6" fill="none"/><path d="m9 12.9 1.5 7.35L12 18.5l1.5 1.75L15 12.9" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" fill="none"/>',
      'export'       => '<path d="M12 14.25V4.5" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" fill="none"/><path d="M8.5 7.75 12 4.25l3.5 3.5" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" fill="none"/><path d="M5.25 13.5v4.25c0 .55.45 1 1 1h11.5c.55 0 1-.45 1-1V13.5" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" fill="none"/>',
      'line-chart'   => '<path d="M4.5 4.5v13a1.5 1.5 0 0 0 1.5 1.5h13.5" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" fill="none"/><path d="m8 13.5 3-3.25 2.5 2.25 4-4.75" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" fill="none"/><path d="M17.5 7.75h-2.25M17.5 7.75V10" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" fill="none"/>',
    );
  }

  public static function render( string $name, string $class = '' ): string {
    $paths = self::paths();
    if ( ! isset( $paths[ $name ] ) ) {
      return '';
    }

    $class_attr = $class !== '' ? ' class="' . esc_attr( $class ) . '"' : '';

    return '<svg' . $class_attr . ' xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" aria-hidden="true" focusable="false">' . $paths[ $name ] . '</svg>';
  }
}

}
