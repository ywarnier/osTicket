<?php
header("Content-Type: text/html; charset=UTF-8");
header("Content-Security-Policy: frame-ancestors ".$cfg->getAllowIframes()."; script-src 'self' 'unsafe-inline' 'unsafe-eval'; object-src 'none'");

$title = ($ost && ($title=$ost->getPageTitle()))
    ? $title : ('osTicket :: '.__('Staff Control Panel'));

if (!isset($_SERVER['HTTP_X_PJAX'])) { ?>
<!DOCTYPE HTML PUBLIC "-//W3C//DTD HTML 4.01//EN" "http://www.w3.org/TR/html4/strict.dtd">
<html<?php
if (($lang = Internationalization::getCurrentLanguage())
        && ($info = Internationalization::getLanguageInfo($lang))
        && (@$info['direction'] == 'rtl'))
    echo ' dir="rtl" class="rtl"';
if ($lang) {
    echo ' lang="' . Internationalization::rfc1766($lang) . '"';
}

// Dropped IE Support Warning
if (osTicket::is_ie())
    $ost->setWarning(__('osTicket no longer supports Internet Explorer.'));
?>>
<head>
    <meta http-equiv="content-type" content="text/html; charset=UTF-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge,chrome=1">
    <meta http-equiv="cache-control" content="no-cache" />
    <meta http-equiv="pragma" content="no-cache" />
    <meta http-equiv="x-pjax-version" content="<?php echo GIT_VERSION; ?>">
    <title><?php echo Format::htmlchars($title); ?></title>
    <!--[if IE]>
    <style type="text/css">
        .tip_shadow { display:block !important; }
    </style>
    <![endif]-->
    <script type="text/javascript" src="<?php echo ROOT_PATH; ?>js/jquery-3.7.0.min.js"></script>
    <link rel="stylesheet" href="<?php echo ROOT_PATH ?>css/thread.css" media="all">
    <link rel="stylesheet" href="<?php echo ROOT_PATH ?>scp/css/scp.css" media="all">
    <link rel="stylesheet" href="<?php echo ROOT_PATH; ?>css/redactor.css" media="screen">
    <link rel="stylesheet" href="<?php echo ROOT_PATH ?>css/typeahead.css" media="screen">
    <link type="text/css" href="<?php echo ROOT_PATH; ?>css/ui-lightness/jquery-ui-1.13.2.custom.min.css"
         rel="stylesheet" media="screen" />
    <link rel="stylesheet" href="<?php echo ROOT_PATH ?>css/jquery-ui-timepicker-addon.css" media="all">
    <link type="text/css" rel="stylesheet" href="<?php echo ROOT_PATH; ?>css/font-awesome.min.css">
    <!--[if IE 7]>
    <link rel="stylesheet" href="<?php echo ROOT_PATH; ?>css/font-awesome-ie7.min.css">
    <![endif]-->
    <link type="text/css" rel="stylesheet" href="<?php echo ROOT_PATH ?>scp/css/dropdown.css">
    <link type="text/css" rel="stylesheet" href="<?php echo ROOT_PATH; ?>css/loadingbar.css"/>
    <link type="text/css" rel="stylesheet" href="<?php echo ROOT_PATH; ?>css/flags.css">
    <link type="text/css" rel="stylesheet" href="<?php echo ROOT_PATH; ?>css/select2.min.css">
    <link type="text/css" rel="stylesheet" href="<?php echo ROOT_PATH; ?>css/rtl.css"/>
    <link type="text/css" rel="stylesheet" href="<?php echo ROOT_PATH ?>scp/css/translatable.css"/>
    <!-- Favicons -->
    <link rel="icon" type="image/png" href="<?php echo ROOT_PATH ?>images/oscar-favicon-32x32.png" sizes="32x32" />
    <link rel="icon" type="image/png" href="<?php echo ROOT_PATH ?>images/oscar-favicon-16x16.png" sizes="16x16" />

    <?php
    if($ost && ($headers=$ost->getExtraHeaders())) {
        echo "\n\t".implode("\n\t", $headers)."\n";
    }
    ?>
    <script>
      // Code to hack into the user creation form (and possibly others in the future) to pre-load the value of a field
      // with the contents of the "hint" attribute to this field.
      // This only exists because OSTicket makes it very impractical to simply load a default value into a field.
      document.addEventListener("DOMContentLoaded", function() {
        window.copyEmToInput = function copyEmToInput() {
          // Select only <em> elements with the data-copy-to-input attribute inside the popup
          var emElements = document.querySelectorAll('#popup em[data-copy-to-input="true"]');

          emElements.forEach(function(em) {
            // Navigate previous siblings to find the corresponding <input>
            var previousSibling = em.previousElementSibling;
            while (previousSibling && previousSibling.tagName !== 'INPUT') {
              previousSibling = previousSibling.previousElementSibling;
            }

            // If an <input> was found, copy the content of <em> to it and remove the <em>
            if (previousSibling && previousSibling.tagName === 'INPUT') {
              previousSibling.value = em.textContent;
              em.remove();
            }
          });
        }

        // Enforce that the clientnum field is disabled once it has a value.
        // This makes it harder for staff to accidentally (or easily) tamper with the prefilled number.
        window.enforceClientnumDisabled = function enforceClientnumDisabled() {
          var acted = false;
          // Only enforce on fields that have not been explicitly unlocked via the padlock in this edit session.
          document.querySelectorAll('#popup input[data-clientnum-field="true"]:not([data-clientnum-unlocked])').forEach(function(input) {
            if (input.value) {
              input.disabled = true;
              // Ensure the attribute is present in the DOM (helps with some rendering paths)
              if (!input.hasAttribute('disabled')) {
                input.setAttribute('disabled', 'disabled');
              }
              acted = true;
            }
          });
          if (acted) {
            console.log('[gazelc clientnum] enforceClientnumDisabled acted on one or more fields');
          }
        }

        // Wire up Gazelec clientnum padlock (click-to-unlock) icons.
        // We centralize this here (instead of inline <script> after the span) because
        // document.currentScript and inline script execution are unreliable when HTML
        // is injected via jQuery .load() / AJAX into the #popup dialog (the "edit user"
        // modal opened from ticket details, the main users list, etc.).
        // The existing MutationObserver below will pick up dynamically added padlocks.
        window.attachClientnumPadlocks = function attachClientnumPadlocks(root) {
          root = root || document;
          root.querySelectorAll('span.clientnum-padlock[data-clientnum-padlock="1"]').forEach(function(lock) {
            if (lock.dataset.attached === '1') return; // prevent double binding on re-observations
            lock.dataset.attached = '1';

            lock.addEventListener('click', function() {
              // Prefer the explicitly marked clientnum input (set during render), fall back to any input in the same wrapper
              var input = lock.parentNode.querySelector('input[data-clientnum-field="true"]')
                       || lock.parentNode.querySelector('input');
              if (input) {
                input.disabled = false;
                input.removeAttribute('disabled');

                // Mark it so the MutationObserver's enforceClientnumDisabled() won't immediately
                // re-disable it when we append the hidden flag (or other DOM changes in the dialog).
                input.setAttribute('data-clientnum-unlocked', '1');

                // Visually "open" the padlock
                var icon = lock.querySelector('i');
                if (icon) {
                  icon.className = 'icon-unlock';
                }
                lock.style.color = '#28a745';
                lock.title = 'Unlocked for this edit';

                // Tell the backend (User::updateInfo) that the staff member explicitly unlocked it
                var unlockFlag = document.createElement('input');
                unlockFlag.type = 'hidden';
                unlockFlag.name = 'clientnum_unlock';
                unlockFlag.value = '1';
                lock.parentNode.appendChild(unlockFlag);

                try { input.focus(); input.select(); } catch (e) {}
              }
            });
          });
        };

        // Observer to detect changes in the popup content
        const observer = new MutationObserver(mutations => {
          for (let mutation of mutations) {
            if (mutation.type === 'childList' || mutation.type === 'subtree') {
              copyEmToInput();
              enforceClientnumDisabled();
              attachClientnumPadlocks(popup);
            }
          }
        });

        // Define what to observe (childList changes) and start observing the popup element
        const popup = document.getElementById('popup');
        if (popup) {
          observer.observe(popup, { childList: true, subtree: true });
          // Initial run in case content is already present
          enforceClientnumDisabled();
          attachClientnumPadlocks(popup);
        }
      });
    </script>
</head>
<body>
<div id="container">
    <?php
    if($ost->getError())
        echo sprintf('<div id="error_bar">%s</div>', $ost->getError());
    elseif($ost->getWarning())
        echo sprintf('<div id="warning_bar">%s</div>', $ost->getWarning());
    elseif($ost->getNotice())
        echo sprintf('<div id="notice_bar">%s</div>', $ost->getNotice());
    ?>
    <div id="header">
        <p id="info" class="pull-right no-pjax"><?php echo sprintf(__('Welcome, %s.'), '<strong>'.$thisstaff->getFirstName().'</strong>'); ?>
           <?php
            if($thisstaff->isAdmin() && !defined('ADMINPAGE')) { ?>
            | <a href="<?php echo ROOT_PATH ?>scp/admin.php" class="no-pjax"><?php echo __('Admin Panel'); ?></a>
            <?php }else{ ?>
            | <a href="<?php echo ROOT_PATH ?>scp/index.php" class="no-pjax"><?php echo __('Agent Panel'); ?></a>
            <?php } ?>
            | <a href="<?php echo ROOT_PATH ?>scp/profile.php"><?php echo __('Profile'); ?></a>
            | <a href="<?php echo ROOT_PATH ?>scp/logout.php?auth=<?php echo $ost->getLinkToken(); ?>" class="no-pjax"><?php echo __('Log Out'); ?></a>
        </p>
        <a href="<?php echo ROOT_PATH ?>scp/index.php" class="no-pjax" id="logo">
            <span class="valign-helper"></span>
            <img src="<?php echo ROOT_PATH ?>scp/logo.php?<?php echo strtotime($cfg->lastModified('staff_logo_id')); ?>" alt="osTicket &mdash; <?php echo __('Customer Support System'); ?>"/>
        </a>
    </div>
    <div id="pjax-container" class="<?php if ($_POST) echo 'no-pjax'; ?>">
<?php } else {
    header('X-PJAX-Version: ' . GIT_VERSION);
    if ($pjax = $ost->getExtraPjax()) { ?>
    <script type="text/javascript">
    <?php foreach (array_filter($pjax) as $s) echo $s.";"; ?>
    </script>
    <?php }
    foreach ($ost->getExtraHeaders() as $h) {
        if (strpos($h, '<script ') !== false)
            echo $h;
    } ?>
    <title><?php echo ($ost && ($title=$ost->getPageTitle()))?$title:'osTicket :: '.__('Staff Control Panel'); ?></title><?php
} # endif X_PJAX ?>
    <ul id="nav">
<?php include STAFFINC_DIR . "templates/navigation.tmpl.php"; ?>
    </ul>
    <?php include STAFFINC_DIR . "templates/sub-navigation.tmpl.php"; ?>

        <div id="content">
        <?php if(isset($errors['err'])) { ?>
            <div id="msg_error"><?php echo $errors['err']; ?></div>
        <?php }elseif($msg) { ?>
            <div id="msg_notice"><?php echo $msg; ?></div>
        <?php }elseif($warn) { ?>
            <div id="msg_warning"><?php echo $warn; ?></div>
        <?php }
        foreach (Messages::getMessages() as $M) { ?>
            <div class="<?php echo strtolower($M->getLevel()); ?>-banner"><?php
                echo (string) $M; ?></div>
<?php   } ?>
