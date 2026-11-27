<?php
// php tests/recruitment_form_test.php; all Bitrix reads use in-memory fixtures.

namespace Bitrix\Main\Engine {
    class CurrentUser
    {
        public static $id = 3076;

        public static function get() { return new self(); }
        public function getId() { return self::$id; }
    }
}

namespace Bitrix\Main\Config {
    class Option
    {
        public static $values = [];

        public static function get($module, $name, $default = '')
        {
            return self::$values[$module][$name] ?? $default;
        }
    }
}

namespace Bitrix\Main {
    class Loader
    {
        public static $modules = [];

        public static function includeModule($name)
        {
            self::$modules[] = $name;
            return true;
        }
    }

    class UserTable
    {
        public static $users = [];

        public static function getById($id)
        {
            return new \RecruitmentFixtureResult(self::$users[$id] ?? false);
        }

        public static function getList(array $parameters)
        {
            $filter = $parameters['filter'];
            $user = self::$users[$filter['=ID']] ?? false;
            if ($user && $user['ACTIVE'] !== $filter['=ACTIVE']) {
                $user = false;
            }
            return new \RecruitmentFixtureResult($user);
        }
    }
}

namespace {
    use Bitrix\Main\Config\Option;
    use Bitrix\Main\Engine\CurrentUser;
    use Bitrix\Main\Loader;
    use Bitrix\Main\UserTable;
    use Kelast\Recruitment\Form;

    class RecruitmentFixtureResult
    {
        private $row;

        public function __construct($row) { $this->row = $row; }
        public function fetch() { return $this->row; }
    }

    class CIBlockElement
    {
        public static $departments = [];
        public static $queries = [];

        public static function GetList($order, $filter, $group, $navigation, $select)
        {
            self::$queries[] = $filter;
            $row = self::$departments[$filter['ID']] ?? false;
            if ($row && ($row['IBLOCK_ID'] !== $filter['IBLOCK_ID'] || $row['ACTIVE'] !== $filter['ACTIVE'])) {
                $row = false;
            }
            return new RecruitmentFixtureResult($row);
        }
    }

    class RecruitmentFormRequest
    {
        private $post;

        public function __construct(array $post) { $this->post = $post; }
        public function getPost($name) { return $this->post[$name] ?? null; }
    }

    require __DIR__ . '/../recruitment/Form.php';
    $config = require __DIR__ . '/../recruitment/config.php';
    $form = new Form($config);
    $checks = 0;

    set_error_handler(static function ($severity, $message, $file, $line) {
        throw new \ErrorException($message, 0, $severity, $file, $line);
    });

    function check($condition, $message)
    {
        global $checks;
        if (!$condition) {
            throw new \RuntimeException($message);
        }
        $checks++;
    }

    function prepareRecruitment(Form $form, array $overrides = [])
    {
        $post = array_replace([
            'service_fields_version' => 'hr-20261126-1',
            'hr_vacancy_title' => 'Кладовщик',
            'hr_requester' => '1466',
            'select-department' => '503',
            'description' => '',
        ], $overrides);
        return $form->prepare(new RecruitmentFormRequest($post), 3076);
    }

    function checkRecruitmentFieldError(Form $form, $field, $value)
    {
        $response = prepareRecruitment($form, [$field => $value]);
        check(!empty($response['errors']) && !empty($response['fieldErrors'][$field]),
            'Invalid value must return a field error for ' . $field);
        check(!isset($response['values']), 'Invalid input must not return a prepared payload');
    }

    UserTable::$users = [
        3076 => ['ID' => 3076, 'ACTIVE' => 'Y', 'UF_DEPARTMENT' => [99],
            'LAST_NAME' => 'Федоров', 'NAME' => 'Алексей', 'SECOND_NAME' => ''],
        1466 => ['ID' => 1466, 'ACTIVE' => 'Y', 'UF_DEPARTMENT' => [503],
            'LAST_NAME' => 'Заказчик', 'NAME' => 'Анна', 'SECOND_NAME' => 'Петровна'],
        20 => ['ID' => 20, 'ACTIVE' => 'N', 'UF_DEPARTMENT' => [503],
            'LAST_NAME' => 'Неактивный', 'NAME' => 'Сотрудник', 'SECOND_NAME' => ''],
        21 => ['ID' => 21, 'ACTIVE' => 'Y', 'UF_DEPARTMENT' => [],
            'LAST_NAME' => 'Внешний', 'NAME' => 'Пользователь', 'SECOND_NAME' => ''],
        22 => ['ID' => 22, 'ACTIVE' => 'Y', 'UF_DEPARTMENT' => [0, '0', ''],
            'LAST_NAME' => 'Без', 'NAME' => 'Подразделения', 'SECOND_NAME' => ''],
        23 => ['ID' => 23, 'ACTIVE' => 'Y', 'UF_DEPARTMENT' => null,
            'LAST_NAME' => 'Без', 'NAME' => 'Данных', 'SECOND_NAME' => ''],
        24 => ['ID' => 24, 'ACTIVE' => 'Y', 'UF_DEPARTMENT' => ['503'],
            'LAST_NAME' => 'Действующий', 'NAME' => 'Сотрудник', 'SECOND_NAME' => ''],
    ];
    CIBlockElement::$departments = [
        503 => ['ID' => 503, 'NAME' => 'Отдел складской логистики', 'ACTIVE' => 'Y', 'IBLOCK_ID' => 55],
        504 => ['ID' => 504, 'NAME' => 'Закрытый отдел', 'ACTIVE' => 'N', 'IBLOCK_ID' => 55],
        505 => ['ID' => 505, 'NAME' => 'Чужой справочник', 'ACTIVE' => 'Y', 'IBLOCK_ID' => 44],
    ];

    check($config['service_id'] === 344025, 'The recruitment pilot must use the verified service');
    Option::$values[$config['option_module']]['enabled'] = 'Y';
    foreach ([3076, 1466] as $actor) {
        CurrentUser::$id = $actor;
        check($form->applies(344025), 'The enabled pilot must apply for each authorized employee');
        check($form->applies('344025'), 'The submitted string service ID must be accepted');
    }
    foreach ([0, 1, 344024, 344026, null, '', '344025unexpected', ['344025'], 344025.5] as $service) {
        check(!$form->applies($service), 'The pilot must reject an unrelated or malformed service ID');
    }
    foreach ([0, -1, 9999] as $actor) {
        CurrentUser::$id = $actor;
        check(!$form->applies(344025), 'Only the configured pilot employees may use the form');
    }
    CurrentUser::$id = 3076;
    foreach (['N', '', 'y', null] as $enabled) {
        Option::$values[$config['option_module']]['enabled'] = $enabled;
        check(!$form->applies(344025), 'Activation must require the enabled option to equal Y');
    }
    Option::$values[$config['option_module']]['enabled'] = 'Y';

    $client = $form->clientSchema();
    check($client['version'] === 'hr-20261126-1' && $client['recruitment'] === true,
        'The client must receive the current recruitment schema version');
    check(strpos(json_encode($client), 'UF_CRM_') === false, 'Client schema must hide all CRM field codes');
    foreach ($client['fields'] as $field) {
        check(!array_key_exists('crm_field', $field) && !array_key_exists('crm_type', $field),
            'Server CRM mapping keys must be absent from every client field');
    }

    $response = prepareRecruitment($form);
    check($response['errors'] === [] && $response['fieldErrors'] === [], 'The minimum complete form must be accepted');
    check($response['values']['hr_quantity'] === '', 'Quantity must remain optional');
    check($response['values']['hr_requester'] === 1466, 'The selected requester must be retained');
    check($response['values']['hr_initiator'] === 3076, 'The initiator must be the server actor');
    check($response['values']['hr_requester'] !== $response['values']['hr_initiator'],
        'A different requester must not replace the initiator');
    check($response['values']['hr_request_date'] === date('Y-m-d'), 'The request date must come from the server');
    check($response['values']['hr_department_id'] === 503
        && $response['values']['hr_department'] === 'Отдел складской логистики',
        'The department must come from the selected department record');
    check(strpos($response['description'], 'Инициатор заявки: Федоров Алексей') !== false,
        'The task description must identify the actual initiator');
    check(strpos($response['description'], 'Заказчик вакансии: Заказчик Анна Петровна (№1466)') !== false,
        'The task description must identify the distinct requester');

    foreach ([null, '', '   ', "\t\n\u{00A0}\u{200B}\u{FEFF}"] as $blank) {
        checkRecruitmentFieldError($form, 'hr_vacancy_title', $blank);
        checkRecruitmentFieldError($form, 'hr_requester', $blank);
    }
    foreach ([['Кладовщик'], 123, false, new \stdClass(), "\xFF", "Клад\0овщик"] as $invalid) {
        checkRecruitmentFieldError($form, 'hr_vacancy_title', $invalid);
    }
    foreach ([['1466'], 1466, '0', '-1', '1.5', '1e3', '1466x', '9999999999', '9999', '20', '21', '22', '23'] as $invalid) {
        checkRecruitmentFieldError($form, 'hr_requester', $invalid);
    }
    $response = prepareRecruitment($form, ['hr_requester' => '24']);
    check($response['errors'] === [] && $response['values']['hr_requester'] === 24,
        'A current employee with a numeric-string UF_DEPARTMENT must be accepted');

    foreach ([null, '', "\u{00A0}\u{200B}"] as $blank) {
        $response = prepareRecruitment($form, ['hr_quantity' => $blank]);
        check($response['errors'] === [] && $response['values']['hr_quantity'] === '',
            'Missing quantity must remain an empty optional value');
    }
    foreach (['1', '7'] as $quantity) {
        $response = prepareRecruitment($form, ['hr_quantity' => $quantity]);
        check($response['errors'] === [] && $response['values']['hr_quantity'] === (int)$quantity,
            'A positive whole quantity must be retained as an integer');
    }
    foreach (['0', '-1', '1.5', '1e3', '7 сотрудников', ['1'], 1, '9999999999'] as $invalid) {
        checkRecruitmentFieldError($form, 'hr_quantity', $invalid);
    }

    $response = prepareRecruitment($form, [
        'hr_vacancy_title' => "\u{FEFF}\u{00A0}Кладовщик\u{200B}\t",
        'hr_probation_salary' => '10 230 руб. до вычета (gross)',
        'hr_salary_after_probation' => 'от 10 230 руб. (по результатам прохождения испытательного срока)',
        'hr_target_start' => 'до 31.05.2023',
        'hr_mentor' => 'Ильин А.Е.',
        'hr_duties' => "\u{00A0}Учёт ТМЦ\r\nИнвентаризация\rПроверка склада\u{200B}",
        'description' => "\u{FEFF}Дополнение\r\nВторая строка\u{00A0}",
        'hr_initiator' => '1466',
        'hr_request_date' => '1900-01-01',
        'hr_department' => 'Подменённый отдел',
        'hr_department_id' => '505',
    ]);
    check($response['errors'] === [], 'Valid Unicode text and literal employment terms must be accepted');
    check($response['values']['hr_vacancy_title'] === 'Кладовщик', 'Unicode whitespace must be trimmed at both edges');
    check($response['values']['hr_duties'] === "Учёт ТМЦ\nИнвентаризация\nПроверка склада",
        'Multiline text must preserve lines and normalize line endings');
    check($response['values']['hr_probation_salary'] === '10 230 руб. до вычета (gross)',
        'Salary text must preserve the amount and gross qualification');
    check($response['values']['hr_salary_after_probation']
        === 'от 10 230 руб. (по результатам прохождения испытательного срока)',
        'Salary bounds and probation conditions must remain literal');
    check($response['values']['hr_target_start'] === 'до 31.05.2023', 'The planned start deadline must preserve до');
    check($response['values']['hr_mentor'] === 'Ильин А.Е.', 'Mentor initials must remain a text value');
    check($response['inputDescription'] === "Дополнение\nВторая строка", 'Additional input must use the same text normalization');
    check(strpos($response['description'], '10 230 руб. до вычета (gross)') !== false
        && strpos($response['description'], 'Дата выхода кандидата на работу: до 31.05.2023') !== false,
        'Literal employment terms must also reach the task description');
    check($response['values']['hr_initiator'] === 3076
        && $response['values']['hr_request_date'] === date('Y-m-d')
        && $response['values']['hr_department_id'] === 503,
        'Submitted values must not override server-owned initiator, date or department');
    check(!array_key_exists('actual_start', $response['values']) && !array_key_exists('opened_at', $response['values']),
        'Preparing a requisition must not invent actual start or opening dates');

    foreach (['hr_benefits', 'hr_target_start', 'description'] as $field) {
        checkRecruitmentFieldError($form, $field, ['unexpected']);
    }
    $response = prepareRecruitment($form, ['hr_vacancy_title' => str_repeat('Я', 255)]);
    check($response['errors'] === [], 'The title limit must count Unicode characters');
    checkRecruitmentFieldError($form, 'hr_vacancy_title', str_repeat('Я', 256));
    checkRecruitmentFieldError($form, 'description', str_repeat('Я', 30001));

    foreach ([null, '', 'hr-20261120-1', ['hr-20261126-1']] as $version) {
        $response = prepareRecruitment($form, ['service_fields_version' => $version]);
        check(!empty($response['errors']) && !isset($response['values']),
            'A stale, absent or malformed field schema version must be rejected');
    }
    foreach ([null, '', ['503'], '503x', '-1', '504', '505', '999'] as $department) {
        $response = prepareRecruitment($form, ['select-department' => $department]);
        check(!empty($response['errors']) && !isset($response['values']),
            'The selected department must be an active record in the configured directory');
    }
    check(in_array('iblock', Loader::$modules, true), 'Department resolution must load the iblock module');

    echo "PASS: {$checks} recruitment form checks; Bitrix reads use fixtures.\n";
}
