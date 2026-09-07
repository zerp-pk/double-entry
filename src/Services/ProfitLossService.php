<?php

namespace Zerp\DoubleEntry\Services;

use Zerp\Account\Models\ChartOfAccount;
use Illuminate\Support\Facades\DB;
use Zerp\DoubleEntry\Support\Money;

class ProfitLossService
{
    public function generateProfitLoss($fromDate, $toDate)
    {
        $accounts = DB::select("
            SELECT
                coa.id,
                coa.account_code,
                coa.account_name,
                coa.normal_balance,
                CASE
                    WHEN coa.normal_balance = 'debit' THEN
                        COALESCE(coa.opening_balance, 0) +
                        COALESCE(SUM(CASE WHEN (ob.effective_date IS NULL OR je.journal_date >= ob.effective_date) AND je.journal_date >= ? AND je.journal_date <= ? AND je.status = 'posted' THEN jei.debit_amount ELSE 0 END), 0) -
                        COALESCE(SUM(CASE WHEN (ob.effective_date IS NULL OR je.journal_date >= ob.effective_date) AND je.journal_date >= ? AND je.journal_date <= ? AND je.status = 'posted' THEN jei.credit_amount ELSE 0 END), 0)
                    ELSE
                        COALESCE(coa.opening_balance, 0) +
                        COALESCE(SUM(CASE WHEN (ob.effective_date IS NULL OR je.journal_date >= ob.effective_date) AND je.journal_date >= ? AND je.journal_date <= ? AND je.status = 'posted' THEN jei.credit_amount ELSE 0 END), 0) -
                        COALESCE(SUM(CASE WHEN (ob.effective_date IS NULL OR je.journal_date >= ob.effective_date) AND je.journal_date >= ? AND je.journal_date <= ? AND je.status = 'posted' THEN jei.debit_amount ELSE 0 END), 0)
                END as balance
            FROM chart_of_accounts coa
            LEFT JOIN opening_balances ob ON coa.id = ob.account_id
                AND ob.created_by = coa.created_by
                AND ob.id = (SELECT MAX(id) FROM opening_balances WHERE account_id = coa.id AND created_by = coa.created_by)
            LEFT JOIN journal_entry_items jei ON coa.id = jei.account_id
            LEFT JOIN journal_entries je ON jei.journal_entry_id = je.id
            WHERE coa.account_code >= '4000' AND coa.account_code <= '5999'
              AND coa.is_active = 1
              AND coa.created_by = ?
            GROUP BY coa.id, coa.account_code, coa.account_name, coa.normal_balance, coa.opening_balance, ob.effective_date
        ", [$fromDate, $toDate, $fromDate, $toDate, $fromDate, $toDate, $fromDate, $toDate, creatorId()]);

        $revenue = [];
        $expenses = [];
        $totalRevenueCents = 0;
        $totalExpensesCents = 0;

        foreach($accounts as $account) {
            $balanceCents = Money::toCents($account->balance);

            // Any account with a balance counts. The old check skipped balances at or
            // under a cent, leaving them out of both the lists and the totals.
            if ($balanceCents !== 0) {
                $code = intval($account->account_code);
                if ($code >= 4000 && $code <= 4999) {
                    $revenue[] = $account;
                    $totalRevenueCents += $balanceCents;
                } elseif ($code >= 5000 && $code <= 5999) {
                    $expenses[] = $account;
                    $totalExpensesCents += $balanceCents;
                }
            }
        }

        return [
            'revenue' => $revenue,
            'expenses' => $expenses,
            'total_revenue' => Money::toAmount($totalRevenueCents),
            'total_expenses' => Money::toAmount($totalExpensesCents),
            'net_profit' => Money::toAmount($totalRevenueCents - $totalExpensesCents),
            'from_date' => $fromDate,
            'to_date' => $toDate
        ];
    }
}
