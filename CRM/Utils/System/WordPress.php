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
 * $Id$
 *
 */

/**
 * WordPress specific stuff goes here
 */
class CRM_Utils_System_WordPress extends CRM_Utils_System_Base {

  /**
   */
  public function __construct() {
    /**
     * deprecated property to check if this is a drupal install. The correct method is to have functions on the UF classes for all UF specific
     * functions and leave the codebase oblivious to the type of CMS
     * @deprecated
     * @var bool
     */
    $this->is_drupal = FALSE;
    $this->is_wordpress = TRUE;
  }

  /**
   * @inheritDoc
   */
  public function setTitle($title, $pageTitle = NULL) {
    if (!$pageTitle) {
      $pageTitle = $title;
    }

    // FIXME: Why is this global?
    global $civicrm_wp_title;
    $civicrm_wp_title = $title;

    // yes, set page title, depending on context
    $context = civi_wp()->civicrm_context_get();
    switch ($context) {
      case 'admin':
      case 'shortcode':
        $template = CRM_Core_Smarty::singleton();
        $template->assign('pageTitle', $pageTitle);
    }
  }

  /**
   * Moved from CRM_Utils_System_Base
   */
  public function getDefaultFileStorage() {
    $config = CRM_Core_Config::singleton();
    $cmsUrl = CRM_Utils_System::languageNegotiationURL($config->userFrameworkBaseURL, FALSE, TRUE);
    $cmsPath = $this->cmsRootPath();
    $filesPath = CRM_Utils_File::baseFilePath();
    $filesRelPath = CRM_Utils_File::relativize($filesPath, $cmsPath);
    $filesURL = rtrim($cmsUrl, '/') . '/' . ltrim($filesRelPath, ' /');
    return [
      'url' => CRM_Utils_File::addTrailingSlash($filesURL, '/'),
      'path' => CRM_Utils_File::addTrailingSlash($filesPath),
    ];
  }

  /**
   * Determine the location of the CiviCRM source tree.
   *
   * @return array
   *   - url: string. ex: "http://example.com/sites/all/modules/civicrm"
   *   - path: string. ex: "/var/www/sites/all/modules/civicrm"
   */
  public function getCiviSourceStorage() {
    global $civicrm_root;

    // Don't use $config->userFrameworkBaseURL; it has garbage on it.
    // More generally, we shouldn't be using $config here.
    if (!defined('CIVICRM_UF_BASEURL')) {
      throw new RuntimeException('Undefined constant: CIVICRM_UF_BASEURL');
    }

    $cmsPath = $this->cmsRootPath();

    // $config  = CRM_Core_Config::singleton();
    // overkill? // $cmsUrl = CRM_Utils_System::languageNegotiationURL($config->userFrameworkBaseURL, FALSE, TRUE);
    $cmsUrl = CIVICRM_UF_BASEURL;
    if (CRM_Utils_System::isSSL()) {
      $cmsUrl = str_replace('http://', 'https://', $cmsUrl);
    }
    $civiRelPath = CRM_Utils_File::relativize(realpath($civicrm_root), realpath($cmsPath));
    $civiUrl = rtrim($cmsUrl, '/') . '/' . ltrim($civiRelPath, ' /');
    return [
      'url' => CRM_Utils_File::addTrailingSlash($civiUrl, '/'),
      'path' => CRM_Utils_File::addTrailingSlash($civicrm_root),
    ];
  }

  /**
   * @inheritDoc
   */
  public function appendBreadCrumb($breadCrumbs) {
    $breadCrumb = wp_get_breadcrumb();

    if (is_array($breadCrumbs)) {
      foreach ($breadCrumbs as $crumbs) {
        if (stripos($crumbs['url'], 'id%%')) {
          $args = ['cid', 'mid'];
          foreach ($args as $a) {
            $val = CRM_Utils_Request::retrieve($a, 'Positive', CRM_Core_DAO::$_nullObject,
              FALSE, NULL, $_GET
            );
            if ($val) {
              $crumbs['url'] = str_ireplace("%%{$a}%%", $val, $crumbs['url']);
            }
          }
        }
        $breadCrumb[] = "<a href=\"{$crumbs['url']}\">{$crumbs['title']}</a>";
      }
    }

    $template = CRM_Core_Smarty::singleton();
    $template->assign_by_ref('breadcrumb', $breadCrumb);
    wp_set_breadcrumb($breadCrumb);
  }

  /**
   * @inheritDoc
   */
  public function resetBreadCrumb() {
    $bc = [];
    wp_set_breadcrumb($bc);
  }

  /**
   * @inheritDoc
   */
  public function addHTMLHead($head) {
    static $registered = FALSE;
    if (!$registered) {
      // front-end view
      add_action('wp_head', [__CLASS__, '_showHTMLHead']);
      // back-end views
      add_action('admin_head', [__CLASS__, '_showHTMLHead']);
    }
    CRM_Core_Region::instance('wp_head')->add([
      'markup' => $head,
    ]);
  }

  /**
   * WP action callback.
   */
  public static function _showHTMLHead() {
    $region = CRM_Core_Region::instance('wp_head', FALSE);
    if ($region) {
      echo $region->render('');
    }
  }

  /**
   * @inheritDoc
   */
  public function mapConfigToSSL() {
    global $base_url;
    $base_url = str_replace('http://', 'https://', $base_url);
  }

  /**
   * @inheritDoc
   */
  public function url(
    $path = NULL,
    $query = NULL,
    $absolute = FALSE,
    $fragment = NULL,
    $frontend = FALSE,
    $forceBackend = FALSE
  ) {
    $config = CRM_Core_Config::singleton();
    $script = '';
    $separator = '&';
    $wpPageParam = '';
    $fragment = isset($fragment) ? ('#' . $fragment) : '';

    $path = CRM_Utils_String::stripPathChars($path);
    $basepage = FALSE;

    //this means wp function we are trying to use is not available,
    //so load bootStrap
    // FIXME: Why bootstrap in url()? Generally want to define 1-2 strategic places to put bootstrap
    if (!function_exists('get_option')) {
      $this->loadBootStrap();
    }

    if ($config->userFrameworkFrontend) {
      global $post;
      if (get_option('permalink_structure') != '') {
        $script = get_permalink($post->ID);
      }
      if ($config->wpBasePage == $post->post_name) {
        $basepage = TRUE;
      }
      // when shortcode is included in page
      // also make sure we have valid query object
      // FIXME: $wpPageParam has no effect and is only set on the *basepage*
      global $wp_query;
      if (get_option('permalink_structure') == '' && method_exists($wp_query, 'get')) {
        if (get_query_var('page_id')) {
          $wpPageParam = "page_id=" . get_query_var('page_id');
        }
        elseif (get_query_var('p')) {
          // when shortcode is inserted in post
          $wpPageParam = "p=" . get_query_var('p');
        }
      }
    }

    $base = $this->getBaseUrl($absolute, $frontend, $forceBackend);

    if (!isset($path) && !isset($query)) {
      // FIXME: This short-circuited codepath is the same as the general one below, except
      // in that it ignores "permlink_structure" /  $wpPageParam / $script . I don't know
      // why it's different (and I can only find two obvious use-cases for this codepath,
      // of which at least one looks gratuitous). A more ambitious person would simply remove
      // this code.
      return $base . $fragment;
    }

    if (!$forceBackend && get_option('permalink_structure') != '' && ($wpPageParam || $script != '')) {
      $base = $script;
    }

    $queryParts = [];

    if (
      // not using clean URLs
      !$config->cleanURL
      // requesting an admin URL
      || ((is_admin() && !$frontend) || $forceBackend)
      // is shortcode
      || (!$basepage && $script != '')
    ) {

      // pre-existing logic
      if (isset($path)) {
        $queryParts[] = 'page=CiviCRM';
        $queryParts[] = 'q=' . rawurlencode($path);
      }
      if ($wpPageParam) {
        $queryParts[] = $wpPageParam;
      }
      if (!empty($query)) {
        $queryParts[] = $query;
      }

      $final = $base . '?' . implode($separator, $queryParts) . $fragment;

    }
    else {

      // clean URLs
      if (isset($path)) {
        $base = trailingslashit($base) . str_replace('civicrm/', '', $path) . '/';
      }
      if (isset($query)) {
        $query = ltrim($query, '=?&');
        $queryParts[] = $query;
      }

      if (!empty($queryParts)) {
        $final = $base . '?' . implode($separator, $queryParts) . $fragment;
      }
      else {
        $final = $base . $fragment;
      }

    }

    return $final;
  }

  /**
   * 27-09-2016
   * CRM-16421 CRM-17633 WIP Changes to support WP in it's own directory
   * https://wiki.civicrm.org/confluence/display/CRM/WordPress+installed+in+its+own+directory+issues
   * For now leave hard coded wp-admin references.
   * TODO: remove wp-admin references and replace with admin_url() in the future.  Look at best way to get path to admin_url
   *
   * @param $absolute
   * @param $frontend
   * @param $forceBackend
   *
   * @return mixed|null|string
   */
  private function getBaseUrl($absolute, $frontend, $forceBackend) {
    $config = CRM_Core_Config::singleton();
    if ((is_admin() && !$frontend) || $forceBackend) {
      return Civi::paths()->getUrl('[wp.backend]/.', $absolute ? 'absolute' : 'relative');
    }
    else {
      return Civi::paths()->getUrl('[wp.frontend]/.', $absolute ? 'absolute' : 'relative');
    }
  }

  /**
   * @inheritDoc
   */
  public function authenticate($name, $password, $loadCMSBootstrap = FALSE, $realPath = NULL) {
    $config = CRM_Core_Config::singleton();

    if ($loadCMSBootstrap) {
      $config->userSystem->loadBootStrap([
        'name' => $name,
        'pass' => $password,
      ]);
    }

    $user = wp_authenticate($name, $password);
    if (is_a($user, 'WP_Error')) {
      return FALSE;
    }

    // TODO: need to change this to make sure we matched only one row

    CRM_Core_BAO_UFMatch::synchronizeUFMatch($user->data, $user->data->ID, $user->data->user_email, 'WordPress');
    $contactID = CRM_Core_BAO_UFMatch::getContactId($user->data->ID);
    if (!$contactID) {
      return FALSE;
    }
    return [$contactID, $user->data->ID, mt_rand()];
  }

  /**
   * FIXME: Do something
   *
   * @param string $message
   */
  public function setMessage($message) {
  }

  /**
   * @param \string $user
   *
   * @return bool
   */
  public function loadUser($user) {
    $userdata = get_user_by('login', $user);
    if (!$userdata->data->ID) {
      return FALSE;
    }

    $uid = $userdata->data->ID;
    wp_set_current_user($uid);
    $contactID = CRM_Core_BAO_UFMatch::getContactId($uid);

    // lets store contact id and user id in session
    $session = CRM_Core_Session::singleton();
    $session->set('ufID', $uid);
    $session->set('userID', $contactID);
    return TRUE;
  }

  /**
   * FIXME: Use CMS-native approach
   * @throws \CRM_Core_Exception
   */
  public function permissionDenied() {
    throw new CRM_Core_Exception(ts('You do not have permission to access this page.'));
  }

  /**
   * Determine the native ID of the CMS user.
   *
   * @param string $username
   *
   * @return int|null
   */
  public function getUfId($username) {
    $userdata = get_user_by('login', $username);
    if (!$userdata->data->ID) {
      return NULL;
    }
    return $userdata->data->ID;
  }

  /**
   * @inheritDoc
   */
  public function logout() {
    // destroy session
    if (session_id()) {
      session_destroy();
    }
    wp_logout();
    wp_redirect(wp_login_url());
  }

  /**
   * @inheritDoc
   */
  public function getUFLocale() {
    // Polylang plugin
    if (function_exists('pll_current_language')) {
      $language = pll_current_language();
    }
    // WPML plugin
    elseif (defined('ICL_LANGUAGE_CODE')) {
      $language = ICL_LANGUAGE_CODE;
    }

    // TODO: set language variable for others WordPress plugin

    if (!empty($language)) {
      return CRM_Core_I18n_PseudoConstant::longForShort(substr($language, 0, 2));
    }
    else {
      return NULL;
    }
  }

  /**
   * @inheritDoc
   */
  public function setUFLocale($civicrm_language) {
    // TODO (probably not possible with WPML?)
    return TRUE;
  }

  /**
   * Load wordpress bootstrap.
   *
   * @param array $params
   *   Optional credentials
   *   - name: string, cms username
   *   - pass: string, cms password
   * @param bool $loadUser
   * @param bool $throwError
   * @param mixed $realPath
   *
   * @return bool
   * @throws \CRM_Core_Exception
   */
  public function loadBootStrap($params = [], $loadUser = TRUE, $throwError = TRUE, $realPath = NULL) {
    global $wp, $wp_rewrite, $wp_the_query, $wp_query, $wpdb, $current_site, $current_blog, $current_user;

    $name = CRM_Utils_Array::value('name', $params);
    $pass = CRM_Utils_Array::value('pass', $params);

    if (!defined('WP_USE_THEMES')) {
      define('WP_USE_THEMES', FALSE);
    }

    $cmsRootPath = $this->cmsRootPath();
    if (!$cmsRootPath) {
      throw new CRM_Core_Exception("Could not find the install directory for WordPress");
    }
    $path = Civi::settings()->get('wpLoadPhp');
    if (!empty($path)) {
      require_once $path;
    }
    elseif (file_exists($cmsRootPath . DIRECTORY_SEPARATOR . 'wp-load.php')) {
      require_once $cmsRootPath . DIRECTORY_SEPARATOR . 'wp-load.php';
    }
    else {
      throw new CRM_Core_Exception("Could not find the bootstrap file for WordPress");
    }
    $wpUserTimezone = get_option('timezone_string');
    if ($wpUserTimezone) {
      date_default_timezone_set($wpUserTimezone);
      CRM_Core_Config::singleton()->userSystem->setMySQLTimeZone();
    }
    require_once $cmsRootPath . DIRECTORY_SEPARATOR . 'wp-includes/pluggable.php';
    $uid = CRM_Utils_Array::value('uid', $params);
    if (!$uid) {
      $name = $name ? $name : trim(CRM_Utils_Array::value('name', $_REQUEST));
      $pass = $pass ? $pass : trim(CRM_Utils_Array::value('pass', $_REQUEST));
      if ($name) {
        $uid = wp_authenticate($name, $pass);
        if (!$uid) {
          if ($throwError) {
            echo '<br />Sorry, unrecognized username or password.';
            exit();
          }
          return FALSE;
        }
      }
    }
    if ($uid) {
      if ($uid instanceof WP_User) {
        $account = wp_set_current_user($uid->ID);
      }
      else {
        $account = wp_set_current_user($uid);
      }
      if ($account && $account->data->ID) {
        global $user;
        $user = $account;
        return TRUE;
      }
    }
    return TRUE;
  }

  /**
   * @param $dir
   *
   * @return bool
   */
  public function validInstallDir($dir) {
    $includePath = "$dir/wp-includes";
    if (@file_exists("$includePath/version.php")) {
      return TRUE;
    }
    return FALSE;
  }

  /**
   * Determine the location of the CMS root.
   *
   * @return string|NULL
   *   local file system path to CMS root, or NULL if it cannot be determined
   */
  public function cmsRootPath() {
    global $civicrm_paths;
    if (!empty($civicrm_paths['cms.root']['path'])) {
      return $civicrm_paths['cms.root']['path'];
    }

    $cmsRoot = $valid = NULL;
    if (defined('CIVICRM_CMSDIR')) {
      if ($this->validInstallDir(CIVICRM_CMSDIR)) {
        $cmsRoot = CIVICRM_CMSDIR;
        $valid = TRUE;
      }
    }
    else {
      $pathVars = explode('/', str_replace('\\', '/', $_SERVER['SCRIPT_FILENAME']));

      //might be windows installation.
      $firstVar = array_shift($pathVars);
      if ($firstVar) {
        $cmsRoot = $firstVar;
      }

      //start w/ csm dir search.
      foreach ($pathVars as $var) {
        $cmsRoot .= "/$var";
        if ($this->validInstallDir($cmsRoot)) {
          //stop as we found bootstrap.
          $valid = TRUE;
          break;
        }
      }
    }

    return ($valid) ? $cmsRoot : NULL;
  }

  /**
   * @inheritDoc
   */
  public function createUser(&$params, $mail) {
    $user_data = [
      'ID' => '',
      'user_pass' => $params['cms_pass'],
      'user_login' => $params['cms_name'],
      'user_email' => $params[$mail],
      'nickname' => $params['cms_name'],
      'role' => get_option('default_role'),
    ];
    if (isset($params['contactID'])) {
      $contactType = CRM_Contact_BAO_Contact::getContactType($params['contactID']);
      if ($contactType == 'Individual') {
        $user_data['first_name'] = CRM_Core_DAO::getFieldValue('CRM_Contact_DAO_Contact',
          $params['contactID'], 'first_name'
        );
        $user_data['last_name'] = CRM_Core_DAO::getFieldValue('CRM_Contact_DAO_Contact',
          $params['contactID'], 'last_name'
        );
      }
    }

    $uid = wp_insert_user($user_data);

    $creds = [];
    $creds['user_login'] = $params['cms_name'];
    $creds['user_password'] = $params['cms_pass'];
    $creds['remember'] = TRUE;
    $user = wp_signon($creds, FALSE);

    wp_new_user_notification($uid, $user_data['user_pass']);
    return $uid;
  }

  /**
   * @inheritDoc
   */
  public function updateCMSName($ufID, $ufName) {
    // CRM-10620
    if (function_exists('wp_update_user')) {
      $ufID = CRM_Utils_Type::escape($ufID, 'Integer');
      $ufName = CRM_Utils_Type::escape($ufName, 'String');

      $values = ['ID' => $ufID, 'user_email' => $ufName];
      if ($ufID) {
        wp_update_user($values);
      }
    }
  }

  /**
   * @param array $params
   * @param $errors
   * @param string $emailName
   */
  public function checkUserNameEmailExists(&$params, &$errors, $emailName = 'email') {
    $config = CRM_Core_Config::singleton();

    $dao = new CRM_Core_DAO();
    $name = $dao->escape(CRM_Utils_Array::value('name', $params));
    $email = $dao->escape(CRM_Utils_Array::value('mail', $params));

    if (!empty($params['name'])) {
      if (!validate_username($params['name'])) {
        $errors['cms_name'] = ts("Your username contains invalid characters");
      }
      elseif (username_exists(sanitize_user($params['name']))) {
        $errors['cms_name'] = ts('The username %1 is already taken. Please select another username.', [1 => $params['name']]);
      }
    }

    if (!empty($params['mail'])) {
      if (!is_email($params['mail'])) {
        $errors[$emailName] = "Your email is invaid";
      }
      elseif (email_exists($params['mail'])) {
        $errors[$emailName] = ts('The email address %1 already has an account associated with it. <a href="%2">Have you forgotten your password?</a>',
          [1 => $params['mail'], 2 => wp_lostpassword_url()]
        );
      }
    }
  }

  /**
   * @inheritDoc
   */
  public function isUserLoggedIn() {
    $isloggedIn = FALSE;
    if (function_exists('is_user_logged_in')) {
      $isloggedIn = is_user_logged_in();
    }

    return $isloggedIn;
  }

  /**
   * @inheritDoc
   */
  public function isUserRegistrationPermitted() {
    if (!get_option('users_can_register')) {
      return FALSE;
    }
    return TRUE;
  }

  /**
   * @inheritDoc
   */
  public function isPasswordUserGenerated() {
    return TRUE;
  }

  /**
   * @return mixed
   */
  public function getLoggedInUserObject() {
    if (function_exists('is_user_logged_in') &&
      is_user_logged_in()
    ) {
      global $current_user;
    }
    return $current_user;
  }

  /**
   * @inheritDoc
   */
  public function getLoggedInUfID() {
    $ufID = NULL;
    $current_user = $this->getLoggedInUserObject();
    return isset($current_user->ID) ? $current_user->ID : NULL;
  }

  /**
   * @inheritDoc
   */
  public function getLoggedInUniqueIdentifier() {
    $user = $this->getLoggedInUserObject();
    return $this->getUniqueIdentifierFromUserObject($user);
  }

  /**
   * Get User ID from UserFramework system (Joomla)
   * @param object $user
   *   Object as described by the CMS.
   *
   * @return int|null
   */
  public function getUserIDFromUserObject($user) {
    return !empty($user->ID) ? $user->ID : NULL;
  }

  /**
   * @inheritDoc
   */
  public function getUniqueIdentifierFromUserObject($user) {
    return empty($user->user_email) ? NULL : $user->user_email;
  }

  /**
   * @inheritDoc
   */
  public function getLoginURL($destination = '') {
    $config = CRM_Core_Config::singleton();
    $loginURL = wp_login_url();
    return $loginURL;
  }

  /**
   * FIXME: Do something.
   *
   * @param \CRM_Core_Form $form
   *
   * @return NULL|string
   */
  public function getLoginDestination(&$form) {
    return NULL;
  }

  /**
   * @inheritDoc
   */
  public function getVersion() {
    if (function_exists('get_bloginfo')) {
      return get_bloginfo('version', 'display');
    }
    else {
      return 'Unknown';
    }
  }

  /**
   * @inheritDoc
   */
  public function getTimeZoneString() {
    return get_option('timezone_string');
  }

  /**
   * @inheritDoc
   */
  public function getUserRecordUrl($contactID) {
    $uid = CRM_Core_BAO_UFMatch::getUFId($contactID);
    if (CRM_Core_Session::singleton()
      ->get('userID') == $contactID || CRM_Core_Permission::checkAnyPerm(['cms:administer users'])
    ) {
      return CRM_Core_Config::singleton()->userFrameworkBaseURL . "wp-admin/user-edit.php?user_id=" . $uid;
    }
  }

  /**
   * Append WP js to coreResourcesList.
   *
   * @param \Civi\Core\Event\GenericHookEvent $e
   */
  public function appendCoreResources(\Civi\Core\Event\GenericHookEvent $e) {
    $e->list[] = 'js/crm.wordpress.js';
  }

  /**
   * @inheritDoc
   */
  public function alterAssetUrl(\Civi\Core\Event\GenericHookEvent $e) {
    // Set menubar breakpoint to match WP admin theme
    if ($e->asset == 'crm-menubar.css') {
      $e->params['breakpoint'] = 783;
    }
  }

  /**
   * @inheritDoc
   */
  public function synchronizeUsers() {
    $config = CRM_Core_Config::singleton();
    if (PHP_SAPI != 'cli') {
      set_time_limit(300);
    }
    $id = 'ID';
    $mail = 'user_email';

    $uf = $config->userFramework;
    $contactCount = 0;
    $contactCreated = 0;
    $contactMatching = 0;

    global $wpdb;
    $wpUserIds = $wpdb->get_col("SELECT $wpdb->users.ID FROM $wpdb->users");

    foreach ($wpUserIds as $wpUserId) {
      $wpUserData = get_userdata($wpUserId);
      $contactCount++;
      if ($match = CRM_Core_BAO_UFMatch::synchronizeUFMatch($wpUserData,
        $wpUserData->$id,
        $wpUserData->$mail,
        $uf,
        1,
        'Individual',
        TRUE
      )
      ) {
        $contactCreated++;
      }
      else {
        $contactMatching++;
      }
    }

    return [
      'contactCount' => $contactCount,
      'contactMatching' => $contactMatching,
      'contactCreated' => $contactCreated,
    ];
  }

  /**
   * Send an HTTP Response base on PSR HTTP RespnseInterface response.
   *
   * @param \Psr\Http\Message\ResponseInterface $response
   */
  public function sendResponse(\Psr\Http\Message\ResponseInterface $response) {
    // use WordPress function status_header to ensure 404 response is sent
    status_header($response->getStatusCode());
    foreach ($response->getHeaders() as $name => $values) {
      CRM_Utils_System::setHttpHeader($name, implode(', ', (array) $values));
    }
    echo $response->getBody();
    CRM_Utils_System::civiExit();
  }

}
