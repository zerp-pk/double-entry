<?php

namespace Zerp\DoubleEntry\Services;

use Illuminate\Support\Facades\DB;
use Zerp\DoubleEntry\Support\Money;

class TrialBalanceService
{
    public function generateTrialBalance($fromDate, $toDate)
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
            WHERE coa.is_active = 1
              AND coa.created_by = ?
            GROUP BY coa.id, coa.account_code, coa.account_name, coa.normal_balance, coa.opening_balance, ob.effective_date
            ORDER BY coa.account_code ASC
        ", [$fromDate, $toDate, $fromDate, $toDate, $fromDate, $toDate, $fromDate, $toDate, creatorId()]);

        $totalDebitCents = 0;
        $totalCreditCents = 0;
        $accountsList = [];

        foreach($accounts as $account) {
            $balanceCents = Money::toCents($account->balance);

            // Every account carrying a balance belongs on the trial balance. This
            // used to skip anything at or under a cent, which silently dropped those
            // accounts from the report AND from the totals, so a one cent balance
            // could make the ledger fail to balance with nothing on screen to explain
            // it. Only a genuinely zero balance is left out now.
            if ($balanceCents !== 0) {
                $debitCents = 0;
                $creditCents = 0;

                if ($balanceCents > 0) {
                    if ($account->normal_balance === 'debit') {
                        $debitCents = $balanceCents;
                        $totalDebitCents += $debitCents;
                    } else {
                        $creditCents = $balanceCents;
                        $totalCreditCents += $creditCents;
                    }
                } else {
                    // Negative balance goes to opposite side
                    if ($account->normal_balance === 'debit') {
                        $creditCents = abs($balanceCents);
                        $totalCreditCents += $creditCents;
                    } else {
                        $debitCents = abs($balanceCents);
                        $totalDebitCents += $debitCents;
                    }
                }

                $accountsList[] = [
                    'id' => $account->id,
                    'account_code' => $account->account_code,
                    'account_name' => $account->account_name,
                    'debit' => Money::toAmount($debitCents),
                    'credit' => Money::toAmount($creditCents)
                ];
            }
        }

        return [
            'accounts' => $accountsList,
            'total_debit' => Money::toAmount($totalDebitCents),
            'total_credit' => Money::toAmount($totalCreditCents),
            // Exact: both sides are integer cents, so the old 0.01 tolerance is not
            // needed and would only hide a real one cent imbalance.
            'is_balanced' => $totalDebitCents === $totalCreditCents,
            'from_date' => $fromDate,
            'to_date' => $toDate
        ];
    }
}
