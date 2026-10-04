# Resource content fixtures

The shared example documents for the **Resources document profile v1** ([ADR 0037](../../../../../docs/adr/0037-resources-are-audience-targeted-packs-of-cards.md), decisions 26 and 28).

The server's validation is the security boundary for rich content, and the Console's editor is configured to the same profile. These files are the contract between the two stacks: every file under `valid/` must be accepted by the server and rendered by the Console, and every file under `invalid/` must be refused by the server (never stripped).

- `valid/*.json`: `{ description, document, text, canonical? }`. `document` is what an editor sends. `canonical` is what the server stores when that differs from `document` (editor-default attributes dropped, marks in the fixed order); when absent, the stored form is `document` itself. `text` is the plain text the server derives from it (what a derived summary is made of), whitespace collapsed.
- `invalid/*.json`: `{ description, path, document }`. The server refuses `document` with `invalid_content`, and the message names `path`, the location of the first offence. Nothing is silently dropped.

The backend side is `tests/Unit/Modules/Resources/ContentFixturesTest.php`.

## How the Console uses them (WP2)

This directory is the **one** home of the corpus. The Console does not keep a copy: its Vitest suite reads these files by the relative path `../platform/tests/Fixtures/resource-content` from `apps/guardian-console` (`src/test/resourceFixtures.ts`), and the Console's dev container, which mounts only `apps/guardian-console`, gets this directory as a read-only compose mount at the same relative path. A directory that is not visible fails the suite loudly; it never passes by finding nothing. Nothing in a production build reads them.

Against every file here the Console proves (`src/richtext/`): the TypeScript profile (`contract.ts`) produces exactly the stored form from each `valid/` document and refuses each `invalid/` one at `path`; the real configured editor round-trips each valid document; the renderer draws each valid one with all its text and withholds each invalid one; and the editor component opens each valid one without calling it a change. Add a file here and every one of those checks, in both stacks, picks it up.

**`editor-*.json`** are not hand-written: the real editor produced them (`src/richtext/editorFixtures.test.ts` holds the recipes), and their `document` is the editor's raw output with its fixed defaults spelled out. That test fails if the editor ever stops producing exactly what is committed (a Tiptap upgrade, a changed extension), so a drift is a red test, not a surprise. After a deliberate change, regenerate from the repository with `UPDATE_RESOURCE_FIXTURES=1 npx vitest run src/richtext/editorFixtures.test.ts` (in `apps/guardian-console`; the container's mount is read-only), commit the files, and the server's `ContentFixturesTest` then checks the new output.
