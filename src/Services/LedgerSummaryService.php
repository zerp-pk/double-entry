<?php

namespace Zerp\DoubleEntry\Services;

use Zerp\Account\Models\ChartOfAccount;
use Zerp\Account\Models\OpeningBalance;
use Zerp\Account\Models\JournalEntry;
use Zerp\Account\Models\JournalEntryItem;
use Illuminate\Support\Facades\DB;

class LedgerSummaryService
{
    public function getAllLedgerEntries($fromDate = null, $toDate = null, $accountId = null, $paginate = true)
    {
        $query = JournalEntryItem::select(
                'journal_entry_items.id',
                'journal_entries.journal_date',
                'journal_entries.reference_type',
                'journal_entries.description as journal_description',
                'journal_entry_items.description',
                'journal_entry_items.debit_amount',
                'journal_entry_items.credit_amount',
                'chart_of_accounts.account_code',
                'chart_of_accounts.account_name'
            )
            ->join('journal_entries', 'journal_entry_items.journal_entry_id', '=', 'journal_entries.id')
            ->join('chart_of_accounts', 'journal_entry_items.account_id', '=', 'chart_of_accounts.id')
            ->where('journal_entries.status', 'posted')
            ->where('journal_entries.created_by', creatorId())
            ->when($fromDate, fn($q) => $q->where('journal_entries.journal_date', '>=', $fromDate))
            ->when($toDate, fn($q) => $q->where('journal_entries.journal_date', '<=', $toDate))
            ->when($accountId, fn($q) => $q->where('journal_entry_items.account_id', $accountId))
            ->when(request('search'), fn($q) => $q->where(function($query) {
                $query->where('chart_of_accounts.account_code', 'like', '%' . request('search') . '%')
                      ->orWhere('chart_of_accounts.account_name', 'like', '%' . request('search') . '%')
                      ->orWhere('journal_entry_items.description', 'like', '%' . request('search') . '%')
                      ->orWhere('journal_entries.description', 'like', '%' . request('search') . '%');
            }))
            ->when(request('sort'), function ($q) {
                // Not sortSafe: this query is joined, so the sortable columns live on
                // other tables and would not be found on the base table. The three the
                // UI marks sortable are mapped explicitly; anything else is ignored.
                $sortable = [
                    'journal_date' => 'journal_entries.journal_date',
                    'account_code' => 'chart_of_accounts.account_code',
                    'account_name' => 'chart_of_accounts.account_name',
                ];
                $column = $sortable[request('sort')] ?? 'journal_entries.journal_date';
                $direction = in_array(strtolower((string) request('direction')), ['asc', 'desc'], true)
                    ? strtolower(request('direction'))
                    : 'desc';

                return $q->orderBy($column, $direction);
            }, fn($q) => $q->orderBy('journal_entries.journal_date', 'desc')->orderBy('journal_entry_items.id', 'desc'));

        if ($paginate) {
            return $query->paginate(request('per_page', 10))->withQueryString();
        }

        return $query->get();
    }


}
