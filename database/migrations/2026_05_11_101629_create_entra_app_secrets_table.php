<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('entra_app_secrets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('entra_app_id')->constrained()->onDelete('cascade');
            $table->string('display_name')->nullable();
            $table->string('key_id')->nullable(); // For secrets
            $table->string('hint')->nullable();   // First few chars of secret
            $table->string('type');              // secret, certificate
            $table->timestamp('start_date')->nullable();
            $table->timestamp('end_date')->nullable();
            $table->string('thumbprint')->nullable(); // For certificates
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('entra_app_secrets');
    }
};
