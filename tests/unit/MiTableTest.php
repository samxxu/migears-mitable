<?php

declare(strict_types=1);

namespace MiGears\MiTable\Tests\Unit;

use PDO;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use MiGears\MiTable\MiTable;

#[CoversClass(MiTable::class)]
final class MiTableTest extends TestCase
{
    private PDO $pdo;
    private MiTable $table;

    protected function setUp(): void
    {
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->table = new MiTable($this->pdo, 'users');
    }

    // --- DDL ---

    public function testCreateAndExists(): void
    {
        self::assertFalse($this->table->exists());

        $this->table->create([
            'id' => 'INTEGER PRIMARY KEY AUTOINCREMENT',
            'username' => 'VARCHAR(50) NOT NULL',
            'email' => 'VARCHAR(255) NOT NULL',
        ]);

        self::assertTrue($this->table->exists());
    }

    public function testCreateWithEmptyColumnsThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->table->create([]);
    }

    public function testDrop(): void
    {
        $this->table->create([
            'id' => 'INTEGER PRIMARY KEY',
            'name' => 'TEXT',
        ]);

        self::assertTrue($this->table->exists());
        $this->table->drop();
        self::assertFalse($this->table->exists());
    }

    public function testGetName(): void
    {
        self::assertSame('users', $this->table->getName());
    }

    public function testGetPdo(): void
    {
        self::assertSame($this->pdo, $this->table->getPdo());
    }

    // --- Column operations ---

    public function testAddColumn(): void
    {
        $this->createUsersTable();
        $this->table->addColumn('age', 'INTEGER DEFAULT 0');

        $id = $this->table->insert(['username' => 'test', 'email' => 'test@example.com', 'age' => 25]);
        $row = $this->table->find(['id' => $id]);

        self::assertSame(25, $row['age']);
    }

    // --- Introspection ---

    public function testShowColumns(): void
    {
        $this->createUsersTable();

        $columns = $this->table->showColumns();

        self::assertSame(['id', 'username', 'email'], array_keys($columns));
        self::assertSame('VARCHAR(50)', $columns['username']['type']);
        self::assertFalse($columns['username']['nullable']);
        self::assertTrue($columns['id']['primary']);
        self::assertTrue($columns['id']['unique']);
        self::assertFalse($columns['username']['unique']);
    }

    public function testShowColumnsReportsSingleColumnUniqueIndex(): void
    {
        $this->createUsersTable();
        $this->table->addUniqueIndex('uniq_email', ['email']);

        $columns = $this->table->showColumns();

        self::assertTrue($columns['email']['unique']);
    }

    public function testShowColumnsReturnsEmptyForMissingTable(): void
    {
        self::assertSame([], $this->table->showColumns());
    }

    public function testShowColumnsReportsPrimaryKeyAsNotNullable(): void
    {
        $this->createUsersTable();

        $columns = $this->table->showColumns();

        self::assertFalse($columns['id']['nullable']);
    }

    public function testAddColumnWithAfterIsIgnoredOnSqlite(): void
    {
        $this->createUsersTable();

        // SQLite has no AFTER clause; the argument must not produce invalid SQL.
        $this->table->addColumn('phone', 'VARCHAR(20) NULL', 'username');

        self::assertArrayHasKey('phone', $this->table->showColumns());
    }

    // --- Index introspection ---

    public function testShowIndexesReportsNamedIndexes(): void
    {
        $this->createUsersTable();
        $this->table->addIndex('idx_username', ['username']);
        $this->table->addUniqueIndex('uniq_email', ['email']);

        $indexes = $this->table->showIndexes();

        self::assertSame(['username'], $indexes['idx_username']['columns']);
        self::assertFalse($indexes['idx_username']['unique']);
        self::assertFalse($indexes['idx_username']['primary']);

        self::assertSame(['email'], $indexes['uniq_email']['columns']);
        self::assertTrue($indexes['uniq_email']['unique']);
    }

    public function testShowIndexesReportsCompositeColumnsInIndexOrder(): void
    {
        $this->createUsersTable();
        $this->table->addUniqueIndex('uniq_name_email', ['username', 'email']);

        // showColumns() cannot see this: the uniqueness spans two columns.
        self::assertSame(['username', 'email'], $this->table->showIndexes()['uniq_name_email']['columns']);
    }

    public function testShowIndexesReflectsDroppedIndex(): void
    {
        $this->createUsersTable();
        $this->table->addIndex('idx_username', ['username']);
        self::assertArrayHasKey('idx_username', $this->table->showIndexes());

        $this->table->dropIndex('idx_username');

        self::assertArrayNotHasKey('idx_username', $this->table->showIndexes());
    }

    public function testShowIndexesReturnsEmptyForMissingTable(): void
    {
        self::assertSame([], $this->table->showIndexes());
    }

    public function testIndexMigrationIsIdempotentAcrossRuns(): void
    {
        $this->createUsersTable();

        // The scenario this method exists for: look the index up by name, then
        // decide between adding, replacing and leaving it alone.
        $migrate = function (): string {
            $indexes = $this->table->showIndexes();

            if (!isset($indexes['idx_email'])) {
                $this->table->addIndex('idx_email', ['email']);

                return 'added';
            }

            if (!$indexes['idx_email']['unique']) {
                $this->table->dropIndex('idx_email');
                $this->table->addUniqueIndex('idx_email', ['email']);

                return 'upgraded';
            }

            return 'skipped';
        };

        self::assertSame('added', $migrate());
        self::assertSame('upgraded', $migrate());
        self::assertSame('skipped', $migrate());
    }

    // --- CRUD ---

    public function testInsertReturnsId(): void
    {
        $this->createUsersTable();

        $id = $this->table->insert([
            'username' => 'alice',
            'email' => 'alice@example.com',
        ]);

        self::assertSame('1', $id);
    }

    public function testFindReturnsRow(): void
    {
        $this->createUsersTable();
        $this->table->insert(['username' => 'alice', 'email' => 'alice@example.com']);

        $row = $this->table->find(['username' => 'alice']);

        self::assertNotNull($row);
        self::assertSame('alice', $row['username']);
        self::assertSame('alice@example.com', $row['email']);
    }

    public function testFindReturnsNullWhenNotFound(): void
    {
        $this->createUsersTable();
        $row = $this->table->find(['username' => 'nonexistent']);
        self::assertNull($row);
    }

    public function testWhereReturnsAllMatching(): void
    {
        $this->createUsersTable();
        $this->table->insert(['username' => 'alice', 'email' => 'alice@example.com']);
        $this->table->insert(['username' => 'bob', 'email' => 'bob@example.com']);
        $this->table->insert(['username' => 'charlie', 'email' => 'charlie@example.com']);

        $rows = $this->table->where(['email' => 'bob@example.com']);
        self::assertCount(1, $rows);
        self::assertSame('bob', $rows[0]['username']);
    }

    public function testWhereWithOrderAndLimit(): void
    {
        $this->createUsersTable();
        $this->table->insert(['username' => 'alice', 'email' => 'alice@example.com']);
        $this->table->insert(['username' => 'bob', 'email' => 'bob@example.com']);
        $this->table->insert(['username' => 'charlie', 'email' => 'charlie@example.com']);

        $rows = $this->table->where([], 'username DESC', 2);
        self::assertCount(2, $rows);
        self::assertSame('charlie', $rows[0]['username']);
        self::assertSame('bob', $rows[1]['username']);
    }

    public function testWhereEmptyReturnsAll(): void
    {
        $this->createUsersTable();
        $this->table->insert(['username' => 'alice', 'email' => 'a@b.com']);
        $this->table->insert(['username' => 'bob', 'email' => 'b@b.com']);

        self::assertCount(2, $this->table->where());
    }

    public function testUpdate(): void
    {
        $this->createUsersTable();
        $id = $this->table->insert(['username' => 'alice', 'email' => 'old@example.com']);

        $count = $this->table->update(
            ['email' => 'new@example.com'],
            ['id' => $id],
        );

        self::assertSame(1, $count);
        $row = $this->table->find(['id' => $id]);
        self::assertSame('new@example.com', $row['email']);
    }

    public function testUpdateWithEmptyDataReturnsZero(): void
    {
        $this->createUsersTable();
        $count = $this->table->update([], ['id' => 1]);
        self::assertSame(0, $count);
    }

    public function testDelete(): void
    {
        $this->createUsersTable();
        $id = $this->table->insert(['username' => 'alice', 'email' => 'alice@example.com']);

        $count = $this->table->delete(['id' => $id]);

        self::assertSame(1, $count);
        self::assertNull($this->table->find(['id' => $id]));
    }

    public function testCount(): void
    {
        $this->createUsersTable();
        self::assertSame(0, $this->table->count());

        $this->table->insert(['username' => 'alice', 'email' => 'a@b.com']);
        $this->table->insert(['username' => 'bob', 'email' => 'b@b.com']);

        self::assertSame(2, $this->table->count());
        self::assertSame(1, $this->table->count(['username' => 'alice']));
    }

    public function testBulkInsert(): void
    {
        $this->createUsersTable();

        $count = $this->table->bulkInsert([
            ['username' => 'alice', 'email' => 'alice@example.com'],
            ['username' => 'bob', 'email' => 'bob@example.com'],
            ['username' => 'charlie', 'email' => 'charlie@example.com'],
        ]);

        self::assertSame(3, $count);
        self::assertSame(3, $this->table->count());
    }

    public function testBulkInsertEmptyArray(): void
    {
        $this->createUsersTable();
        self::assertSame(0, $this->table->bulkInsert([]));
    }

    // --- Where conditions ---

    public function testWhereInCondition(): void
    {
        $this->createUsersTable();
        $this->seedUsers(['alice', 'bob', 'charlie']);

        $rows = $this->table->where(['username' => ['alice', 'charlie']]);

        self::assertSame(['alice', 'charlie'], array_column($rows, 'username'));
    }

    public function testWhereInOperatorCondition(): void
    {
        $this->createUsersTable();
        $this->seedUsers(['alice', 'bob', 'charlie']);

        $rows = $this->table->where(['username' => ['in', ['bob']]]);

        self::assertSame(['bob'], array_column($rows, 'username'));
    }

    public function testWhereNotInCondition(): void
    {
        $this->createUsersTable();
        $this->seedUsers(['alice', 'bob', 'charlie']);

        $rows = $this->table->where(['username' => ['not in', ['alice', 'bob']]]);

        self::assertSame(['charlie'], array_column($rows, 'username'));
    }

    public function testWhereRangeCondition(): void
    {
        $this->createUsersTable();
        $this->seedUsers(['alice', 'bob', 'charlie']);

        $rows = $this->table->where(['id' => ['>=', 2]], 'id ASC');

        self::assertSame([2, 3], array_column($rows, 'id'));
    }

    public function testWhereBetweenCondition(): void
    {
        $this->createUsersTable();
        $this->seedUsers(['alice', 'bob', 'charlie', 'dave']);

        $rows = $this->table->where(['id' => ['between', [2, 3]]], 'id ASC');

        self::assertSame(['bob', 'charlie'], array_column($rows, 'username'));
    }

    public function testWhereNotBetweenCondition(): void
    {
        $this->createUsersTable();
        $this->seedUsers(['alice', 'bob', 'charlie']);

        $rows = $this->table->where(['id' => ['not between', [1, 2]]], 'id ASC');

        self::assertSame(['charlie'], array_column($rows, 'username'));
    }

    public function testWhereLikeCondition(): void
    {
        $this->createUsersTable();
        $this->table->insert(['username' => 'john smith', 'email' => 'a@b.com']);
        $this->table->insert(['username' => 'john', 'email' => 'b@b.com']);
        $this->table->insert(['username' => 'jane', 'email' => 'c@b.com']);

        $rows = $this->table->where(['username' => ['like', '% %']]);

        self::assertCount(1, $rows);
        self::assertSame('john smith', $rows[0]['username']);
    }

    public function testWhereNullCondition(): void
    {
        $this->createUsersTable();
        $this->table->addColumn('bio', 'TEXT');
        $this->table->insert(['username' => 'alice', 'email' => 'a@b.com']);
        $this->table->insert(['username' => 'bob', 'email' => 'b@b.com', 'bio' => 'hello']);

        self::assertCount(1, $this->table->where(['bio' => null]));
        self::assertCount(1, $this->table->where(['bio' => ['!=', null]]));
    }

    public function testWhereCompoundConditions(): void
    {
        $this->createUsersTable();
        $this->seedUsers(['alice', 'bob', 'charlie']);

        $rows = $this->table->where([
            'username' => ['in', ['alice', 'bob']],
            'id' => ['>', 1],
        ]);

        self::assertSame(['bob'], array_column($rows, 'username'));
    }

    public function testWhereEmptyListThrows(): void
    {
        $this->createUsersTable();

        $this->expectException(\InvalidArgumentException::class);
        $this->table->where(['username' => []]);
    }

    public function testUpdateWithInCondition(): void
    {
        $this->createUsersTable();
        $this->seedUsers(['alice', 'bob', 'charlie']);

        $count = $this->table->update(['email' => 'team@example.com'], ['username' => ['alice', 'bob']]);

        self::assertSame(2, $count);
        self::assertSame(2, $this->table->count(['email' => 'team@example.com']));
    }

    public function testDeleteWithRangeCondition(): void
    {
        $this->createUsersTable();
        $this->seedUsers(['alice', 'bob', 'charlie']);

        $count = $this->table->delete(['id' => ['<', 3]]);

        self::assertSame(2, $count);
        self::assertSame(1, $this->table->count());
    }

    public function testCountWithInCondition(): void
    {
        $this->createUsersTable();
        $this->seedUsers(['alice', 'bob', 'charlie']);

        self::assertSame(2, $this->table->count(['username' => ['alice', 'bob']]));
    }

    // --- Iterator ---

    public function testIterator(): void
    {
        $this->createUsersTable();
        for ($i = 1; $i <= 5; $i++) {
            $this->table->insert([
                'username' => "user{$i}",
                'email' => "user{$i}@example.com",
            ]);
        }

        $count = 0;
        $usernames = [];
        foreach ($this->table as $row) {
            $usernames[] = $row['username'];
            $count++;
        }

        self::assertSame(5, $count);
        self::assertSame(['user1', 'user2', 'user3', 'user4', 'user5'], $usernames);
    }

    public function testIteratorWithSmallPageSize(): void
    {
        $this->createUsersTable();
        for ($i = 1; $i <= 7; $i++) {
            $this->table->insert([
                'username' => "user{$i}",
                'email' => "user{$i}@example.com",
            ]);
        }

        $this->table->withPageSize(3);

        $count = 0;
        foreach ($this->table as $row) {
            $count++;
        }

        self::assertSame(7, $count);
    }

    public function testIteratorEmptyTable(): void
    {
        $this->createUsersTable();

        $count = 0;
        foreach ($this->table as $row) {
            $count++;
        }

        self::assertSame(0, $count);
    }

    public function testIteratorKey(): void
    {
        $this->createUsersTable();
        $this->table->insert(['username' => 'a', 'email' => 'a@b.com']);
        $this->table->insert(['username' => 'b', 'email' => 'b@b.com']);

        $keys = [];
        foreach ($this->table as $key => $row) {
            $keys[] = $key;
        }

        self::assertSame([0, 1], $keys);
    }

    public function testIteratorWalksEveryRowAcrossManyPages(): void
    {
        $this->createUsersTable();
        for ($i = 1; $i <= 250; $i++) {
            $this->table->insert(['username' => "user{$i}", 'email' => "user{$i}@example.com"]);
        }

        $this->table->withPageSize(50);

        $ids = [];
        foreach ($this->table as $row) {
            $ids[] = (int) $row['id'];
        }

        self::assertCount(250, $ids);
        self::assertSame(range(1, 250), $ids);
    }

    public function testIteratorWithPageSizeOne(): void
    {
        $this->createUsersTable();
        $this->seedUsers(['alice', 'bob', 'charlie']);

        $this->table->withPageSize(1);

        $ids = [];
        foreach ($this->table as $row) {
            $ids[] = (int) $row['id'];
        }

        self::assertSame([1, 2, 3], $ids);
    }

    public function testCursorIterationDoesNotSkipAfterEarlierRowsAreRemoved(): void
    {
        $this->createUsersTable();
        for ($i = 1; $i <= 5; $i++) {
            $this->table->insert(['username' => "user{$i}", 'email' => "user{$i}@example.com"]);
        }

        $this->table->withPageSize(1);

        $ids = [];
        foreach ($this->table as $row) {
            $ids[] = (int) $row['id'];

            if ((int) $row['id'] === 1) {
                // Removing a row behind the cursor would shift offset paging and skip a row.
                $this->table->delete(['id' => 1]);
            }
        }

        self::assertSame([1, 2, 3, 4, 5], $ids);
    }

    public function testIteratorFallsBackToOffsetPagingWithoutPrimaryKey(): void
    {
        $this->table->create([
            'username' => 'VARCHAR(50) NOT NULL',
            'email' => 'VARCHAR(255) NOT NULL',
        ]);

        for ($i = 1; $i <= 5; $i++) {
            $this->table->insert(['username' => "user{$i}", 'email' => "user{$i}@example.com"]);
        }

        $this->table->withPageSize(2);

        $count = 0;
        foreach ($this->table as $row) {
            $count++;
        }

        self::assertSame(5, $count);
    }

    public function testWithCursorKeyUsesExplicitColumn(): void
    {
        $this->createUsersTable();
        $this->table->addUniqueIndex('uniq_email', ['email']);

        foreach (['charlie', 'alice', 'bob'] as $name) {
            $this->table->insert(['username' => $name, 'email' => "{$name}@example.com"]);
        }

        $this->table->withCursorKey('email')->withPageSize(1);

        $emails = [];
        foreach ($this->table as $row) {
            $emails[] = $row['email'];
        }

        self::assertSame(['alice@example.com', 'bob@example.com', 'charlie@example.com'], $emails);
    }

    public function testWithPageSizeReturnsSelf(): void
    {
        $result = $this->table->withPageSize(50);
        self::assertSame($this->table, $result);
    }

    public function testWithCursorKeyReturnsSelf(): void
    {
        $result = $this->table->withCursorKey('id');
        self::assertSame($this->table, $result);
    }

    // --- Resumable iteration ---

    public function testCursorIsNullBeforeIteration(): void
    {
        $this->createUsersTable();

        self::assertNull($this->table->cursor());
    }

    public function testCursorTracksTheRowBeingHandled(): void
    {
        $this->createUsersTable();
        $this->seedUsers(['alice', 'bob', 'charlie']);
        $this->table->withPageSize(2);

        $observed = [];
        foreach ($this->table as $row) {
            $observed[] = [(int) $row['id'], (int) $this->table->cursor()];
        }

        self::assertSame([[1, 1], [2, 2], [3, 3]], $observed);
    }

    public function testCursorIsNullWithoutCursorKey(): void
    {
        $this->table->create(['label' => 'VARCHAR(20) NOT NULL']);
        $this->table->insert(['label' => 'a']);

        foreach ($this->table as $row) {
            self::assertNull($this->table->cursor());
        }
    }

    public function testCursorUsesExplicitCursorKey(): void
    {
        $this->createUsersTable();
        $this->seedUsers(['alice', 'bob']);

        $this->table->withCursorKey('username')->withPageSize(1);

        $cursors = [];
        foreach ($this->table as $row) {
            $cursors[] = $this->table->cursor();
        }

        self::assertSame(['alice', 'bob'], $cursors);
    }

    public function testWithCursorStartSkipsRowsUpToGivenValue(): void
    {
        $this->createUsersTable();
        $this->seedUsers(['alice', 'bob', 'charlie', 'dave']);

        $this->table->withCursorStart(2);

        $ids = [];
        foreach ($this->table as $row) {
            $ids[] = (int) $row['id'];
        }

        self::assertSame([3, 4], $ids);
    }

    public function testFirstPageIsFetchedFromTheCursorStart(): void
    {
        $this->createUsersTable();
        for ($i = 1; $i <= 10; $i++) {
            $this->table->insert(['username' => "user{$i}", 'email' => "user{$i}@example.com"]);
        }

        // More rows remain than fit in one page, so the very first query has to
        // honour the start point rather than only later pages.
        $this->table->withPageSize(2)->withCursorStart(7);

        $ids = [];
        foreach ($this->table as $row) {
            $ids[] = (int) $row['id'];
        }

        self::assertSame([8, 9, 10], $ids);
    }

    public function testInterruptedRunResumesWithoutSkippingOrRepeating(): void
    {
        $this->createUsersTable();
        for ($i = 1; $i <= 10; $i++) {
            $this->table->insert(['username' => "user{$i}", 'email' => "user{$i}@example.com"]);
        }

        $this->table->withPageSize(3);

        $processed = [];
        $checkpoint = null;

        try {
            foreach ($this->table as $row) {
                if (count($processed) === 4) {
                    throw new \RuntimeException('migration interrupted');
                }

                $processed[] = (int) $row['id'];
                $checkpoint = $this->table->cursor();
            }
            self::fail('Expected the run to be interrupted');
        } catch (\RuntimeException $e) {
            self::assertSame('migration interrupted', $e->getMessage());
        }

        self::assertSame([1, 2, 3, 4], $processed);
        self::assertSame(4, (int) $checkpoint);

        // Resuming must continue at row 5 and touch nothing twice.
        $this->table->withCursorStart($checkpoint);

        foreach ($this->table as $row) {
            $processed[] = (int) $row['id'];
        }

        self::assertSame(range(1, 10), $processed);
    }

    public function testRowThatFailedIsRetriedRatherThanSkipped(): void
    {
        $this->createUsersTable();
        $this->seedUsers(['alice', 'bob', 'charlie']);
        $this->table->withPageSize(1);

        $checkpoint = null;

        try {
            foreach ($this->table as $row) {
                if ((int) $row['id'] === 2) {
                    throw new \RuntimeException('failed while handling row 2');
                }

                $checkpoint = $this->table->cursor();
            }
            self::fail('Expected the run to fail');
        } catch (\RuntimeException) {
        }

        // Row 2 never completed, so the checkpoint still points at row 1.
        self::assertSame(1, (int) $checkpoint);

        $this->table->withCursorStart($checkpoint);

        $ids = [];
        foreach ($this->table as $row) {
            $ids[] = (int) $row['id'];
        }

        self::assertSame([2, 3], $ids);
    }

    public function testWithCursorStartOnKeylessTableThrows(): void
    {
        $this->table->create(['label' => 'VARCHAR(20) NOT NULL']);
        $this->table->insert(['label' => 'a']);

        $this->table->withCursorStart(5);

        $this->expectException(\InvalidArgumentException::class);

        foreach ($this->table as $row) {
        }
    }

    public function testWithCursorStartOnCompositeKeyTableThrows(): void
    {
        $this->pdo->exec(
            'CREATE TABLE users (tenant_id INTEGER NOT NULL, user_id INTEGER NOT NULL, '
            . 'label TEXT NOT NULL, PRIMARY KEY (tenant_id, user_id))'
        );
        $this->table->insert(['tenant_id' => 1, 'user_id' => 1, 'label' => 'a']);

        $this->table->withCursorStart(1);

        $this->expectException(\InvalidArgumentException::class);

        foreach ($this->table as $row) {
        }
    }

    public function testWithCursorStartNullClearsTheStartPoint(): void
    {
        $this->createUsersTable();
        $this->seedUsers(['alice', 'bob', 'charlie']);

        $this->table->withCursorStart(2);
        $this->table->withCursorStart(null);

        $ids = [];
        foreach ($this->table as $row) {
            $ids[] = (int) $row['id'];
        }

        self::assertSame([1, 2, 3], $ids);
    }

    // --- Transactions ---

    public function testWithTransactionCommits(): void
    {
        $this->createUsersTable();

        $this->table->withTransaction(function () {
            $this->table->insert(['username' => 'alice', 'email' => 'a@b.com']);
            $this->table->insert(['username' => 'bob', 'email' => 'b@b.com']);
        });

        self::assertSame(2, $this->table->count());
        self::assertFalse($this->pdo->inTransaction());
    }

    public function testWithTransactionRollsBackOnException(): void
    {
        $this->createUsersTable();

        try {
            $this->table->withTransaction(function () {
                $this->table->insert(['username' => 'alice', 'email' => 'a@b.com']);
                throw new \RuntimeException('migration failed');
            });
            self::fail('Expected exception to propagate');
        } catch (\RuntimeException $e) {
            self::assertSame('migration failed', $e->getMessage());
        }

        self::assertSame(0, $this->table->count());
        self::assertFalse($this->pdo->inTransaction());
    }

    public function testWithTransactionReturnsCallbackValue(): void
    {
        $this->createUsersTable();

        $id = $this->table->withTransaction(fn() => $this->table->insert([
            'username' => 'alice',
            'email' => 'a@b.com',
        ]));

        self::assertSame('1', $id);
    }

    // --- Index operations (SQLite compatible subset) ---

    public function testAddIndex(): void
    {
        $this->createUsersTable();
        $this->table->addIndex('idx_username', ['username']);

        // Verify by checking we can query
        $this->table->insert(['username' => 'test', 'email' => 'test@example.com']);
        $row = $this->table->find(['username' => 'test']);
        self::assertNotNull($row);
    }

    // --- Version constant ---

    public function testVersionConstant(): void
    {
        self::assertSame('2.0.0', MiTable::VERSION);
    }

    // --- Helpers ---

    private function createUsersTable(): void
    {
        $this->table->create([
            'id' => 'INTEGER PRIMARY KEY AUTOINCREMENT',
            'username' => 'VARCHAR(50) NOT NULL',
            'email' => 'VARCHAR(255) NOT NULL',
        ]);
    }

    /** @param list<string> $names */
    private function seedUsers(array $names): void
    {
        foreach ($names as $name) {
            $this->table->insert(['username' => $name, 'email' => "{$name}@example.com"]);
        }
    }
}
