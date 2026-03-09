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
            $table->string('thumbprint_sha1', 40)->nullable()->index();
            $table->string('thumbprint_sha256', 64)->nullable()->index();
        });

        // Re-index existing certificates
        $service = new \App\Services\CertificateService();
        \DB::table('certificates')->whereNotNull('certificate')->orderBy('id')->chunk(100, function ($certificates) use ($service) {
            foreach ($certificates as $cert) {
                \DB::table('certificates')->where('id', $cert->id)->update([
                    'thumbprint_sha1' => $service->extractThumbprint($cert->certificate, 'sha1'),
                    'thumbprint_sha256' => $service->extractThumbprint($cert->certificate, 'sha256'),
                ]);
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('certificates', function (Blueprint $table) {
            $table->dropColumn(['thumbprint_sha1', 'thumbprint_sha256']);
        });
    }
};
