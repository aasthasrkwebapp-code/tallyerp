<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Support\Facades\Route;

/**
 * Alt + key shortcuts to pages. Each user picks their own keys from the pages below;
 * until they do, DEFAULTS apply. The keys in RESERVED act on the page itself (save,
 * add a line, create, alter) and can't be given to a page.
 */
class Shortcuts
{
    /** page => [label, route, route parameters, permission] */
    public const PAGES = [
        'dashboard' => ['Dashboard', 'dashboard', [], null],
        'new_voucher' => ['New voucher', 'vouchers.create', [], 'vouchers.create'],
        'ledgers' => ['Ledgers', 'ledgers.index', [], 'masters.view'],
        'trial_balance' => ['Trial balance', 'reports.trial-balance', [], 'reports.view'],
        'day_book' => ['Day book', 'reports.day-book', [], 'reports.view'],
        'profit_loss' => ['Profit & loss', 'reports.profit-loss', [], 'reports.view'],
        'balance_sheet' => ['Balance sheet', 'reports.balance-sheet', [], 'reports.view'],
        'outstanding' => ['Outstanding', 'reports.outstanding', [], 'reports.view'],
        'new_sale' => ['New sales invoice', 'invoices.create', ['kind' => 'sales'], 'sales.create'],
        'sales' => ['Sales invoices', 'invoices.index', ['kind' => 'sales'], 'sales.view'],
        'new_purchase' => ['New purchase bill', 'invoices.create', ['kind' => 'purchase'], 'purchases.create'],
        'stock_items' => ['Stock items', 'stock-items.index', [], 'inventory.view'],
        'stock_summary' => ['Stock summary', 'stock-summary', [], 'inventory.view'],
        'gstr3b' => ['GSTR-3B', 'gst.gstr3b', [], 'gst.view'],
    ];

    public const DEFAULTS = ['d' => 'dashboard', 'v' => 'new_voucher', 'l' => 'ledgers', 't' => 'trial_balance', 'b' => 'day_book'];

    /** Keys the page itself uses. */
    public const RESERVED = ['s' => 'Save the current form', 'a' => 'Add a line or item', 'c' => 'Create new (on lists)', 'e' => 'Alter (on a document)'];

    /** @return array<string, string> key => page */
    public static function keysFor(?User $user): array
    {
        return $user?->shortcuts ?? self::DEFAULTS;
    }

    /**
     * The user's shortcuts they may open in the current company.
     *
     * @return array<string, array{label: string, url: string}>
     */
    public static function for(?User $user): array
    {
        $out = [];
        foreach (self::keysFor($user) as $key => $page) {
            [$label, $route, $params, $permission] = self::PAGES[$page] ?? [null, null, [], null];
            if (! $route || ! Route::has($route) || ($permission && ! $user?->can($permission))) {
                continue;
            }
            $out[$key] = ['label' => $label, 'url' => route($route, $params)];
        }
        ksort($out);

        return $out;
    }

    /**
     * Check a page => key choice (blank for none).
     *
     * @param  array<string, ?string>  $choice
     * @return array{0: array<string, string>, 1: array<string, string>} key => page, and errors by page
     */
    public static function parse(array $choice): array
    {
        $keys = [];
        $errors = [];
        foreach (self::PAGES as $page => [$label]) {
            $key = strtolower(trim((string) ($choice[$page] ?? '')));
            if ($key === '') {
                continue;
            }
            if (! preg_match('/^[a-z0-9]$/', $key)) {
                $errors[$page] = "{$label}: use a single letter or digit.";
            } elseif (isset(self::RESERVED[$key])) {
                $errors[$page] = "{$label}: Alt + ".strtoupper($key).' is kept for '.mb_strtolower(self::RESERVED[$key]).'.';
            } elseif (isset($keys[$key])) {
                $errors[$page] = "{$label}: Alt + ".strtoupper($key).' is already used for '.self::PAGES[$keys[$key]][0].'.';
            } else {
                $keys[$key] = $page;
            }
        }
        ksort($keys);

        return [$keys, $errors];
    }
}
