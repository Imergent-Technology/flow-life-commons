# Resource content fixtures

The shared example documents for the **Resources document profile v1** ([ADR 0037](../../../../../docs/adr/0037-resources-are-audience-targeted-packs-of-cards.md), decisions 26 and 28).

The server's validation is the security boundary for rich content, and the Console's editor is configured to the same profile. These files are the contract between the two stacks: every file under `valid/` must be accepted by the server and rendered by the Console, and every file under `invalid/` must be refused by the server (never stripped).

- `valid/*.json`: `{ description, document, text, canonical? }`. `document` is what an editor sends. `canonical` is what the server stores when that differs from `document` (editor-default attributes dropped, marks in the fixed order); when absent, the stored form is `document` itself. `text` is the plain text the server derives from it (what a derived summary is made of), whitespace collapsed.
- `invalid/*.json`: `{ description, path, document }`. The server refuses `document` with `invalid_content`, and the message names `path`, the location of the first offence. Nothing is silently dropped.

The backend side is `tests/Unit/Modules/Resources/ContentFixturesTest.php`.

## For WP2 (the Console's editor and renderer)

Today these were written by hand, in the shape Tiptap emits. WP2 must (1) make the Console's test suite consume this same directory, which the Console's dev container cannot see today (it mounts only `apps/guardian-console`), so WP2 decides the sharing mechanism (a read-only compose mount, or a sync step) deliberately; (2) add fixtures produced by the **real** configured editor, and run each through the server's validation, so a document the editor can produce that the server refuses is a defect found in tests rather than in production; and (3) keep both suites green against every file here.
