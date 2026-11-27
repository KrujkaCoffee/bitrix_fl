<?php

if(!defined("B_PROLOG_INCLUDED") || B_PROLOG_INCLUDED !== true) die();

use Kuratovru\Model\Service\Service;

use Bitrix\Main\Engine\ActionFilter\Authentication;
use Bitrix\Main\Error;
use Bitrix\Main\ErrorCollection;
use Kuratovru\Model\TaskBuilder\TaskServiceBuilder;

use Bitrix\Main\Page\Asset;

class ServicesMain extends \CBitrixComponent implements \Bitrix\Main\Engine\Contract\Controllerable, \Bitrix\Main\Errorable{

    private $departmentIdblockId = 55;

    protected $extraFieldsConfig = [];

    private $serviceRules = [];
    private $serviceRuleIds = [];
    private $serviceOutsourceIds = [];
    private $serviceRulesText = '';

    private const INTERNAL_CLAIM_SERVICE_IDS = [255167, 255216];
    private const INTERNAL_CLAIM_PRODUCTION_ORDER_FIELD = 'field_internal_claim_production_order';
    private const DELIVERY_LOCALITY_FIELD = 'field_delivery_locality';

    public function __construct($component = null){
        parent::__construct($component);

        $this->fillExtraFields();
        $this->fillServiceRules();
    }

    private function getProjectsForProjectPicker(){
        global $USER;

        \Bitrix\Main\Loader::includeModule("socialnetwork");

        $groupsResult = \CSocNetGroup::GetList(['ID' => 'DESC'], [
            'ACTIVE' => 'Y',
            'OPENED' => 'Y',
            'CHECK_PERMISSIONS' => $USER->getId(),
        ], false, false, ['ID', 'NAME']);

        $projects = [];

        while ($group = $groupsResult->fetch()):
            $projects[] = [
                'id' => $group['ID'],
                'title' => $group['NAME'],
                'entityId' => 'projectCustom',
                'tabs' => 'projectsCustom'
            ];
        endwhile;

        return $projects;
    }

    private function getElementsForIB($iblockId){
        global $USER;

        \Bitrix\Main\Loader::includeModule("iblock");

        $elements = [];

        $results = \CIBlockElement::GetList([], ['ACTIVE' => 'Y', 'IBLOCK_ID' => $iblockId], false, false, ['ID', 'NAME']);
        while($result = $results->fetch()):
            $elements[] = [
                'label' => $result['NAME'],
                'value' => $result['NAME'],
            ];
        endwhile;

        return $elements;
    }

    private function fillExtraFields(){
        $this->extraFieldsConfig = include 'config.php';


        $projects = [];
        $ib_elements = [];
        foreach ( $this->extraFieldsConfig['sections'] as &$section ):
            foreach ( $section['fields'] as &$field ):
                if ( $field['type'] === 'projectpicker' ):
                    if (!$projects):
                        $projects = $this->getProjectsForProjectPicker();
                    endif;
                    $field['values'] = $projects;
                endif;
                unset($projects);

                if ( $field['type'] === 'iblock_elements' ):
                    if (!$ib_elements):
                        $ib_elements = $this->getElementsForIB($field['iblock_id']);
                    endif;
                    $field['values'] = $ib_elements;
                endif;
                unset($ib_elements);
            endforeach;
            unset($field);
        endforeach;
        unset($section);

        foreach ( $this->extraFieldsConfig['elements'] as &$element ):
            foreach ( $element['fields'] as &$field ):
                if ( $field['type'] === 'projectpicker' ):
                    if (!$projects):
                        $projects = $this->getProjectsForProjectPicker();
                    endif;
                    $field['values'] = $projects;
                endif;
                unset($projects);

                if ( $field['type'] === 'iblock_elements' ):
                    if (!$ib_elements):
                        $ib_elements = $this->getElementsForIB($field['iblock_id']);
                    endif;
                    $field['values'] = $ib_elements;
                endif;
                unset($ib_elements);
            endforeach;
            unset($field);
        endforeach;
        unset($element);
    }

    /** Один список услуг и один текст для формы и серверной сборки задачи. */
    private function fillServiceRules()
    {
        $this->serviceRules = require __DIR__ . '/service_rules.php';
        $environment = (string)($_SERVER['APP_ENV'] ?? '');
        $settings = $this->serviceRules['environments'][$environment] ?? [];
        if (empty($settings['enabled'])) {
            return;
        }

        $ids = $settings['service_ids'] ?? [];
        if (!empty($settings['include_ved'])) {
            $ids = array_merge($ids, $settings['ved_service_ids'] ?? []);
        }
        $this->serviceRuleIds = array_values(array_unique(array_map('intval', $ids)));
        $this->serviceOutsourceIds = array_map('intval', $settings['outsource_service_ids'] ?? []);

        $parts = [(string)$this->serviceRules['task_heading']];
        foreach ($this->serviceRules['sections'] as $section) {
            $parts[] = trim((string)$section['text']);
        }
        $this->serviceRulesText = implode("\n\n", $parts);
    }

    private function serviceRuleApplies($serviceId): bool
    {
        return in_array((int)$serviceId, $this->serviceRuleIds, true);
    }

    private function getServiceFieldSchema($serviceId): array
    {
        $recruitment = $this->recruitmentForm();
        if ($recruitment->applies($serviceId)) {
            return $recruitment->clientSchema();
        }
        if (!$this->serviceRuleApplies($serviceId)) {
            return [];
        }
        $outsource = in_array((int)$serviceId, $this->serviceOutsourceIds, true);
        $fields = array_values(array_filter($this->serviceRules['fields'],
            static function ($field) use ($outsource) {
                return empty($field['outsource_only']) || $outsource;
            }));
        return ['version' => $this->serviceRules['version'], 'fields' => $fields];
    }

    /** Пустая строка и строка только из Unicode-пробелов считаются незаполненными. */
    private function normalizeServiceFieldValue($value)
    {
        if ($value === null) {
            return '';
        }
        if (!is_string($value) || !preg_match('//u', $value)) {
            return null;
        }
        $value = str_replace(["\r\n", "\r"], "\n", $value);
        return preg_replace('/^[\s\p{Z}\x{200B}\x{FEFF}]+|[\s\p{Z}\x{200B}\x{FEFF}]+$/u', '', $value);
    }

    /** Схема берётся с сервера: клиент передаёт только значения отдельных полей. */
    private function prepareServiceRequirementDescription($serviceId, $description, $request): array
    {
        $result = ['description' => $description, 'errors' => [], 'fieldErrors' => []];
        $schema = $this->getServiceFieldSchema($serviceId);
        if (!$schema) {
            return $result;
        }
        if ($request->getPost('service_fields_version') !== $schema['version']) {
            $result['errors'][] = 'Форма услуги обновлена. Сохраните введённые сведения и обновите страницу перед отправкой заявки.';
            return $result;
        }
        $description = $description ?? '';
        $extraDescription = $request->getPost('extra_fields_description') ?? '';
        if ($this->normalizeServiceFieldValue($description) === null
            || $this->normalizeServiceFieldValue($extraDescription) === null) {
            $result['errors'][] = 'Некорректное описание или дополнительные сведения заявки.';
            return $result;
        }

        $values = [];
        foreach ($schema['fields'] as $field) {
            $values[$field['name']] = $this->normalizeServiceFieldValue($request->getPost($field['name']));
        }
        $lines = [];
        foreach ($schema['fields'] as $field) {
            if (!empty($field['visible_if'])) {
                $condition = $field['visible_if'];
                if (($values[$condition['field']] ?? '') !== (string)$condition['equals']) {
                    continue;
                }
            }
            $name = $field['name'];
            $value = $values[$name];
            $message = '';
            if ($value === null) {
                $message = 'Некорректное значение поля «' . $field['label'] . '».';
            } elseif (!empty($field['required']) && $value === '') {
                $message = 'Заполните поле «' . $field['label'] . '».';
            } elseif ($value !== '' && $field['type'] === 'list'
                && !in_array($value, array_column($field['values'], 'value'), true)) {
                $message = 'Выберите допустимое значение поля «' . $field['label'] . '».';
            }
            if ($message !== '') {
                $result['fieldErrors'][$name] = $message;
            } elseif ($value !== '') {
                $lines[] = $field['label'] . ': ' . $value;
            }
        }
        if ($result['fieldErrors']) {
            $result['errors'] = array_values($result['fieldErrors']);
            return $result;
        }

        $parts = [];
        if (trim($description) !== '') {
            $parts[] = rtrim($description);
        }
        if (trim($extraDescription) !== '') {
            $parts[] = trim($extraDescription);
        }
        if ($lines) {
            $parts[] = "Сведения по заявке:\n" . implode("\n\n", $lines);
        }
        $result['description'] = implode("\n\n", $parts);
        return $result;
    }

    /** Обязательный номер производственного заказа для внутренних претензий. */
    private function prepareInternalClaimDescription($serviceId, $description, $request): array
    {
        $result = ['description' => $description, 'errors' => [], 'fieldErrors' => []];
        if (!in_array((int)$serviceId, self::INTERNAL_CLAIM_SERVICE_IDS, true)) {
            return $result;
        }

        $fieldName = self::INTERNAL_CLAIM_PRODUCTION_ORDER_FIELD;
        $value = $this->normalizeServiceFieldValue($request->getPost($fieldName));
        if ($value === null) {
            $message = 'Некорректное значение поля «Номер производственного заказа».';
            $result['errors'][] = $message;
            $result['fieldErrors'][$fieldName] = $message;
            return $result;
        }
        if ($value === '') {
            $message = 'Заполните поле «Номер производственного заказа».';
            $result['errors'][] = $message;
            $result['fieldErrors'][$fieldName] = $message;
            return $result;
        }

        $description = (string)($description ?? '');
        $line = 'Номер производственного заказа: ' . $value;
        if (strpos($description, $line) === false) {
            $description = rtrim($description);
            $description .= ($description !== '' ? "\n\n" : '') . $line;
        }
        $result['description'] = $description;
        return $result;
    }

    /** Населённый пункт обязателен только для услуг с этим полем в config.php. */
    private function prepareDeliveryTask($serviceId, $description, $request): array
    {
        $result = ['description' => $description, 'titleText' => '', 'errors' => [], 'fieldErrors' => []];
        $field = null;
        foreach ($this->extraFieldsConfig['elements'] as $element) {
            if (!in_array((int)$serviceId, array_map('intval', $element['values']), true)) {
                continue;
            }
            foreach ($element['fields'] as $candidate) {
                if ($candidate['name'] === self::DELIVERY_LOCALITY_FIELD) {
                    $field = $candidate;
                    break 2;
                }
            }
        }
        if ($field === null) {
            return $result;
        }

        $value = $this->normalizeServiceFieldValue($request->getPost($field['name']));
        if ($value === null || $value === '') {
            $message = ($value === null ? 'Некорректное значение поля «' : 'Заполните поле «')
                . $field['label'] . '».';
            $result['errors'][] = $message;
            $result['fieldErrors'][$field['name']] = $message;
            return $result;
        }
        // Название задачи должно оставаться однострочным, в том числе после вставки текста.
        $value = preg_replace('/[\s\p{Z}\x{200B}\x{FEFF}]+/u', ' ', $value);

        $description = $description ?? '';
        if ($this->normalizeServiceFieldValue($description) === null) {
            $result['errors'][] = 'Некорректное описание заявки.';
            return $result;
        }
        $line = $field['label'] . ': ' . $value;
        if (strpos($description, $line) === false) {
            $description = rtrim($description);
            $description .= ($description !== '' ? "\n\n" : '') . $line;
        }
        $result['description'] = $description;
        $result['titleText'] = $value;
        return $result;
    }

    /** Выполняется до загрузки файлов и создания задачи. */
    private function appendServiceRules($serviceId, $description)
    {
        if (!$this->serviceRuleApplies($serviceId)) {
            return $description;
        }
        if ($description === null) {
            $description = '';
        }
        if (!is_string($description)) {
            throw new \RuntimeException('Некорректное описание заявки.');
        }
        if (strpos($description, $this->serviceRulesText) === false) {
            $description = rtrim($description) . "\n\n" . $this->serviceRulesText;
        }
        return $description;
    }

    private function getResponsibleDepartments( int $parentId = 0 ) : array {
        $departments = [];

        $results = Service::getChildSections($parentId);

        foreach ($results as $result):
            $departments[] = [
                'label' => $result['NAME'],
                'value' => $result['ID']
            ];
        endforeach;

        return $departments;
    }

    protected function parseFiles($files){
        $data = [];

        for ( $i = 0; $i < count($files['name']); $i++ ):
            $data[] = [
                'error' => $files['error'][$i],
                'name' => $files['name'][$i],
                'size' => $files['size'][$i],
                'tmp_name' => $files['tmp_name'][$i],
                'type' => $files['type'][$i],
            ];
        endfor;

        return $data;
    }

    public function getPrioritiesList() : string{
        $priorities = [
            [
                'label' => 'Низкий приоритет',
                'value' => '95',
            ],
            [
                'label' => 'Важно',
                'value' => '96',
            ],
            [
                'label' => 'Критично',
                'value' => '97',
            ]
        ];
        return \CUtil::PhpToJSObject($priorities);
    }

    public function getDepartmentsList() : string{
        $departments = [];

        $results = \Kuratovru\Helpers\Helper::getDataFromIblock([
            'NAME' => 'ASC'
        ], [
            'IBLOCK_ID' => $this->departmentIdblockId,
            'ACTIVE' => 'Y'
        ], [
            'ID',
            'NAME'
        ]);

        foreach ($results as $result):
            $departments[] = [
                'label' => $result['NAME'],
                'value' => $result['ID']
            ];
        endforeach;

        return \CUtil::PhpToJSObject($departments);
    }

    public function executeComponent(){

        \Bitrix\Main\UI\Extension::load('ui.entity-selector');

        Asset::getInstance()->addCss("/local/components/kuratovru/services.main/service_rules.css?v=20260915-3");
        Asset::getInstance()->addCss("/local/templates/.default/assets/css/flatpickr.min.css");
        Asset::getInstance()->addJs("/local/templates/.default/assets/js/flatpickr.min.js");
        Asset::getInstance()->addJs("/local/templates/.default/assets/js/l10n/ru.js");


        $this->arResult['priorities'] = $this->getPrioritiesList();
        $this->arResult['departments'] = $this->getDepartmentsList();
        $this->arResult['responsible-departments'] = $this->getResponsibleDepartments();
        $this->arResult['extraFieldsConfig'] = \CUtil::PhpToJSObject($this->extraFieldsConfig);

        //\Bitrix\Iblock\Elements\ElementKelastServiceTable::getEntity()->cleanCache();

        $this->includeComponentTemplate();
    }

    public function sendAction() : array{
        $request = Bitrix\Main\Application::getInstance()->getContext()->getRequest();

        $recruitment = $this->recruitmentForm();
        if ($recruitment->applies($request->getPost('select-service'))) {
            return $this->sendRecruitment($request, $recruitment);
        }
        // A stale pilot page must never fall through and create an ordinary duplicate task.
        if ($request->getPost('recruitment_request_key') !== null) {
            return ['validationErrors' => ['Пилот подбора сейчас недоступен для этой формы. Проверьте результат ранее отправленной заявки.'], 'fieldErrors' => []];
        }

        $department = $request->getPost("select-department");
        $priority = $request->getPost("select-priority");
        $service = $request->getPost("select-service");
        $deadline = $request->getPost("input-deadline");
        $description = $request->getPost("description");

        $customProject = $request->getPost("projectpickerField") ? $request->getPost($request->getPost("projectpickerField")) : false;

        if ( $service ==  240637){
            $department = '-';
            $priority = '-';
            $deadline = (new DateTime())->setTimestamp(time() + 3600);
        }else{
            if ( !$department || !$priority || !$service || !$deadline )
                throw new \RuntimeException();

            $deadline = new DateTime($deadline);
        }

        $requirements = $this->prepareServiceRequirementDescription($service, $description, $request);
        if ($requirements['errors']) {
            return [
                'validationErrors' => $requirements['errors'],
                'fieldErrors' => $requirements['fieldErrors'],
            ];
        }
        $description = $requirements['description'];

        $internalClaim = $this->prepareInternalClaimDescription($service, $description, $request);
        if ($internalClaim['errors']) {
            return [
                'validationErrors' => $internalClaim['errors'],
                'fieldErrors' => $internalClaim['fieldErrors'],
            ];
        }
        $description = $internalClaim['description'];

        $delivery = $this->prepareDeliveryTask($service, $description, $request);
        if ($delivery['errors']) {
            return [
                'validationErrors' => $delivery['errors'],
                'fieldErrors' => $delivery['fieldErrors'],
            ];
        }
        $description = $delivery['description'];
        $description = $this->appendServiceRules($service, $description);

        $fileField = $this->parseFiles($request->getFile('task_files'));

        if ($fileField):

            \Bitrix\Main\Loader::includeModule('disk');

            $driver = \Bitrix\Disk\Driver::getInstance();
            $storage = $driver->getStorageByCommonId('shared_files_s1');

            $folder = $storage->getChild(
                array(
                    '=NAME' => 'Файлы тикетов техподдержки',
                    'TYPE' => \Bitrix\Disk\Internals\FolderTable::TYPE_FOLDER
                )
            );

            $filesArr = [];
            foreach ( $fileField as $fileRow ):

                if ( !empty($fileRow['tmp_name']) ):
                    if ( $fileRow['error'] !== 0):
                        throw new \RuntimeException("Не удалось загрузить файл \"{$fileRow['name']}\"! Попробуйте изменить формат файла!");
                    endif;

                    if ( $fileRow['size'] > 10000000 ):
                        throw new \RuntimeException("Файл \"{$fileRow['name']}\" превышает лимит 10мб! Попробуйте изменить размер файла!");
                    endif;

                    $file = $folder->uploadFile($fileRow, [
                        'CREATED_BY' => \Bitrix\Main\Engine\CurrentUser::get()->getId(),
                        'NAME' => pathinfo($fileRow['name'], PATHINFO_FILENAME) . ' ' . uniqid() . '.' . pathinfo($fileRow['name'], PATHINFO_EXTENSION)
                    ]);

                    $filesArr[] = $file->getId();
                endif;
            endforeach;
        endif;

        $userId = \Bitrix\Main\Engine\CurrentUser::get()->getId();

        $builder = new TaskServiceBuilder($service);

        $departmentService = Service::getById($service)['IBLOCK_SECTION_ID'];

        $builder
            ->setDepartment($department)
            ->setPriority($priority)
            ->setDeadLine($deadline)
            ->setDepartmentService($departmentService)
            ->setDescription($description)
            ->setCreatedBy($userId);

        if ($service == 255167 || $service == 255216):
            $builder->setResponsible($userId);
        endif;

        foreach ( $filesArr as $fileId ):
            $builder->addFile($fileId);
        endforeach;

        if ($service == 244002):
            $builder->setWorkGroup(124);
        endif;

        if ( $customProject ):
            $builder->setWorkGroup($customProject);
        endif;

        if ($delivery['titleText'] !== '') {
            // Обязательный суффикс берётся из проверенного поля, а не из списка клиента.
            $builder = $this->taskTitleConcat($builder, [$delivery['titleText']]);
        } else {
            $fieldsForConcatTitle = $request->getPost('fields-for-concat-title');
            $fieldsForConcatTitle = is_string($fieldsForConcatTitle) ? explode(',', $fieldsForConcatTitle) : [];
            $texts = [];

            foreach ($fieldsForConcatTitle as $field) {
                $text = $request->getPost($field);

                if (!empty($text)) {
                    $texts[] = $text;
                }
            }

            $builder = $this->taskTitleConcat($builder, $texts);
        }

        $taskId = $builder->build()->getTaskId();

        return [
            'userId' => $userId,
            'taskId' => $taskId
        ];
    }

    private function recruitmentForm(): \Kelast\Recruitment\Form
    {
        require_once __DIR__ . '/recruitment/bootstrap.php';
        return new \Kelast\Recruitment\Form(require __DIR__ . '/recruitment/config.php');
    }

    /** Pilot is scoped to one service and an explicit list of authenticated users. */
    private function sendRecruitment($request, \Kelast\Recruitment\Form $form): array
    {
        $actor = (int)\Bitrix\Main\Engine\CurrentUser::get()->getId();
        $config = require __DIR__ . '/recruitment/config.php';
        $prepared = $form->prepare($request, $actor);
        if ($prepared['errors']) {
            return ['validationErrors' => $prepared['errors'], 'fieldErrors' => $prepared['fieldErrors']];
        }
        $priority = $request->getPost('select-priority');
        $deadlineText = $request->getPost('input-deadline');
        $deadline = is_string($deadlineText) ? \DateTime::createFromFormat('!d.m.Y H:i', $deadlineText) : false;
        if (!in_array($priority, ['95', '96', '97'], true) || !$deadline
            || $deadline->format('d.m.Y H:i') !== $deadlineText) {
            return ['validationErrors' => ['Проверьте приоритет и крайний срок задачи.'], 'fieldErrors' => []];
        }
        $token = $request->getPost('recruitment_request_key');
        if (!is_string($token) || !preg_match('/^[a-f0-9-]{32,64}$/Di', $token)) {
            return ['validationErrors' => ['Не удалось определить ключ заявки. Сохраните сведения и обновите форму.'], 'fieldErrors' => []];
        }
        $serviceId = $config['service_id'];
        $service = \CIBlockElement::GetList([], ['ID' => $serviceId,
            'IBLOCK_ID' => $config['service_iblock_id'], 'ACTIVE' => 'Y'],
            false, ['nTopCount' => 1], ['ID', 'IBLOCK_SECTION_ID'])->Fetch();
        if (!$service || $actor <= 0) {
            throw new \RuntimeException('Услуга подбора недоступна.');
        }

        $files = $this->recruitmentFiles($request->getFile('task_files'));
        $fileHashes = [];
        foreach ($files as $file) {
            $fileHashes[] = ['name' => $file['name'], 'size' => $file['size'], 'sha256' => hash_file('sha256', $file['tmp_name'])];
        }
        $fingerprintValues = $prepared['values'];
        unset($fingerprintValues['hr_request_date'], $fingerprintValues['hr_department']);
        $fingerprint = hash('sha256', json_encode([$fingerprintValues, $prepared['inputDescription'],
            $priority, $deadlineText, $fileHashes], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
        $builder = new TaskServiceBuilder($serviceId);
        $responsible = (int)$builder->getResponsible();
        $builder->setDepartment($prepared['values']['hr_department_id'])
            ->setPriority($priority)->setDeadLine($deadline)
            ->setDepartmentService($service['IBLOCK_SECTION_ID'])
            ->setDescription($prepared['description'])->setCreatedBy($actor)
            // Recruitment details must not inherit the builder's shared IT workgroup 20.
            ->setWorkGroup(0);
        $builder->setTitle(mb_substr($builder->getTitle() . '_' . $prepared['values']['hr_vacancy_title'], 0, 255, 'UTF-8'));
        $workflow = new \Kelast\Recruitment\Workflow(new \Kelast\Recruitment\RequestStore(),
            new \Kelast\Recruitment\CrmGateway($config));
        return $workflow->submit($token, $actor, $responsible, $prepared['values'],
            $prepared['description'], $fingerprint, function () use ($builder, $files, $actor) {
                // Upload only while holding the request lock, into the submitter's personal Disk.
                $uploadedFiles = [];
                try {
                    if ($files) {
                        if (!\Bitrix\Main\Loader::includeModule('disk')) {
                            throw new \RuntimeException('Не удалось подключить Диск.');
                        }
                        $storage = \Bitrix\Disk\Driver::getInstance()->getStorageByUserId($actor);
                        $folder = $storage ? $storage->getRootObject() : null;
                        if (!$folder || !$folder->canAdd($storage->getSecurityContext($actor))) {
                            throw new \RuntimeException('Нет доступа к личному Диску для вложений заявки.');
                        }
                        foreach ($files as $file) {
                            $uploaded = $folder->uploadFile($file, ['CREATED_BY' => $actor,
                                'NAME' => 'Заявка на подбор ' . bin2hex(random_bytes(6)) . ' ' . basename($file['name'])]);
                            if (!$uploaded) {
                                throw new \RuntimeException('Не удалось сохранить вложение заявки.');
                            }
                            $uploadedFiles[] = $uploaded;
                            $builder->addFile($uploaded->getId());
                        }
                    }
                } catch (\Throwable $error) {
                    foreach ($uploadedFiles as $uploaded) {
                        try {
                            $uploaded->delete($actor);
                        } catch (\Throwable $cleanupError) {
                            // Only newly uploaded objects are touched. Never delete task/user files.
                        }
                    }
                    throw new \Kelast\Recruitment\BeforeTaskCreationException(
                        'Не удалось подготовить вложения на личном Диске. Задача не создавалась. Проверьте доступ к Диску и повторите отправку.');
                }
                try {
                    $builder->build();
                } catch (\Throwable $error) {
                    if ((int)$builder->getTaskId() <= 0) {
                        throw $error;
                    }
                    // The task may exist even if updating service rotation/checklists failed.
                }
                return (int)$builder->getTaskId();
            });
    }

    public function recruitmentStatusAction(): array
    {
        $request = \Bitrix\Main\Application::getInstance()->getContext()->getRequest();
        $form = $this->recruitmentForm();
        $token = $request->getPost('recruitment_request_key');
        if (!$form->applies($request->getPost('select-service')) || !is_string($token)) {
            throw new \RuntimeException('Проверка заявки недоступна.');
        }
        $config = require __DIR__ . '/recruitment/config.php';
        $workflow = new \Kelast\Recruitment\Workflow(new \Kelast\Recruitment\RequestStore(),
            new \Kelast\Recruitment\CrmGateway($config));
        return $workflow->status($token, (int)\Bitrix\Main\Engine\CurrentUser::get()->getId());
    }

    private function recruitmentFiles($uploaded): array
    {
        if ($uploaded === null || $uploaded === false) {
            return [];
        }
        if (!is_array($uploaded) || !isset($uploaded['name']) || !is_array($uploaded['name'])
            || count($uploaded['name']) > 20) {
            throw new \RuntimeException('Некорректный набор вложений заявки.');
        }
        $files = [];
        foreach ($uploaded['name'] as $index => $name) {
            $error = $uploaded['error'][$index] ?? UPLOAD_ERR_NO_FILE;
            if ($error === UPLOAD_ERR_NO_FILE) {
                continue;
            }
            $tmp = $uploaded['tmp_name'][$index] ?? null;
            if ($error !== UPLOAD_ERR_OK || !is_string($name) || !is_string($tmp)
                || !is_uploaded_file($tmp) || filesize($tmp) > 10000000) {
                throw new \RuntimeException('Не удалось принять вложение. Допустимый размер файла — до 10 МБ.');
            }
            $files[] = ['name' => $name, 'tmp_name' => $tmp, 'error' => UPLOAD_ERR_OK,
                'size' => filesize($tmp), 'type' => 'application/octet-stream'];
        }
        return $files;
    }

    public function selectAction( int $department = 0 ) : array{

        if ( empty($department) )
            throw new \RuntimeException();

        $result = [];

        $sections = Service::getChildSections($department);

        if ( $sections ):
            $result['type'] = 'department';
            $result['depth'] = $sections[0]['DEPTH_LEVEL'];
            foreach ( $sections as $section ):
                $result['departments'][] = [
                    'label' => $section['NAME'],
                    'value' => $section['ID']
                ];
            endforeach;
        else:
            $result['type'] = 'service';
            $result['services'] = [];
            $result['serviceRules'] = [];

            $services = Service::getBySectionId($department);
            foreach ($services as $service):
                $hasServiceRules = $this->serviceRuleApplies($service['ID']);
                if ($hasServiceRules) {
                    $result['serviceRules'] = $this->serviceRules['sections'];
                }
                $result['services'][] = [
                    'label' => $service['NAME'],
                    'value' => $service['ID'],
                    'description' => $service['PREVIEW_TEXT'],
                    'hasServiceRules' => $hasServiceRules,
                    'fieldSchema' => $this->getServiceFieldSchema($service['ID']),
                    'leadTime' => FL\Util\Util::getSecondFromServiceFormat($service['LEAD_TIME']),
                    'timeAt' => $service['TIME_AT'],
                    'timeTo' => $service['TIME_TO'],
                    'deadline' => Service::buildDeadline($service['ID'])->getTimestamp(),
                    'startText' => $service['START_TEXT']
                ];
            endforeach;

        endif;

        return $result;
    }

    public function onPrepareComponentParams($arParams){
        $this->errorCollection = new ErrorCollection();
        return $arParams;
    }

    public function configureActions(){
        return [
            'send' => [
                '-prefilters' => [
                    Authentication::class,
                ],
            ],
        ];
    }

    private function taskTitleConcat(TaskServiceBuilder $task, array $texts): TaskServiceBuilder {
        if (empty($texts)) return $task;

        $title = $task->getTitle();

        foreach ($texts as $text) {
            $title .= '_' . $text;
        }

        $task->setTitle($title);

        return $task;
    }

    /**
     * Getting array of errors.
     * @return Error[]
     */
    public function getErrors()
    {
        return $this->errorCollection->toArray();
    }

    /**
     * Getting once error with the necessary code.
     * @param string $code Code of error.
     * @return Error
     */
    public function getErrorByCode($code)
    {
        return $this->errorCollection->getErrorByCode($code);
    }
}
