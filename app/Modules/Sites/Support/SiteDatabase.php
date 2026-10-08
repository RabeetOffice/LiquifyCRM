<?php

declare(strict_types=1);

namespace App\Modules\Sites\Support;

use InvalidArgumentException;
use PDO;

/**
 * Read-only access to the leads table of a website, for pull mode and the
 * database check. Not final so tests can replace it.
 *
 * @phpstan-type DatabaseConfig array{host: string, port: int, database: string, username: string, password: string, table: string}
 */
class SiteDatabase
{
    /**
     * New rows after the cursor, oldest first.
     *
     * @param  DatabaseConfig  $config
     * @return list<array<string, mixed>>
     */
    public function rows(array $config, int $afterId, int $limit = 500): array
    {
        $statement = $this->connect($config)->prepare('SELECT * FROM `'.$this->table($config).'` WHERE `id` > ? ORDER BY `id` ASC LIMIT '.max(1, min(1000, $limit)));
        $statement->execute([$afterId]);

        return array_values($statement->fetchAll(PDO::FETCH_ASSOC));
    }

    /** @param  DatabaseConfig  $config */
    public function maxId(array $config): int
    {
        return (int) $this->connect($config)->query('SELECT COALESCE(MAX(`id`), 0) FROM `'.$this->table($config).'`')->fetchColumn();
    }

    /** @param  DatabaseConfig  $config */
    public function count(array $config): int
    {
        return (int) $this->connect($config)->query('SELECT COUNT(*) FROM `'.$this->table($config).'`')->fetchColumn();
    }

    /** @param  DatabaseConfig  $config */
    protected function connect(array $config): PDO
    {
        return new PDO(
            sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $config['host'], $config['port'], $config['database']),
            $config['username'],
            $config['password'],
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 8],
        );
    }

    /** @param  DatabaseConfig  $config */
    private function table(array $config): string
    {
        if (! preg_match('/^[A-Za-z0-9_]{1,64}$/', $config['table'])) {
            throw new InvalidArgumentException('The table name may only contain letters, numbers and underscores.');
        }

        return $config['table'];
    }
}
