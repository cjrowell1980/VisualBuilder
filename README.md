# Visual Builder

VisualBuilder is a Windows-first visual IDE that turns a versioned application design into a tested Laravel project. It combines schema and page design, Livewire/Flux code generation, managed preview processes, Docker environments, ZIP packaging, and GitHub delivery in one desktop workflow.

## Stack

- Laravel 13, PHP 8.4, Fortify
- Livewire 4 single-file components (the modern successor to Volt's class-based SFC format)
- Flux UI and Flux Pro 2.18
- Blade, Alpine, Tailwind CSS 4
- PostgreSQL 17 and Docker
- PHPUnit, Larastan, Pint, GitHub Actions, GHCR publishing

## Local setup

```bash
composer install
cp .env.example .env
php artisan key:generate
php artisan migrate
npm install
npm run build
composer dev
```

For a quick SQLite setup, set `DB_CONNECTION=sqlite`, clear the other database settings, create `database/database.sqlite`, then migrate.

## Docker

Set `APP_KEY` in your environment, then run:

```bash
docker compose up --build -d
docker compose exec app php artisan migrate --force
```

The application is available at `http://localhost:8080`. PostgreSQL data is stored in the named `postgres-data` volume.

## Windows desktop

VisualBuilder uses NativePHP Desktop 2. Run the Windows development application with:

```bash
composer native:dev
```

Create an unsigned internal-test installer with `php artisan native:build win`. Public releases must use source-bundle protection and Windows code signing before distribution.

The manually triggered **Build Windows application** GitHub Actions workflow produces a 14-day internal-test artifact. It intentionally does not publish a public release: configure NativePHP Bifrost source protection and Windows code signing before enabling public release automation.

## Flux Pro

Flux Pro is installed as a private Composer package. Authenticate locally with the credentials from your Flux account; `auth.json` is ignored and used as a Docker BuildKit secret:

```bash
composer config http-basic.composer.fluxui.dev YOUR_EMAIL YOUR_LICENSE_KEY
```

Add GitHub Actions secrets named `FLUX_USERNAME` and `FLUX_LICENSE_KEY` for CI. Also add a `COMPOSER_AUTH` secret containing the Composer authentication JSON for container publishing. Never commit any of these values.

## Delivery model

1. Create a web, API, or combined project and choose its database, Docker option, and output folder.
2. Design models, typed fields, relationships, pages, controls, select options, and layout widths.
3. Clone the current design into a new iteration before making a new version.
4. Validate the current design. Any subsequent edit invalidates the validation and generated bundle.
5. Generate models, migrations, pivot tables, Livewire pages, authenticated API controllers, routes, Docker files, and CI workflows.
6. Assemble a clean Laravel project, install explicitly approved Composer/npm packages, compile assets, migrate, and run its tests.
7. Launch and stop the assembled application through NativePHP's managed preview process.
8. Package either the review bundle or complete application, or commit and push it to a private-by-default GitHub repository.

See [Build phases](docs/BUILD_PHASES.md) for the current verification and release boundaries.

## Native Windows rewrite

The next-generation Windows application is being developed side-by-side in `native/`. It uses .NET 10 and WinUI 3 while retaining Laravel, Livewire, Flux and Tailwind as generated application targets. The current Laravel/NativePHP application remains available during feature-parity migration.

Build and test the native foundation with:

```powershell
dotnet build native\VisualBuilder.slnx
dotnet test native\tests\VisualBuilder.Domain.Tests\VisualBuilder.Domain.Tests.csproj
```

The versioned project and generator contracts are in `contracts/`. See [Native migration](docs/NATIVE_MIGRATION.md) for architectural decisions, migration boundaries and the parity plan.
