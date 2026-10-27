<?php

namespace App\Services\Data;

use App\Models\AuditLog;
use App\Models\BackupLog;
use App\Models\Company;
use App\Models\User;
use App\Services\CompanyDeletionService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;
use ZipArchive;

/**
 * Company backups: one zip per company holding manifest.json, one JSON file
 * per table and the logo. The manifest carries a SHA-256 per table file and
 * the row counts, so a backup can be verified before it is restored.
 *
 * Restore replaces the company's books with the backup in one transaction,
 * keeping the original ids. Users, roles and the audit trail are not part of a
 * backup and are left as they are. A safety backup is taken first.
 */
class BackupService
{
    public const FORMAT = 1;

    /** Refuse archives that unpack to more than this (protects memory during verify and restore). */
    public const MAX_UNPACKED_BYTES = 1024 * 1024 * 1024;

    /** Company fields saved in the manifest and put back on restore. */
    protected const COMPANY_FIELDS = [
        'name', 'legal_name', 'mailing_name', 'pan', 'cin', 'tan', 'email', 'phone', 'website',
        'address', 'city', 'state', 'pincode', 'country', 'financial_year', 'books_begin_date', 'currency', 'settings',
    ];

    /** Rows of tables without a company_id, found through their parent table. */
    protected const CHILD_TABLES = ['voucher_number_sequences' => ['voucher_type_id', 'voucher_types']];

    /** Tables with a company_id, parents first (the reverse of the deletion order), => self-reference column. */
    public static function tables(): array
    {
        $tables = array_reverse(CompanyDeletionService::tables(), true);
        unset($tables['audit_logs'], $tables['roles']);

        return array_filter($tables, fn ($v, $t) => Schema::hasTable($t), ARRAY_FILTER_USE_BOTH);
    }

    public function create(Company $company, ?User $user, string $type = 'manual'): BackupLog
    {
        $dir = (string) $company->id;
        $name = Str::slug($company->name).'-'.now()->format('Ymd-His').'-'.Str::lower(Str::random(4)).'.zip';
        $full = BackupLog::root()."/{$dir}/{$name}";
        if (! is_dir(dirname($full))) {
            mkdir(dirname($full), 0775, true);
        }

        try {
            $zip = new ZipArchive;
            if ($zip->open($full, ZipArchive::CREATE | ZipArchive::EXCL) !== true) {
                throw new \RuntimeException('Could not create the backup file.');
            }

            $manifest = [
                'format' => self::FORMAT,
                'app' => config('app.name'),
                'created_at' => now()->toIso8601String(),
                'company' => ['id' => $company->id, 'gstin' => $company->gstin] + array_intersect_key((array) DB::table('companies')->find($company->id), array_flip(self::COMPANY_FIELDS)),
                'migrations' => DB::table('migrations')->orderBy('id')->pluck('migration')->all(),
                'tables' => [],
                'logo' => null,
            ];
            $summary = [];

            foreach (array_keys(static::tables() + self::CHILD_TABLES) as $table) {
                $rows = $this->rowsFor($company, $table);
                $json = json_encode($rows, JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR);
                $zip->addFromString("tables/{$table}.json", $json);
                $manifest['tables'][$table] = ['rows' => count($rows), 'sha256' => hash('sha256', $json)];
                $summary[$table] = count($rows);
            }

            if ($company->logo_path && Storage::disk('public')->exists($company->logo_path)) {
                $manifest['logo'] = 'logo/'.basename($company->logo_path);
                $zip->addFromString($manifest['logo'], Storage::disk('public')->get($company->logo_path));
            }

            $zip->addFromString('manifest.json', json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
            $zip->close();

            $log = BackupLog::create([
                'company_id' => $company->id, 'type' => $type, 'status' => 'completed',
                'file_name' => $name, 'path' => "{$dir}/{$name}", 'size' => filesize($full), 'sha256' => hash_file('sha256', $full),
                'summary' => $summary, 'created_by' => $user?->id,
            ]);
            AuditLog::record('backup_created', $log, null, ['file' => $name, 'type' => $type], "Backup created: {$name}");

            return $log;
        } catch (Throwable $e) {
            if (is_file($full)) {
                @unlink($full);
            }
            report($e);

            return BackupLog::create([
                'company_id' => $company->id, 'type' => $type, 'status' => 'failed',
                'file_name' => $name, 'error' => mb_substr($e->getMessage(), 0, 2000), 'created_by' => $user?->id,
            ]);
        }
    }

    /** Takes an uploaded backup zip into the company's backup folder so it can be verified and restored. */
    public function import(Company $company, string $uploadedPath, string $originalName, User $user): BackupLog
    {
        $dir = (string) $company->id;
        $name = 'uploaded-'.now()->format('Ymd-His').'-'.Str::slug(pathinfo($originalName, PATHINFO_FILENAME)).'.zip';
        $full = BackupLog::root()."/{$dir}/{$name}";
        if (! is_dir(dirname($full))) {
            mkdir(dirname($full), 0775, true);
        }
        copy($uploadedPath, $full);

        $manifest = $this->readManifest($full);
        $log = BackupLog::create([
            'company_id' => $company->id, 'type' => 'uploaded', 'status' => 'completed',
            'file_name' => $name, 'path' => "{$dir}/{$name}", 'size' => filesize($full), 'sha256' => hash_file('sha256', $full),
            'summary' => collect($manifest['tables'] ?? [])->map(fn ($t) => (int) ($t['rows'] ?? 0))->all(), 'created_by' => $user->id,
        ]);
        $this->verify($log);

        return $log->fresh();
    }

    /**
     * Checks the file and its contents. Returns the problems found (empty = good) and records the result.
     *
     * @return list<string>
     */
    public function verify(BackupLog $log, ?Company $company = null): array
    {
        $issues = [];
        $company ??= Company::find($log->company_id);

        if (! $log->fileExists()) {
            $issues[] = 'The backup file is missing.';
        } elseif ($log->sha256 && ! hash_equals($log->sha256, hash_file('sha256', $log->fullPath()))) {
            $issues[] = 'The backup file has changed since it was made (checksum does not match).';
        } else {
            try {
                $issues = $this->checkContents($log->fullPath(), $company);
            } catch (Throwable $e) {
                $issues[] = 'The backup file cannot be read: '.$e->getMessage();
            }
        }

        $log->update(['verified_at' => now(), 'verified_ok' => ! $issues]);

        return $issues;
    }

    /** Replaces the company's books with the backup. */
    public function restore(BackupLog $log, Company $company, User $user): BackupLog
    {
        if ($log->company_id !== $company->id) {
            throw ValidationException::withMessages(['backup' => 'This backup belongs to another company.']);
        }
        $issues = $this->verify($log, $company);
        if ($issues) {
            throw ValidationException::withMessages(['backup' => $issues]);
        }

        $safety = $this->create($company, $user, 'pre_restore');
        if ($safety->status !== 'completed') {
            throw ValidationException::withMessages(['backup' => 'A safety backup of the current data could not be made, so nothing was restored: '.$safety->error]);
        }

        $zip = $this->open($log->fullPath());
        $manifest = json_decode($zip->getFromName('manifest.json'), true);
        $tables = static::tables();

        DB::transaction(function () use ($zip, $manifest, $tables, $company, $user, $log) {
            $this->wipe($company);

            $userIds = DB::table('users')->pluck('id')->flip();
            $selfRefs = [];
            foreach (array_keys($tables + self::CHILD_TABLES) as $table) {
                $rows = json_decode($zip->getFromName("tables/{$table}.json") ?: '[]', true, 512, JSON_THROW_ON_ERROR);
                $columns = array_flip(Schema::getColumnListing($table));
                $userColumns = collect(Schema::getForeignKeys($table))->where('foreign_table', 'users')->pluck('columns.0')->all();
                $self = $tables[$table] ?? null;

                $prepared = [];
                foreach ($rows as $row) {
                    $row = array_intersect_key($row, $columns);
                    if (isset($columns['company_id'])) {
                        $row['company_id'] = $company->id;
                    }
                    foreach ($userColumns as $c) {
                        if (! empty($row[$c]) && ! isset($userIds[$row[$c]])) {
                            $row[$c] = null;
                        }
                    }
                    if ($self && ! empty($row[$self])) {
                        $selfRefs[$table][$row['id']] = $row[$self];
                        $row[$self] = null;
                    }
                    $prepared[] = $row;
                }
                foreach (array_chunk($prepared, 200) as $chunk) {
                    DB::table($table)->insert($chunk);
                }
            }
            foreach ($selfRefs as $table => $pairs) {
                foreach ($pairs as $id => $parent) {
                    DB::table($table)->where('id', $id)->update([$tables[$table] => $parent]);
                }
            }

            $this->assertReferencesStayInCompany($company);

            $fields = array_intersect_key($manifest['company'] ?? [], array_flip(self::COMPANY_FIELDS));
            if (array_key_exists('settings', $fields) && is_array($fields['settings'])) {
                $fields['settings'] = json_encode($fields['settings']);
            }
            DB::table('companies')->where('id', $company->id)->update($fields + ['updated_at' => now()]);

            $logo = ! empty($manifest['logo']) ? $zip->getFromName((string) $manifest['logo']) : false;
            $extension = strtolower(pathinfo((string) ($manifest['logo'] ?? ''), PATHINFO_EXTENSION));
            // Only a real image with an image extension is written to the public disk.
            if ($logo !== false && in_array($extension, ['png', 'jpg', 'jpeg', 'webp'], true) && @getimagesizefromstring($logo)) {
                $path = "logos/{$company->id}/".Str::random(20).'.'.$extension;
                Storage::disk('public')->put($path, $logo);
                DB::table('companies')->where('id', $company->id)->update(['logo_path' => $path]);
            }

            $log->update(['status' => 'restored', 'restored_at' => now(), 'restored_by' => $user->id]);
            AuditLog::record('backup_restored', $log, null, ['file' => $log->file_name, 'rows' => $log->totalRows()], "Backup restored: {$log->file_name}");
        });
        $zip->close();

        return $log->fresh();
    }

    /** Deletes old automatic backups beyond the number to keep. Manual and uploaded backups are kept. */
    public function prune(Company $company, int $keep): int
    {
        $old = BackupLog::query()->withoutGlobalScopes()->where('company_id', $company->id)->where('type', 'scheduled')
            ->orderByDesc('created_at')->orderByDesc('id')->skip(max($keep, 1))->take(1000)->get();
        foreach ($old as $log) {
            $this->delete($log);
        }

        return $old->count();
    }

    public function delete(BackupLog $log): void
    {
        if ($log->fileExists()) {
            unlink($log->fullPath());
        }
        $log->delete();
    }

    /** @return list<string> */
    protected function checkContents(string $path, ?Company $company): array
    {
        $issues = [];
        $zip = $this->open($path);
        $manifest = $this->readManifest($path);

        $unpacked = 0;
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $unpacked += (int) ($zip->statIndex($i)['size'] ?? 0);
        }
        if ($unpacked > self::MAX_UNPACKED_BYTES) {
            return ['The backup is too large to restore here ('.round($unpacked / 1048576).' MB unpacked).'];
        }
        if (($manifest['format'] ?? null) !== self::FORMAT) {
            return ['This file is not a backup made by this application, or it is from an unsupported version.'];
        }
        $unknown = array_diff($manifest['migrations'] ?? [], DB::table('migrations')->pluck('migration')->all());
        if ($unknown) {
            $issues[] = 'The backup was made by a newer version of the application ('.count($unknown).' database changes are not installed here).';
        }
        if ($company && (int) ($manifest['company']['id'] ?? 0) !== $company->id) {
            $issues[] = 'The backup is of another company ('.($manifest['company']['name'] ?? '?').').';
        }

        $data = [];
        foreach (array_keys(static::tables() + self::CHILD_TABLES) as $table) {
            $meta = $manifest['tables'][$table] ?? null;
            $json = $zip->getFromName("tables/{$table}.json");
            if (! $meta) {
                continue; // a table added after the backup was made: restored empty
            }
            if ($json === false) {
                $issues[] = "Table {$table} is missing from the backup.";

                continue;
            }
            if (! hash_equals((string) $meta['sha256'], hash('sha256', $json))) {
                $issues[] = "Table {$table} has been altered (checksum does not match).";

                continue;
            }
            $rows = json_decode($json, true);
            if (! is_array($rows) || count($rows) !== (int) $meta['rows']) {
                $issues[] = "Table {$table} does not have the expected number of rows.";

                continue;
            }
            if (in_array($table, ['vouchers', 'voucher_entries'], true)) {
                $data[$table] = $rows;
            }
        }
        $zip->close();

        // Posted vouchers in the backup must balance.
        if (isset($data['vouchers'], $data['voucher_entries'])) {
            $posted = collect($data['vouchers'])->where('status', 'posted')->pluck('voucher_number', 'id');
            $diff = [];
            foreach ($data['voucher_entries'] as $e) {
                if ($posted->has($e['voucher_id'])) {
                    $diff[$e['voucher_id']] = ($diff[$e['voucher_id']] ?? 0) + (int) round($e['debit'] * 100) - (int) round($e['credit'] * 100);
                }
            }
            $unbalanced = array_keys(array_filter($diff));
            if ($unbalanced) {
                $issues[] = count($unbalanced).' posted voucher(s) in the backup do not balance, e.g. '.$posted[$unbalanced[0]].'.';
            }
        }

        return $issues;
    }

    protected function readManifest(string $path): array
    {
        $zip = $this->open($path);
        $raw = $zip->getFromName('manifest.json');
        $zip->close();
        $manifest = $raw === false ? null : json_decode($raw, true);
        if (! is_array($manifest)) {
            throw ValidationException::withMessages(['file' => 'This is not a backup file (manifest.json is missing).']);
        }

        return $manifest;
    }

    protected function open(string $path): ZipArchive
    {
        $zip = new ZipArchive;
        if ($zip->open($path, ZipArchive::RDONLY) !== true) {
            throw ValidationException::withMessages(['file' => 'The file is not a valid zip archive.']);
        }

        return $zip;
    }

    protected function rowsFor(Company $company, string $table): array
    {
        $query = isset(self::CHILD_TABLES[$table])
            ? DB::table($table)->whereIn(self::CHILD_TABLES[$table][0], DB::table(self::CHILD_TABLES[$table][1])->where('company_id', $company->id)->select('id'))
            : DB::table($table)->where('company_id', $company->id);

        return $query->orderBy('id')->get()->map(fn ($r) => (array) $r)->all();
    }

    /** Removes the company's books, children first (as CompanyDeletionService does, but keeping the company). */
    protected function wipe(Company $company): void
    {
        foreach (self::CHILD_TABLES as $table => [$column, $parent]) {
            DB::table($table)->whereIn($column, DB::table($parent)->where('company_id', $company->id)->select('id'))->delete();
        }
        foreach (array_reverse(static::tables(), true) as $table => $self) {
            if ($self) {
                DB::table($table)->where('company_id', $company->id)->update([$self => null]);
            }
            DB::table($table)->where('company_id', $company->id)->delete();
        }
    }

    /** Every reference from a restored row to another company table must point at a row of the same company. */
    protected function assertReferencesStayInCompany(Company $company): void
    {
        $companyTables = array_keys(static::tables());
        foreach ($companyTables as $table) {
            foreach (Schema::getForeignKeys($table) as $fk) {
                if (! in_array($fk['foreign_table'], $companyTables, true)) {
                    continue;
                }
                $column = $fk['columns'][0];
                $bad = DB::table($table)->where('company_id', $company->id)->whereNotNull($column)
                    ->whereNotIn($column, DB::table($fk['foreign_table'])->where('company_id', $company->id)->select('id'))
                    ->count();
                if ($bad) {
                    throw ValidationException::withMessages(['backup' => "The backup has {$bad} row(s) in {$table} that point outside the company ({$column}). Nothing was restored."]);
                }
            }
        }
    }
}
