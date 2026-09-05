# VisualBuilder native migration

## Objective

Replace VisualBuilder's Laravel/NativePHP desktop host with a Windows-native C# application while retaining Laravel, Livewire, Flux, Tailwind and Blade as generated targets.

The existing application remains operational until the native application passes every release-critical parity gate.

## Decisions

- Windows x64 is the first supported platform.
- The native client will use C#, WinUI 3 and Windows App SDK.
- VisualBuilder projects use a portable, versioned JSON contract with stable UUIDs.
- SQLite stores local application state, indexes and recoverable caches; it is not the canonical project interchange format.
- Page functions are typed directed graphs. Blocks never emit code without graph validation.
- The Laravel generator remains deterministic and manifest-driven.
- Licensing is separate from local identity and never prevents users from reading or exporting their projects.
- The current PHP generator may be used as a temporary compatibility bridge only.

## Function graph safety rules

1. Every graph has one entry event and at least one terminal path.
2. Ports and values are typed; incompatible edges are rejected before generation.
3. Database writes require an explicit validation path.
4. Destructive operations require an explicit authorization node.
5. Browser-only and server-only nodes cannot be connected across execution boundaries without a generated call boundary.
6. Cycles are rejected unless they pass through a bounded loop node.
7. Custom-code nodes are marked as manually maintained and excluded from automatic rewriting.
8. Secrets are references to protected settings and never literal graph values.

## Feature parity gates

| Area | Legacy capability | Native release gate |
|---|---|---|
| Projects | Web/API mode, database, Docker and output path | Create, import, save and reopen without data loss |
| Schema | Models, fields, indexes and relationships | Contract validation and equivalent editing |
| Pages | Page types, controls, binding, ordering and layout | Equivalent designer plus undo and crash recovery |
| Functions | Not implemented | Typed blocks for events, validation, CRUD, conditions, notification and navigation |
| Iterations | Complete graph cloning and status invalidation | Stable ancestry and independent cloned graphs |
| Generation | Laravel, Livewire/Flux, API, Docker and CI | Golden-output semantic parity |
| Assembly | Dependency install, build, migrate and test | Cancellable jobs with streamed output |
| Updates | Hash conflicts, backup and file rollback | Previewable update plan with the same safety boundary |
| Debugging | Managed Laravel preview | Start, open, stop and process-tree cleanup |
| Delivery | ZIP and GitHub publishing | Equivalent packaging and authenticated delivery |
| Inspection | Planned | Read-only route and middleware explorer |
| Desktop | NativePHP installer | Signed native installer and verified in-place update |
| Licensing | None | Trial, entitlement, offline grace and device management |

## Phase 0 exit criteria

- Both JSON schemas parse successfully and are versioned.
- A representative project can be exported to the project contract without losing iteration data.
- Golden generator fixtures are deterministic after normalising timestamps and environment-specific paths.
- The public/private source and commercial licensing model is recorded.
- The native solution builds on a clean Windows development environment.

## Phase 1 project workspace

- New-project choices for application type, starter kit, database and Docker.
- Versioned `.vbproject` JSON persistence with atomic replacement.
- Open, save, recent-project history and 30-second dirty-document autosave.
- Contract-compatible enum and property naming.
- Native Windows CI build and test workflow.

Page, model and function editing will use the same workspace dirty-state and persistence pipeline as those editors are introduced.

## Phase 2 model designer

- Add, edit and remove models with table names, timestamps and soft deletes.
- Add, edit and remove typed fields with nullable, index, unique, default and Laravel validation settings.
- Add and remove model relationships, including foreign-key and pivot-table metadata.
- Enforce contract-compatible PascalCase and snake_case names, uniqueness and reserved-field rules before persistence.
- Prevent model deletion while another relationship still targets it.
- Persist every model change through the shared dirty-document and autosave pipeline.

## Repository transition

The native solution lives under `code/native/`. The previous Laravel/NativePHP implementation is preserved intact under `code/legacy-laravel/` until the native build has a working importer and passes the agreed parity gates. Local installers and generated artifacts belong in the ignored `builds/` directory.
