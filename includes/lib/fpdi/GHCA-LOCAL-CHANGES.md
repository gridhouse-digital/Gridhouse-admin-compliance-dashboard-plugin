# Bundled FPDI — upstream version and local changes

- Upstream: setasign/FPDI **v2.6.9** (tag commit `d97ade6368aa0b51b6316d5dc16a567e2bf60167`, 2026-10-13), contents of `src/`.
- Previously bundled: v2.6.0 (tag commit `a6db878129ec6c7e141316ee71872923e7f1b7ad`).
- Advisory addressed: GHSA-2mgw-7q6p-8grg (endless `/Prev` loop, fixed in 2.6.7) and later recursion/memory fixes.

Everything else in this folder is byte-identical to upstream `src/`. Local changes (re-apply after every upgrade and run
`tests/test-fpdi-flate-budget.php` and `tests/test-fpdi-malicious-pdf.php`):

1. `PdfParser/Filter/DecodedStreamBudget.php` — **added**. Aggregate 32 MiB decoded-byte budget, reset per certificate.
2. `PdfParser/Filter/Flate.php` — 32 MiB per-stream cap on every inflate path, `resetDecodedBudget()`, decoded bytes
   counted against the budget.
3. `PdfParser/Filter/AsciiHex.php`, `Ascii85.php`, `Lzw.php` — decoded output counted against the budget.
4. `PdfReader/Page.php` (`getContentStream`) — upstream 2.6.8 ignores streams that fail to unfilter (blank page). Here a
   failing or over-limit content stream must abort the import, so the unfilter call is not wrapped in a try/catch.
