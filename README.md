# Visual Builder

Visual Builder turns a versioned application schema into reviewable Laravel code. The first vertical slice supports authenticated projects, build iterations, models, typed fields, and deterministic model/migration generation.

## Stack

- Laravel 13, PHP 8.4, Fortify
- Livewire 4 single-file components (the modern successor to Volt's class-based SFC format)
- Flux UI with a documented Flux Pro upgrade path
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

## Flux Pro

Flux Pro is a private Composer package and is intentionally not installed without a licence. Authenticate locally with the credentials from your Flux account, ensure `auth.json` remains ignored, then install it:

```bash
composer config http-basic.composer.fluxui.dev YOUR_EMAIL YOUR_LICENSE_KEY
composer require livewire/flux-pro
```

CI/deployment should create Composer authentication from repository secrets named `FLUX_USERNAME` and `FLUX_LICENSE_KEY`; never commit either value.

## Delivery model

1. A project owns ordered, immutable-in-intent build iterations.
2. Each iteration describes models, fields, plugins, UI, authorization, and deployment configuration.
3. Generation writes to `storage/app/private/generated/{project}/iteration-{n}` for review.
4. Plugin requirements are recorded separately and require approval before installation.
5. GitHub Actions verifies the application; version tags publish an immutable GHCR image.

GitHub repository creation and production deployment are deliberately separate from code generation because they require an explicit owner, repository visibility, target host, and secrets.
