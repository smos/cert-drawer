<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class ConsoleBootTest extends TestCase
{
    /**
     * Test that the console/application can boot even if the database is missing.
     * This mimics the environment during Docker build.
     */
    public function test_console_boots_without_database()
    {
        // Change database connection to a non-existent file to simulate missing DB
        Config::set('database.connections.sqlite.database', '/tmp/non_existent_db.sqlite');
        
        // This should not throw an exception because of our try-catch in routes/console.php
        // and checks in AppServiceProvider.php
        $exitCode = Artisan::call('list');
        
        $this->assertEquals(0, $exitCode);
    }
}
