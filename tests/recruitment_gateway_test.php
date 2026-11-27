<?php
declare(strict_types=1);

namespace GatewayTest {
    final class State
    {
        public static array $metadata = [];
        public static array $tasks = [];
        public static array $items = [];
        public static array $events = [];
        public static array $operationUsers = [];
        public static array $permissionUsers = [];
        public static array $moduleAvailability = [];
        public static bool $responsibleActive = true;
        public static bool $categoryAllowed = true;
        public static bool $stageAllowed = true;
        public static bool $stagesEnabled = true;
        public static bool $lockAllowed = true;
        public static string $operationMode = 'success';
        public static ?string $readOnlyField = null;
        public static int $createCalls = 0;
        public static int $launchCalls = 0;
        public static ?string $heldLock = null;
        public static Pool $pool;
        public static Connection $connection;
        public static Factory $factory;

        public static function reset(array $mappings): void
        {
            self::$metadata = [];
            foreach ($mappings as $mapping) {
                self::$metadata[$mapping['crm_field']] = [
                    'FIELD_NAME' => $mapping['crm_field'],
                    'USER_TYPE_ID' => $mapping['crm_type'],
                    'MULTIPLE' => 'N',
                    'MANDATORY' => $mapping['name'] === 'hr_requester' ? 'Y' : 'N',
                ];
            }
            self::$tasks = [501 => [
                'ID' => 501, 'CREATED_BY' => 3076, 'RESPONSIBLE_ID' => 1466,
                'UF_TASK_SERVICE' => 344025, 'UF_TASK_DEPARTMENT' => 55,
            ]];
            self::$items = [];
            self::$events = [];
            self::$operationUsers = [];
            self::$permissionUsers = [];
            self::$moduleAvailability = [];
            self::$responsibleActive = true;
            self::$categoryAllowed = true;
            self::$stageAllowed = true;
            self::$stagesEnabled = true;
            self::$lockAllowed = true;
            self::$operationMode = 'success';
            self::$readOnlyField = null;
            self::$createCalls = 0;
            self::$launchCalls = 0;
            self::$heldLock = null;
            self::$pool = new Pool();
            self::$connection = new Connection();
            self::$factory = new Factory();
        }
    }

    final class Rows
    {
        private array $rows;
        public function __construct(array $rows) { $this->rows = array_values($rows); }
        public function fetch() { return array_shift($this->rows) ?: false; }
    }

    final class Pool
    {
        public bool $masterOnly = false;
        public function isMasterOnly(): bool { return $this->masterOnly; }
        public function useMasterOnly(bool $value): void { $this->masterOnly = $value; }
    }

    final class Connection
    {
        public int $unlocks = 0;
        public function lock(string $name, int $timeout): bool
        {
            \check(State::$pool->masterOnly, 'Task lock must be acquired on master');
            \check($name === 'kelast_hr_vacancy_501' && $timeout === 5, 'Lock must identify the source task');
            State::$events[] = 'lock';
            if (!State::$lockAllowed) return false;
            \check(State::$heldLock === null, 'Fixture allows one gateway lock');
            State::$heldLock = $name;
            return true;
        }
        public function unlock(string $name): bool
        {
            \check(State::$heldLock === $name, 'Only the acquired lock may be released');
            State::$events[] = 'unlock';
            State::$heldLock = null;
            $this->unlocks++;
            return true;
        }
    }

    final class Category
    {
        public function getId(): int { return 23; }
    }

    final class Stage
    {
        private string $id;
        public function __construct(string $id) { $this->id = $id; }
        public function getStatusId(): string { return $this->id; }
    }

    final class Field
    {
        private string $name;
        public function __construct(string $name) { $this->name = $name; }
        public function isUserField(): bool { return true; }
        public function isValueCanBeChanged(): bool { return State::$readOnlyField !== $this->name; }
    }

    final class Fields
    {
        public function getField(string $name): ?Field
        {
            return isset(State::$metadata[$name]) ? new Field($name) : null;
        }
    }

    final class Permissions
    {
        public function category(): self { return $this; }
        public function stage(): self { return $this; }
        public function canAddItems(Category $category): bool { return State::$categoryAllowed; }
        public function canAddInStage(int $type, int $category, string $stage): bool
        {
            \check([$type, $category, $stage] === [1118, 23, 'DT1118_23:NEW'], 'Permission check must use exact configured stage');
            return State::$stageAllowed;
        }
    }

    final class Item
    {
        public int $id;
        public array $data;
        public function __construct(int $id = 0, array $data = []) { $this->id = $id; $this->data = $data; }
        public function set(string $field, $value): void { $this->data[$field] = $value; }
        public function get(string $field) { return $this->data[$field] ?? null; }
        public function getId(): int { return $this->id; }
        public function getAssignedById(): int { return (int)$this->get('ASSIGNED_BY_ID'); }
        public function getCategoryId(): int { return (int)$this->get('CATEGORY_ID'); }
        public function getStageId(): string { return (string)$this->get('STAGE_ID'); }
    }

    final class Operation
    {
        private Item $item;
        public function __construct(Item $item, \Bitrix\Crm\Service\Context $context)
        {
            $this->item = $item;
            State::$operationUsers[] = $context->userId;
        }
        public function launch(): Result
        {
            State::$launchCalls++;
            \check(State::$pool->masterOnly && State::$heldLock !== null, 'Native creation must hold the master task lock');
            if (State::$operationMode === 'fail') return new Result(false);
            if (State::$operationMode === 'throw') throw new \RuntimeException('Native exception');
            $this->item->id = 900 + count(State::$items);
            State::$items[] = clone $this->item;
            if (State::$operationMode === 'public') State::$items[0]->set('OPENED', 'Y');
            return new Result(State::$operationMode !== 'lost-receipt');
        }
    }

    final class Result
    {
        private bool $success;
        public function __construct(bool $success) { $this->success = $success; }
        public function isSuccess(): bool { return $this->success; }
        public function getErrorMessages(): array { return ['Native validation rejected creation']; }
    }

    final class Factory
    {
        public function getCategory(int $id): ?Category { return $id === 23 ? new Category() : null; }
        public function isStagesEnabled(): bool { return State::$stagesEnabled; }
        public function getStageFromCategory(int $category, string $stage): ?Stage
        {
            return $category === 23 && in_array($stage, ['DT1118_23:NEW', 'DT1118_23:PREPARATION'], true)
                ? new Stage($stage) : null;
        }
        public function getUserFieldEntityId(): string { return 'CRM_19'; }
        public function getFieldsCollection(): Fields { return new Fields(); }
        public function getFieldsInfo(): array
        {
            return array_fill_keys(['TITLE', 'ASSIGNED_BY_ID', 'CATEGORY_ID', 'STAGE_ID', 'OPENED'], []);
        }
        public function getItems(array $query): array
        {
            State::$events[] = 'source-read';
            \check(State::$pool->masterOnly && State::$heldLock !== null, 'Source lookup must hold master task lock');
            \check($query['filter'] === ['=UF_CRM_HR_SOURCE_TASK' => 501]
                && $query['limit'] === 2 && $query['select'] === ['ID'], 'Lookup must be exact, bounded and disclose only IDs');
            return array_slice(array_values(array_filter(State::$items, static function (Item $item): bool {
                return $item->get('UF_CRM_HR_SOURCE_TASK') === 501;
            })), 0, 2);
        }
        public function createItem(): Item { State::$createCalls++; return new Item(); }
        public function getAddOperation(Item $item, \Bitrix\Crm\Service\Context $context): Operation
        {
            return new Operation($item, $context);
        }
        public function getItem(int $id): ?Item
        {
            foreach (State::$items as $item) if ($item->getId() === $id) return clone $item;
            return null;
        }
    }
}

namespace Bitrix\Main {
    final class Loader
    {
        public static function includeModule(string $name): bool
        {
            return \GatewayTest\State::$moduleAvailability[$name] ?? true;
        }
    }
    final class Application
    {
        public static function getInstance(): self { return new self(); }
        public function getConnectionPool(): \GatewayTest\Pool { return \GatewayTest\State::$pool; }
        public static function getConnection(): \GatewayTest\Connection { return \GatewayTest\State::$connection; }
    }
    final class UserTable
    {
        public static function getById(int $id): \GatewayTest\Rows
        {
            return new \GatewayTest\Rows([['ACTIVE' => \GatewayTest\State::$responsibleActive ? 'Y' : 'N']]);
        }
    }
}

namespace Bitrix\Main\Type {
    final class Date
    {
        public string $value;
        public function __construct(string $value, string $format) { $this->value = $value; }
    }
}

namespace Bitrix\Crm\Service {
    final class Context
    {
        public int $userId = 0;
        public function setUserId(int $id): self { $this->userId = $id; return $this; }
    }
    final class Container
    {
        public static function getInstance(): self { return new self(); }
        public function getFactory(int $type): ?\GatewayTest\Factory
        {
            return $type === 1118 ? \GatewayTest\State::$factory : null;
        }
        public function getUserPermissions(int $user): \GatewayTest\Permissions
        {
            \GatewayTest\State::$permissionUsers[] = $user;
            return new \GatewayTest\Permissions();
        }
    }
}

namespace {
    use GatewayTest\Item;
    use GatewayTest\Rows;
    use GatewayTest\State;
    use Kelast\Recruitment\CrmGateway;

    final class CUserTypeEntity
    {
        public static function GetList(array $order, array $filter): Rows
        {
            check($filter === ['ENTITY_ID' => 'CRM_19'], 'Metadata must be limited to the vacancy UF entity');
            return new Rows(State::$metadata);
        }
    }
    final class CTasks
    {
        public static function GetList(array $order, array $filter, array $select): Rows
        {
            State::$events[] = 'task-read';
            check(State::$pool->masterOnly, 'Task context must be read on master');
            return new Rows(isset(State::$tasks[$filter['ID']]) ? [State::$tasks[$filter['ID']]] : []);
        }
    }

    require __DIR__ . '/../recruitment/CrmGateway.php';

    set_error_handler(static function ($severity, $message, $file, $line) {
        throw new \ErrorException($message, 0, $severity, $file, $line);
    });
    $checks = 0;
    function check(bool $condition, string $message): void
    {
        global $checks;
        if (!$condition) throw new \RuntimeException($message);
        $checks++;
    }
    function rejects(callable $action, string $expected): void
    {
        try { $action(); } catch (\RuntimeException $error) {
            check(strpos($error->getMessage(), $expected) !== false, 'Unexpected error: ' . $error->getMessage());
            return;
        }
        throw new \RuntimeException('Expected rejection ' . $expected);
    }
    function fixture(array $overrides = []): CrmGateway
    {
        $config = array_replace(require __DIR__ . '/../recruitment/config.php', $overrides);
        $gateway = new CrmGateway($config);
        State::reset($gateway->getFieldMappings());
        return $gateway;
    }
    function values(array $overrides = []): array
    {
        return array_replace([
            'hr_vacancy_title' => 'Оператор ЧПУ', 'hr_requester' => 2024,
            'hr_initiator' => 3076, 'hr_request_date' => '2026-11-26',
            'hr_department' => 'Производство', 'hr_department_id' => 55,
            'hr_quantity' => '',
        ], $overrides);
    }
    function ensure(CrmGateway $gateway, array $overrides = []): array
    {
        return $gateway->ensureVacancy(501, 3076, 1466, values($overrides), 'Проверенная заявка');
    }
    function released(bool $previousMaster = false): void
    {
        check(State::$heldLock === null, 'Task lock must be released');
        check(State::$pool->masterOnly === $previousMaster, 'Previous master scope must be restored');
    }

    // Actor and responsible are intentionally different. Only the responsible executes CRM writes.
    $gateway = fixture();
    $result = ensure($gateway);
    check($result === ['id' => 900, 'created' => true], 'Creation must return the persisted native ID');
    check(State::$operationUsers === [1466] && State::$permissionUsers === [1466], 'Native operation and permission checks must use responsible');
    $saved = State::$items[0];
    check($saved->get('UF_CRM_19_1794913385') === 2024, 'Explicit requester must survive separately from actor and responsible');
    check($saved->get('UF_CRM_HR_INITIATOR') === 3076 && $saved->get('UF_CRM_HR_SOURCE_TASK') === 501, 'Source provenance must be stored');
    check($saved->get('OPENED') === 'N' && $saved->getCategoryId() === 23 && $saved->getStageId() === 'DT1118_23:NEW', 'New vacancy must remain private in the exact configured stage');
    check($saved->get('UF_CRM_HR_REQUEST_DATE') instanceof \Bitrix\Main\Type\Date, 'Request date must use a native Date object');
    check($saved->get('UF_CRM_HR_REQUEST_DATE')->value === '2026-11-26', 'Request date must retain its original accepted value');
    check(!array_key_exists('UF_CRM_HR_QUANTITY', $saved->data)
        && !array_key_exists('UF_CRM_19_1794913420', $saved->data), 'Empty optional quantity and opening date must stay unset');
    check($saved->get('UF_CRM_HR_REQUEST_TEXT') === 'Проверенная заявка', 'Canonical description must be preserved');
    check(State::$events[0] === 'task-read' && array_search('lock', State::$events, true) < array_search('source-read', State::$events, true), 'Source lookup must follow trusted task validation and locking');
    released();
    $result = ensure($gateway, ['hr_vacancy_title' => 'Не перезаписывать']);
    check($result === ['id' => 900, 'created' => false] && State::$createCalls === 1, 'Retry must return the existing vacancy without any mutation');
    check(State::$items[0]->get('TITLE') === 'Оператор ЧПУ', 'Retry must preserve user-edited or previously stored data');
    released();

    // The native operation fake intentionally exposes no disableCheck* methods.
    check(get_class_methods(\GatewayTest\Operation::class) === ['__construct', 'launch'], 'Test double must reject any attempted native check bypass');

    $gateway = fixture();
    State::$items = [new Item(700, ['UF_CRM_HR_SOURCE_TASK' => 777])];
    check(ensure($gateway)['created'] === true && count(State::$items) === 2, 'Unrelated vacancy must not satisfy source lookup');
    released();

    $gateway = fixture();
    State::$items = [new Item(700, ['UF_CRM_HR_SOURCE_TASK' => 501]), new Item(701, ['UF_CRM_HR_SOURCE_TASK' => 501])];
    rejects(static function () use ($gateway) { ensure($gateway); }, 'HR_CRM_DUPLICATE_SOURCE_TASK');
    check(State::$createCalls === 0, 'Duplicate source IDs must block additional creation');
    released();

    foreach (['CREATED_BY' => 42, 'RESPONSIBLE_ID' => 42, 'UF_TASK_SERVICE' => 42, 'UF_TASK_DEPARTMENT' => 42] as $field => $wrong) {
        $gateway = fixture();
        State::$tasks[501][$field] = $wrong;
        rejects(static function () use ($gateway) { ensure($gateway); }, 'HR_CRM_TASK_CONTEXT_MISMATCH');
        check(!in_array('source-read', State::$events, true) && State::$createCalls === 0, 'Foreign task context must not reveal any vacancy IDs');
        released();
    }

    foreach (['USER_TYPE_ID' => 'string', 'MULTIPLE' => 'Y'] as $attribute => $wrong) {
        $gateway = fixture();
        State::$metadata['UF_CRM_HR_SOURCE_TASK'][$attribute] = $wrong;
        rejects(static function () use ($gateway) { ensure($gateway); }, 'HR_CRM_FIELD_MISMATCH');
        check(State::$createCalls === 0, 'Incompatible UF schema must fail before a native item is created');
        released();
    }
    $gateway = fixture();
    State::$metadata['UF_CRM_19_1794913385']['MANDATORY'] = 'N';
    rejects(static function () use ($gateway) { ensure($gateway); }, 'HR_CRM_REQUESTER_MUST_BE_REQUIRED');
    released();
    $gateway = fixture();
    State::$readOnlyField = 'UF_CRM_HR_SOURCE_TASK';
    rejects(static function () use ($gateway) { ensure($gateway); }, 'HR_CRM_FIELD_MISMATCH');
    released();
    $gateway = fixture();
    State::$moduleAvailability['crm'] = false;
    rejects(static function () use ($gateway) { ensure($gateway); }, 'HR_CRM_MODULE_UNAVAILABLE');
    check(State::$createCalls === 0, 'Unavailable CRM must fail before creating an item');
    released();
    $gateway = fixture();
    State::$stagesEnabled = false;
    rejects(static function () use ($gateway) { ensure($gateway); }, 'HR_CRM_STAGES_DISABLED');
    released();

    foreach (['categoryAllowed', 'stageAllowed', 'responsibleActive'] as $flag) {
        $gateway = fixture();
        State::${$flag} = false;
        rejects(static function () use ($gateway) { ensure($gateway); }, $flag === 'responsibleActive' ? 'HR_CRM_RESPONSIBLE_INACTIVE' : 'HR_CRM_CREATE_PERMISSION_DENIED');
        check(State::$createCalls === 0, 'Missing permission or inactive responsible must prevent creation');
        released();
    }
    $gateway = fixture(['vacancy_stage_id' => 'DT1118_23:SUCCESS']);
    rejects(static function () use ($gateway) { ensure($gateway); }, 'HR_CRM_INITIAL_STAGE_NOT_FOUND');
    released();
    $gateway = fixture(['vacancy_category_id' => 0]);
    rejects(static function () use ($gateway) { ensure($gateway); }, 'HR_CRM_CONFIG_INVALID');
    released();
    $gateway = fixture(['vacancy_category_id' => 24]);
    rejects(static function () use ($gateway) { ensure($gateway); }, 'HR_CRM_CATEGORY_NOT_FOUND');
    released();

    $gateway = fixture();
    State::$lockAllowed = false;
    rejects(static function () use ($gateway) { ensure($gateway); }, 'HR_CRM_TASK_LOCK_BUSY');
    check(State::$connection->unlocks === 0 && State::$createCalls === 0, 'Failed lock acquisition must not release another lock or create');
    released();
    foreach (['fail' => 'HR_CRM_CREATE_FAILED', 'throw' => 'Native exception', 'public' => 'HR_CRM_SAVED_FIELDS_MISMATCH'] as $mode => $error) {
        $gateway = fixture();
        State::$operationMode = $mode;
        rejects(static function () use ($gateway) { ensure($gateway); }, $error);
        check(State::$connection->unlocks === 1, 'Native rejection must still release the task lock');
        released();
        if ($mode === 'public') {
            State::$operationMode = 'success';
            rejects(static function () use ($gateway) { ensure($gateway); }, 'HR_CRM_SAVED_FIELDS_MISMATCH');
            check(State::$createCalls === 1 && State::$launchCalls === 1 && count(State::$items) === 1,
                'Retry must keep rejecting the public saved item without adding another vacancy');
            check(State::$items[0]->get('OPENED') === 'Y', 'Rejected retry must not silently repair saved access');
            released();
        }
    }

    // A source link alone cannot certify an existing vacancy after its protected fields changed.
    foreach ([
        'ASSIGNED_BY_ID' => 42,
        'CATEGORY_ID' => 24,
        'UF_CRM_19_1794913385' => 42,
        'STAGE_ID' => 'DT1118_24:NEW',
    ] as $field => $changedValue) {
        $gateway = fixture();
        ensure($gateway);
        State::$items[0]->set($field, $changedValue);
        rejects(static function () use ($gateway) { ensure($gateway); }, 'HR_CRM_SAVED_FIELDS_MISMATCH');
        check(State::$createCalls === 1 && State::$launchCalls === 1 && count(State::$items) === 1,
            'Existing saved-field mismatch must prevent success without launching another add');
        check(State::$items[0]->get($field) === $changedValue, 'Rejected existing item must not be mutated');
        released();
    }

    // A native operation may persist an item and report an error afterwards.
    $gateway = fixture();
    State::$operationMode = 'lost-receipt';
    rejects(static function () use ($gateway) { ensure($gateway); }, 'HR_CRM_CREATE_FAILED');
    released();
    // HR can progress the committed vacancy before the client retries the lost response.
    State::$items[0]->set('STAGE_ID', 'DT1118_23:PREPARATION');
    State::$operationMode = 'success';
    check(ensure($gateway) === ['id' => 900, 'created' => false] && State::$launchCalls === 1,
        'Retry after a lost receipt must accept the committed item progressed within its category');
    check(State::$items[0]->getStageId() === 'DT1118_23:PREPARATION' && State::$createCalls === 1,
        'Retry must preserve the stage already selected by HR without another add');
    released();

    $gateway = fixture();
    State::$pool->masterOnly = true;
    ensure($gateway, ['hr_quantity' => 2]);
    check(State::$items[0]->get('UF_CRM_HR_QUANTITY') === 2, 'Filled quantity must retain integer type');
    released(true);
    $gateway = fixture();
    rejects(static function () use ($gateway) { ensure($gateway, ['hr_request_date' => '2026-02-31']); }, 'HR_CRM_CANONICAL_FIELD_INVALID');
    check(State::$launchCalls === 0, 'Invalid native date must fail before operation launch');
    released();

    foreach (array_keys(State::$metadata) as $name) check(strlen($name) <= 50, 'CRM UF name exceeds the setup size limit');
    echo 'PASS recruitment gateway (' . $checks . " checks)\n";
}
