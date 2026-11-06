<?php

namespace Tests\Browser\ProgramReports;

use Pest\Browser\Api\PendingAwaitablePage;
use PhpOffice\PhpSpreadsheet\IOFactory;

final class ReportDownloads
{
    public static function watch(PendingAwaitablePage $page): void
    {
        // Keep the real request and download click. Copy the received file so we can inspect it.
        $page->script(<<<'JS'
            () => {
                window.reportDownloads = [];
                const createObjectURL = URL.createObjectURL;
                const click = HTMLAnchorElement.prototype.click;
                URL.createObjectURL = function(blob) {
                    const url = createObjectURL.call(this, blob);
                    const file = { url, type: blob.type, filename: null, data: null };
                    window.reportDownloads.push(file);
                    const reader = new FileReader();
                    reader.onload = () => file.data = reader.result.split(',')[1];
                    reader.readAsDataURL(blob);
                    return url;
                };
                HTMLAnchorElement.prototype.click = function() {
                    const file = window.reportDownloads.find(file => file.url === this.href);
                    if (file) file.filename = this.download;
                    return click.call(this);
                };
            }
            JS);
    }

    public static function download(PendingAwaitablePage $page, string $report, string $format): array
    {
        $index = $page->script('window.reportDownloads.length');
        $attribute = $report === 'gap-coverage' ? 'gap' : 'progression';
        $page->click('#'.$report.'-download')->click('[data-'.$attribute.'-export="'.$format.'"]')
            ->assertSeeIn('#'.$report.'-export-status', 'Download started.')
            ->assertMissing('#'.$report.'-export-error')
            ->assertEnabled('#'.$report.'-download')
            ->assertScript("Boolean(window.reportDownloads[{$index}]?.data && window.reportDownloads[{$index}]?.filename)");
        $file = $page->script("window.reportDownloads[{$index}]");
        $file['content'] = base64_decode($file['data'], true);
        expect($file['content'])->not->toBeFalse();
        expect($file['filename'])->toEndWith('.'.$format);
        expect($file['type'])->toBe($format === 'pdf'
            ? 'application/pdf' : 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');

        return $file;
    }

    public static function spreadsheetRows(string $content): array
    {
        $path = tempnam(sys_get_temp_dir(), 'report-download-');
        $workbook = null;
        try {
            file_put_contents($path, $content);
            $workbook = IOFactory::load($path);
            $sheets = [];
            foreach ($workbook->getWorksheetIterator() as $sheet) {
                $sheets[$sheet->getTitle()] = $sheet->toArray(null, true, false);
            }

            return $sheets;
        } finally {
            $workbook?->disconnectWorksheets();
            unlink($path);
        }
    }
}
