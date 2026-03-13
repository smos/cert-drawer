<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('domains', function (Blueprint $table) {
            $table->timestamp('last_cert_check')->nullable();
        });

        Schema::create('cert_health_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('domain_id')->constrained()->onDelete('cascade');
            $table->string('ip_address');
            $table->string('ip_version'); // v4 or v6
            $table->string('thumbprint_sha256')->nullable();
            $table->string('issuer')->nullable();
            $table->timestamp('expiry_date')->nullable();
            $table->text('error')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cert_health_logs');
        Schema::table('domains', function (Blueprint $table) {
            $table->dropColumn('last_cert_check');
        });
    }
};
