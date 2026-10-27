## AI Suggestions Feature End-to-End Tests

These end-to-end browser tests do not run automatically with `composer test`.

Set up the dedicated test database and `.env.testing` as described in [Project Setup](../../../../docs/Setup.md#running-tests). The test command recreates and seeds this database. The test runner starts its Laravel server automatically; keep port 8010 available for callbacks.

Then complete the following set up specific to these tests:

### Playwright Browsers
Install the browser binaries with `npx playwright install` from the `laravel/` directory, then build the frontend assets with `npm run build`. These are real browsers used for automation. You can remove the binaries with `npx playwright uninstall` and reinstall them later if necessary.

<small>
Optional: If you'd like these to install onto a specific drive, follow <a href="https://playwright.dev/docs/browsers#managing-browser-binaries">these configuration steps</a>. However, as of writing this, only the default set up has been tested.
</small>

### Docker
Ensure you have docker on your device, with enough space to create a container. The LocalStack container takes around 1GB of system storage. The actual container creation (and deletion) are handled by the script.

Ensure docker is running on your system. You should be able to run `docker ps` without errors.

### Environment Variables
Like for the other tests, make sure to have `.env.testing` set up. You can just copy `.env.testing.example`. Make sure to set `PLAYWRIGHT_VIEWPORT_WIDTH` and `PLAYWRIGHT_VIEWPORT_HEIGHT` to something **smaller** than your screen resolution.

### Running the Test server (and LocalStack)

From the repository root, `cd` into `python/services/lo_mapping_service`, activate the virtual environment, and install `requirements.txt` as described in [Project Setup](../../../../docs/Setup.md#activate-virtual-environment).

Create a `.env` in that service directory with local test settings:

```dotenv
AWS_REGION=ca-central-1
LO_MAPPING_DYNAMODB_REQUESTS_TABLE=lo-mapping-e2e
BATCH_TRANSFORM_INPUT_S3_BUCKET=lo-mapping-e2e
OUTPUT_S3_URI=s3://lo-mapping-e2e/output
```

`app.test` supplies dummy AWS credentials, the LocalStack endpoint, and the Laravel callback URL on port 8010. It resets the configured table in LocalStack when it starts.

Then run `python -m app.test`. 

If the LocalStack container isn't already up and running, this command automatically sets it up and starts it.

### Running the tests 

In another terminal, run `composer test:ai-suggestions-e2e` from `laravel/`.

**Debugging**

To see the browser as the tests run, use `composer test:ai-suggestions-e2e:visual`.
To see the browser and debug-step, use `composer test:ai-suggestions-e2e:debug`.

### Troubleshooting
- If the failure message says 'interrupted by ...', try closing unnecessary processes on your device.
- If the failure message says it could not find a specific element, this could either be a genuine issue to debug, or it could be a viewport issue (see **Environment Variables** section above).
- Use `$page->screenshot()` before and after problematic points to see what the page showed.
- Use `$page->debug()` to open a debug stepping browser window at that point. This also skips other tests, so you can get here fast - great for debugging one specific point!

### Notes

- Since LocalStack doesn't currently support SageMaker, we've 'mocked' quite a few tasks in the tests by sending requests to test endpoints. One non-SageMaker function is also mocked - `putPendingRecord()` is used instead of submitting a request via the UI. Submitting via the UI would need us to first deploy the lambda to LocalStack. Calling this lambda would put the pending record in DynamoDB, then call SageMaker and fail with LocalStack, returning an error. So `putPendingRecord()` was chosen. In the future, if we make some sort of mock SageMaker server (this seems viable), then we could deploy the lambdas to LocalStack and make the tests more 'end-to-end' accurate with UI interactions throughout.

- To understand the tests, look at `MainWorkflowTest.php` first. It has a lot of comments to explain what's going on.

- In `MainWorkflowTest.php`, the course, program, and initial mapping setup are done by simulating user clicks to be as realistic as possible. In the other tests, however, these are done programmatically to save time and focus on the actual case being tested (unless setup is relevant to the test case, like in `AddingCLOPLOafterSubmittingTest.php`).

- The tests have `wait()` commands scattered throughout. The wait times were set through trial-and-error. They slow down the tests quite a bit, but make sure that elements needed are loaded, and just overall seem to reduce flakiness.

- When writing tests, start with `visit_v(url)` instead of `visit(url)` to take advantage of the viewport set in `.env.testing`. This prevents some 'element not found' errors. Then you can use `navigate()` to keep the same viewport on that window, or use `visit_v()` again to open a new window.

- If a button press fails often, try to `hover()` over the button first and then press.

- The test service overrides AWS credentials, the endpoint, and the callback URL in `app.test`; the table, bucket, and region settings come from the service's `.env` above.
