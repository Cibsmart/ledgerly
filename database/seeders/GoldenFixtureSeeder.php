<?php

namespace Database\Seeders;

use App\Actions\Ledger\PostEntry;
use App\Enums\Role;
use App\Models\Account;
use App\Models\ExchangeRate;
use App\Models\Period;
use App\Models\User;
use App\Support\Currency;
use App\Support\Ledger\PendingLine;
use App\Support\Money;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Date;

/**
 * The golden fixture company: fifty entries across two fiscal periods, whose
 * every figure is hand-verified and committed in
 * tests/Fixtures/GoldenFixtureExpectations.php.
 *
 * The plan calls this the highest-leverage artifact in Phase 1, and the reason
 * is that it is the only test that can fail when a *later* phase quietly breaks
 * an earlier one. A trial balance that nets to zero proves the entries are
 * balanced; it proves nothing about whether they are right. Pinning the
 * expected balance of every account, computed by hand rather than by the code
 * under test, is what turns "it balances" into "it is correct".
 *
 * The scenario is deliberately Cibsmart's own: NGN and USD revenue, a Grey
 * USD→NGN conversion with its FX residual (D9), the UpSub hardware payable and
 * its first instalment recovered from gross revenue (R8), payroll with PAYE and
 * pension liabilities, and remittances in the following month.
 *
 * **Do not adjust an amount here to make a failing test pass.** The expectations
 * file is the specification; if the two disagree, one of them is a bug and it
 * is usually not this file.
 */
class GoldenFixtureSeeder extends Seeder
{
    private PostEntry $post;

    private User $bookkeeper;

    /** @var array<string, Account> */
    private array $accounts = [];

    public function run(): void
    {
        $this->post = app(PostEntry::class);

        $this->bookkeeper = User::query()->firstWhere('email', 'books@cibsmart.test')
            ?? User::factory()->create([
                'name' => 'Fixture Bookkeeper',
                'email' => 'books@cibsmart.test',
                'role' => Role::Bookkeeper,
            ]);

        $this->call(ChartOfAccountsSeeder::class);

        $this->accounts = Account::query()->get()->keyBy('code')->all();

        $this->seedPeriods();
        $this->seedRates();

        $this->january();
        $this->february();
    }

    private function seedPeriods(): void
    {
        for ($month = 1; $month <= 12; $month++) {
            $start = Date::create(2026, $month, 1)->startOfDay();

            if (Period::query()->overlapping($start, $start->endOfMonth())->exists()) {
                continue;
            }

            Period::query()->create([
                'fiscal_year' => 2026,
                'name' => $start->format('F Y'),
                'start_date' => $start,
                'end_date' => $start->endOfMonth()->startOfDay(),
            ]);
        }
    }

    private function seedRates(): void
    {
        foreach ([['2026-01-01', '1600'], ['2026-02-01', '1650']] as [$date, $rate]) {
            // The lookup value must be a date instance, not the string. The
            // column is stored through a date cast as 'Y-m-d H:i:s', so a bare
            // '2026-01-01' matches nothing, updateOrCreate inserts, and the
            // unique index rejects it on the second run.
            ExchangeRate::query()->updateOrCreate(
                [
                    'base_code' => 'USD',
                    'quote_code' => 'NGN',
                    'effective_on' => Date::parse($date)->startOfDay(),
                ],
                ['rate' => $rate, 'source' => 'golden fixture'],
            );
        }
    }

    /**
     * January 2026 — entries 1 to 26.
     */
    private function january(): void
    {
        // 1. Opening balances against Opening Balance Equity.
        $this->entry('2026-01-01', 'Opening balances at 1 January 2026', [
            $this->debit('1011', $this->ngn(5_000_000)),
            $this->debit('1016', $this->ngn(1_200_000)),
            $this->debit('1015', $this->usd(2_000)),
            $this->credit('3200', $this->ngn(9_400_000)),
        ]);

        // 2-6. NGN service revenue received into Zenith.
        foreach ([800_000, 650_000, 1_100_000, 450_000, 300_000] as $i => $amount) {
            $this->entry(sprintf('2026-01-%02d', 5 + $i * 4), 'Service revenue — NGN client', [
                $this->debit('1011', $this->ngn($amount)),
                $this->credit('4010', $this->ngn($amount)),
            ]);
        }

        // 7-9. USD service revenue received into Deel.
        foreach ([500, 750, 1_250] as $i => $amount) {
            $this->entry(sprintf('2026-01-%02d', 6 + $i * 5), 'Service revenue — USD partner', [
                $this->debit('1015', $this->usd($amount)),
                $this->credit('4010', $this->usd($amount)),
            ]);
        }

        // 10. Deel platform fee — an expense, never a reduction of revenue.
        $this->entry('2026-01-25', 'Deel platform fee', [
            $this->debit('5020', $this->ngn(80_000)),
            $this->credit('1015', $this->usd(50)),
        ]);

        // 11-20. Operating expenses paid from Zenith.
        foreach ($this->januaryExpenses() as $code => $amount) {
            $this->entry('2026-01-28', 'Operating expense', [
                $this->debit((string) $code, $this->ngn($amount)),
                $this->credit('1011', $this->ngn($amount)),
            ]);
        }

        // 21. January payroll — gross pay, with PAYE and pension as liabilities.
        $this->entry('2026-01-31', 'January payroll', [
            $this->debit('6010', $this->ngn(1_800_000)),
            $this->credit('2120', $this->ngn(200_000)),
            $this->credit('2130', $this->ngn(144_000)),
            $this->credit('2210', $this->ngn(1_456_000)),
        ]);

        // 22. Employer pension contribution — the employer portion only.
        $this->entry('2026-01-31', 'Employer pension contribution — January', [
            $this->debit('6020', $this->ngn(180_000)),
            $this->credit('2130', $this->ngn(180_000)),
        ]);

        // 23. Net pay settled.
        $this->entry('2026-01-31', 'January net pay', [
            $this->debit('2210', $this->ngn(1_456_000)),
            $this->credit('1011', $this->ngn(1_456_000)),
        ]);

        // 24. R8: the partner buys the Studio Display; we owe them $3,600.
        $this->entry('2026-01-15', 'UpSub-funded Studio Display', [
            $this->debit('1610', $this->ngn(5_760_000)),
            $this->credit('2020', $this->usd(3_600)),
        ]);

        // 25. Move USD from Deel to Grey ahead of converting it.
        $this->entry('2026-01-20', 'Transfer Deel USD to Grey USD', [
            $this->debit('1014', $this->usd(1_000)),
            $this->credit('1015', $this->usd(1_000)),
        ]);

        // 26. D9: an own-account conversion. $1,000 leaves Grey USD at the rate
        // it was carried at, ₦1,580,000 arrives in Grey NGN at the rate the
        // conversion actually executed at, and the ₦20,000 residual is a real
        // FX loss rather than an imbalance to be smoothed away.
        $this->entry('2026-01-22', 'Grey USD to NGN conversion at 1580', [
            $this->credit('1014', $this->usd(1_000)),
            $this->debit('1013', $this->ngn(1_580_000)),
            $this->debit('8100', $this->ngn(20_000)),
        ]);
    }

    /**
     * February 2026 — entries 27 to 50. The USD rate moves to 1650.
     */
    private function february(): void
    {
        // 27-31. NGN service revenue.
        foreach ([900_000, 1_400_000, 500_000, 750_000, 1_050_000] as $i => $amount) {
            $this->entry(sprintf('2026-02-%02d', 3 + $i * 4), 'Service revenue — NGN client', [
                $this->debit('1011', $this->ngn($amount)),
                $this->credit('4010', $this->ngn($amount)),
            ]);
        }

        // 32-34. USD service revenue at the February rate.
        foreach ([600, 900, 1_500] as $i => $amount) {
            $this->entry(sprintf('2026-02-%02d', 4 + $i * 5), 'Service revenue — USD partner', [
                $this->debit('1015', $this->usd($amount)),
                $this->credit('4010', $this->usd($amount)),
            ]);
        }

        // 35. R8, the whole point of it: $2,000 of gross revenue, $600 recovered
        // against the hardware liability, $1,400 in cash. Booking only the
        // $1,400 would understate revenue and never retire the payable.
        //
        // The liability is released at the rate it was booked at (1600), while
        // the revenue and the cash are at February's 1650 — the ₦30,000
        // difference is a realized FX loss on settling a USD obligation.
        $this->entry('2026-02-28', 'UpSub February settlement, net of hardware instalment 1 of 6', [
            $this->debit('1015', $this->usd(1_400)),
            $this->debit('2020', $this->usd(600), rate: '1600'),
            $this->debit('8100', $this->ngn(30_000)),
            $this->credit('4010', $this->usd(2_000)),
        ]);

        // 36-45. Operating expenses.
        foreach ($this->februaryExpenses() as $code => $amount) {
            $this->entry('2026-02-26', 'Operating expense', [
                $this->debit((string) $code, $this->ngn($amount)),
                $this->credit('1011', $this->ngn($amount)),
            ]);
        }

        // 46-48. February payroll, mirroring January.
        $this->entry('2026-02-28', 'February payroll', [
            $this->debit('6010', $this->ngn(1_800_000)),
            $this->credit('2120', $this->ngn(200_000)),
            $this->credit('2130', $this->ngn(144_000)),
            $this->credit('2210', $this->ngn(1_456_000)),
        ]);

        $this->entry('2026-02-28', 'Employer pension contribution — February', [
            $this->debit('6020', $this->ngn(180_000)),
            $this->credit('2130', $this->ngn(180_000)),
        ]);

        $this->entry('2026-02-28', 'February net pay', [
            $this->debit('2210', $this->ngn(1_456_000)),
            $this->credit('1011', $this->ngn(1_456_000)),
        ]);

        // 49-50. January's statutory liabilities remitted.
        $this->entry('2026-02-10', 'PAYE remittance for January', [
            $this->debit('2120', $this->ngn(200_000)),
            $this->credit('1011', $this->ngn(200_000)),
        ]);

        $this->entry('2026-02-10', 'Pension remittance for January', [
            $this->debit('2130', $this->ngn(324_000)),
            $this->credit('1011', $this->ngn(324_000)),
        ]);
    }

    /**
     * Keys are account codes. PHP narrows numeric string keys to integers, so
     * the shape is int-keyed however it is written here.
     *
     * @return array<int, int>
     */
    private function januaryExpenses(): array
    {
        return [
            '6100' => 400_000,
            '6110' => 85_000,
            '6120' => 120_000,
            '6130' => 95_000,
            '6140' => 250_000,
            '6150' => 15_000,
            '6160' => 180_000,
            '6170' => 220_000,
            '6180' => 60_000,
            '6190' => 45_000,
        ];
    }

    /**
     * Keys are account codes. PHP narrows numeric string keys to integers, so
     * the shape is int-keyed however it is written here.
     *
     * @return array<int, int>
     */
    private function februaryExpenses(): array
    {
        return [
            '6100' => 400_000,
            '6110' => 92_000,
            '6120' => 120_000,
            '6130' => 95_000,
            '6140' => 150_000,
            '6150' => 18_000,
            '6160' => 210_000,
            '6170' => 175_000,
            '6180' => 40_000,
            '6190' => 30_000,
        ];
    }

    /**
     * @param  list<PendingLine>  $lines
     */
    private function entry(string $date, string $memo, array $lines): void
    {
        $this->post->handle(
            entryDate: $date,
            lines: $lines,
            memo: $memo,
            user: $this->bookkeeper,
        );
    }

    private function debit(string $code, Money $amount, ?string $rate = null): PendingLine
    {
        return PendingLine::debit($this->accounts[$code], $amount, exchangeRate: $rate);
    }

    private function credit(string $code, Money $amount, ?string $rate = null): PendingLine
    {
        return PendingLine::credit($this->accounts[$code], $amount, exchangeRate: $rate);
    }

    private function ngn(int $major): Money
    {
        return Money::fromMajor($major, Currency::functional());
    }

    private function usd(int $major): Money
    {
        return Money::fromMajor($major, Currency::of('USD'));
    }
}
