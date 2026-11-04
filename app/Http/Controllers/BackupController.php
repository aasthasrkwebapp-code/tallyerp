<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\Company;
use App\Support\CompanyBackup;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class BackupController extends Controller
{
    /**
     * Download the company's books as a JSON file.
     */
    public function download(Company $company)
    {
        $this->authorizeCompany($company);

        $json = json_encode(CompanyBackup::export($company), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        $filename = Str::slug($company->name).'-backup-'.now()->format('Y-m-d-His').'.json';

        AuditLog::create([
            'company_id' => $company->id,
            'user_id' => auth()->id(),
            'action' => 'created',
            'subject_type' => 'Backup',
            'subject_id' => $company->id,
            'summary' => 'Downloaded a backup',
        ]);

        return response()->streamDownload(fn () => print($json), $filename, ['Content-Type' => 'application/json']);
    }

    public function restoreForm()
    {
        return view('backups.restore');
    }

    /**
     * Restore a backup file as a new company owned by the current user.
     */
    public function restore(Request $request)
    {
        $request->validate([
            'backup' => ['required', 'file', 'mimetypes:application/json,text/plain', 'max:51200'],
            'name' => ['nullable', 'string', 'max:255'],
        ]);

        $data = json_decode((string) file_get_contents($request->file('backup')->getRealPath()), true);
        if (! is_array($data)) {
            throw ValidationException::withMessages(['backup' => 'This file is not valid JSON.']);
        }

        try {
            $company = CompanyBackup::restore($data, $request->user(), $request->input('name'));
        } catch (\InvalidArgumentException $e) {
            throw ValidationException::withMessages(['backup' => $e->getMessage()]);
        }

        return redirect()
            ->route('companies.show', $company)
            ->with('success', 'Backup restored as '.$company->name.'.');
    }
}
