<?php

namespace App\Http\Controllers\Data;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\BackupLog;
use App\Services\Data\BackupService;
use App\Support\CurrentCompany;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class BackupController extends Controller
{
    public function __construct(protected BackupService $backups) {}

    public function index(CurrentCompany $current)
    {
        return view('data.backups', [
            'logs' => BackupLog::query()->with('creator', 'restorer')->orderByDesc('created_at')->orderByDesc('id')->paginate(25),
            'company' => $current->get(),
            'tables' => BackupService::tables(),
        ]);
    }

    public function store(Request $request, CurrentCompany $current)
    {
        $log = $this->backups->create($current->get(), $request->user());
        if ($log->status !== 'completed') {
            return back()->with('error', 'The backup failed: '.$log->error);
        }
        $issues = $this->backups->verify($log);

        return back()->with($issues ? 'warning' : 'success', $issues
            ? "Backup {$log->file_name} was made but did not verify: ".implode(' ', $issues)
            : "Backup {$log->file_name} made and verified ({$log->totalRows()} records).");
    }

    public function upload(Request $request, CurrentCompany $current)
    {
        $request->validate(['file' => ['required', 'file', 'mimes:zip', 'max:204800'], 'passphrase' => ['nullable', 'string', 'max:200']], ['file.mimes' => 'Choose a backup .zip file made by this application.']);
        $log = $this->backups->import($current->get(), $request->file('file')->getRealPath(), $request->file('file')->getClientOriginalName(), $request->user(), $request->input('passphrase'));

        return back()->with($log->verified_ok ? 'success' : 'warning', $log->verified_ok
            ? "Backup uploaded and verified ({$log->totalRows()} records). You can restore it from the list."
            : 'The file was uploaded but did not verify. Use Verify to see why; it cannot be restored.');
    }

    public function download(BackupLog $backupLog)
    {
        abort_unless($backupLog->fileExists(), 404, 'The backup file is missing.');
        AuditLog::record('backup_downloaded', $backupLog, null, null, "Backup downloaded: {$backupLog->file_name}");

        return response()->download($backupLog->fullPath(), $backupLog->file_name, ['Content-Type' => 'application/zip']);
    }

    public function verify(BackupLog $backupLog)
    {
        $issues = $this->backups->verify($backupLog);

        return back()->with($issues ? 'error' : 'success', $issues
            ? 'Verification failed: '.implode(' ', $issues)
            : "{$backupLog->file_name} verified: the file is intact and its vouchers balance.");
    }

    public function restore(Request $request, CurrentCompany $current, BackupLog $backupLog)
    {
        $company = $current->get();
        $request->validate(['confirm' => ['required', 'string']]);
        if (trim($request->input('confirm')) !== $company->name) {
            throw ValidationException::withMessages(['confirm' => 'Type the company name exactly to confirm the restore.']);
        }
        $this->backups->restore($backupLog, $company, $request->user());

        return redirect()->route('backups.index')->with('success', "Restored {$backupLog->file_name}. A safety backup of the data before the restore is at the top of the list.");
    }

    public function destroy(BackupLog $backupLog)
    {
        AuditLog::record('backup_deleted', $backupLog, null, null, "Backup deleted: {$backupLog->file_name}");
        $this->backups->delete($backupLog);

        return back()->with('success', 'Backup deleted.');
    }

    /** Sets, changes or removes the passphrase that encrypts new backups. */
    public function passphrase(Request $request, CurrentCompany $current)
    {
        $company = $current->get();
        if ($request->boolean('remove')) {
            $this->backups->setPassphrase($company, null, $request->user());

            return back()->with('success', 'Backup encryption turned off. New backups are not encrypted; existing encrypted backups still open with their passphrase.');
        }
        $data = $request->validate([
            'passphrase' => ['required', 'string', 'min:'.BackupService::MIN_PASSPHRASE, 'max:200', 'confirmed'],
        ], ['passphrase.min' => 'Use at least '.BackupService::MIN_PASSPHRASE.' characters; a few words make a good passphrase.']);
        $this->backups->setPassphrase($company, $data['passphrase'], $request->user());

        return back()->with('success', 'Backup passphrase saved. New backups are encrypted. Keep the passphrase safe: a downloaded backup cannot be opened elsewhere without it.');
    }

    public function schedule(Request $request, CurrentCompany $current)
    {
        $data = $request->validate([
            'backup_frequency' => ['required', 'in:off,daily,weekly'],
            'backup_keep' => ['required', 'integer', 'min:1', 'max:90'],
        ]);
        $company = $current->get();
        $company->update(['settings' => array_merge($company->settings ?? [], $data + ['backup_keep' => (int) $data['backup_keep']])]);

        return back()->with('success', 'Backup schedule saved.');
    }
}
