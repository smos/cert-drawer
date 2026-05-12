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
        Schema::create('entra_apps', function (Blueprint $table) {
            $table->id();
            $table->string('display_name');
            $table->string('app_id')->index();
            $table->string('object_id')->unique();
            $table->string('type'); // enterprise_app, app_registration
            $table->json('allowed_groups')->nullable();
            $table->text('notes')->nullable();
            $table->boolean('is_enabled')->default(true);
            $table->timestamp('last_sync')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('entra_apps');
    }
};
