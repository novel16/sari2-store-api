<?php

// Standalone process: never run migrations or inherit the application's normal DB_* connection.
use App\Exceptions\CheckoutConflict;
use App\Models\Account;
use App\Models\CashDay;
use App\Models\Customer;
use App\Models\Store;
use App\Models\User;
use App\Services\Admin\Status\StatusChangeService;
use App\Services\Admin\Subscriptions\ActivateSubscription;
use App\Services\Cash\CashDayService;
use App\Services\Customers\RecordRepayment;
use App\Services\Sales\CompleteSale;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Tests\Support\MySqlConcurrencyDatabase;

require dirname(__DIR__, 2).'/vendor/autoload.php';

try {
    $task = json_decode(stream_get_contents(STDIN), true, 512, JSON_THROW_ON_ERROR);
    $app = require dirname(__DIR__, 2).'/bootstrap/app.php';
    $app->make(Kernel::class)->bootstrap();
    MySqlConcurrencyDatabase::configure($app);
    if (isset($task['at'])) {
        $at = CarbonImmutable::parse($task['at']);
        Carbon::setTestNow($at);
        CarbonImmutable::setTestNow($at);
    }
    $actor = User::findOrFail($task['actor_id']);
    $store = isset($task['store_id']) ? Store::findOrFail($task['store_id']) : null;
    $customer = isset($task['customer_id']) ? Customer::findOrFail($task['customer_id']) : null;
    $account = isset($task['account_id']) ? Account::findOrFail($task['account_id']) : null;
    $cashDay = isset($task['cash_day_id']) ? CashDay::findOrFail($task['cash_day_id']) : null;
    DB::connection()->beforeExecuting(function (string $sql) use ($task): void {
        if (str_contains(strtolower($sql), 'for update')) {
            file_put_contents($task['directory'].'/'.$task['worker'].'.attempting', '1');
        }
    });
    file_put_contents($task['directory'].'/'.$task['worker'].'.ready', (string) DB::selectOne('SELECT CONNECTION_ID() AS id')->id);
    $deadline = microtime(true) + 60;
    while (! is_file($task['directory'].'/go')) {
        if (microtime(true) > $deadline) {
            throw new RuntimeException('Worker start barrier timed out.');
        }
        usleep(10000);
    }
    $result = match ($task['operation']) {
        'checkout' => app(CompleteSale::class)->handle($store, $actor, $task['input']),
        'repayment' => app(RecordRepayment::class)->handle($store, $customer, $actor, $task['input']),
        'subscription' => app(ActivateSubscription::class)->handle($account, $actor, $task['input']),
        'cash_close' => app(CashDayService::class)->close($store, $cashDay, $actor, $task['input']),
        'status_change' => app(StatusChangeService::class)->change($task['target_type'] === 'account' ? $account : $store, $actor, $task['input']),
        default => throw new RuntimeException('Unknown concurrency operation.'),
    };
    echo json_encode(['status' => 200, 'replayed' => $result['replayed']], JSON_THROW_ON_ERROR);
} catch (CheckoutConflict $exception) {
    echo json_encode(['status' => 409, 'reason' => $exception->reason], JSON_THROW_ON_ERROR);
} catch (HttpExceptionInterface $exception) {
    echo json_encode(['status' => $exception->getStatusCode()], JSON_THROW_ON_ERROR);
} catch (Throwable $exception) {
    fwrite(STDERR, $exception::class.': '.$exception->getMessage());
    exit(1);
}
