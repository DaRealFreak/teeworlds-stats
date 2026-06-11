<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * Regression guard: the app has GROUP BY queries that violate MySQL/MariaDB's
 * ONLY_FULL_GROUP_BY (e.g. MainController@general's `group by country` on SELECT *,
 * Player::totalHoursOnline, Server::players, Map::statsPlayedBy). The original app
 * deliberately ran with strict mode off; the Laravel 13 skeleton defaulted it back
 * to true, which 500s those pages on MariaDB. This locks strict off so a future
 * skeleton/config refresh can't silently re-break it.
 *
 * NOTE: the test suite runs on SQLite (which ignores ONLY_FULL_GROUP_BY), so this
 * asserts the configuration directly rather than executing the queries.
 */
class DatabaseStrictModeTest extends TestCase
{
    public function test_mysql_and_mariadb_connections_disable_strict_mode(): void
    {
        $this->assertFalse(
            config('database.connections.mysql.strict'),
            'mysql connection must keep strict mode off (ONLY_FULL_GROUP_BY breaks the GROUP BY queries)'
        );
        $this->assertFalse(
            config('database.connections.mariadb.strict'),
            'mariadb connection must keep strict mode off (ONLY_FULL_GROUP_BY breaks the GROUP BY queries)'
        );
    }
}
