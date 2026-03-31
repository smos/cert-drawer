<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cert_health_logs', function (Blueprint $table) {
            $table->string('check_type')->default('local')->after('domain_id'); // 'local' or 'external'
        });
    }

    public function down(): void
    {
        Schema::table('cert_health_logs', function (Blueprint $table) {
            $table->dropColumn('check_type');
        });
    }
};
