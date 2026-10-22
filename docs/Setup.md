# 🛠️ Project Setup Guidelines

## Requirements

- PHP 8.4.27 or a newer 8.4.x patch, including the security fixes (the current Composer lockfile does not support PHP 8.5)
- Composer
- Laravel 11
- Node.js `^20.19.0 || >=22.12.0` and npm, as required by the locked Vite dependencies
- PostgreSQL
- Python (for running the microservices under `python/services/`)

To update an existing Homebrew PHP 8.4 installation, run `brew update` and `brew upgrade php@8.4`. Check that `php -v` shows 8.4.27 or a newer 8.4.x patch, then restart any running PHP servers or workers. Deployed environments must be updated separately.

### Clone this repository

```
git clone https://github.com/forestryTLS/curriculum-development-tool.git
cd curriculum-development-tool
```

## Laravel Setup

### Install Dependencies

```
cd laravel
composer install
npm install
```

### Environment Setup

```
cp .env.example .env
```

Use `.env.example` as a template for `.env`, and modify values as required.

```
DB_CONNECTION=pgsql
DB_HOST=127.0.0.1
DB_PORT=5432
DB_DATABASE=laravel
DB_USERNAME=root
DB_PASSWORD=
```

The instructions below assume these defaults. If you change any of them in `.env`, substitute your values into the commands accordingly.

### Application Key

```
php artisan key:generate
```

### Database Setup

#### Initialize a PostgreSQL cluster (one-time, only if not already done)

Check whether you already have a PostgreSQL cluster set up and running:

```
psql -U postgres -l
```

If you see a table of databases (`postgres`, `template0`, `template1`), the cluster is initialized and running. Skip ahead to **Create the `root` role**.

If you see `psql: could not connect to server`, the cluster either has not been created or is not running. Pick a directory to hold the cluster data (substitute your own path), then:

```
initdb -D <path-to-data-directory> --username=postgres
pg_ctl -D <path-to-data-directory> start
```

To stop the server later, use `pg_ctl -D <path-to-data-directory> stop`.

If you see `psql: command not found`, PostgreSQL is not installed; install it first.

#### Create the `root` role

Create the root role:

```
psql -U postgres -h 127.0.0.1 -c "CREATE ROLE root LOGIN PASSWORD '';"
```

The above command has a blank password. If setting a password, also set it in `.env`, under `DB_PASSWORD=`

#### Create the application database

```
psql -U postgres -h 127.0.0.1 -c "CREATE DATABASE laravel OWNER root;"
```


### Storage Setup

```
php artisan storage:link
```

### Optional: PHP upload limits (required for Course Material File uploads)

The Course Material File extraction feature lets users upload PDF course materials. PHP's default limits are small, so set these larger values in your active `php.ini`:

```ini
upload_max_filesize = 50M
post_max_size = 60M
memory_limit = 256M
```

Your active `php.ini` is the file listed as **"Loaded Configuration File"** when you run

```
php --ini
```

### Run migrations and seeders

```
php artisan migrate --seed
```

### Front-end Assets

The project uses Vite for SCSS compilation and JavaScript bundling. Two workflows are available:

**Development (with hot reload)**:

```
npm run dev
```

Vite starts a dev server on `http://localhost:5173/` and serves assets with hot module replacement.

**Production build**:

```
npm run build
```

Compiles a static bundle into `public/build/`. Use this for deployments or when you want to run the app without the dev server.

Both commands trigger a `prebuild`/`predev` hook that copies jQuery into `public/vendor/`. `npm install` also performs this copy automatically.

### Run the Application

Start the queue worker in one terminal:

``` 
php artisan queue:work
```

Note: If you need OCR, add this flag
``` 
php artisan queue:work --memory=2048
```

Run the application in another:

```
php artisan serve
```

Access the application at [http://localhost:8000](http://localhost:8000). If you want hot-reloading front-end assets while developing, also start the Vite dev server in a third terminal with `npm run dev`.

### Running Tests

The project uses a separate `.env.testing` file and a dedicated test database so that running tests do not interfere with the development data.

The core settings in `.env.testing.example` are:

```
APP_NAME=Laravel
APP_ENV=testing
APP_KEY=
APP_DEBUG=true
APP_URL=http://127.0.0.1:8000

DB_CONNECTION=pgsql
DB_HOST=127.0.0.1
DB_PORT=5432
DB_DATABASE=laravel_test
DB_USERNAME=root
DB_PASSWORD=
```

The instructions below assume these defaults.

**First-time setup**:

1. Create the test database:

   ```
   psql -U postgres -h 127.0.0.1 -c "CREATE DATABASE laravel_test OWNER root;"
   ```

2. Copy the test environment template:

   ```
   cp .env.testing.example .env.testing
   ```

3. Generate a separate app key for the testing environment:

   ```
   php artisan key:generate --env=testing
   ```

**Running the test suite**:

This runs unit and feature tests and recreates the database configured in `.env.testing` with `migrate:fresh --seed`. Keep that database separate from development data. Both browser suites are excluded.

```
composer test
```

For AI Suggestions browser tests, see the [setup and running instructions](../laravel/tests/Browser/AiSuggestionsE2E/README.md).

For text-extraction browser tests, complete the [service setup](./FastAPITextExtractionService.md), run the service on `http://127.0.0.1:8003`, and set the browser viewport values in `.env.testing`. From `laravel/`, run:

```sh
npx playwright install
npm run build
composer test:text-extraction-e2e
```

The browser test command also recreates the test database. The test runner starts its Laravel server automatically; keep port 8010 available. These tests use synchronous queue processing.

## Python Setup

From the project root, run `cd python` to enter the `python` directory.
If you are currently in the `laravel` directory, first run `cd ..` and then `cd python`.

### Navigate to a service
From the project `python` directory:

``` 
cd services/<service_name>
```

### Activate virtual environment

#### Unix

``` 
python3 -m venv env
source env/bin/activate
```

#### Windows

``` 
python -m venv env
.\env\Scripts\activate
```

### Install Dependencies

``` 
pip install -r requirements.txt
```
Also check the service's documentation under `docs/` for additional specific setup steps.

### Run the Service

``` 
python3 -m app.main 
```
