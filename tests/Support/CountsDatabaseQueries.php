<?php

namespace Tests\Support;

use Illuminate\Support\Facades\DB;

trait CountsDatabaseQueries
{
    protected function readQueries(callable $action): array
    {
        $connection = DB::connection();
        $wasLogging = $connection->logging();
        $connection->flushQueryLog();
        $connection->enableQueryLog();

        try {
            $action();

            return array_values(array_filter($connection->getQueryLog(),
                fn (array $query) => preg_match('/^\s*(select|with)\b/i', $query['query'])));
        } finally {
            $connection->flushQueryLog();
            if (! $wasLogging) {
                $connection->disableQueryLog();
            }
        }
    }

    protected function assertReadQueriesStayConstant(callable $small, callable $large): void
    {
        $smallQueries = $this->readQueries($small);
        $largeQueries = $this->readQueries($large);

        $this->assertNotEmpty($smallQueries, 'The measured action must execute database reads.');
        $this->assertCount(count($smallQueries), $largeQueries,
            'Database reads must not increase with the number of returned records or sale items.');
    }
}
