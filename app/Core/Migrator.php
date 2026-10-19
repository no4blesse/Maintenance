<?php

declare(strict_types=1);

namespace App\Core;

use PDO;

/** Applies database/migrations/*.sql in order and records them in `migrations`. */
final class Migrator
{
    public function __construct(private PDO $pdo, private string $dir)
    {
    }

    public static function migrationsTableSql(): string
    {
        return "CREATE TABLE IF NOT EXISTS migrations (\n"
            . "  id INT UNSIGNED NOT NULL AUTO_INCREMENT,\n"
            . "  filename VARCHAR(190) NOT NULL,\n"
            . "  batch INT UNSIGNED NOT NULL,\n"
            . "  applied_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,\n"
            . "  PRIMARY KEY (id),\n"
            . "  UNIQUE KEY uq_migrations_file (filename)\n"
            . ") ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
    }

    /** @return list<string> applied filenames */
    public function migrate(?callable $log = null): array
    {
        $log ??= static function (string $m): void {
        };
        $this->pdo->exec(self::migrationsTableSql());
        $done = $this->pdo->query('SELECT filename FROM migrations')->fetchAll(PDO::FETCH_COLUMN);
        $batch = (int) $this->pdo->query('SELECT IFNULL(MAX(batch),0)+1 FROM migrations')->fetchColumn();
        $applied = [];
        $files = glob(rtrim($this->dir, '/\\') . '/*.sql') ?: [];
        sort($files, SORT_STRING);
        foreach ($files as $file) {
            $name = basename($file);
            if (in_array($name, $done, true)) {
                continue;
            }
            $log("Migrating: $name");
            foreach (self::splitSql((string) file_get_contents($file)) as $stmt) {
                $this->pdo->exec($stmt);
            }
            $ins = $this->pdo->prepare('INSERT INTO migrations (filename, batch) VALUES (?, ?)');
            $ins->execute([$name, $batch]);
            $applied[] = $name;
            $log("Migrated:  $name");
        }
        return $applied;
    }

    public function runSqlFile(string $file): void
    {
        foreach (self::splitSql((string) file_get_contents($file)) as $stmt) {
            $this->pdo->exec($stmt);
        }
    }

    /**
     * Splits a SQL script into statements. Statements must end with ';' at
     * end of line. Full-line '--' comments are removed.
     * @return list<string>
     */
    public static function splitSql(string $sql): array
    {
        $sql = str_replace("\r\n", "\n", $sql);
        $lines = array_filter(explode("\n", $sql), static fn ($l) => !preg_match('/^\s*--/', $l));
        $parts = preg_split('/;\s*(\n|$)/', implode("\n", $lines)) ?: [];
        return array_values(array_filter(array_map('trim', $parts), static fn ($s) => $s !== ''));
    }
}
