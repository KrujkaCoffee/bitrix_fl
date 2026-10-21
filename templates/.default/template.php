<?php if (!defined('B_PROLOG_INCLUDED') || B_PROLOG_INCLUDED !== true) die(); ?>

<div class="columns-wrapper">
    <form class="windowContainer js-form" id="service-form">
        <div class="column__title">
            Подача заявки
        </div>

        <label for="select-responsible-department">Отдел</label>
        <select id="select-responsible-department" class="js-select" data-depth="1">
            <option value="" disabled selected>Выберите отдел</option>
            <?php foreach ( $arResult['responsible-departments'] as $department ): ?>
                <option value="<?php echo $department['value'] ?>"><?php echo $department['label'] ?></option>
            <?php endforeach; ?>
        </select>
        
        <label for="description">Описание проблемы</label>
        <textarea id="description" name="description"></textarea>

        <label style="margin-left: 0; margin-bottom: 10px;" class="ui-ctl ui-ctl-file-link">
            <input type="file" class="ui-ctl-element" multiple name="task_files[]" id="task-files">
            <div class="ui-ctl-label-text">Добавить файлы <span id="task-files-counter">[0]</span></div>
        </label>

        <input class="ui-btn ui-btn-lg ui-btn-primary js-submit" style="margin: 0" type="submit" value="Отправить">
    </form>
    <div class="page__description__wrapper">
        <div class="service__description__wrapper" style="height: 0">
            <div class="column__title">
                Описание услуги
            </div>
            <div class="service__description"></div>
        </div>
        <div class="column__title">
            Общие правила
        </div>
        <div class="page__description">
            На основании вашей заявки будет <b>создана задача</b>, где вы сразу сможете увидеть исполнителя и срок выполнения с учётом его режима работы. В комментариях можно писать дополнительную информацию.
            <br><br>
            <b>Режим работы</b> задан индивидуально для каждой услуги. Увидеть его можно после выбора соответствующей услуги, там же вы сможете увидеть ожидаемое время выполнения заявки.
            <br><br>
            Пожалуйста, <b>предоставьте всю информацию</b>, необходимую для ответа. Необходимость уточнять дополнительные данные может увеличить время выполнения заявки в несколько раз.
            <br><br>
            <b>Будьте вежливы</b>. Помните о профессиональном этикете и поддерживаейте благоприятную атмосферу в компании.
        </div>
    </div>
</div>

<script>
    let servicesComponentName = "<?php echo $this->getComponent()->getName(); ?>";

    let priorities = <?php echo $arResult['priorities']; ?>;
    let departments = <?php echo $arResult['departments']; ?>;
    let extraFieldsConfig = <?php echo $arResult['extraFieldsConfig']; ?>;
</script>
