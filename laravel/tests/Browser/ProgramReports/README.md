# Program report browser tests

These tests use Pest's browser runner, the real program wizard, and the report JSON endpoints. Each test signs in a viewer and creates its own program, courses, CLOs, mappings, and Bloom reference. Database transactions roll the fixtures back after each test. No AI services are needed.

Coverage includes:

- Gap report: input validation, slider/input synchronization, enabled concerns, applying expectations, metric-specific filtering, counts/percentages, details, editing retained ranges, and statistics-only mode.
- Progression report: the Comparison and By cognitive level bar charts, counts/percentages, exact values, PLO scope changes, course/CLO filters, and review badges.
- Cognitive-level tooltips: hover over real bars to check the selected unit, scoped totals, and contributing courses. The table also checks that several matching CLOs do not repeat a course.
- Empty PLOs, no configured mapping levels, empty PLO scopes in both progression views, missing Bloom reference data, and the cognitive-level table fallback when Highcharts is unavailable.

Each report view has a complete workflow test, with separate tests for focused checks and empty states. Temporary default distributions are excluded.

## Running

From `laravel/`, with Composer/npm dependencies installed and a migrated, seeded test database configured in `.env.testing`:

```sh
npx playwright install chromium
npm run build
composer test:program-reports-browser
```

Stop the Vite development server before running so the browser uses the freshly built assets. Test-only middleware serves the pinned Highcharts npm dependency in place of the page's CDN scripts. These tests render real charts and check their data alongside visible table values; they do not stub Highcharts or the report endpoints. Production asset loading is unchanged.

The dedicated command uses the existing test database without resetting it. Like the other browser suites, these tests are excluded from `composer test`. The missing-library test removes Highcharts from the browser before rerendering; it still uses the real report endpoint and table.

The tests use Pest's built-in server and condition-based assertions, with no fixed sleeps. Run `php artisan test --group=program-reports-browser --debug` to inspect a failure in the browser.
