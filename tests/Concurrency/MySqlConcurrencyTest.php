<?php

namespace Tests\Concurrency;

use App\Models\Account;
use App\Models\Admin\StatusChange;
use App\Models\Admin\SubscriptionActivation;
use App\Models\CreditLedgerEntry;
use App\Models\Plan;
use App\Models\Store;
use App\Models\User;
use App\Services\Admin\Status\StatusChangeService;
use App\Services\Admin\Subscriptions\ActivateSubscription;
use App\Services\Admin\Subscriptions\SubscriptionQuote;
use App\Services\Auth\RegisterStoreOwner;
use App\Services\Cash\CashDayService;
use App\Services\Cash\CashTotals;
use App\Services\Catalog\ProductWriter;
use App\Services\Customers\CreateCustomer;
use App\Services\Customers\RecordOpeningBalance;
use App\Services\Sales\CompleteSale;
use Carbon\CarbonImmutable;
use Database\Seeders\PlanSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\Process\Process;
use Tests\Support\MySqlConcurrencyDatabase;
use Tests\TestCase;

class MySqlConcurrencyTest extends TestCase
{
    private static ?string $preparedDatabase = null;

    private User $owner;

    private Store $store;

    protected function setUp(): void
    {
        parent::setUp();
        MySqlConcurrencyDatabase::configure($this->app);
        // Workers need committed fixtures, so RefreshDatabase's wrapping
        // transaction is unsuitable. Migrate once and isolate each case by owner.
        if (self::$preparedDatabase !== DB::getDatabaseName()) {
            MySqlConcurrencyDatabase::reset();
            $this->seed(PlanSeeder::class);
            self::$preparedDatabase = DB::getDatabaseName();
        }
        $this->owner = app(RegisterStoreOwner::class)->handle([
            'name' => 'Race owner', 'store_name' => 'Race store',
            'email' => 'race-'.Str::uuid().'@example.test', 'password' => 'test-only-password',
        ]);
        $account = Account::where('owner_user_id', $this->owner->id)->sole();
        $this->store = Store::where('account_id', $account->id)->sole();
    }

    public function test_two_checkouts_cannot_sell_the_last_unit_twice(): void
    {
        $product = app(ProductWriter::class)->save($this->store, $this->owner, [
            'name' => 'Last bottle', 'unit' => 'bottle', 'price_centavos' => 1500,
            'cost_centavos' => 1000, 'initial_stock' => 1,
        ]);
        $tasks = array_map(fn () => ['operation' => 'checkout', 'store_id' => $this->store->id,
            'actor_id' => $this->owner->id, 'input' => ['idempotency_key' => (string) Str::uuid(),
                'items' => [['product_id' => $product->id, 'quantity' => 1]],
                'payment' => ['method' => 'cash', 'tendered_centavos' => 1500]]], [1, 2]);

        $results = $this->race('accounts', $this->store->account_id, $tasks);

        $this->assertSame([200, 409], $this->statuses($results));
        $this->assertSame('insufficient_stock', collect($results)->firstWhere('status', 409)['reason']);
        $this->assertSame(0, $product->inventory()->firstOrFail()->quantity_on_hand);
        foreach (['sales', 'sale_items', 'sale_payments'] as $table) {
            $this->assertSame(1, DB::table($table)->where('store_id', $this->store->id)->count());
        }
        $this->assertSame(1, $product->movements()->where('movement_type', 'sale')->count());
    }

    public function test_two_repayments_reviewed_at_the_same_balance_cannot_both_commit(): void
    {
        $customer = app(CreateCustomer::class)->handle($this->store, [
            'name' => 'Race customer', 'request_key' => (string) Str::uuid(),
        ])['customer'];
        app(RecordOpeningBalance::class)->handle($this->store, $customer, $this->owner, [
            'request_key' => (string) Str::uuid(), 'amount_centavos' => 10000,
            'notes' => 'Synthetic opening balance for concurrency verification',
        ]);
        $tasks = array_map(fn () => ['operation' => 'repayment', 'store_id' => $this->store->id,
            'customer_id' => $customer->id, 'actor_id' => $this->owner->id,
            'input' => ['request_key' => (string) Str::uuid(), 'method' => 'cash',
                'amount_centavos' => 6000, 'expected_balance_centavos' => '10000']], [1, 2]);

        $results = $this->race('accounts', $this->store->account_id, $tasks);

        $this->assertSame([200, 409], $this->statuses($results));
        $this->assertSame('balance_changed', collect($results)->firstWhere('status', 409)['reason']);
        $this->assertSame(4000, $customer->fresh()->balance_centavos);
        $this->assertSame(1, CreditLedgerEntry::where('customer_id', $customer->id)->where('entry_type', 'payment')->count());
        $this->assertDatabaseHas('credit_ledger_entries', ['store_id' => $this->store->id, 'customer_id' => $customer->id, 'entry_type' => 'payment',
            'amount_centavos' => 6000, 'balance_before_centavos' => 10000, 'balance_after_centavos' => 4000]);
    }

    public static function subscriptionStates(): array
    {
        return ['initial activation' => [false], 'renewal' => [true]];
    }

    public function test_final_close_and_cash_checkout_cannot_commit_an_uncounted_sale(): void
    {
        $at = CarbonImmutable::now('UTC')->startOfSecond();
        $this->travelTo($at);
        try {
            $product = app(ProductWriter::class)->save($this->store, $this->owner, [
                'name' => 'Closing race bottle', 'unit' => 'bottle', 'price_centavos' => 1500,
                'cost_centavos' => 1000, 'initial_stock' => 1,
            ]);
            $cash = app(CashDayService::class);
            $day = $cash->open($this->store, $this->owner, [
                'request_key' => (string) Str::uuid(), 'opening_cash_centavos' => 5000,
                'business_date' => $at->setTimezone('Asia/Manila')->toDateString(),
            ])['day'];
            $review = $cash->preview($this->store, $day, $this->owner, ['counted_cash_centavos' => 5000]);
            $tasks = [
                ['operation' => 'cash_close', 'store_id' => $this->store->id, 'cash_day_id' => $day->id,
                    'actor_id' => $this->owner->id, 'at' => $at->toIso8601String(),
                    'input' => ['request_key' => (string) Str::uuid(), 'review_token' => $review['review_token']]],
                ['operation' => 'checkout', 'store_id' => $this->store->id,
                    'actor_id' => $this->owner->id, 'at' => $at->toIso8601String(),
                    'input' => ['idempotency_key' => (string) Str::uuid(),
                        'items' => [['product_id' => $product->id, 'quantity' => 1]],
                        'payment' => ['method' => 'cash', 'tendered_centavos' => 1500]]],
            ];

            [$closing, $checkout] = $this->race('accounts', $this->store->account_id, $tasks);

            $this->assertSame([200, 409], $this->statuses([$closing, $checkout]));
            $day->refresh();
            if ($closing['status'] === 200) {
                $this->assertSame('cash_day_closed', $checkout['reason']);
                $this->assertSame('closed', $day->status);
                $this->assertSame('0', $day->closing['totals']['cash_sales_centavos']);
                $this->assertSame('5000', $day->closing['totals']['expected_cash_centavos']);
                $this->assertSame(0, $this->store->sales()->count());
                $this->assertSame(1, $product->inventory()->firstOrFail()->quantity_on_hand);
            } else {
                $this->assertSame(200, $checkout['status']);
                $this->assertSame('cash_activity_changed', $closing['reason']);
                $this->assertSame('open', $day->status);
                $this->assertNull($day->closing);
                $this->assertSame(1, $this->store->sales()->count());
                $this->assertSame(0, $product->inventory()->firstOrFail()->quantity_on_hand);
                $this->assertSame('6500', app(CashTotals::class)->forDay($day)['expected_cash_centavos']);
            }
        } finally {
            $this->travelBack();
        }
    }

    #[DataProvider('subscriptionStates')]
    public function test_competing_subscription_reviews_commit_only_one_period(bool $renewal): void
    {
        $admin = User::factory()->create(['platform_role' => 'platform_admin', 'status' => 'active']);
        $account = Account::findOrFail($this->store->account_id);
        $quotes = app(SubscriptionQuote::class);
        $input = ['plan_id' => Plan::where('code', 'basic')->value('id'), 'months' => 1,
            'settlement_method' => 'manual_payment', 'amount_centavos' => 4900,
            'payment_reference' => 'RACE-INITIAL', 'reason' => 'Concurrency verification'];
        if ($renewal) {
            $initial = $quotes->create($account, $admin, $input);
            app(ActivateSubscription::class)->handle($account, $admin, [
                'quote' => $initial['quote'], 'request_key' => (string) Str::uuid(),
            ]);
        }
        $before = $account->subscriptions()->count();
        $tasks = [];
        foreach (['RACE-A', 'RACE-B'] as $reference) {
            $quote = $quotes->create($account, $admin, [...$input, 'payment_reference' => $reference]);
            $this->assertSame($renewal ? 'renew' : 'activate', $quote['proposal']['action']);
            $tasks[] = ['operation' => 'subscription', 'account_id' => $account->id,
                'actor_id' => $admin->id, 'input' => ['quote' => $quote['quote'], 'request_key' => (string) Str::uuid()]];
        }

        $results = $this->race('accounts', $account->id, $tasks);

        $this->assertSame([200, 409], $this->statuses($results));
        $this->assertSame($before + 1, $account->subscriptions()->count());
        $this->assertSame($renewal ? 2 : 1, SubscriptionActivation::where('account_id', $account->id)->count());
        $periods = $account->subscriptions()->where('provider', 'manual')->orderBy('starts_at')->get();
        $this->assertCount($renewal ? 2 : 1, $periods);
        if ($renewal) {
            $this->assertTrue($periods[0]->ends_at->equalTo($periods[1]->starts_at));
        }
    }

    public static function suspensionTargets(): array
    {
        return ['account suspension' => ['account'], 'store suspension' => ['store']];
    }

    #[DataProvider('suspensionTargets')]
    public function test_suspension_and_an_already_admitted_checkout_serialize_current_availability(string $type): void
    {
        $admin = User::factory()->create(['platform_role' => 'platform_admin', 'status' => 'active']);
        $product = app(ProductWriter::class)->save($this->store, $this->owner, [
            'name' => 'Suspension race bottle', 'unit' => 'bottle', 'price_centavos' => 1500,
            'cost_centavos' => 1000, 'initial_stock' => 1,
        ]);
        $target = $type === 'account' ? Account::findOrFail($this->store->account_id) : $this->store;
        $review = app(StatusChangeService::class)->preview($target, $admin, [
            'status' => 'suspended', 'reason' => 'Concurrency access enforcement verification',
        ]);
        $tasks = [
            ['operation' => 'status_change', 'target_type' => $type,
                'store_id' => $this->store->id, 'account_id' => $this->store->account_id,
                'actor_id' => $admin->id, 'input' => ['request_key' => (string) Str::uuid(),
                    'review_token' => $review['review_token']]],
            ['operation' => 'checkout', 'store_id' => $this->store->id, 'actor_id' => $this->owner->id,
                'input' => ['idempotency_key' => (string) Str::uuid(),
                    'items' => [['product_id' => $product->id, 'quantity' => 1]],
                    'payment' => ['method' => 'cash', 'tendered_centavos' => 1500]]],
        ];

        [$suspension, $checkout] = $this->race('accounts', $this->store->account_id, $tasks);

        $this->assertSame(200, $suspension['status']);
        $this->assertContains($checkout['status'], [200, 403]);
        $this->assertSame('suspended', $target->fresh()->status);
        $this->assertSame(1, StatusChange::where('account_id', $this->store->account_id)->count());
        $sold = $checkout['status'] === 200 ? 1 : 0;
        $this->assertSame($sold, $this->store->sales()->count());
        $this->assertSame(1 - $sold, $product->inventory()->sole()->quantity_on_hand);
        $this->assertSame($sold, $product->movements()->where('movement_type', 'sale')->count());
        // The account barrier guarantees both requests loaded the active store
        // before suspension. A second call with the old model must also fail.
        try {
            app(CompleteSale::class)->handle($this->store, $this->owner, [
                ...$tasks[1]['input'], 'idempotency_key' => (string) Str::uuid(),
            ]);
            $this->fail('A stale store object must not bypass committed suspension.');
        } catch (HttpExceptionInterface $error) {
            $this->assertSame(403, $error->getStatusCode());
        }
    }

    private function statuses(array $results): array
    {
        $statuses = array_column($results, 'status');
        sort($statuses);

        return $statuses;
    }

    private function race(string $table, int $id, array $tasks): array
    {
        $directory = sys_get_temp_dir().'/sarisari-race-'.Str::uuid();
        mkdir($directory, 0700);
        $processes = [];
        try {
            // Observe both workers executing their lock queries in MySQL before
            // releasing the parent lock, so this cannot become a sequential test.
            DB::beginTransaction();
            DB::table($table)->where('id', $id)->lockForUpdate()->firstOrFail();
            foreach ($tasks as $worker => $task) {
                $process = new Process([PHP_BINARY, base_path('tests/Support/mysql-concurrency-worker.php')], base_path(), [
                    ...MySqlConcurrencyDatabase::environment(), 'APP_ENV' => 'testing',
                    'APP_KEY' => 'base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=',
                    'APP_DEBUG' => 'false', 'APP_MAINTENANCE_DRIVER' => 'file', 'DB_CONNECTION' => 'sqlite',
                    'DB_DATABASE' => ':memory:', 'DB_URL' => '', 'CACHE_STORE' => 'array',
                    'MAIL_MAILER' => 'array', 'QUEUE_CONNECTION' => 'sync', 'SESSION_DRIVER' => 'array',
                ], json_encode([...$task, 'directory' => $directory, 'worker' => $worker], JSON_THROW_ON_ERROR), 90);
                $process->start();
                $processes[$worker] = $process;
            }
            $this->awaitBarrier($directory, 'ready', $processes);
            touch($directory.'/go');
            $this->awaitBarrier($directory, 'attempting', $processes);
            $this->awaitDatabaseLockQueries($directory, $table, $processes);
            foreach ($processes as $process) {
                $this->assertTrue($process->isRunning(), 'Both competing processes must still be running behind the held lock.');
            }
            DB::rollBack();
            $results = [];
            foreach ($processes as $process) {
                $process->wait();
                $this->assertSame(0, $process->getExitCode(), $process->getErrorOutput());
                $results[] = json_decode($process->getOutput(), true, 512, JSON_THROW_ON_ERROR);
            }

            return $results;
        } finally {
            while (DB::transactionLevel() > 0) {
                DB::rollBack();
            }
            foreach ($processes as $process) {
                if ($process->isRunning()) {
                    $process->stop(1);
                }
            }
            foreach (glob($directory.'/*') ?: [] as $file) {
                unlink($file);
            }
            rmdir($directory);
        }
    }

    private function awaitBarrier(string $directory, string $stage, array $processes): void
    {
        $deadline = microtime(true) + 45;
        do {
            $complete = true;
            foreach ($processes as $worker => $process) {
                if (! $process->isRunning()) {
                    throw new RuntimeException('Concurrency worker exited early: '.$process->getErrorOutput().$process->getOutput());
                }
                $complete = $complete && is_file($directory.'/'.$worker.'.'.$stage);
            }
            if ($complete) {
                return;
            }
            usleep(10000);
        } while (microtime(true) < $deadline);

        throw new RuntimeException('Timed out waiting for both MySQL workers at '.$stage.'.');
    }

    private function awaitDatabaseLockQueries(string $directory, string $table, array $processes): void
    {
        $ids = array_map(fn ($worker) => (int) file_get_contents($directory.'/'.$worker.'.ready'), array_keys($processes));
        $deadline = microtime(true) + 45;
        do {
            $waiting = [];
            // SHOW PROCESSLIST exposes the test user's own connections without
            // granting PROCESS or access to another schema's application data.
            foreach (DB::select('SHOW FULL PROCESSLIST') as $thread) {
                $sql = strtolower($thread->Info ?? '');
                if (in_array((int) $thread->Id, $ids, true)
                    && str_contains($sql, 'for update') && str_contains($sql, $table)) {
                    $waiting[] = (int) $thread->Id;
                }
            }
            if (count(array_unique($waiting)) === count($ids)) {
                return;
            }
            foreach ($processes as $process) {
                if (! $process->isRunning()) {
                    throw new RuntimeException('Concurrency worker stopped before its database lock query.');
                }
            }
            usleep(10000);
        } while (microtime(true) < $deadline);

        throw new RuntimeException('MySQL did not report both concurrent lock queries.');
    }
}
