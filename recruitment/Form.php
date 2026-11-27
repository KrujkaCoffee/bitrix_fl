<?php

namespace Kelast\Recruitment;

use Bitrix\Main\Engine\CurrentUser;
use Bitrix\Main\Config\Option;
use Bitrix\Main\UserTable;

class Form
{
    private $config;
    private $schema;

    public function __construct(array $config)
    {
        $this->config = $config;
        $this->schema = require __DIR__ . '/schema.php';
    }

    public function applies($service): bool
    {
        if ((!is_int($service) && !is_string($service))
            || (string)$service !== (string)$this->config['service_id']) {
            return false;
        }
        $actor = (int)CurrentUser::get()->getId();
        return $actor > 0 && in_array($actor, $this->config['pilot_user_ids'], true)
            && Option::get($this->config['option_module'], 'enabled', 'N') === 'Y';
    }

    public function clientSchema(): array
    {
        // CRM mappings stay on the server.
        $fields = [];
        foreach ($this->schema['fields'] as $field) {
            unset($field['crm_field'], $field['crm_type']);
            $fields[] = $field;
        }
        return ['version' => $this->schema['version'], 'recruitment' => true, 'fields' => $fields];
    }

    public function prepare($request, int $actor): array
    {
        $errors = [];
        $values = [];
        if ($request->getPost('service_fields_version') !== $this->schema['version']) {
            return ['errors' => ['Форма подбора обновлена. Сохраните введённые сведения и обновите страницу.'], 'fieldErrors' => []];
        }
        foreach ($this->schema['fields'] as $field) {
            $name = $field['name'];
            $value = self::normalize($request->getPost($name));
            $message = '';
            if ($value === null) {
                $message = 'Некорректное значение поля «' . $field['label'] . '».';
            } elseif (!empty($field['required']) && $value === '') {
                $message = 'Заполните поле «' . $field['label'] . '».';
            } elseif ($value !== '' && in_array($field['type'], ['integer', 'employee'], true)) {
                if (!preg_match('/^[0-9]{1,9}$/D', $value) || (int)$value < ($field['min'] ?? 1)) {
                    $message = 'Укажите допустимое положительное число в поле «' . $field['label'] . '».';
                } elseif ($field['type'] === 'employee' && !self::isActiveEmployee((int)$value)) {
                    $message = 'Выберите действующего сотрудника в поле «' . $field['label'] . '».';
                } else {
                    $value = (int)$value;
                }
            } elseif ($value !== '' && mb_strlen($value, 'UTF-8') > ($field['max_length'] ?? 10000)) {
                $message = 'Слишком длинное значение поля «' . $field['label'] . '».';
            }
            if ($message !== '') {
                $errors[$name] = $message;
            }
            $values[$name] = $value;
        }
        $description = self::normalize($request->getPost('description'));
        if ($description === null || mb_strlen($description, 'UTF-8') > 30000) {
            $errors['description'] = 'Некорректное или слишком длинное описание заявки.';
        }
        if ($errors) {
            return ['errors' => array_values($errors), 'fieldErrors' => $errors];
        }
        $departmentId = $request->getPost('select-department');
        if (!is_string($departmentId) || !ctype_digit($departmentId)) {
            return ['errors' => ['Выберите ваше подразделение.'], 'fieldErrors' => []];
        }
        \Bitrix\Main\Loader::includeModule('iblock');
        $department = \CIBlockElement::GetList([], ['ID' => (int)$departmentId,
            'IBLOCK_ID' => $this->config['department_iblock_id'], 'ACTIVE' => 'Y'],
            false, ['nTopCount' => 1], ['ID', 'NAME'])->Fetch();
        if (!$department) {
            return ['errors' => ['Подразделение не найдено. Выберите действующее подразделение.'], 'fieldErrors' => []];
        }
        $values['hr_initiator'] = $actor;
        $values['hr_request_date'] = date('Y-m-d');
        $values['hr_department'] = (string)$department['NAME'];
        $values['hr_department_id'] = (int)$department['ID'];
        $initiator = UserTable::getById($actor)->fetch();
        $name = trim(implode(' ', array_filter([$initiator['LAST_NAME'] ?? '', $initiator['NAME'] ?? '', $initiator['SECOND_NAME'] ?? ''])));
        $lines = ['Заявка на подбор персонала', 'Дата заявки: ' . date('d.m.Y'),
            'Инициатор заявки: ' . ($name ?: ('Сотрудник №' . $actor)), 'Отдел: ' . $values['hr_department']];
        foreach ($this->schema['fields'] as $field) {
            $value = $values[$field['name']];
            if ($value === '') {
                continue;
            }
            if ($field['type'] === 'employee') {
                $user = UserTable::getById((int)$value)->fetch();
                $value = trim(implode(' ', [$user['LAST_NAME'], $user['NAME'], $user['SECOND_NAME']])) . ' (№' . $value . ')';
            }
            $lines[] = $field['label'] . ': ' . $value;
        }
        if ($description !== '') {
            $lines[] = 'Дополнительные сведения: ' . $description;
        }
        return ['errors' => [], 'fieldErrors' => [], 'values' => $values,
            'description' => implode("\n\n", $lines), 'inputDescription' => $description];
    }

    public static function normalize($value): ?string
    {
        if ($value === null) {
            return '';
        }
        if (!is_string($value) || !preg_match('//u', $value) || strpos($value, "\0") !== false) {
            return null;
        }
        $value = str_replace(["\r\n", "\r"], "\n", $value);
        return preg_replace('/^[\s\p{Z}\x{200B}\x{FEFF}]+|[\s\p{Z}\x{200B}\x{FEFF}]+$/u', '', $value);
    }

    public static function isActiveEmployee(int $id): bool
    {
        $user = UserTable::getList(['filter' => ['=ID' => $id, '=ACTIVE' => 'Y'],
            'select' => ['ID', 'UF_DEPARTMENT']])->fetch();
        return $user && array_filter((array)$user['UF_DEPARTMENT'], static function ($id) { return (int)$id > 0; });
    }
}
