# VisualBuilder

VisualBuilder is a Windows-first visual IDE that turns a versioned application design into a tested Laravel project. The new native Windows application is the active implementation; the earlier Laravel/NativePHP application remains available during feature-parity migration.

## Repository layout

```text
VisualBuilder/
├── code/
│   ├── native/           # Active .NET 10 and WinUI 3 application
│   └── legacy-laravel/   # Existing Laravel and NativePHP application
├── builds/               # Local installers and generated build artifacts (ignored)
├── contracts/            # Portable .vbproject and generator JSON schemas
├── docs/                 # Architecture, migration and build documentation
└── .github/              # CI, Windows build and release workflows
```

## Native Windows application

The active application uses .NET 10 and WinUI 3 while retaining Laravel, Livewire, Flux, Blade and Tailwind as generated application targets.

```powershell
dotnet build code\native\VisualBuilder.slnx
dotnet test code\native\tests\VisualBuilder.Domain.Tests\VisualBuilder.Domain.Tests.csproj
dotnet run --project code\native\src\VisualBuilder.App\VisualBuilder.App.csproj
```

The project supports creating, saving and reopening versioned `.vbproject` files. See [Native migration](docs/NATIVE_MIGRATION.md) for the architectural decisions and parity plan.

## Legacy Laravel application

The working Laravel/NativePHP version is preserved under `code/legacy-laravel` until the native application reaches feature parity.

```powershell
cd code\legacy-laravel
composer install
Copy-Item .env.example .env
php artisan key:generate
php artisan migrate
npm install
npm run build
composer dev
```

For SQLite, set `DB_CONNECTION=sqlite`, clear the other database settings, create `database/database.sqlite`, and run the migrations.

### Docker

From `code/legacy-laravel`:

```powershell
docker compose up --build -d
docker compose exec app php artisan migrate --force
```

The application is available at `http://localhost:8080`. PostgreSQL data is stored in the `postgres-data` volume.

### NativePHP desktop build

From `code/legacy-laravel`, run `composer native:dev` for development or `php artisan native:build win` for an unsigned internal-test installer. Public releases require source-bundle protection and Windows code signing.

### Flux Pro

Authenticate from `code/legacy-laravel` using credentials from your Flux account:

```powershell
composer config http-basic.composer.fluxui.dev YOUR_EMAIL YOUR_LICENSE_KEY
```

`auth.json` remains ignored. GitHub Actions uses `FLUX_USERNAME`, `FLUX_LICENSE_KEY`, and `COMPOSER_AUTH` repository secrets; never commit those values.

## Delivery model

1. Create a web, API, or combined project and choose its database, Docker option, and output folder.
2. Design models, typed fields, relationships, pages, controls and block-based functions.
3. Clone designs into versioned iterations.
4. Validate before generation; later edits invalidate the generated bundle.
5. Generate Laravel, Livewire/Flux, API, Docker and CI code.
6. Assemble, install approved dependencies, build, migrate and test.
7. Launch the debugger and managed preview.
8. Package to ZIP, Git, GitHub or a Windows installer.

See [Build phases](docs/BUILD_PHASES.md) for verification and release boundaries.
