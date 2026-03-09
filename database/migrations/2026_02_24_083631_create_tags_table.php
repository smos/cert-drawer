<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tags', function (Blueprint $row) {
            $row->id();
            $row->foreignId('domain_id')->constrained()->onDelete('cascade');
            $row->string('name');
            $row->string('type'); // server, client
            $row->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tags');
    }
};
