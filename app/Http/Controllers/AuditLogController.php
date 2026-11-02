<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Support\CurrentCompany;
use Illuminate\Http\Request;

class AuditLogController extends Controller
{
    public function index(Request $request, CurrentCompany $current)
    {
        $filters = $request->validate([
            'event' => ['nullable', 'string', 'max:50'],
            'user_id' => ['nullable', 'integer'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
            'q' => ['nullable', 'string', 'max:100'],
        ]);

        $logs = AuditLog::with('user')
            ->where('company_id', $current->id())
            ->when($filters['event'] ?? null, fn ($q, $v) => $q->where('event', $v))
            ->when($filters['user_id'] ?? null, fn ($q, $v) => $q->where('user_id', $v))
            ->when($filters['from'] ?? null, fn ($q, $v) => $q->whereDate('created_at', '>=', $v))
            ->when($filters['to'] ?? null, fn ($q, $v) => $q->whereDate('created_at', '<=', $v))
            ->when($filters['q'] ?? null, fn ($q, $v) => $q->where('description', 'like', "%{$v}%"))
            ->latest('id')
            ->paginate(50)
            ->withQueryString();

        $events = AuditLog::where('company_id', $current->id())->distinct()->orderBy('event')->pluck('event');
        $users = $current->get()->members()->orderBy('name')->get(['users.id', 'users.name']);

        return view('audit-logs.index', compact('logs', 'events', 'users', 'filters'));
    }

    public function show(AuditLog $auditLog, CurrentCompany $current)
    {
        abort_unless($auditLog->company_id === $current->id(), 404);

        return view('audit-logs.show', ['log' => $auditLog->load('user')]);
    }
}
