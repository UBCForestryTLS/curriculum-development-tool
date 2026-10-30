import assert from 'node:assert/strict';
import test from 'node:test';
import { downloadReport } from '../../resources/js/programs/report-download.js';

function mockBrowser(t, response) {
    const link = { click: t.mock.fn(), remove: t.mock.fn() };
    globalThis.document = {
        querySelector: () => ({ content: 'test-csrf-token' }),
        createElement: () => link,
        body: { appendChild: t.mock.fn() },
    };
    t.after(() => delete globalThis.document);
    const fetch = t.mock.method(globalThis, 'fetch', async () => response);
    const createURL = t.mock.method(URL, 'createObjectURL', () => 'blob:test-report');
    const revokeURL = t.mock.method(URL, 'revokeObjectURL', () => {});
    // Control the timeout without making the test wait a minute.
    const timers = [];
    t.mock.method(globalThis, 'setTimeout', (callback, delay) => timers.push({ callback, delay }));
    const clearTimeout = t.mock.method(globalThis, 'clearTimeout', () => {});

    return { link, fetch, createURL, revokeURL, timers, clearTimeout };
}

for (const [format, type] of [
    ['pdf', 'application/pdf'],
    ['xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'],
]) {
    test(`downloads ${format} with the supplied settings and cleans up the link`, async t => {
        const headers = { 'Content-Type': type };
        if (format === 'pdf') headers['Content-Disposition'] = 'attachment; filename="server-report.pdf"';
        const browser = mockBrowser(t, new Response('file contents', { headers }));
        const payload = { format, plo_id: 12, units: 'counts', view: 'cognitive-levels' };
        await downloadReport('/report/export', payload, 'fallback');

        const [endpoint, options] = browser.fetch.mock.calls[0].arguments;
        assert.equal(endpoint, '/report/export');
        assert.equal(options.method, 'POST');
        assert.equal(options.headers['X-CSRF-TOKEN'], 'test-csrf-token');
        assert.deepEqual(JSON.parse(options.body), payload);
        assert.equal(browser.link.download, format === 'pdf' ? 'server-report.pdf' : 'fallback.xlsx');
        assert.equal(browser.link.click.mock.callCount(), 1);
        assert.equal(browser.link.remove.mock.callCount(), 1);
        assert.equal(browser.clearTimeout.mock.callCount(), 1);
        assert.equal(await browser.createURL.mock.calls[0].arguments[0].text(), 'file contents');
        browser.timers[1].callback();
        assert.deepEqual(browser.revokeURL.mock.calls[0].arguments, ['blob:test-report']);
    });
}

test('reports failed requests and rejects HTML instead of downloading it', async t => {
    const cases = [
        { status: 401, message: /session has expired/ },
        { status: 419, message: /session has expired/ },
        { status: 403, message: /permission/ },
        { status: 422, message: /settings are no longer valid/ },
        { status: 500, message: /could not be downloaded/ },
        { status: 200, message: /Refresh the page/ },
    ];
    for (const { status, message } of cases) {
        await t.test(`HTTP ${status}`, async t => {
            const browser = mockBrowser(t, new Response('<html>Not a report</html>', {
                status, headers: { 'Content-Type': 'text/html' },
            }));
            await assert.rejects(downloadReport('/report/export', { format: 'pdf' }, 'report'), message);
            assert.equal(browser.link.click.mock.callCount(), 0);
            assert.equal(browser.createURL.mock.callCount(), 0);
            assert.equal(browser.clearTimeout.mock.callCount(), 1);
        });
    }
});

test('explains connection failures and timeouts', async t => {
    for (const timeout of [false, true]) {
        await t.test(timeout ? 'timeout' : 'connection failure', async t => {
            const browser = mockBrowser(t);
            browser.fetch.mock.mockImplementation(async () => {
                if (timeout) {
                    assert.equal(browser.timers[0].delay, 60000);
                    browser.timers[0].callback();
                    throw new DOMException('Aborted', 'AbortError');
                }
                throw new TypeError('Connection interrupted');
            });
            await assert.rejects(downloadReport('/report/export', { format: 'pdf' }, 'report'),
                timeout ? /took too long/ : /Check your connection/);
            assert.equal(browser.link.click.mock.callCount(), 0);
            assert.equal(browser.clearTimeout.mock.callCount(), 1);
        });
    }
});
