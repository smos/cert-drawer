<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (!Schema::hasTable('settings')) {
            return;
        }

        // Only set default session lifetime to 30 minutes if it does not yet exist in the settings table
        $exists = DB::table('settings')->where('key', 'session_lifetime')->exists();
        if (!$exists) {
            DB::table('settings')->insert([
                'key' => 'session_lifetime',
                'value' => '30',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Safe no-op on rollback
    }
};
