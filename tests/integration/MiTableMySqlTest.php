<?php

declare(strict_types=1);

namespace MiGears\MiTable\Tests\Integration;

use PDO;
use PHPUnit\Framework\Attributes\CoversClass;
use MiGears\MiTable\MiTable;
use MiGears\MiTable\Tests\MySqlTestCase;

/**
 * Integration tests against a real MySQL/MariaDB server.
 *
 * These cover the driver-specific paths the SQLite unit suite cannot reach:
 * information_schema lookups, SHOW COLUMNS / SHOW KEYS introspection, MySQL
 * table options, AFTER positioning, USING index types, and MySQL's rowCount
 * semantics.
 *
 * Skipped automatically when no server is reachable.
 */
#[CoversClass(MiTable::class)]
final class MiTableMySqlTest extends MySqlTestCase
{
    private MiTable $table;

    protected function setUp(): void
    {
        parent::setUp();
        $this->table = new MiTable($this->pdo, 'users');
    }

    // ==================== DDL ====================

    public function testCreateAppliesMysqlTableOptions(): void
    {
        $this->createUsersTable();

        $row = $this->pdo->query(
            'SELECT ENGINE, TABLE_COLLATION FROM information_schema.TABLES '
            . "WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users'"
        )->fetch();

        self::assertSame('InnoDB', $row['ENGINE']);
        self::assertSame('utf8mb4_unicode_ci', $row['TABLE_COLLATION']);
    }

    public function testCreateHonoursCustomEngineAndCharset(): void
    {
        $this->table->create([
            'id' => 'INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY',
            'label' => 'VARCHAR(20) NOT NULL',
        ], 'InnoDB', 'utf8mb4', 'utf8mb4_general_ci');

        $collation = $this->pdo->query(
            'SELECT TABLE_COLLATION FROM information_schema.TABLES '
            . "WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users'"
        )->fetchColumn();

        self::assertSame('utf8mb4_general_ci', $collation);
    }

    public function testExistsReadsInformationSchema(): void
    {
        self::assertFalse($this->table->exists());

        $this->createUsersTable();

        self::assertTrue($this->table->exists());
    }

    public function testExistsIsFalseAfterDrop(): void
    {
        $this->createUsersTable();
        $this->table->drop();

        self::assertFalse($this->table->exists());
    }

    public function testRenameReturnsWorkingInstance(): void
    {
        $this->createUsersTable();

        $renamed = $this->table->rename('app_users');

        self::assertSame('app_users', $renamed->getName());
        self::assertTrue($renamed->exists());
        self::assertFalse($this->table->exists());
    }

    public function testTruncateRemovesRowsButKeepsTable(): void
    {
        $this->createUsersTable();
        $this->seedUsers(['alice', 'bob']);

        $this->table->truncate();

        self::assertSame(0, $this->table->count());
        self::assertTrue($this->table->exists());
    }

    // ==================== Introspection ====================

    public function testShowColumnsReportsMysqlMetadata(): void
    {
        $this->createUsersTable();

        $columns = $this->table->showColumns();

        self::assertSame(['id', 'username', 'email'], array_keys($columns));
        self::assertSame('varchar(50)', $columns['username']['type']);
        self::assertFalse($columns['username']['nullable']);
        self::assertTrue($columns['id']['primary']);
        self::assertTrue($columns['id']['unique']);
        self::assertFalse($columns['email']['unique']);
    }

    public function testShowColumnsReportsNullableAndDefault(): void
    {
        $this->table->create([
            'id' => 'INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY',
            'nickname' => "VARCHAR(50) NULL DEFAULT 'anon'",
        ]);

        $columns = $this->table->showColumns();

        self::assertTrue($columns['nickname']['nullable']);
        self::assertSame('anon', $columns['nickname']['default']);
        self::assertFalse($columns['id']['nullable']);
    }

    public function testShowColumnsReportsUniqueIndexFlag(): void
    {
        $this->createUsersTable();
        $this->table->addUniqueIndex('uniq_email', ['email']);

        self::assertTrue($this->table->showColumns()['email']['unique']);
    }

    public function testShowColumnsReturnsEmptyForMissingTable(): void
    {
        self::assertSame([], $this->table->showColumns());
    }

    // ==================== Index introspection ====================

    public function testShowIndexesReportsPrimaryKey(): void
    {
        $this->createUsersTable();

        $indexes = $this->table->showIndexes();

        self::assertArrayHasKey('PRIMARY', $indexes);
        self::assertSame(['id'], $indexes['PRIMARY']['columns']);
        self::assertTrue($indexes['PRIMARY']['primary']);
        self::assertTrue($indexes['PRIMARY']['unique']);
        self::assertSame('BTREE', $indexes['PRIMARY']['type']);
    }

    public function testShowIndexesReportsNamedAndCompositeIndexes(): void
    {
        $this->createUsersTable();
        $this->table->addIndex('idx_username', ['username']);
        $this->table->addUniqueIndex('uniq_name_email', ['username', 'email']);

        $indexes = $this->table->showIndexes();

        self::assertSame(['username'], $indexes['idx_username']['columns']);
        self::assertFalse($indexes['idx_username']['unique']);

        // Multi-column index: invisible to showColumns(), visible here.
        self::assertSame(['username', 'email'], $indexes['uniq_name_email']['columns']);
        self::assertTrue($indexes['uniq_name_email']['unique']);
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

    public function testIndexMigrationIsIdempotentOnMysql(): void
    {
        $this->createUsersTable();

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
        self::assertTrue($this->table->showIndexes()['idx_email']['unique']);
    }

    // ==================== Columns ====================

    public function testAddColumnWithAfterPositionsColumn(): void
    {
        $this->createUsersTable();

        $this->table->addColumn('phone', 'VARCHAR(20) NULL', 'username');

        $columns = $this->table->showColumns();

        // Without AFTER the column would land last; positioning it after
        // `username` must put it third and push email to fourth.
        self::assertSame(['id', 'username', 'phone', 'email'], array_keys($columns));
    }

    public function testModifyColumnChangesType(): void
    {
        $this->createUsersTable();

        $this->table->modifyColumn('username', 'VARCHAR(120) NOT NULL');

        self::assertSame('varchar(120)', $this->table->showColumns()['username']['type']);
    }

    public function testRenameColumnKeepsData(): void
    {
        $this->createUsersTable();
        $this->table->insert(['username' => 'alice', 'email' => 'alice@example.com']);

        $this->table->renameColumn('username', 'handle', 'VARCHAR(50) NOT NULL');

        $columns = $this->table->showColumns();
        self::assertArrayHasKey('handle', $columns);
        self::assertArrayNotHasKey('username', $columns);
        self::assertSame('alice', $this->table->find(['handle' => 'alice'])['handle']);
    }

    public function testDropColumn(): void
    {
        $this->createUsersTable();

        $this->table->dropColumn('email');

        self::assertArrayNotHasKey('email', $this->table->showColumns());
    }

    // ==================== Indexes ====================

    public function testAddAndDropIndex(): void
    {
        $this->createUsersTable();

        $this->table->addIndex('idx_username', ['username']);
        self::assertTrue($this->indexExists('idx_username'));

        $this->table->dropIndex('idx_username');
        self::assertFalse($this->indexExists('idx_username'));
    }

    public function testAddIndexWithUsingType(): void
    {
        $this->createUsersTable();

        $this->table->addIndex('idx_username', ['username'], 'BTREE');

        self::assertTrue($this->indexExists('idx_username'));
    }

    public function testAddUniqueIndexIsActuallyUnique(): void
    {
        $this->createUsersTable();
        $this->table->addUniqueIndex('uniq_username', ['username']);

        $nonUnique = $this->pdo->query(
            'SELECT NON_UNIQUE FROM information_schema.STATISTICS '
            . "WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND INDEX_NAME = 'uniq_username' LIMIT 1"
        )->fetchColumn();

        self::assertSame(0, (int) $nonUnique);

        $this->table->insert(['username' => 'alice', 'email' => 'a@example.com']);

        $this->expectException(\PDOException::class);
        $this->table->insert(['username' => 'alice', 'email' => 'b@example.com']);
    }

    public function testAddAndDropPrimaryKey(): void
    {
        $this->table->create([
            'id' => 'INT UNSIGNED NOT NULL',
            'label' => 'VARCHAR(20) NOT NULL',
        ]);

        $this->table->addPrimaryKey(['id']);
        self::assertTrue($this->table->showColumns()['id']['primary']);

        $this->table->dropPrimaryKey();
        self::assertFalse($this->table->showColumns()['id']['primary']);
    }

    // ==================== CRUD on MySQL ====================

    public function testInsertBulkInsertAndCount(): void
    {
        $this->createUsersTable();

        $id = $this->table->insert(['username' => 'alice', 'email' => 'alice@example.com']);
        self::assertSame('1', $id);

        $affected = $this->table->bulkInsert([
            ['username' => 'bob', 'email' => 'bob@example.com'],
            ['username' => 'charlie', 'email' => 'charlie@example.com'],
        ]);

        self::assertSame(2, $affected);
        self::assertSame(3, $this->table->count());
    }

    public function testUpdateWithInCondition(): void
    {
        $this->createUsersTable();
        $this->seedUsers(['alice', 'bob', 'charlie']);

        $affected = $this->table->update(
            ['email' => 'team@example.com'],
            ['username' => ['alice', 'bob']]
        );

        self::assertSame(2, $affected);
        self::assertSame(2, $this->table->count(['email' => 'team@example.com']));
    }

    public function testDeleteWithRangeCondition(): void
    {
        $this->createUsersTable();
        $this->seedUsers(['alice', 'bob', 'charlie']);

        $deleted = $this->table->delete(['id' => ['<', 3]]);

        self::assertSame(2, $deleted);
        self::assertSame(1, $this->table->count());
    }

    public function testWhereConditionsOnMysql(): void
    {
        $this->createUsersTable();
        $this->table->addColumn('phone', 'VARCHAR(20) NULL');
        $this->seedUsers(['alice', 'bob', 'charlie']);

        // BETWEEN
        self::assertSame(
            ['bob', 'charlie'],
            array_column($this->table->where(['id' => ['between', [2, 3]]], 'id ASC'), 'username')
        );

        // LIKE
        self::assertSame(
            ['bob'],
            array_column($this->table->where(['username' => ['like', 'b%b']]), 'username')
        );

        // IS NULL / IS NOT NULL
        self::assertSame(3, $this->table->count(['phone' => null]));
        self::assertSame(0, $this->table->count(['phone' => ['!=', null]]));

        // NOT IN
        self::assertSame(
            ['charlie'],
            array_column($this->table->where(['username' => ['not in', ['alice', 'bob']]]), 'username')
        );
    }

    public function testRowCountReportsChangedRowsNotMatchedRows(): void
    {
        $this->createUsersTable();
        $id = $this->table->insert(['username' => 'alice', 'email' => 'alice@example.com']);

        // MySQL reports rows actually changed, so writing an identical value
        // reports 0 even though the row matched. This is why rowCount() is not
        // a reliable "did the migration work" signal.
        $unchanged = $this->table->update(['email' => 'alice@example.com'], ['id' => $id]);

        self::assertSame(0, $unchanged);
        self::assertSame(1, $this->table->count(['id' => $id]));
    }

    // ==================== Iteration on MySQL ====================

    public function testCursorIterationWalksEveryRowInOrder(): void
    {
        $this->createUsersTable();

        for ($i = 1; $i <= 120; $i++) {
            $this->table->insert(['username' => "user{$i}", 'email' => "user{$i}@example.com"]);
        }

        $this->table->withPageSize(25);

        $ids = [];
        foreach ($this->table as $row) {
            $ids[] = (int) $row['id'];
        }

        self::assertCount(120, $ids);
        self::assertSame(range(1, 120), $ids);
    }

    public function testCursorIterationDoesNotSkipAfterEarlierRowsAreRemoved(): void
    {
        $this->createUsersTable();
        $this->seedUsers(['alice', 'bob', 'charlie', 'dave', 'erin']);

        $this->table->withPageSize(1);

        $ids = [];
        foreach ($this->table as $row) {
            $ids[] = (int) $row['id'];

            if ((int) $row['id'] === 1) {
                $this->table->delete(['id' => 1]);
            }
        }

        self::assertSame([1, 2, 3, 4, 5], $ids);
    }

    public function testCompositePrimaryKeyFallsBackToOffsetPaging(): void
    {
        $this->table->create([
            'tenant_id' => 'INT UNSIGNED NOT NULL',
            'user_id' => 'INT UNSIGNED NOT NULL',
            'label' => 'VARCHAR(20) NOT NULL',
        ]);
        $this->table->addPrimaryKey(['tenant_id', 'user_id']);

        for ($i = 1; $i <= 5; $i++) {
            $this->table->insert(['tenant_id' => 1, 'user_id' => $i, 'label' => "row{$i}"]);
        }

        $this->table->withPageSize(2);

        $labels = [];
        foreach ($this->table as $row) {
            $labels[] = $row['label'];
        }

        self::assertCount(5, $labels);
    }

    public function testKeylessTableFallsBackToOffsetPaging(): void
    {
        $this->table->create([
            'label' => 'VARCHAR(20) NOT NULL',
        ]);

        for ($i = 1; $i <= 5; $i++) {
            $this->table->insert(['label' => "row{$i}"]);
        }

        $this->table->withPageSize(2);

        $count = 0;
        foreach ($this->table as $row) {
            $count++;
        }

        self::assertSame(5, $count);
    }

    // ==================== Resumable iteration ====================

    public function testInterruptedRunResumesWithoutSkippingOrRepeating(): void
    {
        $this->createUsersTable();
        for ($i = 1; $i <= 30; $i++) {
            $this->table->insert(['username' => "user{$i}", 'email' => "user{$i}@example.com"]);
        }

        $this->table->withPageSize(7);

        $processed = [];
        $checkpoint = null;

        try {
            foreach ($this->table as $row) {
                if (count($processed) === 12) {
                    throw new \RuntimeException('migration interrupted');
                }

                $processed[] = (int) $row['id'];
                $checkpoint = $this->table->cursor();
            }
            self::fail('Expected the run to be interrupted');
        } catch (\RuntimeException) {
        }

        self::assertSame(range(1, 12), $processed);

        $this->table->withCursorStart($checkpoint);

        foreach ($this->table as $row) {
            $processed[] = (int) $row['id'];
        }

        self::assertSame(range(1, 30), $processed);
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

    public function testWithCursorStartOnCompositeKeyTableThrows(): void
    {
        $this->table->create([
            'tenant_id' => 'INT UNSIGNED NOT NULL',
            'user_id' => 'INT UNSIGNED NOT NULL',
            'label' => 'VARCHAR(20) NOT NULL',
        ]);
        $this->table->addPrimaryKey(['tenant_id', 'user_id']);
        $this->table->insert(['tenant_id' => 1, 'user_id' => 1, 'label' => 'a']);

        $this->table->withCursorStart(1);

        $this->expectException(\InvalidArgumentException::class);

        foreach ($this->table as $row) {
        }
    }

    // ==================== Transactions ====================

    public function testWithTransactionRollsBackOnException(): void
    {
        $this->createUsersTable();

        try {
            $this->table->withTransaction(function (): void {
                $this->table->insert(['username' => 'alice', 'email' => 'a@example.com']);
                throw new \RuntimeException('migration failed');
            });
            self::fail('Expected exception to propagate');
        } catch (\RuntimeException $e) {
            self::assertSame('migration failed', $e->getMessage());
        }

        self::assertSame(0, $this->table->count());
    }

    public function testWithTransactionCommitsOnSuccess(): void
    {
        $this->createUsersTable();

        $this->table->withTransaction(function (): void {
            $this->table->insert(['username' => 'alice', 'email' => 'a@example.com']);
            $this->table->insert(['username' => 'bob', 'email' => 'b@example.com']);
        });

        self::assertSame(2, $this->table->count());
        self::assertFalse($this->pdo->inTransaction());
    }

    public function testDdlInsideTransactionIsNotRolledBackOnMysql(): void
    {
        $this->createUsersTable();

        try {
            $this->table->withTransaction(function (): void {
                $this->table->addColumn('channel', 'VARCHAR(32) NULL');
                throw new \RuntimeException('migration failed after DDL');
            });
            self::fail('Expected exception to propagate');
        } catch (\RuntimeException) {
        }

        // MySQL commits implicitly around DDL, so the added column survives the
        // rollback. A transaction spanning schema changes is not atomic — this
        // test pins that behaviour so the documented warning stays true.
        self::assertArrayHasKey('channel', $this->table->showColumns());
    }

    // ==================== Helpers ====================

    private function createUsersTable(): void
    {
        $this->table->create([
            'id' => 'INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY',
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

    private function indexExists(string $name): bool
    {
        $stmt = $this->pdo->prepare(
            'SELECT 1 FROM information_schema.STATISTICS '
            . "WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND INDEX_NAME = ? LIMIT 1"
        );
        $stmt->execute([$name]);

        return $stmt->fetchColumn() !== false;
    }
}
