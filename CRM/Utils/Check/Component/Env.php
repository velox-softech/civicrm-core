<?php
/*
 +--------------------------------------------------------------------+
 | Copyright CiviCRM LLC. All rights reserved.                        |
 |                                                                    |
 | This work is published under the GNU AGPLv3 license with some      |
 | permitted exceptions and without any warranty. For full license    |
 | and copyright information, see https://civicrm.org/licensing       |
 +--------------------------------------------------------------------+
 */

/**
 *
 * @package CRM
 * @copyright CiviCRM LLC https://civicrm.org/licensing
 */
class CRM_Utils_Check_Component_Env extends CRM_Utils_Check_Component {

  /**
   * @return array
   */
  public function checkPhpVersion() {
    $messages = [];
    $phpVersion = phpversion();

    if (version_compare($phpVersion, CRM_Upgrade_Incremental_General::RECOMMENDED_PHP_VER) >= 0) {
      $messages[] = new CRM_Utils_Check_Message(
        __FUNCTION__,
        ts('This system uses PHP version %1 which meets or exceeds the recommendation of %2.',
          [
            1 => $phpVersion,
            2 => CRM_Upgrade_Incremental_General::RECOMMENDED_PHP_VER,
          ]),
          ts('PHP Up-to-Date'),
          \Psr\Log\LogLevel::INFO,
          'fa-server'
      );
    }
    elseif (version_compare($phpVersion, CRM_Upgrade_Incremental_General::MIN_RECOMMENDED_PHP_VER) >= 0) {
      $messages[] = new CRM_Utils_Check_Message(
        __FUNCTION__,
        ts('This system uses PHP version %1. This meets the minimum recommendations and you do not need to upgrade immediately, but the preferred version is %2.',
          [
            1 => $phpVersion,
            2 => CRM_Upgrade_Incremental_General::RECOMMENDED_PHP_VER,
          ]),
          ts('PHP Out-of-Date'),
          \Psr\Log\LogLevel::NOTICE,
          'fa-server'
      );
    }
    elseif (version_compare($phpVersion, CRM_Upgrade_Incremental_General::MIN_INSTALL_PHP_VER) >= 0) {
      $messages[] = new CRM_Utils_Check_Message(
        __FUNCTION__,
        ts('This system uses PHP version %1. This meets the minimum requirements for CiviCRM to function but is not recommended. At least PHP version %2 is recommended; the preferred version is %3.',
          [
            1 => $phpVersion,
            2 => CRM_Upgrade_Incremental_General::MIN_RECOMMENDED_PHP_VER,
            3 => CRM_Upgrade_Incremental_General::RECOMMENDED_PHP_VER,
          ]),
          ts('PHP Out-of-Date'),
          \Psr\Log\LogLevel::WARNING,
          'fa-server'
      );
    }
    else {
      $messages[] = new CRM_Utils_Check_Message(
        __FUNCTION__,
        ts('This system uses PHP version %1. To ensure the continued operation of CiviCRM, upgrade your server now. At least PHP version %2 is recommended; the preferrred version is %3.',
          [
            1 => $phpVersion,
            2 => CRM_Upgrade_Incremental_General::MIN_RECOMMENDED_PHP_VER,
            3 => CRM_Upgrade_Incremental_General::RECOMMENDED_PHP_VER,
          ]),
          ts('PHP Out-of-Date'),
          \Psr\Log\LogLevel::ERROR,
          'fa-server'
      );
    }

    return $messages;
  }

  /**
   * @return array
   */
  public function checkPhpMysqli() {
    $messages = [];

    if (!extension_loaded('mysqli')) {
      $messages[] = new CRM_Utils_Check_Message(
        __FUNCTION__,
        ts('Future versions of CiviCRM may require the PHP extension "%2". To ensure that your system will be compatible, please install it in advance. For more explanation, see <a href="%1">the announcement</a>.',
          [
            1 => 'https://civicrm.org/blog/totten/psa-please-verify-php-extension-mysqli',
            2 => 'mysqli',
          ]),
          ts('Forward Compatibility: Enable "mysqli"'),
          \Psr\Log\LogLevel::WARNING,
          'fa-server'
      );
    }

    return $messages;
  }

  /**
   * Check that the MySQL time settings match the PHP time settings.
   *
   * @return array<CRM_Utils_Check_Message> an empty array, or a list of warnings
   */
  public function checkMysqlTime() {
    $messages = [];

    $phpNow = date('Y-m-d H:i');
    $sqlNow = CRM_Core_DAO::singleValueQuery("SELECT date_format(now(), '%Y-%m-%d %H:%i')");
    if (!CRM_Utils_Time::isEqual($phpNow, $sqlNow, 2.5 * 60)) {
      $messages[] = new CRM_Utils_Check_Message(
        __FUNCTION__,
        ts('Timestamps reported by MySQL (eg "%2") and PHP (eg "%3" ) are mismatched.<br /><a href="%1">Read more about this warning</a>', [
          1 => CRM_Utils_System::getWikiBaseURL() . 'checkMysqlTime',
          2 => $sqlNow,
          3 => $phpNow,
        ]),
        ts('Timestamp Mismatch'),
        \Psr\Log\LogLevel::ERROR,
        'fa-server'
      );
    }

    return $messages;
  }

  /**
   * @return array
   */
  public function checkDebug() {
    $config = CRM_Core_Config::singleton();
    if ($config->debug) {
      $message = new CRM_Utils_Check_Message(
        __FUNCTION__,
        ts('Warning: Debug is enabled in <a href="%1">system settings</a>. This should not be enabled on production servers.',
          [1 => CRM_Utils_System::url('civicrm/admin/setting/debug', 'reset=1')]),
        ts('Debug Mode Enabled'),
        \Psr\Log\LogLevel::WARNING,
        'fa-bug'
      );
      $message->addAction(
        ts('Disable Debug Mode'),
        ts('Disable debug mode now?'),
        'api3',
        ['Setting', 'create', ['debug_enabled' => 0]]
      );
      return [$message];
    }

    return [];
  }

  /**
   * @return array
   */
  public function checkOutboundMail() {
    $messages = [];

    $mailingInfo = Civi::settings()->get('mailing_backend');
    if (($mailingInfo['outBound_option'] == CRM_Mailing_Config::OUTBOUND_OPTION_REDIRECT_TO_DB
      || (defined('CIVICRM_MAIL_LOG') && CIVICRM_MAIL_LOG)
      || $mailingInfo['outBound_option'] == CRM_Mailing_Config::OUTBOUND_OPTION_DISABLED
      || $mailingInfo['outBound_option'] == CRM_Mailing_Config::OUTBOUND_OPTION_MOCK)
    ) {
      $messages[] = new CRM_Utils_Check_Message(
        __FUNCTION__,
        ts('Warning: Outbound email is disabled in <a href="%1">system settings</a>. Proper settings should be enabled on production servers.',
          [1 => CRM_Utils_System::url('civicrm/admin/setting/smtp', 'reset=1')]),
        ts('Outbound Email Disabled'),
        \Psr\Log\LogLevel::WARNING,
        'fa-envelope'
      );
    }

    return $messages;
  }

  /**
   * Check that domain email and org name are set
   * @return array
   */
  public function checkDomainNameEmail() {
    $messages = [];

    list($domainEmailName, $domainEmailAddress) = CRM_Core_BAO_Domain::getNameAndEmail(TRUE);
    $domain        = CRM_Core_BAO_Domain::getDomain();
    $domainName    = $domain->name;
    $fixEmailUrl   = CRM_Utils_System::url("civicrm/admin/options/from_email_address", "&reset=1");
    $fixDomainName = CRM_Utils_System::url("civicrm/admin/domain", "action=update&reset=1");

    if (!$domainEmailAddress || $domainEmailAddress == 'info@EXAMPLE.ORG') {
      if (!$domainName || $domainName == 'Default Domain Name') {
        $msg = ts("Please enter your organization's <a href=\"%1\">name, primary address </a> and <a href=\"%2\">default FROM Email Address </a> (for system-generated emails).",
          [
            1 => $fixDomainName,
            2 => $fixEmailUrl,
          ]
        );
      }
      else {
        $msg = ts('Please enter a <a href="%1">default FROM Email Address</a> (for system-generated emails).',
          [1 => $fixEmailUrl]);
      }
    }
    elseif (!$domainName || $domainName == 'Default Domain Name') {
      $msg = ts("Please enter your organization's <a href=\"%1\">name and primary address</a>.",
        [1 => $fixDomainName]);
    }

    if (!empty($msg)) {
      $messages[] = new CRM_Utils_Check_Message(
        __FUNCTION__,
        $msg,
        ts('Complete Setup'),
        \Psr\Log\LogLevel::WARNING,
        'fa-check-square-o'
      );
    }

    return $messages;
  }

  /**
   * Checks if a default bounce handling mailbox is set up
   * @return array
   */
  public function checkDefaultMailbox() {
    $messages = [];
    $config = CRM_Core_Config::singleton();

    if (in_array('CiviMail', $config->enableComponents) &&
      CRM_Core_BAO_MailSettings::defaultDomain() == "EXAMPLE.ORG"
    ) {
      $message = new CRM_Utils_Check_Message(
        __FUNCTION__,
        ts('Please configure a <a href="%1">default mailbox</a> for CiviMail.',
          [1 => CRM_Utils_System::url('civicrm/admin/mailSettings', "reset=1")]),
        ts('Configure Default Mailbox'),
        \Psr\Log\LogLevel::WARNING,
        'fa-envelope'
      );
      $docUrl = 'target="_blank" href="' . CRM_Utils_System::docURL(['page' => 'user/advanced-configuration/email-system-configuration/', 'URLonly' => TRUE]) . '""';
      $message->addHelp(
        ts('A default mailbox must be configured for email bounce processing.') . '<br />' .
        ts("Learn more in the <a %1>online documentation</a>.", [1 => $docUrl])
      );
      $messages[] = $message;
    }

    return $messages;
  }

  /**
   * Checks if cron has run in a reasonable amount of time
   * @return array
   */
  public function checkLastCron() {
    $messages = [];

    $statusPreference = new CRM_Core_DAO_StatusPreference();
    $statusPreference->domain_id = CRM_Core_Config::domainID();
    $statusPreference->name = 'checkLastCron';

    if ($statusPreference->find(TRUE) && !empty($statusPreference->check_info)) {
      $lastCron = $statusPreference->check_info;
      $msg = ts('Last cron run at %1.', [1 => CRM_Utils_Date::customFormat(date('c', $lastCron))]);
    }
    else {
      $lastCron = 0;
      $msg = ts('No cron runs have been recorded.');
    }

    if ($lastCron > gmdate('U') - 3600) {
      $messages[] = new CRM_Utils_Check_Message(
        __FUNCTION__,
        $msg,
        ts('Cron Running OK'),
        \Psr\Log\LogLevel::INFO,
        'fa-clock-o'
      );
    }
    else {
      $cronLink = 'target="_blank" href="' . htmlentities(CRM_Utils_System::docURL2('sysadmin/setup/jobs/', TRUE)) . '""';
      $msg .= '<p>' . ts('To enable scheduling support, please <a %1>set up the cron job</a>.', [
        1 => $cronLink,
      ]) . '</p>';
      $message = new CRM_Utils_Check_Message(
        __FUNCTION__,
        $msg,
        ts('Cron Not Running'),
        ($lastCron > gmdate('U') - 86400) ? \Psr\Log\LogLevel::WARNING : \Psr\Log\LogLevel::ERROR,
        'fa-clock-o'
      );
      $messages[] = $message;
    }

    return $messages;
  }

  /**
   * Recommend that sites use path-variables for their directories and URLs.
   * @return array
   */
  public function checkUrlVariables() {
    $messages = [];
    $hasOldStyle = FALSE;
    $settingNames = [
      'userFrameworkResourceURL',
      'imageUploadURL',
      'customCSSURL',
      'extensionsURL',
    ];

    foreach ($settingNames as $settingName) {
      $settingValue = Civi::settings()->get($settingName);
      if (!empty($settingValue) && $settingValue{0} != '[') {
        $hasOldStyle = TRUE;
        break;
      }
    }

    if ($hasOldStyle) {
      $message = new CRM_Utils_Check_Message(
        __FUNCTION__,
        ts('<a href="%1">Resource URLs</a> may use absolute paths, relative paths, or variables. Absolute paths are more difficult to maintain. To maximize portability, consider using a variable in each URL (eg "<tt>[cms.root]</tt>" or "<tt>[civicrm.files]</tt>").',
          [1 => CRM_Utils_System::url('civicrm/admin/setting/url', "reset=1")]),
        ts('Resource URLs: Make them portable'),
        \Psr\Log\LogLevel::NOTICE,
        'fa-server'
      );
      $messages[] = $message;
    }

    return $messages;
  }

  /**
   * Recommend that sites use path-variables for their directories and URLs.
   * @return array
   */
  public function checkDirVariables() {
    $messages = [];
    $hasOldStyle = FALSE;
    $settingNames = [
      'uploadDir',
      'imageUploadDir',
      'customFileUploadDir',
      'customTemplateDir',
      'customPHPPathDir',
      'extensionsDir',
    ];

    foreach ($settingNames as $settingName) {
      $settingValue = Civi::settings()->get($settingName);
      if (!empty($settingValue) && $settingValue{0} != '[') {
        $hasOldStyle = TRUE;
        break;
      }
    }

    if ($hasOldStyle) {
      $message = new CRM_Utils_Check_Message(
        __FUNCTION__,
        ts('<a href="%1">Directories</a> may use absolute paths, relative paths, or variables. Absolute paths are more difficult to maintain. To maximize portability, consider using a variable in each directory (eg "<tt>[cms.root]</tt>" or "<tt>[civicrm.files]</tt>").',
          [1 => CRM_Utils_System::url('civicrm/admin/setting/path', "reset=1")]),
        ts('Directory Paths: Make them portable'),
        \Psr\Log\LogLevel::NOTICE,
        'fa-server'
      );
      $messages[] = $message;
    }

    return $messages;
  }

  /**
   * Check that important directories are writable.
   *
   * @return array
   *   Any CRM_Utils_Check_Message instances that need to be generated.
   */
  public function checkDirsWritable() {
    $notWritable = [];

    $config = CRM_Core_Config::singleton();
    $directories = [
      'uploadDir' => ts('Temporary Files Directory'),
      'imageUploadDir' => ts('Images Directory'),
      'customFileUploadDir' => ts('Custom Files Directory'),
    ];

    foreach ($directories as $directory => $label) {
      $file = CRM_Utils_File::createFakeFile($config->$directory);

      if ($file === FALSE) {
        $notWritable[] = "$label ({$config->$directory})";
      }
      else {
        $dirWithSlash = CRM_Utils_File::addTrailingSlash($config->$directory);
        unlink($dirWithSlash . $file);
      }
    }

    $messages = [];

    if (!empty($notWritable)) {
      $messages[] = new CRM_Utils_Check_Message(
        __FUNCTION__,
        ts('The %1 is not writable.  Please check your file permissions.', [
          1 => implode(', ', $notWritable),
          'count' => count($notWritable),
          'plural' => 'The following directories are not writable: %1.  Please check your file permissions.',
        ]),
        ts('Directory not writable', [
          'count' => count($notWritable),
          'plural' => 'Directories not writable',
        ]),
        \Psr\Log\LogLevel::ERROR,
        'fa-ban'
      );
    }

    return $messages;
  }

  /**
   * Checks if new versions are available
   * @return array
   */
  public function checkVersion() {
    $messages = [];
    try {
      $vc = new CRM_Utils_VersionCheck();
      $vc->initialize();
    }
    catch (Exception $e) {
      $messages[] = new CRM_Utils_Check_Message(
        'checkVersionError',
        ts('Directory %1 is not writable.  Please change your file permissions.',
          [1 => dirname($vc->cacheFile)]),
        ts('Directory not writable'),
        \Psr\Log\LogLevel::ERROR,
        'fa-times-circle-o'
      );
      return $messages;
    }

    // Show a notice if the version_check job is disabled
    if (empty($vc->cronJob['is_active'])) {
      $args = empty($vc->cronJob['id']) ? ['reset' => 1] : ['reset' => 1, 'action' => 'update', 'id' => $vc->cronJob['id']];
      $messages[] = new CRM_Utils_Check_Message(
        'checkVersionDisabled',
        ts('The check for new versions of CiviCRM has been disabled. <a %1>Re-enable the scheduled job</a> to receive important security update notifications.', [1 => 'href="' . CRM_Utils_System::url('civicrm/admin/job', $args) . '"']),
        ts('Update Check Disabled'),
        \Psr\Log\LogLevel::NOTICE,
        'fa-times-circle-o'
      );
    }

    if ($vc->isInfoAvailable) {
      $severities = [
        'info' => CRM_Utils_Check::severityMap(\Psr\Log\LogLevel::INFO),
        'notice' => CRM_Utils_Check::severityMap(\Psr\Log\LogLevel::NOTICE) ,
        'warning' => CRM_Utils_Check::severityMap(\Psr\Log\LogLevel::WARNING) ,
        'critical' => CRM_Utils_Check::severityMap(\Psr\Log\LogLevel::CRITICAL),
      ];
      foreach ($vc->getVersionMessages() as $msg) {
        $messages[] = new CRM_Utils_Check_Message(__FUNCTION__ . '_' . $msg['name'],
          $msg['message'], $msg['title'], $severities[$msg['severity']], 'fa-cloud-upload');
      }
    }

    return $messages;
  }

  /**
   * Checks if extensions are set up properly
   * @return array
   */
  public function checkExtensions() {
    $messages = [];
    $extensionSystem = CRM_Extension_System::singleton();
    $mapper = $extensionSystem->getMapper();
    $manager = $extensionSystem->getManager();

    if ($extensionSystem->getDefaultContainer()) {
      $basedir = $extensionSystem->getDefaultContainer()->baseDir;
    }

    if (empty($basedir)) {
      // no extension directory
      $messages[] = new CRM_Utils_Check_Message(
        __FUNCTION__,
        ts('Your extensions directory is not set.  Click <a href="%1">here</a> to set the extensions directory.',
          [1 => CRM_Utils_System::url('civicrm/admin/setting/path', 'reset=1')]),
        ts('Directory not writable'),
        \Psr\Log\LogLevel::NOTICE,
        'fa-plug'
      );
      return $messages;
    }

    if (!is_dir($basedir)) {
      $messages[] = new CRM_Utils_Check_Message(
        __FUNCTION__,
        ts('Your extensions directory path points to %1, which is not a directory.  Please check your file system.',
          [1 => $basedir]),
        ts('Extensions directory incorrect'),
        \Psr\Log\LogLevel::ERROR,
        'fa-plug'
      );
      return $messages;
    }
    elseif (!is_writable($basedir)) {
      $messages[] = new CRM_Utils_Check_Message(
        __FUNCTION__ . 'Writable',
        ts('Your extensions directory (%1) is read-only. If you would like to perform downloads or upgrades, then change the file permissions.',
          [1 => $basedir]),
        ts('Read-Only Extensions'),
        \Psr\Log\LogLevel::NOTICE,
        'fa-plug'
      );
    }

    if (empty($extensionSystem->getDefaultContainer()->baseUrl)) {
      $messages[] = new CRM_Utils_Check_Message(
        __FUNCTION__ . 'URL',
        ts('The extensions URL is not properly set. Please go to the <a href="%1">URL setting page</a> and correct it.',
          [1 => CRM_Utils_System::url('civicrm/admin/setting/url', 'reset=1')]),
        ts('Extensions url missing'),
        \Psr\Log\LogLevel::ERROR,
        'fa-plug'
      );
    }

    if (!$extensionSystem->getBrowser()->isEnabled()) {
      $messages[] = new CRM_Utils_Check_Message(
        __FUNCTION__,
        ts('Not checking remote URL for extensions since ext_repo_url is set to false.'),
        ts('Extensions check disabled'),
        \Psr\Log\LogLevel::NOTICE,
        'fa-plug'
      );
      return $messages;
    }

    try {
      $remotes = $extensionSystem->getBrowser()->getExtensions();
    }
    catch (CRM_Extension_Exception $e) {
      $messages[] = new CRM_Utils_Check_Message(
        __FUNCTION__,
        $e->getMessage(),
        ts('Extension download error'),
        \Psr\Log\LogLevel::ERROR,
        'fa-plug'
      );
      return $messages;
    }

    if (!$remotes) {
      // CRM-13141 There may not be any compatible extensions available for the requested CiviCRM version + CMS. If so, $extdir is empty so just return a notice.
      $messages[] = new CRM_Utils_Check_Message(
        __FUNCTION__,
        ts('There are currently no extensions on the CiviCRM public extension directory which are compatible with version %1. If you want to install an extension which is not marked as compatible, you may be able to <a %2>download and install extensions manually</a> (depending on access to your web server).', [
          1 => CRM_Utils_System::majorVersion(),
          2 => 'href="http://wiki.civicrm.org/confluence/display/CRMDOC/Extensions"',
        ]),
        ts('No Extensions Available for this Version'),
        \Psr\Log\LogLevel::NOTICE,
        'fa-plug'
      );
      return $messages;
    }

    $keys = array_keys($manager->getStatuses());
    sort($keys);
    $updates = $errors = $okextensions = [];

    foreach ($keys as $key) {
      try {
        $obj = $mapper->keyToInfo($key);
      }
      catch (CRM_Extension_Exception $ex) {
        $errors[] = ts('Failed to read extension (%1). Please refresh the extension list.', [1 => $key]);
        continue;
      }
      $row = CRM_Admin_Page_Extensions::createExtendedInfo($obj);
      switch ($row['status']) {
        case CRM_Extension_Manager::STATUS_INSTALLED_MISSING:
          $errors[] = ts('%1 extension (%2) is installed but missing files.', [1 => CRM_Utils_Array::value('label', $row), 2 => $key]);
          break;

        case CRM_Extension_Manager::STATUS_INSTALLED:
          if (!empty($remotes[$key]) && version_compare($row['version'], $remotes[$key]->version, '<')) {
            $updates[] = ts('%1 (%2) version %3 is installed. <a %4>Upgrade to version %5</a>.', [
              1 => CRM_Utils_Array::value('label', $row),
              2 => $key,
              3 => $row['version'],
              4 => 'href="' . CRM_Utils_System::url('civicrm/admin/extensions', "action=update&id=$key&key=$key") . '"',
              5 => $remotes[$key]->version,
            ]);
          }
          else {
            if (empty($row['label'])) {
              $okextensions[] = $key;
            }
            else {
              $okextensions[] = ts('%1 (%2) version %3', [
                1 => $row['label'],
                2 => $key,
                3 => $row['version'],
              ]);
            }
          }
          break;
      }
    }

    if (!$okextensions && !$updates && !$errors) {
      $messages[] = new CRM_Utils_Check_Message(
        'extensionsOk',
        ts('No extensions installed. <a %1>Browse available extensions</a>.', [
          1 => 'href="' . CRM_Utils_System::url('civicrm/admin/extensions', 'reset=1') . '"',
        ]),
        ts('Extensions'),
        \Psr\Log\LogLevel::INFO,
        'fa-plug'
      );
    }

    if ($errors) {
      $messages[] = new CRM_Utils_Check_Message(
        __FUNCTION__ . 'Error',
        '<ul><li>' . implode('</li><li>', $errors) . '</li></ul>',
        ts('Extension Error'),
        \Psr\Log\LogLevel::ERROR,
        'fa-plug'
      );
    }

    if ($updates) {
      $messages[] = new CRM_Utils_Check_Message(
        'extensionUpdates',
        '<ul><li>' . implode('</li><li>', $updates) . '</li></ul>',
        ts('Extension Update Available', ['plural' => '%count Extension Updates Available', 'count' => count($updates)]),
        \Psr\Log\LogLevel::WARNING,
        'fa-plug'
      );
    }

    if ($okextensions) {
      if ($updates || $errors) {
        $message = ts('1 extension is up-to-date:', ['plural' => '%count extensions are up-to-date:', 'count' => count($okextensions)]);
      }
      else {
        $message = ts('All extensions are up-to-date:');
      }
      $messages[] = new CRM_Utils_Check_Message(
        'extensionsOk',
        $message . '<ul><li>' . implode('</li><li>', $okextensions) . '</li></ul>',
        ts('Extensions'),
        \Psr\Log\LogLevel::INFO,
        'fa-plug'
      );
    }

    return $messages;
  }

  /**
   * Checks if there are pending extension upgrades.
   *
   * @return array
   */
  public function checkExtensionUpgrades() {
    if (CRM_Extension_Upgrades::hasPending()) {
      $message = new CRM_Utils_Check_Message(
        __FUNCTION__,
        ts('Extension upgrades should be run as soon as possible.'),
        ts('Extension Upgrades Pending'),
        \Psr\Log\LogLevel::ERROR,
        'fa-plug'
      );
      $message->addAction(
        ts('Run Upgrades'),
        ts('Run extension upgrades now?'),
        'href',
        ['path' => 'civicrm/admin/extensions/upgrade', 'query' => ['reset' => 1, 'destination' => CRM_Utils_System::url('civicrm/a/#/status')]]
      );
      return [$message];
    }
    return [];
  }

  /**
   * Checks if CiviCRM database version is up-to-date
   * @return array
   */
  public function checkDbVersion() {
    $messages = [];
    $dbVersion = CRM_Core_BAO_Domain::version();
    $upgradeUrl = CRM_Utils_System::url("civicrm/upgrade", "reset=1");

    if (!$dbVersion) {
      // if db.ver missing
      $messages[] = new CRM_Utils_Check_Message(
        __FUNCTION__,
        ts('Version information found to be missing in database. You will need to determine the correct version corresponding to your current database state.'),
        ts('Database Version Missing'),
        \Psr\Log\LogLevel::ERROR,
        'fa-database'
      );
    }
    elseif (!CRM_Utils_System::isVersionFormatValid($dbVersion)) {
      $messages[] = new CRM_Utils_Check_Message(
        __FUNCTION__,
        ts('Database is marked with invalid version format. You may want to investigate this before you proceed further.'),
        ts('Database Version Invalid'),
        \Psr\Log\LogLevel::ERROR,
        'fa-database'
      );
    }
    elseif (stripos($dbVersion, 'upgrade')) {
      // if db.ver indicates a partially upgraded db
      $messages[] = new CRM_Utils_Check_Message(
        __FUNCTION__,
        ts('Database check failed - the database looks to have been partially upgraded. You must reload the database with the backup and try the <a href=\'%1\'>upgrade process</a> again.', [1 => $upgradeUrl]),
        ts('Database Partially Upgraded'),
        \Psr\Log\LogLevel::ALERT,
        'fa-database'
      );
    }
    else {
      $codeVersion = CRM_Utils_System::version();

      // if db.ver < code.ver, time to upgrade
      if (version_compare($dbVersion, $codeVersion) < 0) {
        $messages[] = new CRM_Utils_Check_Message(
          __FUNCTION__,
          ts('New codebase version detected. You must visit <a href=\'%1\'>upgrade screen</a> to upgrade the database.', [1 => $upgradeUrl]),
          ts('Database Upgrade Required'),
          \Psr\Log\LogLevel::ALERT,
          'fa-database'
        );
      }

      // if db.ver > code.ver, sth really wrong
      if (version_compare($dbVersion, $codeVersion) > 0) {
        $messages[] = new CRM_Utils_Check_Message(
          __FUNCTION__,
          ts('Your database is marked with an unexpected version number: %1. The v%2 codebase may not be compatible with your database state.
            You will need to determine the correct version corresponding to your current database state. You may want to revert to the codebase
            you were using until you resolve this problem.<br/>OR if this is a manual install from git, you might want to fix civicrm-version.php file.',
              [1 => $dbVersion, 2 => $codeVersion]
            ),
          ts('Database In Unexpected Version'),
          \Psr\Log\LogLevel::ERROR,
          'fa-database'
        );
      }
    }

    return $messages;
  }

  /**
   * ensure that all CiviCRM tables are InnoDB
   * @return array
   */
  public function checkDbEngine() {
    $messages = [];

    if (CRM_Core_DAO::isDBMyISAM(150)) {
      $messages[] = new CRM_Utils_Check_Message(
        __FUNCTION__,
        ts('Your database is configured to use the MyISAM database engine. CiviCRM requires InnoDB. You will need to convert any MyISAM tables in your database to InnoDB. Using MyISAM tables will result in data integrity issues.'),
        ts('MyISAM Database Engine'),
        \Psr\Log\LogLevel::ERROR,
        'fa-database'
      );
    }
    return $messages;
  }

  /**
   * ensure reply id is set to any default value
   * @return array
   */
  public function checkReplyIdForMailing() {
    $messages = [];

    if (!CRM_Mailing_PseudoConstant::defaultComponent('Reply', '')) {
      $messages[] = new CRM_Utils_Check_Message(
        __FUNCTION__,
        ts('Reply Auto Responder is not set to any default value in <a %1>Headers, Footers, and Automated Messages</a>. This will disable the submit operation on any mailing created from CiviMail.', [1 => 'href="' . CRM_Utils_System::url('civicrm/admin/component', 'reset=1') . '"']),
        ts('No Default value for Auto Responder.'),
        \Psr\Log\LogLevel::WARNING,
        'fa-reply'
      );
    }
    return $messages;
  }

  /**
   * Check for required mbstring extension
   * @return array
   */
  public function checkMbstring() {
    $messages = [];

    if (!function_exists('mb_substr')) {
      $messages[] = new CRM_Utils_Check_Message(
        __FUNCTION__,
        ts('The PHP Multibyte String extension is needed for CiviCRM to correctly handle user input among other functionality. Ask your system administrator to install it.'),
        ts('Missing mbstring Extension'),
        \Psr\Log\LogLevel::WARNING,
        'fa-server'
      );
    }
    return $messages;
  }

  /**
   * Check if environment is Production.
   * @return array
   */
  public function checkEnvironment() {
    $messages = [];

    $environment = CRM_Core_Config::environment();
    if ($environment != 'Production') {
      $messages[] = new CRM_Utils_Check_Message(
        __FUNCTION__,
        ts('The environment of this CiviCRM instance is set to \'%1\'. Certain functionality like scheduled jobs has been disabled.', [1 => $environment]),
        ts('Non-Production Environment'),
        \Psr\Log\LogLevel::ALERT,
        'fa-bug'
      );
    }
    return $messages;
  }

  /**
   * Check that the resource URL points to the correct location.
   * @return array
   */
  public function checkResourceUrl() {
    $messages = [];
    // Skip when run during unit tests, you can't check without a CMS.
    if (CRM_Core_Config::singleton()->userFramework == 'UnitTests') {
      return $messages;
    }
    // CRM-21629 Set User Agent to avoid being blocked by filters
    stream_context_set_default([
      'http' => ['user_agent' => 'CiviCRM'],
    ]);

    // Does arrow.png exist where we expect it?
    $arrowUrl = CRM_Core_Config::singleton()->userFrameworkResourceURL . 'packages/jquery/css/images/arrow.png';
    if ($this->fileExists($arrowUrl) === FALSE) {
      $messages[] = new CRM_Utils_Check_Message(
        __FUNCTION__,
        ts('The Resource URL is not set correctly. Please set the <a href="%1">CiviCRM Resource URL</a>.',
          [1 => CRM_Utils_System::url('civicrm/admin/setting/url', 'reset=1')]),
        ts('Incorrect Resource URL'),
        \Psr\Log\LogLevel::ERROR,
        'fa-server'
      );
    }
    return $messages;
  }

  /**
   * Check for utf8mb4 support by MySQL.
   *
   * @return array<CRM_Utils_Check_Message> an empty array, or a list of warnings
   */
  public function checkMysqlUtf8mb4() {
    $messages = [];

    if (CRM_Core_DAO::getConnection()->phptype != 'mysqli') {
      return $messages;
    }

    // Use mysqli_query() to avoid logging an error message.
    if (mysqli_query(CRM_Core_DAO::getConnection()->connection, 'CREATE TEMPORARY TABLE civicrm_utf8mb4_test (id VARCHAR(255), PRIMARY KEY(id(255))) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC ENGINE=INNODB')) {
      CRM_Core_DAO::executeQuery('DROP TEMPORARY TABLE civicrm_utf8mb4_test');
    }
    else {
      $messages[] = new CRM_Utils_Check_Message(
        __FUNCTION__,
        ts('Future versions of CiviCRM may require MySQL utf8mb4 support. It is recommended, though not yet required, to configure your MySQL server for utf8mb4 support. You will need the following MySQL server configuration: innodb_large_prefix=true innodb_file_format=barracuda innodb_file_per_table=true'),
        ts('MySQL utf8mb4 Support'),
        \Psr\Log\LogLevel::WARNING,
        'fa-database'
      );
    }
    // Ensure that the MySQL driver supports utf8mb4 encoding.
    $version = mysqli_get_client_info();
    if (strpos($version, 'mysqlnd') !== FALSE) {
      // The mysqlnd driver supports utf8mb4 starting at version 5.0.9.
      $version = preg_replace('/^\D+([\d.]+).*/', '$1', $version);
      if (version_compare($version, '5.0.9', '<')) {
        $messages[] = new CRM_Utils_Check_Message(
          __FUNCTION__ . 'mysqlnd',
          ts('It is recommended, though not yet required, to upgrade your PHP MySQL driver (mysqlnd) to >= 5.0.9 for utf8mb4 support.'),
          ts('PHP MySQL Driver (mysqlnd)'),
          \Psr\Log\LogLevel::WARNING,
          'fa-server'
        );
      }
    }
    else {
      // The libmysqlclient driver supports utf8mb4 starting at version 5.5.3.
      if (version_compare($version, '5.5.3', '<')) {
        $messages[] = new CRM_Utils_Check_Message(
          __FUNCTION__ . 'libmysqlclient',
          ts('It is recommended, though not yet required, to upgrade your PHP MySQL driver (libmysqlclient) to >= 5.5.3 for utf8mb4 support.'),
          ts('PHP MySQL Driver (libmysqlclient)'),
          \Psr\Log\LogLevel::WARNING,
          'fa-server'
        );
      }
    }

    return $messages;
  }

}
