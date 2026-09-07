<?php

namespace Zerp\DoubleEntry\Services;

use Zerp\Account\Models\ChartOfAccount;
use Zerp\Account\Models\OpeningBalance;
use Zerp\Account\Models\JournalEntry;
use Zerp\Account\Models\JournalEntryItem;
use Illuminate\Support\Facades\DB;
use Zerp\DoubleEntry\Support\Money;

class ReportService
{
    public function getGeneralLedger($filters = [])
    {
        $accountId = $filters['account_id'] ?? null;
        $fromDate = $filters['from_date'] ?? null;
        $toDate = $filters['to_date'] ?? null;

        $query = JournalEntryItem::select(
                'journal_entry_items.id',
                'journal_entries.journal_date',
                'journal_entries.reference_type',
                'journal_entries.reference_id',
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
            ->when($accountId, fn($q) => $q->where('journal_entry_items.account_id', $accountId))
            ->when($fromDate, fn($q) => $q->where('journal_entries.journal_date', '>=', $fromDate))
            ->when($toDate, fn($q) => $q->where('journal_entries.journal_date', '<=', $toDate))
            ->orderBy('journal_entries.journal_date', 'asc')
            ->orderBy('journal_entry_items.id', 'asc');

        $entries = $query->get();

        $openingBalance = 0;
        if ($accountId && $fromDate) {
            $openingBalance = $this->getOpeningBalance($accountId, $fromDate);
        }

        // Accumulated in cents so a long ledger does not drift; each row is
        // converted back for display.
        $runningBalanceCents = Money::toCents($openingBalance);
        $transactions = $entries->map(function ($entry) use (&$runningBalanceCents) {
            $runningBalanceCents += Money::toCents($entry->debit_amount) - Money::toCents($entry->credit_amount);
            return [
                'id' => $entry->id,
                'date' => $entry->journal_date,
                'account_code' => $entry->account_code,
                'account_name' => $entry->account_name,
                'description' => $entry->description,
                'reference_type' => $entry->reference_type,
                'reference_id' => $entry->reference_id,
                'debit' => $entry->debit_amount,
                'credit' => $entry->credit_amount,
                'balance' => Money::toAmount($runningBalanceCents),
            ];
        });

        return [
            'opening_balance' => $openingBalance,
            'transactions' => $transactions,
            'closing_balance' => Money::toAmount($runningBalanceCents),
        ];
    }

    public function getOpeningBalance($accountId, $date)
    {
        $openingBalance = OpeningBalance::where('account_id', $accountId)
            ->where('created_by', creatorId())
            ->first();

        $balanceCents = $openingBalance
            ? Money::toCents($openingBalance->debit_amount) - Money::toCents($openingBalance->credit_amount)
            : 0;

        $priorTransactions = JournalEntryItem::join('journal_entries', 'journal_entry_items.journal_entry_id', '=', 'journal_entries.id')
            ->where('journal_entry_items.account_id', $accountId)
            ->where('journal_entries.status', 'posted')
            ->where('journal_entries.created_by', creatorId())
            ->where('journal_entries.journal_date', '<', $date)
            ->select(
                DB::raw('SUM(journal_entry_items.debit_amount) as total_debit'),
                DB::raw('SUM(journal_entry_items.credit_amount) as total_credit')
            )
            ->first();

        if ($priorTransactions) {
            $balanceCents += Money::toCents($priorTransactions->total_debit ?? 0)
                - Money::toCents($priorTransactions->total_credit ?? 0);
        }

        return Money::toAmount($balanceCents);
    }

    public function getJournalEntries($filters = [])
    {
        $query = JournalEntry::with(['items.account'])
            ->where('created_by', creatorId())
            ->when($filters['from_date'] ?? null, fn($q, $date) => $q->where('journal_date', '>=', $date))
            ->when($filters['to_date'] ?? null, fn($q, $date) => $q->where('journal_date', '<=', $date))
            ->when($filters['status'] ?? null, fn($q, $status) => $q->where('status', $status))
            ->orderBy('journal_date', 'desc')
            ->orderBy('id', 'desc')
            ->get();

        return $query->map(function ($entry) {
            $totalDebitCents = $entry->items->sum(fn($item) => Money::toCents($item->debit_amount));
            $totalCreditCents = $entry->items->sum(fn($item) => Money::toCents($item->credit_amount));

            return [
                'id' => $entry->id,
                'journal_number' => $entry->journal_number ?? 'JE-' . $entry->id,
                'date' => $entry->journal_date,
                'reference_type' => $entry->reference_type,
                'description' => $entry->description,
                'total_debit' => Money::toAmount($totalDebitCents),
                'total_credit' => Money::toAmount($totalCreditCents),
                'status' => $entry->status,
                // Exact: a journal entry must balance to the cent.
                'is_balanced' => $totalDebitCents === $totalCreditCents,
                'items' => $entry->items->map(fn($item) => [
                    'account_code' => $item->account->account_code ?? '',
                    'account_name' => $item->account->account_name ?? '',
                    'description' => $item->description,
                    'debit' => $item->debit_amount,
                    'credit' => $item->credit_amount,
                ]),
            ];
        });
    }

    public function getAccountBalances($filters = [])
    {
        $asOfDate = $filters['as_of_date'] ?? date('Y-m-d');
        $accountType = $filters['account_type'] ?? null;
        $showZeroBalances = $filters['show_zero_balances'] ?? false;

        $accounts = ChartOfAccount::where('created_by', creatorId())
            ->orderBy('account_code')
            ->get();

        if ($accountType && trim($accountType)) {
            $accounts = $accounts->filter(function($account) use ($accountType) {
                return $this->getAccountTypeLabel($account->account_code) === $accountType;
            });
        }

        $grouped = [];
        $totalsCents = ['debit' => 0, 'credit' => 0, 'net' => 0];

        foreach ($accounts as $account) {
            $balanceCents = Money::toCents($this->calculateAccountBalance($account->id, $asOfDate));

            // Only a genuinely zero balance is hidden. The old check also swallowed
            // any balance under a cent, which then went missing from the subtotals.
            if (!$showZeroBalances && $balanceCents === 0) {
                continue;
            }

            $debitCents = $balanceCents > 0 ? $balanceCents : 0;
            $creditCents = $balanceCents < 0 ? abs($balanceCents) : 0;

            $type = $this->getAccountTypeLabel($account->account_code);

            if (!isset($grouped[$type])) {
                $grouped[$type] = ['accounts' => [], 'subtotal_debit' => 0, 'subtotal_credit' => 0, 'subtotal_net' => 0];
            }

            $grouped[$type]['accounts'][] = [
                'account_code' => $account->account_code,
                'account_name' => $account->account_name,
                'account_type' => $type,
                'debit' => Money::toAmount($debitCents),
                'credit' => Money::toAmount($creditCents),
                'net_balance' => Money::toAmount($balanceCents),
            ];

            $grouped[$type]['subtotal_debit'] += $debitCents;
            $grouped[$type]['subtotal_credit'] += $creditCents;
            $grouped[$type]['subtotal_net'] += $balanceCents;

            $totalsCents['debit'] += $debitCents;
            $totalsCents['credit'] += $creditCents;
            $totalsCents['net'] += $balanceCents;
        }

        // Subtotals and totals were accumulated in cents; convert at the boundary.
        foreach ($grouped as $type => $group) {
            foreach (['subtotal_debit', 'subtotal_credit', 'subtotal_net'] as $key) {
                $grouped[$type][$key] = Money::toAmount($group[$key]);
            }
        }
        $totals = array_map(fn($cents) => Money::toAmount($cents), $totalsCents);

        return [
            'grouped' => $grouped,
            'totals' => $totals,
            'as_of_date' => $asOfDate,
        ];
    }

    private function calculateAccountBalance($accountId, $asOfDate)
    {
        $openingBalance = OpeningBalance::where('account_id', $accountId)
            ->where('created_by', creatorId())
            ->first();

        $balance = $openingBalance ? ($openingBalance->debit_amount - $openingBalance->credit_amount) : 0;

        $transactions = JournalEntryItem::join('journal_entries', 'journal_entry_items.journal_entry_id', '=', 'journal_entries.id')
            ->where('journal_entry_items.account_id', $accountId)
            ->where('journal_entries.status', 'posted')
            ->where('journal_entries.created_by', creatorId())
            ->where('journal_entries.journal_date', '<=', $asOfDate)
            ->select(
                DB::raw('SUM(journal_entry_items.debit_amount) as total_debit'),
                DB::raw('SUM(journal_entry_items.credit_amount) as total_credit')
            )
            ->first();

        if ($transactions) {
            $balance += ($transactions->total_debit ?? 0) - ($transactions->total_credit ?? 0);
        }

        return $balance;
    }

    private function getAccountTypeLabel($accountCode)
    {
        $code = (int) $accountCode;
        if ($code >= 1000 && $code < 2000) return 'Assets';
        if ($code >= 2000 && $code < 3000) return 'Liabilities';
        if ($code >= 3000 && $code < 4000) return 'Equity';
        if ($code >= 4000 && $code < 5000) return 'Revenue';
        if ($code >= 5000 && $code < 6000) return 'Expenses';
        return 'Other';
    }

    public function getIncomeStatement($filters = [])
    {
        $fromDate = $filters['from_date'] ?? date('Y') . '-01-01';
        $toDate = $filters['to_date'] ?? date('Y') . '-12-31';
        $showZeroBalances = $filters['show_zero_balances'] ?? false;

        $accounts = ChartOfAccount::where('created_by', creatorId())
            ->where(function($q) {
                $q->whereBetween('account_code', [4000, 5999]);
            })
            ->orderBy('account_code')
            ->get();

        $revenue = [];
        $cogs = [];
        $expenses = [];
        $totalRevenueCents = 0;
        $totalCogsCents = 0;
        $totalExpensesCents = 0;

        foreach ($accounts as $account) {
            $balanceCents = Money::toCents($this->getAccountBalanceForPeriod($account->id, $fromDate, $toDate));

            if (!$showZeroBalances && $balanceCents === 0) {
                continue;
            }

            $code = (int) $account->account_code;
            $amountCents = abs($balanceCents);
            $item = [
                'account_code' => $account->account_code,
                'account_name' => $account->account_name,
                'amount' => Money::toAmount($amountCents),
            ];

            if ($code >= 4000 && $code < 5000) {
                $revenue[] = $item;
                $totalRevenueCents += $amountCents;
            } elseif ($code >= 5000 && $code < 5100) {
                $cogs[] = $item;
                $totalCogsCents += $amountCents;
            } elseif ($code >= 5100 && $code < 6000) {
                $expenses[] = $item;
                $totalExpensesCents += $amountCents;
            }
        }

        $totalRevenue = Money::toAmount($totalRevenueCents);
        $totalCogs = Money::toAmount($totalCogsCents);
        $totalExpenses = Money::toAmount($totalExpensesCents);
        $grossProfit = Money::toAmount($totalRevenueCents - $totalCogsCents);
        $operatingIncome = Money::toAmount($totalRevenueCents - $totalCogsCents - $totalExpensesCents);
        $netIncome = $operatingIncome;

        return [
            'revenue' => $revenue,
            'cogs' => $cogs,
            'expenses' => $expenses,
            'total_revenue' => $totalRevenue,
            'total_cogs' => $totalCogs,
            'total_expenses' => $totalExpenses,
            'gross_profit' => $grossProfit,
            'operating_income' => $operatingIncome,
            'net_income' => $netIncome,
            'from_date' => $fromDate,
            'to_date' => $toDate,
        ];
    }

    private function getAccountBalanceForPeriod($accountId, $fromDate, $toDate)
    {
        $transactions = JournalEntryItem::join('journal_entries', 'journal_entry_items.journal_entry_id', '=', 'journal_entries.id')
            ->where('journal_entry_items.account_id', $accountId)
            ->where('journal_entries.status', 'posted')
            ->where('journal_entries.created_by', creatorId())
            ->whereBetween('journal_entries.journal_date', [$fromDate, $toDate])
            ->select(
                DB::raw('SUM(journal_entry_items.credit_amount) as total_credit'),
                DB::raw('SUM(journal_entry_items.debit_amount) as total_debit')
            )
            ->first();

        return ($transactions->total_credit ?? 0) - ($transactions->total_debit ?? 0);
    }

    public function getCashFlow($filters = [])
    {
        $fromDate = $filters['from_date'] ?? date('Y') . '-01-01';
        $toDate = $filters['to_date'] ?? date('Y') . '-12-31';

        $cashAccounts = ChartOfAccount::where('created_by', creatorId())
            ->whereBetween('account_code', [1000, 1099])
            ->get();

        $beginningCash = 0;
        foreach ($cashAccounts as $account) {
            $beginningCash += $this->calculateAccountBalance($account->id, $fromDate);
        }

        $operating = $this->getCashFlowByCategory($fromDate, $toDate, 4000, 5999);
        $investing = $this->getCashFlowByCategory($fromDate, $toDate, 1100, 1999);
        $financing = $this->getCashFlowByCategory($fromDate, $toDate, 2000, 3999);

        $netCashFlow = $operating + $investing + $financing;
        $endingCash = $beginningCash + $netCashFlow;

        return [
            'beginning_cash' => $beginningCash,
            'operating' => $operating,
            'investing' => $investing,
            'financing' => $financing,
            'net_cash_flow' => $netCashFlow,
            'ending_cash' => $endingCash,
            'from_date' => $fromDate,
            'to_date' => $toDate,
        ];
    }

    private function getCashFlowByCategory($fromDate, $toDate, $codeStart, $codeEnd)
    {
        $accounts = ChartOfAccount::where('created_by', creatorId())
            ->whereBetween('account_code', [$codeStart, $codeEnd])
            ->get();

        $total = 0;
        foreach ($accounts as $account) {
            $balance = $this->getAccountBalanceForPeriod($account->id, $fromDate, $toDate);
            $total += $balance;
        }

        return $total;
    }

    public function getExpenseReport($filters = [])
    {
        $fromDate = $filters['from_date'] ?? date('Y') . '-01-01';
        $toDate = $filters['to_date'] ?? date('Y') . '-12-31';

        $accounts = ChartOfAccount::where('created_by', creatorId())
            ->whereBetween('account_code', [5000, 5999])
            ->orderBy('account_code')
            ->get();

        $expenses = [];
        $totalExpensesCents = 0;

        foreach ($accounts as $account) {
            $balanceCents = Money::toCents($this->getAccountBalanceForPeriod($account->id, $fromDate, $toDate));

            if ($balanceCents === 0) {
                continue;
            }

            $expenses[] = [
                'account_code' => $account->account_code,
                'account_name' => $account->account_name,
                'amount' => Money::toAmount(abs($balanceCents)),
            ];

            $totalExpensesCents += abs($balanceCents);
        }

        usort($expenses, fn($a, $b) => $b['amount'] <=> $a['amount']);

        return [
            'expenses' => $expenses,
            'total_expenses' => Money::toAmount($totalExpensesCents),
            'from_date' => $fromDate,
            'to_date' => $toDate,
        ];
    }
}
