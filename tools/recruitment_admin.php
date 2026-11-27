<?php

/** Run only by require from the authenticated Bitrix admin PHP console. */
(static function () {
    global $USER, $hrRecruitmentMode, $hrRecruitmentRequestKey, $hrRecruitmentTaskId;
    if (!defined('B_PROLOG_INCLUDED') || B_PROLOG_INCLUDED !== true
        || !is_object($USER) || !$USER->IsAdmin()) {
        http_response_code(403);
        return;
    }
    $mode = strtoupper((string)($hrRecruitmentMode ?? 'PLAN'));
    $result = ['SCRIPT' => 'HR_RECRUITMENT_SETUP_V1', 'MODE' => $mode,
        'STATUS' => 'FAILED', 'WRITES' => [], 'ERRORS' => []];
    try {
        require_once dirname(__DIR__) . '/recruitment/bootstrap.php';
        $config = require dirname(__DIR__) . '/recruitment/config.php';
        $store = new \Kelast\Recruitment\RequestStore();
        $gateway = new \Kelast\Recruitment\CrmGateway($config);
        $module = $config['option_module'];
        $result['ENABLED'] = \Bitrix\Main\Config\Option::get($module, 'enabled', 'N');
        if ($mode === 'DISABLE') {
            \Bitrix\Main\Config\Option::set($module, 'enabled', 'N');
            $result['WRITES'][] = 'PILOT_DISABLED';
            $result['STATUS'] = 'COMPLETE';
        } elseif ($mode === 'STATUS') {
            $result['JOURNAL_EXISTS'] = $store->ready();
            if ($store->ready()) {
                // Operational metadata only. Do not print candidate/requisition text or attachments.
                $result['REQUESTS'] = \Bitrix\Main\Application::getConnection()->query(
                    'SELECT REQUEST_KEY, ACTOR_ID, STATE, TASK_ID, VACANCY_ID, ERROR_CODE, UPDATED_AT FROM '
                    . \Kelast\Recruitment\RequestStore::TABLE . ' ORDER BY UPDATED_AT DESC LIMIT 30')->fetchAll();
            }
            $result['STATUS'] = 'COMPLETE';
        } elseif ($mode === 'RETRY') {
            $workflow = new \Kelast\Recruitment\Workflow($store, $gateway);
            $result['RESULT'] = $workflow->retryVacancy((string)$hrRecruitmentRequestKey);
            $result['STATUS'] = $result['RESULT']['recruitmentStatus'];
        } elseif ($mode === 'RECONCILE') {
            // Explicitly bind an already-existing task after an ambiguous create. Never create one here.
            $key = (string)$hrRecruitmentRequestKey;
            $taskId = (int)$hrRecruitmentTaskId;
            if (!preg_match('/^[a-f0-9]{64}$/D', $key) || $taskId <= 0) {
                throw new \RuntimeException('RECONCILE_REQUIRES_KEY_AND_EXISTING_TASK_ID');
            }
            $store->withLock($key, static function () use ($store, $key, $taskId, $config) {
                $row = $store->get($key);
                if (!$row || (int)$row['TASK_ID'] > 0
                    || !in_array($row['STATE'], ['TASK_CREATING', 'TASK_UNCERTAIN'], true)) {
                    throw new \RuntimeException('RECONCILE_STATE_NOT_AMBIGUOUS');
                }
                \Bitrix\Main\Loader::includeModule('tasks');
                $payload = json_decode($row['PAYLOAD_JSON'], true, 512, JSON_THROW_ON_ERROR);
                $task = \CTasks::GetList([], ['ID' => $taskId], ['ID', 'CREATED_BY', 'RESPONSIBLE_ID',
                    'UF_TASK_SERVICE', 'UF_TASK_DEPARTMENT', 'DESCRIPTION'])->Fetch();
                if (!$task || (int)$task['CREATED_BY'] !== (int)$row['ACTOR_ID']
                    || (int)$task['RESPONSIBLE_ID'] !== (int)$row['RESPONSIBLE_ID']
                    || (int)$task['UF_TASK_SERVICE'] !== $config['service_id']
                    || (int)$task['UF_TASK_DEPARTMENT'] !== (int)$payload['values']['hr_department_id']
                    || $task['DESCRIPTION'] !== $payload['description']) {
                    throw new \RuntimeException('RECONCILE_TASK_DOES_NOT_MATCH_IMMUTABLE_REQUEST');
                }
                $other = \Bitrix\Main\Application::getConnection()->query('SELECT REQUEST_KEY FROM '
                    . \Kelast\Recruitment\RequestStore::TABLE . ' WHERE TASK_ID = ' . $taskId)->fetch();
                if ($other) {
                    throw new \RuntimeException('RECONCILE_TASK_ALREADY_BOUND');
                }
                $store->update($key, ['TASK_ID' => $taskId, 'STATE' => 'TASK_CREATED', 'ERROR_CODE' => '']);
            });
            $result['WRITES'][] = 'EXISTING_TASK_BOUND';
            $result['STATUS'] = 'COMPLETE';
        } elseif (in_array($mode, ['PLAN', 'APPLY', 'ENABLE'], true)) {
            if (!\Bitrix\Main\Loader::includeModule('crm') || !\Bitrix\Main\Loader::includeModule('iblock')) {
                throw new \RuntimeException('CRM_OR_IBLOCK_UNAVAILABLE');
            }
            $factory = \Bitrix\Crm\Service\Container::getInstance()->getFactory($config['vacancy_entity_type_id']);
            if (!$factory || !$factory->getCategory($config['vacancy_category_id'])
                || !$factory->getStageFromCategory($config['vacancy_category_id'], $config['vacancy_stage_id'])) {
                throw new \RuntimeException('CONFIGURED_VACANCY_CATEGORY_OR_STAGE_NOT_FOUND');
            }
            if (!extension_loaded('mbstring')) {
                throw new \RuntimeException('PHP_MBSTRING_REQUIRED');
            }
            $service = \CIBlockElement::GetList([], ['ID' => $config['service_id'],
                'IBLOCK_ID' => $config['service_iblock_id'], 'ACTIVE' => 'Y'], false, false,
                ['ID', 'PROPERTY_EMPLOYEE'])->Fetch();
            if (!$service) {
                throw new \RuntimeException('HR_SERVICE_NOT_FOUND');
            }
            $entityId = $factory->getUserFieldEntityId();
            $existing = [];
            $query = \CUserTypeEntity::GetList([], ['ENTITY_ID' => $entityId]);
            while ($field = $query->Fetch()) {
                $existing[$field['FIELD_NAME']] = $field;
            }
            $schema = require dirname(__DIR__) . '/recruitment/schema.php';
            $labels = [];
            $types = [];
            foreach ($schema['fields'] as $field) {
                $labels[$field['name']] = $field['label'];
                $types[$field['name']] = $field['type'];
            }
            $labels += ['hr_source_task' => 'Исходная задача на подбор', 'hr_initiator' => 'Инициатор заявки',
                'hr_request_date' => 'Дата заявки на подбор', 'hr_department' => 'Подразделение по заявке',
                'hr_department_id' => 'ID подразделения по заявке', 'hr_request_text' => 'Исходная заявка на подбор'];
            $missing = [];
            foreach ($gateway->getFieldMappings() as $mapping) {
                $name = $mapping['crm_field'];
                $found = $existing[$name] ?? null;
                if ($found && ($found['USER_TYPE_ID'] !== $mapping['crm_type'] || $found['MULTIPLE'] !== 'N')) {
                    throw new \RuntimeException('EXISTING_FIELD_TYPE_MISMATCH: ' . $name);
                }
                if ($name === $config['requester_field']) {
                    if (!$found || $found['MANDATORY'] !== 'Y') {
                        throw new \RuntimeException('EXISTING_REQUIRED_REQUESTER_FIELD_MISSING');
                    }
                    continue;
                }
                if (!$found) {
                    $missing[] = ['ENTITY_ID' => $entityId, 'FIELD_NAME' => $name,
                        'USER_TYPE_ID' => $mapping['crm_type'], 'MULTIPLE' => 'N', 'MANDATORY' => 'N',
                        'SORT' => 500 + count($missing) * 10, 'SHOW_FILTER' => 'I', 'SHOW_IN_LIST' => 'Y',
                        'EDIT_IN_LIST' => 'N', 'IS_SEARCHABLE' => 'N',
                        'EDIT_FORM_LABEL' => ['ru' => $labels[$mapping['name']] ?? $mapping['name']],
                        'LIST_COLUMN_LABEL' => ['ru' => $labels[$mapping['name']] ?? $mapping['name']],
                        'LIST_FILTER_LABEL' => ['ru' => $labels[$mapping['name']] ?? $mapping['name']],
                        'SETTINGS' => $mapping['crm_type'] === 'string'
                            ? ['ROWS' => (($types[$mapping['name']] ?? '') === 'textarea' || $mapping['name'] === 'hr_request_text') ? 6 : 1]
                            : []];
                }
            }
            $result['VACANCY'] = ['ENTITY_TYPE_ID' => $config['vacancy_entity_type_id'],
                'CATEGORY_ID' => $config['vacancy_category_id'], 'STAGE_ID' => $config['vacancy_stage_id']];
            $result['ADD_FIELDS'] = array_map(static function ($f) {
                return ['NAME' => $f['FIELD_NAME'], 'TYPE' => $f['USER_TYPE_ID'], 'LABEL' => $f['EDIT_FORM_LABEL']['ru']];
            }, $missing);
            $result['CREATE_JOURNAL'] = !$store->ready();
            $result['PILOT_USER_IDS'] = $config['pilot_user_ids'];
            if ($mode === 'APPLY') {
                $store->withLock('setup_v1', static function () use ($store, $missing, &$result) {
                    if (!$store->ready()) {
                        \Bitrix\Main\Application::getConnection()->queryExecute(\Kelast\Recruitment\RequestStore::createSql());
                        $result['WRITES'][] = 'JOURNAL_CREATED';
                    }
                    foreach ($missing as $field) {
                        // Recheck inside setup lock: another admin may have applied this plan.
                        $found = \CUserTypeEntity::GetList([], ['ENTITY_ID' => $field['ENTITY_ID'], 'FIELD_NAME' => $field['FIELD_NAME']])->Fetch();
                        if ($found) {
                            if ($found['USER_TYPE_ID'] !== $field['USER_TYPE_ID'] || $found['MULTIPLE'] !== 'N') {
                                throw new \RuntimeException('FIELD_CHANGED_DURING_SETUP: ' . $field['FIELD_NAME']);
                            }
                            continue;
                        }
                        $manager = new \CUserTypeEntity();
                        if (!$manager->Add($field)) {
                            throw new \RuntimeException('FIELD_CREATE_FAILED: ' . $field['FIELD_NAME']);
                        }
                        $result['WRITES'][] = 'FIELD_CREATED: ' . $field['FIELD_NAME'];
                    }
                });
            }
            if ($mode === 'ENABLE') {
                if ($missing || !$store->ready()) {
                    throw new \RuntimeException('RUN_APPLY_BEFORE_ENABLE');
                }
                // Builder is read only here; build() must never run from setup.
                $builder = new \Kuratovru\Model\TaskBuilder\TaskServiceBuilder($config['service_id']);
                $gateway->preflight((int)$USER->GetID(), (int)$builder->getResponsible());
                \Bitrix\Main\Config\Option::set($module, 'enabled', 'Y');
                $result['WRITES'][] = 'PILOT_ENABLED';
            }
            $result['STATUS'] = 'COMPLETE';
        } else {
            throw new \RuntimeException('UNKNOWN_MODE');
        }
    } catch (\Throwable $error) {
        $result['ERRORS'][] = $error->getMessage();
    }
    echo '<pre>' . htmlspecialchars(json_encode($result,
        JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE),
        ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</pre>';
})();
