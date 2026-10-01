<?php

namespace Tests\Unit;

use App\Helpers\BloomSpreadsheetReader;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class BloomSpreadsheetReaderTest extends TestCase
{
    private string $path;
    private Spreadsheet $workbook;

    protected function setUp(): void
    {
        $temporary = tempnam(sys_get_temp_dir(), 'bloom-reader-');
        $this->path = $temporary.'.xlsx';
        rename($temporary, $this->path);
        $this->workbook = new Spreadsheet();
        $this->workbook->getActiveSheet()->setTitle('VERBS')->fromArray(['Domain', 'N', 'Proto-verb', 'Verb'], null, 'B4');
    }

    protected function tearDown(): void
    {
        $this->workbook->disconnectWorksheets();
        unlink($this->path);
    }

    private function read(): array
    {
        (new Xlsx($this->workbook))->save($this->path);

        return BloomSpreadsheetReader::read($this->path);
    }

    public function test_reads_only_reference_columns_and_preserves_cross_level_assignments(): void
    {
        $this->workbook->getActiveSheet()->fromArray([
            [' Example ', 1, ' First ', ' Sample ', '=1+1'],
            ['example', 1, 'first', 'sample', 2],
            [null, null, null, null, 100],
            ['Example', 2, 'Second', 'Sample', 2],
            ['Other', 1, 'First', 'Sample', 2],
        ], null, 'B5');
        $this->workbook->createSheet()->setTitle('OUTER RING')->setCellValue('B5', 'Ignored');
        $result = $this->read();
        $this->assertSame(4, $result['source_rows']);
        $this->assertSame(1, $result['duplicate_rows']);
        $this->assertCount(6, $result['entries']);
        $this->assertSame(['domain' => 'Example', 'position' => 1, 'level' => 'First', 'term' => 'Sample'], $result['entries'][0]);
    }

    public function test_includes_level_terms_without_changing_labels_or_duplicating_verbs(): void
    {
        $this->workbook->getActiveSheet()->fromArray([
            ['Example', 1, 'First & Second', 'FIRST'],
            ['Example', 1, 'First & Second', 'Sample'],
            ['Example', 2, 'Third (alternate phrase)', 'Sample'],
            ['Example', 3, 'First', 'Sample'],
        ], null, 'B5');

        $result = $this->read();
        $this->assertSame(4, $result['source_rows']);
        $this->assertSame(0, $result['duplicate_rows']);
        $this->assertCount(8, $result['entries']);
        $terms = [];
        foreach ($result['entries'] as $entry) {
            $terms[$entry['position']][] = [$entry['level'], $entry['term']];
        }
        $this->assertSame([
            1 => [['First & Second', 'FIRST'], ['First & Second', 'Sample'], ['First & Second', 'Second']],
            2 => [['Third (alternate phrase)', 'Sample'], ['Third (alternate phrase)', 'Third'], ['Third (alternate phrase)', 'alternate phrase']],
            3 => [['First', 'Sample'], ['First', 'First']],
        ], $terms);
    }

    #[DataProvider('invalidRows')]
    public function test_rejects_invalid_rows(array $row, string $message): void
    {
        $this->workbook->getActiveSheet()->fromArray(['Example', 1, 'First', 'Sample'], null, 'B5');
        $this->workbook->getActiveSheet()->fromArray($row, null, 'B6');
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Row 6: '.$message);
        $this->read();
    }

    public static function invalidRows(): array
    {
        return [
            [['Example', 1, 'First', null], 'Domain, N, Proto-verb and Verb are all required.'],
            [['Example', 1.5, 'First', 'Sample'], 'N must be a positive integer'],
            [['Example', -1, 'First', 'Sample'], 'N must be a positive integer'],
            [['Example', 1, 'Conflicting', 'Sample'], 'conflicting Proto-verb'],
            [['Example', 1, 'First', str_repeat('x', 256)], 'reference text must not exceed'],
            [['Example', 1, 'First', '=1+1'], 'columns B–E must contain values'],
        ];
    }

    public function test_rejects_wrong_headers(): void
    {
        $this->workbook->getActiveSheet()->setCellValue('E4', 'Something else');
        $this->expectExceptionMessage("Cell E4 must have the header 'Verb'.");
        $this->read();
    }

    public function test_rejects_missing_sheet(): void
    {
        $this->workbook->getActiveSheet()->setTitle('Other');
        $this->expectExceptionMessage('must contain a VERBS sheet');
        $this->read();
    }

    public function test_rejects_empty_reference(): void
    {
        $this->expectExceptionMessage('contains no reference entries');
        $this->read();
    }
}
