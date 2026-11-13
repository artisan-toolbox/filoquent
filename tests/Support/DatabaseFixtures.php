<?php

declare(strict_types=1);

namespace ArtisanToolbox\Filoquent\Tests\Support;

use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

final class DatabaseFixtures
{
    public static function seedOwners(int $population, string $table): void
    {
        DB::statement('INSERT INTO '.DB::connection()->getQueryGrammar()->wrapTable($table).' (id) SELECT n FROM '.self::population($population));

        if (DB::getDriverName() === 'pgsql') {
            DB::select("SELECT setval(pg_get_serial_sequence(?, 'id'), ?, true)", [$table, $population]);
        }
    }

    public static function seedReceipts(int $population, string $table, string $ownerType): void
    {
        $id = match (DB::getDriverName()) {
            'mysql' => "concat('00000000-0000-7000-8000-', lpad(n, 12, '0'))",
            'pgsql' => "('00000000-0000-7000-8000-' || lpad(n::text, 12, '0'))::uuid",
            default => "printf('00000000-0000-7000-8000-%012d', n)",
        };
        $key = DB::getDriverName() === 'mysql' ? 'CAST(n AS CHAR)' : 'CAST(n AS TEXT)';
        DB::statement("INSERT INTO filoquent_uploads (id, owner_type, owner_table, owner_key_name, owner_key, attribute, manifest, state) SELECT {$id}, ?, ?, 'id', {$key}, 'attachment', '{}', 'active' FROM ".self::population($population), [$ownerType, self::ownerRoute($table)]);
    }

    public static function ownerRoute(string $table): string
    {
        return DB::getDriverName() === 'pgsql' && ! str_contains($table, '.') ? 'public.'.$table : $table;
    }

    public static function sql(string $query): string
    {
        return str_replace('`', '"', $query);
    }

    /** @param list<mixed> $bindings */
    public static function assertIndexed(string $query, array $bindings, ?string $index = null): void
    {
        if (DB::getDriverName() === 'sqlite') {
            $details = collect(DB::select('EXPLAIN QUERY PLAN '.$query, $bindings))->pluck('detail')->implode(' ');
            expect($details)->toContain('SEARCH')->toContain('INDEX')->not->toContain('SCAN filoquent_uploads');

            if ($index === null) {
                expect($details)->not->toContain('TEMP B-TREE');
            }
        } elseif (DB::getDriverName() === 'mysql') {
            DB::statement('ANALYZE TABLE filoquent_uploads');
            $rows = collect(DB::select('EXPLAIN '.$query, $bindings));
            $receipts = $rows->where('table', 'filoquent_uploads');
            expect($receipts)->not->toBeEmpty();
            foreach ($receipts as $row) {
                expect($row->key)->not->toBeNull()->and($row->type)->not->toBe('ALL');

                if ($index === null) {
                    expect($row->Extra)->not->toContain('Using filesort')->not->toContain('Using temporary');
                }
            }
            $details = $rows->pluck('key')->implode(' ');
        } else {
            DB::statement('ANALYZE filoquent_uploads');
            $plan = DB::select('EXPLAIN (FORMAT JSON) '.$query, $bindings);
            $details = json_encode($plan, JSON_THROW_ON_ERROR);
            $decoded = json_decode((string) $plan[0]->{'QUERY PLAN'}, true, flags: JSON_THROW_ON_ERROR);
            expect(self::assertPostgresIndexes($decoded, $index !== null))->toBeGreaterThan(0);
        }

        if ($index !== null) {
            expect($details)->toContain($index);
        }
    }

    /** @param array<array-key, mixed> $node */
    private static function assertPostgresIndexes(array $node, bool $allowSort): int
    {
        $count = 0;

        if (! $allowSort) {
            expect($node['Node Type'] ?? null)->not->toBe('Sort')->not->toBe('Incremental Sort');
        }

        if (($node['Relation Name'] ?? null) === 'filoquent_uploads') {
            expect($node['Node Type'])->toContain('Index');
            $count++;
        }
        foreach ($node as $child) {
            if (is_array($child)) {
                $count += self::assertPostgresIndexes($child, $allowSort);
            }
        }

        return $count;
    }

    private static function population(int $population): string
    {
        throw_if($population < 1 || $population > 1_000_000, InvalidArgumentException::class, 'Fixture populations must contain between one and one million rows.');

        if (DB::getDriverName() === 'pgsql') {
            return 'generate_series(1, '.$population.') AS population(n)';
        }

        if (DB::getDriverName() === 'mysql') {
            $digits = '(SELECT 0 d UNION ALL SELECT 1 UNION ALL SELECT 2 UNION ALL SELECT 3 UNION ALL SELECT 4 UNION ALL SELECT 5 UNION ALL SELECT 6 UNION ALL SELECT 7 UNION ALL SELECT 8 UNION ALL SELECT 9)';
            $terms = [];
            $joins = [];
            for ($index = 0; $index < strlen((string) ($population - 1)); $index++) {
                $terms[] = 'd'.$index.'.d * '.(10 ** $index);
                $joins[] = $digits.' d'.$index;
            }
            $number = '1 + '.implode(' + ', $terms);

            return '(SELECT '.$number.' n FROM '.implode(' CROSS JOIN ', $joins).' WHERE '.$number.' <= '.$population.') population';
        }

        return '(WITH RECURSIVE population(n) AS (SELECT 1 UNION ALL SELECT n + 1 FROM population WHERE n < '.$population.') SELECT n FROM population) population';
    }
}
