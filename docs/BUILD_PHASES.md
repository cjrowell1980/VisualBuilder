# VisualBuilder build phases

## Implemented workflow

1. **Windows application foundation** — NativePHP Desktop opens VisualBuilder as a remembered Windows application window. Environment checks report PHP, Composer, Node.js, Git, GitHub CLI, and Docker readiness.
2. **Project setup** — projects capture web/API mode, database driver, Docker inclusion, and an editable absolute output path.
3. **Visual data editor** — models, fields, validation rules, indexes, uniqueness, relationships, ordering, editing, and deletion are supported.
4. **Visual page editor** — custom, dashboard, index, create, edit, and show pages support ordered controls, model binding, control editing, widths, and select options.
5. **Iterations** — a new iteration clones the complete editable model/page/plugin graph. Design changes return an iteration to draft and prevent stale output from being packaged.
6. **Generation** — VisualBuilder emits Eloquent models and relationships, migrations and pivot tables, Livewire/Flux pages, authenticated Sanctum CRUD APIs, routes, database-specific Docker files, GitHub Actions, and a manifest.
7. **Validation and assembly** — schema checks must pass before generation. Assembly creates a clean Laravel application, installs only approved Composer/npm packages, builds assets, migrates the database, and runs generated-project tests.
8. **Debugging** — NativePHP manages a localhost Laravel preview process with explicit launch, open, and stop actions.
9. **Delivery** — review bundles and complete application ZIPs include SHA-256 metadata. Complete ZIPs exclude secrets, Git metadata, and `node_modules`. GitHub delivery authenticates with `gh`, commits changes, protects an existing mismatched remote, and creates new repositories as private.
10. **Automation** — CI validates VisualBuilder, version tags publish its container image, and a manual Windows workflow produces an internal-test installer artifact.

## External release prerequisites

- Add repository secrets `FLUX_USERNAME`, `FLUX_LICENSE_KEY`, and `COMPOSER_AUTH` so GitHub runners can install Flux Pro.
- Configure NativePHP Bifrost (or an equivalent supported secure bundle) before distributing a build publicly; local source-exposed builds are internal-test artifacts only.
- Configure an appropriate Windows code-signing certificate before publishing installers to end users.

These prerequisites require account credentials or certificates and are intentionally not inferred from local files or committed to the repository.
