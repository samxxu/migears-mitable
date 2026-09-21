<?php

declare(strict_types=1);

namespace MiGears\MiTable\Tests;

use PDO;
use PHPUnit\Framework\TestCase;

/**
 * Base class for integration tests needing a real MySQL/MariaDB server.
 *
 * Connection resolution (first match wins):
 *   1. MYSQL_DSN                        e.g. mysql://user:pass@host:3306/db
 *   2. MYSQL_HOST (+ _PORT/_DB/_USER/_PASSWORD)   any externally managed server
 *   3. docker|podman                    auto-spawn a throwaway MySQL container
 *   none available                      -> test marked skipped
 *
 * The server connection is created once per test process and reused by every
 * integration class. A container spawned for the run is removed at process
 * shutdown, so several integration classes share one server rather than each
 * paying the container's start-up cost.
 *
 * Each test method starts from a schema with no tables, which keeps tests
 * independent without needing transactions or fixtures.
 */
abstract class MySqlTestCase extends TestCase
{
    private const DEFAULT_TEST_DB = 'migears_mitable_test';
    private const ENGINES = ['docker', 'podman'];

    private static ?PDO $sharedPdo = null;
    private static bool $resolved = false;
    private static ?string $containerEngine = null;
    private static ?string $containerId = null;
    private static bool $cleanupRegistered = false;

    protected PDO $pdo;

    protected function setUp(): void
    {
        $pdo = self::sharedPdo();

        if ($pdo === null) {
            $this->markTestSkipped(
                'MySQL unavailable. Set MYSQL_DSN / MYSQL_HOST, start a local server, or install docker/podman.'
            );
        }

        $this->pdo = $pdo;
        $this->dropAllTables();
    }

    /** Remove every table so each test starts from an empty schema. */
    private function dropAllTables(): void
    {
        $this->pdo->exec('SET FOREIGN_KEY_CHECKS = 0');

        $tables = $this->pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);

        foreach ($tables as $table) {
            $this->pdo->exec("DROP TABLE IF EXISTS `{$table}`");
        }

        $this->pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
    }

    /* ==================== Connection resolution ==================== */

    private static function sharedPdo(): ?PDO
    {
        if (self::$resolved) {
            return self::$sharedPdo;
        }
        self::$resolved = true;

        $config = self::locateServer();
        if ($config === null) {
            return null;
        }

        try {
            self::$sharedPdo = self::connect($config);

            return self::$sharedPdo;
        } catch (\Throwable $e) {
            self::destroyContainer();
            throw $e;
        }
    }

    /** @return array{host: string, port: int, user: string, password: string, db: string}|null */
    private static function locateServer(): ?array
    {
        return self::fromDsn()
            ?? self::fromEnv()
            ?? self::fromContainer();
    }

    /** @return array{host: string, port: int, user: string, password: string, db: string}|null */
    private static function fromDsn(): ?array
    {
        $dsn = getenv('MYSQL_DSN');
        if (!is_string($dsn) || $dsn === '') {
            return null;
        }

        if (str_starts_with($dsn, 'mysql://') || str_starts_with($dsn, 'mariadb://')) {
            $parts = parse_url($dsn);
            if ($parts === false) {
                return null;
            }

            $db = isset($parts['path']) ? ltrim($parts['path'], '/') : '';

            return [
                'host' => $parts['host'] ?? '127.0.0.1',
                'port' => (int) ($parts['port'] ?? 3306),
                'user' => $parts['user'] ?? 'root',
                'password' => $parts['pass'] ?? '',
                'db' => $db !== '' ? $db : self::DEFAULT_TEST_DB,
            ];
        }

        // Bare host:port form
        $bits = explode(':', $dsn);

        return [
            'host' => $bits[0] === '' ? '127.0.0.1' : $bits[0],
            'port' => (int) ($bits[1] ?? 3306),
            'user' => 'root',
            'password' => '',
            'db' => self::DEFAULT_TEST_DB,
        ];
    }

    /** @return array{host: string, port: int, user: string, password: string, db: string}|null */
    private static function fromEnv(): ?array
    {
        $host = getenv('MYSQL_HOST');
        if (!is_string($host) || $host === '') {
            return null;
        }

        $db = getenv('MYSQL_DB');

        return [
            'host' => $host,
            'port' => (int) (getenv('MYSQL_PORT') ?: 3306),
            'user' => (string) (getenv('MYSQL_USER') ?: 'root'),
            'password' => (string) (getenv('MYSQL_PASSWORD') ?: ''),
            'db' => is_string($db) && $db !== '' ? $db : self::DEFAULT_TEST_DB,
        ];
    }

    /* ==================== Throwaway container ==================== */

    /** @return array{host: string, port: int, user: string, password: string, db: string}|null */
    private static function fromContainer(): ?array
    {
        foreach (self::ENGINES as $engine) {
            if (trim(self::shell("command -v {$engine}")) === '') {
                continue;
            }

            $config = self::tryStartContainer($engine);
            if ($config !== null) {
                return $config;
            }
        }

        // An engine binary may exist while its daemon or VM is down; fall back to none.
        return null;
    }

    /** @return array{host: string, port: int, user: string, password: string, db: string}|null */
    private static function tryStartContainer(string $engine): ?array
    {
        $image = getenv('MYSQL_IMAGE') ?: 'mysql:8.0';
        $password = 'migears';

        $id = trim(self::shell(
            "{$engine} run -d -P -e MYSQL_ROOT_PASSWORD={$password} {$image}"
        ));

        if ($id === '') {
            return null;
        }

        $out = trim(self::shell("{$engine} port {$id} 3306/tcp"));
        $colon = strrpos($out, ':');

        if ($colon === false) {
            self::shell("{$engine} rm -f {$id}");

            return null;
        }

        $config = [
            'host' => '127.0.0.1',
            'port' => (int) substr($out, $colon + 1),
            'user' => 'root',
            'password' => $password,
            'db' => self::DEFAULT_TEST_DB,
        ];

        // A published port only means the entrypoint is up; MySQL still has to
        // initialise its data directory before it answers queries.
        if (!self::waitForServer($config, 120.0)) {
            self::shell("{$engine} rm -f {$id}");

            return null;
        }

        self::$containerEngine = $engine;
        self::$containerId = $id;
        self::registerShutdownCleanup();

        return $config;
    }

    /** Poll until the server accepts queries, so a spawn race is not a test failure. */
    private static function waitForServer(array $config, float $timeout): bool
    {
        $deadline = microtime(true) + $timeout;

        do {
            try {
                new PDO(
                    "mysql:host={$config['host']};port={$config['port']}",
                    $config['user'],
                    $config['password'],
                    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 2]
                );

                return true;
            } catch (\PDOException) {
                usleep(500_000);
            }
        } while (microtime(true) < $deadline);

        return false;
    }

    private static function destroyContainer(): void
    {
        if (self::$containerEngine !== null && self::$containerId !== null) {
            self::shell(self::$containerEngine . ' rm -f ' . self::$containerId);
        }

        self::$containerEngine = null;
        self::$containerId = null;
    }

    private static function registerShutdownCleanup(): void
    {
        if (self::$cleanupRegistered) {
            return;
        }

        self::$cleanupRegistered = true;

        register_shutdown_function(static function (): void {
            self::destroyContainer();
        });
    }

    private static function shell(string $cmd): string
    {
        $pipes = [];
        $proc = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);

        if (!is_resource($proc)) {
            return '';
        }

        $out = stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($proc);

        return $out;
    }

    /* ==================== Database setup ==================== */

    /** @param array{host: string, port: int, user: string, password: string, db: string} $config */
    private static function connect(array $config): PDO
    {
        $pdo = new PDO(
            "mysql:host={$config['host']};port={$config['port']}",
            $config['user'],
            $config['password'],
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
        );

        try {
            $pdo->exec(
                "CREATE DATABASE IF NOT EXISTS `{$config['db']}` "
                . 'DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci'
            );
        } catch (\PDOException) {
            // An externally managed account may lack CREATE; USE below reports
            // a missing database with a clearer error than this one.
        }

        $pdo->exec("USE `{$config['db']}`");

        return $pdo;
    }
}
