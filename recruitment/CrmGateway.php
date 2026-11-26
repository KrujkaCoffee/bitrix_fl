<?php
declare(strict_types=1);

namespace Kelast\Recruitment;

use Bitrix\Crm\Service\Container;
use Bitrix\Crm\Service\Context;
use Bitrix\Main\Application;
use Bitrix\Main\Loader;
use Bitrix\Main\Type\Date;

require_once __DIR__ . '/Gateway.php';

final class CrmGateway implements Gateway
{
    private array $config;
    private array $fields;

    public function __construct(array $config)
    {
        $this->config = $config;
        $this->fields = $config['fields'] ?? require __DIR__ . '/schema.php';
        if (isset($this->fields['fields'])) {
            $this->fields = $this->fields['fields'];
        }
    }

    public function preflight(int $actor, int $responsible): void
    {
        if ($actor <= 0 || $responsible <= 0) {
            throw new \RuntimeException('HR_CRM_INVALID_USER_CONTEXT');
        }
        $responsibleUser = \Bitrix\Main\UserTable::getById($responsible)->fetch();
        if (!$responsibleUser || $responsibleUser['ACTIVE'] !== 'Y') {
            throw new \RuntimeException('HR_CRM_RESPONSIBLE_INACTIVE');
        }
        $factory = $this->getFactory();
        $categoryId = $this->positiveConfigId('vacancy_category_id');
        $stageId = $this->requiredConfigString('vacancy_stage_id');
        $category = $factory->getCategory($categoryId);
        if ($category === null || (int)$category->getId() !== $categoryId) {
            throw new \RuntimeException('HR_CRM_CATEGORY_NOT_FOUND');
        }
        if (!$factory->isStagesEnabled()) {
            throw new \RuntimeException('HR_CRM_STAGES_DISABLED');
        }
        $stage = $factory->getStageFromCategory($categoryId, $stageId);
        if ($stage === null || $stage->getStatusId() !== $stageId) {
            throw new \RuntimeException('HR_CRM_INITIAL_STAGE_NOT_FOUND');
        }
        $permissions = Container::getInstance()->getUserPermissions($responsible);
        if (!method_exists($permissions, 'category') || !method_exists($permissions, 'stage')) {
            throw new \RuntimeException('HR_CRM_PERMISSION_API_UNAVAILABLE');
        }
        $categoryPermissions = $permissions->category();
        $stagePermissions = $permissions->stage();
        if (!method_exists($categoryPermissions, 'canAddItems')
            || !method_exists($stagePermissions, 'canAddInStage')) {
            throw new \RuntimeException('HR_CRM_PERMISSION_API_UNAVAILABLE');
        }
        if (!$categoryPermissions->canAddItems($category)
            || !$stagePermissions->canAddInStage(
                $this->positiveConfigId('vacancy_entity_type_id'),
                $categoryId,
                $stageId
            )) {
            throw new \RuntimeException('HR_CRM_CREATE_PERMISSION_DENIED');
        }

        $userFields = [];
        $result = \CUserTypeEntity::GetList([], ['ENTITY_ID' => $factory->getUserFieldEntityId()]);
        while ($field = $result->Fetch()) {
            $userFields[$field['FIELD_NAME']] = $field;
        }
        $factoryFields = $factory->getFieldsInfo();
        $fieldCollection = $factory->getFieldsCollection();
        foreach ($this->getFieldMappings() as $mapping) {
            $name = $mapping['crm_field'];
            $metadata = $userFields[$name] ?? null;
            $crmField = $fieldCollection->getField($name);
            if ($crmField === null || !$crmField->isUserField() || !$crmField->isValueCanBeChanged()
                || $metadata === null
                || $metadata['USER_TYPE_ID'] !== $mapping['crm_type']
                || $metadata['MULTIPLE'] !== 'N') {
                throw new \RuntimeException('HR_CRM_FIELD_MISMATCH: ' . $name);
            }
        }
        $requesterField = $this->requiredConfigString('requester_field');
        if (($userFields[$requesterField]['MANDATORY'] ?? 'N') !== 'Y') {
            throw new \RuntimeException('HR_CRM_REQUESTER_MUST_BE_REQUIRED');
        }
        foreach (['TITLE', 'ASSIGNED_BY_ID', 'CATEGORY_ID', 'STAGE_ID', 'OPENED'] as $field) {
            if (!isset($factoryFields[$field])) {
                throw new \RuntimeException('HR_CRM_SYSTEM_FIELD_MISSING: ' . $field);
            }
        }
    }

    /** @return array{id: int, created: bool} */
    public function ensureVacancy(
        int $taskId,
        int $actor,
        int $responsible,
        array $canonicalValues,
        string $canonicalDescription
    ): array {
        $pool = Application::getInstance()->getConnectionPool();
        $wasMasterOnly = $pool->isMasterOnly();
        $pool->useMasterOnly(true);
        try {
            $this->assertTaskContext($taskId, $actor, $responsible, $canonicalValues);
            $this->preflight($actor, $responsible);
            $connection = Application::getConnection();
            $lockName = 'kelast_hr_vacancy_' . $taskId;
            if (!$connection->lock($lockName, 5)) {
                throw new \RuntimeException('HR_CRM_TASK_LOCK_BUSY');
            }
            try {
                $factory = $this->getFactory();
                $existingId = $this->findExistingId($factory, $taskId);
                if ($existingId !== null) {
                    $this->assertSavedItem($factory, $existingId, $responsible, $canonicalValues, false);
                    return ['id' => $existingId, 'created' => false];
                }
                $data = $this->buildItemData($taskId, $responsible, $canonicalValues, $canonicalDescription);
                $item = $factory->createItem();
                foreach ($data as $field => $value) {
                    $item->set($field, $value);
                }
                $context = (new Context())->setUserId($responsible);
                $result = $factory->getAddOperation($item, $context)->launch();
                if (!$result->isSuccess()) {
                    throw new \RuntimeException('HR_CRM_CREATE_FAILED: ' . implode(' | ', $result->getErrorMessages()));
                }
                $id = (int)$item->getId();
                if ($id <= 0 || $this->findExistingId($factory, $taskId) !== $id) {
                    throw new \RuntimeException('HR_CRM_CREATE_RESULT_UNVERIFIED');
                }
                $this->assertSavedItem($factory, $id, $responsible, $canonicalValues, true);
                return ['id' => $id, 'created' => true];
            } finally {
                $connection->unlock($lockName);
            }
        } finally {
            $pool->useMasterOnly($wasMasterOnly);
        }
    }

    private function assertSavedItem($factory, int $id, int $responsible, array $values, bool $justCreated): void
    {
        $saved = $factory->getItem($id);
        if ($saved === null
            || (int)$saved->getAssignedById() !== $responsible
            || (int)$saved->getCategoryId() !== $this->positiveConfigId('vacancy_category_id')
            || ($justCreated && $saved->getStageId() !== $this->requiredConfigString('vacancy_stage_id'))
            || $factory->getStageFromCategory($this->positiveConfigId('vacancy_category_id'), $saved->getStageId()) === null
            || (int)$saved->get($this->requiredConfigString('requester_field')) !== $values['hr_requester']
            || ($saved->get('OPENED') !== false && $saved->get('OPENED') !== 'N')) {
            throw new \RuntimeException('HR_CRM_SAVED_FIELDS_MISMATCH');
        }
    }

    private function getFactory()
    {
        if (!Loader::includeModule('crm')) {
            throw new \RuntimeException('HR_CRM_MODULE_UNAVAILABLE');
        }
        $factory = Container::getInstance()->getFactory($this->positiveConfigId('vacancy_entity_type_id'));
        if ($factory === null) {
            throw new \RuntimeException('HR_CRM_FACTORY_UNAVAILABLE');
        }
        return $factory;
    }

    private function assertTaskContext(int $taskId, int $actor, int $responsible, array $values): void
    {
        if ($taskId <= 0 || $actor <= 0 || $responsible <= 0 || !Loader::includeModule('tasks')) {
            throw new \RuntimeException('HR_CRM_INVALID_TASK_CONTEXT');
        }
        $task = \CTasks::GetList([], ['ID' => $taskId], [
            'ID', 'CREATED_BY', 'RESPONSIBLE_ID', 'UF_TASK_SERVICE', 'UF_TASK_DEPARTMENT',
        ])->Fetch();
        if (!$task || (int)$task['CREATED_BY'] !== $actor
            || (int)$task['RESPONSIBLE_ID'] !== $responsible
            || (int)$task['UF_TASK_SERVICE'] !== $this->positiveConfigId('service_id')
            || (int)($values['hr_initiator'] ?? 0) !== $actor
            || (int)$task['UF_TASK_DEPARTMENT'] !== (int)($values['hr_department_id'] ?? 0)) {
            throw new \RuntimeException('HR_CRM_TASK_CONTEXT_MISMATCH');
        }
    }

    private function findExistingId($factory, int $taskId): ?int
    {
        $items = $factory->getItems([
            'select' => ['ID'],
            'filter' => ['=' . $this->requiredConfigString('source_task_field') => $taskId],
            'order' => ['ID' => 'ASC'],
            'limit' => 2,
        ]);
        if (count($items) > 1) {
            throw new \RuntimeException('HR_CRM_DUPLICATE_SOURCE_TASK');
        }
        return $items ? (int)$items[0]->getId() : null;
    }

    private function buildItemData(int $taskId, int $responsible, array $values, string $description): array
    {
        $title = $values['hr_vacancy_title'] ?? null;
        $requester = $values['hr_requester'] ?? null;
        if (!is_string($title) || trim($title) === '' || !is_int($requester) || $requester <= 0) {
            throw new \RuntimeException('HR_CRM_CANONICAL_VALUES_INVALID');
        }
        $data = [
            'TITLE' => $title,
            'ASSIGNED_BY_ID' => $responsible,
            'CATEGORY_ID' => $this->positiveConfigId('vacancy_category_id'),
            'STAGE_ID' => $this->requiredConfigString('vacancy_stage_id'),
            'OPENED' => 'N',
        ];
        foreach ($this->getFieldMappings() as $mapping) {
            $name = $mapping['name'];
            if ($name === 'hr_source_task') {
                $value = $taskId;
            } elseif ($name === 'hr_request_text') {
                $value = $description;
            } else {
                if (!array_key_exists($name, $values) && $mapping['required']) {
                    throw new \RuntimeException('HR_CRM_CANONICAL_FIELD_MISSING: ' . $name);
                }
                $value = $values[$name] ?? '';
            }
            if (!$mapping['required'] && ($value === '' || $value === null)) {
                continue;
            }
            if (in_array($mapping['crm_type'], ['integer', 'employee'], true)) {
                if (!is_int($value) || $value <= 0) {
                    throw new \RuntimeException('HR_CRM_CANONICAL_FIELD_INVALID: ' . $name);
                }
            } elseif ($mapping['crm_type'] === 'date') {
                $date = is_string($value) ? \DateTimeImmutable::createFromFormat('!Y-m-d', $value) : false;
                if (!$date || $date->format('Y-m-d') !== $value) {
                    throw new \RuntimeException('HR_CRM_CANONICAL_FIELD_INVALID: ' . $name);
                }
                $value = new Date($value, 'Y-m-d');
            } elseif (!is_string($value)) {
                throw new \RuntimeException('HR_CRM_CANONICAL_FIELD_INVALID: ' . $name);
            }
            $data[$mapping['crm_field']] = $value;
        }
        return $data;
    }

    public function getFieldMappings(): array
    {
        $fixed = [
            ['hr_requester', 'requester_field', 'employee'],
            ['hr_source_task', 'source_task_field', 'integer'],
            ['hr_initiator', 'initiator_field', 'employee'],
            ['hr_request_date', 'request_date_field', 'date'],
            ['hr_department', 'department_field', 'string'],
            ['hr_department_id', 'department_id_field', 'integer'],
            ['hr_request_text', 'request_text_field', 'string'],
        ];
        $mappings = [];
        foreach ($fixed as [$name, $configKey, $type]) {
            $mappings[$name] = [
                'name' => $name,
                'crm_field' => $this->requiredConfigString($configKey),
                'crm_type' => $type,
                'required' => true,
            ];
        }
        foreach ($this->fields as $field) {
            if (empty($field['crm_field'])) {
                continue;
            }
            $name = $field['name'];
            $mapping = [
                'name' => $name,
                'crm_field' => $field['crm_field'],
                'crm_type' => $field['crm_type'] ?? 'string',
                'required' => !empty($field['required']),
            ];
            if (isset($mappings[$name]) && $mappings[$name] !== $mapping) {
                throw new \RuntimeException('HR_CRM_SCHEMA_MAPPING_CONFLICT: ' . $name);
            }
            $mappings[$name] = $mapping;
        }
        $targets = [];
        foreach ($mappings as $mapping) {
            if (!preg_match('/^UF_CRM_[A-Z0-9_]+$/D', $mapping['crm_field'])
                || isset($targets[$mapping['crm_field']])
                || !in_array($mapping['crm_type'], ['string', 'integer', 'employee', 'date'], true)) {
                throw new \RuntimeException('HR_CRM_SCHEMA_TARGET_INVALID');
            }
            $targets[$mapping['crm_field']] = true;
        }
        return array_values($mappings);
    }

    private function positiveConfigId(string $key): int
    {
        $value = $this->config[$key] ?? null;
        if (!is_int($value) || $value <= 0) {
            throw new \RuntimeException('HR_CRM_CONFIG_INVALID: ' . $key);
        }
        return $value;
    }

    private function requiredConfigString(string $key): string
    {
        $value = $this->config[$key] ?? null;
        if (!is_string($value) || $value === '') {
            throw new \RuntimeException('HR_CRM_CONFIG_INVALID: ' . $key);
        }
        return $value;
    }
}
