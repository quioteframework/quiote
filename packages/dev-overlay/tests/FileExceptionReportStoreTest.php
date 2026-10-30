<?php

declare(strict_types=1);

use PHPUnit\Framework\Attributes\After;
use PHPUnit\Framework\Attributes\Before;
use PHPUnit\Framework\TestCase;
use Quiote\DevOverlay\Report\FileExceptionReportStore;

final class FileExceptionReportStoreTest extends TestCase
{
    private string $directory;

    #[Before]
    public function createDirectory(): void
    {
        $this->directory = sys_get_temp_dir() . '/quiote-dev-overlay-test-' . bin2hex(random_bytes(6));
    }

    #[After]
    public function removeDirectory(): void
    {
        if (!is_dir($this->directory)) {
            return;
        }
        foreach (glob($this->directory . '/*') ?: [] as $file) {
            unlink($file);
        }
        rmdir($this->directory);
    }

    private static function id(int $n): string
    {
        return str_pad(dechex($n), 32, '0', STR_PAD_LEFT);
    }

    /** @return array{id: string, time: string, status: int, request: array{method: string, uri: string}, exceptions: list<never>} */
    private static function report(string $id): array
    {
        return ['id' => $id, 'time' => '2026-10-30T12:00:00+00:00', 'status' => 500, 'request' => ['method' => 'GET', 'uri' => '/'], 'exceptions' => []];
    }

    public function testAStoredReportReadsBack(): void
    {
        $store = new FileExceptionReportStore($this->directory);

        $store->put(self::id(1), self::report(self::id(1)));

        $this->assertSame(self::report(self::id(1)), $store->get(self::id(1)));
    }

    public function testCreatesItsDirectoryOnFirstWrite(): void
    {
        $store = new FileExceptionReportStore($this->directory . '/nested');

        $store->put(self::id(1), self::report(self::id(1)));

        $this->assertDirectoryExists($this->directory . '/nested');
        unlink($this->directory . '/nested/' . self::id(1) . '.json');
        rmdir($this->directory . '/nested');
    }

    public function testAnUnknownIdIsNull(): void
    {
        $this->assertNull((new FileExceptionReportStore($this->directory))->get(self::id(9)));
    }

    public function testAMalformedIdIsNullAndNeverBecomesAPath(): void
    {
        $store = new FileExceptionReportStore($this->directory);

        $this->assertNull($store->get('../../etc/passwd'));
        $this->assertNull($store->get(strtoupper(self::id(1))));
    }

    public function testRefusesToWriteUnderAMalformedId(): void
    {
        $this->expectException(RuntimeException::class);
        (new FileExceptionReportStore($this->directory))->put('../escape', self::report('x'));
    }

    public function testInvalidUtf8InAMessageIsSubstitutedRatherThanLosingTheReport(): void
    {
        $store = new FileExceptionReportStore($this->directory);
        $report = self::report(self::id(1));
        $report['request']['uri'] = "/caf\xE9";

        $store->put(self::id(1), $report);

        $stored = $store->get(self::id(1));
        $this->assertIsArray($stored);
        $this->assertIsArray($stored['request']);
        $this->assertSame("/caf\u{FFFD}", $stored['request']['uri']);
    }

    public function testACorruptReportIsNull(): void
    {
        mkdir($this->directory);
        file_put_contents($this->directory . '/' . self::id(1) . '.json', '{not json');

        $this->assertNull((new FileExceptionReportStore($this->directory))->get(self::id(1)));
    }

    public function testKeepsOnlyTheNewestReports(): void
    {
        $store = new FileExceptionReportStore($this->directory, maxReports: 2);
        foreach ([1, 2, 3] as $n) {
            $store->put(self::id($n), self::report(self::id($n)));
            touch($this->directory . '/' . self::id($n) . '.json', 1_700_000_000 + $n);
            clearstatcache();
        }
        $store->put(self::id(4), self::report(self::id(4)));

        $this->assertNull($store->get(self::id(1)));
        $this->assertNull($store->get(self::id(2)));
        $this->assertNotNull($store->get(self::id(3)));
        $this->assertNotNull($store->get(self::id(4)));
    }

    public function testAnUncreatableDirectoryThrows(): void
    {
        $file = $this->directory . '-file';
        file_put_contents($file, '');
        try {
            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessageMatches('/could not be created/');
            (new FileExceptionReportStore($file . '/reports'))->put(self::id(1), self::report(self::id(1)));
        } finally {
            unlink($file);
        }
    }
}
