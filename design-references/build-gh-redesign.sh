#!/bin/sh
# Rebuilds assets/gh-redesign.css from the board CSS, the canvas app layer, and the plugin head (fonts, reset) and tail (adapter).
# Both the board CSS and the adapter get the interactive-component !important guard.
set -e
cd "$(dirname "$0")/.."
{
  cat design-references/gh-redesign.head.css
  python3 design-references/scope-board-css.py design-references/board-source.css
  python3 design-references/scope-board-css.py --app design-references/app-source.css
  python3 design-references/scope-board-css.py --guard-only design-references/gh-redesign.tail.css
} > assets/gh-redesign.css
