<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Purge legacy/stale 'local' check logs from cert_health_logs table
        DB::table('cert_health_logs')->where('check_type', 'local')->delete();
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // No-op: Cannot restore purged stale logs
    }
};
