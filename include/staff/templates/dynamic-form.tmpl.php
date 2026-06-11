<?php
global $thisstaff;

// Gazelec customization: Control the padlock feature for clientnum field in admin edits.
// The value is taken from ost-config.php if defined, otherwise defaults to true.
if (!defined('CLIENTNUM_PADLOCK_ENABLED')) {
    define('CLIENTNUM_PADLOCK_ENABLED', true);
}

$isCreate = (isset($options['mode']) && $options['mode'] == 'create');

if (isset($options['entry']) && $options['mode'] == 'edit'
    && $_POST
    && ($_POST['forms'] && !in_array($options['entry']->getId(), $_POST['forms']))
)
    return;

if (isset($options['entry']) && $options['mode'] == 'edit') { ?>
<tbody>
<?php } ?>
    <tr><td style="width:<?php echo $options['width'] ?: 150;?>px;"></td><td></td></tr>
<?php
// Keep up with the entry id in a hidden field to decide what to add and
// delete when the parent form is submitted
if (isset($options['entry']) && $options['mode'] == 'edit') { ?>
    <input type="hidden" name="forms[]" value="<?php
        echo $options['entry']->getId(); ?>" />
<?php } ?>
<?php if ($form->getTitle()) { ?>
    <tr><th colspan="2">
        <em>
<?php if ($options['mode'] == 'edit') { ?>
        <div class="pull-right">
    <?php if ($options['entry']
                && $options['entry']->getDynamicForm()->get('type') == 'G') { ?>
            <a href="#" title="Delete Entry" onclick="javascript:
                $(this).closest('tbody').remove();
                return false;"><i class="icon-trash"></i></a>&nbsp;
    <?php } ?>
            <i class="icon-sort" title="Drag to Sort"></i>
        </div>
<?php } ?>
        <strong><?php echo Format::htmlchars($form->getTitle()); ?></strong>:
        <div><?php echo Format::display($form->getInstructions()); ?></div>
        </em>
        <?php
        if ($form->getNotice())
            echo sprintf('<p id="msg_warning">%s</p>',
                Format::htmlchars($form->getNotice()));
        ?>
    </th></tr>
    <?php
    }
    foreach ($form->getFields() as $field) {
        try {
            if (!$field->isEnabled())
                continue;
        }
        catch (Exception $e) {
            // Not connected to a DynamicFormField
        }

        // Gazelec: compute once whether this clientnum field needs padlock protection
        // (read-only + explicit unlock) in staff edit context. We do this early so we
        // can force the disabled attribute on the input during render.
        $__clientnum_protect = false;
        if (CLIENTNUM_PADLOCK_ENABLED
                && $field->getLocal('name') === 'clientnum'
                && !$isCreate
                && $field->getClean()
                && (!empty($options['staff']) || !empty($GLOBALS['thisstaff']))) {
            $__clientnum_protect = true;
        }

        ?>
        <tr><?php if ($field->isBlockLevel()) { ?>
                <td colspan="2">
                <?php
            }
            else { ?>
                <td class="multi-line <?php if ($field->isRequiredForStaff() || $field->isRequiredForClose()) echo 'required';
                ?>" style="min-width:120px;" <?php if ($options['width'])
                    echo "width=\"{$options['width']}\""; ?>>
                <?php echo Format::htmlchars($field->getLocal('label')); ?>:</td>
                <td><div style="position:relative"><?php
            }

            if ($field->isEditableToStaff() || $isCreate) {
                // Gazelec: always tag the clientnum field on the impl that actually
                // renders, so the central enforcer + hidden-mirror logic in
                // header.inc.php can find it. DynamicFormEntry::create() sets this on
                // the ORM field, but the marker is lost before getImpl() rebuilds the
                // impl (the answer construction builds the impl first), which is why
                // the create modal previously rendered the field with no marker and
                // the reserved number was never submitted.
                if ($field->getLocal('name') === 'clientnum') {
                    if (!isset($field->ht['attributes']) || !is_array($field->ht['attributes'])) {
                        $field->ht['attributes'] = [];
                    }
                    $field->ht['attributes']['data-clientnum-field'] = 'true';
                }
                if ($__clientnum_protect) {
                    // Force disabled + reliable data marker at render time for the edit-user
                    // dialog (ticket details -> user -> edit icon). This ensures the input
                    // is non-editable in the initial HTML, unlike the create path which uses
                    // configuration['disabled'] in DynamicFormEntry::create().
                    if (!isset($field->ht['attributes']) || !is_array($field->ht['attributes'])) {
                        $field->ht['attributes'] = [];
                    }
                    $field->ht['attributes']['data-clientnum-field'] = 'true';
                    $field->ht['attributes']['disabled'] = 'disabled';
                }
                $field->render($options); ?>
                <?php if (!$field->isBlockLevel() && $field->isRequiredForStaff()) { ?>
                    <span class="error">*</span>
                <?php
                }
                if ($field->isStorable() && ($a = $field->getAnswer()) && $a->isDeleted()) {
                    ?><a class="action-button float-right danger overlay" title="Delete this data"
                        href="#delete-answer"
                        onclick="javascript:if (confirm('<?php echo __('You sure?'); ?>'))
                            $.ajax({
                                url: 'ajax.php/form/answer/'
                                    +$(this).data('entryId') + '/' + $(this).data('fieldId'),
                                type: 'delete',
                                success: $.proxy(function() {
                                    $(this).closest('tr').fadeOut();
                                }, this)
                            });
                        return false;"
                        data-field-id="<?php echo $field->getAnswer()->get('field_id');
                    ?>" data-entry-id="<?php echo $field->getAnswer()->get('entry_id');
                    ?>"> <i class="icon-trash"></i> </a></div><?php
                }
                if ($a && !$a->getValue() && $field->isRequiredForClose() && get_class($field) != 'BooleanField') {
    ?><i class="icon-warning-sign help-tip warning"
        data-title="<?php echo __('Required to close ticket'); ?>"
        data-content="<?php echo __('Data is required in this field in order to close the related ticket'); ?>"
    /></i><?php
                }
                if ($field->get('hint') && !$field->isBlockLevel()) { ?>
                    <br /><em style="color:gray;display:inline-block"
                    <?php
                        if (in_array($field->getLocal('name'), ['clientnum','email'])) {
                            echo 'data-copy-to-input="true" ';
                        }
                    ?>
                    ><?php
                        echo Format::viewableImages($field->getLocal('hint')); ?></em>
                <?php
                }
                foreach ($field->errors() as $e) { ?>
                    <div class="error"><?php echo Format::htmlchars($e); ?></div>
                <?php }

                // Gazelec customization: Padlock for clientnum field in admin edits.
                // Uses pre-computed flag so the input could be forced disabled during render.
                // Only admins (isAdmin()) get the clickable padlock; other staff see the
                // field as read-only with no unlock path.
                if ($__clientnum_protect && $thisstaff && $thisstaff->isAdmin()) {
                    ?>
                    <span class="clientnum-padlock" data-clientnum-padlock="1" style="margin-left: 8px; cursor: pointer; color: #888;" title="Click padlock to allow editing of Num usager for this save">
                        <i class="icon-lock"></i>
                    </span>
                <?php }
            } else {
                $val = '';
                if ($field->value)
                    $val = $field->display($field->value);
                elseif (($a= $field->getAnswer()))
                    $val = $a->display();

                echo $val;
            }?>
            </div></td>
        </tr>
    <?php }
if (isset($options['entry']) && $options['mode'] == 'edit') { ?>
</tbody>
<?php } ?>
