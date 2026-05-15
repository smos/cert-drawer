<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use Illuminate\Http\Request;

class AuditLogController extends Controller
{
    public function index(Request $request)
    {
        $query = AuditLog::with('user')->latest();

        if ($request->filled('category')) {
            $category = $request->category;
            
            if ($category === 'entra') {
                $query->where(function($q) {
                    $q->where('action', 'like', 'entra_%')
                      ->orWhereNotNull('entra_app_id');
                });
            } elseif ($category === 'certificate') {
                $query->where(function($q) {
                    $q->whereIn('action', [
                        'cert_archive', 'acme_auto_renewal_success', 'acme_auto_renewal_failed', 
                        'acme_manual_renewal_success', 'acme_manual_renewal_failed', 'csr_initiate', 
                        'cert_fulfill_adcs', 'acme_order_initiate', 'csr_delete', 'pfx_generate', 
                        'pfx_legacy_generate', 'cert_fulfill_manual', 'cert_download', 
                        'cert_chain_download', 'csr_download', 'key_download', 'cert_import', 
                        'pfx_import', 'acme_error', 'acme_fulfill_success'
                    ])->orWhere('action', 'like', 'acme_%')
                      ->orWhere('action', 'like', 'cert_%')
                      ->orWhere('action', 'like', 'csr_%')
                      ->orWhere('action', 'like', 'pfx_%');
                });
            } elseif ($category === 'automation') {
                $query->where(function($q) {
                    $q->where('action', 'like', 'automation_%');
                });
            } elseif ($category === 'domain') {
                $query->where(function($q) {
                    $q->where('action', 'like', 'domain_%')
                      ->orWhereIn('action', ['tag_add', 'tag_remove']);
                });
            } elseif ($category === 'auth') {
                $query->whereIn('action', ['login', 'logout', 'auth_settings_update']);
            }
        }

        $logs = $query->paginate(50)->withQueryString();
        
        return view('audit.index', compact('logs'))
            ->with('containerClass', 'container-fluid');
    }
}
