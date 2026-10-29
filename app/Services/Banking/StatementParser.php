<?php

namespace App\Services\Banking;

use App\Support\Money;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Reads bank statement files into plain lines: CSV (columns found by header
 * name), OFX / QFX (version 1 SGML or version 2 XML), SWIFT MT940 and ISO 20022 CAMT.053 XML.
 *
 * Each line is [date, description, reference, withdrawal paise, deposit paise, external id].
 * The external id (OFX FITID, MT940 bank reference, CAMT entry reference) lets a file be imported again
 * without doubling its lines.
 */
class StatementParser
{
    public const FORMATS = ['csv' => 'CSV', 'ofx' => 'OFX / QFX', 'mt940' => 'MT940', 'camt053' => 'CAMT.053'];

    /** @return array{format: string, lines: array<int, array>, errors: array<int, string>} */
    public function parse(string $content): array
    {
        $content = preg_replace('/^\xEF\xBB\xBF/', '', $content);
        $format = $this->detect($content);
        [$lines, $errors] = match ($format) {
            'ofx' => $this->ofx($content),
            'mt940' => $this->mt940($content),
            'camt053' => $this->camt053($content),
            default => $this->csv($content),
        };

        return ['format' => $format, 'lines' => $lines, 'errors' => $errors];
    }

    public function detect(string $content): string
    {
        if (preg_match('/<(\w+:)?BkToCstmrStmt[\s>]/', substr($content, 0, 4000))) {
            return 'camt053';
        }
        if (preg_match('/OFXHEADER|<OFX>/i', substr($content, 0, 4000))) {
            return 'ofx';
        }
        if (preg_match('/^:20:/m', $content) && preg_match('/^:61:/m', $content)) {
            return 'mt940';
        }

        return 'csv';
    }

    // ------------------------------------------------------------------ CSV

    protected function csv(string $csv): array
    {
        $rows = array_values(array_filter(array_map('str_getcsv', preg_split('/\r\n|\r|\n/', trim($csv))), fn ($r) => array_filter($r, fn ($c) => trim((string) $c) !== '') !== []));
        if (count($rows) < 2) {
            return [[], ['The file needs a header row and at least one line.']];
        }

        $header = array_map(fn ($h) => Str::lower(trim((string) $h)), array_shift($rows));
        $find = function (array $names) use ($header) {
            foreach ($header as $i => $h) {
                foreach ($names as $name) {
                    // Short names (dr, cr, ref) must match a whole word, so "description" is not "cr".
                    if ($h === $name || (strlen($name) > 3 ? str_contains($h, $name) : in_array($name, preg_split('/[^a-z]+/', $h), true))) {
                        return $i;
                    }
                }
            }

            return null;
        };

        $col = [
            'date' => $find(['date']),
            'description' => $find(['description', 'narration', 'particulars', 'remarks']),
            'reference' => $find(['reference', 'ref', 'cheque', 'chq', 'utr']),
            'withdrawal' => $find(['withdrawal', 'debit', 'dr']),
            'deposit' => $find(['deposit', 'credit', 'cr']),
            'amount' => $find(['amount']),
        ];
        if ($col['date'] === null || (($col['withdrawal'] === null || $col['deposit'] === null) && $col['amount'] === null)) {
            return [[], ['Columns not recognised. The header needs Date and either Withdrawal + Deposit (or Debit + Credit) or Amount.']];
        }

        $lines = [];
        $errors = [];
        foreach ($rows as $n => $row) {
            $lineNo = $n + 2;
            $date = $this->parseDate($row[$col['date']] ?? '');
            if (! $date) {
                $errors[] = "Row {$lineNo}: unreadable date \"".($row[$col['date']] ?? '').'".';

                continue;
            }
            try {
                if ($col['withdrawal'] !== null && $col['deposit'] !== null) {
                    $out = abs(Money::toPaise($row[$col['withdrawal']] ?? ''));
                    $in = abs(Money::toPaise($row[$col['deposit']] ?? ''));
                } else {
                    $amount = Money::toPaise($row[$col['amount']] ?? '');
                    [$out, $in] = $amount < 0 ? [-$amount, 0] : [0, $amount];
                }
            } catch (\InvalidArgumentException) {
                $errors[] = "Row {$lineNo}: unreadable amount.";

                continue;
            }
            if (($out === 0) === ($in === 0)) {
                $errors[] = "Row {$lineNo}: enter either a withdrawal or a deposit.";

                continue;
            }
            $lines[] = [$date, trim((string) ($row[$col['description']] ?? '')), $col['reference'] !== null ? trim((string) ($row[$col['reference']] ?? '')) : '', $out, $in, null];
        }

        return [$lines, $errors];
    }

    // ------------------------------------------------------------------ OFX

    protected function ofx(string $content): array
    {
        if (! preg_match_all('/<STMTTRN>(.*?)(?:<\/STMTTRN>|(?=<STMTTRN>)|(?=<\/BANKTRANLIST>))/is', $content, $blocks)) {
            return [[], ['No transactions (STMTTRN) were found in the OFX file.']];
        }
        $lines = [];
        $errors = [];
        foreach ($blocks[1] as $n => $block) {
            $field = function (string $tag) use ($block): string {
                // SGML (OFX 1) leaves elements unclosed; XML (OFX 2) closes them.
                return preg_match('/<'.$tag.'>([^<\r\n]*)/i', $block, $m) ? html_entity_decode(trim($m[1]), ENT_QUOTES | ENT_XML1, 'UTF-8') : '';
            };
            $label = 'Transaction '.($n + 1);
            $posted = $field('DTPOSTED');
            $date = preg_match('/^(\d{4})(\d{2})(\d{2})/', $posted, $d) && checkdate((int) $d[2], (int) $d[3], (int) $d[1]) ? "{$d[1]}-{$d[2]}-{$d[3]}" : null;
            if (! $date) {
                $errors[] = "{$label}: unreadable date \"{$posted}\".";

                continue;
            }
            try {
                $amount = Money::toPaise(str_replace([' ', '+'], '', $field('TRNAMT')));
            } catch (\InvalidArgumentException) {
                $errors[] = "{$label}: unreadable amount.";

                continue;
            }
            if ($amount === 0) {
                continue; // nothing moved
            }
            $name = $field('NAME');
            $memo = $field('MEMO');
            $description = trim($name.($memo !== '' && $memo !== $name ? ' '.$memo : ''));
            $reference = $field('CHECKNUM') ?: $field('REFNUM');
            $fitid = $field('FITID');
            $lines[] = [$date, $description, $reference, $amount < 0 ? -$amount : 0, $amount > 0 ? $amount : 0, $fitid !== '' ? 'ofx:'.$fitid : null];
        }

        return [$lines, $errors];
    }

    // ---------------------------------------------------------------- MT940

    protected function mt940(string $content): array
    {
        // Join continuation lines onto their tag.
        $fields = [];
        foreach (preg_split('/\r\n|\r|\n/', $content) as $raw) {
            if (preg_match('/^:(\d{2}[A-Z]?):(.*)$/', $raw, $m)) {
                $fields[] = [$m[1], $m[2]];
            } elseif ($fields && trim($raw) !== '' && ! preg_match('/^-\}?$|^\{/', trim($raw))) {
                $fields[count($fields) - 1][1] .= "\n".$raw;
            }
        }

        $lines = [];
        $errors = [];
        $statement = null; // [reference, opening paise, sum paise, line count]
        $close = function (?int $closing) use (&$statement, &$errors) {
            if ($statement && $statement[1] !== null && $closing !== null && $statement[1] + $statement[2] !== $closing) {
                $errors[] = "Statement {$statement[0]}: the opening balance plus the transactions (".Money::format(Money::fromPaise($statement[1] + $statement[2])).') does not equal the closing balance ('.Money::format(Money::fromPaise($closing)).'). The file may be incomplete.';
            }
        };
        $last = null;
        foreach ($fields as [$tag, $value]) {
            switch ($tag) {
                case '20':
                    $statement = [trim($value), null, 0, 0];
                    break;
                case '60F':
                case '60M':
                    // Each page (60M … 62M) is checked on its own.
                    $statement ??= ['', null, 0, 0];
                    [$statement[1], $statement[2]] = [$this->mtBalance($value), 0];
                    break;
                case '62F':
                case '62M':
                    $close($this->mtBalance($value));
                    break;
                case '61':
                    $last = null;
                    $first = strtok($value, "\n");
                    if (! preg_match('/^(\d{2})(\d{2})(\d{2})(\d{4})?(R?[DC])[A-Z]?(\d+,\d*)[NSF][A-Z0-9]{3}([^\/]*)(?:\/\/(.*))?$/', trim($first), $m)) {
                        $errors[] = 'Line :61:'.$first.' could not be read.';
                        break;
                    }
                    $date = checkdate((int) $m[2], (int) $m[3], 2000 + (int) $m[1]) ? sprintf('20%s-%s-%s', $m[1], $m[2], $m[3]) : null;
                    if (! $date) {
                        $errors[] = 'Line :61:'.$first.': unreadable date.';
                        break;
                    }
                    $amount = Money::toPaise(str_replace(',', '.', rtrim($m[6], ',')) ?: '0');
                    // RD (reversal of a debit) is money in; RC money out.
                    $in = in_array($m[5], ['C', 'RD'], true);
                    $reference = trim($m[7]) === 'NONREF' ? '' : trim($m[7]);
                    $bankRef = trim($m[8] ?? '');
                    $extra = trim((string) substr($value, strlen($first)));
                    if ($statement) {
                        $statement[2] += $in ? $amount : -$amount;
                        $statement[3]++;
                    }
                    $lines[] = [$date, $extra, $reference, $in ? 0 : $amount, $in ? $amount : 0,
                        'mt940:'.($statement[0] ?? '').':'.($bankRef !== '' ? $bankRef : $date.':'.($statement[3] ?? count($lines)))];
                    $last = count($lines) - 1;
                    break;
                case '86':
                    if ($last !== null) {
                        $text = preg_replace('/\s*\n\s*/', ' ', trim($value));
                        // Structured ?20–?29 subfields hold the remittance text.
                        if (preg_match_all('/\?2\d([^?]*)/', $text, $sub)) {
                            $text = implode(' ', array_map('trim', $sub[1]));
                        }
                        $lines[$last][1] = trim($lines[$last][1].' '.$text);
                    }
                    break;
            }
        }
        if (! $lines && ! $errors) {
            $errors[] = 'No transactions (:61:) were found in the MT940 file.';
        }

        return [$lines, $errors];
    }

    // ------------------------------------------------------------- CAMT.053

    protected function camt053(string $content): array
    {
        // Namespaces are dropped so paths read the same for every camt.053 version.
        $xml = preg_replace(['/\sxmlns(:\w+)?="[^"]*"/', '/<(\/?)\w+:/'], ['', '<$1'], $content);
        if (preg_match('/<!DOCTYPE|<!ENTITY/i', $xml)) {
            return [[], ['The CAMT.053 file contains a document type declaration, which is not accepted.']];
        }
        $previous = libxml_use_internal_errors(true);
        $doc = simplexml_load_string($xml, \SimpleXMLElement::class, LIBXML_NONET);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        if ($doc === false) {
            return [[], ['The CAMT.053 file is not valid XML.']];
        }

        $lines = [];
        $errors = [];
        $n = 0;
        foreach ($doc->xpath('//BkToCstmrStmt/Stmt') ?: [] as $stmt) {
            $stmtId = trim((string) $stmt->Id);
            $balances = [];
            foreach ($stmt->Bal as $bal) {
                $code = trim((string) ($bal->Tp->CdOrPrtry->Cd ?? ''));
                $paise = $this->camtAmount((string) $bal->Amt);
                if ($paise !== null) {
                    $balances[$code] = trim((string) $bal->CdtDbtInd) === 'DBIT' ? -$paise : $paise;
                }
            }
            $sum = 0;
            foreach ($stmt->Ntry as $entry) {
                $n++;
                $label = "Entry {$n}";
                $status = trim((string) ($entry->Sts->Cd ?? $entry->Sts));
                if ($status !== '' && strtoupper($status) !== 'BOOK') {
                    continue; // pending or information only
                }
                $rawDate = trim((string) ($entry->BookgDt->Dt ?? '')) ?: substr(trim((string) ($entry->BookgDt->DtTm ?? '')), 0, 10) ?: trim((string) ($entry->ValDt->Dt ?? ''));
                $date = preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $rawDate, $d) && checkdate((int) $d[2], (int) $d[3], (int) $d[1]) ? $rawDate : null;
                if (! $date) {
                    $errors[] = "{$label}: unreadable booking date \"{$rawDate}\".";

                    continue;
                }
                $amount = $this->camtAmount((string) $entry->Amt);
                if ($amount === null) {
                    $errors[] = "{$label}: unreadable amount.";

                    continue;
                }
                $credit = trim((string) $entry->CdtDbtInd) === 'CRDT';
                if (strtolower(trim((string) $entry->RvslInd)) === 'true') {
                    $credit = ! $credit; // a reversal moves money the other way
                }
                $sum += $credit ? $amount : -$amount;
                $tx = $entry->NtryDtls->TxDtls ?? null;
                $text = array_filter(array_map('trim', [
                    (string) ($tx?->RltdPties->{$credit ? 'Dbtr' : 'Cdtr'}->Nm ?? $tx?->RltdPties->{$credit ? 'Dbtr' : 'Cdtr'}->Pty->Nm ?? ''),
                    implode(' ', array_map('strval', $tx ? $tx->xpath('RmtInf/Ustrd') ?: [] : [])),
                    (string) $entry->AddtlNtryInf,
                ]));
                $reference = trim((string) ($tx?->Refs->EndToEndId ?? '')) ?: trim((string) ($tx?->Refs->InstrId ?? '')) ?: trim((string) ($tx?->Refs->ChqNb ?? ''));
                if (strtoupper($reference) === 'NOTPROVIDED') {
                    $reference = '';
                }
                $id = trim((string) $entry->NtryRef) ?: trim((string) $entry->AcctSvcrRef) ?: trim((string) ($tx?->Refs->AcctSvcrRef ?? ''));
                $lines[] = [$date, mb_substr(implode(' · ', array_unique($text)), 0, 500), mb_substr($reference, 0, 60), $credit ? 0 : $amount, $credit ? $amount : 0,
                    'camt:'.$stmtId.':'.($id !== '' ? $id : $date.':'.$n)];
            }
            if (isset($balances['OPBD'], $balances['CLBD']) && $balances['OPBD'] + $sum !== $balances['CLBD']) {
                $errors[] = "Statement {$stmtId}: the opening balance plus the booked entries (".Money::format(Money::fromPaise($balances['OPBD'] + $sum)).') does not equal the closing balance ('.Money::format(Money::fromPaise($balances['CLBD'])).'). The file may be incomplete.';
            }
        }
        if (! $lines && ! $errors) {
            $errors[] = 'No booked entries (Ntry) were found in the CAMT.053 file.';
        }

        return [$lines, $errors];
    }

    protected function camtAmount(string $value): ?int
    {
        $value = trim($value);
        if (! preg_match('/^\d+(\.\d{1,5})?$/', $value)) {
            return null;
        }

        // Two decimals are exact; longer ones (allowed by the schema) are rounded to paise.
        return Money::toPaise(preg_match('/^\d+(\.\d{1,2})?$/', $value) ? $value : number_format((float) $value, 2, '.', ''));
    }

    /** "C261031INR12345,67" => signed paise. */
    protected function mtBalance(string $value): ?int
    {
        if (! preg_match('/^([CD])\d{6}[A-Z]{3}(\d+,\d*)/', trim($value), $m)) {
            return null;
        }
        $paise = Money::toPaise(str_replace(',', '.', rtrim($m[2], ',')) ?: '0');

        return $m[1] === 'D' ? -$paise : $paise;
    }

    public function parseDate(string $value): ?string
    {
        $value = trim($value);
        foreach (['Y-m-d', 'd-m-Y', 'd/m/Y', 'd.m.Y', 'd-M-Y', 'd M Y', 'd/m/y', 'd-m-y', 'd-M-y'] as $format) {
            try {
                $date = Carbon::createFromFormat('!'.$format, $value);
                if ($date && $date->format($format) === $value) {
                    return $date->toDateString();
                }
            } catch (\Throwable) {
            }
        }

        return null;
    }
}
