<?php

declare(strict_types=1);

namespace MiGears\MiTable\Tests;

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

    public function testWithPageSizeReturnsSelf(): void
    {
        $result = $this->table->withPageSize(50);
        self::assertSame($this->table, $result);
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

    // --- Helper ---

    private function createUsersTable(): void
    {
        $this->table->create([
            'id' => 'INTEGER PRIMARY KEY AUTOINCREMENT',
            'username' => 'VARCHAR(50) NOT NULL',
            'email' => 'VARCHAR(255) NOT NULL',
        ]);
    }
}
