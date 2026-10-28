<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class AuditLogController extends Controller
{
    public function index(Request $request)
    {
        Gate::authorize('viewAny', AuditLog::class);
        $f = $request->validate(['search' => ['nullable', 'string', 'max:255'], 'from' => ['nullable', 'date'], 'to' => ['nullable', 'date', $request->filled('from') ? 'after_or_equal:from' : 'date']]);
        $query = AuditLog::with('user');
        if ($f['search'] ?? null) {
            $query->where(fn ($q) => $q->where('action', 'like', '%'.$f['search'].'%')->orWhere('description', 'like', '%'.$f['search'].'%')->orWhereHas('user', fn ($q) => $q->where('name', 'like', '%'.$f['search'].'%')));
        }
        if ($f['from'] ?? null) {
            $query->whereDate('created_at', '>=', $f['from']);
        } if ($f['to'] ?? null) {
            $query->whereDate('created_at', '<=', $f['to']);
        }

        return view('admin.audit', ['logs' => $query->orderByDesc('id')->paginate(30)->withQueryString()]);
    }
}
