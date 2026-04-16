<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::create('automation_logs', function (Blueprint $blueprint) {
            $blueprint->id();
            $blueprint->foreignId('automation_id')->constrained()->onDelete('cascade');
            $blueprint->string('status'); // success, failure, warning
            $blueprint->string('message');
            $blueprint->json('details')->nullable();
            $blueprint->timestamps();
        });
    }

    public function down()
    {
        Schema::dropIfExists('automation_logs');
    }
};
