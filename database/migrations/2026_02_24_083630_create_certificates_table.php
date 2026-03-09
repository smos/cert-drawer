<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('certificates', function (Blueprint $row) {
            $row->id();
            $row->foreignId('domain_id')->constrained()->onDelete('cascade');
            $row->string('request_type'); // adcs, acme, manual
            $row->text('csr')->nullable();
            $row->text('certificate')->nullable();
            $row->text('private_key')->nullable(); // To be deleted after PFX or stored encrypted
            $row->string('pfx_password')->nullable(); // Encrypted
            $row->string('issuer')->nullable();
            $row->timestamp('expiry_date')->nullable();
            $row->string('status')->default('requested');
            $row->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('certificates');
    }
};
