// node tests/recruitment_ui_test.js
// Exercise the component script with a small DOM and Bitrix selector stub.
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const {webcrypto} = require('node:crypto');

class Element {
    constructor(tag) {
        this.tagName = tag.toUpperCase();
        this.children = [];
        this.events = {};
        this.attributes = {};
        this.dataset = {};
        this.value = '';
        this.disabled = false;
        this.classList = {add() {}, toggle() {}, contains() { return false; }};
    }
    set textContent(value) { this.text = value; this.children = []; }
    get textContent() { return this.text || ''; }
    append(...nodes) { nodes.forEach(node => this.appendChild(node)); }
    appendChild(node) { this.children.push(node); node.parent = this; return node; }
    insertAdjacentElement(position, node) {
        if (position === 'afterbegin' || position === 'beforeend') {
            this.appendChild(node);
            return;
        }
        const index = this.parent.children.indexOf(this) + (position === 'afterend' ? 1 : 0);
        this.parent.children.splice(index, 0, node);
        node.parent = this.parent;
    }
    setAttribute(name, value) { this.attributes[name] = value; }
    removeAttribute(name) { delete this.attributes[name]; }
    setCustomValidity(message) { this.validationMessage = message; }
    addEventListener(name, callback) { this.events[name] = callback; }
    dispatchEvent(event) { return this.events[event.type]?.(event); }
    focus() { this.focused = true; }
    querySelectorAll(selector) {
        const nodes = this.children.flatMap(child => [child, ...child.querySelectorAll('*')]);
        if (selector === '*') return nodes;
        if (selector === '[name]') return nodes.filter(node => node.name);
        if (selector === '[type="submit"]') return nodes.filter(node => node.type === 'submit');
        if (selector.startsWith('#')) return nodes.filter(node => node.id === selector.slice(1));
        if (/^[a-z]+$/.test(selector)) return nodes.filter(node => node.tagName === selector.toUpperCase());
        return [];
    }
    querySelector(selector) { return this.querySelectorAll(selector)[0] || null; }
}

const source = fs.readFileSync(__dirname + '/../templates/.default/script.js', 'utf8')
    .replace("        serviceBlock = buildDiv('container-grid');", "        globalThis.createTestField = createField;\n        serviceBlock = buildDiv('container-grid');")
    .replace(/}\s*$/, `
    globalThis.ui = {
        selectServiceForm, validateRequirementFields, getRecruitmentRequest, showServerValidation,
        createServiceBlock, formHandler,
        get controls() { return requirementControls; },
        get stateBlock() { return recruitmentStateBlock; },
        setup(form) {
            descriptionField = form.querySelector('#description');
            responsibleSelects = [form.querySelector('#responsible')];
        },
    };
}`);

function harness(storage = new Map()) {
    const form = new Element('form');
    form.id = 'service-form';
    for (const [id, type, value] of [
        ['responsible', 'select', '1'], ['description', 'textarea', 'Описание вакансии'],
        ['input-deadline', 'input', '30.11.2026 12:00'], ['submit', 'input', 'Отправить'],
        ['task-files', 'input', 'old-attachment.pdf'], ['task-files-counter', 'span', ''],
    ]) {
        const input = new Element(type);
        input.id = id;
        input.name = id;
        input.value = value;
        if (id === 'submit') input.type = 'submit';
        if (id === 'task-files') input.type = 'file';
        if (id === 'task-files-counter') input.textContent = '[1]';
        form.append(input);
    }
    const dialogs = [];
    const alerts = [];
    const requests = [];
    const actions = [];
    const replies = [];
    const context = {
        console, Uint8Array, Map, Event: class { constructor(type) { this.type = type; } },
        crypto: {getRandomValues: bytes => webcrypto.getRandomValues(bytes)},
        servicesComponentName: 'kuratovru:services.main',
        departments: [], priorities: [], extraFieldsConfig: {sections: [], elements: []},
        alert: message => alerts.push(message), location: {href: ''},
        sessionStorage: {
            getItem: key => storage.get(key) || null,
            setItem: (key, value) => storage.set(key, value),
        },
        document: {
            createElement: tag => new Element(tag),
            addEventListener() {},
            querySelector: selector => selector === '#service-form' ? form : form.querySelector(selector),
        },
        FormData: class {
            constructor(element) {
                this.values = new Map((element ? element.querySelectorAll('[name]') : [])
                    .filter(input => !input.disabled).map(input => [input.name, String(input.value)]));
            }
            set(name, value) { this.values.set(name, String(value)); }
            get(name) { return this.values.get(name); }
        },
        BX: {
            UI: {EntitySelector: {Dialog: class {
                constructor(options) { this.options = options; dialogs.push(this); }
                show() { this.visible = true; }
                hide() { this.visible = false; }
                destroy() { this.destroyed = true; }
                deselectAll() {}
            }}},
            ajax: {runComponentAction: async (component, action, options) => {
                requests.push(options.data);
                actions.push(action);
                const reply = replies.shift();
                if (reply instanceof Error) throw reply;
                return reply;
            }},
        },
    };
    vm.createContext(context);
    vm.runInContext(source, context);
    context.ui.setup(form);
    context.ui.createServiceBlock([], '1');
    context.ui.formHandler();
    form.querySelector('#select-department').value = '1';
    form.querySelector('#select-priority').value = '95';
    const schema = {version: 'recruitment-v1', recruitment: true, fields: [
        {name: 'hr_requester', type: 'employee', label: 'Заказчик', required: true,
            value: '3076', userLabel: 'Федоров Алексей'},
        {name: 'hr_count', type: 'integer', label: 'Количество', required: true, min: 1, max: 10},
        {name: 'hr_title', type: 'text', label: 'Должность', required: true},
    ]};
    const select = (service = {value: '999', hasServiceRules: false, fieldSchema: schema}) => {
        form.querySelector('#select-service').value = service.value;
        context.ui.selectServiceForm(service, context.createTestField);
    };
    select();
    const fill = () => {
        context.ui.controls.get('hr_count').input.value = '2';
        context.ui.controls.get('hr_title').input.value = 'Оператор станка';
        context.ui.controls.get('hr_requester').element.setServiceValue('3076', 'Федоров Алексей');
    };
    fill();
    const submit = () => form.events.submit({preventDefault() {}});
    return {context, form, schema, select, fill, submit, dialogs, storage, alerts, requests, replies, actions};
}

async function main() {
    const demo = harness();
    const {ui} = demo.context;
    assert.equal(ui.controls.size, 3, 'Schema must render without serviceRules');
    const employee = ui.controls.get('hr_requester');
    assert.equal(employee.input.tagName, 'INPUT');
    assert.equal(employee.input.type, 'hidden');
    assert.equal(employee.input.name, 'hr_requester');
    assert.equal(employee.focusInput.textContent, 'Федоров Алексей');
    employee.focusInput.events.click();
    const dialog = demo.dialogs.at(-1);
    dialog.options.events['Item:onSelect']({getData: () => ({item: {
        getId: () => 42, getTitle: () => 'Новый заказчик',
    }})});
    assert.equal(employee.input.value, '42');
    assert.equal(employee.focusInput.textContent, 'Новый заказчик');
    demo.select({value: 'other'});
    demo.select();
    assert.equal(ui.controls.get('hr_requester').input.value, '42');
    assert.equal(ui.controls.get('hr_requester').focusInput.textContent, 'Новый заказчик');
    assert.equal(dialog.destroyed, true, 'Removed employee controls must destroy their dialog');
    const unloadedEmployee = demo.context.createTestField({name: 'restored_employee', type: 'employee'});
    unloadedEmployee.setServiceValue('57', '');
    const loadingDialog = demo.dialogs.at(-1);
    assert.equal(loadingDialog.options.preselectedItems[0][0], 'user');
    assert.equal(loadingDialog.options.preselectedItems[0][1], 57);
    loadingDialog.options.events.onLoad({getTarget: () => ({getSelectedItems: () => [{
        getId: () => 57, getTitle: () => 'Восстановленный сотрудник',
    }]})});
    assert.equal(unloadedEmployee.serviceFocusInput.textContent, 'Восстановленный сотрудник');
    demo.form.querySelector('#responsible').disabled = true;
    demo.select();
    assert.equal(demo.form.querySelector('#responsible').disabled, true,
        'Rendering HR state must preserve an independent department-selection request lock');
    demo.form.querySelector('#responsible').disabled = false;

    for (const invalid of ['0', '11', '1.5', '2e0', '']) {
        ui.controls.get('hr_count').input.value = invalid;
        assert.ok(ui.validateRequirementFields().hr_count, `Invalid count ${invalid} must fail`);
    }
    demo.fill();
    const requestKey = ui.getRecruitmentRequest('999').key;
    assert.match(requestKey, /^[a-f0-9]{8}-[a-f0-9]{4}-4[a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$/);
    demo.replies.push(new Error('Connection closed'));
    await demo.submit();
    assert.equal(demo.requests[0].get('select-service'), '999', 'Service must survive the request lock');
    assert.equal(demo.requests[0].get('hr_requester'), '3076');
    assert.equal(demo.requests[0].get('service_fields_version'), 'recruitment-v1');
    assert.equal(demo.requests[0].get('recruitment_request_key'), requestKey);
    assert.equal(ui.getRecruitmentRequest('999').status, 'SENDING');
    assert.equal(demo.form.querySelector('#submit').disabled, true,
        'A transport failure must block submission until its server status is checked');
    assert.equal(ui.controls.get('hr_title').input.disabled, true);
    assert.equal(demo.form.querySelector('#description').disabled, true);
    assert.equal(demo.form.querySelector('#task-files').disabled, true);
    await demo.submit();
    assert.equal(demo.requests.length, 1, 'A direct retry must not resend an uncertain request');
    const interruptedPage = harness(new Map(demo.storage));
    assert.equal(interruptedPage.form.querySelector('#submit').disabled, true,
        'A restored SENDING state must block submission after reload');
    assert.equal(interruptedPage.context.ui.stateBlock.children.some(node => node.textContent === 'Новая заявка'), false);
    interruptedPage.replies.push({data: {userId: 3076, taskId: 123, recruitmentStatus: 'COMPLETE'}});
    await interruptedPage.context.ui.stateBlock.children.find(node => node.textContent === 'Проверить отправку').events.click();
    assert.equal(interruptedPage.actions[0], 'recruitmentStatus');
    assert.equal(interruptedPage.requests[0].get('recruitment_request_key'), requestKey);
    assert.equal(interruptedPage.requests[0].get('select-service'), '999');
    assert.equal(interruptedPage.requests[0].get('hr_title'), undefined,
        'Recovery must query the journal without depending on restored form contents');
    assert.match(interruptedPage.context.location.href, /\/123\/$/);
    demo.replies.push({data: {recruitmentStatus: 'NOT_FOUND'}});
    await ui.stateBlock.children.find(node => node.textContent === 'Проверить отправку').events.click();
    assert.equal(demo.actions[1], 'recruitmentStatus');
    assert.equal(ui.getRecruitmentRequest('999').status, 'DRAFT');
    assert.equal(demo.form.querySelector('#submit').disabled, false);
    assert.equal(ui.controls.get('hr_title').input.disabled, false);
    assert.equal(demo.form.querySelector('#description').disabled, false);
    assert.equal(demo.form.querySelector('#task-files').disabled, false);
    ui.controls.get('hr_title').input.value = 'Уточнённая должность';
    demo.replies.push({data: {userId: 3076, taskId: 123, recruitmentStatus: 'COMPLETE'}});
    await demo.submit();
    assert.equal(demo.requests[2].get('recruitment_request_key'), requestKey);
    assert.equal(demo.requests[2].get('hr_title'), 'Уточнённая должность');
    assert.match(demo.context.location.href, /\/tasks\/task\/view\/123\/$/);
    await demo.submit();
    assert.equal(demo.requests.length, 3, 'Spent requests must not be submitted again');
    assert.equal(demo.form.querySelector('#submit').disabled, true);
    const stored = JSON.parse([...demo.storage.values()][0]);
    assert.deepEqual(Object.keys(stored).sort(), ['key', 'status']);
    const restored = harness(demo.storage);
    assert.equal(restored.form.querySelector('#submit').disabled, true, 'Spent state must survive a new page');
    const newRequest = restored.context.ui.stateBlock.children.find(node => node.textContent === 'Новая заявка');
    newRequest.events.click();
    assert.notEqual(restored.context.ui.getRecruitmentRequest('999').key, requestKey);
    assert.equal(restored.form.querySelector('#submit').disabled, false);
    assert.equal(restored.context.ui.controls.get('hr_requester').input.value, '');
    assert.equal(restored.form.querySelector('#task-files').value, '', 'New draft must discard previous attachments');
    assert.equal(restored.form.querySelector('#task-files-counter').textContent, '[0]');

    const pending = harness();
    pending.replies.push({data: {userId: 3076, taskId: 124, recruitmentStatus: 'PENDING',
        recruitmentMessage: 'Задача создана, вакансия ожидает обработки.'}});
    await pending.submit();
    assert.ok(pending.alerts.includes('Задача создана, вакансия ожидает обработки.'));
    assert.match(pending.context.location.href, /\/124\/$/);

    const uncertain = harness();
    uncertain.replies.push({data: {recruitmentStatus: 'UNCERTAIN', recruitmentMessage: 'Нужна проверка.'}});
    await uncertain.submit();
    assert.equal(uncertain.form.querySelector('#submit').disabled, true);
    assert.equal(uncertain.context.ui.stateBlock.children.some(node => node.textContent === 'Новая заявка'), false);
    assert.ok(uncertain.alerts[0].includes('Повторно отправлять заявку не следует'));
    const uncertainKey = uncertain.context.ui.getRecruitmentRequest('999').key;
    uncertain.replies.push({data: {recruitmentStatus: 'COMPLETE', userId: 3076, taskId: 126}});
    await uncertain.context.ui.stateBlock.children.find(node => node.textContent === 'Проверить отправку').events.click();
    assert.equal(uncertain.requests[1].get('recruitment_request_key'), uncertainKey,
        'An administrator reconciliation must be discoverable through the original request identifier');
    assert.equal(uncertain.context.ui.getRecruitmentRequest('999').status, 'COMPLETE');
    assert.equal(uncertain.context.ui.stateBlock.children.some(node => node.textContent === 'Новая заявка'), true);
    assert.match(uncertain.context.location.href, /\/126\/$/);

    const failedCheck = harness();
    failedCheck.replies.push(new Error('Connection closed'));
    await failedCheck.submit();
    failedCheck.replies.push(new Error('Status endpoint unavailable'));
    await failedCheck.context.ui.stateBlock.children.find(node => node.textContent === 'Проверить отправку').events.click();
    assert.equal(failedCheck.context.ui.getRecruitmentRequest('999').status, 'SENDING');
    assert.equal(failedCheck.form.querySelector('#submit').disabled, true);
    assert.equal(failedCheck.context.ui.stateBlock.children.some(node => node.textContent === 'Новая заявка'), false);
    assert.equal(JSON.parse([...failedCheck.storage.values()][0]).status, 'SENDING');
    failedCheck.replies.push({data: {recruitmentStatus: 'PENDING', userId: 3076, taskId: 127}});
    await failedCheck.context.ui.stateBlock.children.find(node => node.textContent === 'Проверить отправку').events.click();
    assert.deepEqual(failedCheck.actions, ['send', 'recruitmentStatus', 'recruitmentStatus']);
    assert.match(failedCheck.context.location.href, /\/127\/$/);

    const validation = harness();
    validation.replies.push({data: {userId: 3076, taskId: 999, validationErrors: ['Не заполнен отдел.'],
        fieldErrors: {hr_title: 'Уточните должность.'}}});
    await validation.submit();
    assert.equal(validation.context.location.href, '', 'Validation must stop navigation even if IDs are present');
    assert.equal(validation.context.ui.controls.get('hr_title').error.textContent, 'Уточните должность.');
    assert.ok(validation.alerts.includes('Не заполнен отдел.'), 'Unmapped validation messages must remain visible');
    assert.equal(validation.form.querySelector('#submit').disabled, false);

    const legacy = harness();
    legacy.select({value: 'other'});
    legacy.replies.push({data: {userId: 3076, taskId: 125}});
    await legacy.submit();
    assert.equal(legacy.requests[0].get('recruitment_request_key'), undefined, 'Other services must not receive an HR key');
    assert.match(legacy.context.location.href, /\/125\/$/);
    console.log('PASS: recruitment UI, employee selection, validation, retry and partial-result scenarios.');
}

main().catch(error => { console.error(error); process.exitCode = 1; });
