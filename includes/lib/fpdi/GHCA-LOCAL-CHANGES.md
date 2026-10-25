# Bundled FPDI — upstream version and local changes

- Upstream: setasign/FPDI **v2.6.9** (tag commit `d97ade6368aa0b51b6316d5dc16a567e2bf60167`, 2026-10-13), contents of `src/`.
  `git archive v2.6.9 src | sha256sum` = `b4897824631760c8e719b09f9d1f776bd73e828baab0f6902e0c77e5f69a86ff`.
- Previously bundled: v2.6.0 (tag commit `a6db878129ec6c7e141316ee71872923e7f1b7ad`).
- Advisory addressed: GHSA-2mgw-7q6p-8grg (endless `/Prev` loop, fixed in 2.6.7) and later recursion/memory fixes.

Every file in this folder is byte-identical to upstream `src/` **except** the F-006 decoded-byte limits below (plus this
note). Verify with `diff -r <upstream v2.6.9>/src includes/lib/fpdi`; only these should differ:

| File | Change | sha256 |
|---|---|---|
| `PdfParser/Filter/DecodedStreamBudget.php` | **added** — 32 MiB aggregate decoded-byte budget, reset per certificate | `2b100b3b9a1e4549f51c74e4af5058c4bebd7b276dc366dc5689717137db9aef` |
| `PdfParser/Filter/Flate.php` | 32 MiB per-stream cap on every inflate path, `resetDecodedBudget()`, output counted | `80892c2a6c174c07e05ac62954e42db10a5e8bc80b5d5653c994210e872d4966` |
| `PdfParser/Filter/AsciiHex.php` | decoded output counted against the budget | `2d406e924d76b060a02d3bd58195780e5d09bcffdd2099dc21482097a0fd6795` |
| `PdfParser/Filter/Ascii85.php` | decoded output counted against the budget | `482776a6ba825d40b18b4017334bd888e3d27068940019985567a042bf9c851a` |
| `PdfParser/Filter/Lzw.php` | decoded output counted against the budget | `389468dd6e1287c62af9b4308bb39612b7cb58b366d3c04620f6af75467f4515` |

`PdfReader/Page.php` is **unmodified** upstream 2.6.9 (sha256 `1be07232069cfbbfc7f64341ad400c19e1dbabb7f1ee4b2623b6847345cb862b`).

After any upgrade re-apply the table above and run `tests/test-fpdi-flate-budget.php` and `tests/test-fpdi-malicious-pdf.php`.

## Not handled by FPDI — enforced by the plugin instead
FPDI does not stop on undecodable page content, at any version we have bundled: `FpdiTrait::importPage` copies a single
content stream without decoding it, and turns any `FpdiException` while handling the page (including an over-limit
stream) into an empty content stream. Since 2.6.8, `Page::getContentStream()` also skips streams that cannot be
unfiltered. The plugin rule is that corrupt evidence stops the packet, so `GHCA_Audit_PDF::certificate_content_is_decodable()`
(`includes/class-audit-pdf.php`) decodes every page content stream through FPDI's public reader before
`append_certificate()` may import a certificate, and refuses it on any decode or limit failure. Do not weaken it when
upgrading; `tests/test-fpdi-evidence-integrity.php` (default suite) and `tests/test-fpdi-corrupt-stream-abort.php`
(needs TCPDF) cover it.
