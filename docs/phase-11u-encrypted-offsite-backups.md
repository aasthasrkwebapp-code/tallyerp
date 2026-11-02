# 11u. Encrypted and off-site backups

Company backups (Data > Backup & Restore) were plain zip files kept on the
server. Anyone with a downloaded file could read the books in it, and a lost
server meant lost backups unless someone had downloaded them. Backups can now
be encrypted, and copied to cloud storage as they are made.

## Encryption

The **Encryption** box on the Backup & Restore page sets a passphrase for the
company (needs `data.backup`):

- **Passphrase:**
  - at least 12 characters, typed twice
  - stored on the server, encrypted with the application key (`APP_KEY`)
  - never shown again, and never written to a backup, JSON output or the
    audit log
- **New backups** (manual, scheduled and the safety backup before a restore):
  - every file in the zip, including the manifest, is encrypted with AES-256
  - 7-Zip, WinZip or any tool that supports AES zip opens them with the
    passphrase
  - the list marks them with a lock
- **Each backup keeps the passphrase it was made with**, so changing the
  passphrase later does not lock out older backups. They still verify and
  restore here.
- **Uploading an encrypted backup** needs its passphrase. Left blank, the
  company's current passphrase is tried. A wrong passphrase is refused before
  anything is stored.
- **Turn encryption off** stops encrypting new backups. Existing encrypted
  backups are unchanged.

Verification and restore work as before: the table checksums, the row counts
and the balanced-voucher check all run on the decrypted contents.

## Off-site copies

Set `ERP_BACKUP_OFFSITE_DISK` to a disk in `config/filesystems.php` (for
example `s3`) and every backup is also copied there, under
`erp-backups/<company id>/` (`ERP_BACKUP_OFFSITE_PREFIX` changes the folder).

- The list shows when each copy was made.
- If the copy fails, the backup is kept on the server and the error is shown
  on its row.
- With encryption on, the off-site copy is the encrypted file.

The page says whether an off-site disk is set up. `docs/deployment.md` lists
the S3 settings.

## Tests

`tests/Feature/Data/BackupTest.php`:

- `test_encrypted_backups_verify_restore_and_need_the_passphrase`:
  - a short passphrase is refused
  - the stored passphrase is encrypted, hidden from JSON and absent from the
    audit log
  - every zip entry is AES-256 and unreadable without the passphrase
  - the backup verifies, and restores after the passphrase has changed
  - the safety backup is encrypted
  - an upload with no passphrase or the wrong one is refused, and the right
    one verifies
  - turning encryption off makes plain backups
  - a viewer cannot change the setting
- `test_backups_are_copied_off_site_when_a_disk_is_set`:
  - no copy without a disk
  - with a disk, a copy with the same checksum, shown on the page
  - a failing disk leaves the backup completed and records the error

## Manual checklist

1. Set a backup passphrase and press **Back up now**. The new row has a lock.
2. Download it and open it in 7-Zip: it asks for the passphrase.
3. Change the passphrase, then verify and restore the earlier backup.
4. Upload the downloaded file with a wrong passphrase (refused), then with
   the right one.
5. With `ERP_BACKUP_OFFSITE_DISK` set, back up and check the bucket and the
   "off-site" note on the row.
6. Check the page at phone width.

## Done, partial, missing

- **Done:**
  - AES-256 encrypted backups, with a passphrase kept per backup
  - encrypted uploads
  - off-site copies with their status
- **Missing:**
  - pruning old off-site copies (the bucket's own lifecycle rules can do this)
  - restoring directly from the off-site disk (download the file and upload
    it instead)
