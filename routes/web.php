<?php

use App\Http\Controllers\DomainController;
use App\Http\Controllers\CertificateController;
use App\Http\Controllers\SettingController;
use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\SetupController;
use Illuminate\Support\Facades\Route;

Route::get('/login', [LoginController::class, 'showLoginForm'])->name('login');
Route::post('/login', [LoginController::class, 'login']);
Route::post('/logout', [LoginController::class, 'logout'])->name('logout');

Route::get('/setup', [SetupController::class, 'index'])->name('setup.index');
Route::post('/setup', [SetupController::class, 'store'])->name('setup.store');

Route::middleware('auth')->group(function () {
    Route::get('/', [DomainController::class, 'index'])->name('domains.index');
    Route::get('/authorities', [DomainController::class, 'caIndex'])->name('domains.authorities');
    Route::post('/domains', [DomainController::class, 'store'])->name('domains.store');
    Route::post('/domains/import-cert', [DomainController::class, 'importCert'])->name('domains.import-cert');
    Route::post('/domains/import-pfx', [DomainController::class, 'importPfx'])->name('domains.import-pfx');
    Route::get('/domains/{domain}', [DomainController::class, 'show'])->name('domains.show');
    Route::delete('/domains/{domain}', [DomainController::class, 'destroy'])->name('domains.destroy');
    Route::post('/domains/{domain}/tags', [DomainController::class, 'addTag'])->name('domains.tags.add');
    Route::post('/domains/{domain}/groups', [DomainController::class, 'updateGroups'])->name('domains.groups.update');
    Route::post('/domains/{domain}/notes', [DomainController::class, 'updateNotes'])->name('domains.notes.update');
    Route::post('/domains/{domain}/toggle-status', [DomainController::class, 'toggleStatus'])->name('domains.toggle-status');
    Route::delete('/tags/{tag}', [DomainController::class, 'removeTag'])->name('tags.remove');
    Route::get('/domains/{domain}/preview-csr-config', [CertificateController::class, 'previewCsrConfig'])->name('domains.preview-csr-config');
    Route::post('/domains/{domain}/initiate-request', [CertificateController::class, 'initiateRequest'])->name('domains.initiate-request');

    Route::get('/certificates/{certificate}', [CertificateController::class, 'show'])->name('certificates.show');
    Route::post('/certificates/{certificate}/adcs-request', [CertificateController::class, 'requestAdcs'])->name('certificates.adcs-request');
    Route::post('/certificates/{certificate}/acme-request', [CertificateController::class, 'requestAcme'])->name('certificates.acme-request');
    Route::post('/certificates/{certificate}/acme-fulfill', [CertificateController::class, 'fulfillAcme'])->name('certificates.acme-fulfill');

    Route::match(['get', 'post'], '/certificates/{certificate}/download/{type}', [CertificateController::class, 'download'])->name('certificates.download');
    Route::match(['get', 'post'], '/certificates/{certificate}/pfx', [CertificateController::class, 'generatePfx'])->name('certificates.pfx');
    Route::post('/certificates/{certificate}/legacy-pfx', [CertificateController::class, 'generateLegacyPfx'])->name('certificates.legacy-pfx');
    Route::post('/certificates/{certificate}/upload', [CertificateController::class, 'upload'])->name('certificates.upload');

    Route::get('/settings', [SettingController::class, 'index'])->name('settings.index');
    Route::post('/settings', [SettingController::class, 'update'])->name('settings.update');
    
    Route::get('/settings/auth', [\App\Http\Controllers\AuthController::class, 'index'])->name('auth.settings.index');
    Route::post('/settings/auth', [\App\Http\Controllers\AuthController::class, 'update'])->name('auth.settings.update');
    Route::post('/settings/auth/test-ldap', [\App\Http\Controllers\AuthController::class, 'testLdap'])->name('auth.settings.test-ldap');
    Route::get('/settings/auth/search-groups', [\App\Http\Controllers\AuthController::class, 'searchGroups'])->name('auth.settings.search-groups');

    Route::get('/settings/search-groups', [SettingController::class, 'searchGroups'])->name('settings.search-groups');
    Route::get('/audit-logs', [\App\Http\Controllers\AuditLogController::class, 'index'])->name('audit.index');
    
    Route::get('/automations', [\App\Http\Controllers\AutomationController::class, 'index'])->name('automations.index');
    Route::post('/automations', [\App\Http\Controllers\AutomationController::class, 'store'])->name('automations.store');
    Route::post('/automations/{automation}/run', [\App\Http\Controllers\AutomationController::class, 'run'])->name('automations.run');
    Route::delete('/automations/{automation}', [\App\Http\Controllers\AutomationController::class, 'destroy'])->name('automations.destroy');
});
