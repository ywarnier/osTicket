<div id="the-lookup-form">
<h3 class="drag-handle"><?php echo $info['title']; ?></h3>
<b><a class="close" href="#"><i class="icon-remove-circle"></i></a></b>
<hr/>
<?php
if (!isset($info['lookup']) || $info['lookup'] !== false) { ?>
<div><p id="msg_info"><i class="icon-info-sign"></i>&nbsp; <?php echo
    $thisstaff->hasPerm(User::PERM_CREATE)
    ? __('Search existing users or add a new user.')
    : __('Search existing users.');
?></p></div>
<div style="margin-bottom:10px;">
    <input type="text" class="search-input" style="width:100%;"
    placeholder="<?php echo __('Search by email, phone or name'); ?>" id="user-search"
    autofocus autocorrect="off" autocomplete="off"/>
</div>
<?php
}

if ($info['error']) {
    echo sprintf('<p id="msg_error">%s</p>', $info['error']);
} elseif ($info['warn']) {
    echo sprintf('<p id="msg_warning">%s</p>', $info['warn']);
} elseif ($info['msg']) {
    echo sprintf('<p id="msg_notice">%s</p>', $info['msg']);
} ?>
<div id="selected-user-info" style="display:<?php echo $user ? 'block' :'none'; ?>;margin:5px;">
<form method="post" class="user" action="<?php echo $info['action'] ?  $info['action'] : '#users/lookup'; ?>">
    <input type="hidden" id="user-id" name="id" value="<?php echo $user ? $user->getId() : 0; ?>"/>
<?php
if ($user) { ?>
    <div class="avatar pull-left" style="margin: 0 10px;">
    <?php echo $user->getAvatar(); ?>
    </div>
<?php
}
else { ?>
    <i class="icon-user icon-4x pull-left icon-border"></i>
<?php
}
if ($thisstaff->hasPerm(User::PERM_CREATE)) { ?>
    <a class="action-button pull-right" style="overflow:inherit"
        id="unselect-user"  href="#"><i class="icon-remove"></i>
        <?php echo __('Add New User'); ?></a>
<?php }
if ($user) { ?>
    <div><strong id="user-name"><?php echo Format::htmlchars($user->getVar('firstname').' '.$user->getName()->getOriginal()); ?></strong></div>
    <!--div>&lt;<span id="user-email"><?php echo $user->getEmail(); ?></span>&gt;</div-->
    <?php
    /* if ($org=$user->getOrganization()) { ?>
    <div><span id="user-org"><?php echo $org->getName(); ?></span></div>
    <?php
    }*/ ?>
    <table style="margin-top: 1em;">
<?php foreach ($user->getDynamicData() as $entry) { ?>
    <tr><td colspan="2" style="border-bottom: 1px dotted black"><strong><?php
         echo $entry->getTitle(); ?></strong></td></tr>
<?php foreach ($entry->getAnswers() as $a) { ?>
    <tr style="vertical-align:top"><td style="width:30%;border-bottom: 1px dotted #ccc"><?php echo Format::htmlchars($a->getField()->get('label'));
         ?>:</td>
    <td style="border-bottom: 1px dotted #ccc"><?php echo $a->display(); ?></td>
    </tr>
<?php }
}
?>
</table>
<?php } ?>
    <div class="clear"></div>
    <hr>
    <p class="full-width">
        <span class="buttons pull-left">
        </span>
        <span class="buttons pull-right">
            <input type="submit" value="<?php echo __('OK'); ?>">
            <input type="button" name="cancel" class="close"  value="<?php
            echo __('Cancel'); ?>">
        </span>
     </p>
</form>
</div>
<div id="new-user-form" style="display:<?php echo $user ? 'none' :'block'; ?>;">
<?php if ($thisstaff->hasPerm(User::PERM_CREATE)) { ?>
<form method="post" class="user" action="<?php echo $info['action'] ?: '#users/lookup/form'; ?>">
    <table width="100%" class="fixed">
    <?php
        $form = $form ?: UserForm::getInstance();
        $form->render(['staff' => true, 'title' => __('Create New User'), 'mode' => 'create']); ?>
    </table>
    <hr>
    <p class="full-width">
        <span class="buttons pull-left">
            <input type="reset" value="<?php echo __('Reset'); ?>">
            <input type="button" name="cancel" class="<?php echo $user ?  'cancel' : 'close' ?>"  value="<?php echo __('Cancel'); ?>">
        </span>
        <span class="buttons pull-right">
            <input type="submit" value="<?php echo __('Add User'); ?>">
        </span>
     </p>
</form>
<?php }
else { ?>
    <hr/>
    <p class="full-width">
        <span class="buttons pull-left">
            <input type="button" name="cancel" class="<?php echo $user ?  'cancel' : 'close' ?>"  value="<?php echo __('Cancel'); ?>">
        </span>
     </p>
<?php } ?>
</div>
<div class="clear"></div>
</div>
<script type="text/javascript">
$(function() {
    var last_req;
    $('#user-search').typeahead({
        source: function (typeahead, query) {
            if (last_req) last_req.abort();
            last_req = $.ajax({
                url: "ajax.php/users<?php
                    echo $info['lookup'] ? "/{$info['lookup']}" : '' ?>?q="+encodeURIComponent(query),
                dataType: 'json',
                success: function (data) {
                    typeahead.process(data);
                }
            });
        },
        onselect: function (obj) {
            $('#the-lookup-form').load(
                '<?php echo $info['onselect']? $info['onselect']: "ajax.php/users/select/"; ?>'+encodeURIComponent(obj.id)
            );
        },
        property: "/bin/true"
    });

    $('a#unselect-user').click( function(e) {
        e.preventDefault();
        $("#msg_error, #msg_notice, #msg_warning").fadeOut();
        $('div#selected-user-info').hide();

        var $newForm = $('div#new-user-form');

        // Fetch a fresh clientnum as early as possible
        var promise = $.getJSON('ajax.php/users/next-clientnum');

        $newForm.fadeIn({
            start: function() { $('#user-search').focus(); },
            complete: function() {
                promise.done(function(data) {
                    if (!data || !data.clientnum) return;

                    var num = data.clientnum;

                    // Prefer the properly marked field
                    var $field = $newForm.find('input[data-clientnum-field="true"]').first();

                    if (!$field.length) {
                        // Fallback for older renders: first disabled input that looks numeric/empty
                        $field = $newForm.find('input[disabled]').filter(function() {
                            var v = $(this).val() || '';
                            return v === '' || /^\d{5,}$/.test(v);
                        }).first();
                    }

                    if ($field.length) {
                        $field.val(num);
                        // Set disabled synchronously, right next to the value
                        $field.prop('disabled', true);
                        $field.attr('disabled', 'disabled');
                        if ($field[0]) {
                            $field[0].disabled = true;
                        }
                        console.log('[gazelc clientnum] Filled + disabled synchronously', $field[0]);

                        // Also keep the derived fake email (@example.com) in sync with the new clientnum
                        var $emailField = $newForm.find('input[data-clientnum-derived-email="true"]').first();
                        if ($emailField.length) {
                            $emailField.val(num + '@example.com');
                            console.log('[gazelc clientnum] Updated derived email field synchronously');
                        } else {
                            // Fallback: find any input that currently holds a clientnum-based fake email
                            $newForm.find('input').each(function() {
                                var $inp = $(this);
                                if (($inp.val() || '').match(/^\d{5,}@example\.com$/)) {
                                    $inp.val(num + '@example.com');
                                    console.log('[gazelc clientnum] Updated derived email via fallback');
                                }
                            });
                        }
                    } else {
                        // Stronger fallback for clientnum
                        $newForm.find('input').each(function() {
                            var $inp = $(this);
                            var name = ($inp.attr('name') || '').toLowerCase();
                            var id   = ($inp.attr('id') || '').toLowerCase();
                            var currentVal = $inp.val() || '';

                            if ($inp.prop('disabled') ||
                                name.indexOf('clientnum') > -1 ||
                                id.indexOf('clientnum') > -1 ||
                                /^\d{5,}$/.test(currentVal)) {

                                $inp.val(num);
                                $inp.prop('disabled', true);
                                $inp.attr('disabled', 'disabled');
                                if ($inp[0]) $inp[0].disabled = true;
                                console.log('[gazelc clientnum] Filled + disabled via fallback', $inp[0]);
                                $field = $inp;
                            }
                        });
                    }

                    // Do not rely on the global enforcer for the reveal path — it can introduce delay via the observer.
                });
            }
        });

        return false;
     });

    $(document).on('click', 'form.user input.cancel', function (e) {
        e.preventDefault();
        $('div#new-user-form').hide();
        $('div#selected-user-info').fadeIn({start: function(){ $('#user-search').focus(); }});
        return false;
     });
});
</script>
