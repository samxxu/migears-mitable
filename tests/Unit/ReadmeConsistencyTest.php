<?php

declare(strict_types=1);

namespace MiGears\MiTable\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Guards the README's factual claims against their sources of truth.
 *
 * The workflow matrix is the source of truth: it is what actually runs. The
 * README states the same range in both halves, and a matrix bump used to leave
 * that statement behind — after `'8.5'` was appended to the matrix, both halves
 * still said `PHP 8.1–8.4`. This test fails whenever the two disagree, so the
 * next bump cannot silently ship a stale claim.
 */
final class ReadmeConsistencyTest extends TestCase
{
    public function testReadmeAdvertisesTheSamePhpRangeAsTheCiMatrix(): void
    {
        $root = dirname(__DIR__, 2);

        $workflow = (string) file_get_contents($root . '/.github/workflows/tests.yml');
        self::assertSame(
            1,
            preg_match('/php:\s*\[([^\]]+)\]/', $workflow, $matrix),
            'Could not find the PHP matrix in .github/workflows/tests.yml'
        );

        $versions = array_map(
            static fn (string $version): string => trim($version, " '\""),
            explode(',', $matrix[1])
        );
        $expected = $versions[0] . "\u{2013}" . $versions[count($versions) - 1];

        $readme = (string) file_get_contents($root . '/README.md');
        $stated = [];
        foreach (explode("\n", $readme) as $line) {
            if (preg_match('/\d+\.\d+\x{2013}\d+\.\d+/u', $line, $range) === 1) {
                $stated[] = $range[0];
            }
        }

        // One statement per README half; both must track the matrix.
        self::assertSame(
            [$expected, $expected],
            $stated,
            "Both README halves must state the supported PHP range as {$expected}"
        );
    }

    /**
     * The behaviour notes must warn about both whole-table shortcuts.
     *
     * `update($data, [])` reaches every row exactly as `delete([])` does, but
     * the README warned about only the second. One warning per half.
     */
    public function testReadmeWarnsThatAnEmptyWhereUpdatesEveryRow(): void
    {
        $root = dirname(__DIR__, 2);

        $readme = (string) file_get_contents($root . '/README.md');

        $hits = preg_match_all(
            '/`update\(\$data, \[\]\)`.{0,32}(updates every row|会更新全部行)/u',
            $readme
        );

        self::assertSame(
            2,
            $hits,
            'Both README halves must warn that update($data, []) updates every row'
        );
    }
}
