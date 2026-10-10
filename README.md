# Laravel Livewire Chat

A real-time chat application built with Laravel, Livewire, and Laravel Reverb. It includes user registration, role-based pages, contacts, message history, and client-side end-to-end encryption key handling.

## Tech stack

- PHP 8.3+ and Laravel 13
- Livewire 4, Flux UI 2, and Livewire Blaze
- Laravel Fortify for authentication
- SQLite by default (MySQL can be configured)
- Laravel Reverb and Laravel Echo for WebSockets
- Vite Plus, Bun, and Tailwind CSS 4
- Pest for tests, Pint for formatting, and Larastan for static analysis

## Requirements

- Git
- PHP 8.3 or newer, with the PHP extensions required by Laravel and PDO SQLite enabled for the default database
- Composer 2
- Bun (the repository commits `bun.lock` and the dev process uses Bun)

If you use MySQL instead of SQLite, install and enable the matching PDO driver as well.

## Get started

1. Clone the repository and enter the project directory:

   ```sh
   git clone <repository-url>
   cd <repository-directory>
   ```

2. Install PHP dependencies:

   ```sh
   composer install
   ```

3. Install JavaScript dependencies from the committed Bun lockfile:

   ```sh
   bun install --frozen-lockfile
   ```

4. Create your local environment file and application key:

   ```sh
   cp .env.example .env
   php artisan key:generate
   ```

   On PowerShell, use `Copy-Item .env.example .env` instead of `cp` if needed. Keep `.env` local; it is ignored by Git.

5. Configure the local database. SQLite is the project default (`DB_CONNECTION=sqlite`). Create its database file before running migrations:

   ```sh
   php -r "file_exists('database/database.sqlite') || touch('database/database.sqlite');"
   php artisan migrate
   ```

   To use MySQL, create a database and update `.env` with `DB_CONNECTION=mysql`, `DB_HOST`, `DB_PORT`, `DB_DATABASE`, `DB_USERNAME`, and `DB_PASSWORD` before migrating.

6. Configure local Reverb credentials in `.env`. These values are for local development only; use unique secrets in shared or production environments:

   ```dotenv
   BROADCAST_CONNECTION=reverb
   REVERB_APP_ID=local-app
   REVERB_APP_KEY=local-key
   REVERB_APP_SECRET=local-secret
   REVERB_HOST=127.0.0.1
   REVERB_PORT=8080
   REVERB_SCHEME=http
   VITE_REVERB_APP_KEY="${REVERB_APP_KEY}"
   VITE_REVERB_HOST="${REVERB_HOST}"
   VITE_REVERB_PORT="${REVERB_PORT}"
   VITE_REVERB_SCHEME="${REVERB_SCHEME}"
   ```

   Keep the `REVERB_*` and `VITE_REVERB_*` settings aligned. If you change Reverb's host, port, or scheme, update both sides. Clear cached configuration after changing environment values if you had cached it: `php artisan config:clear`.

7. Start the application and its development services:

   ```sh
   composer run dev
   ```

   This runs Laravel, the database queue listener, Vite Plus, and Reverb together. Open the URL shown by Laravel (normally `http://localhost:8000`). Keep this terminal running while developing.

8. Register an account at `/register`, then sign in at `/login`. The chat and dashboard routes require an authenticated, verified account. The default mailer writes messages to the application log (`storage/logs/laravel.log`), which is useful for inspecting local password reset links.

### Optional development seed account

To create the seed user, run:

```sh
php artisan db:seed
```

This creates `test@example.com` with password `password`. Use it only in a local development database. The seeder does not create an admin account.

## Useful commands

| Task | Command |
| --- | --- |
| Start all development processes | `composer run dev` |
| Run database migrations | `php artisan migrate` |
| Seed the local database | `php artisan db:seed` |
| Build frontend assets | `bun run build` |
| Run tests | `php artisan test` |
| Format PHP | `composer run lint` |
| Check PHP formatting | `composer run lint:check` |
| Run static analysis | `composer run types:check` |

## Stack configuration notes

- **Composer and Bun:** Composer manages PHP dependencies; Bun manages frontend dependencies. The repository has `bun.lock`, and `php artisan dev` runs `bun run dev`. The Composer `setup` script currently calls `npm install`, so follow the Bun commands above to use the committed frontend lockfile consistently.
- **Laravel and Fortify:** Fortify supplies the authentication backend and the project provides Livewire views. Extend Fortify actions and features through `config/fortify.php` and `app/Providers/FortifyServiceProvider.php`; do not add a second authentication package for the same flows.
- **Livewire, Flux, and Blaze:** The UI is Blade/Livewire. Use Flux components for consistent interactive UI and keep authorization and validation in server-side Livewire actions. Blaze is installed for component rendering optimization; enable it selectively and measure the affected pages.
- **Tailwind CSS 4 and Vite:** Tailwind is integrated through `@tailwindcss/vite` in `vite.config.js`. Use `bun run dev` for hot reload (already included in `composer run dev`) and `bun run build` for a production asset build.
- **Reverb and Echo:** Echo is initialized in `resources/js/echo.js` and uses the Vite-exposed `VITE_REVERB_*` values. `composer run dev` starts Reverb locally. For production, configure TLS and the public WebSocket endpoint, keep app credentials private, and restrict allowed origins instead of leaving the current wildcard origin.
- **Database-backed services:** Sessions, cache, and queues use the database by default. Run migrations before starting the app. The dev command starts a queue listener; in production, configure an appropriate persistent queue worker and choose cache/session stores to suit the deployment.
- **SQLite versus MySQL:** SQLite is convenient for local development. Use MySQL by setting the connection variables in `.env`; make sure the database exists and its user has access before migrating.

## Potential README improvements

As the project grows, consider adding a deployment guide, screenshots or a short feature walkthrough, a development troubleshooting section, and documented procedures for granting admin access and provisioning Reverb credentials. The README can also be updated to use Bun in the Composer `setup` script so the automated setup path matches the checked-in lockfile.
