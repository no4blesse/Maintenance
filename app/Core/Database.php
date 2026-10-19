<?php

declare(strict_types=1);

namespace App\Core;

use PDO;
use PDOStatement;
use RuntimeException;

/**
 * Thin PDO wrapper. All queries use prepared statements with positional
 * placeholders. Connections use UTC so DATETIME columns are stored in UTC.
 */
final class Database
{
    private static ?PDO $pdo = null;
    private static int $txDepth = 0;

    /** @param array<string, mixed> $cfg */
    public static function connect(array $cfg): PDO
    {
        $dsn = sprintf(
            'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
            $cfg['host'],
            (int) $cfg['port'],
            $cfg['database']
        );
        $pdo = new PDO($dsn, (string) $cfg['username'], (string) $cfg['password'], [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
            PDO::ATTR_STRINGIFY_FETCHES => false,
        ]);
        $pdo->exec('SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci');
        $pdo->exec("SET time_zone = '+00:00'");
        self::$pdo = $pdo;
        self::$txDepth = 0;
        return $pdo;
    }

    public static function setPdo(?PDO $pdo): void
    {
        self::$pdo = $pdo;
        self::$txDepth = 0;
    }

    public static function pdo(): PDO
    {
        if (self::$pdo === null) {
            self::connect(Config::get('database'));
        }
        return self::$pdo;
    }

    /** @param list<mixed> $params */
    public static function query(string $sql, array $params = []): PDOStatement
    {
        $stmt = self::pdo()->prepare($sql);
        $i = 1;
        foreach ($params as $p) {
            $type = match (true) {
                is_int($p) => PDO::PARAM_INT,
                is_bool($p) => PDO::PARAM_INT,
                $p === null => PDO::PARAM_NULL,
                default => PDO::PARAM_STR,
            };
            $stmt->bindValue($i++, is_bool($p) ? (int) $p : $p, $type);
        }
        $stmt->execute();
        return $stmt;
    }

    /** @return list<array<string, mixed>> */
    public static function select(string $sql, array $params = []): array
    {
        return self::query($sql, $params)->fetchAll();
    }

    /** @return array<string, mixed>|null */
    public static function first(string $sql, array $params = []): ?array
    {
        $row = self::query($sql, $params)->fetch();
        return $row === false ? null : $row;
    }

    public static function value(string $sql, array $params = []): mixed
    {
        $v = self::query($sql, $params)->fetchColumn();
        return $v === false ? null : $v;
    }

    /** @return list<mixed> */
    public static function column(string $sql, array $params = []): array
    {
        return self::query($sql, $params)->fetchAll(PDO::FETCH_COLUMN);
    }

    public static function execute(string $sql, array $params = []): int
    {
        return self::query($sql, $params)->rowCount();
    }

    /** @param array<string, mixed> $data */
    public static function insert(string $table, array $data): int
    {
        self::assertIdent($table);
        $cols = array_keys($data);
        array_walk($cols, [self::class, 'assertIdent']);
        $sql = sprintf(
            'INSERT INTO `%s` (`%s`) VALUES (%s)',
            $table,
            implode('`,`', $cols),
            implode(',', array_fill(0, count($cols), '?'))
        );
        self::query($sql, array_values($data));
        return (int) self::pdo()->lastInsertId();
    }

    /**
     * @param array<string, mixed> $data
     * @param list<mixed> $whereParams
     */
    public static function update(string $table, array $data, string $where, array $whereParams): int
    {
        self::assertIdent($table);
        $sets = [];
        foreach (array_keys($data) as $col) {
            self::assertIdent($col);
            $sets[] = "`$col` = ?";
        }
        $sql = sprintf('UPDATE `%s` SET %s WHERE %s', $table, implode(', ', $sets), $where);
        return self::execute($sql, [...array_values($data), ...$whereParams]);
    }

    /**
     * Build "?,?,?" for IN() lists. Returns "NULL" for empty lists so the
     * resulting condition is always false (safe default = no access).
     * @param list<int|string> $values
     */
    public static function placeholders(array $values): string
    {
        return $values === [] ? 'NULL' : implode(',', array_fill(0, count($values), '?'));
    }

    /**
     * Runs $fn inside a transaction. Nested calls use savepoints so a caught
     * inner exception cannot accidentally commit a partially applied operation.
     * @template T
     * @param callable(): T $fn
     * @return T
     */
    public static function transaction(callable $fn): mixed
    {
        $pdo = self::pdo();
        $level = self::$txDepth;
        if ($level === 0) {
            $pdo->beginTransaction();
        } else {
            $pdo->exec('SAVEPOINT sgmc_' . $level);
        }
        self::$txDepth++;
        try {
            $result = $fn();
            if ($level === 0) {
                $pdo->commit();
            } else {
                $pdo->exec('RELEASE SAVEPOINT sgmc_' . $level);
            }
            return $result;
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                if ($level === 0) { $pdo->rollBack(); }
                else { $pdo->exec('ROLLBACK TO SAVEPOINT sgmc_' . $level); $pdo->exec('RELEASE SAVEPOINT sgmc_' . $level); }
            }
            throw $e;
        } finally {
            self::$txDepth = $level;
        }
    }

    public static function inTransaction(): bool
    {
        return self::$txDepth > 0;
    }

    private static function assertIdent(string $name): void
    {
        if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $name)) {
            throw new RuntimeException('Invalid SQL identifier: ' . $name);
        }
    }
}
