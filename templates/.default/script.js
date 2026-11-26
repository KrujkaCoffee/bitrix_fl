{
    let responsibleSelects = [];
    let serviceBlock = null;
    let serviceDescriptionWrapper = null;
    let serviceDescription = null;
    let serviceRulesBlock = null;
    let executorRulesBlock = null;
    let generalRulesNodes = [];

    let descriptionField = null;
    let requirementFieldsBlock = null;
    const requirementControls = new Map();
    let activeServiceKey = null;
    let activeFieldSchema = null;
    let unassignedDescription = '';
    const serviceDrafts = new Map();
    const recruitmentRequests = new Map();
    let recruitmentStateBlock = null;
    let formRequestPending = false;
    let submitLockedSelectors = [];
    let lockedRecruitmentInputs = [];

    const isRecruitmentSchema = schema => !!schema && schema.recruitment === true;

    const recruitmentStorageKey = serviceKey => 'services.recruitment.'
        + encodeURIComponent(servicesComponentName) + '.' + encodeURIComponent(serviceKey);

    const newRecruitmentRequestKey = () => {
        if (typeof crypto.randomUUID === 'function') return crypto.randomUUID();
        const bytes = new Uint8Array(16);
        crypto.getRandomValues(bytes);
        bytes[6] = (bytes[6] & 0x0f) | 0x40;
        bytes[8] = (bytes[8] & 0x3f) | 0x80;
        const hex = Array.from(bytes, byte => byte.toString(16).padStart(2, '0')).join('');
        return [hex.slice(0, 8), hex.slice(8, 12), hex.slice(12, 16),
            hex.slice(16, 20), hex.slice(20)].join('-');
    };

    const saveRecruitmentRequest = (serviceKey, state) => {
        // Persist only the request identifier and its lifecycle, never form fields or names.
        recruitmentRequests.set(serviceKey, state);
        sessionStorage.setItem(recruitmentStorageKey(serviceKey), JSON.stringify({
            key: state.key,
            status: state.status,
        }));
    };

    const getRecruitmentRequest = serviceKey => {
        if (recruitmentRequests.has(serviceKey)) return recruitmentRequests.get(serviceKey);
        const stored = sessionStorage.getItem(recruitmentStorageKey(serviceKey));
        if (stored) {
            let state;
            try {
                state = JSON.parse(stored);
            } catch (error) {
                throw new Error('Не удалось прочитать идентификатор предыдущей заявки. Обратитесь к администратору.');
            }
            if (!state || !/^[a-f0-9]{8}(-[a-f0-9]{4}){3}-[a-f0-9]{12}$/i.test(state.key)
                || !['DRAFT', 'SENDING', 'PENDING', 'COMPLETE', 'UNCERTAIN'].includes(state.status)) {
                throw new Error('Не удалось определить состояние предыдущей заявки. Обратитесь к администратору.');
            }
            recruitmentRequests.set(serviceKey, state);
            return state;
        }
        const state = {key: newRecruitmentRequestKey(), status: 'DRAFT'};
        saveRecruitmentRequest(serviceKey, state);
        return state;
    };

    const handleRecruitmentResult = (serviceKey, state, result) => {
        if (!result || !['PENDING', 'COMPLETE', 'UNCERTAIN'].includes(result.recruitmentStatus)) {
            throw new Error('Сервер не подтвердил состояние заявки.');
        }
        state.status = result.recruitmentStatus;
        try {
            saveRecruitmentRequest(serviceKey, state);
        } catch (error) {
            alert('Результат заявки получен, но браузер не сохранил её состояние. '
                + 'Идентификатор заявки: ' + state.key + '. ' + error.message);
        }
        if (result.recruitmentStatus === 'PENDING') {
            alert(result.recruitmentMessage || 'Задача создана. Создание вакансии ещё выполняется. '
                + 'Откроется существующая задача, повторно отправлять заявку не требуется.');
        } else if (result.recruitmentStatus === 'UNCERTAIN') {
            alert((result.recruitmentMessage || 'Результат создания заявки требует проверки. '
                + 'Обратитесь к администратору.') + '\nПовторно отправлять заявку не следует. '
                + 'Идентификатор заявки: ' + state.key + '.');
        }
        if (/^[1-9]\d*$/.test(String(result.userId)) && /^[1-9]\d*$/.test(String(result.taskId))) {
            location.href = `/company/personal/user/${result.userId}/tasks/task/view/${result.taskId}/`;
        }
    };

    const checkRecruitmentStatus = async (serviceKey, state) => {
        if (formRequestPending) return;
        formRequestPending = true;
        updateRecruitmentState();
        try {
            const data = new FormData();
            data.set('select-service', serviceKey);
            data.set('recruitment_request_key', state.key);
            const response = await BX.ajax.runComponentAction(servicesComponentName, 'recruitmentStatus', {
                mode: 'class', data,
            });
            const result = response && response.data;
            if (showServerValidation(result)) return;
            if (result && result.recruitmentStatus === 'NOT_FOUND') {
                state.status = 'DRAFT';
                saveRecruitmentRequest(serviceKey, state);
                alert(result.recruitmentMessage || 'Отправка не зарегистрирована. Можно проверить поля и отправить заявку.');
            } else {
                handleRecruitmentResult(serviceKey, state, result);
            }
        } catch (error) {
            const messages = error && Array.isArray(error.errors)
                ? error.errors.map(item => item.message).filter(Boolean) : [];
            alert((messages.length ? messages.join('\n') : 'Не удалось проверить отправку. Повторите проверку позже.')
                + '\nИдентификатор заявки: ' + state.key + '.');
        } finally {
            formRequestPending = false;
            updateRecruitmentState();
        }
    };

    const updateRecruitmentState = () => {
        const form = document.querySelector('#service-form');
        if (!form) return;
        if (!recruitmentStateBlock) {
            recruitmentStateBlock = createRulesElement('div', 'service-requirements__request-state');
            recruitmentStateBlock.setAttribute('aria-live', 'polite');
            form.querySelector('[type="submit"]').insertAdjacentElement('beforebegin', recruitmentStateBlock);
        }
        recruitmentStateBlock.textContent = '';
        recruitmentStateBlock.hidden = true;
        let blocked = false;
        if (isRecruitmentSchema(activeFieldSchema)) {
            try {
                const state = getRecruitmentRequest(activeServiceKey);
                blocked = state.status !== 'DRAFT';
                if (blocked) {
                    let message = 'Эта заявка уже отправлена. Для отдельной вакансии нажмите «Новая заявка».';
                    if (state.status === 'SENDING') {
                        message = formRequestPending ? 'Выполняется отправка или проверка заявки.'
                            : 'Подтверждение отправки не получено. Нажмите «Проверить отправку», '
                                + 'чтобы узнать результат и продолжить работу с заявкой.';
                    } else if (state.status === 'UNCERTAIN') {
                        message = 'Результат создания заявки требует проверки. Повторная отправка заблокирована. '
                            + 'Обратитесь к администратору и сообщите идентификатор ' + state.key + '.';
                    }
                    recruitmentStateBlock.appendChild(createRulesElement('p', '', message));
                    if (state.status === 'SENDING' || state.status === 'UNCERTAIN') {
                        const checkButton = createRulesElement('button', 'ui-btn ui-btn-light-border', 'Проверить отправку');
                        checkButton.type = 'button';
                        checkButton.disabled = formRequestPending;
                        const serviceKey = activeServiceKey;
                        checkButton.addEventListener('click', () => checkRecruitmentStatus(serviceKey, state));
                        recruitmentStateBlock.appendChild(checkButton);
                    } else if (state.status !== 'UNCERTAIN') {
                        const newDraftButton = createRulesElement('button', 'ui-btn ui-btn-light-border', 'Новая заявка');
                        newDraftButton.type = 'button';
                        newDraftButton.disabled = formRequestPending;
                        newDraftButton.addEventListener('click', () => {
                            if (formRequestPending) return;
                            try {
                                saveRecruitmentRequest(activeServiceKey, {
                                    key: newRecruitmentRequestKey(), status: 'DRAFT',
                                });
                                serviceDrafts.delete(activeServiceKey);
                                requirementControls.forEach(control => {
                                    if (control.element.setServiceValue) control.element.setServiceValue('', '');
                                    else control.input.value = '';
                                    clearRequirementError(control);
                                });
                                descriptionField.value = '';
                                const files = form.querySelector('#task-files');
                                const filesCounter = form.querySelector('#task-files-counter');
                                if (files) files.value = '';
                                if (filesCounter) filesCounter.textContent = '[0]';
                                updateRequirementVisibility();
                                updateRecruitmentState();
                            } catch (error) {
                                alert('Не удалось подготовить новую заявку. ' + error.message);
                            }
                        });
                        recruitmentStateBlock.appendChild(newDraftButton);
                    }
                    recruitmentStateBlock.hidden = false;
                }
            } catch (error) {
                blocked = true;
                recruitmentStateBlock.textContent = 'Не удалось сохранить состояние заявки в браузере. '
                    + 'Проверьте доступность хранилища браузера. ' + error.message;
                recruitmentStateBlock.hidden = false;
            }
        }
        form.querySelectorAll('[type="submit"]').forEach(button => {
            button.disabled = formRequestPending || blocked;
        });
        const serviceSelect = form.querySelector('#select-service');
        if (formRequestPending && !submitLockedSelectors.length) {
            submitLockedSelectors = [...responsibleSelects, serviceSelect].filter(Boolean)
                .map(select => ({select, disabled: select.disabled}));
            submitLockedSelectors.forEach(({select}) => { select.disabled = true; });
        } else if (!formRequestPending && submitLockedSelectors.length) {
            submitLockedSelectors.forEach(({select, disabled}) => { select.disabled = disabled; });
            submitLockedSelectors = [];
        }
        lockedRecruitmentInputs.forEach(({input, disabled}) => { input.disabled = disabled; });
        lockedRecruitmentInputs = [];
        requirementControls.forEach(control => {
            if (control.element.setServiceDisabled) control.element.setServiceDisabled(control.input.disabled);
        });
        if (isRecruitmentSchema(activeFieldSchema) && (blocked || formRequestPending)) {
            const inputs = new Set([descriptionField, form.querySelector('#task-files')]);
            requirementControls.forEach(control => {
                inputs.add(control.input);
                inputs.add(control.focusInput);
                control.element.querySelectorAll('button').forEach(button => inputs.add(button));
            });
            lockedRecruitmentInputs = Array.from(inputs).filter(Boolean)
                .map(input => ({input, disabled: input.disabled}));
            lockedRecruitmentInputs.forEach(({input}) => { input.disabled = true; });
            requirementControls.forEach(control => {
                if (control.element.setServiceDisabled) control.element.setServiceDisabled(true);
            });
        }
    };

    let marketingLogic = 0;

    let extraFieldsMap = [];

    let fieldsForConcatTitle = [];

    const createRulesElement = (tag, className, text) => {
        const element = document.createElement(tag);
        if (className) element.className = className;
        if (text !== undefined) element.textContent = text;
        return element;
    };

    const appendRulesText = (container, text) => {
        let list = null;
        let lastItem = null;
        let nestedList = null;
        String(text || '').split(/\r?\n/).forEach(rawLine => {
            const line = rawLine.trim();
            if (!line) {
                list = null;
                lastItem = null;
                nestedList = null;
                return;
            }
            if (/^\*\s/.test(line)) {
                if (!list) {
                    list = createRulesElement('ul', 'service-rules__list');
                    container.appendChild(list);
                }
                lastItem = createRulesElement('li', '', line.replace(/^\*\s+/, ''));
                list.appendChild(lastItem);
                nestedList = null;
            } else if (/^-\s/.test(line) && lastItem) {
                if (!nestedList) {
                    nestedList = createRulesElement('ul', 'service-rules__sublist');
                    lastItem.appendChild(nestedList);
                }
                nestedList.appendChild(createRulesElement('li', '', line.replace(/^-\s+/, '')));
            } else {
                list = null;
                lastItem = null;
                nestedList = null;
                container.appendChild(createRulesElement('p', 'service-rules__paragraph', line));
            }
        });
    };

    const renderExecutorRules = sections => {
        if (!generalRulesNodes.length) return;
        if (!executorRulesBlock) {
            executorRulesBlock = createRulesElement('div', 'service-executor-rules');
            generalRulesNodes[0].insertAdjacentElement('beforebegin', executorRulesBlock);
        }
        executorRulesBlock.textContent = '';
        executorRulesBlock.hidden = !sections.length;
        generalRulesNodes.forEach(node => node.classList.toggle('service-rules-original-hidden', !!sections.length));
        sections.forEach(section => {
            executorRulesBlock.appendChild(createRulesElement('div', 'column__title', section.title));
            const body = createRulesElement('div', 'service-rules service-rules--executor');
            const lines = String(section.text || '').split(/\r?\n/);
            if (lines[0].trim() === section.title) lines.shift();
            appendRulesText(body, lines.join('\n'));
            executorRulesBlock.appendChild(body);
        });
    };

    const appendRequirementsTable = (body, fields) => {
        body.appendChild(createRulesElement('p', 'service-rules__paragraph',
            'Обязательные сведения заполняются в полях заявки.'));
        const table = createRulesElement('table', 'service-rules__table');
        const thead = document.createElement('thead');
        const heading = document.createElement('tr');
        ['Что указать', 'Требование'].forEach(text => {
            const th = createRulesElement('th', '', text);
            th.scope = 'col';
            heading.appendChild(th);
        });
        thead.appendChild(heading);
        table.appendChild(thead);
        const tbody = document.createElement('tbody');
        fields.filter(field => field.required || field.visible_if).forEach(field => {
            const row = document.createElement('tr');
            const label = createRulesElement('th', '', field.label);
            label.scope = 'row';
            row.appendChild(label);
            const cell = document.createElement('td');
            cell.appendChild(createRulesElement('strong', 'service-rules__requirement',
                field.visible_if ? (field.condition_hint || 'При выполнении условия') : 'Обязательно'));
            cell.appendChild(createRulesElement('span', '', field.hint));
            row.appendChild(cell);
            tbody.appendChild(row);
        });
        table.appendChild(tbody);
        body.appendChild(table);
    };

    const renderServiceRules = (sections, schema = null) => {
        sections = Array.isArray(sections) ? sections : [];
        renderExecutorRules(sections.filter(section => section.placement === 'general'));
        if (!serviceDescriptionWrapper) return;
        if (!serviceRulesBlock) {
            serviceRulesBlock = createRulesElement('div', 'service-rules');
            serviceRulesBlock.setAttribute('aria-label', 'Правила выбранной услуги');
            serviceDescriptionWrapper.insertAdjacentElement('afterend', serviceRulesBlock);
        }
        serviceRulesBlock.textContent = '';
        serviceRulesBlock.hidden = true;
        sections = sections.filter(section => section.placement !== 'general');
        if (!sections.length) return;

        sections.forEach((section, index) => {
            const kind = ['standard', 'notice', 'details'].includes(section.kind)
                ? section.kind : 'standard';
            const isDetails = kind === 'details';
            const panel = createRulesElement(isDetails ? 'details' : 'section',
                'service-rules__section service-rules__section--' + kind);
            const title = createRulesElement(isDetails ? 'summary' : 'h3',
                'service-rules__title', section.title);
            title.id = 'service-rules-title-' + index;
            if (!isDetails) panel.setAttribute('aria-labelledby', title.id);
            panel.appendChild(title);
            const body = createRulesElement('div', 'service-rules__body');
            if (kind === 'primary' && schema && schema.fields) {
                appendRequirementsTable(body, schema.fields);
            } else {
                appendRulesText(body, section.text);
            }
            panel.appendChild(body);
            serviceRulesBlock.appendChild(panel);
        });
        serviceRulesBlock.hidden = false;
    };

    const normalizedFieldValue = value => String(value || '').replace(
        /^[\s\p{Z}\u200B\uFEFF]+|[\s\p{Z}\u200B\uFEFF]+$/gu, '');

    const currentRequirementValues = () => {
        const values = {};
        requirementControls.forEach((control, name) => { values[name] = control.input.value; });
        return values;
    };

    const clearRequirementError = control => {
        control.error.textContent = '';
        control.error.hidden = true;
        control.input.removeAttribute('aria-invalid');
        control.focusInput.removeAttribute('aria-invalid');
        control.input.setCustomValidity('');
    };

    const showRequirementErrors = errors => {
        let first = null;
        Object.entries(errors).forEach(([name, message]) => {
            const control = requirementControls.get(name);
            if (!control || control.wrapper.hidden) return;
            control.error.textContent = message;
            control.error.hidden = false;
            control.input.setAttribute('aria-invalid', 'true');
            control.focusInput.setAttribute('aria-invalid', 'true');
            control.input.setCustomValidity(message);
            if (!first) first = control.focusInput;
        });
        if (first) first.focus();
        return !!first;
    };

    const showServerValidation = payload => {
        if (!payload || typeof payload !== 'object') return false;
        const fieldErrors = payload.fieldErrors && typeof payload.fieldErrors === 'object'
            ? payload.fieldErrors : {};
        const messages = Array.isArray(payload.validationErrors) ? payload.validationErrors : [];
        if (!messages.length && !Object.keys(fieldErrors).length) return false;
        showRequirementErrors(fieldErrors);
        const visibleMessages = Object.entries(fieldErrors)
            .filter(([name]) => requirementControls.has(name) && !requirementControls.get(name).wrapper.hidden)
            .map(([, message]) => message);
        const remainingMessages = [...messages, ...Object.values(fieldErrors)]
            .filter(message => typeof message === 'string' && !visibleMessages.includes(message));
        if (remainingMessages.length) alert(Array.from(new Set(remainingMessages)).join('\n'));
        return true;
    };

    const updateRequirementVisibility = () => {
        const values = currentRequirementValues();
        requirementControls.forEach(control => {
            const condition = control.field.visible_if;
            const visible = !condition || normalizedFieldValue(values[condition.field]) === String(condition.equals);
            control.wrapper.hidden = !visible;
            control.input.disabled = !visible;
            if (control.focusInput !== control.input) control.focusInput.disabled = !visible;
            if (control.element.setServiceDisabled) control.element.setServiceDisabled(!visible);
            control.input.required = visible && !!control.field.required;
            control.input.setAttribute('aria-required', control.input.required ? 'true' : 'false');
            control.focusInput.setAttribute('aria-required', control.input.required ? 'true' : 'false');
            if (!visible) clearRequirementError(control);
        });
    };

    const validateRequirementFields = () => {
        const errors = {};
        requirementControls.forEach(control => {
            if (control.input.disabled) return;
            const {field, input} = control;
            const value = normalizedFieldValue(input.value);
            if (field.required && !value) {
                errors[field.name] = 'Заполните поле «' + field.label + '».';
            } else if (value && field.type === 'list' && !field.values.some(item => item.value === value)) {
                errors[field.name] = 'Выберите допустимое значение поля «' + field.label + '».';
            } else if (value && field.type === 'employee' && !/^[1-9]\d*$/.test(value)) {
                errors[field.name] = 'Выберите сотрудника в поле «' + field.label + '».';
            } else if (value && field.type === 'integer') {
                const number = Number(value);
                if (!/^-?\d+$/.test(value) || !Number.isSafeInteger(number)
                    || (field.min !== undefined && number < Number(field.min))
                    || (field.max !== undefined && number > Number(field.max))) {
                    errors[field.name] = 'Укажите допустимое целое число в поле «' + field.label + '».';
                }
            }
        });
        return errors;
    };

    const renderRequirementFields = (schema, createField, savedValues = {}, savedLabels = {}) => {
        requirementControls.forEach(control => {
            if (control.element.destroyServiceField) control.element.destroyServiceField();
        });
        requirementControls.clear();
        if (requirementFieldsBlock) {
            requirementFieldsBlock.textContent = '';
            requirementFieldsBlock.hidden = true;
        }
        if (!schema) return;
        if (!requirementFieldsBlock) {
            requirementFieldsBlock = createRulesElement('fieldset', 'service-requirements');
            const anchor = document.querySelector('#service-form label[for="description"]') || descriptionField;
            anchor.insertAdjacentElement('beforebegin', requirementFieldsBlock);
        }
        requirementFieldsBlock.appendChild(createRulesElement('legend', '', schema.title || 'Сведения по заявке'));
        requirementFieldsBlock.appendChild(createRulesElement('p', 'service-requirements__intro',
            schema.intro || 'Поля со звёздочкой обязательны.'));
        const grid = createRulesElement('div', 'service-requirements__grid');
        schema.fields.forEach(field => {
            const wrapper = createRulesElement('div', 'service-requirements__field');
            if (field.layout === 'wide') wrapper.classList.add('service-requirements__field--wide');
            const label = createRulesElement('label', '', field.label + (field.required ? ' *' : ''));
            const element = createField(field);
            if (!element) throw new Error('Неподдерживаемый тип поля: ' + field.type);
            const input = element.serviceCanonicalInput || element;
            const focusInput = element.serviceFocusInput || input;
            label.htmlFor = focusInput.id;
            const value = Object.prototype.hasOwnProperty.call(savedValues, field.name)
                ? savedValues[field.name] : (field.defaultValue ?? field.value ?? '');
            if (element.setServiceValue) {
                element.setServiceValue(value, savedLabels[field.name] || field.userLabel || '');
            } else {
                input.value = value;
            }
            const hint = createRulesElement('p', 'service-requirements__hint', field.hint || '');
            hint.id = field.name + '_hint';
            hint.hidden = !field.hint;
            const error = createRulesElement('p', 'service-requirements__error');
            error.id = field.name + '_error';
            error.hidden = true;
            error.setAttribute('aria-live', 'polite');
            input.setAttribute('aria-describedby', [hint.id, error.id].join(' '));
            focusInput.setAttribute('aria-describedby', [hint.id, error.id].join(' '));
            wrapper.append(label, element, hint, error);
            grid.appendChild(wrapper);
            const control = {field, input, focusInput, element, wrapper, error};
            requirementControls.set(field.name, control);
            const onEdit = () => { clearRequirementError(control); updateRequirementVisibility(); };
            input.addEventListener('input', onEdit);
            input.addEventListener('change', onEdit);
            input.addEventListener('invalid', () => {
                if (input.validity.valueMissing) {
                    error.textContent = 'Заполните поле «' + field.label + '».';
                    error.hidden = false;
                    input.setAttribute('aria-invalid', 'true');
                }
            });
        });
        requirementFieldsBlock.appendChild(grid);
        requirementFieldsBlock.hidden = false;
        updateRequirementVisibility();
    };

    const saveServiceDraft = () => {
        if (!descriptionField) return;
        if (activeServiceKey !== null) {
            serviceDrafts.set(activeServiceKey, {
                description: descriptionField.value,
                fields: currentRequirementValues(),
                labels: Object.fromEntries(Array.from(requirementControls.entries())
                    .filter(([, control]) => control.element.getServiceLabel)
                    .map(([name, control]) => [name, control.element.getServiceLabel()])),
            });
        } else {
            unassignedDescription = descriptionField.value;
        }
    };

    const selectServiceForm = (service, createField) => {
        saveServiceDraft();
        activeServiceKey = String(service.value);
        activeFieldSchema = service.fieldSchema
            && Array.isArray(service.fieldSchema.fields) ? service.fieldSchema : null;
        const draft = serviceDrafts.get(activeServiceKey);
        if (draft) {
            descriptionField.value = draft.description;
        } else {
            descriptionField.value = [service.startText || '', unassignedDescription]
                .filter(text => String(text).trim()).join('\n\n');
        }
        renderRequirementFields(activeFieldSchema, createField, draft ? draft.fields : {}, draft ? draft.labels : {});
        updateRecruitmentState();
    };

    const resetServiceForm = () => {
        saveServiceDraft();
        activeServiceKey = null;
        activeFieldSchema = null;
        renderRequirementFields(null);
        if (descriptionField) descriptionField.value = unassignedDescription;
        updateRecruitmentState();
    };

    let createResponsibleBlock = (departments, depth) => {

        let select = document.createElement('select');
        select.dataset.depth = depth;
        select.classList.add('js-select');


        let option = document.createElement('option');
        option.value = '';
        option.innerText = 'Выберите';
        option.selected = true;
        option.disabled = true;
        select.insertAdjacentElement('beforeend', option);

        departments.forEach( department => {
            let option = document.createElement('option');
            option.value = department.value;
            option.innerText = department.label;
            select.insertAdjacentElement('beforeend', option);
        });

        responsibleSelects[responsibleSelects.length-1].insertAdjacentElement('afterend', select);

        responsibleSelects.push(select);
        selectAddHandler(select);
    };

    let deleteExcessResponsibleBlocks = depth => {
        let toRemove = [];
        responsibleSelects.forEach( (select, index) => {
            if ( select.dataset.depth >= depth ){
                toRemove.push(select);
                select.remove();
            }
        });

        toRemove.forEach(item => {
            responsibleSelects.splice(responsibleSelects.indexOf(item), 1);
        });
    };

    let deleteServiceBlock = () => {
        renderServiceRules([]);
        resetServiceForm();
        marketingLogic = 0;
        if (serviceBlock){
            serviceBlock.remove();
            serviceBlock = null;
            serviceDescription.innerHTML = '';
            serviceDescriptionWrapper.style.height = 0;
        }

    };

    let getWordEnding = (number, declension) => {
        if (number % 100 >= 11 && number % 100 <= 19 || number % 10 == 0 || number % 10 >= 5 && number % 10 <= 9) {
            return declension[0];
        }
        if (number % 10 == 1) {
            return declension[1];
        }
        return declension[2];
    }

    let fullServiceDescription = (description, leadTime = '', timeAt = '', timeTo = '') => {

        let leadTimeFormatted;
        let schedule;

        if (leadTime) {
            leadTime = leadTime / 60;
            let mins = leadTime % 60,
                hours = (leadTime - mins) / 60;
            leadTimeFormatted = (hours ? hours + ' ' + getWordEnding(hours, ['часов', 'час', 'часа']) + ' ' : '') + (mins ? mins + ' ' + getWordEnding(mins, ['минут', 'минута', 'минуты']) : '');
        }

        if (timeAt && timeTo){
            schedule = `${timeAt} — ${timeTo}`;
        }

        serviceDescription.innerHTML = `${description} ${(leadTimeFormatted || schedule) && `<div class="service__description__properties" ${!description && `style="margin-top: 0;"`}>${leadTimeFormatted && `<div>Ожидаемое время решения проблемы</div><div>${leadTimeFormatted}</div>`}${schedule && `<div>Режим оказания услуги</div><div>${schedule}</div>`}</div>`}`;
        serviceDescriptionWrapper.style.height = serviceDescription.scrollHeight + 47 + 'px';
    }

    let createServiceBlock = (services, parentDepartment, serviceRules = []) => {

        let buildDiv = className => {
            let div = document.createElement('div');
            div.classList.add(className);
            return div;
        };

        let buildLabel = (text, id) => {
            let label = document.createElement('label');
            label.innerText = text;
            label.htmlFor = id;
            return label;
        };

        let buildSelect = (options, name, multiple = false) => {
            let select = document.createElement('select');
            select.name = name;
            select.id = name;
            select.multiple = multiple;
            if ( select.multiple === true ){
                select.classList.add('js-select-multiple');
            }
            select.classList.add('js-select');
            select.insertAdjacentElement('beforeend', buildOption('Выберите подходящее', '', 'selected', 'disabled'));
            options.forEach( option => {
                select.insertAdjacentElement('beforeend', buildOption(option.label, option.value));
            } );
            return select;
        };

        let buildOption = (label, value, selected = null, disabled = null) => {
            let option = document.createElement('option');
            option.innerText = label;
            option.value = value;
            if (selected){
                option.selected = true;
            }
            if (disabled){
                option.disabled = true;
            }
            return option;
        };

        let buildDeadlineInput = (deadline, timeAt, timeTo) => {
            let input = document.createElement('input');
            input.classList.add('input-deadline');
            input.type = 'text';
            input.name = 'input-deadline';
            input.id = 'input-deadline';
            let minDateTime = new Date((deadline * 1000));

            let getDate = dateTime => {
                return `${dateTime.getDate()}.${(dateTime.getMonth()+1)}.${dateTime.getFullYear()}`;
            };

            let getTime = dateTime => {
                return `${dateTime.getHours()}.${(dateTime.getMinutes())}`;
            }

            let flatpickrChange = (selectedDates, dateStr, instance) => {
                if ( (new Date(selectedDates).getDate()) === minDateTime.getDate() ){
                    instance.set('minTime', minTime);
                }else{
                    instance.set('minTime', timeAt);
                }
            }


            let minDate = getDate(minDateTime);
            let minTime = getTime(minDateTime);

            let options = {
                enableTime: true,
                dateFormat: "d.m.Y H:i",
                time_24hr: true,
                minDate: minDate,
                minTime: timeAt,
                maxTime: timeTo,
                onChange: flatpickrChange,
                disable: [
                    function(date) {
                        return (date.getDay() === 0 || date.getDay() === 6);
                    }
                ],
            }
            flatpickr(input, options);

            return input;
        };

        let buildInput = (name, id) => {
            let input = document.createElement('input');
            input.classList.add('input-deadline');
            input.type = 'text';
            input.name = name;
            input.id = id;
            return input;
        }

        let createField = (field) => {
            let input = null;

            switch (field.type){
                case 'text':
                    input = buildInput(field.name, field.name);
                    break;
                case 'textarea':
                    input = document.createElement('textarea');
                    input.className = 'service-field-textarea';
                    input.name = field.name;
                    input.id = field.name;
                    input.rows = field.rows || 3;
                    break;
                case 'integer':
                    input = buildInput(field.name, field.name);
                    input.type = 'number';
                    input.step = '1';
                    input.inputMode = 'numeric';
                    if (field.min !== undefined) input.min = field.min;
                    if (field.max !== undefined) input.max = field.max;
                    break;
                case 'employee': {
                    const container = buildDiv('service-field-employee');
                    const canonical = buildInput(field.name, field.name);
                    canonical.type = 'hidden';
                    const selectButton = createRulesElement('button', 'ui-btn ui-btn-light-border', 'Выбрать сотрудника');
                    selectButton.type = 'button';
                    selectButton.id = field.name + '_select';
                    const clearButton = createRulesElement('button', 'ui-btn ui-btn-link', 'Очистить');
                    clearButton.type = 'button';
                    clearButton.hidden = true;
                    let selectedLabel = '';
                    let dialog = null;

                    const setValue = (value, title) => {
                        canonical.value = value ? String(value) : '';
                        selectedLabel = title || '';
                        selectButton.textContent = canonical.value
                            ? (selectedLabel || 'Загрузка имени сотрудника…') : 'Выбрать сотрудника';
                        clearButton.hidden = !canonical.value;
                    };
                    const createDialog = () => {
                        const knownSelection = canonical.value && selectedLabel
                            ? [{id: Number(canonical.value), entityId: 'user', title: selectedLabel}] : [];
                        dialog = new BX.UI.EntitySelector.Dialog({
                            targetNode: selectButton,
                            context: 'services.main.recruitment',
                            multiple: false,
                            enableSearch: true,
                            hideOnSelect: true,
                            entities: [{id: 'user', options: {intranetUsersOnly: true, inviteEmployeeLink: false}}],
                            selectedItems: knownSelection,
                            preselectedItems: canonical.value && !selectedLabel
                                ? [['user', Number(canonical.value)]] : [],
                            events: {
                                'Item:onSelect': event => {
                                    const item = event.getData().item;
                                    setValue(item.getId(), item.getTitle());
                                    canonical.dispatchEvent(new Event('change', {bubbles: true}));
                                },
                                'Item:onDeselect': event => {
                                    if (String(event.getData().item.getId()) === canonical.value) {
                                        setValue('', '');
                                        canonical.dispatchEvent(new Event('change', {bubbles: true}));
                                    }
                                },
                                onLoad: event => {
                                    const selected = event.getTarget().getSelectedItems()
                                        .find(item => String(item.getId()) === canonical.value);
                                    if (selected) setValue(selected.getId(), selected.getTitle());
                                    else if (canonical.value && !selectedLabel) {
                                        selectButton.textContent = 'Выберите сотрудника повторно';
                                    }
                                },
                                onLoadError: () => {
                                    selectButton.textContent = selectedLabel || 'Выбрать сотрудника';
                                    alert('Не удалось загрузить список сотрудников. Откройте выбор сотрудника ещё раз.');
                                },
                            },
                        });
                    };
                    selectButton.addEventListener('click', () => {
                        if (!dialog) createDialog();
                        dialog.show();
                    });
                    clearButton.addEventListener('click', () => {
                        if (dialog) dialog.deselectAll();
                        setValue('', '');
                        canonical.dispatchEvent(new Event('change', {bubbles: true}));
                    });
                    container.serviceCanonicalInput = canonical;
                    container.serviceFocusInput = selectButton;
                    container.setServiceValue = (value, title) => {
                        if (dialog) dialog.destroy();
                        dialog = null;
                        setValue(value, title);
                        if (canonical.value && !selectedLabel) createDialog();
                    };
                    container.getServiceLabel = () => selectedLabel;
                    container.setServiceDisabled = disabled => {
                        clearButton.disabled = disabled;
                        if (disabled && dialog) dialog.hide();
                    };
                    container.destroyServiceField = () => {
                        if (dialog) dialog.destroy();
                    };
                    container.append(canonical, selectButton, clearButton);
                    input = container;
                    break;
                }
                case 'list':
                    input = buildSelect(field.values, field.name);
                    break;
                case 'iblock_elements':
                    console.log(field);
                    input = buildSelect(field.values, field.name, field.multiple);
                    break;
                case 'projectpicker':
                    const inputHidden = buildInput(field.name, field.name);
                    inputHidden.type = 'hidden';
                    const div = buildDiv('container-' + field.name);
                    div.classList.add('projectpicker-div');

                    const tagSelector = new BX.UI.EntitySelector.TagSelector({
                        multiple: false,
                        dialogOptions: {
                            context: 'services.main',
                            multiple: false,
                            tabs: [
                                {
                                    id: 'projectsCustom', title: 'Проекты', itemOrder: { title: 'asc' }
                                }
                            ],
                            items: field.values,
                            enableSearch: false,
                            dropdownMode: true,
                            compactView: false,

                        },

                        events: {
                            onTagAdd: (event) => {
                                inputHidden.value = event.getData().tag.id;
                            },
                            onTagRemove: (event) => {
                                inputHidden.value = 0;
                            },
                            onCreateButtonClick: (event) => {
                                BX.SidePanel.Instance.open("/company/personal/user/1/groups/create/?firstRow=project&refresh=N", {
                                    cacheable: false,
                                    allowChangeHistory: false,
                                    allowChangeTitle: false,
                                    width: 1200,
                                });

                            }
                        },
                        showCreateButton: true,
                    });



                    tagSelector.renderTo(div);

                    BX.addCustomEvent('SidePanel.Slider:onMessage', (event)=>{
                        const data = event.getData();

                        if ( data.projects ){
                            tagSelector.addTag({
                                id: data.projects[0].id,
                                title: data.projects[0].title,
                                entityId: 'project',
                            });
                        }
                    })



                    div.insertAdjacentElement('afterbegin', inputHidden);

                    const hint = document.createElement('span');
                    hint.classList.add('projectpicker-hint');
                    hint.innerText = 'Задача будет создана в выбранном проекте';
                    div.insertAdjacentElement('beforeend', hint);

                    input = div;
                    break;
            }

            if (input) {
                input.setAttribute('aria-required', field.required ? 'true' : 'false');
                if (field.placeholder) input.setAttribute('placeholder', field.placeholder);
            }
            return input;
        }

        serviceBlock = buildDiv('container-grid');

        let deadlineDiv;

        let serviceDiv = buildDiv('container-grid-field');
        let serviceLabel = buildLabel('Тип услуги', 'select-service');
        let serviceSelect = buildSelect(services, 'select-service');
        serviceSelect.addEventListener('change', () => {
            let index = (serviceSelect.selectedIndex-1);
            if (!services[index]) {
                renderServiceRules([]);
                resetServiceForm();
                return;
            }
            fullServiceDescription(services[index].description, services[index].leadTime, services[index].timeAt, services[index].timeTo);
            selectServiceForm(services[index], createField);
            renderServiceRules(services[index].hasServiceRules ? serviceRules : [], activeFieldSchema);

            if ( extraFieldsConfig.elements.length ){
                extraFieldsConfig.elements.forEach( element => {

                    element.fields.forEach(field => {
                        let existedField = document.querySelector('#' + field.name);
                        if ( existedField ){
                            existedField.closest('.container-grid-field').remove();
                        }
                    });

                    if ( element.values.some((elementId) => String(elementId) === String(services[index].value) ) ){
                        element.fields.forEach(field => {
                            let div = buildDiv('container-grid-field');
                            let label = buildLabel(field.label + (field.required ? ' *' : ''), field.name);

                            let input = createField(field);

                            div.insertAdjacentElement('afterbegin', input);
                            div.insertAdjacentElement('afterbegin', label);
                            serviceBlock.insertAdjacentElement('beforeend', div);

                        });
                    }
                });
            }

            if ( deadlineDiv ){
                deadlineDiv.remove();
            }

            deadlineDiv = buildDiv('container-grid-field');
            let deadlineLabel = buildLabel('Крайний срок', 'select-department');
            let deadlineInput = buildDeadlineInput(services[index].deadline, services[index].timeAt, services[index].timeTo);
            deadlineDiv.insertAdjacentElement('afterbegin', deadlineInput);
            deadlineDiv.insertAdjacentElement('afterbegin', deadlineLabel);

            serviceBlock.insertAdjacentElement('beforeend', deadlineDiv);


            if (serviceSelect.value == 240637){
                departmentDiv.hidden = true;
                priorityDiv.hidden = true;
                deadlineDiv.hidden = true;

                marketingLogic = 1;
            }else{
                departmentDiv.hidden = false;
                priorityDiv.hidden = false;
                deadlineDiv.hidden = false;

                marketingLogic = 0;
            }

        });
        serviceDiv.insertAdjacentElement('afterbegin', serviceSelect);
        serviceDiv.insertAdjacentElement('afterbegin', serviceLabel);
        serviceBlock.insertAdjacentElement('beforeend', serviceDiv);

        let departmentDiv = buildDiv('container-grid-field');
        let departmentLabel = buildLabel('Ваше подразделение', 'select-department');
        let departmentSelect = buildSelect(departments, 'select-department');
        departmentDiv.insertAdjacentElement('afterbegin', departmentSelect);
        departmentDiv.insertAdjacentElement('afterbegin', departmentLabel);
        serviceBlock.insertAdjacentElement('beforeend', departmentDiv);

        let priorityDiv = buildDiv('container-grid-field');
        let priorityLabel = buildLabel('Приоритет', 'select-priority');
        let prioritySelect = buildSelect(priorities, 'select-priority');
        priorityDiv.insertAdjacentElement('afterbegin', prioritySelect);
        priorityDiv.insertAdjacentElement('afterbegin', priorityLabel);
        serviceBlock.insertAdjacentElement('beforeend', priorityDiv);

        if ( extraFieldsConfig.sections.length ){
            extraFieldsConfig.sections.forEach( section => {
                if ( section.values.some((sectionId) => String(sectionId) === String(parentDepartment) ) ){

                    section.fields.forEach(field => {
                        let div = buildDiv('container-grid-field');
                        let label = buildLabel(field.label + (field.required ? ' *' : ''), field.name);

                        let input = createField(field);

                        div.insertAdjacentElement('afterbegin', input);
                        div.insertAdjacentElement('afterbegin', label);
                        serviceBlock.insertAdjacentElement('beforeend', div);

                    });
                }
            });
        }

        responsibleSelects[responsibleSelects.length-1].insertAdjacentElement('afterend', serviceBlock);
    };

    let selectAddHandler = ( select ) => {

        let prepareAjax = () => {
            isAjax = true;
            responsibleSelects.forEach(select => {
                select.disabled = true;
            });
        };

        let finishAjax = () => {
            isAjax = false;
            responsibleSelects.forEach(select => {
                select.disabled = false;
            });
        };

        let isAjax = false;
        select.addEventListener('change', async () => {
            if ( isAjax ) return false;
            prepareAjax();
            deleteServiceBlock();

            let data = new FormData();
            data.append('department', select.value);

            try {
                let response = await BX.ajax.runComponentAction(servicesComponentName, 'select', {
                    mode: 'class',
                    data: data,
                });


                if ( response.data.type === 'department' ){

                    deleteServiceBlock();
                    deleteExcessResponsibleBlocks(response.data.depth);
                    createResponsibleBlock(response.data.departments, response.data.depth);

                }else if (response.data.type === 'service'){

                    deleteServiceBlock();
                    deleteExcessResponsibleBlocks(Number(select.dataset.depth) + 1);

                    if (response.data.services.length !== 0){
                        createServiceBlock(response.data.services, select.value, response.data.serviceRules || []);
                    }else{
                        alert('Данное подразделение не имеет активных услуг!');
                    }
                }
            } catch(err) {
                alert('Произошла неожиданная ошибка! Пожалуйста, попробуйте позже!');
            }

            finishAjax();
        });


    };

    let formHandler = () => {
        let form = document.querySelector('#service-form');
        let isAjax = false;
        if ( form ){
            form.addEventListener('submit', async e => {
                e.preventDefault();
                if (isAjax) return;
                let department = form.querySelector('#select-department');
                let priority = form.querySelector('#select-priority');
                let service = form.querySelector('#select-service');
                let deadline = form.querySelector('#input-deadline');
                let description = form.querySelector('#description');

                if ( !marketingLogic ){
                    if ( !department || !priority || !service || !deadline ){
                        alert('Пожалуйста, выберите Отдел-испольнитель услуги!');
                        return false;
                    }


                    if ( !department.value ){
                        alert('Пожалуйста, выберите ваше подразделение!');
                        return false;
                    }

                    if ( !priority.value ){
                        alert('Пожалуйста, выберите приоритет вашей задачи!');
                        return false;
                    }

                    if ( !deadline.value ){
                        alert('Пожалуйста, установите крайний срок для задачи!');
                        return false;
                    }

                    for ( let field of extraFieldsMap ){
                        let fieldForm = form.querySelector('#' + field.id);
                        if ( fieldForm && field.required && (!fieldForm.value.trim()
                            || (field.type === 'projectpicker' && fieldForm.value === '0')) ){
                            alert('Пожалуйста, заполните поле "' + field.label + '"!');
                            return false;
                        }
                    }


                }

                if ( !service || !service.value ){
                    alert('Пожалуйста, выберите тип услуги!');
                    return false;
                }



                const fieldErrors = validateRequirementFields();
                if (Object.keys(fieldErrors).length) {
                    showRequirementErrors(fieldErrors);
                    return false;
                }

                const submittedServiceKey = activeServiceKey;
                const submittedSchema = activeFieldSchema;
                const recruitment = isRecruitmentSchema(submittedSchema);
                let recruitmentRequest = null;
                if (recruitment) {
                    try {
                        recruitmentRequest = getRecruitmentRequest(submittedServiceKey);
                        if (recruitmentRequest.status !== 'DRAFT') {
                            updateRecruitmentState();
                            alert('Эта заявка уже отправлена. Проверьте сообщение под формой.');
                            return;
                        }
                        // Verify persistence before any task can be created.
                        saveRecruitmentRequest(submittedServiceKey, recruitmentRequest);
                    } catch (error) {
                        alert('Не удалось подготовить отправку заявки. ' + error.message);
                        return;
                    }
                }
                isAjax = true;

                try {
                    let extraDescription = '';
                    extraFieldsMap.forEach(field => {
                        let fieldForm = form.querySelector('#' + field.id);
                        if ( fieldForm && !fieldsForConcatTitle.includes(field.id) ){
                            if ( fieldForm.tagName === 'SELECT' && fieldForm.multiple ){
                                extraDescription += field.label + ": " + Array.from(fieldForm.options).filter(o => o.selected).map(o => o.value).join('; ') + "\n\n";

                            }else{
                                extraDescription += field.label + ": " + fieldForm.value + "\n\n";
                            }
                        }
                    });

                    let data = new FormData(form);
                    data.set('description', submittedSchema ? description.value
                        : description.value + (extraDescription ? '\n\n' + extraDescription : ''));
                    data.set('extra_fields_description', extraDescription);
                    if (submittedSchema) data.set('service_fields_version', submittedSchema.version);
                    if (recruitment) data.set('recruitment_request_key', recruitmentRequest.key);

                    extraFieldsMap.forEach(field => {
                        let fieldForm = form.querySelector('#' + field.id);
                        if ( fieldForm ){
                            if ( field.type === 'projectpicker' ){
                                data.set('projectpickerField', field.id)
                            }
                        }
                    });

                    data.set('fields-for-concat-title', fieldsForConcatTitle)

                    if (recruitment) {
                        recruitmentRequest.status = 'SENDING';
                        saveRecruitmentRequest(submittedServiceKey, recruitmentRequest);
                    }
                    formRequestPending = true;
                    updateRecruitmentState();
                    let response = await BX.ajax.runComponentAction(servicesComponentName, 'send', {
                        mode: 'class',
                        data: data,
                    });

                    const result = response && response.data;
                    if (showServerValidation(result)) {
                        if (recruitment) {
                            recruitmentRequest.status = 'DRAFT';
                            saveRecruitmentRequest(submittedServiceKey, recruitmentRequest);
                        }
                        return;
                    }

                    if (recruitment) {
                        handleRecruitmentResult(submittedServiceKey, recruitmentRequest, result);
                    } else if (result && /^[1-9]\d*$/.test(String(result.userId))
                        && /^[1-9]\d*$/.test(String(result.taskId))) {
                        location.href = `/company/personal/user/${result.userId}/tasks/task/view/${result.taskId}/`;
                    } else {
                        alert('Сервер не вернул номер созданной задачи. Обратитесь к администратору.');
                    }

                } catch(err) {
                    if (showServerValidation(err && err.data)) {
                        if (recruitment) {
                            recruitmentRequest.status = 'DRAFT';
                            try {
                                saveRecruitmentRequest(submittedServiceKey, recruitmentRequest);
                            } catch (error) {
                                alert('Не удалось сохранить состояние черновика. ' + error.message);
                            }
                        }
                    } else {
                        const messages = err && Array.isArray(err.errors)
                            ? err.errors.map(error => error.message).filter(Boolean) : [];
                        const message = messages.length ? messages.join('\n')
                            : 'Не удалось получить подтверждение отправки. Проверьте соединение.';
                        alert(message + (recruitment ? '\nНажмите «Проверить отправку», чтобы узнать результат '
                            + 'перед продолжением работы с заявкой.' : ''));
                    }
                } finally {
                    isAjax = false;
                    formRequestPending = false;
                    updateRecruitmentState();
                }
            });

        }
    };

    let taskFilesCounter = () => {
        let taskFilesField = document.querySelector('#task-files');
        let taskFilesCounter = document.querySelector('#task-files-counter');

        if ( taskFilesField ){
            taskFilesField.addEventListener('change', () => {
                taskFilesCounter.innerText = "[" + taskFilesField.files.length + "]";
            })
        }

    }

    document.addEventListener('DOMContentLoaded', () => {

        flatpickr.localize(flatpickr.l10ns.ru);

        let mainSelect = document.querySelector('#select-responsible-department');

        descriptionField = document.querySelector('#description');
        if (descriptionField) {
            unassignedDescription = descriptionField.value;

        }

        if ( mainSelect ) {
            responsibleSelects.push(mainSelect);
            selectAddHandler(mainSelect);

            serviceDescriptionWrapper = document.querySelector('.service__description__wrapper');
            serviceDescription = document.querySelector('.service__description');
            if (serviceDescription && serviceDescriptionWrapper && typeof ResizeObserver !== 'undefined') {
                new ResizeObserver(() => {
                    if (serviceBlock && serviceDescription.innerHTML) {
                        serviceDescriptionWrapper.style.height = serviceDescription.scrollHeight + 47 + 'px';
                    }
                }).observe(serviceDescription);
            }
            const generalBody = document.querySelector('.page__description__wrapper > .page__description');
            const generalTitle = generalBody && generalBody.previousElementSibling;
            if (generalTitle && generalTitle.classList.contains('column__title')) {
                generalRulesNodes = [generalTitle, generalBody];
            }

            formHandler();

            extraFieldsConfig.sections.forEach( section => {
                section.fields.forEach(field => {
                    extraFieldsMap.push({
                        id: field.name,
                        required: field.required,
                        label: field.label,
                        type: field.type
                    });

                    if (field.type === "text" && field?.concat_title) {
                        fieldsForConcatTitle.push(field.name)
                    }
                })
            } );

            extraFieldsConfig.elements.forEach( section => {
                section.fields.forEach(field => {
                    extraFieldsMap.push({
                        id: field.name,
                        required: field.required,
                        label: field.label,
                        type: field.type
                    });

                    if (field.type === "text" && field?.concat_title) {
                        fieldsForConcatTitle.push(field.name)
                    }
                })
            } );

            taskFilesCounter();
        }

    });
}
