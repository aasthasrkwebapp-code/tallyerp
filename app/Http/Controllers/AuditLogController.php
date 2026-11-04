<?php

namespace App\Http\Controllers;

use App\Models\Company;
use Illuminate\Http\Request;

class AuditLogController extends Controller
{
    public function __invoke(Request $request, Company $company)
    {
        $this->authorizeCompany($company);

        $request->validate([
            'action' => ['nullable', 'in:created,updated,deleted'],
            'type' => ['nullable', 'string', 'max:50'],
        ]);

        $logs = $company->auditLogs()
            ->with('user')
            ->when($request->filled('action'), fn ($q) => $q->where('action', $request->input('action')))
            ->when($request->filled('type'), fn ($q) => $q->where('subject_type', $request->input('type')))
            ->latest('id')
            ->paginate(50)
            ->withQueryString();

        $types = $company->auditLogs()->distinct()->orderBy('subject_type')->pluck('subject_type');

        return view('audit.index', compact('company', 'logs', 'types'));
    }
}
