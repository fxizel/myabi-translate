<?php

namespace App\Services\CatalogueSearch;

use Illuminate\Database\Query\Builder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use PDO;
use PDOException;

/** Non-locking snapshot reads, bounded on disk as well as in PHP memory. */
final class SqlSpool
{
    private function __construct(private $file, private int $width) {}

    public static function read(Builder $query, int $width, callable $encode, Budget $budget): self
    {
        $file = tmpfile();
        if ($file === false) {
            throw new SearchStopped;
        }
        $connection = DB::connection();
        $pdo = $connection->getPdo();
        $mysql = in_array($connection->getDriverName(), ['mariadb', 'mysql']);
        $previous = $mysql ? $pdo->getAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY) : null;
        $limit = (int) config('catalogue-search.max_spool_bytes');
        $query = (clone $query)->limit(intdiv($limit, $width) + 1);
        $sql = $query->toSql();
        $bindings = $connection->prepareBindings($query->getBindings());
        try {
            $budget->sql(function () use ($connection, $pdo, $mysql, $previous, $sql, $bindings, $encode, $width, $limit, $file, $budget) {
                $statement = null;
                $started = hrtime(true);
                try {
                    if ($mysql) {
                        $pdo->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, false);
                    }
                    $statement = $pdo->prepare($sql);
                    $connection->bindValues($statement, $bindings);
                    $statement->execute();
                    $bytes = 0;
                    $buffer = '';
                    while ($row = $statement->fetch(PDO::FETCH_ASSOC)) {
                        $budget->check();
                        $record = $encode($row);
                        $bytes += strlen($record);
                        if (strlen($record) !== $width || $bytes > $limit) {
                            throw new SearchStopped;
                        }
                        $buffer .= $record;
                        if (strlen($buffer) >= 65536) {
                            self::write($file, $buffer);
                            $buffer = '';
                        }
                    }
                    self::write($file, $buffer);
                } catch (PDOException $error) {
                    throw new QueryException($connection->getName(), $sql, $bindings, $error);
                } finally {
                    // A second query cannot run until the unbuffered cursor is closed.
                    try {
                        $statement?->closeCursor();
                    } finally {
                        if ($mysql) {
                            $pdo->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, $previous);
                        }
                    }
                    $connection->logQuery($sql, $bindings, (hrtime(true) - $started) / 1e6);
                }
            });
            rewind($file);

            return new self($file, $width);
        } catch (\Throwable $error) {
            fclose($file);
            throw $error;
        }
    }

    private static function write($file, string $buffer): void
    {
        if ($buffer !== '' && fwrite($file, $buffer) !== strlen($buffer)) {
            throw new SearchStopped;
        }
    }

    public function batches(): \Generator
    {
        try {
            $batch = [];
            while (($record = fread($this->file, $this->width)) !== '') {
                if ($record === false || strlen($record) !== $this->width) {
                    throw new SearchStopped;
                }
                $batch[] = $record;
                if (count($batch) === (int) config('catalogue-search.batch_size')) {
                    yield $batch;
                    $batch = [];
                }
            }
            if ($batch) {
                yield $batch;
            }
        } finally {
            $this->close();
        }
    }

    private function close(): void
    {
        if (is_resource($this->file)) {
            fclose($this->file);
        }
    }

    public function __destruct()
    {
        $this->close();
    }
}
