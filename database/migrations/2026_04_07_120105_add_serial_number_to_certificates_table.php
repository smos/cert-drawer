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
        Schema::table('certificates', function (Blueprint $table) {
            $table->string('serial_number')->nullable()->index();
            // We do NOT add a unique index here yet, as there ARE duplicates in the database right now.
        });

        // Populate serial numbers
        $service = new \App\Services\CertificateService();
        \App\Models\Certificate::whereNotNull('certificate')->chunk(100, function ($certificates) use ($service) {
            foreach ($certificates as $cert) {
                $info = openssl_x509_parse($cert->certificate);
                if ($info && isset($info['serialNumber'])) {
                    $cert->update(['serial_number' => $info['serialNumber']]);
                }
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('certificates', function (Blueprint $table) {
            $table->dropColumn('serial_number');
        });
    }
};
