<?php

declare(strict_types=1);

namespace MiGears\MiTable\Tests\Conformance;

use MiGears\MiTable\MiTableInterface;

/**
 * The assertions every dialect implementation must satisfy identically.
 *
 * This trait is the answer to a real gap: before it existed, the SQLite suite
 * and the MySQL suite tested *different* things, so "cross-driver" was never a
 * verified property. The DDL verbs with no SQLite coverage at all —
 * rename, truncate, renameColumn, dropColumn — went unnoticed for a whole
 * version because nothing ran them on both drivers.
 *
 * A subclass supplies the connection and the fixture, creating a fresh table
 * per test:
 *
 *   protected function newTable(): MiTableInterface
 *
 * Dialect-specific behaviour stays out of here: MySQL's AFTER positioning and
 * USING index types, SQLite's rowid-alias primary key, and the three DDL verbs
 * SQLite has no ALTER equivalent for all belong in the per-dialect suites.
 */
trait TableConformanceTests
{
    protected MiTableInterface $conform;

    /** Create a fresh, empty standard table for the dialect under test. */
    abstract protected function newTable(): MiTableInterface;

    protected function setUp(): void
    {
        parent::setUp();
        $this->conform = $this->newTable();
    }

    /**
     * An instance bound to a table name that does not exist.
     *
     * Drops the fixture created by setUp() rather than building another one,
     * because the table it was built from is still present mid-test.
     */
    protected function missingTable(): MiTableInterface
    {
        $this->conform->drop();

        return $this->conform;
    }

    // ==================== DDL ====================

    public function testCreateAndExists(): void
    {
        self::assertTrue($this->conform->exists());

        $this->conform->drop();

        self::assertFalse($this->conform->exists());
    }

    public function testCreateRejectsAnEmptyColumnList(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->conform->create([]);
    }

    public function testRenameMovesTheTableAndKeepsItsRows(): void
    {
        $this->conform->insert(['username' => 'alice', 'email' => 'a@example.com']);

        $renamed = $this->conform->rename('renamed_users');

        self::assertSame('renamed_users', $renamed->getName());
        self::assertTrue($renamed->exists());
        self::assertFalse($this->conform->exists());
        self::assertSame(1, $renamed->count());
        self::assertSame('alice', $renamed->find(['username' => 'alice'])['username']);
    }

    public function testTruncateEmptiesTheTableAndKeepsIt(): void
    {
        $this->conform->insert(['username' => 'alice', 'email' => 'a@example.com']);
        $this->conform->insert(['username' => 'bob', 'email' => 'b@example.com']);

        $this->conform->truncate();

        self::assertSame(0, $this->conform->count());
        self::assertTrue($this->conform->exists());
    }

    public function testTruncateRestartsTheIdentityColumn(): void
    {
        $this->conform->insert(['username' => 'alice', 'email' => 'a@example.com']);
        $this->conform->insert(['username' => 'bob', 'email' => 'b@example.com']);

        $this->conform->truncate();
        $id = $this->conform->insert(['username' => 'carol', 'email' => 'c@example.com']);

        self::assertSame(1, (int) $id);
    }

    public function testAddColumn(): void
    {
        $this->conform->addColumn('phone', 'VARCHAR(20) NULL');

        $columns = $this->conform->showColumns();
        self::assertArrayHasKey('phone', $columns);

        $id = $this->conform->insert(['username' => 'alice', 'email' => 'a@example.com', 'phone' => '123']);
        self::assertSame('123', $this->conform->find(['id' => $id])['phone']);
    }

    public function testDropColumn(): void
    {
        $this->conform->dropColumn('email');

        self::assertArrayNotHasKey('email', $this->conform->showColumns());
    }

    public function testRenameColumn(): void
    {
        $this->conform->insert(['username' => 'alice', 'email' => 'a@example.com']);

        $this->conform->renameColumn('username', 'handle', 'VARCHAR(50) NOT NULL');

        $columns = $this->conform->showColumns();
        self::assertArrayHasKey('handle', $columns);
        self::assertArrayNotHasKey('username', $columns);
        self::assertSame('alice', $this->conform->find(['handle' => 'alice'])['handle']);
    }

    public function testAddAndDropIndex(): void
    {
        $this->conform->addIndex('idx_username', ['username']);
        self::assertArrayHasKey('idx_username', $this->conform->showIndexes());

        $this->conform->dropIndex('idx_username');
        self::assertArrayNotHasKey('idx_username', $this->conform->showIndexes());
    }

    public function testAddUniqueIndexEnforcesUniqueness(): void
    {
        $this->conform->addUniqueIndex('uniq_username', ['username']);
        $this->conform->insert(['username' => 'alice', 'email' => 'a@example.com']);

        self::assertTrue($this->conform->showIndexes()['uniq_username']['unique']);

        $this->expectException(\PDOException::class);
        $this->conform->insert(['username' => 'alice', 'email' => 'b@example.com']);
    }

    // ==================== Introspection ====================

    public function testShowColumnsReportsTheStandardShape(): void
    {
        $columns = $this->conform->showColumns();

        self::assertSame(['id', 'username', 'email'], array_keys($columns));
        self::assertTrue($columns['id']['primary']);
        self::assertSame(false, $columns['id']['nullable']);
        self::assertFalse($columns['username']['nullable']);
        self::assertTrue($columns['email']['nullable']);
    }

    public function testShowColumnsAndIndexesReturnEmptyForAMissingTable(): void
    {
        $missing = $this->missingTable();

        self::assertSame([], $missing->showColumns());
        self::assertSame([], $missing->showIndexes());
    }

    public function testShowIndexesReportsAColumnList(): void
    {
        $this->conform->addUniqueIndex('uniq_username_email', ['username', 'email']);

        // A composite index is invisible to showColumns(); this is why
        // showIndexes() exists.
        self::assertSame(['username', 'email'], $this->conform->showIndexes()['uniq_username_email']['columns']);
    }

    // ==================== CRUD ====================

    public function testInsertFindAndCount(): void
    {
        self::assertSame(0, $this->conform->count());

        $id = $this->conform->insert(['username' => 'alice', 'email' => 'a@example.com']);

        self::assertSame(1, (int) $id);
        self::assertSame('alice', $this->conform->find(['id' => $id])['username']);
        self::assertNull($this->conform->find(['username' => 'nobody']));
        self::assertSame(1, $this->conform->count());
    }

    public function testBulkInsert(): void
    {
        $affected = $this->conform->bulkInsert([
            ['username' => 'alice', 'email' => 'a@example.com'],
            ['username' => 'bob', 'email' => 'b@example.com'],
            ['username' => 'carol', 'email' => 'c@example.com'],
        ]);

        self::assertSame(3, $affected);
        self::assertSame(3, $this->conform->count());
    }

    public function testBulkInsertWithNoRows(): void
    {
        self::assertSame(0, $this->conform->bulkInsert([]));
    }

    public function testBulkInsertRejectsARowWithAnExtraColumn(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('unexpected [phone]');

        $this->conform->bulkInsert([
            ['username' => 'alice', 'email' => 'a@example.com'],
            ['username' => 'bob', 'email' => 'b@example.com', 'phone' => '123'],
        ]);
    }

    public function testBulkInsertRejectsARowWithAMissingColumn(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('missing [email]');

        $this->conform->bulkInsert([
            ['username' => 'alice', 'email' => 'a@example.com'],
            ['username' => 'bob'],
        ]);
    }

    public function testBulkInsertRejectsRaggedRowsBeforeWriting(): void
    {
        try {
            $this->conform->bulkInsert([
                ['username' => 'alice', 'email' => 'a@example.com'],
                ['username' => 'bob'],
            ]);
            self::fail('Expected the ragged batch to be rejected');
        } catch (\InvalidArgumentException) {
        }

        // The rejection happens before any SQL is built, so nothing is written.
        self::assertSame(0, $this->conform->count());
    }

    public function testInsertWorksWithHyphenatedColumnName(): void
    {
        // PDO placeholders built from the raw column name would break on a
        // hyphen (":user-id" gets parsed as ":user" minus "-id"). Synthetic
        // placeholders (:i0, :i1, …) make any legal column name work.
        $table = $this->makeTableWith('user-id', 'VARCHAR(20) NOT NULL');
        $table->insert(['user-id' => 'abc-123']);

        self::assertSame('abc-123', $table->find(['user-id' => 'abc-123'])['user-id']);
    }

    public function testBulkInsertWorksWithHyphenatedColumnName(): void
    {
        $table = $this->makeTableWith('user-id', 'VARCHAR(20) NOT NULL');
        $table->bulkInsert([
            ['user-id' => 'abc-1'],
            ['user-id' => 'abc-2'],
        ]);

        self::assertSame(2, $table->count());
    }

    /**
     * Build a one-column table for tests that need a column whose name is not
     * in the standard fixture (e.g. hyphenated, spaced).
     */
    private function makeTableWith(string $columnName, string $definition): MiTableInterface
    {
        $name = 'conform_special_' . bin2hex(random_bytes(4));
        $class = $this->conform::class;
        $table = new $class($this->conform->getPdo(), $name);
        $table->create([
            'id' => $this->conform instanceof \MiGears\MiTable\SQLiteTable
                ? 'INTEGER PRIMARY KEY AUTOINCREMENT'
                : 'INT UNSIGNED AUTO_INCREMENT PRIMARY KEY',
            $columnName => $definition,
        ]);

        return $table;
    }

    public function testUpdate(): void
    {
        $id = $this->conform->insert(['username' => 'alice', 'email' => 'old@example.com']);

        $affected = $this->conform->update(['email' => 'new@example.com'], ['id' => $id]);

        self::assertSame(1, $affected);
        self::assertSame('new@example.com', $this->conform->find(['id' => $id])['email']);
    }

    public function testDelete(): void
    {
        $id = $this->conform->insert(['username' => 'alice', 'email' => 'a@example.com']);

        self::assertSame(1, $this->conform->delete(['id' => $id]));
        self::assertNull($this->conform->find(['id' => $id]));
    }

    public function testWhereWithOrderAndLimit(): void
    {
        $this->conform->bulkInsert([
            ['username' => 'alice', 'email' => 'a@example.com'],
            ['username' => 'bob', 'email' => 'b@example.com'],
            ['username' => 'carol', 'email' => 'c@example.com'],
        ]);

        $rows = $this->conform->where([], 'username DESC', 2);

        self::assertSame(['carol', 'bob'], array_column($rows, 'username'));
    }

    // ==================== Condition shapes ====================

    public function testConditionsCompileTheSameWayEverywhere(): void
    {
        $this->conform->bulkInsert([
            ['username' => 'alice', 'email' => 'a@example.com'],
            ['username' => 'bob', 'email' => 'b@example.com'],
            ['username' => 'carol', 'email' => 'c@example.com'],
        ]);

        self::assertSame(2, $this->conform->count(['username' => ['alice', 'carol']]));
        self::assertSame(1, $this->conform->count(['username' => ['not in', ['alice', 'bob']]]));
        self::assertSame(2, $this->conform->count(['id' => ['>=', 2]]));
        self::assertSame(2, $this->conform->count(['id' => ['between', [2, 3]]]));
        self::assertSame(1, $this->conform->count(['username' => ['like', '%ob%']]));
        self::assertSame(3, $this->conform->count(['email' => ['!=', null]]));
        self::assertSame(3, $this->conform->count(['username' => ['in', ['alice', 'bob', 'carol']]]));
    }

    public function testMalformedOperatorConditionIsRejected(): void
    {
        $this->conform->bulkInsert([
            ['username' => 'alice', 'email' => 'a@example.com'],
            ['username' => 'bob', 'email' => 'b@example.com'],
        ]);

        // This used to be read as `id IN ('=', 1, 2)` and quietly match two rows.
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('an operator condition is [operator, value]');

        $this->conform->where(['id' => ['=', 1, 2]]);
    }

    public function testInListWhoseFirstValueLooksLikeAnOperator(): void
    {
        $this->conform->bulkInsert([
            ['username' => 'in', 'email' => 'a@example.com'],
            ['username' => 'out', 'email' => 'b@example.com'],
        ]);

        // The first element decides the shape, so ['in', 'out'] would be read as
        // the `in` operator; the explicit form is how a caller asks for values.
        self::assertSame(2, $this->conform->count(['username' => ['in', ['in', 'out']]]));
    }

    public function testUnrecognisedSymbolOperatorIsRejectedWithHint(): void
    {
        // A short all-symbol string like "==", "===" or "<=>" is almost certainly
        // a typo, not a real value. Instead of silently compiling it as IN (and
        // returning nothing), we point the caller at the supported operators.
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('not a recognised operator');

        $this->conform->where(['id' => ['==', 1]]);
    }

    public function testUnrecognisedSymbolOperatorStillAllowsExplicitIn(): void
    {
        // The heuristic only fires on the implicit-IN form. If the caller
        // explicitly writes ['in', [...]] it goes through unchallenged, even
        // when one of the values happens to be a symbol-only string.
        $this->conform->insert(['username' => '==', 'email' => 'a@example.com']);

        self::assertSame(1, $this->conform->count(['username' => ['in', ['==', '!=']]]));
    }

    // ==================== Iteration ====================

    public function testCursorIterationWalksEveryRowInOrder(): void
    {
        for ($i = 1; $i <= 25; $i++) {
            $this->conform->insert(['username' => "user{$i}", 'email' => "user{$i}@example.com"]);
        }

        $this->conform->withPageSize(7);

        $ids = [];
        foreach ($this->conform as $row) {
            $ids[] = (int) $row['id'];
        }

        self::assertSame(range(1, 25), $ids);
    }

    public function testCursorResumeSkipsEarlierRows(): void
    {
        for ($i = 1; $i <= 6; $i++) {
            $this->conform->insert(['username' => "user{$i}", 'email' => "user{$i}@example.com"]);
        }

        $this->conform->withPageSize(2)->withCursorStart(4);

        $ids = [];
        foreach ($this->conform as $row) {
            $ids[] = (int) $row['id'];
        }

        self::assertSame([5, 6], $ids);
    }

    public function testCursorReportsTheRowBeingHandled(): void
    {
        $this->conform->bulkInsert([
            ['username' => 'alice', 'email' => 'a@example.com'],
            ['username' => 'bob', 'email' => 'b@example.com'],
        ]);
        $this->conform->withPageSize(1);

        $observed = [];
        foreach ($this->conform as $row) {
            $observed[] = (int) $this->conform->cursor();
        }

        self::assertSame([1, 2], $observed);
    }

    public function testCurrentThrowsBeforeRewind(): void
    {
        // Used to emit an "Undefined array key" warning and then a TypeError.
        $this->expectException(\OutOfBoundsException::class);
        $this->expectExceptionMessage('call rewind() first');

        $this->conform->current();
    }

    // ==================== Transactions ====================

    public function testTransactionCommitsOnSuccess(): void
    {
        $this->conform->withTransaction(function (): void {
            $this->conform->insert(['username' => 'alice', 'email' => 'a@example.com']);
            $this->conform->insert(['username' => 'bob', 'email' => 'b@example.com']);
        });

        self::assertSame(2, $this->conform->count());
    }

    public function testTransactionRollsBackAndRethrows(): void
    {
        try {
            $this->conform->withTransaction(function (): void {
                $this->conform->insert(['username' => 'alice', 'email' => 'a@example.com']);
                throw new \RuntimeException('migration failed');
            });
            self::fail('Expected the exception to propagate');
        } catch (\RuntimeException $e) {
            self::assertSame('migration failed', $e->getMessage());
        }

        self::assertSame(0, $this->conform->count());
    }
}
