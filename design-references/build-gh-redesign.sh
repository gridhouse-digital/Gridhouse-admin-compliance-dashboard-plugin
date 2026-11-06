#!/bin/sh
# Rebuilds assets/gh-redesign.css from the board CSS plus the plugin head (fonts, reset) and tail (adapter).
set -e
cd "$(dirname "$0")/.."
{
  cat design-references/gh-redesign.head.css
  python3 design-references/scope-board-css.py design-references/board-source.css
  cat design-references/gh-redesign.tail.css
} > assets/gh-redesign.css
