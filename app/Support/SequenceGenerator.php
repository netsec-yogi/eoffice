<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;

/**
 * Gap-free, concurrency-safe running numbers per scope and year
 * (request numbers, file numbers, certificate numbers). Must be called
 * inside the transaction that creates the numbered record, so a rollback
 * also returns the number.
 */
class SequenceGenerator
{
    public function next(string $scope, ?int $year = null): int
    {
        $year ??= (int) now()->format('Y');

        return DB::transaction(function () use ($scope, $year) {
            DB::table('sequences')->insertOrIgnore([
                'scope' => $scope, 'year' => $year, 'next_value' => 1, 'created_at' => now(), 'updated_at' => now(),
            ]);
            $row = DB::table('sequences')->where(['scope' => $scope, 'year' => $year])->lockForUpdate()->first();
            DB::table('sequences')->where('id', $row->id)->update(['next_value' => $row->next_value + 1, 'updated_at' => now()]);

            return (int) $row->next_value;
        });
    }

    /** e.g. format('IT-EMAIL', 'IT-EMAIL/{YYYY}/{SEQ:5}') => "IT-EMAIL/2026/00042" */
    public function format(string $scope, string $pattern): string
    {
        $year = (int) now()->format('Y');
        $value = $this->next($scope, $year);

        return preg_replace_callback('/\{(YYYY|YY|SEQ(?::(\d+))?)\}/', fn ($m) => match (true) {
            $m[1] === 'YYYY' => (string) $year,
            $m[1] === 'YY' => substr((string) $year, -2),
            default => str_pad((string) $value, (int) ($m[2] ?? 1), '0', STR_PAD_LEFT),
        }, $pattern);
    }
}
