<?php
declare(strict_types=1);

namespace Tests;

use app\controller\InvestmentPayback;
use app\middleware\Auth;
use app\model\User;
use app\service\HotelScopeService;
use app\service\InvestmentPaybackCalculator;
use app\service\InvestmentPaybackImportService;
use app\service\InvestmentPaybackService;
use app\service\LocalImageOcrService;
use app\service\PermissionService;
use InvalidArgumentException;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Shared\Date;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use think\App;
use think\facade\Config;
use think\facade\Db;
use think\Request;
use think\Route;
use think\route\Dispatch;
use think\route\dispatch\Controller;
use ZipArchive;

/** Every write uses a disposable synthetic SQLite database, never real project data. */
final class InvestmentPaybackImportTest extends TestCase
{
    private static array $originalConfig;
    private static string $databasePath;
    private array $temporaryFiles = [];
    private Request $originalRequest;

    public static function setUpBeforeClass(): void
    {
        (new App(dirname(__DIR__)))->initialize();
        self::$originalConfig = Config::get('database');
        self::$databasePath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'payback_import_test_' . bin2hex(random_bytes(8)) . '.sqlite';
        Config::set(['default' => 'payback_import_test', 'connections' => ['payback_import_test' => [
            'type' => 'sqlite', 'database' => self::$databasePath, 'prefix' => '', 'fields_strict' => true,
        ]]], 'database');
        Db::connect(null, true);
    }

    public static function tearDownAfterClass(): void
    {
        Db::connect('payback_import_test')->close();
        Config::set(self::$originalConfig, 'database');
        Db::connect(null, true);
        @unlink(self::$databasePath);
    }

    protected function setUp(): void
    {
        $this->originalRequest = request();
        foreach (['investment_payback_projects', 'investment_payback_entries', 'investment_payback_events', 'hotels', 'system_config'] as $table) {
            Db::execute('DROP TABLE IF EXISTS ' . $table);
        }
        Db::execute('CREATE TABLE hotels (id INTEGER PRIMARY KEY, tenant_id INTEGER NOT NULL, status INTEGER NOT NULL)');
        Db::execute('INSERT INTO hotels VALUES (80,10,1),(81,10,1),(90,20,1)');
        Db::execute('CREATE TABLE system_config (id INTEGER PRIMARY KEY AUTOINCREMENT, config_key TEXT NOT NULL UNIQUE, '
            . 'config_value TEXT NULL, description TEXT NOT NULL DEFAULT "", create_time INTEGER NULL, update_time INTEGER NULL)');
        Db::execute('CREATE TABLE investment_payback_projects ('
            . 'id INTEGER PRIMARY KEY AUTOINCREMENT, tenant_id INTEGER NOT NULL, hotel_id INTEGER NULL, '
            . 'project_name TEXT NOT NULL, investor_name TEXT NOT NULL, basis TEXT NOT NULL, currency TEXT NOT NULL, status TEXT NOT NULL, '
            . 'first_invested_on TEXT NULL, expected_monthly_amount TEXT NULL, expected_source TEXT NOT NULL, forecast_as_of TEXT NOT NULL, '
            . 'history_complete_through TEXT NULL, opening_as_of TEXT NULL, opening_invested TEXT NULL, opening_recovered TEXT NULL, opening_source TEXT NOT NULL, '
            . 'notes TEXT NOT NULL, client_request_id TEXT NOT NULL, input_digest TEXT NOT NULL, version INTEGER NOT NULL, '
            . 'created_by INTEGER NOT NULL, updated_by INTEGER NOT NULL, created_at TEXT NOT NULL, updated_at TEXT NOT NULL, archived_at TEXT NULL, archive_reason TEXT NOT NULL DEFAULT "", '
            . 'UNIQUE (tenant_id,created_by,client_request_id))');
        Db::execute('CREATE TABLE investment_payback_entries ('
            . 'id INTEGER PRIMARY KEY AUTOINCREMENT, tenant_id INTEGER NOT NULL, project_id INTEGER NOT NULL, kind TEXT NOT NULL, amount TEXT NOT NULL, '
            . 'business_date TEXT NOT NULL, precision TEXT NOT NULL, is_planned INTEGER NOT NULL, confirmed_zero INTEGER NOT NULL, category TEXT NOT NULL, source TEXT NOT NULL, notes TEXT NOT NULL, '
            . 'original_entry_id INTEGER NULL, client_request_id TEXT NOT NULL, input_digest TEXT NOT NULL, version INTEGER NOT NULL, created_by INTEGER NOT NULL, updated_by INTEGER NOT NULL, '
            . 'created_at TEXT NOT NULL, updated_at TEXT NOT NULL, voided_at TEXT NULL, voided_by INTEGER NULL, void_reason TEXT NOT NULL DEFAULT "", '
            . 'UNIQUE (tenant_id,project_id,created_by,client_request_id))');
        Db::execute('CREATE TABLE investment_payback_events ('
            . 'id INTEGER PRIMARY KEY AUTOINCREMENT, tenant_id INTEGER NOT NULL, project_id INTEGER NOT NULL, entry_id INTEGER NULL, actor_id INTEGER NOT NULL, '
            . 'event_type TEXT NOT NULL, project_version INTEGER NOT NULL, payload_json TEXT NOT NULL, created_at TEXT NOT NULL)');
    }

    protected function tearDown(): void
    {
        app()->instance('request', $this->originalRequest);
        foreach ($this->temporaryFiles as $path) {
            @unlink($path);
        }
    }

    public function testCsvPreviewPreservesTextExactMoneyAndLiteralFormulaWithoutWrites(): void
    {
        $bytes = "\xEF\xBB\xBF项目,主体,金额,日期\r\n酒店甲,测试人,1000.01,2026-09-30\r\n酒店乙,测试人,=1+2,09-30\r\n";
        $preview = $this->importer()->preview(['file_name' => '测试.csv', 'file_base64' => base64_encode($bytes)]);
        self::assertSame(hash('sha256', $bytes), $preview['sha256']);
        self::assertSame('spreadsheet', $preview['source_method']);
        self::assertSame(['酒店甲', '测试人', '1000.01', '2026-09-30'], $preview['sheets'][0]['rows'][1]);
        self::assertSame('=1+2', $preview['sheets'][0]['rows'][2][2]);
        self::assertSame('09-30', $preview['sheets'][0]['rows'][2][3]);
        self::assertCount(1, $preview['warnings']);
        self::assertSame('unverified', $preview['data_status']);
        self::assertSame(0, Db::name('investment_payback_projects')->count());
        self::assertSame(0, Db::name('system_config')->count());
    }

    public function testCsvSupportsQuotedNewlinesTabSeparatedAndGb18030(): void
    {
        $preview = $this->importer()->preview(['file_name' => '测试.csv', 'file_base64' => base64_encode(mb_convert_encoding("项目\t说明\n酒店甲\t\"第一行\n第二行\"", 'GB18030', 'UTF-8'))]);
        self::assertSame(['酒店甲', "第一行\n第二行"], $preview['sheets'][0]['rows'][1]);
    }

    public function testXlsxAndXlsPreviewsKeepMultipleSheetsDatesAndFormulaUncalculated(): void
    {
        foreach (['Xlsx' => 'xlsx', 'Xls' => 'xls'] as $writerType => $extension) {
            $book = new Spreadsheet();
            $book->getActiveSheet()->setTitle('项目');
            $book->getActiveSheet()->fromArray([['项目', '金额', '截至日', '说明'], ['酒店甲', '1000.01', null, null]], null, 'A1');
            $book->getActiveSheet()->setCellValue('C2', Date::PHPToExcel(new \DateTimeImmutable('2026-09-30')));
            $book->getActiveSheet()->getStyle('C2')->getNumberFormat()->setFormatCode('yyyy-mm-dd');
            $book->getActiveSheet()->setCellValueExplicit('D2', '=SUM(1,2)', DataType::TYPE_FORMULA);
            $book->createSheet()->setTitle('明细')->fromArray([['收回', '10.01']], null, 'A1');
            $path = $this->temporaryFile();
            $writer = IOFactory::createWriter($book, $writerType);
            $writer->setPreCalculateFormulas(false)->save($path);
            $preview = $this->importer()->preview(['file_name' => '测试.' . $extension, 'file_base64' => base64_encode(file_get_contents($path))]);
            self::assertCount(2, $preview['sheets']);
            self::assertSame('2026-09-30', $preview['sheets'][0]['rows'][1][2]);
            self::assertSame('=SUM(1,2)', $preview['sheets'][0]['rows'][1][3]);
            self::assertSame(['收回', '10.01'], $preview['sheets'][1]['rows'][0]);
            self::assertTrue((bool)array_filter($preview['warnings'], static fn(string $warning): bool => str_contains($warning, '公式仅显示原文，未计算')));
            $book->disconnectWorksheets();
        }
    }

    public function testXlsxAndXlsMonthlyDateFormatsNeverPromoteSerialsToDayPrecision(): void
    {
        foreach (['Xlsx' => 'xlsx', 'Xls' => 'xls'] as $writerType => $extension) {
            $book = new Spreadsheet();
            $formats = ['yyyy-mm', 'yyyy年m月', 'yyyy"年"m"月"', 'yyyy"days"mm', '[Red]yyyy-mm', 'yyyy-mm-dd'];
            foreach ($formats as $index => $format) {
                $address = [$index + 1, 1];
                $book->getActiveSheet()->setCellValue($address, Date::PHPToExcel(new \DateTimeImmutable('2026-09-30')));
                $book->getActiveSheet()->getStyle($address)->getNumberFormat()->setFormatCode($format);
            }
            $path = $this->temporaryFile();
            IOFactory::createWriter($book, $writerType)->save($path);
            $preview = $this->importer()->preview(['file_name' => '月份.' . $extension, 'file_base64' => base64_encode(file_get_contents($path))]);
            self::assertSame(['2026-09', '2026-09', '2026-09', '2026-09', '2026-09', '2026-09-30'], $preview['sheets'][0]['rows'][0], $extension);
            $book->disconnectWorksheets();
        }
    }

    public function testXlsxAndXlsIncompleteDateDisplaysNeverGainHiddenDateParts(): void
    {
        foreach (['Xlsx' => 'xlsx', 'Xls' => 'xls'] as $writerType => $extension) {
            $book = new Spreadsheet();
            $formats = ['yyyy', 'mm-dd', 'hh:mm', 'yyyy hh:mm', 'yyyy hh:mm AM/PM', 'yyyy-mm-ddd', 'yyyy-mm hh:mm', 'yyyy-mm-dd hh:mm'];
            $displayed = [];
            foreach ($formats as $index => $format) {
                $cell = $book->getActiveSheet()->getCell([$index + 1, 1]);
                $cell->setValue(Date::PHPToExcel(new \DateTimeImmutable('2026-09-30 12:34:00')));
                $cell->getStyle()->getNumberFormat()->setFormatCode($format);
                $displayed[] = $cell->getFormattedValue();
            }
            $path = $this->temporaryFile();
            IOFactory::createWriter($book, $writerType)->save($path);
            $preview = $this->importer()->preview(['file_name' => '不完整日期.' . $extension, 'file_base64' => base64_encode(file_get_contents($path))]);
            self::assertSame([...array_slice($displayed, 0, 6), '2026-09', '2026-09-30'], $preview['sheets'][0]['rows'][0], $extension);
            self::assertTrue((bool)array_filter($preview['warnings'], static fn(string $warning): bool => str_contains($warning, '不补全日期')));
            self::assertSame(0, Db::name('investment_payback_projects')->count());
            $book->disconnectWorksheets();
        }
    }

    public function testImportMarkerFitsDeclaredKeyLengthAndDatetimeDefaults(): void
    {
        Db::execute('DROP TABLE system_config');
        Db::execute('CREATE TABLE system_config (id INTEGER PRIMARY KEY AUTOINCREMENT, '
            . 'config_key TEXT NOT NULL UNIQUE CHECK (length(config_key) <= 50), config_value TEXT NULL, description TEXT NOT NULL, '
            . 'create_time TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP CHECK (create_time LIKE "____-__-__ __:__:__"), '
            . 'update_time TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP CHECK (update_time LIKE "____-__-__ __:__:__"))');
        $input = $this->batch('projects', [$this->projectRow()]);
        $result = $this->importer(2147483647, 2147483647)->confirm($input);
        $marker = Db::name('system_config')->where('id', 1)->find();
        self::assertLessThanOrEqual(50, strlen($marker['config_key']));
        self::assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $marker['create_time']);
        self::assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $marker['update_time']);
        self::assertSame($result['project_ids'], $this->importer(2147483647, 2147483647)->confirm($input)['project_ids']);
        self::assertSame(1, Db::name('investment_payback_projects')->count());
    }

    public function testImportMarkerUsesDatetimeDefaultsInsteadOfEpochNumbers(): void
    {
        Db::execute('DROP TABLE system_config');
        Db::execute('CREATE TABLE system_config (id INTEGER PRIMARY KEY AUTOINCREMENT, config_key TEXT NOT NULL UNIQUE, '
            . 'config_value TEXT NULL, description TEXT NOT NULL, '
            . 'create_time TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP CHECK (create_time LIKE "____-__-__ __:__:__"), '
            . 'update_time TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP CHECK (update_time LIKE "____-__-__ __:__:__"))');
        self::assertSame(1, $this->importer()->confirm($this->batch('projects', [$this->projectRow()]))['imported_count']);
        $marker = Db::name('system_config')->where('id', 1)->find();
        self::assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $marker['create_time']);
        self::assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $marker['update_time']);
    }

    public function testEntryDuplicateCheckUsesMysqlCurrentReadWithinProjectLock(): void
    {
        $id = $this->emptyProject();
        $manager = app(\think\DbManager::class);
        $eventsProperty = new \ReflectionProperty($manager, 'event');
        $originalEvents = $eventsProperty->getValue($manager);
        $eventsProperty->setValue($manager, clone $originalEvents);
        $duplicateSql = [];
        try {
            Db::event('before_select', static function (mixed $query) use (&$duplicateSql): void {
                $options = $query->getOptions();
                if ($query->getTable() === 'investment_payback_entries'
                    && str_contains(json_encode($options['where'] ?? [], JSON_THROW_ON_ERROR), 'voided_at')) {
                    // Compile the actual import query through the MySQL dialect;
                    // execute all writes only against this disposable SQLite DB.
                    $mysqlQuery = clone $query;
                    $mysqlQuery->parseOptions();
                    $duplicateSql[] = (new \think\db\builder\Mysql($query->getConnection()))->select($mysqlQuery);
                }
            });
            $this->importer()->confirm($this->batch('entries', [$this->entryRow()], ['project_id' => $id]));
        } finally {
            $eventsProperty->setValue($manager, $originalEvents);
        }
        self::assertCount(1, $duplicateSql);
        self::assertStringContainsString('FOR UPDATE', $duplicateSql[0]);
        foreach (['tenant_id', 'project_id', 'kind', 'business_date', 'precision', 'is_planned', 'voided_at'] as $field) {
            self::assertStringContainsString('`' . $field . '`', $duplicateSql[0]);
        }
        self::assertSame(1, Db::name('investment_payback_entries')->count());
    }

    public function testLegacyLongMarkerReplaysWithoutRewritingOrAddingRecords(): void
    {
        $input = $this->batch('projects', [$this->projectRow()]);
        $result = $this->importer()->confirm($input);
        $legacyKey = 'payback_import_t10_u7_' . str_replace('-', '', $input['client_request_id']);
        Db::name('system_config')->where('id', 1)->update(['config_key' => $legacyKey]);
        $before = Db::name('system_config')->where('id', 1)->find();
        $retry = $this->importer()->confirm($input);
        self::assertTrue($retry['replayed']);
        self::assertSame($result['project_ids'], $retry['project_ids']);
        self::assertSame($before, Db::name('system_config')->where('id', 1)->find());
        self::assertSame(1, Db::name('system_config')->count());
        self::assertSame(1, Db::name('investment_payback_projects')->count());
        self::assertSame(1, Db::name('investment_payback_events')->count());
    }

    public function testPreviewRejectsInvalidEncodingOversizeRowsColumnsAndUnsafeXlsx(): void
    {
        foreach ([
            ['bad.csv', '!invalidbase64!', '编码'],
            ['bad.exe', base64_encode('x'), '请选择'],
            ['../bad.csv', base64_encode('x'), '文件名'],
            ['empty.csv', base64_encode("\n\n"), '没有可识别'],
            ['wide.csv', base64_encode(implode(',', array_fill(0, 33, 'x'))), '32列'],
            ['long.csv', base64_encode(implode("\n", array_fill(0, 501, 'x'))), '500行'],
            ['large.csv', base64_encode(str_repeat('x', 10485761)), '10MB'],
            ['bad.png', base64_encode('plain text'), '图片格式'],
        ] as [$name, $encoded, $message]) {
            $this->validationFailure(fn() => $this->importer()->preview(['file_name' => $name, 'file_base64' => $encoded]), $message);
        }
        $path = $this->temporaryFile();
        $zip = new ZipArchive();
        $zip->open($path, ZipArchive::OVERWRITE);
        $zip->addFromString('../escape.xml', '<x/>');
        $zip->close();
        $this->validationFailure(fn() => $this->importer()->preview(['file_name' => 'bad.xlsx', 'file_base64' => base64_encode(file_get_contents($path))]), '压缩包路径');
        $zip->open($path, ZipArchive::OVERWRITE);
        $zip->addFromString('xl/workbook.xml', '<!DOCTYPE x [<!ENTITY y SYSTEM "http://invalid.test/x">]><x/>');
        $zip->close();
        $this->validationFailure(fn() => $this->importer()->preview(['file_name' => 'bad.xlsx', 'file_base64' => base64_encode(file_get_contents($path))]), 'XML声明');
    }

    public function testImageWordsGroupVisualCellsAndNormalizePunctuationWithoutInventingYear(): void
    {
        $ocr = ['text' => '项目 金额\n酒店甲 1000.01', 'language' => 'zh-Hans', 'source_method' => 'local_windows_ocr', 'lines' => [
            ['text' => '项目', 'x' => 5, 'y' => 0, 'height' => 20, 'words' => [['text' => '项目', 'x' => 5, 'width' => 40]]],
            ['text' => '金额', 'x' => 160, 'y' => 1, 'height' => 20, 'words' => [['text' => '金额', 'x' => 160, 'width' => 40]]],
            ['text' => '酒店甲 1000.01', 'x' => 5, 'y' => 30, 'height' => 20, 'words' => [['text' => '酒店甲', 'x' => 5, 'width' => 60], ['text' => '1000.01', 'x' => 160, 'width' => 90]]],
        ]];
        $method = new \ReflectionMethod(InvestmentPaybackImportService::class, 'imageRows');
        self::assertSame([['项目', '金额'], ['酒店甲', '1000.01']], $method->invoke(null, $ocr));
        self::assertSame([['2026-09-29', '12,345.67'], ['09/30']], $method->invoke(null, ['text' => "２０２６ 一 ０９ 一 ２９\t１ ２ ， ３４５ ． ６７\n09/30", 'lines' => []]));
        $columns = ['text' => '', 'lines' => [
            ['text' => '项目 主体 金额', 'y' => 0, 'height' => 20, 'words' => [['text' => '项目', 'x' => 0, 'width' => 40], ['text' => '主体', 'x' => 150, 'width' => 40], ['text' => '金额', 'x' => 300, 'width' => 40]]],
            ['text' => '酒店甲 1000.01', 'y' => 30, 'height' => 20, 'words' => [['text' => '酒店甲', 'x' => 0, 'width' => 60], ['text' => '1000.01', 'x' => 300, 'width' => 90]]],
        ]];
        $warnings = [];
        self::assertSame([['项目', '主体', '金额'], ['酒店甲', '', '1000.01']], $method->invokeArgs(null, [$columns, &$warnings]));
        self::assertNotEmpty($warnings);
    }

    public function testNativeImagePreviewReturnsUsableColumnsAndKeepsOriginalOcrText(): void
    {
        if (PHP_OS_FAMILY !== 'Windows' || !function_exists('proc_open')) {
            self::markTestSkipped('Native OCR fixture requires Windows.');
        }
        $path = $this->temporaryFile();
        $executable = (string)getenv('SystemRoot') . '\\System32\\WindowsPowerShell\\v1.0\\powershell.exe';
        $process = proc_open([$executable, '-NoProfile', '-NonInteractive', '-File', __DIR__ . '/fixtures/generate_payback_ocr_image.ps1', '-Path', $path],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, null, ['bypass_shell' => true, 'create_no_window' => true]);
        self::assertIsResource($process);
        $output = stream_get_contents($pipes[1]);
        $error = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        self::assertSame(0, proc_close($process), $output . $error);
        $bytes = file_get_contents($path);
        try {
            $preview = $this->importer()->preview(['file_name' => '合成验收.png', 'file_base64' => base64_encode($bytes)]);
        } catch (RuntimeException $exception) {
            if ($exception->getCode() === 503 && preg_match('/环境|语言包|引擎|进程能力/', $exception->getMessage())) {
                self::markTestSkipped($exception->getMessage());
            }
            throw $exception;
        }
        self::assertSame(hash('sha256', $bytes), $preview['sha256']);
        self::assertSame('image_ocr', $preview['source_method']);
        self::assertSame('local_windows_ocr', $preview['ocr_source_method']);
        self::assertNotEmpty($preview['raw_text']);
        self::assertNotEmpty($preview['warnings']);
        $financialRows = array_values(array_filter($preview['sheets'][0]['rows'], static fn(array $row): bool => str_contains($row[0] ?? '', '2026')));
        self::assertCount(2, $financialRows);
        self::assertSame(['2026年09月29日', '营业收入', '12,345.67'], $financialRows[0]);
        self::assertSame(['2026年09月30日', '经营支出', '2,000.00'], $financialRows[1]);
        self::assertSame(0, Db::name('investment_payback_projects')->count());
    }

    public function testCumulativeProjectsImportExactSourceReadbackAndRetryWithoutDuplicates(): void
    {
        $input = $this->batch('projects', [
            $this->projectRow(), $this->projectRow(['row_number' => 3, 'project_name' => '酒店乙', 'opening_invested' => '2000.99', 'opening_recovered' => '-10.01']),
        ]);
        $result = $this->importer()->confirm($input);
        self::assertSame(2, $result['imported_count']);
        self::assertCount(2, $result['project_ids']);
        $detail = $this->ledger()->detail($result['project_ids'][0]);
        self::assertSame('1000.01', $detail['project']['opening_invested']);
        self::assertSame('300.01', $detail['project']['opening_recovered']);
        self::assertSame('2026-09-30', $detail['project']['forecast_as_of']);
        self::assertNull($detail['project']['history_complete_through']);
        self::assertStringContainsString('file=本地测试.xlsx', $detail['project']['opening_source']);
        self::assertStringContainsString($input['source_sha256'], $detail['project']['opening_source']);
        self::assertStringContainsString('row=2', $detail['project']['opening_source']);
        $retry = $this->importer()->confirm($input);
        self::assertTrue($retry['replayed']);
        self::assertSame($result['project_ids'], $retry['project_ids']);
        self::assertSame(2, Db::name('investment_payback_projects')->count());
        self::assertSame(2, Db::name('investment_payback_events')->count());
        self::assertSame(1, Db::name('system_config')->count());
        $input['rows'][0]['opening_invested'] = '1001.00';
        $this->runtimeFailure(fn() => $this->importer()->confirm($input), 409);
        self::assertSame('1000.01', $this->ledger()->detail($result['project_ids'][0])['project']['opening_invested']);
    }

    public function testExistingProjectDuplicateAndLaterFailureRollBackWholeBatch(): void
    {
        $this->ledger()->saveProject(['project_name' => '酒店甲', 'investor_name' => '测试人', 'client_request_id' => 'existing-project-001', 'forecast_as_of' => '2026-09-30']);
        $input = $this->batch('projects', [$this->projectRow(['project_name' => '应回滚项目']), $this->projectRow(['row_number' => 3])]);
        $this->runtimeFailure(fn() => $this->importer()->confirm($input), 409);
        self::assertSame(1, Db::name('investment_payback_projects')->count());
        self::assertSame(1, Db::name('investment_payback_events')->count());
        self::assertSame(0, Db::name('system_config')->count());
        // Identical names in a different tenant are independent.
        self::assertSame(1, $this->importer(20, 8, [90])->confirm($this->batch('projects', [$this->projectRow()]))['imported_count']);
    }

    public function testEntryImportExactCentPrecisionSourceZeroConfirmationAndRetry(): void
    {
        $id = $this->emptyProject();
        $input = $this->batch('entries', [$this->entryRow(), $this->entryRow(['row_number' => 3, 'date' => '2026-09', 'precision' => 'month', 'kind' => 'recovery', 'amount' => '10.01'])], ['project_id' => $id, 'source_method' => 'pasted_table']);
        $result = $this->importer()->confirm($input);
        self::assertSame([$id], $result['project_ids']);
        self::assertCount(2, $result['entry_ids']);
        $detail = $this->ledger()->detail($id);
        self::assertSame('1000.01', $detail['summary']['invested_amount']);
        self::assertSame('10.01', $detail['summary']['net_recovered_amount']);
        self::assertSame('2026-09', $detail['entries'][0]['date']);
        self::assertStringContainsString('method=pasted_table', $detail['entries'][0]['source']);
        self::assertTrue($this->importer()->confirm($input)['replayed']);
        self::assertSame(2, Db::name('investment_payback_entries')->count());
        $zero = $this->batch('entries', [$this->entryRow(['kind' => 'recovery', 'amount' => '0.00', 'confirmed_zero' => true])], ['project_id' => $id, 'client_request_id' => '00000000-0000-4000-a000-000000000002']);
        self::assertSame(1, $this->importer()->confirm($zero)['imported_count']);
        self::assertTrue($this->ledger()->detail($id)['entries'][2]['confirmed_zero']);
    }

    public function testOpeningOverlapAfterFirstEntryAndAuditFailureAreAtomic(): void
    {
        $id = $this->ledger()->saveProject(['project_name' => '含期初', 'investor_name' => '测试人', 'client_request_id' => 'opening-project-001',
            'opening_invested' => '100.00', 'opening_recovered' => '0.00', 'opening_as_of' => '2026-08-31', 'opening_source' => '测试期初', 'forecast_as_of' => '2026-09-30', 'history_complete_through' => '2026-09-30'])['project']['id'];
        $input = $this->batch('entries', [$this->entryRow(), $this->entryRow(['row_number' => 3, 'date' => '2026-08', 'precision' => 'month'])], ['project_id' => $id]);
        $this->runtimeFailure(fn() => $this->importer()->confirm($input), 409);
        self::assertSame(0, Db::name('investment_payback_entries')->count());
        self::assertSame(1, $this->ledger()->detail($id)['project']['version']);
        self::assertSame('2026-09-30', $this->ledger()->detail($id)['project']['history_complete_through']);
        self::assertSame(1, Db::name('investment_payback_events')->count());
        self::assertSame(0, Db::name('system_config')->count());
        Db::execute('CREATE TRIGGER reject_import_marker BEFORE INSERT ON system_config BEGIN SELECT RAISE(ABORT, "synthetic marker failure"); END');
        try {
            $this->importer()->confirm($this->batch('entries', [$this->entryRow()], ['project_id' => $id]));
            self::fail('Synthetic persistence failure must roll back');
        } catch (\Throwable $exception) {
            self::assertStringContainsString('synthetic marker failure', $exception->getMessage());
        }
        self::assertSame(0, Db::name('investment_payback_entries')->count());
        self::assertSame(1, $this->ledger()->detail($id)['project']['version']);
        self::assertSame('2026-09-30', $this->ledger()->detail($id)['project']['history_complete_through']);
    }

    public function testDuplicatesInBatchAndExistingEntriesCannotDoubleCount(): void
    {
        $id = $this->emptyProject();
        $duplicateBatch = $this->batch('entries', [$this->entryRow(), $this->entryRow(['row_number' => 3, 'amount' => '1000.010'])], ['project_id' => $id]);
        $this->validationFailure(fn() => $this->importer()->confirm($duplicateBatch), '金额');
        $duplicateBatch['rows'][1]['amount'] = '1000.01';
        $this->validationFailure(fn() => $this->importer()->confirm($duplicateBatch), '重复');
        self::assertSame(0, Db::name('investment_payback_entries')->count());
        $first = $this->importer()->confirm($this->batch('entries', [$this->entryRow()], ['project_id' => $id]));
        $this->runtimeFailure(fn() => $this->importer()->confirm($this->batch('entries', [$this->entryRow()], ['project_id' => $id, 'client_request_id' => '00000000-0000-4000-a000-000000000003'])), 409);
        self::assertSame(1, Db::name('investment_payback_entries')->count());
        $this->ledger()->voidEntry($id, $first['entry_ids'][0], ['expected_version' => 1, 'reason' => '测试作废重复明细']);
        self::assertSame(1, $this->importer()->confirm($this->batch('entries', [$this->entryRow()], ['project_id' => $id, 'client_request_id' => '00000000-0000-4000-a000-000000000004']))['imported_count']);
    }

    public function testConfirmationMissingDatesMalformedMoneyAndScopesNeverWrite(): void
    {
        $id = $this->emptyProject();
        $base = $this->batch('entries', [$this->entryRow()], ['project_id' => $id]);
        foreach ([
            array_merge($base, ['confirmed' => false]),
            array_merge($base, ['confirmed' => 'true']),
            array_merge($base, ['client_request_id' => 'not-a-uuid']),
            array_merge($base, ['rows' => [$this->entryRow(['date' => '09-30'])]]),
            array_merge($base, ['rows' => [$this->entryRow(['date' => ''])]]),
            array_merge($base, ['rows' => [$this->entryRow(['amount' => '1.005'])]]),
            array_merge($base, ['rows' => [$this->entryRow(['kind' => 'recovery', 'amount' => '0'])]]),
        ] as $input) {
            $this->validationFailure(fn() => $this->importer()->confirm($input), '');
        }
        $this->validationFailure(fn() => $this->importer()->confirm($this->batch('projects', [$this->projectRow(['opening_as_of' => ''])])), '截至日');
        $this->runtimeFailure(fn() => $this->importer(20, 8, [90])->confirm($base), 404);
        $hotelId = $this->ledger()->saveProject(['project_name' => '酒店限定', 'investor_name' => '测试人', 'hotel_id' => 80, 'client_request_id' => 'hotel-scope-001', 'forecast_as_of' => '2026-09-30'])['project']['id'];
        $this->runtimeFailure(fn() => $this->importer(10, 8, [81])->confirm(array_merge($base, ['project_id' => $hotelId])), 403);
        self::assertSame(0, Db::name('investment_payback_entries')->count());
        self::assertSame(0, Db::name('system_config')->count());
    }

    public function testEditedPreviewShowsExactBatchAndSimilarCandidatesWithoutWrites(): void
    {
        $ledger = $this->ledger();
        $id = $ledger->saveProject(['project_name' => '重复预览测试', 'investor_name' => '测试主体', 'client_request_id' => 'review-project',
            'opening_invested' => '100.00', 'opening_recovered' => '0.00', 'opening_as_of' => '2026-08-31', 'opening_source' => '合成期初', 'forecast_as_of' => '2026-09-30'])['project']['id'];
        $ledger->saveEntry($id, ['kind' => 'recovery', 'amount' => '20.02', 'date' => '2026-09-15', 'source' => '合成实收', 'notes' => '收款A', 'client_request_id' => 'review-existing']);
        $ledger->saveEntry($id, ['kind' => 'recovery', 'amount' => '20.02', 'date' => '2026-09-16', 'source' => '合成计划', 'notes' => '收款B', 'is_planned' => true, 'client_request_id' => 'review-plan']);
        $rows = [
            $this->entryRow(['date' => '2026-09-15', 'kind' => 'recovery', 'amount' => '20.02', 'note' => '收款A']),
            $this->entryRow(['row_number' => 3, 'date' => '2026-09-15', 'kind' => 'recovery', 'amount' => '20.02', 'note' => '收款A']),
            $this->entryRow(['row_number' => 4, 'date' => '2026-09-15', 'kind' => 'recovery', 'amount' => '20.02', 'note' => '收款B']),
            $this->entryRow(['row_number' => 5, 'date' => '2026-09-17', 'amount' => '3.01']),
            $this->entryRow(['row_number' => 6, 'date' => '2099-09-17']),
            $this->entryRow(['row_number' => 7, 'date' => '2026-09-17', 'is_planned' => true]),
            $this->entryRow(['row_number' => 8, 'date' => '2026-08-31']),
        ];
        $beforeEntries = Db::name('investment_payback_entries')->count();
        $beforeEvents = Db::name('investment_payback_events')->count();
        $review = $this->importer()->preview(['review_rows' => true, 'mode' => 'entries', 'project_id' => $id, 'rows' => $rows]);
        self::assertFalse($review['can_confirm']);
        self::assertSame(2, $review['exact_count']);
        self::assertSame(3, $review['invalid_count']);
        self::assertSame('收款A', $review['rows'][0]['exact_matches'][0]['note']);
        self::assertSame(3, $review['rows'][0]['batch_duplicates'][0]['row_number']);
        self::assertSame(2, $review['rows'][1]['batch_duplicates'][0]['row_number']);
        self::assertSame('收款A', $review['rows'][2]['similar_matches'][0]['note']);
        self::assertStringContainsString('可能是另一笔真实资金', $review['rows'][2]['similar_matches'][0]['reason']);
        self::assertSame('3.01', $review['impact']['actual_invested_delta']);
        self::assertSame('20.02', $review['impact']['actual_net_recovered_delta']);
        self::assertSame('opening_overlap', $review['rows'][6]['impact_excluded_reason']);
        self::assertSame($beforeEntries, Db::name('investment_payback_entries')->count());
        self::assertSame($beforeEvents, Db::name('investment_payback_events')->count());
        self::assertSame(0, Db::name('system_config')->count());
        $validRows = [$rows[2], $rows[3]];
        $review = $this->importer()->preview(['review_rows' => true, 'mode' => 'entries', 'project_id' => $id, 'rows' => $validRows]);
        self::assertTrue($review['can_confirm']);
        self::assertSame(1, $review['similar_count']);
        $batch = $this->batch('entries', $validRows, ['project_id' => $id, 'review_token' => $review['review_token']]);
        $this->validationFailure(fn() => $this->importer()->confirm($batch), '相似候选');
        self::assertSame($beforeEntries, Db::name('investment_payback_entries')->count());
        $batch['similar_confirmed'] = true;
        self::assertSame(2, $this->importer()->confirm($batch)['imported_count']);
        self::assertTrue($this->importer()->confirm($batch)['replayed']);
        self::assertSame('40.04', $ledger->detail($id)['summary']['net_recovered_amount']);
    }

    public function testPreviewTokenRejectsEditedRowsOrChangedLedgerAndKeepsWholeBatchAtomic(): void
    {
        $id = $this->emptyProject();
        $rows = [$this->entryRow(), $this->entryRow(['row_number' => 3, 'date' => '2026-09-02', 'kind' => 'recovery', 'amount' => '2.01'])];
        $input = ['review_rows' => true, 'mode' => 'entries', 'project_id' => $id, 'rows' => $rows];
        $review = $this->importer()->preview($input);
        $edited = $rows;
        $edited[1]['amount'] = '2.02';
        $this->runtimeFailure(fn() => $this->importer()->confirm($this->batch('entries', $edited, ['project_id' => $id, 'review_token' => $review['review_token']])), 409);
        self::assertSame(0, Db::name('investment_payback_entries')->count());
        $this->ledger()->saveEntry($id, ['kind' => 'recovery', 'amount' => '9.00', 'date' => '2026-09-04', 'source' => '合成并发写入', 'client_request_id' => 'review-concurrent']);
        $this->runtimeFailure(fn() => $this->importer()->confirm($this->batch('entries', $rows, ['project_id' => $id, 'review_token' => $review['review_token']])), 409);
        self::assertSame(1, Db::name('investment_payback_entries')->count());
        self::assertSame(0, Db::name('system_config')->count());
        $input['rows'][1]['selected'] = false;
        $newReview = $this->importer()->preview($input);
        self::assertSame(1, $newReview['selected_count']);
        self::assertSame('0.00', $newReview['impact']['actual_net_recovered_delta']);
        self::assertSame(1, $this->importer()->confirm($this->batch('entries', [$rows[0]], ['project_id' => $id, 'review_token' => $newReview['review_token']]))['imported_count']);
    }

    public function testProjectPreviewSeparatesOpeningTotalsAndExcludedDuplicateDoesNotInvalidateConfirmation(): void
    {
        $this->ledger()->saveProject(['project_name' => '酒店甲', 'investor_name' => '测试人', 'client_request_id' => 'review-existing-project', 'forecast_as_of' => '2026-09-30']);
        $rows = [$this->projectRow(['selected' => false]), $this->projectRow(['row_number' => 3, 'project_name' => '酒店乙', 'opening_recovered' => '-10.01'])];
        $review = $this->importer()->preview(['review_rows' => true, 'mode' => 'projects', 'rows' => $rows]);
        self::assertTrue($review['can_confirm']);
        self::assertCount(1, $review['rows'][0]['exact_matches']);
        self::assertSame(0, $review['exact_count']);
        self::assertSame('0.00', $review['impact']['actual_invested_delta']);
        self::assertSame('1000.01', $review['impact']['opening_invested_total']);
        self::assertSame('-10.01', $review['impact']['opening_net_recovered_total']);
        self::assertSame(1, $this->importer()->confirm($this->batch('projects', [$rows[1]], ['review_token' => $review['review_token']]))['imported_count']);
    }

    public function testConfirmPreservesSelectedProjectRowsFromTheReviewedFullPayload(): void
    {
        $rows = [$this->projectRow(['selected' => true]), $this->projectRow(['row_number' => 3, 'project_name' => '已排除项目', 'opening_invested' => '9000.00', 'selected' => false])];
        $review = $this->importer()->preview(['review_rows' => true, 'mode' => 'projects', 'rows' => $rows]);
        self::assertTrue($review['can_confirm']);
        self::assertSame(1, $review['selected_count']);
        $batch = $this->batch('projects', $rows, ['review_token' => $review['review_token']]);
        $result = $this->importer()->confirm($batch);
        self::assertSame(1, $result['imported_count']);
        self::assertSame(1, Db::name('investment_payback_projects')->count());
        self::assertSame(0, Db::name('investment_payback_projects')->where('project_name', '已排除项目')->count());
        self::assertSame($review['impact']['opening_invested_total'], $this->ledger()->detail($result['project_ids'][0])['summary']['invested_amount']);
        $batch['rows'] = [$rows[0]];
        self::assertTrue($this->importer()->confirm($batch)['replayed']);
    }

    public function testConfirmIgnoresExcludedEntryDuplicatesAndInvalidAmountsWithoutChangingTheReviewedImpact(): void
    {
        $id = $this->emptyProject();
        $rows = [$this->entryRow(['selected' => true]), $this->entryRow(['row_number' => 3, 'selected' => false]), $this->entryRow(['row_number' => 4, 'date' => '', 'amount' => '未填写', 'selected' => false])];
        $review = $this->importer()->preview(['review_rows' => true, 'mode' => 'entries', 'project_id' => $id, 'rows' => $rows]);
        self::assertTrue($review['can_confirm']);
        self::assertSame(1, $review['selected_count']);
        self::assertSame(1, $this->importer()->confirm($this->batch('entries', $rows, ['project_id' => $id, 'review_token' => $review['review_token']]))['imported_count']);
        self::assertSame(1, Db::name('investment_payback_entries')->count());
        self::assertSame($review['impact']['actual_invested_delta'], $this->ledger()->detail($id)['summary']['invested_amount']);
    }

    public function testAllExcludedAndMalformedSelectionsCannotWriteRecordsOrAnImportMarker(): void
    {
        $rows = [$this->projectRow(['selected' => false])];
        $this->validationFailure(fn() => $this->importer()->confirm($this->batch('projects', $rows)), '至少选择');
        foreach ([1, 'true', null, 0, 'false'] as $invalid) {
            $rows = [$this->projectRow(['selected' => $invalid])];
            $this->validationFailure(fn() => $this->importer()->preview(['review_rows' => true, 'mode' => 'projects', 'rows' => $rows]), '选中状态');
            $this->validationFailure(fn() => $this->importer()->confirm($this->batch('projects', $rows)), '选中状态');
        }
        self::assertSame(0, Db::name('investment_payback_projects')->count());
        self::assertSame(0, Db::name('investment_payback_events')->count());
        self::assertSame(0, Db::name('system_config')->count());
    }

    public function testPlannedStringFlagsCannotBecomeActualCashThroughImport(): void
    {
        $id = $this->emptyProject();
        foreach ([true, 1, '1'] as $planned) {
            $rows = [$this->entryRow(['is_planned' => $planned])];
            $review = $this->importer()->preview(['review_rows' => true, 'mode' => 'entries', 'project_id' => $id, 'rows' => $rows]);
            self::assertFalse($review['can_confirm']);
            self::assertSame('0.00', $review['impact']['actual_invested_delta']);
            $this->validationFailure(fn() => $this->importer()->confirm($this->batch('entries', $rows, ['project_id' => $id])), '计划记录');
        }
        self::assertSame(0, Db::name('investment_payback_entries')->count());
        self::assertSame(0, Db::name('system_config')->count());
    }

    public function testPreviewPreservesTenantHotelScopeAndDoesNotCountIncompleteCurrentMonth(): void
    {
        $id = $this->emptyProject();
        $this->runtimeFailure(fn() => $this->importer(20, 8, [90])->preview(['review_rows' => true, 'mode' => 'entries', 'project_id' => $id, 'rows' => [$this->entryRow()]]), 404);
        $hotelId = $this->ledger()->saveProject(['project_name' => '预览酒店限定', 'investor_name' => '测试主体', 'hotel_id' => 80, 'client_request_id' => 'preview-hotel-scope', 'forecast_as_of' => '2026-09-30'])['project']['id'];
        $this->runtimeFailure(fn() => $this->importer(10, 8, [81])->preview(['review_rows' => true, 'mode' => 'entries', 'project_id' => $hotelId, 'rows' => [$this->entryRow()]]), 403);
        $today = InvestmentPaybackCalculator::today();
        $month = substr($today, 0, 7);
        $review = $this->importer()->preview(['review_rows' => true, 'mode' => 'entries', 'project_id' => $id, 'rows' => [$this->entryRow(['date' => $month, 'precision' => 'month', 'kind' => 'recovery', 'amount' => '20.02'])]]);
        [, $monthEnd] = InvestmentPaybackCalculator::period($month, 'month');
        self::assertSame($monthEnd > $today ? '0.00' : '20.02', $review['impact']['actual_net_recovered_delta']);
        self::assertSame($monthEnd > $today ? 'period_after_today' : null, $review['rows'][0]['impact_excluded_reason']);
    }

    public function testConfirmedImportInvalidatesPriorHistoryButReplayPreservesFreshReview(): void
    {
        $ledger = $this->ledger();
        $id = $ledger->saveProject(['project_name' => '核对状态导入', 'investor_name' => '测试主体', 'client_request_id' => 'review-history-project',
            'forecast_as_of' => '2026-09-30', 'history_complete_through' => '2026-09-30'])['project']['id'];
        $batch = $this->batch('entries', [$this->entryRow()], ['project_id' => $id]);
        $review = $this->importer()->preview(['review_rows' => true, 'mode' => 'entries', 'project_id' => $id, 'rows' => $batch['rows']]);
        $batch['review_token'] = $review['review_token'];
        self::assertSame(1, $this->importer()->confirm($batch)['imported_count']);
        $project = $ledger->detail($id)['project'];
        self::assertNull($project['history_complete_through']);
        $ledger->saveProject(['id' => $id, 'expected_version' => $project['version'], 'history_complete_through' => '2026-09-30']);
        self::assertTrue($this->importer()->confirm($batch)['replayed']);
        self::assertSame('2026-09-30', $ledger->detail($id)['project']['history_complete_through']);
    }

    public function testImportControllerReturnsStandardPreviewAndSemanticErrors(): void
    {
        self::assertSame(401, $this->controller(null)->importPreview()->getCode());
        $user = new User(['id' => 7, 'tenant_id' => 10, 'role_id' => 1]);
        self::assertSame(422, $this->controller($user)->importConfirm()->getCode());
        $response = $this->controller($user, ['file_name' => '测试.csv', 'file_base64' => base64_encode("项目,金额\n酒店甲,1.01")])->importPreview();
        self::assertSame(200, $response->getCode());
        $body = json_decode($response->getContent(), true);
        self::assertSame(200, $body['code']);
        self::assertArrayHasKey('message', $body);
        self::assertSame('1.01', $body['data']['sheets'][0]['rows'][1][1]);
    }

    public function testImportRoutesResolveExactPostActionsWithAuth(): void
    {
        $originalApp = app();
        try {
        $app = new App(dirname(__DIR__));
        $app->setRuntimePath(sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'payback_import_route_' . bin2hex(random_bytes(8)) . DIRECTORY_SEPARATOR);
        $app->config->set(require dirname(__DIR__) . '/config/route.php', 'route');
        $router = new InvestmentPaybackImportRoutingProbe($app);
        $app->instance('route', $router);
        require dirname(__DIR__) . '/route/app.php';
        foreach (['preview' => 'importPreview', 'confirm' => 'importConfirm'] as $path => $action) {
            $request = (new Request())->setMethod('POST')->setUrl('/api/investment-payback/import/' . $path)
                ->setBaseUrl('/api/investment-payback/import/' . $path)->setPathinfo('api/investment-payback/import/' . $path);
            $dispatch = $router->resolve($request);
            self::assertInstanceOf(Controller::class, $dispatch);
            self::assertSame(['InvestmentPayback', $action], $dispatch->getDispatch());
            $rule = (new \ReflectionProperty(Dispatch::class, 'rule'))->getValue($dispatch);
            $middleware = array_map(static fn($item) => is_array($item) ? $item[0] : $item, $rule->getOption('middleware', []));
            self::assertContains(Auth::class, $middleware);
        }
        } finally {
            \think\Container::setInstance($originalApp);
        }
    }

    private function temporaryFile(): string
    {
        $path = tempnam(sys_get_temp_dir(), 'payback_import_fixture_');
        $this->temporaryFiles[] = $path;
        return $path;
    }

    private function importer(int $tenantId = 10, int $actorId = 7, array $allowedHotels = [80], ?LocalImageOcrService $ocr = null): InvestmentPaybackImportService
    {
        $user = new User(['id' => $actorId, 'tenant_id' => $tenantId, 'role_id' => 1]);
        return new InvestmentPaybackImportService($user, $this->ledger($tenantId, $actorId, $allowedHotels), $ocr);
    }

    private function ledger(int $tenantId = 10, int $actorId = 7, array $allowedHotels = [80]): InvestmentPaybackService
    {
        $permissions = $this->createMock(PermissionService::class);
        $permissions->method('authorize')->willReturnCallback(static fn(User $user, string $capability, ?int $hotelId = null): array => ['allowed' => $capability === 'investment.simulate' && ($hotelId === null || in_array($hotelId, $allowedHotels, true))]);
        $scope = $this->createMock(HotelScopeService::class);
        $scope->method('accessibleHotelIds')->willReturn($allowedHotels);
        return new InvestmentPaybackService(new User(['id' => $actorId, 'tenant_id' => $tenantId, 'role_id' => 1]), $permissions, null, $scope);
    }

    private function emptyProject(): int
    {
        return $this->ledger()->saveProject(['project_name' => '明细测试项目', 'investor_name' => '测试人', 'client_request_id' => 'empty-import-project', 'forecast_as_of' => '2026-09-30'])['project']['id'];
    }

    private function batch(string $mode, array $rows, array $changes = []): array
    {
        return array_merge(['client_request_id' => '00000000-0000-4000-a000-000000000001', 'confirmed' => true,
            'source_file_name' => '本地测试.xlsx', 'source_sha256' => hash('sha256', 'synthetic test-only file'), 'source_method' => 'spreadsheet', 'mode' => $mode, 'rows' => $rows], $changes);
    }

    private function projectRow(array $changes = []): array
    {
        return array_merge(['row_number' => 2, 'project_name' => '酒店甲', 'investor_name' => '测试人', 'opening_invested' => '1000.01', 'opening_recovered' => '300.01', 'opening_as_of' => '2026-09-30'], $changes);
    }

    private function entryRow(array $changes = []): array
    {
        return array_merge(['row_number' => 2, 'date' => '2026-09-01', 'precision' => 'day', 'kind' => 'investment', 'amount' => '1000.01', 'note' => '测试明细'], $changes);
    }

    private function controller(?User $user, array $post = []): InvestmentPayback
    {
        $reflection = new \ReflectionClass(InvestmentPayback::class);
        $controller = $reflection->newInstanceWithoutConstructor();
        $reflection->getProperty('currentUser')->setValue($controller, $user);
        $request = (new Request())->withPost($post);
        $request->user = $user;
        app()->instance('request', $request);
        $reflection->getProperty('request')->setValue($controller, $request);
        return $controller;
    }

    private function validationFailure(callable $action, string $message): void
    {
        try {
            $action();
            self::fail('Expected input rejection');
        } catch (InvalidArgumentException $exception) {
            self::assertStringContainsString($message, $exception->getMessage());
        }
    }

    private function runtimeFailure(callable $action, int $code): void
    {
        try {
            $action();
            self::fail('Expected conflict or scope rejection');
        } catch (RuntimeException $exception) {
            self::assertSame($code, $exception->getCode(), $exception->getMessage());
        }
    }
}

final class InvestmentPaybackImportRoutingProbe extends Route
{
    public function resolve(Request $request): Dispatch|false
    {
        $this->request = $request;
        $this->host = $request->host(true);
        $url = str_replace($this->config('pathinfo_depr'), '|', $this->path());
        return $this->check($url, (bool)$this->config('route_complete_match'));
    }
}
