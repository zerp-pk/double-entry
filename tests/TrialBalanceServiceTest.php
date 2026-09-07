<?php

namespace Zerp\DoubleEntry\Tests;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Zerp\DoubleEntry\Services\TrialBalanceService;

/**
 * The reported symptom, end to end: an account holding one cent was skipped by the
 * `abs($balance) > 0.01` guard, so it vanished from the report and from the totals,
 * which could then fail to balance with nothing on screen explaining why.
 * See zerp-pk/double-entry#2.
 *
 * The schema lives in the account package, so the few columns this query touches are
 * built here rather than pulling that package in as a test dependency.
 */
class TrialBalanceServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('chart_of_accounts', function (Blueprint $table) {
            $table->id();
            $table->string('account_code');
            $table->string('account_name');
            $table->string('normal_balance');
            $table->decimal('opening_balance', 15, 2)->default(0);
            $table->boolean('is_active')->default(true);
            $table->unsignedBigInteger('created_by');
        });

        Schema::create('opening_balances', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('account_id');
            $table->date('effective_date')->nullable();
            $table->unsignedBigInteger('created_by');
        });

        Schema::create('journal_entries', function (Blueprint $table) {
            $table->id();
            $table->date('journal_date');
            $table->string('status');
        });

        Schema::create('journal_entry_items', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('journal_entry_id');
            $table->unsignedBigInteger('account_id');
            $table->decimal('debit_amount', 15, 2)->default(0);
            $table->decimal('credit_amount', 15, 2)->default(0);
        });
    }

    private function account(string $code, string $normal, string $opening): int
    {
        return DB::table('chart_of_accounts')->insertGetId([
            'account_code' => $code,
            'account_name' => 'Account ' . $code,
            'normal_balance' => $normal,
            'opening_balance' => $opening,
            'is_active' => 1,
            'created_by' => 1,
        ]);
    }

    private function generate(): array
    {
        return (new TrialBalanceService())->generateTrialBalance('2026-01-01', '2026-12-31');
    }

    public function test_a_one_cent_balance_is_reported_and_counted(): void
    {
        $this->account('1000', 'debit', '0.01');
        $this->account('2000', 'credit', '0.01');

        $result = $this->generate();

        $codes = array_column($result['accounts'], 'account_code');
        $this->assertContains('1000', $codes, 'a one cent account must not be dropped');
        $this->assertContains('2000', $codes);

        $this->assertSame(0.01, $result['total_debit']);
        $this->assertSame(0.01, $result['total_credit']);
        $this->assertTrue($result['is_balanced']);
    }

    public function test_a_zero_balance_account_is_still_left_out(): void
    {
        $this->account('1000', 'debit', '5.00');
        $this->account('2000', 'credit', '5.00');
        $this->account('3000', 'credit', '0.00');

        $codes = array_column($this->generate()['accounts'], 'account_code');

        $this->assertNotContains('3000', $codes);
        $this->assertCount(2, $codes);
    }

    public function test_many_small_balances_total_exactly(): void
    {
        // 200 accounts at a cent each on both sides. Added as floats this drifts off
        // 2.00 and the old tolerance eventually calls a balanced ledger unbalanced.
        for ($i = 0; $i < 200; $i++) {
            $this->account('1' . str_pad((string) $i, 3, '0', STR_PAD_LEFT), 'debit', '0.01');
            $this->account('2' . str_pad((string) $i, 3, '0', STR_PAD_LEFT), 'credit', '0.01');
        }

        $result = $this->generate();

        $this->assertSame(2.0, $result['total_debit']);
        $this->assertSame(2.0, $result['total_credit']);
        $this->assertTrue($result['is_balanced']);
    }

    public function test_a_one_cent_imbalance_is_reported_as_unbalanced(): void
    {
        $this->account('1000', 'debit', '100.00');
        $this->account('2000', 'credit', '99.99');

        $result = $this->generate();

        $this->assertFalse($result['is_balanced'], 'a real one cent gap must not be tolerated');
        $this->assertSame(100.0, $result['total_debit']);
        $this->assertSame(99.99, $result['total_credit']);
    }

    public function test_a_negative_balance_moves_to_the_opposite_column(): void
    {
        $this->account('1000', 'debit', '-25.50');

        $result = $this->generate();

        $this->assertSame(0.0, $result['accounts'][0]['debit']);
        $this->assertSame(25.50, $result['accounts'][0]['credit']);
        $this->assertSame(25.50, $result['total_credit']);
    }
}
