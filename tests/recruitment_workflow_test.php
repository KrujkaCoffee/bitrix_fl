<?php
declare(strict_types=1);

// php tests/recruitment_workflow_test.php
// Exercise failure recovery without loading Bitrix or writing external records.
require __DIR__ . '/../recruitment/Gateway.php';
require __DIR__ . '/../recruitment/RequestStore.php';
require __DIR__ . '/../recruitment/BeforeTaskCreationException.php';
require __DIR__ . '/../recruitment/Workflow.php';

use Kelast\Recruitment\BeforeTaskCreationException;
use Kelast\Recruitment\Gateway;
use Kelast\Recruitment\RequestStore;
use Kelast\Recruitment\Workflow;

final class MemoryRequestStore extends RequestStore
{
    public $rows = [];
    public $isReady = true;
    public $failTaskCheckpointOnce = false;
    public $failVacancyCheckpointOnce = false;
    private $locked = [];

    public function __construct()
    {
    }

    public function ready(): bool
    {
        return $this->isReady;
    }

    public function withLock(string $key, callable $callback)
    {
        if (isset($this->locked[$key])) {
            throw new RuntimeException('Request already locked');
        }
        $this->locked[$key] = true;
        try {
            return $callback();
        } finally {
            unset($this->locked[$key]);
        }
    }

    public function get(string $key): ?array
    {
        return $this->rows[$key] ?? null;
    }

    public function insert(string $key, int $actor, int $responsible, string $hash, string $payload): void
    {
        if (isset($this->rows[$key])) {
            throw new RuntimeException('Duplicate request key');
        }
        $this->rows[$key] = [
            'ACTOR_ID' => $actor,
            'RESPONSIBLE_ID' => $responsible,
            'PAYLOAD_HASH' => $hash,
            'PAYLOAD_JSON' => $payload,
            'STATE' => 'NEW',
            'TASK_ID' => 0,
            'VACANCY_ID' => 0,
            'ERROR_CODE' => '',
        ];
    }

    public function update(string $key, array $values): void
    {
        if ($this->failTaskCheckpointOnce && ($values['STATE'] ?? '') === 'TASK_CREATED') {
            $this->failTaskCheckpointOnce = false;
            throw new RuntimeException('Task checkpoint unavailable');
        }
        if ($this->failVacancyCheckpointOnce && ($values['STATE'] ?? '') === 'COMPLETE') {
            $this->failVacancyCheckpointOnce = false;
            throw new RuntimeException('Vacancy checkpoint unavailable');
        }
        if (!isset($this->rows[$key])) {
            throw new RuntimeException('Missing request row');
        }
        $this->rows[$key] = array_replace($this->rows[$key], $values);
    }

    public function discardUnstarted(string $key): void
    {
        $row = $this->rows[$key] ?? null;
        if ($row && $row['STATE'] === 'NEW' && (int)$row['TASK_ID'] === 0 && (int)$row['VACANCY_ID'] === 0) {
            unset($this->rows[$key]);
        }
    }
}

final class FakeGateway implements Gateway
{
    public $preflightCalls = 0;
    public $ensureCalls = 0;
    public $createdVacancies = [];
    public $ensureArguments = [];
    public $rejectPreflight = false;
    public $failBeforeCreateOnce = false;
    public $loseCreateReceiptOnce = false;

    public function preflight(int $actor, int $responsible): void
    {
        $this->preflightCalls++;
        if ($this->rejectPreflight) {
            throw new RuntimeException('CRM creation is unavailable');
        }
    }

    public function ensureVacancy(
        int $taskId,
        int $actor,
        int $responsible,
        array $canonicalValues,
        string $canonicalDescription
    ): array {
        $this->ensureCalls++;
        $this->ensureArguments[] = [$taskId, $actor, $responsible, $canonicalValues, $canonicalDescription];
        if ($this->failBeforeCreateOnce) {
            $this->failBeforeCreateOnce = false;
            throw new RuntimeException('CRM write failed');
        }
        $created = !isset($this->createdVacancies[$taskId]);
        if ($created) {
            $this->createdVacancies[$taskId] = 9000 + count($this->createdVacancies);
        }
        if ($this->loseCreateReceiptOnce) {
            $this->loseCreateReceiptOnce = false;
            throw new RuntimeException('CRM committed but receipt was lost');
        }
        return ['id' => $this->createdVacancies[$taskId], 'created' => $created];
    }
}

set_error_handler(static function ($severity, $message, $file, $line) {
    throw new ErrorException($message, 0, $severity, $file, $line);
});

$checks = 0;
function check(bool $condition, string $message): void
{
    global $checks;
    if (!$condition) {
        throw new RuntimeException($message);
    }
    $checks++;
}

function throws(callable $action, string $expectedMessage): void
{
    try {
        $action();
    } catch (RuntimeException $error) {
        check(strpos($error->getMessage(), $expectedMessage) !== false,
            'Unexpected exception: ' . $error->getMessage());
        return;
    }
    throw new RuntimeException('Expected RuntimeException containing ' . $expectedMessage);
}

function fixture(): array
{
    $store = new MemoryRequestStore();
    $gateway = new FakeGateway();
    return [$store, $gateway, new Workflow($store, $gateway)];
}

function requestKey(string $token, int $actor = 3076): string
{
    return hash('sha256', $actor . ':344025:' . strtolower($token));
}

function submit(Workflow $workflow, callable $createTask, array $overrides = []): array
{
    $request = array_replace([
        'token' => '12345678-1234-4234-8234-123456789abc',
        'actor' => 3076,
        'responsible' => 1466,
        'values' => ['job_title' => 'Оператор ЧПУ', 'requester_id' => 3076],
        'description' => 'Тестовая заявка на подбор',
    ], $overrides);
    $fingerprint = hash('sha256', json_encode(
        [$request['values'], $request['description']],
        JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
    ));
    return $workflow->submit($request['token'], $request['actor'], $request['responsible'],
        $request['values'], $request['description'], $fingerprint, $createTask);
}

$token = '12345678-1234-4234-8234-123456789abc';

// A network retry, including the same token with different letter case, reuses both records.
[$store, $gateway, $workflow] = fixture();
$taskCalls = 0;
$createTask = static function () use (&$taskCalls) { $taskCalls++; return 501; };
$first = submit($workflow, $createTask);
$second = submit($workflow, $createTask, ['token' => strtoupper($token)]);
check($first['recruitmentStatus'] === 'COMPLETE' && $second === $first, 'Completed request must replay its result');
check($taskCalls === 1 && $gateway->preflightCalls === 1 && $gateway->ensureCalls === 1,
    'Same request must create one task and one vacancy');
check(count($store->rows) === 1 && count($gateway->createdVacancies) === 1, 'Replay must keep one journal row and vacancy');

// The already-used token cannot be attached to changed request contents.
throws(static function () use ($workflow, $createTask) {
    submit($workflow, $createTask, ['description' => 'Другая заявка']);
}, 'другими данными');
check($taskCalls === 1 && $gateway->ensureCalls === 1, 'Payload conflict must not write records');

// Distinct requests can create separate tasks and vacancies.
[$store, $gateway, $workflow] = fixture();
$nextTask = 600;
$createTask = static function () use (&$nextTask) { return ++$nextTask; };
$first = submit($workflow, $createTask);
$second = submit($workflow, $createTask, ['token' => '22345678-1234-4234-8234-123456789abc']);
check($first['taskId'] === 601 && $second['taskId'] === 602, 'Different tokens must create independent tasks');
check(count($store->rows) === 2 && count($gateway->createdVacancies) === 2, 'Different tokens need independent journal rows');

// Failed preflight and unavailable storage stop before task/file callback or journal insertion.
[$store, $gateway, $workflow] = fixture();
$taskCalls = 0;
$createTask = static function () use (&$taskCalls) { $taskCalls++; return 701; };
$gateway->rejectPreflight = true;
throws(static function () use ($workflow, $createTask) { submit($workflow, $createTask); }, 'CRM creation is unavailable');
check($taskCalls === 0 && !$store->rows && $gateway->ensureCalls === 0, 'Preflight rejection must precede all task writes');
$gateway->rejectPreflight = false;
$store->isReady = false;
throws(static function () use ($workflow, $createTask) { submit($workflow, $createTask); }, 'не подготовлен');
check($taskCalls === 0 && $gateway->preflightCalls === 1 && !$store->rows, 'Unavailable storage must precede preflight and writes');

// CRM failure returns a confirmed task and retries only the vacancy from durable inputs.
[$store, $gateway, $workflow] = fixture();
$gateway->failBeforeCreateOnce = true;
$taskCalls = 0;
$createTask = static function () use (&$taskCalls) { $taskCalls++; return 801; };
$pending = submit($workflow, $createTask);
check($pending['recruitmentStatus'] === 'PENDING' && $pending['taskId'] === 801, 'CRM failure must preserve confirmed task');
check($store->rows[requestKey($token)]['STATE'] === 'VACANCY_PENDING', 'CRM failure must checkpoint pending state');
$done = submit($workflow, $createTask, ['responsible' => 9999]);
check($done['recruitmentStatus'] === 'COMPLETE' && $taskCalls === 1 && $gateway->ensureCalls === 2,
    'CRM retry must reuse confirmed task');
check($gateway->ensureArguments[1][2] === 1466, 'Retry must keep original trusted responsible');
check($gateway->ensureArguments[1][3] === ['job_title' => 'Оператор ЧПУ', 'requester_id' => 3076]
    && $gateway->ensureArguments[1][4] === 'Тестовая заявка на подбор', 'Retry must use durable request values');

// CRM commit with a lost response is deduplicated by task in the gateway on retry.
[$store, $gateway, $workflow] = fixture();
$gateway->loseCreateReceiptOnce = true;
$taskCalls = 0;
$createTask = static function () use (&$taskCalls) { $taskCalls++; return 901; };
check(submit($workflow, $createTask)['recruitmentStatus'] === 'PENDING', 'Lost CRM receipt should remain pending');
check(count($gateway->createdVacancies) === 1, 'Lost receipt may already have committed a vacancy');
check(submit($workflow, $createTask)['recruitmentStatus'] === 'COMPLETE', 'Retry should confirm existing vacancy');
check($taskCalls === 1 && $gateway->ensureCalls === 2 && count($gateway->createdVacancies) === 1,
    'Lost receipt retry must not create a second task or vacancy');

// A failure recording COMPLETE also resolves through the same gateway deduplication.
[$store, $gateway, $workflow] = fixture();
$store->failVacancyCheckpointOnce = true;
$taskCalls = 0;
$createTask = static function () use (&$taskCalls) { $taskCalls++; return 1001; };
check(submit($workflow, $createTask)['recruitmentStatus'] === 'PENDING', 'Lost completion checkpoint should be pending');
check(submit($workflow, $createTask)['recruitmentStatus'] === 'COMPLETE', 'Completion checkpoint retry should confirm vacancy');
check($taskCalls === 1 && count($gateway->createdVacancies) === 1, 'Completion checkpoint retry must preserve one vacancy');

// Any unknown task outcome forbids another task callback on every repeated submit.
[$store, $gateway, $workflow] = fixture();
$taskCalls = 0;
$throwTask = static function () use (&$taskCalls) { $taskCalls++; throw new RuntimeException('Task outcome unknown'); };
$unknown = submit($workflow, $throwTask);
$repeated = submit($workflow, $throwTask);
check($unknown['recruitmentStatus'] === 'UNCERTAIN' && $repeated === $unknown, 'Unknown task result must remain uncertain');
check(!isset($unknown['taskId']) && $taskCalls === 1 && $gateway->ensureCalls === 0,
    'Unknown task result must not expose a fake task ID or create a vacancy');
check($store->rows[requestKey($token)]['STATE'] === 'TASK_UNCERTAIN', 'Task exception must be durably uncertain');
throws(static function () use ($workflow, $token) { $workflow->retryVacancy(requestKey($token)); }, 'Нет подтверждённой задачи');

// Simulate process loss after task creation but before a durable TASK_ID checkpoint.
[$store, $gateway, $workflow] = fixture();
$store->failTaskCheckpointOnce = true;
$taskCalls = 0;
$createTask = static function () use (&$taskCalls) { $taskCalls++; return 1101; };
throws(static function () use ($workflow, $createTask) { submit($workflow, $createTask); }, 'Task checkpoint unavailable');
check($store->rows[requestKey($token)]['STATE'] === 'TASK_CREATING'
    && $store->rows[requestKey($token)]['TASK_ID'] === 0, 'Failed task checkpoint must leave the durable uncertainty marker');
check(submit($workflow, $createTask)['recruitmentStatus'] === 'UNCERTAIN', 'Retry after lost task checkpoint must not repeat task');
check($taskCalls === 1 && $gateway->ensureCalls === 0, 'Lost task checkpoint must prevent task and vacancy retries');

// Administrative vacancy retry operates only on journal rows with a confirmed task.
[$store, $gateway, $workflow] = fixture();
throws(static function () use ($workflow) { $workflow->retryVacancy(str_repeat('a', 64)); }, 'Нет подтверждённой задачи');
throws(static function () use ($workflow) { $workflow->retryVacancy('invalid'); }, 'Некорректный ключ журнала');
$gateway->failBeforeCreateOnce = true;
$taskCalls = 0;
$createTask = static function () use (&$taskCalls) { $taskCalls++; return 1201; };
submit($workflow, $createTask);
$done = $workflow->retryVacancy(requestKey($token));
check($done['taskId'] === 1201 && $done['recruitmentStatus'] === 'COMPLETE', 'Admin retry should finish a confirmed task');
check($workflow->retryVacancy(requestKey($token)) === $done && $taskCalls === 1 && $gateway->ensureCalls === 2,
    'Completed admin retry should replay without writing records');

// Invalid public inputs stop without creating a journal row or entering the task callback.
[$store, $gateway, $workflow] = fixture();
$taskCalls = 0;
$createTask = static function () use (&$taskCalls) { $taskCalls++; return 1301; };
foreach ([['token' => ''], ['token' => 'bad-token'], ['token' => $token . "\n"], ['actor' => 0], ['actor' => -1], ['responsible' => 0]] as $invalid) {
    throws(static function () use ($workflow, $createTask, $invalid) { submit($workflow, $createTask, $invalid); }, 'Некорректный ключ заявки');
}
check($taskCalls === 0 && $gateway->preflightCalls === 0 && !$store->rows, 'Invalid public inputs must precede all state changes');

// A typed preparation error certifies that task creation has not started yet.
[$store, $gateway, $workflow] = fixture();
$preparationCalls = 0;
$taskCalls = 0;
$prepareFailure = static function () use (&$preparationCalls) {
    $preparationCalls++;
    throw new BeforeTaskCreationException('Не удалось загрузить файл');
};
$failed = submit($workflow, $prepareFailure);
check($failed === ['validationErrors' => ['Не удалось загрузить файл'], 'fieldErrors' => []],
    'Known preparation failure must return editable validation error');
check($preparationCalls === 1 && !$store->rows && $gateway->ensureCalls === 0,
    'Known preparation failure must release its unstarted reservation');
check($workflow->status($token, 3076) === ['recruitmentStatus' => 'NOT_FOUND'],
    'Released reservation must not appear as an uncertain task');
$createTask = static function () use (&$taskCalls) { $taskCalls++; return 1401; };
$done = submit($workflow, $createTask, ['description' => 'Исправленная заявка']);
check($done['taskId'] === 1401 && $done['recruitmentStatus'] === 'COMPLETE' && $taskCalls === 1,
    'Same token may retry edited inputs after a known preparation failure');
check($gateway->preflightCalls === 2 && $gateway->ensureArguments[0][4] === 'Исправленная заявка',
    'Editable retry must recheck preflight and persist the new request inputs');

// An abandoned NEW reservation can likewise be replaced before task creation starts.
[$store, $gateway, $workflow] = fixture();
$store->insert(requestKey($token), 3076, 1466, str_repeat('a', 64),
    json_encode(['values' => ['job_title' => 'Старый черновик'], 'description' => 'Старый черновик'], JSON_THROW_ON_ERROR));
check($workflow->status($token, 3076) === ['recruitmentStatus' => 'NOT_FOUND'],
    'An unstarted NEW reservation must not imply a saved task');
$taskCalls = 0;
$createTask = static function () use (&$taskCalls) { $taskCalls++; return 1501; };
$done = submit($workflow, $createTask, ['description' => 'Новая версия после прерывания']);
check($done['recruitmentStatus'] === 'COMPLETE' && $taskCalls === 1 && count($store->rows) === 1,
    'Retry may replace an abandoned reservation with different inputs');
check($gateway->ensureArguments[0][4] === 'Новая версия после прерывания',
    'Abandoned reservation must not retain stale request inputs');

// The public result endpoint is author-scoped and only reports persisted progress.
[$store, $gateway, $workflow] = fixture();
check($workflow->status($token, 3076) === ['recruitmentStatus' => 'NOT_FOUND'], 'Unknown token must report NOT_FOUND');
$taskCalls = 0;
$createTask = static function () use (&$taskCalls) { $taskCalls++; return 1601; };
submit($workflow, $createTask);
$status = $workflow->status(strtoupper($token), 3076);
check($status['recruitmentStatus'] === 'COMPLETE' && $status['userId'] === 3076 && $status['taskId'] === 1601,
    'Author can retrieve completed task with the original token');
check(!isset($status['PAYLOAD_JSON']) && !isset($status['values']) && !isset($status['description'])
    && !isset($status['vacancyId']), 'Result endpoint must not expose private request payload or CRM contents');
check($workflow->status($token, 1466) === ['recruitmentStatus' => 'NOT_FOUND'],
    'Same token under another actor must not expose the original request result');
check($taskCalls === 1 && $gateway->ensureCalls === 1 && $gateway->preflightCalls === 1,
    'Result polling must not rerun preflight, task creation or vacancy creation');
$store->isReady = false;
check($workflow->status($token, 3076) === ['recruitmentStatus' => 'NOT_FOUND'],
    'Unavailable journal must return NOT_FOUND without looking up private results');

[$store, $gateway, $workflow] = fixture();
$gateway->failBeforeCreateOnce = true;
submit($workflow, static function () { return 1701; });
$status = $workflow->status($token, 3076);
check($status['recruitmentStatus'] === 'PENDING' && $status['taskId'] === 1701,
    'Result polling must report a confirmed task while its vacancy is pending');
check($gateway->ensureCalls === 1, 'Polling a pending request must not attempt a CRM retry');

[$store, $gateway, $workflow] = fixture();
submit($workflow, static function () { throw new RuntimeException('Task callback failed'); });
$status = $workflow->status($token, 3076);
check($status['recruitmentStatus'] === 'UNCERTAIN' && !isset($status['taskId']),
    'Result polling must report an unknown task outcome without inventing an ID');
$store->rows[requestKey($token)]['STATE'] = 'TASK_CREATING';
$status = $workflow->status($token, 3076);
check($status['recruitmentStatus'] === 'UNCERTAIN' && !isset($status['taskId']),
    'A stuck TASK_CREATING reservation must also report UNCERTAIN');

foreach ([['bad-token', 3076], [$token, 0], [$token . "\n", 3076]] as [$invalidToken, $invalidActor]) {
    throws(static function () use ($workflow, $invalidToken, $invalidActor) {
        $workflow->status($invalidToken, $invalidActor);
    }, 'Некорректный ключ заявки');
}

echo 'PASS recruitment_workflow_test (' . $checks . " checks)\n";
