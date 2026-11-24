<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditTrail;
use Illuminate\Http\Request;

/**
 * The audit trail, read-only, SUPER ONLY (gate `read-audit-trail`; owner,
 * 2026-11-24). Restored from before the undilot2026 split so that what an
 * admin did while signed in as a participant can be read — the rows were
 * always written (Auditable, Impersonation), only the screen was gone.
 */
class AuditTrailController extends Controller
{
    public function index(Request $request)
    {
        $audits = AuditTrail::with('performer')
            ->latest('created_at')->latest('id')
            ->when($request->input('action'), fn ($q, $action) => $q->where('action', $action))
            ->when($request->input('model'), fn ($q, $model) => $q->where('auditable_type', 'App\\Models\\'.$model))
            ->when($request->input('performer'), fn ($q, $performer) => $q->where('performer_type', 'App\\Models\\'.$performer))
            ->when($request->input('search'), fn ($q, $search) => $q->where(fn ($w) => $w
                ->where('ip_address', 'like', "%{$search}%")
                ->orWhere('url', 'like', "%{$search}%")))
            // One admin's rows, or one participant's account (e.g. who opened it).
            ->when($request->input('admin'), fn ($q, $id) => $q->where('performer_type', \App\Models\Admin::class)->where('performer_id', $id))
            ->when($request->input('user'), fn ($q, $id) => $q->where('auditable_type', \App\Models\User::class)->where('auditable_id', $id))
            ->when($request->input('from'), fn ($q, $from) => $q->whereDate('created_at', '>=', $from))
            ->when($request->input('to'), fn ($q, $to) => $q->whereDate('created_at', '<=', $to))
            ->paginate(25)
            ->withQueryString();

        return view('admin.audit-trails.index', [
            'audits' => $audits,
            'actions' => AuditTrail::distinct()->orderBy('action')->pluck('action'),
            'models' => AuditTrail::distinct()->pluck('auditable_type')->filter()->map(fn ($type) => class_basename($type)),
        ]);
    }

    public function show(AuditTrail $auditTrail)
    {
        $auditTrail->load(['performer', 'auditable']);

        return view('admin.audit-trails.show', compact('auditTrail'));
    }
}
