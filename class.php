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

        $department = $request->getPost("select-department");
        $priority = $request->getPost("select-priority");
        $service = $request->getPost("select-service");
        $deadline = $request->getPost("input-deadline");
        $fieldsForConcatTitle = explode(",", $request->getPost("fields-for-concat-title"));

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

        if (!empty($fieldsForConcatTitle)) {
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
