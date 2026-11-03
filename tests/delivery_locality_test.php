<?php
// php tests/delivery_locality_test.php; Bitrix and task creation are replaced with stubs.
namespace Bitrix\Main\Engine\Contract { interface Controllerable {} }
namespace Bitrix\Main {
    interface Errorable {}
    class Application {
        public static $request;
        public static function getInstance() { return new self(); }
        public function getContext() { return $this; }
        public function getRequest() { return self::$request; }
    }
    class Loader { public static function includeModule($name) {} }
}
namespace Bitrix\Main\Engine {
    class CurrentUser {
        public static function get() { return new self(); }
        public function getId() { return 1; }
    }
}
namespace Bitrix\Disk {
    class Driver {
        public static function getInstance() { return new self(); }
        public function getStorageByCommonId($id) { return $this; }
        public function getChild($filter) { return $this; }
        public function uploadFile($file, $fields) { throw new \RuntimeException('Unexpected upload'); }
    }
}
namespace Bitrix\Disk\Internals { class FolderTable { const TYPE_FOLDER = 1; } }
namespace Kuratovru\Model\Service {
    class Service { public static function getById($id) { return ['IBLOCK_SECTION_ID' => 1]; } }
}
namespace Kuratovru\Model\TaskBuilder {
    class TaskServiceBuilder {
        public const BASE_TITLE = 'Услуга: Проработка стоимости доставки по РФ и ЕАЭС';
        public static $instances = [];
        public $title = self::BASE_TITLE;
        public $description;
        public $built = false;
        public function __construct($service) { self::$instances[] = $this; }
        public function __call($name, $args) { return $this; }
        public function setDescription($description) { $this->description = $description; return $this; }
        public function getTitle() { return $this->title; }
        public function setTitle($title) { $this->title = $title; return $this; }
        public function build() { $this->built = true; return $this; }
        public function getTaskId() { return 123; }
    }
}
namespace {
    use Bitrix\Main\Application;
    use Kuratovru\Model\TaskBuilder\TaskServiceBuilder;

    class CBitrixComponent {}
    class TestRequest {
        public $post;
        public $fileReads = 0;
        public function __construct(array $post) { $this->post = $post; }
        public function getPost($key) { return $this->post[$key] ?? null; }
        public function getFile($key) {
            $this->fileReads++;
            return ['name' => [''], 'error' => [4], 'size' => [0], 'tmp_name' => [''], 'type' => ['']];
        }
    }
    define('B_PROLOG_INCLUDED', true);
    $_SERVER['APP_ENV'] = 'prod';
    $config = require __DIR__ . '/../config.php';
    require __DIR__ . '/../class.php';
    set_error_handler(static function ($severity, $message, $file, $line) {
        throw new \ErrorException($message, 0, $severity, $file, $line);
    });

    $reflection = new \ReflectionClass(ServicesMain::class);
    $component = $reflection->newInstanceWithoutConstructor();
    $property = $reflection->getProperty('extraFieldsConfig');
    $property->setAccessible(true);
    $property->setValue($component, $config);
    $fillRules = $reflection->getMethod('fillServiceRules');
    $fillRules->setAccessible(true);
    $fillRules->invoke($component);
    $checks = 0;
    function check($condition, $message) {
        global $checks;
        if (!$condition) { throw new \RuntimeException($message); }
        $checks++;
    }
    function send($component, $id, array $overrides = []) {
        TaskServiceBuilder::$instances = [];
        Application::$request = new TestRequest(array_replace([
            'select-service' => (string)$id,
            'select-department' => '1',
            'select-priority' => '95',
            'input-deadline' => '2030-01-15 12:00:00',
            'description' => 'Адрес грузополучателя: г. Вологда, ул. Мальцева, д. 52',
        ], $overrides));
        return $component->sendAction();
    }

    $scopedIds = [];
    foreach ($config['elements'] as $element) {
        foreach ($element['fields'] as $field) {
            if ($field['name'] === 'field_delivery_locality') {
                $scopedIds = array_merge($scopedIds, $element['values']);
                check($field['type'] === 'text' && $field['required'] && $field['concat_title'],
                    'Locality must be a required title field in the existing form');
            }
        }
    }
    check($scopedIds === [291349, 291350, 291351], 'Only the three RF/EAEU services are in scope');

    foreach ($scopedIds as $serviceId) {
        foreach ([null, '', '   ', "\t\n\u{00A0}\u{200B}\u{FEFF}", ['Вологда'], "\xFF", 123] as $invalid) {
            $response = send($component, $serviceId, ['field_delivery_locality' => $invalid]);
            check(!empty($response['fieldErrors']['field_delivery_locality']), 'Invalid locality must be rejected');
            check(Application::$request->fileReads === 0, 'Validation must precede file access');
            check(TaskServiceBuilder::$instances === [], 'Validation must precede builder construction');
        }
        // Omitted, duplicated and malformed client title lists cannot change the server-owned suffix.
        foreach ([null, '', 'field_delivery_locality,field_delivery_locality', 'description', ['description']] as $list) {
            $response = send($component, $serviceId, [
                'field_delivery_locality' => "\u{00A0}Великий\t\nНовгород\u{200B}",
                'fields-for-concat-title' => $list,
            ]);
            $task = TaskServiceBuilder::$instances[0];
            check($response['taskId'] === 123 && $task->built, 'Valid request must reach the builder');
            check($task->title === TaskServiceBuilder::BASE_TITLE . '_Великий Новгород', 'Exactly one normalized locality suffix');
            check(substr_count($task->description, 'Населённый пункт доставки груза: Великий Новгород') === 1,
                'Locality must also remain in the description');
            check(strpos($task->description, 'ул. Мальцева, д. 52') !== false, 'The full address must remain intact');
        }
    }
    $description = "Пояснение\n\nНаселённый пункт доставки груза: Вологда";
    for ($retry = 0; $retry < 2; $retry++) {
        send($component, 291349, ['field_delivery_locality' => 'Вологда', 'description' => $description]);
        check(TaskServiceBuilder::$instances[0]->description === $description, 'Repeated submission must not duplicate the description line');
    }

    // The existing contractor-name suffix continues to use its client field list.
    send($component, 260970, ['fields-for-concat-title' => 'field_legacy_name', 'field_legacy_name' => 'ООО Пример']);
    check(TaskServiceBuilder::$instances[0]->title === TaskServiceBuilder::BASE_TITLE . '_ООО Пример',
        'Legacy title concatenation must keep working');
    send($component, 341103, ['field_delivery_locality' => 'Вологда']);
    check(TaskServiceBuilder::$instances[0]->title === TaskServiceBuilder::BASE_TITLE,
        'Unrelated services must not use the delivery locality');

    // VED delivery shares address fields, but keeps its existing supply rules and scope.
    $vedFields = [
        'service_fields_version' => 'supply-rules-20260915-v3',
        'field_supply_item_name' => 'Груз',
        'field_supply_quantity' => '1 шт.',
        'field_supply_characteristics' => 'По чертежу',
        'field_supply_new_product' => 'Нет',
        'field_supply_special_conditions' => 'Нет',
    ];
    $response = send($component, 291448, $vedFields);
    check(isset($response['taskId']), 'VED must not require the new locality field');
    $task = TaskServiceBuilder::$instances[0];
    check($task->title === TaskServiceBuilder::BASE_TITLE, 'VED title must remain unchanged');
    check(strpos($task->description, 'Обязательный текст') !== false, 'Existing supply rules must be retained');
    echo "PASS: {$checks} checks; no portal access or real tasks.\n";
}
