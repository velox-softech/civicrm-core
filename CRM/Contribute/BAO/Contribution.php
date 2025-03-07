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
class CRM_Contribute_BAO_Contribution extends CRM_Contribute_DAO_Contribution {

  /**
   * Static field for all the contribution information that we can potentially import
   *
   * @var array
   */
  public static $_importableFields = NULL;

  /**
   * Static field for all the contribution information that we can potentially export
   *
   * @var array
   */
  public static $_exportableFields = NULL;

  /**
   * Static field to hold financial trxn id's.
   *
   * @var array
   */
  public static $_trxnIDs = NULL;

  /**
   * Field for all the objects related to this contribution
   *
   * @var \CRM_Member_BAO_Membership|\CRM_Event_BAO_Participant[]
   */
  public $_relatedObjects = [];

  /**
   * Field for the component - either 'event' (participant) or 'contribute'
   * (any item related to a contribution page e.g. membership, pledge, contribution)
   * This is used for composing messages because they have dependency on the
   * contribution_page or event page - although over time we may eliminate that
   *
   * @var "contribution"\"event"
   */
  public $_component = NULL;

  /**
   * Possibly obsolete variable.
   *
   * If you use it please explain why it is set in the create function here.
   *
   * @var string
   */
  public $trxn_result_code;

  /**
   * Class constructor.
   */
  public function __construct() {
    parent::__construct();
  }

  /**
   * Takes an associative array and creates a contribution object.
   *
   * the function extract all the params it needs to initialize the create a
   * contribution object. the params array could contain additional unused name/value
   * pairs
   *
   * @param array $params
   *   (reference ) an assoc array of name/value pairs.
   * @param array $ids
   *   The array that holds all the db ids.
   *
   * @return \CRM_Contribute_BAO_Contribution
   * @throws \CRM_Core_Exception
   * @throws \CiviCRM_API3_Exception
   */
  public static function add(&$params, $ids = []) {
    if (empty($params)) {
      return NULL;
    }
    //per http://wiki.civicrm.org/confluence/display/CRM/Database+layer we are moving away from $ids array
    $contributionID = CRM_Utils_Array::value('contribution', $ids, CRM_Utils_Array::value('id', $params));
    $duplicates = [];
    if (self::checkDuplicate($params, $duplicates, $contributionID)) {
      $message = ts("Duplicate error - existing contribution record(s) have a matching Transaction ID or Invoice ID. Contribution record ID(s) are: %1", [1 => implode(', ', $duplicates)]);
      throw new CRM_Core_Exception($message);
    }

    // first clean up all the money fields
    $moneyFields = [
      'total_amount',
      'net_amount',
      'fee_amount',
      'non_deductible_amount',
    ];

    //if priceset is used, no need to cleanup money
    if (!empty($params['skipCleanMoney'])) {
      $moneyFields = [];
    }
    else {
      // @todo put a deprecated here - this should be done in the form layer.
      $params['skipCleanMoney'] = FALSE;
      Civi::log()->warning('Deprecated code path. Money should always be clean before it hits the BAO.', array('civi.tag' => 'deprecated'));
    }

    foreach ($moneyFields as $field) {
      if (isset($params[$field])) {
        $params[$field] = CRM_Utils_Rule::cleanMoney($params[$field]);
      }
    }

    //set defaults in create mode
    if (!$contributionID) {
      CRM_Core_DAO::setCreateDefaults($params, self::getDefaults());

      if (empty($params['invoice_number'])) {
        $nextContributionID = CRM_Core_DAO::singleValueQuery("SELECT COALESCE(MAX(id) + 1, 1) FROM civicrm_contribution");
        $params['invoice_number'] = self::getInvoiceNumber($nextContributionID);
      }
    }

    $contributionStatus = CRM_Contribute_PseudoConstant::contributionStatus(NULL, 'name');
    //if contribution is created with cancelled or refunded status, add credit note id
    // do the same for chargeback - this entered the code 'accidentally' but moving it to here
    // as part of cleanup maintains consistency.
    if (self::isContributionStatusNegative(CRM_Utils_Array::value('contribution_status_id', $params))) {
      if (empty($params['creditnote_id'])) {
        $params['creditnote_id'] = self::createCreditNoteId();
      }
    }
    if (empty($params['contribution_status_id'])) {
      // Since the fee amount is expecting this (later on) ensure it is always set.
      // It would only not be set for an update where it is unchanged.
      $params['contribution_status_id'] = civicrm_api3('Contribution', 'getvalue', [
        'id' => $contributionID,
        'return' => 'contribution_status_id',
      ]);
    }

    if (!$contributionID
      && CRM_Utils_Array::value('membership_id', $params)
      && Civi::settings()->get('deferred_revenue_enabled')
    ) {
      $memberStartDate = CRM_Core_DAO::getFieldValue('CRM_Member_DAO_Membership', $params['membership_id'], 'start_date');
      if ($memberStartDate) {
        $params['revenue_recognition_date'] = date('Ymd', strtotime($memberStartDate));
      }
    }
    self::calculateMissingAmountParams($params, $contributionID);

    if (!empty($params['payment_instrument_id'])) {
      $paymentInstruments = CRM_Contribute_PseudoConstant::paymentInstrument('name');
      if ($params['payment_instrument_id'] != array_search('Check', $paymentInstruments)) {
        $params['check_number'] = 'null';
      }
    }

    $setPrevContribution = TRUE;
    // CRM-13964 partial payment
    if (!empty($params['partial_payment_total']) && !empty($params['partial_amount_to_pay'])) {
      $partialAmtTotal = $params['partial_payment_total'];
      $partialAmtPay = $params['partial_amount_to_pay'];
      $params['total_amount'] = $partialAmtTotal;
      if ($partialAmtPay < $partialAmtTotal) {
        $params['contribution_status_id'] = CRM_Core_PseudoConstant::getKey('CRM_Contribute_BAO_Contribution', 'contribution_status_id', 'Partially paid');
        $params['is_pay_later'] = 0;
        $setPrevContribution = FALSE;
      }
    }
    if ($contributionID && $setPrevContribution) {
      $params['prevContribution'] = self::getOriginalContribution($contributionID);
    }

    if ($contributionID && !empty($params['revenue_recognition_date']) && !empty($params['prevContribution'])
      && !($contributionStatus[$params['prevContribution']->contribution_status_id] == 'Pending')
      && !self::allowUpdateRevenueRecognitionDate($contributionID)
    ) {
      unset($params['revenue_recognition_date']);
    }

    if (!isset($params['tax_amount']) && $setPrevContribution && (isset($params['total_amount']) ||
     isset($params['financial_type_id']))) {
      $params = CRM_Contribute_BAO_Contribution::checkTaxAmount($params);
    }

    if ($contributionID) {
      CRM_Utils_Hook::pre('edit', 'Contribution', $contributionID, $params);
    }
    else {
      CRM_Utils_Hook::pre('create', 'Contribution', NULL, $params);
    }
    $contribution = new CRM_Contribute_BAO_Contribution();
    $contribution->copyValues($params);

    $contribution->id = $contributionID;

    if (empty($contribution->id)) {
      // (only) on 'create', make sure that a valid currency is set (CRM-16845)
      if (!CRM_Utils_Rule::currencyCode($contribution->currency)) {
        $contribution->currency = CRM_Core_Config::singleton()->defaultCurrency;
      }
    }

    $result = $contribution->save();

    // Add financial_trxn details as part of fix for CRM-4724
    $contribution->trxn_result_code = CRM_Utils_Array::value('trxn_result_code', $params);
    $contribution->payment_processor = CRM_Utils_Array::value('payment_processor', $params);

    //add Account details
    $params['contribution'] = $contribution;
    if (empty($params['is_post_payment_create'])) {
      // If this is being called from the Payment.create api/ BAO then that Entity
      // takes responsibility for the financial transactions. In fact calling Payment.create
      // to add payments & having it call completetransaction and / or contribution.create
      // to update related entities is the preferred flow.
      // Note that leveraging this parameter for any other code flow is not supported and
      // is likely to break in future and / or cause serious problems in your data.
      // https://github.com/civicrm/civicrm-core/pull/14673
      self::recordFinancialAccounts($params);
    }

    if (self::isUpdateToRecurringContribution($params)) {
      CRM_Contribute_BAO_ContributionRecur::updateOnNewPayment(
        (!empty($params['contribution_recur_id']) ? $params['contribution_recur_id'] : $params['prevContribution']->contribution_recur_id),
        $contributionStatus[$params['contribution_status_id']],
        CRM_Utils_Array::value('receive_date', $params)
      );
    }

    CRM_Contact_BAO_GroupContactCache::opportunisticCacheFlush();

    if ($contributionID) {
      CRM_Utils_Hook::post('edit', 'Contribution', $contribution->id, $contribution);
    }
    else {
      CRM_Utils_Hook::post('create', 'Contribution', $contribution->id, $contribution);
    }

    return $result;
  }

  /**
   * Is this contribution updating an existing recurring contribution.
   *
   * We need to upd the status of the linked recurring contribution if we have a new payment against it, or the initial
   * pending payment is being confirmed (or failing).
   *
   * @param array $params
   *
   * @return bool
   */
  public static function isUpdateToRecurringContribution($params) {
    if (!empty($params['contribution_recur_id']) && empty($params['id'])) {
      return TRUE;
    }
    if (empty($params['prevContribution']) || empty($params['contribution_status_id'])) {
      return FALSE;
    }
    if (empty($params['contribution_recur_id']) && empty($params['prevContribution']->contribution_recur_id)) {
      return FALSE;
    }
    $contributionStatus = CRM_Contribute_PseudoConstant::contributionStatus(NULL, 'name');
    if ($params['prevContribution']->contribution_status_id == array_search('Pending', $contributionStatus)) {
      return TRUE;
    }
    return FALSE;
  }

  /**
   * Get defaults for new entity.
   *
   * @return array
   */
  public static function getDefaults() {
    return [
      'payment_instrument_id' => key(CRM_Core_OptionGroup::values('payment_instrument',
        FALSE, FALSE, FALSE, 'AND is_default = 1')
      ),
      'contribution_status_id' => CRM_Core_PseudoConstant::getKey('CRM_Contribute_BAO_Contribution', 'contribution_status_id', 'Completed'),
      'receive_date' => date('Y-m-d H:i:s'),
    ];
  }

  /**
   * Fetch the object and store the values in the values array.
   *
   * @param array $params
   *   Input parameters to find object.
   * @param array $values
   *   Output values of the object.
   * @param array $ids
   *   The array that holds all the db ids.
   *
   * @return CRM_Contribute_BAO_Contribution|null
   *   The found object or null
   */
  public static function getValues($params, &$values = [], &$ids = []) {
    if (empty($params)) {
      return NULL;
    }
    $contribution = new CRM_Contribute_BAO_Contribution();

    $contribution->copyValues($params);

    if ($contribution->find(TRUE)) {
      $ids['contribution'] = $contribution->id;

      CRM_Core_DAO::storeValues($contribution, $values);

      return $contribution;
    }
    // return by reference
    $null = NULL;
    return $null;
  }

  /**
   * Get the values and resolve the most common mappings.
   *
   * Since contribution status is resolved in almost every function that calls getValues it makes
   * sense to have an extra function to resolve it rather than repeat the code.
   *
   * Think carefully before adding more mappings to be resolved as there could be performance implications
   * if this function starts to be called from more iterative functions.
   *
   * @param array $params
   *   Input parameters to find object.
   *
   * @return array
   *   Array of the found contribution.
   * @throws CRM_Core_Exception
   */
  public static function getValuesWithMappings($params) {
    $values = $ids = [];
    $contribution = self::getValues($params, $values, $ids);
    if (is_null($contribution)) {
      throw new CRM_Core_Exception('No contribution found');
    }
    $values['contribution_status'] = CRM_Core_PseudoConstant::getName('CRM_Contribute_BAO_Contribution', 'contribution_status_id', $values['contribution_status_id']);
    return $values;
  }

  /**
   * Calculate net_amount & fee_amount if they are not set.
   *
   * Net amount should be total - fee.
   * This should only be called for new contributions.
   *
   * @param array $params
   *   Params for a new contribution before they are saved.
   * @param int|null $contributionID
   *   Contribution ID if we are dealing with an update.
   *
   * @throws \CiviCRM_API3_Exception
   */
  public static function calculateMissingAmountParams(&$params, $contributionID) {
    if (!$contributionID && !isset($params['fee_amount'])) {
      if (isset($params['total_amount']) && isset($params['net_amount'])) {
        $params['fee_amount'] = $params['total_amount'] - $params['net_amount'];
      }
      else {
        $params['fee_amount'] = 0;
      }
    }
    if (!isset($params['net_amount'])) {
      if (!$contributionID) {
        $params['net_amount'] = $params['total_amount'] - $params['fee_amount'];
      }
      else {
        if (isset($params['fee_amount']) || isset($params['total_amount'])) {
          // We have an existing contribution and fee_amount or total_amount has been passed in but not net_amount.
          // net_amount may need adjusting.
          $contribution = civicrm_api3('Contribution', 'getsingle', [
            'id' => $contributionID,
            'return' => ['total_amount', 'net_amount', 'fee_amount'],
          ]);
          $totalAmount = (isset($params['total_amount']) ? (float) $params['total_amount'] : (float) CRM_Utils_Array::value('total_amount', $contribution));
          $feeAmount = (isset($params['fee_amount']) ? (float) $params['fee_amount'] : (float) CRM_Utils_Array::value('fee_amount', $contribution));
          $params['net_amount'] = $totalAmount - $feeAmount;
        }
      }
    }
  }

  /**
   * @param $params
   * @param $billingLocationTypeID
   *
   * @return array
   */
  protected static function getBillingAddressParams($params, $billingLocationTypeID) {
    $hasBillingField = FALSE;
    $billingFields = [
      'street_address',
      'city',
      'state_province_id',
      'postal_code',
      'country_id',
    ];

    //build address array
    $addressParams = [];
    $addressParams['location_type_id'] = $billingLocationTypeID;
    $addressParams['is_billing'] = 1;

    $billingFirstName = CRM_Utils_Array::value('billing_first_name', $params);
    $billingMiddleName = CRM_Utils_Array::value('billing_middle_name', $params);
    $billingLastName = CRM_Utils_Array::value('billing_last_name', $params);
    $addressParams['address_name'] = "{$billingFirstName}" . CRM_Core_DAO::VALUE_SEPARATOR . "{$billingMiddleName}" . CRM_Core_DAO::VALUE_SEPARATOR . "{$billingLastName}";

    foreach ($billingFields as $value) {
      $addressParams[$value] = CRM_Utils_Array::value("billing_{$value}-{$billingLocationTypeID}", $params);
      if (!empty($addressParams[$value])) {
        $hasBillingField = TRUE;
      }
    }
    return [$hasBillingField, $addressParams];
  }

  /**
   * Get address params ready to be passed to the payment processor.
   *
   * We need address params in a couple of formats. For the payment processor we wan state_province_id-5.
   * To create an address we need state_province_id.
   *
   * @param array $params
   * @param int $billingLocationTypeID
   *
   * @return array
   */
  public static function getPaymentProcessorReadyAddressParams($params, $billingLocationTypeID) {
    list($hasBillingField, $addressParams) = self::getBillingAddressParams($params, $billingLocationTypeID);
    foreach ($addressParams as $name => $field) {
      if (substr($name, 0, 8) == 'billing_') {
        $addressParams[substr($name, 9)] = $addressParams[$field];
      }
    }
    return [$hasBillingField, $addressParams];
  }

  /**
   * Get the number of terms for this contribution for a given membership type
   * based on querying the line item table and relevant price field values
   * Note that any one contribution should only be able to have one line item relating to a particular membership
   * type
   *
   * @param int $membershipTypeID
   *
   * @param int $contributionID
   *
   * @return int
   */
  public function getNumTermsByContributionAndMembershipType($membershipTypeID, $contributionID) {
    $numTerms = CRM_Core_DAO::singleValueQuery("
      SELECT membership_num_terms FROM civicrm_line_item li
      LEFT JOIN civicrm_price_field_value v ON li.price_field_value_id = v.id
      WHERE contribution_id = %1 AND membership_type_id = %2",
      [1 => [$contributionID, 'Integer'], 2 => [$membershipTypeID, 'Integer']]
    );
    // default of 1 is precautionary
    return empty($numTerms) ? 1 : $numTerms;
  }

  /**
   * Takes an associative array and creates a contribution object.
   *
   * @param array $params
   *   (reference ) an assoc array of name/value pairs.
   * @param array $ids
   *   The array that holds all the db ids.
   *
   * @return CRM_Contribute_BAO_Contribution
   */
  public static function create(&$params, $ids = []) {
    $contributionID = CRM_Utils_Array::value('contribution', $ids, CRM_Utils_Array::value('id', $params));
    $action = $contributionID ? 'edit' : 'create';

    $dateFields = [
      'receive_date',
      'cancel_date',
      'receipt_date',
      'thankyou_date',
      'revenue_recognition_date',
    ];
    foreach ($dateFields as $df) {
      if (isset($params[$df])) {
        $params[$df] = CRM_Utils_Date::isoToMysql($params[$df]);
      }
    }

    $transaction = new CRM_Core_Transaction();

    try {
      $contribution = self::add($params, $ids);
    }
    catch (CRM_Core_Exception $e) {
      $transaction->rollback();
      throw $e;
    }

    $params['contribution_id'] = $contribution->id;

    if (!empty($params['custom']) &&
      is_array($params['custom'])
    ) {
      CRM_Core_BAO_CustomValueTable::store($params['custom'], 'civicrm_contribution', $contribution->id, $action);
    }

    $session = CRM_Core_Session::singleton();

    if (!empty($params['note'])) {
      $noteParams = [
        'entity_table' => 'civicrm_contribution',
        'note' => $params['note'],
        'entity_id' => $contribution->id,
        'contact_id' => $session->get('userID'),
        'modified_date' => date('Ymd'),
      ];
      if (!$noteParams['contact_id']) {
        $noteParams['contact_id'] = $params['contact_id'];
      }
      CRM_Core_BAO_Note::add($noteParams);
    }

    // make entry in batch entity batch table
    if (!empty($params['batch_id'])) {
      // in some update cases we need to get extra fields - ie an update that doesn't pass in all these params
      $titleFields = [
        'contact_id',
        'total_amount',
        'currency',
        'financial_type_id',
      ];
      $retrieveRequired = 0;
      foreach ($titleFields as $titleField) {
        if (!isset($contribution->$titleField)) {
          $retrieveRequired = 1;
          break;
        }
      }
      if ($retrieveRequired == 1) {
        $contribution->find(TRUE);
      }
    }

    CRM_Contribute_BAO_ContributionSoft::processSoftContribution($params, $contribution);

    $transaction->commit();

    $activity = civicrm_api3('Activity', 'get', [
      'source_record_id' => $contribution->id,
      'options' => ['limit' => 1],
      'sequential' => 1,
      'activity_type_id' => 'Contribution',
      'return' => ['id', 'campaign'],
    ]);

    //CRM-18406: Update activity when edit contribution.
    if ($activity['count']) {
      // CRM-13237 : if activity record found, update it with campaign id of contribution
      // @todo compare campaign ids first.
      CRM_Core_DAO::setFieldValue('CRM_Activity_BAO_Activity', $activity['id'], 'campaign_id', $contribution->campaign_id);
      $contribution->activity_id = $activity['id'];
    }

    if (empty($contribution->contact_id)) {
      $contribution->find(TRUE);
    }
    CRM_Activity_BAO_Activity::addActivity($contribution, 'Contribution');

    // do not add to recent items for import, CRM-4399
    if (empty($params['skipRecentView'])) {
      $url = CRM_Utils_System::url('civicrm/contact/view/contribution',
        "action=view&reset=1&id={$contribution->id}&cid={$contribution->contact_id}&context=home"
      );
      // in some update cases we need to get extra fields - ie an update that doesn't pass in all these params
      $titleFields = [
        'contact_id',
        'total_amount',
        'currency',
        'financial_type_id',
      ];
      $retrieveRequired = 0;
      foreach ($titleFields as $titleField) {
        if (!isset($contribution->$titleField)) {
          $retrieveRequired = 1;
          break;
        }
      }
      if ($retrieveRequired == 1) {
        $contribution->find(TRUE);
      }
      $financialType = CRM_Contribute_PseudoConstant::financialType($contribution->financial_type_id);
      $title = CRM_Contact_BAO_Contact::displayName($contribution->contact_id) . ' - (' . CRM_Utils_Money::format($contribution->total_amount, $contribution->currency) . ' ' . ' - ' . $financialType . ')';

      $recentOther = [];
      if (CRM_Core_Permission::checkActionPermission('CiviContribute', CRM_Core_Action::UPDATE)) {
        $recentOther['editUrl'] = CRM_Utils_System::url('civicrm/contact/view/contribution',
          "action=update&reset=1&id={$contribution->id}&cid={$contribution->contact_id}&context=home"
        );
      }

      if (CRM_Core_Permission::checkActionPermission('CiviContribute', CRM_Core_Action::DELETE)) {
        $recentOther['deleteUrl'] = CRM_Utils_System::url('civicrm/contact/view/contribution',
          "action=delete&reset=1&id={$contribution->id}&cid={$contribution->contact_id}&context=home"
        );
      }

      // add the recently created Contribution
      CRM_Utils_Recent::add($title,
        $url,
        $contribution->id,
        'Contribution',
        $contribution->contact_id,
        NULL,
        $recentOther
      );
    }

    return $contribution;
  }

  /**
   * Get the values for pseudoconstants for name->value and reverse.
   *
   * @param array $defaults
   *   (reference) the default values, some of which need to be resolved.
   * @param bool $reverse
   *   True if we want to resolve the values in the reverse direction (value -> name).
   */
  public static function resolveDefaults(&$defaults, $reverse = FALSE) {
    self::lookupValue($defaults, 'financial_type', CRM_Contribute_PseudoConstant::financialType(), $reverse);
    self::lookupValue($defaults, 'payment_instrument', CRM_Contribute_PseudoConstant::paymentInstrument(), $reverse);
    self::lookupValue($defaults, 'contribution_status', CRM_Contribute_PseudoConstant::contributionStatus(NULL, 'label'), $reverse);
    self::lookupValue($defaults, 'pcp', CRM_Contribute_PseudoConstant::pcPage(), $reverse);
  }

  /**
   * Convert associative array names to values and vice-versa.
   *
   * This function is used by both the web form layer and the api. Note that
   * the api needs the name => value conversion, also the view layer typically
   * requires value => name conversion
   *
   * @param array $defaults
   * @param string $property
   * @param array $lookup
   * @param bool $reverse
   *
   * @return bool
   */
  public static function lookupValue(&$defaults, $property, &$lookup, $reverse) {
    $id = $property . '_id';

    $src = $reverse ? $property : $id;
    $dst = $reverse ? $id : $property;

    if (!array_key_exists($src, $defaults)) {
      return FALSE;
    }

    $look = $reverse ? array_flip($lookup) : $lookup;

    if (is_array($look)) {
      if (!array_key_exists($defaults[$src], $look)) {
        return FALSE;
      }
    }
    $defaults[$dst] = $look[$defaults[$src]];
    return TRUE;
  }

  /**
   * Retrieve DB object based on input parameters.
   *
   * It also stores all the retrieved values in the default array.
   *
   * @param array $params
   *   (reference ) an assoc array of name/value pairs.
   * @param array $defaults
   *   (reference ) an assoc array to hold the name / value pairs.
   *                        in a hierarchical manner
   * @param array $ids
   *   (reference) the array that holds all the db ids.
   *
   * @return CRM_Contribute_BAO_Contribution
   */
  public static function retrieve(&$params, &$defaults = [], &$ids = []) {
    $contribution = CRM_Contribute_BAO_Contribution::getValues($params, $defaults, $ids);
    return $contribution;
  }

  /**
   * Combine all the importable fields from the lower levels object.
   *
   * The ordering is important, since currently we do not have a weight
   * scheme. Adding weight is super important and should be done in the
   * next week or so, before this can be called complete.
   *
   * @param string $contactType
   * @param bool $status
   *
   * @return array
   *   array of importable Fields
   */
  public static function &importableFields($contactType = 'Individual', $status = TRUE) {
    if (!self::$_importableFields) {
      if (!self::$_importableFields) {
        self::$_importableFields = [];
      }

      if (!$status) {
        $fields = ['' => ['title' => ts('- do not import -')]];
      }
      else {
        $fields = ['' => ['title' => ts('- Contribution Fields -')]];
      }

      $note = CRM_Core_DAO_Note::import();
      $tmpFields = CRM_Contribute_DAO_Contribution::import();
      unset($tmpFields['option_value']);
      $optionFields = CRM_Core_OptionValue::getFields($mode = 'contribute');
      $contactFields = CRM_Contact_BAO_Contact::importableFields($contactType, NULL);

      // Using new Dedupe rule.
      $ruleParams = [
        'contact_type' => $contactType,
        'used' => 'Unsupervised',
      ];
      $fieldsArray = CRM_Dedupe_BAO_Rule::dedupeRuleFields($ruleParams);
      $tmpContactField = [];
      if (is_array($fieldsArray)) {
        foreach ($fieldsArray as $value) {
          //skip if there is no dupe rule
          if ($value == 'none') {
            continue;
          }
          $customFieldId = CRM_Core_DAO::getFieldValue('CRM_Core_DAO_CustomField',
            $value,
            'id',
            'column_name'
          );
          $value = $customFieldId ? 'custom_' . $customFieldId : $value;
          $tmpContactField[trim($value)] = $contactFields[trim($value)];
          if (!$status) {
            $title = $tmpContactField[trim($value)]['title'] . ' ' . ts('(match to contact)');
          }
          else {
            $title = $tmpContactField[trim($value)]['title'];
          }
          $tmpContactField[trim($value)]['title'] = $title;
        }
      }

      $tmpContactField['external_identifier'] = $contactFields['external_identifier'];
      $tmpContactField['external_identifier']['title'] = $contactFields['external_identifier']['title'] . ' ' . ts('(match to contact)');
      $tmpFields['contribution_contact_id']['title'] = $tmpFields['contribution_contact_id']['title'] . ' ' . ts('(match to contact)');
      $fields = array_merge($fields, $tmpContactField);
      $fields = array_merge($fields, $tmpFields);
      $fields = array_merge($fields, $note);
      $fields = array_merge($fields, $optionFields);
      $fields = array_merge($fields, CRM_Financial_DAO_FinancialType::export());
      $fields = array_merge($fields, CRM_Core_BAO_CustomField::getFieldsForImport('Contribution'));
      self::$_importableFields = $fields;
    }
    return self::$_importableFields;
  }

  /**
   * Combine all the exportable fields from the lower level objects.
   *
   * @param bool $checkPermission
   *
   * @return array
   *   array of exportable Fields
   */
  public static function &exportableFields($checkPermission = TRUE) {
    if (!self::$_exportableFields) {
      if (!self::$_exportableFields) {
        self::$_exportableFields = [];
      }

      $fields = CRM_Contribute_DAO_Contribution::export();
      if (CRM_Contribute_BAO_Query::isSiteHasProducts()) {
        $fields = array_merge(
          $fields,
          CRM_Contribute_DAO_Product::export(),
          CRM_Contribute_DAO_ContributionProduct::export(),
          // CRM-16713 - contribution search by Premiums on 'Find Contribution' form.
          [
            'contribution_product_id' => [
              'title' => ts('Premium'),
              'name' => 'contribution_product_id',
              'where' => 'civicrm_product.id',
              'data_type' => CRM_Utils_Type::T_INT,
            ],
          ]
        );
      }

      $financialAccount = CRM_Financial_DAO_FinancialAccount::export();

      $contributionPage = [
        'contribution_page' => [
          'title' => ts('Contribution Page'),
          'name' => 'contribution_page',
          'where' => 'civicrm_contribution_page.title',
          'data_type' => CRM_Utils_Type::T_STRING,
        ],
      ];

      $contributionNote = [
        'contribution_note' => [
          'title' => ts('Contribution Note'),
          'name' => 'contribution_note',
          'data_type' => CRM_Utils_Type::T_TEXT,
        ],
      ];

      $extraFields = [
        'contribution_batch' => [
          'title' => ts('Batch Name'),
        ],
      ];

      // CRM-17787
      $campaignTitle = [
        'contribution_campaign_title' => [
          'title' => ts('Campaign Title'),
          'name' => 'campaign_title',
          'where' => 'civicrm_campaign.title',
          'data_type' => CRM_Utils_Type::T_STRING,
        ],
      ];
      $softCreditFields = [
        'contribution_soft_credit_name' => [
          'name' => 'contribution_soft_credit_name',
          'title' => ts('Soft Credit For'),
          'where' => 'civicrm_contact_d.display_name',
          'data_type' => CRM_Utils_Type::T_STRING,
        ],
        'contribution_soft_credit_amount' => [
          'name' => 'contribution_soft_credit_amount',
          'title' => ts('Soft Credit Amount'),
          'where' => 'civicrm_contribution_soft.amount',
          'data_type' => CRM_Utils_Type::T_MONEY,
        ],
        'contribution_soft_credit_type' => [
          'name' => 'contribution_soft_credit_type',
          'title' => ts('Soft Credit Type'),
          'where' => 'contribution_softcredit_type.label',
          'data_type' => CRM_Utils_Type::T_STRING,
        ],
        'contribution_soft_credit_contribution_id' => [
          'name' => 'contribution_soft_credit_contribution_id',
          'title' => ts('Soft Credit For Contribution ID'),
          'where' => 'civicrm_contribution_soft.contribution_id',
          'data_type' => CRM_Utils_Type::T_INT,
        ],
        'contribution_soft_credit_contact_id' => [
          'name' => 'contribution_soft_credit_contact_id',
          'title' => ts('Soft Credit For Contact ID'),
          'where' => 'civicrm_contact_d.id',
          'data_type' => CRM_Utils_Type::T_INT,
        ],
      ];

      $fields = array_merge($fields, $contributionPage,
        $contributionNote, $extraFields, $softCreditFields, $financialAccount, $campaignTitle,
        CRM_Core_BAO_CustomField::getFieldsForImport('Contribution', FALSE, FALSE, FALSE, $checkPermission)
      );

      self::$_exportableFields = $fields;
    }

    return self::$_exportableFields;
  }

  /**
   * Record an activity when a payment is received.
   *
   * @todo this is intended to be moved to payment BAO class as a protected function
   * on that class. Currently being cleaned up. The addActivityForPayment doesn't really
   * merit it's own function as it makes the code less rather than more readable.
   *
   * @param int $contributionId
   * @param int $participantId
   * @param string $totalAmount
   * @param string $currency
   * @param string $trxnDate
   *
   * @throws \CRM_Core_Exception
   * @throws \CiviCRM_API3_Exception
   */
  public static function recordPaymentActivity($contributionId, $participantId, $totalAmount, $currency, $trxnDate) {
    $activityType = ($totalAmount < 0) ? 'Refund' : 'Payment';

    if ($participantId) {
      $inputParams['id'] = $participantId;
      $values = [];
      $ids = [];
      $entityObj = CRM_Event_BAO_Participant::getValues($inputParams, $values, $ids);
      $entityObj = $entityObj[$participantId];
      $title = CRM_Core_DAO::getFieldValue('CRM_Event_BAO_Event', $entityObj->event_id, 'title');
    }
    else {
      $entityObj = new CRM_Contribute_BAO_Contribution();
      $entityObj->id = $contributionId;
      $entityObj->find(TRUE);
      $title = ts('Contribution');
    }
    // @todo per block above this is not a logical splitting off of functionality.
    self::addActivityForPayment($entityObj->contact_id, $activityType, $title, $contributionId, $totalAmount, $currency, $trxnDate);
  }

  /**
   * Get the value for the To Financial Account.
   *
   * @param $contribution
   * @param $params
   *
   * @return int
   */
  public static function getToFinancialAccount($contribution, $params) {
    if (!empty($params['payment_processor_id'])) {
      return CRM_Contribute_PseudoConstant::getRelationalFinancialAccount($params['payment_processor_id'], NULL, 'civicrm_payment_processor');
    }
    if (!empty($params['payment_instrument_id'])) {
      return CRM_Financial_BAO_FinancialTypeAccount::getInstrumentFinancialAccount($contribution['payment_instrument_id']);
    }
    else {
      $relationTypeId = key(CRM_Core_PseudoConstant::accountOptionValues('financial_account_type', NULL, " AND v.name LIKE 'Asset' "));
      $queryParams = [1 => [$relationTypeId, 'Integer']];
      return CRM_Core_DAO::singleValueQuery("SELECT id FROM civicrm_financial_account WHERE is_default = 1 AND financial_account_type_id = %1", $queryParams);
    }
  }

  /**
   * Get memberships realted to the contribution.
   *
   * @param int $contributionID
   *
   * @return array
   */
  protected static function getRelatedMemberships($contributionID) {
    $membershipPayments = civicrm_api3('MembershipPayment', 'get', [
      'return' => 'membership_id',
      'contribution_id' => (int) $contributionID,
    ])['values'];
    $membershipIDs = [];
    foreach ($membershipPayments as $membershipPayment) {
      $membershipIDs[] = $membershipPayment['membership_id'];
    }
    if (empty($membershipIDs)) {
      return [];
    }
    // We could combine this with the MembershipPayment.get - we'd
    // need to re-wrangle the params (here or in the calling function)
    // as they would then me membership.contact_id, membership.is_test etc
    return civicrm_api3('Membership', 'get', [
      'id' => ['IN' => $membershipIDs],
      'return' => ['id', 'contact_id', 'membership_type_id', 'is_test', 'status_id', 'end_date'],
    ])['values'];
  }

  /**
   * Cancel contribution.
   *
   * This function should only be called from transitioncomponents - it is an interim step in refactoring.
   *
   * @param $processContributionObject
   * @param $memberships
   * @param $contributionId
   * @param $membershipStatuses
   * @param $updateResult
   * @param $participant
   * @param $oldStatus
   * @param $pledgePayment
   * @param $pledgeID
   * @param $pledgePaymentIDs
   * @param $contributionStatusId
   *
   * @return array
   */
  protected static function cancel($processContributionObject, $memberships, $contributionId, $membershipStatuses, $updateResult, $participant, $oldStatus, $pledgePayment, $pledgeID, $pledgePaymentIDs, $contributionStatusId) {
    // @fixme https://lab.civicrm.org/dev/core/issues/927 Cancelling membership etc is not desirable for all use-cases and we should be able to disable it
    $processContribution = FALSE;
    $participantStatuses = CRM_Event_PseudoConstant::participantStatus();
    if (is_array($memberships)) {
      foreach ($memberships as $membership) {
        $update = TRUE;
        //Update Membership status if there is no other completed contribution associated with the membership.
        $relatedContributions = CRM_Member_BAO_Membership::getMembershipContributionId($membership->id, TRUE);
        foreach ($relatedContributions as $contriId) {
          if ($contriId == $contributionId) {
            continue;
          }
          $statusId = CRM_Core_DAO::getFieldValue('CRM_Contribute_BAO_Contribution', $contriId, 'contribution_status_id');
          if (CRM_Core_PseudoConstant::getName('CRM_Contribute_BAO_Contribution', 'contribution_status_id', $statusId) === 'Completed') {
            $update = FALSE;
          }
        }
        if ($membership && $update) {
          $newStatus = array_search('Cancelled', $membershipStatuses);

          // Create activity
          $allStatus = CRM_Member_BAO_Membership::buildOptions('status_id', 'get');
          $activityParam = [
            'subject' => "Status changed from {$allStatus[$membership->status_id]} to {$allStatus[$newStatus]}",
            'source_contact_id' => CRM_Core_Session::singleton()->get('userID'),
            'target_contact_id' => $membership->contact_id,
            'source_record_id' => $membership->id,
            'activity_type_id' => 'Change Membership Status',
            'status_id' => 'Completed',
            'priority_id' => 'Normal',
            'activity_date_time' => 'now',
          ];

          $membership->status_id = $newStatus;
          $membership->is_override = TRUE;
          $membership->status_override_end_date = 'null';
          $membership->save();
          civicrm_api3('activity', 'create', $activityParam);

          $updateResult['updatedComponents']['CiviMember'] = $membership->status_id;
          if ($processContributionObject) {
            $processContribution = TRUE;
          }
        }
      }
    }

    if ($participant) {
      $updatedStatusId = array_search('Cancelled', $participantStatuses);
      CRM_Event_BAO_Participant::updateParticipantStatus($participant->id, $oldStatus, $updatedStatusId, TRUE);

      $updateResult['updatedComponents']['CiviEvent'] = $updatedStatusId;
      if ($processContributionObject) {
        $processContribution = TRUE;
      }
    }

    if ($pledgePayment) {
      CRM_Pledge_BAO_PledgePayment::updatePledgePaymentStatus($pledgeID, $pledgePaymentIDs, $contributionStatusId);

      $updateResult['updatedComponents']['CiviPledge'] = $contributionStatusId;
      if ($processContributionObject) {
        $processContribution = TRUE;
      }
    }
    return [$updateResult, $processContribution];
  }

  /**
   * Do any accounting updates required as a result of a contribution status change.
   *
   * Currently we have a bit of a roundabout where adding a payment results in this being called &
   * this may attempt to add a payment. We need to resolve that....
   *
   * The 'right' way to add payments or refunds is through the Payment.create api. That api
   * then updates the contribution but this process should not also record another financial trxn.
   * Currently we have weak detection fot that scenario & where it is detected the first returned
   * value is FALSE - meaning 'do not continue'.
   *
   * We should also look at the fact that the calling function - updateFinancialAccounts
   * bunches together some disparate processes rather than having separate appropriate
   * functions.
   *
   * @param array $params
   *
   * @return bool
   *   Return indicates whether the updateFinancialAccounts function should continue.
   */
  private static function updateFinancialAccountsOnContributionStatusChange(&$params) {
    $previousContributionStatus = CRM_Contribute_PseudoConstant::contributionStatus($params['prevContribution']->contribution_status_id, 'name');
    $currentContributionStatus = CRM_Core_PseudoConstant::getName('CRM_Contribute_BAO_Contribution', 'contribution_status_id', $params['contribution']->contribution_status_id);

    if ((($previousContributionStatus == 'Partially paid' && $currentContributionStatus == 'Completed')
        || ($previousContributionStatus == 'Pending refund' && $currentContributionStatus == 'Completed')
        // This concept of pay_later as different to any other sort of pending is deprecated & it's unclear
        // why it is here or where it is handled instead.
        || ($previousContributionStatus == 'Pending' && $params['prevContribution']->is_pay_later == TRUE
          && $currentContributionStatus == 'Partially paid'))
    ) {
      return FALSE;
    }

    if (self::isContributionUpdateARefund($params['prevContribution']->contribution_status_id, $params['contribution']->contribution_status_id)) {
      // @todo we should stop passing $params by reference - splitting this out would be a step towards that.
      $params['trxnParams']['total_amount'] = -$params['total_amount'];
      if (empty($params['contribution']->creditnote_id)) {
        // This is always set in the Contribution::create function.
        CRM_Core_Error::deprecatedFunctionWarning('Logic says this line is never reached & can be removed');
        $creditNoteId = self::createCreditNoteId();
        CRM_Core_DAO::setFieldValue('CRM_Contribute_DAO_Contribution', $params['contribution']->id, 'creditnote_id', $creditNoteId);
      }
    }
    elseif (($previousContributionStatus == 'Pending'
        && $params['prevContribution']->is_pay_later) || $previousContributionStatus == 'In Progress'
    ) {
      $financialTypeID = CRM_Utils_Array::value('financial_type_id', $params) ? $params['financial_type_id'] : $params['prevContribution']->financial_type_id;
      $arAccountId = CRM_Contribute_PseudoConstant::getRelationalFinancialAccount($financialTypeID, 'Accounts Receivable Account is');

      if ($currentContributionStatus == 'Cancelled') {
        // @todo we should stop passing $params by reference - splitting this out would be a step towards that.
        $params['trxnParams']['to_financial_account_id'] = $arAccountId;
        $params['trxnParams']['total_amount'] = -$params['total_amount'];
        if (empty($params['contribution']->creditnote_id)) {
          // This is always set in the Contribution::create function.
          CRM_Core_Error::deprecatedFunctionWarning('Logic says this line is never reached & can be removed');
          $creditNoteId = self::createCreditNoteId();
          CRM_Core_DAO::setFieldValue('CRM_Contribute_DAO_Contribution', $params['contribution']->id, 'creditnote_id', $creditNoteId);
        }
      }
      else {
        // @todo we should stop passing $params by reference - splitting this out would be a step towards that.
        $params['trxnParams']['from_financial_account_id'] = $arAccountId;
      }
    }

    if (($previousContributionStatus == 'Pending'
        || $previousContributionStatus == 'In Progress')
      && ($currentContributionStatus == 'Completed')
    ) {
      if (empty($params['line_item'])) {
        //CRM-15296
        //@todo - check with Joe regarding this situation - payment processors create pending transactions with no line items
        // when creating recurring membership payment - there are 2 lines to comment out in contributonPageTest if fixed
        // & this can be removed
        return FALSE;
      }
      // @todo we should stop passing $params by reference - splitting this out would be a step towards that.
      // This is an update so original currency if none passed in.
      $params['trxnParams']['currency'] = CRM_Utils_Array::value('currency', $params, $params['prevContribution']->currency);

      self::recordAlwaysAccountsReceivable($params['trxnParams'], $params);
      $trxn = CRM_Core_BAO_FinancialTrxn::create($params['trxnParams']);
      // @todo we should stop passing $params by reference - splitting this out would be a step towards that.
      $params['entity_id'] = self::$_trxnIDs[] = $trxn->id;

      $sql = "SELECT id, amount FROM civicrm_financial_item WHERE entity_id = %1 and entity_table = 'civicrm_line_item'";

      $entityParams = [
        'entity_table' => 'civicrm_financial_item',
      ];
      foreach ($params['line_item'] as $fieldId => $fields) {
        foreach ($fields as $fieldValueId => $lineItemDetails) {
          self::updateFinancialItemForLineItemToPaid($lineItemDetails['id']);
          $fparams = [
            1 => [$lineItemDetails['id'], 'Integer'],
          ];
          $financialItem = CRM_Core_DAO::executeQuery($sql, $fparams);
          while ($financialItem->fetch()) {
            $entityParams['entity_id'] = $financialItem->id;
            $entityParams['amount'] = $financialItem->amount;
            foreach (self::$_trxnIDs as $tID) {
              $entityParams['financial_trxn_id'] = $tID;
              CRM_Financial_BAO_FinancialItem::createEntityTrxn($entityParams);
            }
          }
        }
      }
      return FALSE;
    }
    return TRUE;
  }

  /**
   * It is possible to override the membership id that is updated from the payment processor.
   *
   * Historically Paypal does this & it still does if it determines data is messed up - see
   * https://lab.civicrm.org/dev/membership/issues/13
   *
   * Read the comment block on repeattransaction for more information
   * about how things should  work.
   *
   * @param int $contributionID
   * @param array $input
   *
   * @throws \CiviCRM_API3_Exception
   */
  protected static function handleMembershipIDOverride($contributionID, $input) {
    if (!empty($input['membership_id'])) {
      Civi::log()->debug('The related membership id has been overridden - this may impact data - see  https://github.com/civicrm/civicrm-core/pull/15053');
      civicrm_api3('MembershipPayment', 'create', ['contribution_id' => $contributionID, 'membership_id' => $input['membership_id']]);
    }
  }

  /**
   * Update all financial items related to the line item tto have a status of paid.
   *
   * @param int $lineItemID
   */
  private static function updateFinancialItemForLineItemToPaid($lineItemID) {
    $fparams = [
      1 => [
        CRM_Core_PseudoConstant::getKey('CRM_Financial_BAO_FinancialItem', 'status_id', 'Paid'),
        'Integer',
      ],
      2 => [$lineItemID, 'Integer'],
    ];
    $query = "UPDATE civicrm_financial_item SET status_id = %1 WHERE entity_id = %2 and entity_table = 'civicrm_line_item'";
    CRM_Core_DAO::executeQuery($query, $fparams);
  }

  /**
   * Create the financial items for the line.
   *
   * @param array $params
   * @param string $context
   * @param array $fields
   * @param array $previousLineItems
   * @param array $inputParams
   * @param bool $isARefund
   * @param array $trxnIds
   * @param int $fieldId
   *
   * @return array
   */
  private static function createFinancialItemsForLine($params, $context, $fields, array $previousLineItems, array $inputParams, bool $isARefund, $trxnIds, $fieldId): array {
    foreach ($fields as $fieldValueId => $lineItemDetails) {
      $prevFinancialItem = CRM_Financial_BAO_FinancialItem::getPreviousFinancialItem($lineItemDetails['id']);
      $receiveDate = CRM_Utils_Date::isoToMysql($params['prevContribution']->receive_date);
      if ($params['contribution']->receive_date) {
        $receiveDate = CRM_Utils_Date::isoToMysql($params['contribution']->receive_date);
      }

      $financialAccount = self::getFinancialAccountForStatusChangeTrxn($params, CRM_Utils_Array::value('financial_account_id', $prevFinancialItem));

      $currency = $params['prevContribution']->currency;
      if ($params['contribution']->currency) {
        $currency = $params['contribution']->currency;
      }
      $previousLineItemTotal = CRM_Utils_Array::value('line_total', CRM_Utils_Array::value($fieldValueId, $previousLineItems), 0);
      $itemParams = [
        'transaction_date' => $receiveDate,
        'contact_id' => $params['prevContribution']->contact_id,
        'currency' => $currency,
        'amount' => self::getFinancialItemAmountFromParams($inputParams, $context, $lineItemDetails, $isARefund, $previousLineItemTotal),
        'description' => CRM_Utils_Array::value('description', $prevFinancialItem),
        'status_id' => $prevFinancialItem['status_id'],
        'financial_account_id' => $financialAccount,
        'entity_table' => 'civicrm_line_item',
        'entity_id' => $lineItemDetails['id'],
      ];
      $financialItem = CRM_Financial_BAO_FinancialItem::create($itemParams, NULL, $trxnIds);
      $params['line_item'][$fieldId][$fieldValueId]['deferred_line_total'] = $itemParams['amount'];
      $params['line_item'][$fieldId][$fieldValueId]['financial_item_id'] = $financialItem->id;

      if (($lineItemDetails['tax_amount'] && $lineItemDetails['tax_amount'] !== 'null') || ($context == 'changeFinancialType')) {
        $taxAmount = (float) $lineItemDetails['tax_amount'];
        if ($context == 'changeFinancialType' && $lineItemDetails['tax_amount'] === 'null') {
          // reverse the Sale Tax amount if there is no tax rate associated with new Financial Type
          $taxAmount = CRM_Utils_Array::value('tax_amount', CRM_Utils_Array::value($fieldValueId, $previousLineItems), 0);
        }
        elseif ($previousLineItemTotal != $lineItemDetails['line_total']) {
          $taxAmount -= CRM_Utils_Array::value('tax_amount', CRM_Utils_Array::value($fieldValueId, $previousLineItems), 0);
        }
        if ($taxAmount != 0) {
          $itemParams['amount'] = self::getMultiplier($params['contribution']->contribution_status_id, $context) * $taxAmount;
          $itemParams['description'] = CRM_Invoicing_Utils::getTaxTerm();
          if ($lineItemDetails['financial_type_id']) {
            $itemParams['financial_account_id'] = CRM_Financial_BAO_FinancialAccount::getSalesTaxFinancialAccount($lineItemDetails['financial_type_id']);
          }
          CRM_Financial_BAO_FinancialItem::create($itemParams, NULL, $trxnIds);
        }
      }
    }
    return $params;
  }

  /**
   * Does this contributtion status update represent a refund.
   *
   * @param int $previousContributionStatusID
   * @param int $currentContributionStatusID
   *
   * @return bool
   */
  private static function isContributionUpdateARefund($previousContributionStatusID, $currentContributionStatusID): bool {
    if ('Completed' !== CRM_Core_PseudoConstant::getName('CRM_Contribute_BAO_Contribution', 'contribution_status_id', $previousContributionStatusID)) {
      return FALSE;
    }
    return self::isContributionStatusNegative($currentContributionStatusID);
  }

  /**
   * @inheritDoc
   */
  public function addSelectWhereClause() {
    $whereClauses = parent::addSelectWhereClause();
    if ($whereClauses !== []) {
      // In this case permisssions have been applied & we assume the
      // financialaclreport is applying these
      // https://github.com/JMAConsulting/biz.jmaconsulting.financialaclreport/blob/master/financialaclreport.php#L107
      return $whereClauses;
    }

    if (!CRM_Financial_BAO_FinancialType::isACLFinancialTypeStatus()) {
      return $whereClauses;
    }
    $types = CRM_Financial_BAO_FinancialType::getAllEnabledAvailableFinancialTypes();
    if (empty($types)) {
      $whereClauses['financial_type_id'] = 'IN (0)';
    }
    else {
      $whereClauses['financial_type_id'] = [
        'IN (' . implode(',', array_keys($types)) . ')',
      ];
    }
    return $whereClauses;
  }

  /**
   * @param null $status
   * @param null $startDate
   * @param null $endDate
   *
   * @return array|null
   */
  public static function getTotalAmountAndCount($status = NULL, $startDate = NULL, $endDate = NULL) {
    $where = [];
    switch ($status) {
      case 'Valid':
        $where[] = 'contribution_status_id = 1';
        break;

      case 'Cancelled':
        $where[] = 'contribution_status_id = 3';
        break;
    }

    if ($startDate) {
      $where[] = "receive_date >= '" . CRM_Utils_Type::escape($startDate, 'Timestamp') . "'";
    }
    if ($endDate) {
      $where[] = "receive_date <= '" . CRM_Utils_Type::escape($endDate, 'Timestamp') . "'";
    }
    $financialTypeACLJoin = '';
    if (CRM_Financial_BAO_FinancialType::isACLFinancialTypeStatus()) {
      $financialTypeACLJoin = " LEFT JOIN civicrm_line_item i ON (i.contribution_id = c.id AND i.entity_table = 'civicrm_contribution') ";
      $financialTypes = CRM_Contribute_PseudoConstant::financialType();
      CRM_Financial_BAO_FinancialType::getAvailableFinancialTypes($financialTypes);
      if ($financialTypes) {
        $where[] = "c.financial_type_id IN (" . implode(',', array_keys($financialTypes)) . ")";
        $where[] = "i.financial_type_id IN (" . implode(',', array_keys($financialTypes)) . ")";
      }
      else {
        $where[] = "c.financial_type_id IN (0)";
      }
    }

    $whereCond = implode(' AND ', $where);

    $query = "
    SELECT  sum( total_amount ) as total_amount,
            count( c.id ) as total_count,
            currency
      FROM  civicrm_contribution c
INNER JOIN  civicrm_contact contact ON ( contact.id = c.contact_id )
     $financialTypeACLJoin
     WHERE  $whereCond
       AND  ( is_test = 0 OR is_test IS NULL )
       AND  contact.is_deleted = 0
  GROUP BY  currency
";

    $dao = CRM_Core_DAO::executeQuery($query);
    $amount = [];
    $count = 0;
    while ($dao->fetch()) {
      $count += $dao->total_count;
      $amount[] = CRM_Utils_Money::format($dao->total_amount, $dao->currency);
    }
    if ($count) {
      return [
        'amount' => implode(', ', $amount),
        'count' => $count,
      ];
    }
    return NULL;
  }

  /**
   * Delete the indirect records associated with this contribution first.
   *
   * @param int $id
   *
   * @return mixed|null
   *   $results no of deleted Contribution on success, false otherwise
   */
  public static function deleteContribution($id) {
    CRM_Utils_Hook::pre('delete', 'Contribution', $id, CRM_Core_DAO::$_nullArray);

    $transaction = new CRM_Core_Transaction();

    $results = NULL;
    //delete activity record
    $params = [
      'source_record_id' => $id,
      // activity type id for contribution
      'activity_type_id' => 6,
    ];

    CRM_Activity_BAO_Activity::deleteActivity($params);

    //delete billing address if exists for this contribution.
    self::deleteAddress($id);

    //update pledge and pledge payment, CRM-3961
    CRM_Pledge_BAO_PledgePayment::resetPledgePayment($id);

    // remove entry from civicrm_price_set_entity, CRM-5095
    if (CRM_Price_BAO_PriceSet::getFor('civicrm_contribution', $id)) {
      CRM_Price_BAO_PriceSet::removeFrom('civicrm_contribution', $id);
    }
    // cleanup line items.
    $participantId = CRM_Core_DAO::getFieldValue('CRM_Event_DAO_ParticipantPayment', $id, 'participant_id', 'contribution_id');

    // delete any related entity_financial_trxn, financial_trxn and financial_item records.
    CRM_Core_BAO_FinancialTrxn::deleteFinancialTrxn($id);

    if ($participantId) {
      CRM_Price_BAO_LineItem::deleteLineItems($participantId, 'civicrm_participant');
    }
    else {
      CRM_Price_BAO_LineItem::deleteLineItems($id, 'civicrm_contribution');
    }

    //delete note.
    $note = CRM_Core_BAO_Note::getNote($id, 'civicrm_contribution');
    $noteId = key($note);
    if ($noteId) {
      CRM_Core_BAO_Note::del($noteId, FALSE);
    }

    $dao = new CRM_Contribute_DAO_Contribution();
    $dao->id = $id;

    $results = $dao->delete();

    $transaction->commit();

    CRM_Utils_Hook::post('delete', 'Contribution', $dao->id, $dao);

    // delete the recently created Contribution
    $contributionRecent = [
      'id' => $id,
      'type' => 'Contribution',
    ];
    CRM_Utils_Recent::del($contributionRecent);

    return $results;
  }

  /**
   * React to a financial transaction (payment) failure.
   *
   * Prior to CRM-16417 these were simply removed from the database but it has been agreed that seeing attempted
   * payments is important for forensic and outreach reasons.
   *
   * @param int $contributionID
   * @param int $contactID
   * @param string $message
   *
   * @throws \CiviCRM_API3_Exception
   */
  public static function failPayment($contributionID, $contactID, $message) {
    civicrm_api3('activity', 'create', [
      'activity_type_id' => 'Failed Payment',
      'details' => $message,
      'subject' => ts('Payment failed at payment processor'),
      'source_record_id' => $contributionID,
      'source_contact_id' => CRM_Core_Session::getLoggedInContactID() ? CRM_Core_Session::getLoggedInContactID() : $contactID,
    ]);

    // CRM-20336 Make sure that the contribution status is Failed, not Pending.
    civicrm_api3('contribution', 'create', [
      'id' => $contributionID,
      'contribution_status_id' => 'Failed',
    ]);
  }

  /**
   * Check if there is a contribution with the same trxn_id or invoice_id.
   *
   * @param array $input
   *   An assoc array of name/value pairs.
   * @param array $duplicates
   *   (reference) store ids of duplicate contribs.
   * @param int $id
   *
   * @return bool
   *   true if duplicate, false otherwise
   */
  public static function checkDuplicate($input, &$duplicates, $id = NULL) {
    if (!$id) {
      $id = CRM_Utils_Array::value('id', $input);
    }
    $trxn_id = CRM_Utils_Array::value('trxn_id', $input);
    $invoice_id = CRM_Utils_Array::value('invoice_id', $input);

    $clause = [];
    $input = [];

    if ($trxn_id) {
      $clause[] = "trxn_id = %1";
      $input[1] = [$trxn_id, 'String'];
    }

    if ($invoice_id) {
      $clause[] = "invoice_id = %2";
      $input[2] = [$invoice_id, 'String'];
    }

    if (empty($clause)) {
      return FALSE;
    }

    $clause = implode(' OR ', $clause);
    if ($id) {
      $clause = "( $clause ) AND id != %3";
      $input[3] = [$id, 'Integer'];
    }

    $query = "SELECT id FROM civicrm_contribution WHERE $clause";
    $dao = CRM_Core_DAO::executeQuery($query, $input);
    $result = FALSE;
    while ($dao->fetch()) {
      $duplicates[] = $dao->id;
      $result = TRUE;
    }
    return $result;
  }

  /**
   * Takes an associative array and creates a contribution_product object.
   *
   * the function extract all the params it needs to initialize the create a
   * contribution_product object. the params array could contain additional unused name/value
   * pairs
   *
   * @param array $params
   *   (reference) an assoc array of name/value pairs.
   *
   * @return CRM_Contribute_DAO_ContributionProduct
   */
  public static function addPremium(&$params) {
    $contributionProduct = new CRM_Contribute_DAO_ContributionProduct();
    $contributionProduct->copyValues($params);
    return $contributionProduct->save();
  }

  /**
   * Get list of contribution fields for profile.
   * For now we only allow custom contribution fields to be in
   * profile
   *
   * @param bool $addExtraFields
   *   True if special fields needs to be added.
   *
   * @return array
   *   the list of contribution fields
   */
  public static function getContributionFields($addExtraFields = TRUE) {
    $contributionFields = CRM_Contribute_DAO_Contribution::export();
    // @todo remove this - this line was added because payment_instrument_id was not
    // set to exportable - but now it is.
    $contributionFields = array_merge($contributionFields, CRM_Core_OptionValue::getFields($mode = 'contribute'));

    if ($addExtraFields) {
      $contributionFields = array_merge($contributionFields, self::getSpecialContributionFields());
    }

    $contributionFields = array_merge($contributionFields, CRM_Financial_DAO_FinancialType::export());

    foreach ($contributionFields as $key => $var) {
      if ($key == 'contribution_contact_id') {
        continue;
      }
      elseif ($key == 'contribution_campaign_id') {
        $var['title'] = ts('Campaign');
      }
      $fields[$key] = $var;
    }

    $fields = array_merge($fields, CRM_Core_BAO_CustomField::getFieldsForImport('Contribution'));
    return $fields;
  }

  /**
   * Add extra fields specific to contribution.
   */
  public static function getSpecialContributionFields() {
    $extraFields = [
      'contribution_soft_credit_name' => [
        'name' => 'contribution_soft_credit_name',
        'title' => ts('Soft Credit Name'),
        'headerPattern' => '/^soft_credit_name$/i',
        'where' => 'civicrm_contact_d.display_name',
      ],
      'contribution_soft_credit_email' => [
        'name' => 'contribution_soft_credit_email',
        'title' => ts('Soft Credit Email'),
        'headerPattern' => '/^soft_credit_email$/i',
        'where' => 'soft_email.email',
      ],
      'contribution_soft_credit_phone' => [
        'name' => 'contribution_soft_credit_phone',
        'title' => ts('Soft Credit Phone'),
        'headerPattern' => '/^soft_credit_phone$/i',
        'where' => 'soft_phone.phone',
      ],
      'contribution_soft_credit_contact_id' => [
        'name' => 'contribution_soft_credit_contact_id',
        'title' => ts('Soft Credit Contact ID'),
        'headerPattern' => '/^soft_credit_contact_id$/i',
        'where' => 'civicrm_contribution_soft.contact_id',
      ],
      'contribution_pcp_title' => [
        'name' => 'contribution_pcp_title',
        'title' => ts('Personal Campaign Page Title'),
        'headerPattern' => '/^contribution_pcp_title$/i',
        'where' => 'contribution_pcp.title',
      ],
    ];

    return $extraFields;
  }

  /**
   * @param int $pageID
   *
   * @return array
   */
  public static function getCurrentandGoalAmount($pageID) {
    $query = "
SELECT p.goal_amount as goal, sum( c.total_amount ) as total
  FROM civicrm_contribution_page p,
       civicrm_contribution      c
 WHERE p.id = c.contribution_page_id
   AND p.id = %1
   AND c.cancel_date is null
GROUP BY p.id
";

    $config = CRM_Core_Config::singleton();
    $params = [1 => [$pageID, 'Integer']];
    $dao = CRM_Core_DAO::executeQuery($query, $params);

    if ($dao->fetch()) {
      return [$dao->goal, $dao->total];
    }
    else {
      return [NULL, NULL];
    }
  }

  /**
   * Get list of contributions which credit the passed in contact ID.
   *
   * The returned array provides details about the original contribution & donor.
   *
   * @param int $honorId
   *   In Honor of Contact ID.
   *
   * @return array
   *   list of contribution fields
   * @todo - this is a confusing function called from one place. It has a test. It would be
   * nice to deprecate it.
   *
   */
  public static function getHonorContacts($honorId) {
    $params = [];
    $honorDAO = new CRM_Contribute_DAO_ContributionSoft();
    $honorDAO->contact_id = $honorId;
    $honorDAO->find();

    $type = CRM_Contribute_PseudoConstant::financialType();

    while ($honorDAO->fetch()) {
      $contributionDAO = new CRM_Contribute_DAO_Contribution();
      $contributionDAO->id = $honorDAO->contribution_id;

      if ($contributionDAO->find(TRUE)) {
        $params[$contributionDAO->id]['honor_type'] = CRM_Core_PseudoConstant::getLabel('CRM_Contribute_BAO_ContributionSoft', 'soft_credit_type_id', $honorDAO->soft_credit_type_id);
        $params[$contributionDAO->id]['honorId'] = $contributionDAO->contact_id;
        $params[$contributionDAO->id]['display_name'] = CRM_Core_DAO::getFieldValue('CRM_Contact_DAO_Contact', $contributionDAO->contact_id, 'display_name');
        $params[$contributionDAO->id]['type'] = $type[$contributionDAO->financial_type_id];
        $params[$contributionDAO->id]['type_id'] = $contributionDAO->financial_type_id;
        $params[$contributionDAO->id]['amount'] = CRM_Utils_Money::format($contributionDAO->total_amount, $contributionDAO->currency);
        $params[$contributionDAO->id]['source'] = $contributionDAO->source;
        $params[$contributionDAO->id]['receive_date'] = $contributionDAO->receive_date;
        $params[$contributionDAO->id]['contribution_status'] = CRM_Contribute_PseudoConstant::contributionStatus($contributionDAO->contribution_status_id, 'label');
      }
    }

    return $params;
  }

  /**
   * Get the sort name of a contact for a particular contribution.
   *
   * @param int $id
   *   Id of the contribution.
   *
   * @return null|string
   *   sort name of the contact if found
   */
  public static function sortName($id) {
    $id = CRM_Utils_Type::escape($id, 'Integer');

    $query = "
SELECT civicrm_contact.sort_name
FROM   civicrm_contribution, civicrm_contact
WHERE  civicrm_contribution.contact_id = civicrm_contact.id
  AND  civicrm_contribution.id = {$id}
";
    return CRM_Core_DAO::singleValueQuery($query);
  }

  /**
   * Generate summary of amount received in the current fiscal year to date from the contact or contacts.
   *
   * @param int|array $contactIDs
   *
   * @return array
   */
  public static function annual($contactIDs) {
    if (!is_array($contactIDs)) {
      // In practice I can't fine any evidence that this function is ever called with
      // anything other than a single contact id, but left like this due to .... fear.
      $contactIDs = explode(',', $contactIDs);
    }

    $query = self::getAnnualQuery($contactIDs);
    $dao = CRM_Core_DAO::executeQuery($query);
    $count = 0;
    $amount = $average = [];
    while ($dao->fetch()) {
      if ($dao->count > 0 && $dao->amount > 0) {
        $count += $dao->count;
        $amount[] = CRM_Utils_Money::format($dao->amount, $dao->currency);
        $average[] = CRM_Utils_Money::format($dao->average, $dao->currency);
      }
    }
    if ($count > 0) {
      return [
        $count,
        implode(',&nbsp;', $amount),
        implode(',&nbsp;', $average),
      ];
    }
    return [0, 0, 0];
  }

  /**
   * Check if there is a contribution with the params passed in.
   *
   * Used for trxn_id,invoice_id and contribution_id
   *
   * @param array $params
   *   An assoc array of name/value pairs.
   *
   * @return array
   *   contribution id if success else NULL
   */
  public static function checkDuplicateIds($params) {
    $dao = new CRM_Contribute_DAO_Contribution();

    $clause = [];
    $input = [];
    foreach ($params as $k => $v) {
      if ($v) {
        $clause[] = "$k = '$v'";
      }
    }
    $clause = implode(' AND ', $clause);
    $query = "SELECT id FROM civicrm_contribution WHERE $clause";
    $dao = CRM_Core_DAO::executeQuery($query, $input);

    while ($dao->fetch()) {
      $result = $dao->id;
      return $result;
    }
    return NULL;
  }

  /**
   * Get the contribution details for component export.
   *
   * @param int $exportMode
   *   Export mode.
   * @param array $componentIds
   *   Component ids.
   *
   * @return array
   *   associated array
   */
  public static function getContributionDetails($exportMode, $componentIds) {
    $paymentDetails = [];
    $componentClause = ' IN ( ' . implode(',', $componentIds) . ' ) ';

    if ($exportMode == CRM_Export_Form_Select::EVENT_EXPORT) {
      $componentSelect = " civicrm_participant_payment.participant_id id";
      $additionalClause = "
INNER JOIN civicrm_participant_payment ON (civicrm_contribution.id = civicrm_participant_payment.contribution_id
AND civicrm_participant_payment.participant_id {$componentClause} )
";
    }
    elseif ($exportMode == CRM_Export_Form_Select::MEMBER_EXPORT) {
      $componentSelect = " civicrm_membership_payment.membership_id id";
      $additionalClause = "
INNER JOIN civicrm_membership_payment ON (civicrm_contribution.id = civicrm_membership_payment.contribution_id
AND civicrm_membership_payment.membership_id {$componentClause} )
";
    }
    elseif ($exportMode == CRM_Export_Form_Select::PLEDGE_EXPORT) {
      $componentSelect = " civicrm_pledge_payment.id id";
      $additionalClause = "
INNER JOIN civicrm_pledge_payment ON (civicrm_contribution.id = civicrm_pledge_payment.contribution_id
AND civicrm_pledge_payment.pledge_id {$componentClause} )
";
    }

    $query = " SELECT total_amount, contribution_status.name as status_id, contribution_status.label as status, payment_instrument.name as payment_instrument, receive_date,
                          trxn_id, {$componentSelect}
FROM civicrm_contribution
LEFT JOIN civicrm_option_group option_group_payment_instrument ON ( option_group_payment_instrument.name = 'payment_instrument')
LEFT JOIN civicrm_option_value payment_instrument ON (civicrm_contribution.payment_instrument_id = payment_instrument.value
     AND option_group_payment_instrument.id = payment_instrument.option_group_id )
LEFT JOIN civicrm_option_group option_group_contribution_status ON (option_group_contribution_status.name = 'contribution_status')
LEFT JOIN civicrm_option_value contribution_status ON (civicrm_contribution.contribution_status_id = contribution_status.value
                               AND option_group_contribution_status.id = contribution_status.option_group_id )
{$additionalClause}
";

    $dao = CRM_Core_DAO::executeQuery($query);

    while ($dao->fetch()) {
      $paymentDetails[$dao->id] = [
        'total_amount' => $dao->total_amount,
        'contribution_status' => $dao->status,
        'receive_date' => $dao->receive_date,
        'pay_instru' => $dao->payment_instrument,
        'trxn_id' => $dao->trxn_id,
      ];
    }

    return $paymentDetails;
  }

  /**
   * Create address associated with contribution record.
   *
   * As long as there is one or more billing field in the parameters we will create the address.
   *
   * (historically the decision to create or not was based on the payment 'type' but these lines are greyer than once
   * thought).
   *
   * @param array $params
   * @param int $billingLocationTypeID
   *
   * @return int
   *   address id
   */
  public static function createAddress($params, $billingLocationTypeID) {
    list($hasBillingField, $addressParams) = self::getBillingAddressParams($params, $billingLocationTypeID);
    if ($hasBillingField) {
      $address = CRM_Core_BAO_Address::add($addressParams, FALSE);
      return $address->id;
    }
    return NULL;

  }

  /**
   * Delete billing address record related contribution.
   *
   * @param int $contributionId
   * @param int $contactId
   */
  public static function deleteAddress($contributionId = NULL, $contactId = NULL) {
    $clauses = [];
    $contactJoin = NULL;

    if ($contributionId) {
      $clauses[] = "cc.id = {$contributionId}";
    }

    if ($contactId) {
      $clauses[] = "cco.id = {$contactId}";
      $contactJoin = "INNER JOIN civicrm_contact cco ON cc.contact_id = cco.id";
    }

    if (empty($clauses)) {
      CRM_Core_Error::fatal();
    }

    $condition = implode(' OR ', $clauses);

    $query = "
SELECT     ca.id
FROM       civicrm_address ca
INNER JOIN civicrm_contribution cc ON cc.address_id = ca.id
           $contactJoin
WHERE      $condition
";
    $dao = CRM_Core_DAO::executeQuery($query);

    while ($dao->fetch()) {
      $params = ['id' => $dao->id];
      CRM_Core_BAO_Block::blockDelete('Address', $params);
    }
  }

  /**
   * This function check online pending contribution associated w/
   * Online Event Registration or Online Membership signup.
   *
   * @param int $componentId
   *   Participant/membership id.
   * @param string $componentName
   *   Event/Membership.
   *
   * @return int
   *   pending contribution id.
   */
  public static function checkOnlinePendingContribution($componentId, $componentName) {
    $contributionId = NULL;
    if (!$componentId ||
      !in_array($componentName, ['Event', 'Membership'])
    ) {
      return $contributionId;
    }

    if ($componentName == 'Event') {
      $idName = 'participant_id';
      $componentTable = 'civicrm_participant';
      $paymentTable = 'civicrm_participant_payment';
      $source = ts('Online Event Registration');
    }

    if ($componentName == 'Membership') {
      $idName = 'membership_id';
      $componentTable = 'civicrm_membership';
      $paymentTable = 'civicrm_membership_payment';
      $source = ts('Online Contribution');
    }

    $pendingStatusId = array_search('Pending', CRM_Contribute_PseudoConstant::contributionStatus(NULL, 'name'));

    $query = "
   SELECT  component.id as {$idName},
           componentPayment.contribution_id as contribution_id,
           contribution.source source,
           contribution.contribution_status_id as contribution_status_id,
           contribution.is_pay_later as is_pay_later
     FROM  $componentTable component
LEFT JOIN  $paymentTable componentPayment    ON ( componentPayment.{$idName} = component.id )
LEFT JOIN  civicrm_contribution contribution ON ( componentPayment.contribution_id = contribution.id )
    WHERE  component.id = {$componentId}";

    $dao = CRM_Core_DAO::executeQuery($query);

    while ($dao->fetch()) {
      if ($dao->contribution_id &&
        $dao->is_pay_later &&
        $dao->contribution_status_id == $pendingStatusId &&
        strpos($dao->source, $source) !== FALSE
      ) {
        $contributionId = $dao->contribution_id;
      }
    }

    return $contributionId;
  }

  /**
   * Update contribution as well as related objects.
   *
   * This function by-passes hooks - to address this - don't use this function.
   *
   * @param array $params
   * @param bool $processContributionObject
   *
   * @return array
   * @throws \Exception
   * @deprecated
   *
   * Use api contribute.completetransaction
   * For failures use failPayment (preferably exposing by api in the process).
   *
   */
  public static function transitionComponents($params, $processContributionObject = FALSE) {
    // get minimum required values.
    $contactId = CRM_Utils_Array::value('contact_id', $params);
    $componentId = CRM_Utils_Array::value('component_id', $params);
    $componentName = CRM_Utils_Array::value('componentName', $params);
    $contributionId = CRM_Utils_Array::value('contribution_id', $params);
    $contributionStatusId = CRM_Utils_Array::value('contribution_status_id', $params);

    // if we already processed contribution object pass previous status id.
    $previousContriStatusId = CRM_Utils_Array::value('previous_contribution_status_id', $params);

    $updateResult = [];

    $contributionStatuses = CRM_Contribute_PseudoConstant::contributionStatus(NULL, 'name');

    // we process only ( Completed, Cancelled, or Failed ) contributions.
    if (!$contributionId ||
      !in_array($contributionStatusId, [
        array_search('Completed', $contributionStatuses),
        array_search('Cancelled', $contributionStatuses),
        array_search('Failed', $contributionStatuses),
      ])
    ) {
      return $updateResult;
    }

    if (!$componentName || !$componentId) {
      // get the related component details.
      $componentDetails = self::getComponentDetails($contributionId);
    }
    else {
      $componentDetails['contact_id'] = $contactId;
      $componentDetails['component'] = $componentName;

      if ($componentName == 'event') {
        $componentDetails['participant'] = $componentId;
      }
      else {
        $componentDetails['membership'] = $componentId;
      }
    }

    if (!empty($componentDetails['contact_id'])) {
      $componentDetails['contact_id'] = CRM_Core_DAO::getFieldValue('CRM_Contribute_DAO_Contribution',
        $contributionId,
        'contact_id'
      );
    }

    // do check for required ids.
    if (empty($componentDetails['membership']) && empty($componentDetails['participant']) && empty($componentDetails['pledge_payment']) || empty($componentDetails['contact_id'])) {
      return $updateResult;
    }

    //now we are ready w/ required ids, start processing.

    $baseIPN = new CRM_Core_Payment_BaseIPN();

    $input = $ids = $objects = [];

    $input['component'] = CRM_Utils_Array::value('component', $componentDetails);
    $ids['contribution'] = $contributionId;
    $ids['contact'] = CRM_Utils_Array::value('contact_id', $componentDetails);
    $ids['membership'] = CRM_Utils_Array::value('membership', $componentDetails);
    $ids['participant'] = CRM_Utils_Array::value('participant', $componentDetails);
    $ids['event'] = CRM_Utils_Array::value('event', $componentDetails);
    $ids['pledge_payment'] = CRM_Utils_Array::value('pledge_payment', $componentDetails);
    $ids['contributionRecur'] = NULL;
    $ids['contributionPage'] = NULL;

    if (!$baseIPN->validateData($input, $ids, $objects, FALSE)) {
      CRM_Core_Error::fatal();
    }

    $memberships = &$objects['membership'];
    $participant = &$objects['participant'];
    $pledgePayment = &$objects['pledge_payment'];
    $contribution = &$objects['contribution'];
    $pledgeID = $oldStatus = NULL;
    $pledgePaymentIDs = [];
    if ($pledgePayment) {
      foreach ($pledgePayment as $key => $object) {
        $pledgePaymentIDs[] = $object->id;
      }
      $pledgeID = $pledgePayment[0]->pledge_id;
    }

    $membershipStatuses = CRM_Member_PseudoConstant::membershipStatus();

    if ($participant) {
      $participantStatuses = CRM_Event_PseudoConstant::participantStatus();
      $oldStatus = CRM_Core_DAO::getFieldValue('CRM_Event_DAO_Participant',
        $participant->id,
        'status_id'
      );
    }
    // we might want to process contribution object.
    $processContribution = FALSE;
    if ($contributionStatusId == array_search('Cancelled', $contributionStatuses)) {
      // Call interim cancel function - with a goal to cleaning up the signature on it and switching to a tested api Contribution.cancel function.
      list($updateResult, $processContribution) = self::cancel($processContributionObject, $memberships, $contributionId, $membershipStatuses, $updateResult, $participant, $oldStatus, $pledgePayment, $pledgeID, $pledgePaymentIDs, $contributionStatusId);
    }
    elseif ($contributionStatusId == array_search('Failed', $contributionStatuses)) {
      if (is_array($memberships)) {
        foreach ($memberships as $membership) {
          $update = TRUE;
          //Update Membership status if there is no other completed contribution associated with the membership.
          $relatedContributions = CRM_Member_BAO_Membership::getMembershipContributionId($membership->id, TRUE);
          foreach ($relatedContributions as $contriId) {
            if ($contriId == $contributionId) {
              continue;
            }
            $statusId = CRM_Core_DAO::getFieldValue('CRM_Contribute_BAO_Contribution', $contriId, 'contribution_status_id');
            if (CRM_Core_PseudoConstant::getName('CRM_Contribute_BAO_Contribution', 'contribution_status_id', $statusId) === 'Completed') {
              $update = FALSE;
            }
          }
          if ($membership && $update) {
            $membership->status_id = array_search('Expired', $membershipStatuses);
            $membership->is_override = TRUE;
            $membership->status_override_end_date = 'null';
            $membership->save();

            $updateResult['updatedComponents']['CiviMember'] = $membership->status_id;
            if ($processContributionObject) {
              $processContribution = TRUE;
            }
          }
        }
      }
      if ($participant) {
        $updatedStatusId = array_search('Cancelled', $participantStatuses);
        CRM_Event_BAO_Participant::updateParticipantStatus($participant->id, $oldStatus, $updatedStatusId, TRUE);

        $updateResult['updatedComponents']['CiviEvent'] = $updatedStatusId;
        if ($processContributionObject) {
          $processContribution = TRUE;
        }
      }

      if ($pledgePayment) {
        CRM_Pledge_BAO_PledgePayment::updatePledgePaymentStatus($pledgeID, $pledgePaymentIDs, $contributionStatusId);

        $updateResult['updatedComponents']['CiviPledge'] = $contributionStatusId;
        if ($processContributionObject) {
          $processContribution = TRUE;
        }
      }
    }
    elseif ($contributionStatusId == array_search('Completed', $contributionStatuses)) {

      // only pending contribution related object processed.
      if ($previousContriStatusId &&
        !in_array($contributionStatuses[$previousContriStatusId], [
          'Pending',
          'Partially paid',
        ])
      ) {
        // this is case when we already processed contribution object.
        return $updateResult;
      }
      elseif (!$previousContriStatusId &&
        !in_array($contributionStatuses[$contribution->contribution_status_id], [
          'Pending',
          'Partially paid',
        ])
      ) {
        // this is case when we are going to process contribution object later.
        return $updateResult;
      }

      if (is_array($memberships)) {
        foreach ($memberships as $membership) {
          if ($membership) {
            $format = '%Y%m%d';

            //CRM-4523
            $currentMembership = CRM_Member_BAO_Membership::getContactMembership($membership->contact_id,
              $membership->membership_type_id,
              $membership->is_test, $membership->id
            );

            // CRM-8141 update the membership type with the value recorded in log when membership created/renewed
            // this picks up membership type changes during renewals
            $sql = "
              SELECT    membership_type_id
              FROM      civicrm_membership_log
              WHERE     membership_id=$membership->id
              ORDER BY  id DESC
              LIMIT     1;";
            $dao = new CRM_Core_DAO();
            $dao->query($sql);
            if ($dao->fetch()) {
              if (!empty($dao->membership_type_id)) {
                $membership->membership_type_id = $dao->membership_type_id;
                $membership->save();
              }
            }
            // else fall back to using current membership type
            // Figure out number of terms
            $numterms = 1;
            $lineitems = CRM_Price_BAO_LineItem::getLineItemsByContributionID($contributionId);
            foreach ($lineitems as $lineitem) {
              if ($membership->membership_type_id == CRM_Utils_Array::value('membership_type_id', $lineitem)) {
                $numterms = CRM_Utils_Array::value('membership_num_terms', $lineitem);

                // in case membership_num_terms comes through as null or zero
                $numterms = $numterms >= 1 ? $numterms : 1;
                break;
              }
            }

            // CRM-15735-to update the membership status as per the contribution receive date
            $joinDate = NULL;
            $oldStatus = $membership->status_id;
            if (!empty($params['receive_date'])) {
              $joinDate = $params['receive_date'];
              $status = CRM_Member_BAO_MembershipStatus::getMembershipStatusByDate($membership->start_date,
                $membership->end_date,
                $membership->join_date,
                $params['receive_date'],
                FALSE,
                $membership->membership_type_id,
                (array) $membership
              );
              $membership->status_id = CRM_Utils_Array::value('id', $status, $membership->status_id);
              $membership->save();
            }

            if ($currentMembership) {
              CRM_Member_BAO_Membership::fixMembershipStatusBeforeRenew($currentMembership, NULL);
              $dates = CRM_Member_BAO_MembershipType::getRenewalDatesForMembershipType($membership->id, NULL, NULL, $numterms);
              $dates['join_date'] = CRM_Utils_Date::customFormat($currentMembership['join_date'], $format);
            }
            else {
              $dates = CRM_Member_BAO_MembershipType::getDatesForMembershipType($membership->membership_type_id, $joinDate, NULL, NULL, $numterms);
            }

            //get the status for membership.
            $calcStatus = CRM_Member_BAO_MembershipStatus::getMembershipStatusByDate($dates['start_date'],
              $dates['end_date'],
              $dates['join_date'],
              'today',
              TRUE,
              $membership->membership_type_id,
              (array) $membership
            );

            $formattedParams = [
              'status_id' => CRM_Utils_Array::value('id', $calcStatus,
                array_search('Current', $membershipStatuses)
              ),
              'join_date' => CRM_Utils_Date::customFormat($dates['join_date'], $format),
              'start_date' => CRM_Utils_Date::customFormat($dates['start_date'], $format),
              'end_date' => CRM_Utils_Date::customFormat($dates['end_date'], $format),
            ];

            CRM_Utils_Hook::pre('edit', 'Membership', $membership->id, $formattedParams);

            $membership->copyValues($formattedParams);
            $membership->save();

            //updating the membership log
            $membershipLog = [];
            $membershipLog = $formattedParams;
            $logStartDate = CRM_Utils_Date::customFormat(CRM_Utils_Array::value('log_start_date', $dates), $format);
            $logStartDate = ($logStartDate) ? CRM_Utils_Date::isoToMysql($logStartDate) : $formattedParams['start_date'];

            $membershipLog['start_date'] = $logStartDate;
            $membershipLog['membership_id'] = $membership->id;
            $membershipLog['modified_id'] = $membership->contact_id;
            $membershipLog['modified_date'] = date('Ymd');
            $membershipLog['membership_type_id'] = $membership->membership_type_id;

            CRM_Member_BAO_MembershipLog::add($membershipLog);

            //update related Memberships.
            CRM_Member_BAO_Membership::updateRelatedMemberships($membership->id, $formattedParams);

            foreach (['Membership Signup', 'Membership Renewal'] as $activityType) {
              $scheduledActivityID = CRM_Utils_Array::value('id',
                civicrm_api3('Activity', 'Get',
                  [
                    'source_record_id' => $membership->id,
                    'activity_type_id' => $activityType,
                    'status_id' => 'Scheduled',
                    'options' => [
                      'limit' => 1,
                      'sort' => 'id DESC',
                    ],
                  ]
                )
              );
              // 1. Update Schedule Membership Signup/Renewal activity to completed on successful payment of pending membership
              // 2. OR Create renewal activity scheduled if its membership renewal will be paid later
              if ($scheduledActivityID) {
                CRM_Activity_BAO_Activity::addActivity($membership, $activityType, $membership->contact_id, ['id' => $scheduledActivityID]);
                break;
              }
            }

            // track membership status change if any
            if (!empty($oldStatus) && $membership->status_id != $oldStatus) {
              $allStatus = CRM_Member_BAO_Membership::buildOptions('status_id', 'get');
              CRM_Activity_BAO_Activity::addActivity($membership,
                'Change Membership Status',
                NULL,
                [
                  'subject' => "Status changed from {$allStatus[$oldStatus]} to {$allStatus[$membership->status_id]}",
                  'source_contact_id' => $membershipLog['modified_id'],
                  'priority_id' => 'Normal',
                ]
              );
            }

            $updateResult['updatedComponents']['CiviMember'] = $membership->status_id;
            if ($processContributionObject) {
              $processContribution = TRUE;
            }

            CRM_Utils_Hook::post('edit', 'Membership', $membership->id, $membership);
          }
        }
      }

      if ($participant) {
        $updatedStatusId = array_search('Registered', $participantStatuses);
        CRM_Event_BAO_Participant::updateParticipantStatus($participant->id, $oldStatus, $updatedStatusId, TRUE);

        $updateResult['updatedComponents']['CiviEvent'] = $updatedStatusId;
        if ($processContributionObject) {
          $processContribution = TRUE;
        }
      }

      if ($pledgePayment) {
        CRM_Pledge_BAO_PledgePayment::updatePledgePaymentStatus($pledgeID, $pledgePaymentIDs, $contributionStatusId);

        $updateResult['updatedComponents']['CiviPledge'] = $contributionStatusId;
        if ($processContributionObject) {
          $processContribution = TRUE;
        }
      }
    }

    // process contribution object.
    if ($processContribution) {
      $contributionParams = [];
      $fields = [
        'contact_id',
        'total_amount',
        'receive_date',
        'is_test',
        'campaign_id',
        'payment_instrument_id',
        'trxn_id',
        'invoice_id',
        'financial_type_id',
        'contribution_status_id',
        'non_deductible_amount',
        'receipt_date',
        'check_number',
      ];
      foreach ($fields as $field) {
        if (empty($params[$field])) {
          continue;
        }
        $contributionParams[$field] = $params[$field];
      }

      $ids = ['contribution' => $contributionId];
      $contribution = CRM_Contribute_BAO_Contribution::create($contributionParams, $ids);
    }

    return $updateResult;
  }

  /**
   * Returns all contribution related object ids.
   *
   * @param $contributionId
   *
   * @return array
   */
  public static function getComponentDetails($contributionId) {
    $componentDetails = $pledgePayment = [];
    if (!$contributionId) {
      return $componentDetails;
    }

    $query = "
      SELECT    c.id                 as contribution_id,
                c.contact_id         as contact_id,
                c.contribution_recur_id,
                mp.membership_id     as membership_id,
                m.membership_type_id as membership_type_id,
                pp.participant_id    as participant_id,
                p.event_id           as event_id,
                pgp.id               as pledge_payment_id
      FROM      civicrm_contribution c
      LEFT JOIN civicrm_membership_payment  mp   ON mp.contribution_id = c.id
      LEFT JOIN civicrm_participant_payment pp   ON pp.contribution_id = c.id
      LEFT JOIN civicrm_participant         p    ON pp.participant_id  = p.id
      LEFT JOIN civicrm_membership          m    ON m.id  = mp.membership_id
      LEFT JOIN civicrm_pledge_payment      pgp  ON pgp.contribution_id  = c.id
      WHERE     c.id = $contributionId";

    $dao = CRM_Core_DAO::executeQuery($query);
    $componentDetails = [];

    while ($dao->fetch()) {
      $componentDetails['component'] = $dao->participant_id ? 'event' : 'contribute';
      $componentDetails['contact_id'] = $dao->contact_id;
      if ($dao->event_id) {
        $componentDetails['event'] = $dao->event_id;
      }
      if ($dao->participant_id) {
        $componentDetails['participant'] = $dao->participant_id;
      }
      if ($dao->membership_id) {
        if (!isset($componentDetails['membership'])) {
          $componentDetails['membership'] = $componentDetails['membership_type'] = [];
        }
        $componentDetails['membership'][] = $dao->membership_id;
        $componentDetails['membership_type'][] = $dao->membership_type_id;
      }
      if ($dao->pledge_payment_id) {
        $pledgePayment[] = $dao->pledge_payment_id;
      }
      if ($dao->contribution_recur_id) {
        $componentDetails['contributionRecur'] = $dao->contribution_recur_id;
      }
    }

    if ($pledgePayment) {
      $componentDetails['pledge_payment'] = $pledgePayment;
    }

    return $componentDetails;
  }

  /**
   * @param int $contactId
   * @param bool $includeSoftCredit
   *
   * @return null|string
   */
  public static function contributionCount($contactId, $includeSoftCredit = TRUE) {
    if (!$contactId) {
      return 0;
    }
    $financialTypes = CRM_Financial_BAO_FinancialType::getAllAvailableFinancialTypes();
    $additionalWhere = " AND contribution.financial_type_id IN (0)";
    $liWhere = " AND i.financial_type_id IN (0)";
    if (!empty($financialTypes)) {
      $additionalWhere = " AND contribution.financial_type_id IN (" . implode(',', array_keys($financialTypes)) . ")";
      $liWhere = " AND i.financial_type_id NOT IN (" . implode(',', array_keys($financialTypes)) . ")";
    }
    $contactContributionsSQL = "
      SELECT contribution.id AS id
      FROM civicrm_contribution contribution
      LEFT JOIN civicrm_line_item i ON i.contribution_id = contribution.id AND i.entity_table = 'civicrm_contribution' $liWhere
      WHERE contribution.is_test = 0 AND contribution.contact_id = {$contactId}
      $additionalWhere
      AND i.id IS NULL";

    $contactSoftCreditContributionsSQL = "
      SELECT contribution.id
      FROM civicrm_contribution contribution INNER JOIN civicrm_contribution_soft softContribution
      ON ( contribution.id = softContribution.contribution_id )
      WHERE contribution.is_test = 0 AND softContribution.contact_id = {$contactId} ";
    $query = "SELECT count( x.id ) count FROM ( ";
    $query .= $contactContributionsSQL;

    if ($includeSoftCredit) {
      $query .= " UNION ";
      $query .= $contactSoftCreditContributionsSQL;
    }

    $query .= ") x";

    return CRM_Core_DAO::singleValueQuery($query);
  }

  /**
   * Repeat a transaction as part of a recurring series.
   *
   * The ideal flow is
   * 1) Processor calls contribution.repeattransaction with contribution_status_id = Pending
   * 2) The repeattransaction loads the 'template contribution' and calls a hook to allow altering of it .
   * 3) Repeat transaction calls order.create to create the pending contribution with correct line items
   *   and associated entities.
   * 4) The  calling code calls Payment.create which in turn calls CompleteOrder (if completing)
   *   which updates the various entities and sends appropriate emails.
   *
   * Gaps in the above (@todo)
   *  1) many processors still call repeattransaction with contribution_status_id = Completed
   *  2) repeattransaction code is current munged into completeTransaction code for historical bad coding reasons
   *  3) Repeat transaction duplicates rather than calls Order.create
   *  4) Use of payment.create still limited - completetransaction is more common.
   *  5) the template transaction is tricky - historically we used the first contribution
   *    linked to a recurring contribution. More recently that was changed to be the most recent.
   *    Ideally it would be an actual template - not a contribution used as a template which
   *    would give more appropriate flexibility. Note line_items have an entity so that table
   *    could be used for the line item template - the difficulty is the custom fields...
   * 6) the determination of the membership to be linked is tricksy. The prioritised method is
   *   to load the membership(s) referred to via line items in the template transactions. Any other
   *   method is likely to lead to incorrect line items & related entities being created (as the line_item
   *   link is a required part of 'correct data'). However there are 3 other methods to determine it
   *   - membership_payment record
   *   - civicrm_membership.contribution_recur_id
   *   - input override.
   *   Passing in an input override WILL ensure the membership is extended to prevent regressions
   *   of historical processors since this has been handled 'forever' - specifically for paypal.
   *   albeit by an even nastier mechanism than the current input override.
   *   The count is out on how correct related entities wind up in this case.
   *
   * @param CRM_Contribute_BAO_Contribution $contribution
   * @param array $input
   * @param array $contributionParams
   * @param int $paymentProcessorID
   *
   * @return bool
   * @throws CiviCRM_API3_Exception
   */
  protected static function repeatTransaction(&$contribution, &$input, $contributionParams, $paymentProcessorID) {
    if (!empty($contribution->id)) {
      return FALSE;
    }
    if (empty($contribution->id)) {
      // Unclear why this would only be set for repeats.
      if (!empty($input['amount'])) {
        $contribution->total_amount = $contributionParams['total_amount'] = $input['amount'];
      }

      if (!empty($contributionParams['contribution_recur_id'])) {
        $recurringContribution = civicrm_api3('ContributionRecur', 'getsingle', [
          'id' => $contributionParams['contribution_recur_id'],
        ]);
        if (!empty($recurringContribution['campaign_id'])) {
          // CRM-17718 the campaign id on the contribution recur record should get precedence.
          $contributionParams['campaign_id'] = $recurringContribution['campaign_id'];
        }
        if (!empty($recurringContribution['financial_type_id'])) {
          // CRM-17718 the campaign id on the contribution recur record should get precedence.
          $contributionParams['financial_type_id'] = $recurringContribution['financial_type_id'];
        }
      }
      $templateContribution = CRM_Contribute_BAO_ContributionRecur::getTemplateContribution(
        $contributionParams['contribution_recur_id'],
        array_intersect_key($contributionParams, [
          'total_amount' => TRUE,
          'financial_type_id' => TRUE,
        ])
      );
      $input['line_item'] = $contributionParams['line_item'] = $templateContribution['line_item'];

      $contributionParams['status_id'] = 'Pending';
      if (isset($contributionParams['financial_type_id'])) {
        // Give precedence to passed in type.
        $contribution->financial_type_id = $contributionParams['financial_type_id'];
      }
      else {
        $contributionParams['financial_type_id'] = $templateContribution['financial_type_id'];
      }
      $contributionParams['contact_id'] = $templateContribution['contact_id'];
      $contributionParams['source'] = empty($templateContribution['source']) ? ts('Recurring contribution') : $templateContribution['source'];

      //CRM-18805 -- Contribution page not recorded on recurring transactions, Recurring contribution payments
      //do not create CC or BCC emails or profile notifications.
      //The if is just to be safe. Not sure if we can ever arrive with this unset
      // but per CRM-19478 it seems it can be 'null'
      if (isset($contribution->contribution_page_id) && is_numeric($contribution->contribution_page_id)) {
        $contributionParams['contribution_page_id'] = $contribution->contribution_page_id;
      }
      if (!empty($contribution->tax_amount)) {
        $contributionParams['tax_amount'] = $contribution->tax_amount;
      }

      $createContribution = civicrm_api3('Contribution', 'create', $contributionParams);
      $contribution->id = $createContribution['id'];
      CRM_Contribute_BAO_ContributionRecur::copyCustomValues($contributionParams['contribution_recur_id'], $contribution->id);
      self::handleMembershipIDOverride($contribution->id, $input);
      return TRUE;
    }
  }

  /**
   * Get individual id for onbehalf contribution.
   *
   * @param int $contributionId
   *   Contribution id.
   * @param int $contributorId
   *   Contributor id.
   *
   * @return array
   *   containing organization id and individual id
   */
  public static function getOnbehalfIds($contributionId, $contributorId = NULL) {

    $ids = [];

    if (!$contributionId) {
      return $ids;
    }

    // fetch contributor id if null
    if (!$contributorId) {
      $contributorId = CRM_Core_DAO::getFieldValue('CRM_Contribute_DAO_Contribution',
        $contributionId, 'contact_id'
      );
    }

    $activityTypeIds = CRM_Core_PseudoConstant::activityType(TRUE, FALSE, FALSE, 'name');
    $activityTypeId = array_search('Contribution', $activityTypeIds);

    if ($activityTypeId && $contributorId) {
      $activityQuery = "
SELECT     civicrm_activity_contact.contact_id
  FROM     civicrm_activity_contact
INNER JOIN civicrm_activity ON civicrm_activity_contact.activity_id = civicrm_activity.id
 WHERE     civicrm_activity.activity_type_id   = %1
   AND     civicrm_activity.source_record_id   = %2
   AND     civicrm_activity_contact.record_type_id = %3
";

      $activityContacts = CRM_Activity_BAO_ActivityContact::buildOptions('record_type_id', 'validate');
      $sourceID = CRM_Utils_Array::key('Activity Source', $activityContacts);

      $params = [
        1 => [$activityTypeId, 'Integer'],
        2 => [$contributionId, 'Integer'],
        3 => [$sourceID, 'Integer'],
      ];

      $sourceContactId = CRM_Core_DAO::singleValueQuery($activityQuery, $params);

      // for on behalf contribution source is individual and contributor is organization
      if ($sourceContactId && $sourceContactId != $contributorId) {
        $relationshipTypeIds = CRM_Core_PseudoConstant::relationshipType('name');
        // get rel type id for employee of relation
        foreach ($relationshipTypeIds as $id => $typeVals) {
          if ($typeVals['name_a_b'] == 'Employee of') {
            $relationshipTypeId = $id;
            break;
          }
        }

        $rel = new CRM_Contact_DAO_Relationship();
        $rel->relationship_type_id = $relationshipTypeId;
        $rel->contact_id_a = $sourceContactId;
        $rel->contact_id_b = $contributorId;
        if ($rel->find(TRUE)) {
          $ids['individual_id'] = $rel->contact_id_a;
          $ids['organization_id'] = $rel->contact_id_b;
        }
      }
    }

    return $ids;
  }

  /**
   * @return array
   */
  public static function getContributionDates() {
    $config = CRM_Core_Config::singleton();
    $currentMonth = date('m');
    $currentDay = date('d');
    if ((int ) $config->fiscalYearStart['M'] > $currentMonth ||
      ((int ) $config->fiscalYearStart['M'] == $currentMonth &&
        (int ) $config->fiscalYearStart['d'] > $currentDay
      )
    ) {
      $year = date('Y') - 1;
    }
    else {
      $year = date('Y');
    }
    $year = ['Y' => $year];
    $yearDate = $config->fiscalYearStart;
    $yearDate = array_merge($year, $yearDate);
    $yearDate = CRM_Utils_Date::format($yearDate);

    $monthDate = date('Ym') . '01';

    $now = date('Ymd');

    return [
      'now' => $now,
      'yearDate' => $yearDate,
      'monthDate' => $monthDate,
    ];
  }

  /**
   * Load objects relations to contribution object.
   * Objects are stored in the $_relatedObjects property
   * In the first instance we are just moving functionality from BASEIpn -
   *
   * @see http://issues.civicrm.org/jira/browse/CRM-9996
   *
   * Note that the unit test for the BaseIPN class tests this function
   *
   * @param array $input
   *   Input as delivered from Payment Processor.
   * @param array $ids
   *   Ids as Loaded by Payment Processor.
   * @param bool $loadAll
   *   Load all related objects - even where id not passed in? (allows API to call this).
   *
   * @return bool
   * @throws Exception
   */
  public function loadRelatedObjects(&$input, &$ids, $loadAll = FALSE) {
    // @todo deprecate this function - the steps should be
    // 1) add additional functions like 'getRelatedMemberships'
    // 2) switch all calls that refer to ->_relatedObjects to
    // using the helper functions
    // 3) make ->_relatedObjects noisy in some way (deprecation won't work for properties - hmm
    // 4) make ->_relatedObjects protected
    // 5) hone up the individual functions to not use rely on this having been called
    // 6) deprecate like mad
    if ($loadAll) {
      $ids = array_merge($this->getComponentDetails($this->id), $ids);
      if (empty($ids['contact']) && isset($this->contact_id)) {
        $ids['contact'] = $this->contact_id;
      }
    }
    if (empty($this->_component)) {
      if (!empty($ids['event'])) {
        $this->_component = 'event';
      }
      else {
        $this->_component = strtolower(CRM_Utils_Array::value('component', $input, 'contribute'));
      }
    }

    // If the object is not fully populated then make sure it is - this is a more about legacy paths & cautious
    // refactoring than anything else, and has unit test coverage.
    if (empty($this->financial_type_id)) {
      $this->find(TRUE);
    }

    $paymentProcessorID = CRM_Utils_Array::value('payment_processor_id', $input, CRM_Utils_Array::value(
      'paymentProcessor',
      $ids
    ));

    if (!isset($input['payment_processor_id']) && !$paymentProcessorID && $this->contribution_page_id) {
      $paymentProcessorID = CRM_Core_DAO::getFieldValue('CRM_Contribute_DAO_ContributionPage',
        $this->contribution_page_id,
        'payment_processor'
      );
      if ($paymentProcessorID) {
        $intentionalEnotice = $CRM16923AnUnreliableMethodHasBeenUserToDeterminePaymentProcessorFromContributionPage;
      }
    }

    $ids['contributionType'] = $this->financial_type_id;
    $ids['financialType'] = $this->financial_type_id;
    if ($this->contribution_page_id) {
      $ids['contributionPage'] = $this->contribution_page_id;
    }

    $this->loadRelatedEntitiesByID($ids);

    if (!empty($ids['contributionRecur']) && !$paymentProcessorID) {
      $paymentProcessorID = $this->_relatedObjects['contributionRecur']->payment_processor_id;
    }

    if (!empty($ids['pledge_payment'])) {
      foreach ($ids['pledge_payment'] as $key => $paymentID) {
        if (empty($paymentID)) {
          continue;
        }
        $payment = new CRM_Pledge_BAO_PledgePayment();
        $payment->id = $paymentID;
        if (!$payment->find(TRUE)) {
          throw new Exception("Could not find pledge payment record: " . $paymentID);
        }
        $this->_relatedObjects['pledge_payment'][] = $payment;
      }
    }

    // These are probably no longer accessed from anywhere
    // @todo remove this line, after ensuring not used.
    $ids = $this->loadRelatedMembershipObjects($ids);

    if ($this->_component != 'contribute') {
      // we are in event mode
      // make sure event exists and is valid
      $event = new CRM_Event_BAO_Event();
      $event->id = $ids['event'];
      if ($ids['event'] &&
        !$event->find(TRUE)
      ) {
        throw new Exception("Could not find event: " . $ids['event']);
      }

      $this->_relatedObjects['event'] = &$event;

      $participant = new CRM_Event_BAO_Participant();
      $participant->id = $ids['participant'];
      if ($ids['participant'] &&
        !$participant->find(TRUE)
      ) {
        throw new Exception("Could not find participant: " . $ids['participant']);
      }
      $participant->register_date = CRM_Utils_Date::isoToMysql($participant->register_date);

      $this->_relatedObjects['participant'] = &$participant;

      // get the payment processor id from event - this is inaccurate see CRM-16923
      // in future we should look at throwing an exception here rather than an dubious guess.
      if (!$paymentProcessorID) {
        $paymentProcessorID = $this->_relatedObjects['event']->payment_processor;
        if ($paymentProcessorID) {
          $intentionalEnotice = $CRM16923AnUnreliableMethodHasBeenUserToDeterminePaymentProcessorFromEvent;
        }
      }
    }

    if ($paymentProcessorID) {
      $paymentProcessor = CRM_Financial_BAO_PaymentProcessor::getPayment($paymentProcessorID,
        $this->is_test ? 'test' : 'live'
      );
      $ids['paymentProcessor'] = $paymentProcessorID;
      $this->_relatedObjects['paymentProcessor'] = $paymentProcessor;
    }

    // Add contribution id to $ids. CRM-20401
    $ids['contribution'] = $this->id;
    return TRUE;
  }

  /**
   * Create array of message information - ie. return html version, txt version, to field
   *
   * @param array $input
   *   Incoming information.
   *   - is_recur - should this be treated as recurring (not sure why you wouldn't
   *    just check presence of recur object but maintaining legacy approach
   *    to be careful)
   * @param array $ids
   *   IDs of related objects.
   * @param array $values
   *   Any values that may have already been compiled by calling process.
   *   This is augmented by values 'gathered' by gatherMessageValues
   * @param bool $returnMessageText
   *   Distinguishes between whether to send message or return.
   *   message text. We are working towards this function ALWAYS returning message text & calling
   *   function doing emails / pdfs with it
   *
   * @return array
   *   messages
   * @throws Exception
   */
  public function composeMessageArray(&$input, &$ids, &$values, $returnMessageText = TRUE) {
    $this->loadRelatedObjects($input, $ids);

    if (empty($this->_component)) {
      $this->_component = CRM_Utils_Array::value('component', $input);
    }

    //not really sure what params might be passed in but lets merge em into values
    $values = array_merge($this->_gatherMessageValues($input, $values, $ids), $values);
    $values['is_email_receipt'] = $this->isEmailReceipt($input, $values);
    if (!empty($input['receipt_date'])) {
      $values['receipt_date'] = $input['receipt_date'];
    }

    $template = $this->_assignMessageVariablesToTemplate($values, $input, $returnMessageText);
    //what does recur 'mean here - to do with payment processor return functionality but
    // what is the importance
    if (!empty($this->contribution_recur_id) && !empty($this->_relatedObjects['paymentProcessor'])) {
      $paymentObject = Civi\Payment\System::singleton()->getByProcessor($this->_relatedObjects['paymentProcessor']);

      $entityID = $entity = NULL;
      if (isset($ids['contribution'])) {
        $entity = 'contribution';
        $entityID = $ids['contribution'];
      }
      if (!empty($ids['membership'])) {
        //not sure whether is is possible for this not to be an array - load related contacts loads an array but this code was expecting a string
        // the addition of the casting is in case it could get here & be a string. Added in 4.6 - maybe remove later? This AuthorizeNetIPN & PaypalIPN tests hit this
        // line having loaded an array
        $ids['membership'] = (array) $ids['membership'];
        $entity = 'membership';
        $entityID = $ids['membership'][0];
      }

      $template->assign('cancelSubscriptionUrl', $paymentObject->subscriptionURL($entityID, $entity, 'cancel'));
      $template->assign('updateSubscriptionBillingUrl', $paymentObject->subscriptionURL($entityID, $entity, 'billing'));
      $template->assign('updateSubscriptionUrl', $paymentObject->subscriptionURL($entityID, $entity, 'update'));

      if ($this->_relatedObjects['paymentProcessor']['billing_mode'] & CRM_Core_Payment::BILLING_MODE_FORM) {
        //direct mode showing billing block, so use directIPN for temporary
        $template->assign('contributeMode', 'directIPN');
      }
    }
    // todo remove strtolower - check consistency
    if (strtolower($this->_component) == 'event') {
      $eventParams = ['id' => $this->_relatedObjects['participant']->event_id];
      $values['event'] = [];

      CRM_Event_BAO_Event::retrieve($eventParams, $values['event']);

      //get location details
      $locationParams = [
        'entity_id' => $this->_relatedObjects['participant']->event_id,
        'entity_table' => 'civicrm_event',
      ];
      $values['location'] = CRM_Core_BAO_Location::getValues($locationParams);

      $ufJoinParams = [
        'entity_table' => 'civicrm_event',
        'entity_id' => $ids['event'],
        'module' => 'CiviEvent',
      ];

      list($custom_pre_id,
        $custom_post_ids
        ) = CRM_Core_BAO_UFJoin::getUFGroupIds($ufJoinParams);

      $values['custom_pre_id'] = $custom_pre_id;
      $values['custom_post_id'] = $custom_post_ids;
      //for tasks 'Change Participant Status' and 'Update multiple Contributions' case
      //and cases involving status updation through ipn
      // whatever that means!
      // total_amount appears to be the preferred input param & it is unclear why we support amount here
      // perhaps we should throw an e-notice if amount is set & force total_amount?
      if (!empty($input['amount'])) {
        $values['totalAmount'] = $input['amount'];
      }
      // @todo set this in is_email_receipt, based on $this->_relatedObjects.
      if ($values['event']['is_email_confirm']) {
        $values['is_email_receipt'] = 1;
      }

      if (!empty($ids['contribution'])) {
        $values['contributionId'] = $ids['contribution'];
      }

      return CRM_Event_BAO_Event::sendMail($ids['contact'], $values,
        $this->_relatedObjects['participant']->id, $this->is_test, $returnMessageText
      );
    }
    else {
      $values['contribution_id'] = $this->id;
      if (!empty($ids['related_contact'])) {
        $values['related_contact'] = $ids['related_contact'];
        if (isset($ids['onbehalf_dupe_alert'])) {
          $values['onbehalf_dupe_alert'] = $ids['onbehalf_dupe_alert'];
        }
        $entityBlock = [
          'contact_id' => $ids['contact'],
          'location_type_id' => CRM_Core_DAO::getFieldValue('CRM_Core_DAO_LocationType',
            'Home', 'id', 'name'
          ),
        ];
        $address = CRM_Core_BAO_Address::getValues($entityBlock);
        $template->assign('onBehalfAddress', $address[$entityBlock['location_type_id']]['display']);
      }
      $isTest = FALSE;
      if ($this->is_test) {
        $isTest = TRUE;
      }
      if (!empty($this->_relatedObjects['membership'])) {
        foreach ($this->_relatedObjects['membership'] as $membership) {
          if ($membership->id) {
            $values['membership_id'] = $membership->id;
            $values['isMembership'] = TRUE;
            $values['membership_assign'] = TRUE;

            // need to set the membership values here
            $template->assign('membership_name',
              CRM_Member_PseudoConstant::membershipType($membership->membership_type_id)
            );
            $template->assign('mem_start_date', $membership->start_date);
            $template->assign('mem_join_date', $membership->join_date);
            $template->assign('mem_end_date', $membership->end_date);
            $membership_status = CRM_Member_PseudoConstant::membershipStatus($membership->status_id, NULL, 'label');
            $template->assign('mem_status', $membership_status);
            if ($membership_status == 'Pending' && $membership->is_pay_later == 1) {
              $values['is_pay_later'] = 1;
            }
            // Pass amount to floatval as string '0.00' is considered a
            // valid amount and includes Fee section in the mail.
            if (isset($values['amount'])) {
              $values['amount'] = floatval($values['amount']);
            }

            if (!empty($this->contribution_recur_id) && $paymentObject) {
              $url = $paymentObject->subscriptionURL($membership->id, 'membership', 'cancel');
              $template->assign('cancelSubscriptionUrl', $url);
              $url = $paymentObject->subscriptionURL($membership->id, 'membership', 'billing');
              $template->assign('updateSubscriptionBillingUrl', $url);
              $url = $paymentObject->subscriptionURL($entityID, $entity, 'update');
              $template->assign('updateSubscriptionUrl', $url);
            }

            $result = CRM_Contribute_BAO_ContributionPage::sendMail($ids['contact'], $values, $isTest, $returnMessageText);

            return $result;
            // otherwise if its about sending emails, continue sending without return, as we
            // don't want to exit the loop.
          }
        }
      }
      else {
        return CRM_Contribute_BAO_ContributionPage::sendMail($ids['contact'], $values, $isTest, $returnMessageText);
      }
    }
  }

  /**
   * Gather values for contribution mail - this function has been created
   * as part of CRM-9996 refactoring as a step towards simplifying the composeMessage function
   * Values related to the contribution in question are gathered
   *
   * @param array $input
   *   Input into function (probably from payment processor).
   * @param array $values
   * @param array $ids
   *   The set of ids related to the input.
   *
   * @return array
   * @throws \CRM_Core_Exception
   */
  public function _gatherMessageValues($input, &$values, $ids = []) {
    // set display address of contributor
    $values['billingName'] = '';
    if ($this->address_id) {
      $addressDetails = CRM_Core_BAO_Address::getValues(['id' => $this->address_id], FALSE, 'id');
      $addressDetails = reset($addressDetails);
      $values['billingName'] = $addressDetails['name'] ?? '';
    }
    // Else we assign the billing address of the contribution contact.
    else {
      $addressDetails = (array) CRM_Core_BAO_Address::getValues(['contact_id' => $this->contact_id, 'is_billing' => 1]);
      $addressDetails = reset($addressDetails);
    }
    $values['address'] = $addressDetails['display'] ?? '';

    if ($this->_component === 'contribute') {
      //get soft contributions
      $softContributions = CRM_Contribute_BAO_ContributionSoft::getSoftContribution($this->id, TRUE);
      if (!empty($softContributions)) {
        $values['softContributions'] = $softContributions['soft_credit'];
      }
      if (isset($this->contribution_page_id)) {
        // This is a call we want to use less, in favour of loading related objects.
        $values = $this->addContributionPageValuesToValuesHeavyHandedly($values);
        if ($this->contribution_page_id) {
          // This is precautionary as there are some legacy flows, but it should really be
          // loaded by now.
          if (!isset($this->_relatedObjects['contributionPage'])) {
            $this->loadRelatedEntitiesByID(['contributionPage' => $this->contribution_page_id]);
          }
          CRM_Contribute_BAO_Contribution_Utils::overrideDefaultCurrency($values);
        }
      }
      // no contribution page -probably back office
      else {
        // Handle re-print receipt for offline contributions (call from PDF.php - no contribution_page_id)
        $values['title'] = 'Contribution';
      }
      // set lineItem for contribution
      if ($this->id) {
        $lineItems = CRM_Price_BAO_LineItem::getLineItemsByContributionID($this->id);
        if (!empty($lineItems)) {
          $firstLineItem = reset($lineItems);
          $priceSet = [];
          if (!empty($firstLineItem['price_set_id'])) {
            $priceSet = civicrm_api3('PriceSet', 'getsingle', [
              'id' => $firstLineItem['price_set_id'],
              'return' => 'is_quick_config, id',
            ]);
            $values['priceSetID'] = $priceSet['id'];
          }
          foreach ($lineItems as &$eachItem) {
            if (isset($this->_relatedObjects['membership'])
              && is_array($this->_relatedObjects['membership'])
              && array_key_exists($eachItem['membership_type_id'], $this->_relatedObjects['membership'])) {
              $eachItem['join_date'] = CRM_Utils_Date::customFormat($this->_relatedObjects['membership'][$eachItem['membership_type_id']]->join_date);
              $eachItem['start_date'] = CRM_Utils_Date::customFormat($this->_relatedObjects['membership'][$eachItem['membership_type_id']]->start_date);
              $eachItem['end_date'] = CRM_Utils_Date::customFormat($this->_relatedObjects['membership'][$eachItem['membership_type_id']]->end_date);
            }
            // This is actually used in conjunction with is_quick_config in the template & we should deprecate it.
            // However, that does create upgrade pain so would be better to be phased in.
            $values['useForMember'] = empty($priceSet['is_quick_config']);
          }
          $values['lineItem'][0] = $lineItems;
        }
      }

      $relatedContact = CRM_Contribute_BAO_Contribution::getOnbehalfIds(
        $this->id,
        $this->contact_id
      );
      // if this is onbehalf of contribution then set related contact
      if (!empty($relatedContact['individual_id'])) {
        $values['related_contact'] = $ids['related_contact'] = $relatedContact['individual_id'];
      }
    }
    else {
      $values = array_merge($values, $this->loadEventMessageTemplateParams((int) $ids['event'], (int) $this->_relatedObjects['participant']->id, $this->id));
    }

    $groupTree = CRM_Core_BAO_CustomGroup::getTree('Contribution', NULL, $this->id);

    $customGroup = [];
    foreach ($groupTree as $key => $group) {
      if ($key === 'info') {
        continue;
      }

      foreach ($group['fields'] as $k => $customField) {
        $groupLabel = $group['title'];
        if (!empty($customField['customValue'])) {
          foreach ($customField['customValue'] as $customFieldValues) {
            $customGroup[$groupLabel][$customField['label']] = CRM_Utils_Array::value('data', $customFieldValues);
          }
        }
      }
    }
    $values['customGroup'] = $customGroup;

    $values['is_pay_later'] = $this->is_pay_later;

    return $values;
  }

  /**
   * Assign message variables to template but try to break the habit.
   *
   * In order to get away from leaky variables it is better to ensure variables are set in values and assign them
   * from the send function. Otherwise smarty variables can leak if this is called more than once - e.g. processing
   * multiple recurring payments for processors like IATS that use tokens.
   *
   * Apply variables for message to smarty template - this function is part of analysing what is in the huge
   * function & breaking it down into manageable chunks. Eventually it will be refactored into something else
   * Note we send directly from this function in some cases because it is only partly refactored.
   *
   * Don't call this function directly as the signature will change.
   *
   * @param $values
   * @param $input
   * @param bool $returnMessageText
   *
   * @return mixed
   */
  public function _assignMessageVariablesToTemplate(&$values, $input, $returnMessageText = TRUE) {
    // @todo - this should have a better separation of concerns - ie.
    // gatherMessageValues should build an array of values to be assigned to the template
    // and this function should assign them (assigning null if not set).
    // the way the pcpParams & honor Params section works is a baby-step towards this.
    $template = CRM_Core_Smarty::singleton();
    $template->assign('first_name', $this->_relatedObjects['contact']->first_name);
    $template->assign('last_name', $this->_relatedObjects['contact']->last_name);
    $template->assign('displayName', $this->_relatedObjects['contact']->display_name);
    $template->assign('billingName', $values['billingName']);

    // For some unit tests contribution cannot contain paymentProcessor information
    $billingMode = empty($this->_relatedObjects['paymentProcessor']) ? CRM_Core_Payment::BILLING_MODE_NOTIFY : $this->_relatedObjects['paymentProcessor']['billing_mode'];
    $template->assign('contributeMode', CRM_Utils_Array::value($billingMode, CRM_Core_SelectValues::contributeMode()));

    //assign honor information to receipt message
    $softRecord = CRM_Contribute_BAO_ContributionSoft::getSoftContribution($this->id);

    $honorParams = [
      'soft_credit_type' => NULL,
      'honor_block_is_active' => NULL,
    ];
    if (isset($softRecord['soft_credit'])) {
      //if id of contribution page is present
      if (!empty($values['id'])) {
        $values['honor'] = [
          'honor_profile_values' => [],
          'honor_profile_id' => CRM_Core_DAO::getFieldValue('CRM_Core_DAO_UFJoin', $values['id'], 'uf_group_id', 'entity_id'),
          'honor_id' => $softRecord['soft_credit'][1]['contact_id'],
        ];

        $honorParams['soft_credit_type'] = $softRecord['soft_credit'][1]['soft_credit_type_label'];
        $honorParams['honor_block_is_active'] = CRM_Core_DAO::getFieldValue('CRM_Core_DAO_UFJoin', $values['id'], 'is_active', 'entity_id');
      }
      else {
        //offline contribution
        $softCreditTypes = $softCredits = [];
        foreach ($softRecord['soft_credit'] as $key => $softCredit) {
          $softCreditTypes[$key] = $softCredit['soft_credit_type_label'];
          $softCredits[$key] = [
            'Name' => $softCredit['contact_name'],
            'Amount' => CRM_Utils_Money::format($softCredit['amount'], $softCredit['currency']),
          ];
        }
        $template->assign('softCreditTypes', $softCreditTypes);
        $template->assign('softCredits', $softCredits);
      }
    }

    $dao = new CRM_Contribute_DAO_ContributionProduct();
    $dao->contribution_id = $this->id;
    if ($dao->find(TRUE)) {
      $premiumId = $dao->product_id;
      $template->assign('option', $dao->product_option);

      $productDAO = new CRM_Contribute_DAO_Product();
      $productDAO->id = $premiumId;
      $productDAO->find(TRUE);
      $template->assign('selectPremium', TRUE);
      $template->assign('product_name', $productDAO->name);
      $template->assign('price', $productDAO->price);
      $template->assign('sku', $productDAO->sku);
    }
    $template->assign('title', CRM_Utils_Array::value('title', $values));
    $values['amount'] = CRM_Utils_Array::value('total_amount', $input, (CRM_Utils_Array::value('amount', $input)), NULL);
    if (!$values['amount'] && isset($this->total_amount)) {
      $values['amount'] = $this->total_amount;
    }

    $pcpParams = [
      'pcpBlock' => NULL,
      'pcp_display_in_roll' => NULL,
      'pcp_roll_nickname' => NULL,
      'pcp_personal_note' => NULL,
      'title' => NULL,
    ];

    if (strtolower($this->_component) == 'contribute') {
      //PCP Info
      $softDAO = new CRM_Contribute_DAO_ContributionSoft();
      $softDAO->contribution_id = $this->id;
      if ($softDAO->find(TRUE)) {
        $pcpParams['pcpBlock'] = TRUE;
        $pcpParams['pcp_display_in_roll'] = $softDAO->pcp_display_in_roll;
        $pcpParams['pcp_roll_nickname'] = $softDAO->pcp_roll_nickname;
        $pcpParams['pcp_personal_note'] = $softDAO->pcp_personal_note;

        //assign the pcp page title for email subject
        $pcpDAO = new CRM_PCP_DAO_PCP();
        $pcpDAO->id = $softDAO->pcp_id;
        if ($pcpDAO->find(TRUE)) {
          $pcpParams['title'] = $pcpDAO->title;
        }
      }
    }
    foreach (array_merge($honorParams, $pcpParams) as $templateKey => $templateValue) {
      $template->assign($templateKey, $templateValue);
    }

    if ($this->financial_type_id) {
      $values['financial_type_id'] = $this->financial_type_id;
    }

    $template->assign('trxn_id', $this->trxn_id);
    $template->assign('receive_date',
      CRM_Utils_Date::processDate($this->receive_date)
    );
    $values['receipt_date'] = (empty($this->receipt_date) ? NULL : $this->receipt_date);
    $template->assign('action', $this->is_test ? 1024 : 1);
    $template->assign('receipt_text',
      CRM_Utils_Array::value('receipt_text',
        $values
      )
    );
    $template->assign('is_monetary', 1);
    $template->assign('is_recur', !empty($this->contribution_recur_id));
    $template->assign('currency', $this->currency);
    $template->assign('address', CRM_Utils_Address::format($input));
    if (!empty($values['customGroup'])) {
      $template->assign('customGroup', $values['customGroup']);
    }
    if (!empty($values['softContributions'])) {
      $template->assign('softContributions', $values['softContributions']);
    }
    if ($this->_component == 'event') {
      $template->assign('title', $values['event']['title']);
      $participantRoles = CRM_Event_PseudoConstant::participantRole();
      $viewRoles = [];
      foreach (explode(CRM_Core_DAO::VALUE_SEPARATOR, $this->_relatedObjects['participant']->role_id) as $k => $v) {
        $viewRoles[] = $participantRoles[$v];
      }
      $values['event']['participant_role'] = implode(', ', $viewRoles);
      $template->assign('event', $values['event']);
      $template->assign('participant', $values['participant']);
      $template->assign('location', $values['location']);
      $template->assign('customPre', $values['custom_pre_id']);
      $template->assign('customPost', $values['custom_post_id']);

      $isTest = FALSE;
      if ($this->_relatedObjects['participant']->is_test) {
        $isTest = TRUE;
      }

      $values['params'] = [];
      //to get email of primary participant.
      $primaryEmail = CRM_Core_DAO::getFieldValue('CRM_Core_DAO_Email', $this->_relatedObjects['participant']->contact_id, 'email', 'contact_id');
      $primaryAmount[] = [
        'label' => $this->_relatedObjects['participant']->fee_level . ' - ' . $primaryEmail,
        'amount' => $this->_relatedObjects['participant']->fee_amount,
      ];
      //build an array of cId/pId of participants
      $additionalIDs = CRM_Event_BAO_Event::buildCustomProfile($this->_relatedObjects['participant']->id, NULL, $this->_relatedObjects['contact']->id, $isTest, TRUE);
      unset($additionalIDs[$this->_relatedObjects['participant']->id]);
      //send receipt to additional participant if exists
      if (count($additionalIDs)) {
        $template->assign('isPrimary', 0);
        $template->assign('customProfile', NULL);
        //set additionalParticipant true
        $values['params']['additionalParticipant'] = TRUE;
        foreach ($additionalIDs as $pId => $cId) {
          $amount = [];
          //to change the status pending to completed
          $additional = new CRM_Event_DAO_Participant();
          $additional->id = $pId;
          $additional->contact_id = $cId;
          $additional->find(TRUE);
          $additional->register_date = $this->_relatedObjects['participant']->register_date;
          $additional->status_id = 1;
          $additionalParticipantInfo = CRM_Core_DAO::getFieldValue('CRM_Core_DAO_Email', $additional->contact_id, 'email', 'contact_id');
          //if additional participant dont have email
          //use display name.
          if (!$additionalParticipantInfo) {
            $additionalParticipantInfo = CRM_Contact_BAO_Contact::displayName($additional->contact_id);
          }
          $amount[0] = [
            'label' => $additional->fee_level,
            'amount' => $additional->fee_amount,
          ];
          $primaryAmount[] = [
            'label' => $additional->fee_level . ' - ' . $additionalParticipantInfo,
            'amount' => $additional->fee_amount,
          ];
          $additional->save();
          $template->assign('amount', $amount);
          CRM_Event_BAO_Event::sendMail($cId, $values, $pId, $isTest, $returnMessageText);
        }
      }

      //build an array of custom profile and assigning it to template
      $customProfile = CRM_Event_BAO_Event::buildCustomProfile($this->_relatedObjects['participant']->id, $values, NULL, $isTest);

      if (count($customProfile)) {
        $template->assign('customProfile', $customProfile);
      }

      // for primary contact
      $values['params']['additionalParticipant'] = FALSE;
      $template->assign('isPrimary', 1);
      $template->assign('amount', $primaryAmount);
      $template->assign('register_date', CRM_Utils_Date::isoToMysql($this->_relatedObjects['participant']->register_date));
      if ($this->payment_instrument_id) {
        $paymentInstrument = CRM_Contribute_PseudoConstant::paymentInstrument();
        $template->assign('paidBy', $paymentInstrument[$this->payment_instrument_id]);
      }
      // carry paylater, since we did not created billing,
      // so need to pull email from primary location, CRM-4395
      $values['params']['is_pay_later'] = $this->_relatedObjects['participant']->is_pay_later;
    }
    return $template;
  }

  /**
   * Check whether payment processor supports
   * cancellation of contribution subscription
   *
   * @param int $contributionId
   *   Contribution id.
   *
   * @param bool $isNotCancelled
   *
   * @return bool
   */
  public static function isCancelSubscriptionSupported($contributionId, $isNotCancelled = TRUE) {
    $cacheKeyString = "$contributionId";
    $cacheKeyString .= $isNotCancelled ? '_1' : '_0';

    static $supportsCancel = [];

    if (!array_key_exists($cacheKeyString, $supportsCancel)) {
      $supportsCancel[$cacheKeyString] = FALSE;
      $isCancelled = FALSE;

      if ($isNotCancelled) {
        $isCancelled = self::isSubscriptionCancelled($contributionId);
      }

      $paymentObject = CRM_Financial_BAO_PaymentProcessor::getProcessorForEntity($contributionId, 'contribute', 'obj');
      if (!empty($paymentObject)) {
        $supportsCancel[$cacheKeyString] = $paymentObject->supports('cancelRecurring') && !$isCancelled;
      }
    }
    return $supportsCancel[$cacheKeyString];
  }

  /**
   * Check whether subscription is already cancelled.
   *
   * @param int $contributionId
   *   Contribution id.
   *
   * @return string
   *   contribution status
   */
  public static function isSubscriptionCancelled($contributionId) {
    $sql = "
       SELECT cr.contribution_status_id
         FROM civicrm_contribution_recur cr
    LEFT JOIN civicrm_contribution con ON ( cr.id = con.contribution_recur_id )
        WHERE con.id = %1 LIMIT 1";
    $params = [1 => [$contributionId, 'Integer']];
    $statusId = CRM_Core_DAO::singleValueQuery($sql, $params);
    $status = CRM_Contribute_PseudoConstant::contributionStatus($statusId, 'name');
    if ($status == 'Cancelled') {
      return TRUE;
    }
    return FALSE;
  }

  /**
   * Create all financial accounts entry.
   *
   * @param array $params
   *   Contribution object, line item array and params for trxn.
   *
   *
   * @param array $financialTrxnValues
   *
   * @return null|\CRM_Core_BAO_FinancialTrxn
   */
  public static function recordFinancialAccounts(&$params, $financialTrxnValues = NULL) {
    $skipRecords = $update = $return = $isRelatedId = FALSE;

    $additionalParticipantId = [];
    $contributionStatuses = CRM_Contribute_PseudoConstant::contributionStatus(NULL, 'name');
    $contributionStatus = empty($params['contribution_status_id']) ? NULL : $contributionStatuses[$params['contribution_status_id']];

    if (CRM_Utils_Array::value('contribution_mode', $params) == 'participant') {
      $entityId = $params['participant_id'];
      $entityTable = 'civicrm_participant';
      $additionalParticipantId = CRM_Event_BAO_Participant::getAdditionalParticipantIds($entityId);
    }
    elseif (!empty($params['membership_id'])) {
      //so far $params['membership_id'] should only be set coming in from membershipBAO::create so the situation where multiple memberships
      // are created off one contribution should be handled elsewhere
      $entityId = $params['membership_id'];
      $entityTable = 'civicrm_membership';
    }
    else {
      $entityId = $params['contribution']->id;
      $entityTable = 'civicrm_contribution';
    }

    if (CRM_Utils_Array::value('contribution_mode', $params) == 'membership') {
      $isRelatedId = TRUE;
    }

    $entityID[] = $entityId;
    if (!empty($additionalParticipantId)) {
      $entityID += $additionalParticipantId;
    }
    // prevContribution appears to mean - original contribution object- ie copy of contribution from before the update started that is being updated
    if (empty($params['prevContribution'])) {
      $entityID = NULL;
    }
    else {
      $update = TRUE;
    }

    $statusId = $params['contribution']->contribution_status_id;
    // CRM-13964 partial payment
    if ($contributionStatus == 'Partially paid'
      && !empty($params['partial_payment_total']) && !empty($params['partial_amount_to_pay'])
    ) {
      $partialAmtPay = CRM_Utils_Rule::cleanMoney($params['partial_amount_to_pay']);
      $partialAmtTotal = CRM_Utils_Rule::cleanMoney($params['partial_payment_total']);

      $fromFinancialAccountId = CRM_Financial_BAO_FinancialAccount::getFinancialAccountForFinancialTypeByRelationship($params['financial_type_id'], 'Accounts Receivable Account is');
      $statusId = CRM_Core_PseudoConstant::getKey('CRM_Contribute_BAO_Contribution', 'contribution_status_id', 'Completed');
      $params['total_amount'] = $partialAmtPay;

      $balanceTrxnInfo = CRM_Core_BAO_FinancialTrxn::getBalanceTrxnAmt($params['contribution']->id, $params['financial_type_id']);
      if (empty($balanceTrxnInfo['trxn_id'])) {
        // create new balance transaction record
        $toFinancialAccount = CRM_Financial_BAO_FinancialAccount::getFinancialAccountForFinancialTypeByRelationship($params['financial_type_id'], 'Accounts Receivable Account is');

        $balanceTrxnParams['total_amount'] = $partialAmtTotal;
        $balanceTrxnParams['to_financial_account_id'] = $toFinancialAccount;
        $balanceTrxnParams['contribution_id'] = $params['contribution']->id;
        $balanceTrxnParams['trxn_date'] = !empty($params['contribution']->receive_date) ? $params['contribution']->receive_date : date('YmdHis');
        $balanceTrxnParams['fee_amount'] = CRM_Utils_Array::value('fee_amount', $params);
        $balanceTrxnParams['net_amount'] = CRM_Utils_Array::value('net_amount', $params);
        $balanceTrxnParams['currency'] = $params['contribution']->currency;
        $balanceTrxnParams['trxn_id'] = $params['contribution']->trxn_id;
        $balanceTrxnParams['status_id'] = $statusId;
        $balanceTrxnParams['payment_instrument_id'] = $params['contribution']->payment_instrument_id;
        $balanceTrxnParams['check_number'] = CRM_Utils_Array::value('check_number', $params);
        $balanceTrxnParams['pan_truncation'] = CRM_Utils_Array::value('pan_truncation', $params);
        $balanceTrxnParams['card_type_id'] = CRM_Utils_Array::value('card_type_id', $params);
        if (!empty($balanceTrxnParams['from_financial_account_id']) &&
          ($statusId == array_search('Completed', $contributionStatuses) || $statusId == array_search('Partially paid', $contributionStatuses))
        ) {
          $balanceTrxnParams['is_payment'] = 1;
        }
        if (!empty($params['payment_processor'])) {
          $balanceTrxnParams['payment_processor_id'] = $params['payment_processor'];
        }
        $financialTxn = CRM_Core_BAO_FinancialTrxn::create($balanceTrxnParams);
      }
    }

    // build line item array if its not set in $params
    if (empty($params['line_item']) || $additionalParticipantId) {
      CRM_Price_BAO_LineItem::getLineItemArray($params, $entityID, str_replace('civicrm_', '', $entityTable), $isRelatedId);
    }

    if ($contributionStatus != 'Failed' &&
      !($contributionStatus == 'Pending' && !$params['contribution']->is_pay_later)
    ) {
      $skipRecords = TRUE;
      $pendingStatus = [
        'Pending',
        'In Progress',
      ];
      if (in_array($contributionStatus, $pendingStatus)) {
        $params['to_financial_account_id'] = CRM_Financial_BAO_FinancialAccount::getFinancialAccountForFinancialTypeByRelationship(
          $params['financial_type_id'],
          'Accounts Receivable Account is'
        );
      }
      elseif (!empty($params['payment_processor'])) {
        $params['to_financial_account_id'] = CRM_Contribute_PseudoConstant::getRelationalFinancialAccount($params['payment_processor'], NULL, 'civicrm_payment_processor');
        $params['payment_instrument_id'] = civicrm_api3('PaymentProcessor', 'getvalue', [
          'id' => $params['payment_processor'],
          'return' => 'payment_instrument_id',
        ]);
      }
      elseif (!empty($params['payment_instrument_id'])) {
        $params['to_financial_account_id'] = CRM_Financial_BAO_FinancialTypeAccount::getInstrumentFinancialAccount($params['payment_instrument_id']);
      }
      else {
        $relationTypeId = key(CRM_Core_PseudoConstant::accountOptionValues('financial_account_type', NULL, " AND v.name LIKE 'Asset' "));
        $queryParams = [1 => [$relationTypeId, 'Integer']];
        $params['to_financial_account_id'] = CRM_Core_DAO::singleValueQuery("SELECT id FROM civicrm_financial_account WHERE is_default = 1 AND financial_account_type_id = %1", $queryParams);
      }

      $totalAmount = CRM_Utils_Array::value('total_amount', $params);
      if (!isset($totalAmount) && !empty($params['prevContribution'])) {
        $totalAmount = $params['total_amount'] = $params['prevContribution']->total_amount;
      }
      //build financial transaction params
      $trxnParams = [
        'contribution_id' => $params['contribution']->id,
        'to_financial_account_id' => $params['to_financial_account_id'],
        'trxn_date' => !empty($params['contribution']->receive_date) ? $params['contribution']->receive_date : date('YmdHis'),
        'total_amount' => $totalAmount,
        'fee_amount' => CRM_Utils_Array::value('fee_amount', $params),
        'net_amount' => CRM_Utils_Array::value('net_amount', $params, $totalAmount),
        'currency' => $params['contribution']->currency,
        'trxn_id' => $params['contribution']->trxn_id,
        // @todo - this is getting the status id from the contribution - that is BAD - ie the contribution could be partially
        // paid but each payment is completed. The work around is to pass in the status_id in the trxn_params but
        // this should really default to completed (after discussion).
        'status_id' => $statusId,
        'payment_instrument_id' => CRM_Utils_Array::value('payment_instrument_id', $params, $params['contribution']->payment_instrument_id),
        'check_number' => CRM_Utils_Array::value('check_number', $params),
        'pan_truncation' => CRM_Utils_Array::value('pan_truncation', $params),
        'card_type_id' => CRM_Utils_Array::value('card_type_id', $params),
      ];
      if ($contributionStatus == 'Refunded' || $contributionStatus == 'Chargeback' || $contributionStatus == 'Cancelled') {
        $trxnParams['trxn_date'] = !empty($params['contribution']->cancel_date) ? $params['contribution']->cancel_date : date('YmdHis');
        if (isset($params['refund_trxn_id'])) {
          // CRM-17751 allow a separate trxn_id for the refund to be passed in via api & form.
          $trxnParams['trxn_id'] = $params['refund_trxn_id'];
        }
      }
      //CRM-16259, set is_payment flag for non pending status
      if (!in_array($contributionStatus, $pendingStatus)) {
        $trxnParams['is_payment'] = 1;
      }
      if (!empty($params['payment_processor'])) {
        $trxnParams['payment_processor_id'] = $params['payment_processor'];
      }

      if (isset($fromFinancialAccountId)) {
        $trxnParams['from_financial_account_id'] = $fromFinancialAccountId;
      }

      // consider external values passed for recording transaction entry
      if (!empty($financialTrxnValues)) {
        $trxnParams = array_merge($trxnParams, $financialTrxnValues);
      }
      if (empty($trxnParams['payment_processor_id'])) {
        unset($trxnParams['payment_processor_id']);
      }

      $params['trxnParams'] = $trxnParams;

      if (!empty($params['prevContribution'])) {
        $updated = FALSE;
        $params['trxnParams']['total_amount'] = $trxnParams['total_amount'] = $params['total_amount'] = $params['prevContribution']->total_amount;
        $params['trxnParams']['fee_amount'] = $params['prevContribution']->fee_amount;
        $params['trxnParams']['net_amount'] = $params['prevContribution']->net_amount;
        if (!isset($params['trxnParams']['trxn_id'])) {
          // Actually I have no idea why we are overwriting any values from the previous contribution.
          // (filling makes sense to me). However, only protecting this value as I really really know we
          // don't want this one overwritten.
          // CRM-17751.
          $params['trxnParams']['trxn_id'] = $params['prevContribution']->trxn_id;
        }
        $params['trxnParams']['status_id'] = $params['prevContribution']->contribution_status_id;

        if (!(($params['prevContribution']->contribution_status_id == array_search('Pending', $contributionStatuses)
            || $params['prevContribution']->contribution_status_id == array_search('In Progress', $contributionStatuses))
          && $params['contribution']->contribution_status_id == array_search('Completed', $contributionStatuses))
        ) {
          $params['trxnParams']['payment_instrument_id'] = $params['prevContribution']->payment_instrument_id;
          $params['trxnParams']['check_number'] = $params['prevContribution']->check_number;
        }

        //if financial type is changed
        if (!empty($params['financial_type_id']) &&
          $params['contribution']->financial_type_id != $params['prevContribution']->financial_type_id
        ) {
          $accountRelationship = 'Income Account is';
          if (!empty($params['revenue_recognition_date']) || $params['prevContribution']->revenue_recognition_date) {
            $accountRelationship = 'Deferred Revenue Account is';
          }
          $oldFinancialAccount = CRM_Financial_BAO_FinancialAccount::getFinancialAccountForFinancialTypeByRelationship($params['prevContribution']->financial_type_id, $accountRelationship);
          $newFinancialAccount = CRM_Financial_BAO_FinancialAccount::getFinancialAccountForFinancialTypeByRelationship($params['financial_type_id'], $accountRelationship);
          if ($oldFinancialAccount != $newFinancialAccount) {
            $params['total_amount'] = 0;
            if (in_array($params['contribution']->contribution_status_id, $pendingStatus)) {
              $params['trxnParams']['to_financial_account_id'] = CRM_Financial_BAO_FinancialAccount::getFinancialAccountForFinancialTypeByRelationship(
                $params['prevContribution']->financial_type_id, $accountRelationship);
            }
            else {
              $lastFinancialTrxnId = CRM_Core_BAO_FinancialTrxn::getFinancialTrxnId($params['prevContribution']->id, 'DESC');
              if (!empty($lastFinancialTrxnId['financialTrxnId'])) {
                $params['trxnParams']['to_financial_account_id'] = CRM_Core_DAO::getFieldValue('CRM_Financial_DAO_FinancialTrxn', $lastFinancialTrxnId['financialTrxnId'], 'to_financial_account_id');
              }
            }
            self::updateFinancialAccounts($params, 'changeFinancialType');
            $params['skipLineItem'] = FALSE;
            foreach ($params['line_item'] as &$lineItems) {
              foreach ($lineItems as &$line) {
                $line['financial_type_id'] = $params['financial_type_id'];
              }
            }
            CRM_Core_BAO_FinancialTrxn::createDeferredTrxn(CRM_Utils_Array::value('line_item', $params), $params['contribution'], TRUE, 'changeFinancialType');
            /* $params['trxnParams']['to_financial_account_id'] = $trxnParams['to_financial_account_id']; */
            $params['financial_account_id'] = $newFinancialAccount;
            $params['total_amount'] = $params['trxnParams']['total_amount'] = $params['trxnParams']['net_amount'] = $trxnParams['total_amount'];
            self::updateFinancialAccounts($params);
            CRM_Core_BAO_FinancialTrxn::createDeferredTrxn(CRM_Utils_Array::value('line_item', $params), $params['contribution'], TRUE);
            $params['trxnParams']['to_financial_account_id'] = $trxnParams['to_financial_account_id'];
            $updated = TRUE;
            $params['deferred_financial_account_id'] = $newFinancialAccount;
          }
        }

        //Update contribution status
        $params['trxnParams']['status_id'] = $params['contribution']->contribution_status_id;
        if (!isset($params['refund_trxn_id'])) {
          // CRM-17751 This has previously been deliberately set. No explanation as to why one variant
          // gets preference over another so I am only 'protecting' a very specific tested flow
          // and letting natural justice take care of the rest.
          $params['trxnParams']['trxn_id'] = $params['contribution']->trxn_id;
        }
        if (!empty($params['contribution_status_id']) &&
          $params['prevContribution']->contribution_status_id != $params['contribution']->contribution_status_id
        ) {
          //Update Financial Records
          $callUpdateFinancialAccounts = self::updateFinancialAccountsOnContributionStatusChange($params);
          if ($callUpdateFinancialAccounts) {
            self::updateFinancialAccounts($params, 'changedStatus');
            CRM_Core_BAO_FinancialTrxn::createDeferredTrxn(CRM_Utils_Array::value('line_item', $params), $params['contribution'], TRUE, 'changedStatus');
          }
          $updated = TRUE;
        }

        // change Payment Instrument for a Completed contribution
        // first handle special case when contribution is changed from Pending to Completed status when initial payment
        // instrument is null and now new payment instrument is added along with the payment
        if (!$params['contribution']->payment_instrument_id) {
          $params['contribution']->find(TRUE);
        }
        $params['trxnParams']['payment_instrument_id'] = $params['contribution']->payment_instrument_id;
        $params['trxnParams']['check_number'] = CRM_Utils_Array::value('check_number', $params);

        if (self::isPaymentInstrumentChange($params, $pendingStatus)) {
          $updated = CRM_Core_BAO_FinancialTrxn::updateFinancialAccountsOnPaymentInstrumentChange($params);
        }

        //if Change contribution amount
        $params['trxnParams']['fee_amount'] = CRM_Utils_Array::value('fee_amount', $params);
        $params['trxnParams']['net_amount'] = CRM_Utils_Array::value('net_amount', $params);
        $params['trxnParams']['total_amount'] = $trxnParams['total_amount'] = $params['total_amount'] = $totalAmount;
        $params['trxnParams']['trxn_id'] = $params['contribution']->trxn_id;
        if (isset($totalAmount) &&
          $totalAmount != $params['prevContribution']->total_amount
        ) {
          //Update Financial Records
          $params['trxnParams']['from_financial_account_id'] = NULL;
          self::updateFinancialAccounts($params, 'changedAmount');
          CRM_Core_BAO_FinancialTrxn::createDeferredTrxn(CRM_Utils_Array::value('line_item', $params), $params['contribution'], TRUE, 'changedAmount');
          $updated = TRUE;
        }

        if (!$updated) {
          // Looks like we might have a data correction update.
          // This would be a case where a transaction id has been entered but it is incorrect &
          // the person goes back in & fixes it, as opposed to a new transaction.
          // Currently the UI doesn't support multiple refunds against a single transaction & we are only supporting
          // the data fix scenario.
          // CRM-17751.
          if (isset($params['refund_trxn_id'])) {
            $refundIDs = CRM_Core_BAO_FinancialTrxn::getRefundTransactionIDs($params['id']);
            if (!empty($refundIDs['financialTrxnId']) && $refundIDs['trxn_id'] != $params['refund_trxn_id']) {
              civicrm_api3('FinancialTrxn', 'create', [
                'id' => $refundIDs['financialTrxnId'],
                'trxn_id' => $params['refund_trxn_id'],
              ]);
            }
          }
          $cardType = CRM_Utils_Array::value('card_type_id', $params);
          $panTruncation = CRM_Utils_Array::value('pan_truncation', $params);
          CRM_Core_BAO_FinancialTrxn::updateCreditCardDetails($params['contribution']->id, $panTruncation, $cardType);
        }
      }

      if (!$update) {
        // records finanical trxn and entity financial trxn
        // also make it available as return value
        self::recordAlwaysAccountsReceivable($trxnParams, $params);
        $trxnParams['pan_truncation'] = CRM_Utils_Array::value('pan_truncation', $params);
        $trxnParams['card_type_id'] = CRM_Utils_Array::value('card_type_id', $params);
        $return = $financialTxn = CRM_Core_BAO_FinancialTrxn::create($trxnParams);
        $params['entity_id'] = $financialTxn->id;
        if (empty($params['partial_payment_total']) && empty($params['partial_amount_to_pay'])) {
          self::$_trxnIDs[] = $financialTxn->id;
        }
      }
    }
    // record line items and financial items
    if (empty($params['skipLineItem'])) {
      CRM_Price_BAO_LineItem::processPriceSet($entityId, CRM_Utils_Array::value('line_item', $params), $params['contribution'], $entityTable, $update);
    }

    // create batch entry if batch_id is passed and
    // ensure no batch entry is been made on 'Pending' or 'Failed' contribution, CRM-16611
    if (!empty($params['batch_id']) && !empty($financialTxn)) {
      $entityParams = [
        'batch_id' => $params['batch_id'],
        'entity_table' => 'civicrm_financial_trxn',
        'entity_id' => $financialTxn->id,
      ];
      CRM_Batch_BAO_EntityBatch::create($entityParams);
    }

    // when a fee is charged
    if (!empty($params['fee_amount']) && (empty($params['prevContribution']) || $params['contribution']->fee_amount != $params['prevContribution']->fee_amount) && $skipRecords) {
      CRM_Core_BAO_FinancialTrxn::recordFees($params);
    }

    if (!empty($params['prevContribution']) && $entityTable == 'civicrm_participant'
      && $params['prevContribution']->contribution_status_id != $params['contribution']->contribution_status_id
    ) {
      $eventID = CRM_Core_DAO::getFieldValue('CRM_Event_DAO_Participant', $entityId, 'event_id');
      $feeLevel[] = str_replace('', '', $params['prevContribution']->amount_level);
      CRM_Event_BAO_Participant::createDiscountTrxn($eventID, $params, $feeLevel);
    }
    unset($params['line_item']);
    self::$_trxnIDs = NULL;
    return $return;
  }

  /**
   * Update all financial accounts entry.
   *
   * @param array $params
   *   Contribution object, line item array and params for trxn.
   *
   * @param string $context
   *   Update scenarios.
   *
   * @todo stop passing $params by reference. It is unclear the purpose of doing this &
   * adds unpredictability.
   *
   */
  public static function updateFinancialAccounts(&$params, $context = NULL) {
    $trxnID = NULL;
    $inputParams = $params;
    $isARefund = self::isContributionUpdateARefund($params['prevContribution']->contribution_status_id, $params['contribution']->contribution_status_id);

    if ($context == 'changedAmount' || $context == 'changeFinancialType') {
      // @todo we should stop passing $params by reference - splitting this out would be a step towards that.
      $params['trxnParams']['total_amount'] = $params['trxnParams']['net_amount'] = ($params['total_amount'] - $params['prevContribution']->total_amount);
    }

    $trxn = CRM_Core_BAO_FinancialTrxn::create($params['trxnParams']);
    // @todo we should stop passing $params by reference - splitting this out would be a step towards that.
    $params['entity_id'] = $trxn->id;

    $itemParams['entity_table'] = 'civicrm_line_item';
    $trxnIds['id'] = $params['entity_id'];
    $previousLineItems = CRM_Price_BAO_LineItem::getLineItemsByContributionID($params['contribution']->id);
    foreach ($params['line_item'] as $fieldId => $fields) {
      $params = self::createFinancialItemsForLine($params, $context, $fields, $previousLineItems, $inputParams, $isARefund, $trxnIds, $fieldId);
    }
  }

  /**
   * Is this contribution status a reversal.
   *
   * If so we would expect to record a negative value in the financial_trxn table.
   *
   * @param int $status_id
   *
   * @return bool
   */
  public static function isContributionStatusNegative($status_id) {
    $reversalStatuses = ['Cancelled', 'Chargeback', 'Refunded'];
    return in_array(CRM_Core_PseudoConstant::getName('CRM_Contribute_BAO_Contribution', 'contribution_status_id', $status_id), $reversalStatuses, TRUE);
  }

  /**
   * Check status validation on update of a contribution.
   *
   * @param array $values
   *   Previous form values before submit.
   *
   * @param array $fields
   *   The input form values.
   *
   * @param array $errors
   *   List of errors.
   *
   * @return bool
   */
  public static function checkStatusValidation($values, &$fields, &$errors) {
    if (CRM_Utils_System::isNull($values) && !empty($fields['id'])) {
      $values['contribution_status_id'] = CRM_Core_DAO::getFieldValue('CRM_Contribute_DAO_Contribution', $fields['id'], 'contribution_status_id');
      if ($values['contribution_status_id'] == $fields['contribution_status_id']) {
        return FALSE;
      }
    }
    $contributionStatuses = CRM_Contribute_PseudoConstant::contributionStatus(NULL, 'name');
    $checkStatus = [
      'Cancelled' => ['Completed', 'Refunded'],
      'Completed' => ['Cancelled', 'Refunded', 'Chargeback'],
      'Pending' => ['Cancelled', 'Completed', 'Failed', 'Partially paid'],
      'In Progress' => ['Cancelled', 'Completed', 'Failed'],
      'Refunded' => ['Cancelled', 'Completed'],
      'Partially paid' => ['Completed'],
      'Pending refund' => ['Completed', 'Refunded'],
    ];

    if (!in_array($contributionStatuses[$fields['contribution_status_id']],
      CRM_Utils_Array::value($contributionStatuses[$values['contribution_status_id']], $checkStatus, []))
    ) {
      $errors['contribution_status_id'] = ts("Cannot change contribution status from %1 to %2.", [
        1 => $contributionStatuses[$values['contribution_status_id']],
        2 => $contributionStatuses[$fields['contribution_status_id']],
      ]);
    }
  }

  /**
   * Delete contribution of contact.
   *
   * CRM-12155
   *
   * @param int $contactId
   *   Contact id.
   *
   */
  public static function deleteContactContribution($contactId) {
    $contribution = new CRM_Contribute_DAO_Contribution();
    $contribution->contact_id = $contactId;
    $contribution->find();
    while ($contribution->fetch()) {
      self::deleteContribution($contribution->id);
    }
  }

  /**
   * Get options for a given contribution field.
   *
   * @param string $fieldName
   * @param string $context see CRM_Core_DAO::buildOptionsContext.
   * @param array $props whatever is known about this dao object.
   *
   * @return array|bool
   * @see CRM_Core_DAO::buildOptions
   *
   */
  public static function buildOptions($fieldName, $context = NULL, $props = []) {
    $className = __CLASS__;
    $params = [];
    if (isset($props['orderColumn'])) {
      $params['orderColumn'] = $props['orderColumn'];
    }
    switch ($fieldName) {
      // This field is not part of this object but the api supports it
      case 'payment_processor':
        $className = 'CRM_Contribute_BAO_ContributionPage';
        // Filter results by contribution page
        if (!empty($props['contribution_page_id'])) {
          $page = civicrm_api('contribution_page', 'getsingle', [
            'version' => 3,
            'id' => ($props['contribution_page_id']),
          ]);
          $types = (array) CRM_Utils_Array::value('payment_processor', $page, 0);
          $params['condition'] = 'id IN (' . implode(',', $types) . ')';
        }
        break;

      // CRM-13981 This field was combined with soft_credits in 4.5 but the api still supports it
      case 'honor_type_id':
        $className = 'CRM_Contribute_BAO_ContributionSoft';
        $fieldName = 'soft_credit_type_id';
        $params['condition'] = "v.name IN ('in_honor_of','in_memory_of')";
        break;

      case 'contribution_status_id':
        if ($context !== 'validate') {
          $params['condition'] = "v.name <> 'Template'";
        }
    }
    return CRM_Core_PseudoConstant::get($className, $fieldName, $params, $context);
  }

  /**
   * Validate financial type.
   *
   * CRM-13231
   *
   * @param int $financialTypeId
   *   Financial Type id.
   *
   * @param string $relationName
   *
   * @return array|bool
   */
  public static function validateFinancialType($financialTypeId, $relationName = 'Expense Account is') {
    $financialAccount = CRM_Contribute_PseudoConstant::getRelationalFinancialAccount($financialTypeId, $relationName);

    if (!$financialAccount) {
      return CRM_Contribute_PseudoConstant::financialType($financialTypeId);
    }
    return FALSE;
  }

  /**
   * @param int $targetCid
   * @param $activityType
   * @param string $title
   * @param int $contributionId
   * @param string $totalAmount
   * @param string $currency
   * @param string $trxn_date
   *
   * @throws \CRM_Core_Exception
   * @throws \CiviCRM_API3_Exception
   */
  public static function addActivityForPayment($targetCid, $activityType, $title, $contributionId, $totalAmount, $currency, $trxn_date) {
    $paymentAmount = CRM_Utils_Money::format($totalAmount, $currency);
    $subject = "{$paymentAmount} - Offline {$activityType} for {$title}";
    $date = CRM_Utils_Date::isoToMysql($trxn_date);
    // source record id would be the contribution id
    $srcRecId = $contributionId;

    // activity params
    $activityParams = [
      'source_contact_id' => $targetCid,
      'source_record_id' => $srcRecId,
      'activity_type_id' => CRM_Core_PseudoConstant::getKey('CRM_Activity_BAO_Activity', 'activity_type_id', $activityType),
      'subject' => $subject,
      'activity_date_time' => $date,
      'status_id' => CRM_Core_PseudoConstant::getKey('CRM_Activity_BAO_Activity', 'activity_status_id', 'Completed'),
      'skipRecentView' => TRUE,
    ];

    // create activity with target contacts
    $session = CRM_Core_Session::singleton();
    $id = $session->get('userID');
    if ($id) {
      $activityParams['source_contact_id'] = $id;
      $activityParams['target_contact_id'][] = $targetCid;
    }
    civicrm_api3('Activity', 'create', $activityParams);
  }

  /**
   * Get list of payments displayed by Contribute_Page_PaymentInfo.
   *
   * @param int $id
   * @param $component
   * @param bool $getTrxnInfo
   * @param bool $usingLineTotal
   *
   * @return mixed
   */
  public static function getPaymentInfo($id, $component = 'contribution', $getTrxnInfo = FALSE, $usingLineTotal = FALSE) {
    // @todo deprecate passing in component - always call with contribution.
    if ($component == 'event') {
      $contributionId = CRM_Core_DAO::getFieldValue('CRM_Event_BAO_ParticipantPayment', $id, 'contribution_id', 'participant_id');

      if (!$contributionId) {
        if ($primaryParticipantId = CRM_Core_DAO::getFieldValue('CRM_Event_BAO_Participant', $id, 'registered_by_id')) {
          $contributionId = CRM_Core_DAO::getFieldValue('CRM_Event_BAO_ParticipantPayment', $primaryParticipantId, 'contribution_id', 'participant_id');
          $id = $primaryParticipantId;
        }
        if (!$contributionId) {
          return;
        }
      }
    }
    elseif ($component == 'membership') {
      $contributionId = CRM_Core_DAO::getFieldValue('CRM_Member_DAO_MembershipPayment', $id, 'contribution_id', 'membership_id');
    }
    else {
      $contributionId = $id;
    }

    $total = CRM_Core_BAO_FinancialTrxn::getBalanceTrxnAmt($contributionId);
    $baseTrxnId = !empty($total['trxn_id']) ? $total['trxn_id'] : NULL;
    if (!$baseTrxnId) {
      $baseTrxnId = CRM_Core_BAO_FinancialTrxn::getFinancialTrxnId($contributionId);
      $baseTrxnId = $baseTrxnId['financialTrxnId'];
    }
    if (empty($total['total_amount']) || $usingLineTotal) {
      $total = CRM_Price_BAO_LineItem::getLineTotal($contributionId);
    }
    else {
      $baseTrxnId = $total['trxn_id'];
      $total = $total['total_amount'];
    }

    $paymentBalance = CRM_Contribute_BAO_Contribution::getContributionBalance($contributionId, $total);

    $contribution = civicrm_api3('Contribution', 'getsingle', [
      'id' => $contributionId,
      'return' => [
        'currency',
        'is_pay_later',
        'contribution_status_id',
        'financial_type_id',
      ],
    ]);

    $info['payLater'] = $contribution['is_pay_later'];
    $info['contribution_status'] = $contribution['contribution_status'];
    $info['currency'] = $contribution['currency'];

    $financialTypeId = $contribution['financial_type_id'];
    $feeFinancialAccount = CRM_Contribute_PseudoConstant::getRelationalFinancialAccount($financialTypeId, 'Expense Account is');

    $info['total'] = $total;
    $info['paid'] = $total - $paymentBalance;
    $info['balance'] = $paymentBalance;
    $info['id'] = $id;
    $info['component'] = $component;
    $rows = [];
    if ($getTrxnInfo && $baseTrxnId) {
      // Need to exclude fee trxn rows so filter out rows where TO FINANCIAL ACCOUNT is expense account
      $sql = "
        SELECT GROUP_CONCAT(fa.`name`) as financial_account,
          ft.total_amount,
          ft.payment_instrument_id,
          ft.trxn_date, ft.trxn_id, ft.status_id, ft.check_number, ft.currency, ft.pan_truncation, ft.card_type_id, ft.id

        FROM civicrm_contribution con
          LEFT JOIN civicrm_entity_financial_trxn eft ON (eft.entity_id = con.id AND eft.entity_table = 'civicrm_contribution')
          INNER JOIN civicrm_financial_trxn ft ON ft.id = eft.financial_trxn_id
            AND ft.to_financial_account_id != %2
          LEFT JOIN civicrm_entity_financial_trxn ef ON (ef.financial_trxn_id = ft.id AND ef.entity_table = 'civicrm_financial_item')
          LEFT JOIN civicrm_financial_item fi ON fi.id = ef.entity_id
          LEFT JOIN civicrm_financial_account fa ON fa.id = fi.financial_account_id

        WHERE con.id = %1 AND ft.is_payment = 1
        GROUP BY ft.id";
      $queryParams = [
        1 => [$contributionId, 'Integer'],
        2 => [$feeFinancialAccount, 'Integer'],
      ];
      $resultDAO = CRM_Core_DAO::executeQuery($sql, $queryParams);
      $statuses = CRM_Contribute_PseudoConstant::contributionStatus(NULL, 'label');

      while ($resultDAO->fetch()) {
        $paidByLabel = CRM_Core_PseudoConstant::getLabel('CRM_Core_BAO_FinancialTrxn', 'payment_instrument_id', $resultDAO->payment_instrument_id);
        $paidByName = CRM_Core_PseudoConstant::getName('CRM_Core_BAO_FinancialTrxn', 'payment_instrument_id', $resultDAO->payment_instrument_id);
        if ($resultDAO->card_type_id) {
          $creditCardType = CRM_Core_PseudoConstant::getLabel('CRM_Core_BAO_FinancialTrxn', 'card_type_id', $resultDAO->card_type_id);
          $pantruncation = '';
          if ($resultDAO->pan_truncation) {
            $pantruncation = ": {$resultDAO->pan_truncation}";
          }
          $paidByLabel .= " ({$creditCardType}{$pantruncation})";
        }

        // show payment edit link only for payments done via backoffice form
        $paymentEditLink = '';
        if (empty($resultDAO->payment_processor_id) && CRM_Core_Permission::check('edit contributions')) {
          $links = [
            CRM_Core_Action::UPDATE => [
              'name' => "<i class='crm-i fa-pencil'></i>",
              'url' => 'civicrm/payment/edit',
              'class' => 'medium-popup',
              'qs' => "reset=1&id=%%id%%&contribution_id=%%contribution_id%%",
              'title' => ts('Edit Payment'),
            ],
          ];
          $paymentEditLink = CRM_Core_Action::formLink(
            $links,
            CRM_Core_Action::mask([CRM_Core_Permission::EDIT]),
            [
              'id' => $resultDAO->id,
              'contribution_id' => $contributionId,
            ]
          );
        }

        $val = [
          'id' => $resultDAO->id,
          'total_amount' => $resultDAO->total_amount,
          'financial_type' => $resultDAO->financial_account,
          'payment_instrument' => $paidByLabel,
          'receive_date' => $resultDAO->trxn_date,
          'trxn_id' => $resultDAO->trxn_id,
          'status' => $statuses[$resultDAO->status_id],
          'currency' => $resultDAO->currency,
          'action' => $paymentEditLink,
        ];
        if ($paidByName == 'Check') {
          $val['check_number'] = $resultDAO->check_number;
        }
        $rows[] = $val;
      }
      $info['transaction'] = $rows;
    }

    $info['payment_links'] = self::getContributionPaymentLinks($id, $paymentBalance, $info['contribution_status']);
    return $info;
  }

  /**
   * Get the outstanding balance on a contribution.
   *
   * @param int $contributionId
   * @param float $contributionTotal
   *   Optional amount to override the saved amount paid (e.g if calculating what it WILL be).
   *
   * @return float
   */
  public static function getContributionBalance($contributionId, $contributionTotal = NULL) {
    if ($contributionTotal === NULL) {
      $contributionTotal = CRM_Price_BAO_LineItem::getLineTotal($contributionId);
    }

    return (float) CRM_Utils_Money::subtractCurrencies(
      $contributionTotal,
      CRM_Core_BAO_FinancialTrxn::getTotalPayments($contributionId, TRUE) ?: 0,
      CRM_Core_DAO::getFieldValue('CRM_Contribute_DAO_Contribution', $contributionId, 'currency')
    );
  }

  /**
   * Get the tax amount (misnamed function).
   *
   * @param array $params
   * @param bool $isLineItem
   *
   * @return array
   */
  public static function checkTaxAmount($params, $isLineItem = FALSE) {
    $taxRates = CRM_Core_PseudoConstant::getTaxRates();

    // This function should be only called after standardisation (removal of
    // thousand separator & using a decimal point for cents separator.
    // However, we don't know if that is always true :-(
    // There is a deprecation notice tho :-)
    $unknownIfMoneyIsClean = empty($params['skipCleanMoney']) && !$isLineItem;
    // Update contribution.
    if (!empty($params['id'])) {
      // CRM-19126 and CRM-19152 If neither total or financial_type_id are set on an update
      // there are no tax implications - early return.
      if (!isset($params['total_amount']) && !isset($params['financial_type_id'])) {
        return $params;
      }
      if (empty($params['prevContribution'])) {
        $params['prevContribution'] = self::getOriginalContribution($params['id']);
      }

      foreach (['total_amount', 'financial_type_id', 'fee_amount'] as $field) {
        if (!isset($params[$field])) {
          if ($field == 'total_amount' && $params['prevContribution']->tax_amount) {
            // Tax amount gets added back on later....
            $params['total_amount'] = $params['prevContribution']->total_amount -
              $params['prevContribution']->tax_amount;
          }
          else {
            $params[$field] = $params['prevContribution']->$field;
            if ($params[$field] != $params['prevContribution']->$field) {
            }
          }
        }
      }

      self::calculateMissingAmountParams($params, $params['id']);
      if (!array_key_exists($params['financial_type_id'], $taxRates)) {
        // Assign tax Amount on update of contribution
        if (!empty($params['prevContribution']->tax_amount)) {
          $params['tax_amount'] = 'null';
          CRM_Price_BAO_LineItem::getLineItemArray($params, [$params['id']]);
          foreach ($params['line_item'] as $setID => $priceField) {
            foreach ($priceField as $priceFieldID => $priceFieldValue) {
              $params['line_item'][$setID][$priceFieldID]['tax_amount'] = $params['tax_amount'];
            }
          }
        }
      }
    }

    // New Contribution and update of contribution with tax rate financial type
    if (isset($params['financial_type_id']) && array_key_exists($params['financial_type_id'], $taxRates) &&
      empty($params['skipLineItem']) && !$isLineItem
    ) {
      $taxRateParams = $taxRates[$params['financial_type_id']];
      $taxAmount = CRM_Contribute_BAO_Contribution_Utils::calculateTaxAmount(CRM_Utils_Array::value('total_amount', $params), $taxRateParams, $unknownIfMoneyIsClean);
      $params['tax_amount'] = round($taxAmount['tax_amount'], 2);

      // Get Line Item on update of contribution
      if (isset($params['id'])) {
        CRM_Price_BAO_LineItem::getLineItemArray($params, [$params['id']]);
      }
      else {
        CRM_Price_BAO_LineItem::getLineItemArray($params);
      }
      foreach ($params['line_item'] as $setID => $priceField) {
        foreach ($priceField as $priceFieldID => $priceFieldValue) {
          $params['line_item'][$setID][$priceFieldID]['tax_amount'] = $params['tax_amount'];
        }
      }
      $params['total_amount'] = CRM_Utils_Array::value('total_amount', $params) + $params['tax_amount'];
    }
    elseif (isset($params['api.line_item.create'])) {
      // Update total amount of contribution using lineItem
      $taxAmountArray = [];
      foreach ($params['api.line_item.create'] as $key => $value) {
        if (isset($value['financial_type_id']) && array_key_exists($value['financial_type_id'], $taxRates)) {
          $taxRate = $taxRates[$value['financial_type_id']];
          $taxAmount = CRM_Contribute_BAO_Contribution_Utils::calculateTaxAmount($value['line_total'], $taxRate);
          $taxAmountArray[] = round($taxAmount['tax_amount'], 2);
        }
      }
      $params['tax_amount'] = array_sum($taxAmountArray);
      $params['total_amount'] = $params['total_amount'] + $params['tax_amount'];
    }
    else {
      // update line item of contrbution
      if (isset($params['financial_type_id']) && array_key_exists($params['financial_type_id'], $taxRates) && $isLineItem) {
        $taxRate = $taxRates[$params['financial_type_id']];
        $taxAmount = CRM_Contribute_BAO_Contribution_Utils::calculateTaxAmount($params['line_total'], $taxRate, $unknownIfMoneyIsClean);
        $params['tax_amount'] = round($taxAmount['tax_amount'], 2);
      }
    }
    return $params;
  }

  /**
   * Check financial type validation on update of a contribution.
   *
   * @param int $financialTypeId
   *   Value of latest Financial Type.
   *
   * @param int $contributionId
   *   Contribution Id.
   *
   * @param array $errors
   *   List of errors.
   *
   * @return void
   */
  public static function checkFinancialTypeChange($financialTypeId, $contributionId, &$errors) {
    if (!empty($financialTypeId)) {
      $oldFinancialTypeId = CRM_Core_DAO::getFieldValue('CRM_Contribute_DAO_Contribution', $contributionId, 'financial_type_id');
      if ($oldFinancialTypeId == $financialTypeId) {
        return;
      }
    }
    $sql = 'SELECT financial_type_id FROM civicrm_line_item WHERE contribution_id = %1 GROUP BY financial_type_id;';
    $params = [
      '1' => [$contributionId, 'Integer'],
    ];
    $result = CRM_Core_DAO::executeQuery($sql, $params);
    if ($result->N > 1) {
      $errors['financial_type_id'] = ts('One or more line items have a different financial type than the contribution. Editing the financial type is not yet supported in this situation.');
    }
  }

  /**
   * Update related pledge payment payments.
   *
   * This function has been refactored out of the back office contribution form and may
   * still overlap with other functions.
   *
   * @param string $action
   * @param int $pledgePaymentID
   * @param int $contributionID
   * @param bool $adjustTotalAmount
   * @param float $total_amount
   * @param float $original_total_amount
   * @param int $contribution_status_id
   * @param int $original_contribution_status_id
   */
  public static function updateRelatedPledge(
    $action,
    $pledgePaymentID,
    $contributionID,
    $adjustTotalAmount,
    $total_amount,
    $original_total_amount,
    $contribution_status_id,
    $original_contribution_status_id
  ) {
    if (!$pledgePaymentID && $action & CRM_Core_Action::ADD && !$contributionID) {
      return;
    }

    if ($pledgePaymentID) {
      //store contribution id in payment record.
      CRM_Core_DAO::setFieldValue('CRM_Pledge_DAO_PledgePayment', $pledgePaymentID, 'contribution_id', $contributionID);
    }
    else {
      $pledgePaymentID = CRM_Core_DAO::getFieldValue('CRM_Pledge_DAO_PledgePayment',
        $contributionID,
        'id',
        'contribution_id'
      );
    }

    if (!$pledgePaymentID) {
      return;
    }
    $pledgeID = CRM_Core_DAO::getFieldValue('CRM_Pledge_DAO_PledgePayment',
      $contributionID,
      'pledge_id',
      'contribution_id'
    );

    $updatePledgePaymentStatus = FALSE;

    // If either the status or the amount has changed we update the pledge status.
    if ($action & CRM_Core_Action::ADD) {
      $updatePledgePaymentStatus = TRUE;
    }
    elseif ($action & CRM_Core_Action::UPDATE && (($original_contribution_status_id != $contribution_status_id) ||
        ($original_total_amount != $total_amount))
    ) {
      $updatePledgePaymentStatus = TRUE;
    }

    if ($updatePledgePaymentStatus) {
      CRM_Pledge_BAO_PledgePayment::updatePledgePaymentStatus($pledgeID,
        [$pledgePaymentID],
        $contribution_status_id,
        NULL,
        $total_amount,
        $adjustTotalAmount
      );
    }
  }

  /**
   * Compute the stats values
   *
   * @param string $stat either 'mode' or 'median'
   * @param string $sql
   * @param string $alias of civicrm_contribution
   *
   * @return array|null
   * @deprecated
   *
   */
  public static function computeStats($stat, $sql, $alias = NULL) {
    CRM_Core_Error::deprecatedFunctionWarning('computeStats is now deprecated');
    return [];
  }

  /**
   * Is there only one line item attached to the contribution.
   *
   * @param int $id
   *   Contribution ID.
   *
   * @return bool
   * @throws \CiviCRM_API3_Exception
   */
  public static function isSingleLineItem($id) {
    $lineItemCount = civicrm_api3('LineItem', 'getcount', ['contribution_id' => $id]);
    return ($lineItemCount == 1);
  }

  /**
   * Complete an order.
   *
   * Do not call this directly - use the contribution.completetransaction api as this function is being refactored.
   *
   * Currently overloaded to complete a transaction & repeat a transaction - fix!
   *
   * Moving it out of the BaseIPN class is just the first step.
   *
   * @param array $input
   * @param array $ids
   * @param array $objects
   * @param CRM_Core_Transaction $transaction
   * @param int $recur
   * @param CRM_Contribute_BAO_Contribution $contribution
   * @param bool $isPostPaymentCreate
   *   Is this being called from the payment.create api. If so the api has taken care of financial entities.
   *   Note that our goal is that this would only ever be called from payment.create and never handle financials (only
   *   transitioning related elements).
   *
   * @return array
   * @throws \CRM_Core_Exception
   * @throws \CiviCRM_API3_Exception
   */
  public static function completeOrder($input, &$ids, $objects, $transaction, $recur, $contribution, $isPostPaymentCreate = FALSE) {
    $primaryContributionID = isset($contribution->id) ? $contribution->id : $objects['first_contribution']->id;
    // The previous details are used when calculating line items so keep it before any code that 'does something'
    if (!empty($contribution->id)) {
      $input['prevContribution'] = CRM_Contribute_BAO_Contribution::getValues(['id' => $contribution->id]);
    }
    $inputContributionWhiteList = [
      'fee_amount',
      'net_amount',
      'trxn_id',
      'check_number',
      'payment_instrument_id',
      'is_test',
      'campaign_id',
      'receive_date',
      'receipt_date',
      'contribution_status_id',
      'card_type_id',
      'pan_truncation',
    ];
    if (self::isSingleLineItem($primaryContributionID)) {
      $inputContributionWhiteList[] = 'financial_type_id';
    }

    $participant = CRM_Utils_Array::value('participant', $objects);
    $recurContrib = CRM_Utils_Array::value('contributionRecur', $objects);
    $recurringContributionID = (empty($recurContrib->id)) ? NULL : $recurContrib->id;
    $event = CRM_Utils_Array::value('event', $objects);

    $paymentProcessorId = '';
    if (isset($objects['paymentProcessor'])) {
      if (is_array($objects['paymentProcessor'])) {
        $paymentProcessorId = $objects['paymentProcessor']['id'];
      }
      else {
        $paymentProcessorId = $objects['paymentProcessor']->id;
      }
    }

    $completedContributionStatusID = CRM_Core_PseudoConstant::getKey('CRM_Contribute_BAO_Contribution', 'contribution_status_id', 'Completed');

    $contributionParams = array_merge([
      'contribution_status_id' => $completedContributionStatusID,
      'source' => self::getRecurringContributionDescription($contribution, $event),
    ], array_intersect_key($input, array_fill_keys($inputContributionWhiteList, 1)
    ));

    // CRM-20678 Ensure that the currency is correct in subseqent transcations.
    if (empty($contributionParams['currency']) && isset($objects['first_contribution']->currency)) {
      $contributionParams['currency'] = $objects['first_contribution']->currency;
    }

    $contributionParams['payment_processor'] = $input['payment_processor'] = $paymentProcessorId;

    // If paymentProcessor is not set then the payment_instrument_id would not be correct.
    // not clear when or if this would occur if you encounter this please fix here & add a unit test.
    if (empty($contributionParams['payment_instrument_id']) && isset($contribution->_relatedObjects['paymentProcessor']['payment_instrument_id'])) {
      $contributionParams['payment_instrument_id'] = $contribution->_relatedObjects['paymentProcessor']['payment_instrument_id'];
    }

    if ($recurringContributionID) {
      $contributionParams['contribution_recur_id'] = $recurringContributionID;
    }
    $changeDate = CRM_Utils_Array::value('trxn_date', $input, date('YmdHis'));

    if (empty($contributionParams['receive_date']) && $changeDate) {
      $contributionParams['receive_date'] = $changeDate;
    }

    self::repeatTransaction($contribution, $input, $contributionParams, $paymentProcessorId);
    $contributionParams['financial_type_id'] = $contribution->financial_type_id;

    $values = [];
    if (isset($input['is_email_receipt'])) {
      $values['is_email_receipt'] = $input['is_email_receipt'];
    }

    if ($input['component'] == 'contribute') {
      if ($contribution->contribution_page_id) {
        // Figure out what we gain from this.
        // Note that we may have overwritten the is_email_receipt input, fix that below.
        CRM_Contribute_BAO_ContributionPage::setValues($contribution->contribution_page_id, $values);
      }
      elseif ($recurContrib && $recurringContributionID) {
        $values['amount'] = $recurContrib->amount;
        $values['financial_type_id'] = $objects['contributionType']->id;
        $values['title'] = $source = ts('Offline Recurring Contribution');
      }

      if (isset($input['is_email_receipt'])) {
        // CRM-19601 - we may have overwritten this above.
        $values['is_email_receipt'] = $input['is_email_receipt'];
      }
      elseif ($recurContrib && $recurringContributionID) {
        //CRM-13273 - is_email_receipt setting on recurring contribution should take precedence over contribution page setting
        // but CRM-16124 if $input['is_email_receipt'] is set then that should not be overridden.
        // dev/core#1245 this maybe not the desired effect because the default value for is_email_receipt is set to 0 rather than 1 in
        // Instance that had the table added via an upgrade in 4.1
        // see also https://github.com/civicrm/civicrm-svn/commit/7f39befd60bc735408d7866b02b3ac7fff1d4eea#diff-9ad8e290180451a2d6eacbd3d1ca7966R354
        // https://lab.civicrm.org/dev/core/issues/1245
        $values['is_email_receipt'] = $recurContrib->is_email_receipt;
      }

      if ($contributionParams['contribution_status_id'] === $completedContributionStatusID) {
        self::updateMembershipBasedOnCompletionOfContribution(
          $contribution,
          $primaryContributionID,
          $changeDate
        );
      }
    }
    else {
      if (empty($input['IAmAHorribleNastyBeyondExcusableHackInTheCRMEventFORMTaskClassThatNeedsToBERemoved'])) {
        if ($event->is_email_confirm) {
          // @todo this should be set by the function that sends the mail after sending.
          $contributionParams['receipt_date'] = $changeDate;
        }
        $participantParams['id'] = $participant->id;
        $participantParams['status_id'] = 'Registered';
        civicrm_api3('Participant', 'create', $participantParams);
      }
    }

    $contributionParams['id'] = $contribution->id;
    $contributionParams['is_post_payment_create'] = $isPostPaymentCreate;

    // CRM-19309 - if you update the contribution here with financial_type_id it can/will mess with $lineItem
    // unsetting it here does NOT cause any other contribution test to fail!
    unset($contributionParams['financial_type_id']);
    $contributionResult = civicrm_api3('Contribution', 'create', $contributionParams);

    // Add new soft credit against current $contribution.
    if (!empty($objects['contributionRecur']) && $objects['contributionRecur']->id) {
      CRM_Contribute_BAO_ContributionRecur::addrecurSoftCredit($objects['contributionRecur']->id, $contribution->id);
    }

    $contributionStatuses = CRM_Core_PseudoConstant::get('CRM_Contribute_DAO_Contribution', 'contribution_status_id', [
      'labelColumn' => 'name',
      'flip' => 1,
    ]);
    if (isset($input['prevContribution']) && (!$input['prevContribution']->is_pay_later && $input['prevContribution']->contribution_status_id == $contributionStatuses['Pending'])) {
      $input['payment_processor'] = $paymentProcessorId;
    }

    if (!empty($contribution->_relatedObjects['participant'])) {
      $input['contribution_mode'] = 'participant';
      $input['participant_id'] = $contribution->_relatedObjects['participant']->id;
    }
    elseif (!empty($contribution->_relatedObjects['membership'])) {
      // @todo - use getRelatedMemberships instead
      $input['contribution_mode'] = 'membership';
      $contribution->contribution_status_id = $contributionParams['contribution_status_id'];
      $contribution->trxn_id = CRM_Utils_Array::value('trxn_id', $input);
      $contribution->receive_date = CRM_Utils_Date::isoToMysql($contribution->receive_date);
    }

    CRM_Core_Error::debug_log_message("Contribution record updated successfully");
    $transaction->commit();

    CRM_Contribute_BAO_ContributionRecur::updateRecurLinkedPledge($contribution->id, $recurringContributionID,
      $contributionParams['contribution_status_id'], $input['amount']);

    // create an activity record
    if ($input['component'] == 'contribute') {
      //CRM-4027
      $targetContactID = NULL;
      if (!empty($ids['related_contact'])) {
        $targetContactID = $contribution->contact_id;
        $contribution->contact_id = $ids['related_contact'];
      }
      CRM_Activity_BAO_Activity::addActivity($contribution, NULL, $targetContactID);
    }

    // CRM-9132 legacy behaviour was that receipts were sent out in all instances. Still sending
    // when array_key 'is_email_receipt doesn't exist in case some instances where is needs setting haven't been set
    if (!array_key_exists('is_email_receipt', $values) ||
      $values['is_email_receipt'] == 1
    ) {
      civicrm_api3('Contribution', 'sendconfirmation', [
        'id' => $contribution->id,
        'payment_processor_id' => $paymentProcessorId,
      ]);
      CRM_Core_Error::debug_log_message("Receipt sent");
    }

    CRM_Core_Error::debug_log_message("Success: Database updated");
    return $contributionResult;
  }

  /**
   * Send receipt from contribution.
   *
   * Do not call this directly - it is being refactored. use contribution.sendmessage api call.
   *
   * Note that the compose message part has been moved to contribution
   * In general LoadObjects is called first to get the objects but the composeMessageArray function now calls it.
   *
   * @param array $input
   *   Incoming data from Payment processor.
   * @param array $ids
   *   Related object IDs.
   * @param int $contributionID
   * @param array $values
   *   Values related to objects that have already been loaded.
   * @param bool $returnMessageText
   *   Should text be returned instead of sent. This.
   *   is because the function is also used to generate pdfs
   *
   * @return array
   * @throws \CRM_Core_Exception
   * @throws \CiviCRM_API3_Exception
   * @throws \Exception
   */
  public static function sendMail(&$input, &$ids, $contributionID, &$values,
                                  $returnMessageText = FALSE) {

    $contribution = new CRM_Contribute_BAO_Contribution();
    $contribution->id = $contributionID;
    if (!$contribution->find(TRUE)) {
      throw new CRM_Core_Exception('Contribution does not exist');
    }
    $contribution->loadRelatedObjects($input, $ids, TRUE);
    // set receipt from e-mail and name in value
    if (!$returnMessageText) {
      list($values['receipt_from_name'], $values['receipt_from_email']) = self::generateFromEmailAndName($input, $contribution);
    }
    $values['contribution_status'] = CRM_Core_PseudoConstant::getLabel('CRM_Contribute_BAO_Contribution', 'contribution_status_id', $contribution->contribution_status_id);
    $return = $contribution->composeMessageArray($input, $ids, $values, $returnMessageText);
    if ((!isset($input['receipt_update']) || $input['receipt_update']) && empty($contribution->receipt_date)) {
      civicrm_api3('Contribution', 'create', [
        'receipt_date' => 'now',
        'id' => $contribution->id,
      ]);
    }
    return $return;
  }

  /**
   * Generate From email and from name in an array values
   *
   * @param array $input
   * @param \CRM_Contribute_BAO_Contribution $contribution
   *
   * @return array
   */
  public static function generateFromEmailAndName($input, $contribution) {
    // Use input value if supplied.
    if (!empty($input['receipt_from_email'])) {
      return [
        CRM_Utils_Array::value('receipt_from_name', $input, ''),
        $input['receipt_from_email'],
      ];
    }
    // if we are still empty see if we can use anything from a contribution page.
    $pageValues = [];
    if (!empty($contribution->contribution_page_id)) {
      $pageValues = civicrm_api3('ContributionPage', 'getsingle', ['id' => $contribution->contribution_page_id]);
    }
    // if we are still empty see if we can use anything from a contribution page.
    if (!empty($pageValues['receipt_from_email'])) {
      return [
        CRM_Utils_Array::value('receipt_from_name', $pageValues),
        $pageValues['receipt_from_email'],
      ];
    }
    // If we are still empty fall back to the domain or logged in user information.
    return CRM_Core_BAO_Domain::getDefaultReceiptFrom();
  }

  /**
   * Generate credit note id with next avaible number
   *
   * @return string
   *   Credit Note Id.
   */
  public static function createCreditNoteId() {
    $prefixValue = Civi::settings()->get('contribution_invoice_settings');

    $creditNoteNum = CRM_Core_DAO::singleValueQuery("SELECT count(creditnote_id) as creditnote_number FROM civicrm_contribution WHERE creditnote_id IS NOT NULL");
    $creditNoteId = NULL;

    do {
      $creditNoteNum++;
      $creditNoteId = CRM_Utils_Array::value('credit_notes_prefix', $prefixValue) . "" . $creditNoteNum;
      $result = civicrm_api3('Contribution', 'getcount', [
        'sequential' => 1,
        'creditnote_id' => $creditNoteId,
      ]);
    } while ($result > 0);

    return $creditNoteId;
  }

  /**
   * Load related memberships.
   *
   * @param array $ids
   *
   * @return array $ids
   *
   * @throws Exception
   * @deprecated
   *
   * Note that in theory it should be possible to retrieve these from the line_item table
   * with the membership_payment table being deprecated. Attempting to do this here causes tests to fail
   * as it seems the api is not correctly linking the line items when the contribution is created in the flow
   * where the contribution is created in the API, followed by the membership (using the api) followed by the membership
   * payment. The membership payment BAO does have code to address this but it doesn't appear to be working.
   *
   * I don't know if it never worked or broke as a result of https://issues.civicrm.org/jira/browse/CRM-14918.
   *
   */
  public function loadRelatedMembershipObjects($ids = []) {
    $query = "
      SELECT membership_id
      FROM   civicrm_membership_payment
      WHERE  contribution_id = %1 ";
    $params = [1 => [$this->id, 'Integer']];
    $ids['membership'] = (array) CRM_Utils_Array::value('membership', $ids, []);

    $dao = CRM_Core_DAO::executeQuery($query, $params);
    while ($dao->fetch()) {
      if ($dao->membership_id && !in_array($dao->membership_id, $ids['membership'])) {
        $ids['membership'][$dao->membership_id] = $dao->membership_id;
      }
    }

    if (array_key_exists('membership', $ids) && is_array($ids['membership'])) {
      foreach ($ids['membership'] as $id) {
        if (!empty($id)) {
          $membership = new CRM_Member_BAO_Membership();
          $membership->id = $id;
          if (!$membership->find(TRUE)) {
            throw new Exception("Could not find membership record: $id");
          }
          $membership->join_date = CRM_Utils_Date::isoToMysql($membership->join_date);
          $membership->start_date = CRM_Utils_Date::isoToMysql($membership->start_date);
          $membership->end_date = CRM_Utils_Date::isoToMysql($membership->end_date);
          $this->_relatedObjects['membership'][$membership->membership_type_id] = $membership;
        }
      }
    }
    return $ids;
  }

  /**
   * This function is used to record partial payments for contribution
   *
   * @param array $contribution
   *
   * @param array $params
   *
   * @return CRM_Financial_DAO_FinancialTrxn
   */
  public static function recordPartialPayment($contribution, $params) {
    CRM_Core_Error::deprecatedFunctionWarning('use payment create api');
    $balanceTrxnParams['to_financial_account_id'] = self::getToFinancialAccount($contribution, $params);
    $balanceTrxnParams['from_financial_account_id'] = CRM_Financial_BAO_FinancialAccount::getFinancialAccountForFinancialTypeByRelationship($contribution['financial_type_id'], 'Accounts Receivable Account is');
    $balanceTrxnParams['total_amount'] = $params['total_amount'];
    $balanceTrxnParams['contribution_id'] = $params['contribution_id'];
    $balanceTrxnParams['trxn_date'] = CRM_Utils_Array::value('trxn_date', $params, CRM_Utils_Array::value('contribution_receive_date', $params, date('YmdHis')));
    $balanceTrxnParams['fee_amount'] = CRM_Utils_Array::value('fee_amount', $params);
    $balanceTrxnParams['net_amount'] = CRM_Utils_Array::value('total_amount', $params);
    $balanceTrxnParams['currency'] = $contribution['currency'];
    $balanceTrxnParams['trxn_id'] = CRM_Utils_Array::value('contribution_trxn_id', $params, NULL);
    $balanceTrxnParams['status_id'] = CRM_Core_PseudoConstant::getKey('CRM_Core_BAO_FinancialTrxn', 'status_id', 'Completed');
    $balanceTrxnParams['payment_instrument_id'] = CRM_Utils_Array::value('payment_instrument_id', $params, $contribution['payment_instrument_id']);
    $balanceTrxnParams['check_number'] = CRM_Utils_Array::value('check_number', $params);
    $balanceTrxnParams['is_payment'] = 1;

    if (!empty($params['payment_processor'])) {
      // I can't find evidence this is passed in - I was gonna just remove it but decided to deprecate  as I see self::getToFinancialAccount
      // also anticipates it.
      CRM_Core_Error::deprecatedFunctionWarning('passing payment_processor is deprecated - use payment_processor_id');
      $balanceTrxnParams['payment_processor_id'] = $params['payment_processor'];
    }
    return CRM_Core_BAO_FinancialTrxn::create($balanceTrxnParams);
  }

  /**
   * Get the description (source field) for the recurring contribution.
   *
   * @param CRM_Contribute_BAO_Contribution $contribution
   * @param CRM_Event_DAO_Event|null $event
   *
   * @return string
   * @throws \CiviCRM_API3_Exception
   */
  protected static function getRecurringContributionDescription($contribution, $event) {
    if (!empty($contribution->source)) {
      return $contribution->source;
    }
    elseif (!empty($contribution->contribution_page_id) && is_numeric($contribution->contribution_page_id)) {
      $contributionPageTitle = civicrm_api3('ContributionPage', 'getvalue', [
        'id' => $contribution->contribution_page_id,
        'return' => 'title',
      ]);
      return ts('Online Contribution') . ': ' . $contributionPageTitle;
    }
    elseif ($event) {
      return ts('Online Event Registration') . ': ' . $event->title;
    }
    elseif (!empty($contribution->contribution_recur_id)) {
      return 'recurring contribution';
    }
    return '';
  }

  /**
   * Function to add payments for contribution
   * for Partially Paid status
   *
   * @param array $contributions
   * @param string $contributionStatusId
   *
   */
  public static function addPayments($contributions, $contributionStatusId = NULL) {
    // get financial trxn which is a payment
    $ftSql = "SELECT ft.id, ft.total_amount
      FROM civicrm_financial_trxn ft
      INNER JOIN civicrm_entity_financial_trxn eft ON eft.financial_trxn_id = ft.id AND eft.entity_table = 'civicrm_contribution'
      WHERE eft.entity_id = %1 AND ft.is_payment = 1 ORDER BY ft.id DESC LIMIT 1";
    $contributionStatus = CRM_Core_PseudoConstant::get('CRM_Contribute_DAO_Contribution', 'contribution_status_id', [
      'labelColumn' => 'name',
    ]);
    foreach ($contributions as $contribution) {
      if (!($contributionStatus[$contribution->contribution_status_id] == 'Partially paid'
        || CRM_Utils_Array::value($contributionStatusId, $contributionStatus) == 'Partially paid')
      ) {
        continue;
      }
      $ftDao = CRM_Core_DAO::executeQuery($ftSql, [
        1 => [
          $contribution->id,
          'Integer',
        ],
      ]);
      $ftDao->fetch();

      // store financial item Proportionaly.
      $trxnParams = [
        'total_amount' => $ftDao->total_amount,
        'contribution_id' => $contribution->id,
      ];
      self::assignProportionalLineItems($trxnParams, $ftDao->id, $contribution->total_amount);
    }
  }

  /**
   * Function use to store line item proportionally in in entity financial trxn table
   *
   * @param array $trxnParams
   *
   * @param int $trxnId
   *
   * @param float $contributionTotalAmount
   *
   * @throws \CiviCRM_API3_Exception
   */
  public static function assignProportionalLineItems($trxnParams, $trxnId, $contributionTotalAmount) {
    $lineItems = CRM_Price_BAO_LineItem::getLineItemsByContributionID($trxnParams['contribution_id']);
    if (!empty($lineItems)) {
      // get financial item
      list($ftIds, $taxItems) = self::getLastFinancialItemIds($trxnParams['contribution_id']);
      $entityParams = [
        'contribution_total_amount' => $contributionTotalAmount,
        'trxn_total_amount' => $trxnParams['total_amount'],
        'trxn_id' => $trxnId,
      ];
      self::createProportionalFinancialEntries($entityParams, $lineItems, $ftIds, $taxItems);
    }
  }

  /**
   * Checks if line items total amounts
   * match the contribution total amount.
   *
   * @param array $params
   *  array of order params.
   *
   * @throws \API_Exception
   */
  public static function checkLineItems(&$params) {
    $totalAmount = CRM_Utils_Array::value('total_amount', $params);
    $lineItemAmount = 0;

    foreach ($params['line_items'] as &$lineItems) {
      foreach ($lineItems['line_item'] as &$item) {
        if (empty($item['financial_type_id'])) {
          $item['financial_type_id'] = $params['financial_type_id'];
        }
        $lineItemAmount += $item['line_total'] + CRM_Utils_Array::value('tax_amount', $item, 0.00);
      }
    }

    if (!isset($totalAmount)) {
      $params['total_amount'] = $lineItemAmount;
    }
    else {
      $currency = CRM_Utils_Array::value('currency', $params, '');

      if (empty($currency)) {
        $currency = CRM_Core_Config::singleton()->defaultCurrency;
      }

      if (!CRM_Utils_Money::equals($totalAmount, $lineItemAmount, $currency)) {
        throw new CRM_Contribute_Exception_CheckLineItemsException();
      }
    }
  }

  /**
   * Get the financial account for the item associated with the new transaction.
   *
   * @param array $params
   * @param int $default
   *
   * @return int
   */
  public static function getFinancialAccountForStatusChangeTrxn($params, $default) {

    if (!empty($params['financial_account_id'])) {
      return $params['financial_account_id'];
    }

    $contributionStatus = CRM_Contribute_PseudoConstant::contributionStatus($params['contribution_status_id'], 'name');
    $preferredAccountsRelationships = [
      'Refunded' => 'Credit/Contra Revenue Account is',
      'Chargeback' => 'Chargeback Account is',
    ];

    if (in_array($contributionStatus, array_keys($preferredAccountsRelationships))) {
      $financialTypeID = !empty($params['financial_type_id']) ? $params['financial_type_id'] : $params['prevContribution']->financial_type_id;
      return CRM_Financial_BAO_FinancialAccount::getFinancialAccountForFinancialTypeByRelationship(
        $financialTypeID,
        $preferredAccountsRelationships[$contributionStatus]
      );
    }

    return $default;
  }

  /**
   * ContributionPage values were being imposed onto values.
   *
   * I have made this explicit and removed the couple (is_recur, is_pay_later) we
   * REALLY didn't want superimposed. The rest are left there in their overkill out
   * of cautiousness.
   *
   * The rationale for making this explicit is that it was a case of carefully set values being
   * seemingly randonly overwritten without much care. In general I think array randomly setting
   * variables en mass is risky.
   *
   * @param array $values
   *
   * @return array
   */
  protected function addContributionPageValuesToValuesHeavyHandedly(&$values) {
    $contributionPageValues = [];
    CRM_Contribute_BAO_ContributionPage::setValues(
      $this->contribution_page_id,
      $contributionPageValues
    );
    $valuesToCopy = [
      // These are the values that I believe to be useful.
      'id',
      'title',
      'pay_later_receipt',
      'pay_later_text',
      'receipt_from_email',
      'receipt_from_name',
      'receipt_text',
      'custom_pre_id',
      'custom_post_id',
      'honoree_profile_id',
      'onbehalf_profile_id',
      'honor_block_is_active',
      // Kinda might be - but would be on the contribution...
      'campaign_id',
      'currency',
      // Included for 'fear of regression' but can't justify any use for these....
      'intro_text',
      'payment_processor',
      'financial_type_id',
      'amount_block_is_active',
      'bcc_receipt',
      'cc_receipt',
      'created_date',
      'created_id',
      'default_amount_id',
      'end_date',
      'footer_text',
      'goal_amount',
      'initial_amount_help_text',
      'initial_amount_label',
      'intro_text',
      'is_allow_other_amount',
      'is_billing_required',
      'is_confirm_enabled',
      'is_credit_card_only',
      'is_monetary',
      'is_partial_payment',
      'is_recur_installments',
      'is_recur_interval',
      'is_share',
      'max_amount',
      'min_amount',
      'min_initial_amount',
      'recur_frequency_unit',
      'start_date',
      'thankyou_footer',
      'thankyou_text',
      'thankyou_title',

    ];
    foreach ($valuesToCopy as $valueToCopy) {
      if (isset($contributionPageValues[$valueToCopy])) {
        if ($valueToCopy === 'title') {
          $values[$valueToCopy] = CRM_Contribute_BAO_Contribution_Utils::getContributionPageTitle($this->contribution_page_id);
        }
        else {
          $values[$valueToCopy] = $contributionPageValues[$valueToCopy];
        }
      }
    }
    return $values;
  }

  /**
   * Get values of CiviContribute Settings
   * and check if its enabled or not.
   * Note: The CiviContribute settings are stored as single entry in civicrm_setting
   * in serialized form. Usually this should be stored as flat settings for each form fields
   * as per CiviCRM standards. Since this would take more effort to change the current behaviour of CiviContribute
   * settings we will live with an inconsistency because it's too hard to change for now.
   * https://github.com/civicrm/civicrm-core/pull/8562#issuecomment-227874245
   *
   *
   * @param string $name
   * @param bool $checkInvoicing
   * @return string
   *
   */
  public static function checkContributeSettings($name = NULL, $checkInvoicing = FALSE) {
    $contributeSettings = Civi::settings()->get('contribution_invoice_settings');

    if ($checkInvoicing && empty($contributeSettings['invoicing'])) {
      return NULL;
    }

    if ($name) {
      return CRM_Utils_Array::value($name, $contributeSettings);
    }
    return $contributeSettings;
  }

  /**
   * This function process contribution related objects.
   *
   * @param int $contributionId
   * @param int $statusId
   * @param int|null $previousStatusId
   *
   * @param string $receiveDate
   *
   * @return null|string
   */
  public static function transitionComponentWithReturnMessage($contributionId, $statusId, $previousStatusId = NULL, $receiveDate = NULL) {
    $statusMsg = NULL;
    if (!$contributionId || !$statusId) {
      return $statusMsg;
    }

    $params = [
      'contribution_id' => $contributionId,
      'contribution_status_id' => $statusId,
      'previous_contribution_status_id' => $previousStatusId,
      'receive_date' => $receiveDate,
    ];

    $updateResult = CRM_Contribute_BAO_Contribution::transitionComponents($params);

    if (!is_array($updateResult) ||
      !($updatedComponents = CRM_Utils_Array::value('updatedComponents', $updateResult)) ||
      !is_array($updatedComponents) ||
      empty($updatedComponents)
    ) {
      return $statusMsg;
    }

    // get the user display name.
    $sql = "
   SELECT  display_name as displayName
     FROM  civicrm_contact
LEFT JOIN  civicrm_contribution on (civicrm_contribution.contact_id = civicrm_contact.id )
    WHERE  civicrm_contribution.id = {$contributionId}";
    $userDisplayName = CRM_Core_DAO::singleValueQuery($sql);

    // get the status message for user.
    foreach ($updatedComponents as $componentName => $updatedStatusId) {

      if ($componentName == 'CiviMember') {
        $updatedStatusName = CRM_Utils_Array::value($updatedStatusId,
          CRM_Member_PseudoConstant::membershipStatus()
        );

        $statusNameMsgPart = 'updated';
        switch ($updatedStatusName) {
          case 'Cancelled':
          case 'Expired':
            $statusNameMsgPart = $updatedStatusName;
            break;
        }

        $statusMsg .= "<br />" . ts("Membership for %1 has been %2.", [
          1 => $userDisplayName,
          2 => $statusNameMsgPart,
        ]);
      }

      if ($componentName == 'CiviEvent') {
        $updatedStatusName = CRM_Utils_Array::value($updatedStatusId,
          CRM_Event_PseudoConstant::participantStatus()
        );
        if ($updatedStatusName == 'Cancelled') {
          $statusMsg .= "<br />" . ts("Event Registration for %1 has been Cancelled.", [1 => $userDisplayName]);
        }
        elseif ($updatedStatusName == 'Registered') {
          $statusMsg .= "<br />" . ts("Event Registration for %1 has been updated.", [1 => $userDisplayName]);
        }
      }

      if ($componentName == 'CiviPledge') {
        $updatedStatusName = CRM_Utils_Array::value($updatedStatusId,
          CRM_Contribute_PseudoConstant::contributionStatus(NULL, 'name')
        );
        if ($updatedStatusName == 'Cancelled') {
          $statusMsg .= "<br />" . ts("Pledge Payment for %1 has been Cancelled.", [1 => $userDisplayName]);
        }
        elseif ($updatedStatusName == 'Failed') {
          $statusMsg .= "<br />" . ts("Pledge Payment for %1 has been Failed.", [1 => $userDisplayName]);
        }
        elseif ($updatedStatusName == 'Completed') {
          $statusMsg .= "<br />" . ts("Pledge Payment for %1 has been updated.", [1 => $userDisplayName]);
        }
      }
    }

    return $statusMsg;
  }

  /**
   * Get the contribution as it is in the database before being updated.
   *
   * @param int $contributionID
   *
   * @return \CRM_Contribute_BAO_Contribution|null
   */
  private static function getOriginalContribution($contributionID) {
    return self::getValues(['id' => $contributionID]);
  }

  /**
   * Get the amount for the financial item row.
   *
   * Helper function to start to break down recordFinancialTransactions for readability.
   *
   * The logic is more historical than .. logical. Paths other than the deprecated one are tested.
   *
   * Codewise, several somewhat disimmilar things have been squished into recordFinancialAccounts
   * for historical reasons. Going forwards we can hope to add tests & improve readibility
   * of that function
   *
   * @param array $params
   *   Params as passed to contribution.create
   *
   * @param string $context
   *   changeFinancialType| changedAmount
   * @param array $lineItemDetails
   *   Line items.
   * @param bool $isARefund
   *   Is this a refund / negative transaction.
   * @param int $previousLineItemTotal
   *
   * @return float
   * @todo move recordFinancialAccounts & helper functions to their own class?
   *
   */
  protected static function getFinancialItemAmountFromParams($params, $context, $lineItemDetails, $isARefund, $previousLineItemTotal) {
    if ($context == 'changedAmount') {
      $lineTotal = $lineItemDetails['line_total'];
      if ($lineTotal != $previousLineItemTotal) {
        $lineTotal -= $previousLineItemTotal;
      }
      return $lineTotal;
    }
    elseif ($context == 'changeFinancialType') {
      return -$lineItemDetails['line_total'];
    }
    elseif ($context == 'changedStatus') {
      $cancelledTaxAmount = 0;
      if ($isARefund) {
        $cancelledTaxAmount = CRM_Utils_Array::value('tax_amount', $lineItemDetails, '0.00');
      }
      return self::getMultiplier($params['contribution']->contribution_status_id, $context) * ((float) $lineItemDetails['line_total'] + (float) $cancelledTaxAmount);
    }
    elseif ($context === NULL) {
      // erm, yes because? but, hey, it's tested.
      return $lineItemDetails['line_total'];
    }
    elseif (empty($lineItemDetails['line_total'])) {
      // follow legacy code path
      Civi::log()
        ->warning('Deprecated bit of code, please log a ticket explaining how you got here!', ['civi.tag' => 'deprecated']);
      return $params['total_amount'];
    }
    else {
      return self::getMultiplier($params['contribution']->contribution_status_id, $context) * ((float) $lineItemDetails['line_total']);
    }
  }

  /**
   * Get the multiplier for adjusting rows.
   *
   * If we are dealing with a refund or cancellation then it will be a negative
   * amount to reflect the negative transaction.
   *
   * If we are changing Financial Type it will be a negative amount to
   * adjust down the old type.
   *
   * @param int $contribution_status_id
   * @param string $context
   *
   * @return int
   */
  protected static function getMultiplier($contribution_status_id, $context) {
    if ($context == 'changeFinancialType' || self::isContributionStatusNegative($contribution_status_id)) {
      return -1;
    }
    return 1;
  }

  /**
   * Does this transaction reflect a payment instrument change.
   *
   * @param array $params
   * @param array $pendingStatuses
   *
   * @return bool
   */
  protected static function isPaymentInstrumentChange(&$params, $pendingStatuses) {
    $contributionStatus = CRM_Core_PseudoConstant::getName('CRM_Contribute_BAO_Contribution', 'contribution_status_id', $params['contribution']->contribution_status_id);

    if (array_key_exists('payment_instrument_id', $params)) {
      if (CRM_Utils_System::isNull($params['prevContribution']->payment_instrument_id) &&
        !CRM_Utils_System::isNull($params['payment_instrument_id'])
      ) {
        //check if status is changed from Pending to Completed
        // do not update payment instrument changes for Pending to Completed
        if (!($contributionStatus == 'Completed' &&
          in_array($params['prevContribution']->contribution_status_id, $pendingStatuses))
        ) {
          return TRUE;
        }
      }
      elseif ((!CRM_Utils_System::isNull($params['payment_instrument_id']) &&
          !CRM_Utils_System::isNull($params['prevContribution']->payment_instrument_id)) &&
        $params['payment_instrument_id'] != $params['prevContribution']->payment_instrument_id
      ) {
        return TRUE;
      }
      elseif (!CRM_Utils_System::isNull($params['contribution']->check_number) &&
        $params['contribution']->check_number != $params['prevContribution']->check_number
      ) {
        // another special case when check number is changed, create new financial records
        // create financial trxn with negative amount
        return TRUE;
      }
    }
    return FALSE;
  }

  /**
   * Update the memberships associated with a contribution if it has been completed.
   *
   * Note that the way in which $memberships are loaded as objects is pretty messy & I think we could just
   * load them in this function. Code clean up would compensate for any minor performance implication.
   *
   * @param \CRM_Contribute_BAO_Contribution $contribution
   * @param int $primaryContributionID
   * @param string $changeDate
   *
   * @throws \CRM_Core_Exception
   * @throws \CiviCRM_API3_Exception
   */
  public static function updateMembershipBasedOnCompletionOfContribution($contribution, $primaryContributionID, $changeDate) {
    $memberships = self::getRelatedMemberships($contribution->id);
    foreach ($memberships as $membership) {
      $membershipParams = [
        'id' => $membership['id'],
        'contact_id' => $membership['contact_id'],
        'is_test' => $membership['is_test'],
        'membership_type_id' => $membership['membership_type_id'],
        'membership_activity_status' => 'Completed',
      ];

      $currentMembership = CRM_Member_BAO_Membership::getContactMembership($membershipParams['contact_id'],
        $membershipParams['membership_type_id'],
        $membershipParams['is_test'],
        $membershipParams['id']
      );

      // CRM-8141 update the membership type with the value recorded in log when membership created/renewed
      // this picks up membership type changes during renewals
      // @todo this is almost certainly an obsolete sql call, the pre-change
      // membership is accessible via $this->_relatedObjects
      $sql = "
SELECT    membership_type_id
FROM      civicrm_membership_log
WHERE     membership_id={$membershipParams['id']}
ORDER BY  id DESC
LIMIT 1;";
      $dao = CRM_Core_DAO::executeQuery($sql);
      if ($dao->fetch()) {
        if (!empty($dao->membership_type_id)) {
          $membershipParams['membership_type_id'] = $dao->membership_type_id;
        }
      }
      if (empty($membership['end_date']) || (int) $membership['status_id'] !== CRM_Core_PseudoConstant::getKey('CRM_Member_BAO_Membership', 'status_id', 'Pending')) {
        // Passing num_terms to the api triggers date calculations, but for pending memberships these may be already calculated.
        // sigh - they should  be  consistent but removing the end date check causes test failures & maybe UI too?
        // The api assumes num_terms is a special sauce for 'is_renewal' so we need to not pass it when updating a pending to completed.
        // @todo once apiv4 ships with core switch to that & find sanity.
        $membershipParams['num_terms'] = $contribution->getNumTermsByContributionAndMembershipType(
          $membershipParams['membership_type_id'],
          $primaryContributionID
        );
      }
      // @todo remove all this stuff in favour of letting the api call further down handle in
      // (it is a duplication of what the api does).
      $dates = array_fill_keys([
        'join_date',
        'start_date',
        'end_date',
      ], NULL);
      if ($currentMembership) {
        /*
         * Fixed FOR CRM-4433
         * In BAO/Membership.php(renewMembership function), we skip the extend membership date and status
         * when Contribution mode is notify and membership is for renewal )
         */
        CRM_Member_BAO_Membership::fixMembershipStatusBeforeRenew($currentMembership, $changeDate);

        // @todo - we should pass membership_type_id instead of null here but not
        // adding as not sure of testing
        $dates = CRM_Member_BAO_MembershipType::getRenewalDatesForMembershipType($membershipParams['id'],
          $changeDate, NULL, $membershipParams['num_terms']
        );
        $dates['join_date'] = $currentMembership['join_date'];
      }

      //get the status for membership.
      $calcStatus = CRM_Member_BAO_MembershipStatus::getMembershipStatusByDate($dates['start_date'],
        $dates['end_date'],
        $dates['join_date'],
        'today',
        TRUE,
        $membershipParams['membership_type_id'],
        $membershipParams
      );

      unset($dates['end_date']);
      $membershipParams['status_id'] = CRM_Utils_Array::value('id', $calcStatus, 'New');
      //we might be renewing membership,
      //so make status override false.
      $membershipParams['is_override'] = FALSE;
      $membershipParams['status_override_end_date'] = 'null';

      //CRM-17723 - reset static $relatedContactIds array()
      // @todo move it to Civi Statics.
      $var = TRUE;
      CRM_Member_BAO_Membership::createRelatedMemberships($var, $var, TRUE);
      civicrm_api3('Membership', 'create', $membershipParams);
    }
  }

  /**
   * Get payment links as they relate to a contribution.
   *
   * If a payment can be made then include a payment link & if a refund is appropriate
   * then a refund link.
   *
   * @param int $id
   * @param float $balance
   * @param string $contributionStatus
   *
   * @return array
   *   $actionLinks Links array containing:
   *     -url
   *     -title
   */
  protected static function getContributionPaymentLinks($id, $balance, $contributionStatus) {
    if ($contributionStatus === 'Failed' || !CRM_Core_Permission::check('edit contributions')) {
      // In general the balance is the best way to determine if a payment can be added or not,
      // but not for Failed contributions, where we don't accept additional payments at the moment.
      // (in some cases the contribution is 'Pending' and only the payment is failed. In those we
      // do accept more payments agains them.
      return [];
    }
    $actionLinks = [];
    if ((int) $balance > 0) {
      if (CRM_Core_Config::isEnabledBackOfficeCreditCardPayments()) {
        $actionLinks[] = [
          'url' => CRM_Utils_System::url('civicrm/payment', [
            'action' => 'add',
            'reset' => 1,
            'id' => $id,
            'mode' => 'live',
          ]),
          'title' => ts('Submit Credit Card payment'),
        ];
      }
      $actionLinks[] = [
        'url' => CRM_Utils_System::url('civicrm/payment', [
          'action' => 'add',
          'reset' => 1,
          'id' => $id,
        ]),
        'title' => ts('Record Payment'),
      ];
    }
    elseif ((int) $balance < 0) {
      $actionLinks[] = [
        'url' => CRM_Utils_System::url('civicrm/payment', [
          'action' => 'add',
          'reset' => 1,
          'id' => $id,
        ]),
        'title' => ts('Record Refund'),
      ];
    }
    return $actionLinks;
  }

  /**
   * Get a query to determine the amount donated by the contact/s in the current financial year.
   *
   * @param array $contactIDs
   *
   * @return string
   */
  public static function getAnnualQuery($contactIDs) {
    $contactIDs = implode(',', $contactIDs);
    $config = CRM_Core_Config::singleton();
    $currentMonth = date('m');
    $currentDay = date('d');
    if (
      (int) $config->fiscalYearStart['M'] > $currentMonth ||
      (
        (int) $config->fiscalYearStart['M'] == $currentMonth &&
        (int) $config->fiscalYearStart['d'] > $currentDay
      )
    ) {
      $year = date('Y') - 1;
    }
    else {
      $year = date('Y');
    }
    $nextYear = $year + 1;

    if ($config->fiscalYearStart) {
      $newFiscalYearStart = $config->fiscalYearStart;
      if ($newFiscalYearStart['M'] < 10) {
        // This is just a clumsy way of adding padding.
        // @todo next round look for a nicer way.
        $newFiscalYearStart['M'] = '0' . $newFiscalYearStart['M'];
      }
      if ($newFiscalYearStart['d'] < 10) {
        // This is just a clumsy way of adding padding.
        // @todo next round look for a nicer way.
        $newFiscalYearStart['d'] = '0' . $newFiscalYearStart['d'];
      }
      $config->fiscalYearStart = $newFiscalYearStart;
      $monthDay = $config->fiscalYearStart['M'] . $config->fiscalYearStart['d'];
    }
    else {
      // First of January.
      $monthDay = '0101';
    }
    $startDate = "$year$monthDay";
    $endDate = "$nextYear$monthDay";

    $whereClauses = [
      'contact_id' => 'IN (' . $contactIDs . ')',
      'is_test' => ' = 0',
      'receive_date' => ['>=' . $startDate, '<  ' . $endDate],
    ];
    $havingClause = 'contribution_status_id = ' . (int) CRM_Core_PseudoConstant::getKey('CRM_Contribute_BAO_Contribution', 'contribution_status_id', 'Completed');
    CRM_Financial_BAO_FinancialType::addACLClausesToWhereClauses($whereClauses);

    $clauses = [];
    foreach ($whereClauses as $key => $clause) {
      $clauses[] = 'b.' . $key . " " . implode(' AND b.' . $key, (array) $clause);
    }
    $whereClauseString = implode(' AND ', $clauses);

    // See https://github.com/civicrm/civicrm-core/pull/13512 for discussion of how
    // this group by + having on contribution_status_id improves performance
    $query = "
      SELECT COUNT(*) as count,
             SUM(total_amount) as amount,
             AVG(total_amount) as average,
             currency
      FROM civicrm_contribution b
      WHERE " . $whereClauseString . "
      GROUP BY currency, contribution_status_id
      HAVING $havingClause
      ";
    return $query;
  }

  /**
   * Assign Test Value.
   *
   * @param string $fieldName
   * @param array $fieldDef
   * @param int $counter
   */
  protected function assignTestValue($fieldName, &$fieldDef, $counter) {
    if ($fieldName == 'tax_amount') {
      $this->{$fieldName} = "0.00";
    }
    elseif ($fieldName == 'net_amount') {
      $this->{$fieldName} = "2.00";
    }
    elseif ($fieldName == 'total_amount') {
      $this->{$fieldName} = "3.00";
    }
    elseif ($fieldName == 'fee_amount') {
      $this->{$fieldName} = "1.00";
    }
    else {
      parent::assignTestValues($fieldName, $fieldDef, $counter);
    }
  }

  /**
   * Check if contribution has participant/membership payment.
   *
   * @param int $contributionId
   *   Contribution ID
   *
   * @return bool
   */
  public static function allowUpdateRevenueRecognitionDate($contributionId) {
    // get line item for contribution
    $lineItems = CRM_Price_BAO_LineItem::getLineItemsByContributionID($contributionId);
    // check if line item is for membership or participant
    foreach ($lineItems as $items) {
      if ($items['entity_table'] == 'civicrm_participant') {
        $flag = FALSE;
        break;
      }
      elseif ($items['entity_table'] == 'civicrm_membership') {
        $flag = FALSE;
      }
      else {
        $flag = TRUE;
        break;
      }
    }
    return $flag;
  }

  /**
   * Create Accounts Receivable financial trxn entry for Completed Contribution.
   *
   * @param array $trxnParams
   *   Financial trxn params
   * @param array $contributionParams
   *   Contribution Params
   *
   * @return null
   */
  public static function recordAlwaysAccountsReceivable(&$trxnParams, $contributionParams) {
    if (!Civi::settings()->get('always_post_to_accounts_receivable')) {
      return NULL;
    }
    $statusId = $contributionParams['contribution']->contribution_status_id;
    $contributionStatuses = CRM_Contribute_PseudoConstant::contributionStatus(NULL, 'name');
    $contributionStatus = empty($statusId) ? NULL : $contributionStatuses[$statusId];
    $previousContributionStatus = empty($contributionParams['prevContribution']) ? NULL : $contributionStatuses[$contributionParams['prevContribution']->contribution_status_id];
    // Return if contribution status is not completed.
    if (!($contributionStatus == 'Completed' && (empty($previousContributionStatus)
        || (!empty($previousContributionStatus) && $previousContributionStatus == 'Pending'
          && $contributionParams['prevContribution']->is_pay_later == 0
        )))
    ) {
      return NULL;
    }

    $params = $trxnParams;
    $financialTypeID = CRM_Utils_Array::value('financial_type_id', $contributionParams) ? $contributionParams['financial_type_id'] : $contributionParams['prevContribution']->financial_type_id;
    $arAccountId = CRM_Contribute_PseudoConstant::getRelationalFinancialAccount($financialTypeID, 'Accounts Receivable Account is');
    $params['to_financial_account_id'] = $arAccountId;
    $params['status_id'] = array_search('Pending', $contributionStatuses);
    $params['is_payment'] = FALSE;
    $trxn = CRM_Core_BAO_FinancialTrxn::create($params);
    self::$_trxnIDs[] = $trxn->id;
    $trxnParams['from_financial_account_id'] = $params['to_financial_account_id'];
  }

  /**
   * Calculate financial item amount when contribution is updated.
   *
   * @param array $params
   *   contribution params
   * @param array $amountParams
   *
   * @param string $context
   *
   * @return float
   */
  public static function calculateFinancialItemAmount($params, $amountParams, $context) {
    if (!empty($params['is_quick_config'])) {
      $amount = $amountParams['item_amount'];
      if (!$amount) {
        $amount = $params['total_amount'];
        if ($context === NULL) {
          $amount -= CRM_Utils_Array::value('tax_amount', $params, 0);
        }
      }
    }
    else {
      $amount = $amountParams['line_total'];
      if ($context == 'changedAmount') {
        $amount -= $amountParams['previous_line_total'];
      }
      $amount *= $amountParams['diff'];
    }
    return $amount;
  }

  /**
   * Retrieve Sales Tax Financial Accounts.
   *
   *
   * @return array
   *
   */
  public static function getSalesTaxFinancialAccounts() {
    $query = "SELECT cfa.id FROM civicrm_entity_financial_account ce
 INNER JOIN civicrm_financial_account cfa ON ce.financial_account_id = cfa.id
 WHERE `entity_table` = 'civicrm_financial_type' AND cfa.is_tax = 1 AND ce.account_relationship = %1 GROUP BY cfa.id";
    $accountRel = key(CRM_Core_PseudoConstant::accountOptionValues('account_relationship', NULL, " AND v.name LIKE 'Sales Tax Account is' "));
    $queryParams = [1 => [$accountRel, 'Integer']];
    $dao = CRM_Core_DAO::executeQuery($query, $queryParams);
    $financialAccount = [];
    while ($dao->fetch()) {
      $financialAccount[$dao->id] = $dao->id;
    }
    return $financialAccount;
  }

  /**
   * Create tax entry in civicrm_entity_financial_trxn table.
   *
   * @param array $entityParams
   *
   * @param array $eftParams
   *
   * @throws \CiviCRM_API3_Exception
   */
  public static function createProportionalEntry($entityParams, $eftParams) {
    $paid = 0;
    if ($entityParams['contribution_total_amount'] != 0) {
      $paid = $entityParams['line_item_amount'] * ($entityParams['trxn_total_amount'] / $entityParams['contribution_total_amount']);
    }
    // Record Entity Financial Trxn; CRM-20145
    $eftParams['amount'] = CRM_Contribute_BAO_Contribution_Utils::formatAmount($paid);
    civicrm_api3('EntityFinancialTrxn', 'create', $eftParams);
  }

  /**
   * Create array of last financial item id's.
   *
   * @param int $contributionId
   *
   * @return array
   */
  public static function getLastFinancialItemIds($contributionId) {
    $sql = "SELECT fi.id, li.price_field_value_id, li.tax_amount, fi.financial_account_id
      FROM civicrm_financial_item fi
      INNER JOIN civicrm_line_item li ON li.id = fi.entity_id and fi.entity_table = 'civicrm_line_item'
      WHERE li.contribution_id = %1";
    $dao = CRM_Core_DAO::executeQuery($sql, [
      1 => [
        $contributionId,
        'Integer',
      ],
    ]);
    $ftIds = $taxItems = [];
    $salesTaxFinancialAccount = self::getSalesTaxFinancialAccounts();
    while ($dao->fetch()) {
      /* if sales tax item*/
      if (in_array($dao->financial_account_id, $salesTaxFinancialAccount)) {
        $taxItems[$dao->price_field_value_id] = [
          'financial_item_id' => $dao->id,
          'amount' => $dao->tax_amount,
        ];
      }
      else {
        $ftIds[$dao->price_field_value_id] = $dao->id;
      }
    }
    return [$ftIds, $taxItems];
  }

  /**
   * Create proportional entries in civicrm_entity_financial_trxn.
   *
   * @param array $entityParams
   *
   * @param array $lineItems
   *
   * @param array $ftIds
   *
   * @param array $taxItems
   *
   * @throws \CiviCRM_API3_Exception
   */
  public static function createProportionalFinancialEntries($entityParams, $lineItems, $ftIds, $taxItems) {
    $eftParams = [
      'entity_table' => 'civicrm_financial_item',
      'financial_trxn_id' => $entityParams['trxn_id'],
    ];
    foreach ($lineItems as $key => $value) {
      if ($value['qty'] == 0) {
        continue;
      }
      $eftParams['entity_id'] = $ftIds[$value['price_field_value_id']];
      $entityParams['line_item_amount'] = $value['line_total'];
      self::createProportionalEntry($entityParams, $eftParams);
      if (array_key_exists($value['price_field_value_id'], $taxItems)) {
        $entityParams['line_item_amount'] = $taxItems[$value['price_field_value_id']]['amount'];
        $eftParams['entity_id'] = $taxItems[$value['price_field_value_id']]['financial_item_id'];
        self::createProportionalEntry($entityParams, $eftParams);
      }
    }
  }

  /**
   * Load entities related to the contribution into $this->_relatedObjects.
   *
   * @param array $ids
   *
   * @throws \CRM_Core_Exception
   */
  protected function loadRelatedEntitiesByID($ids) {
    $entities = [
      'contact' => 'CRM_Contact_BAO_Contact',
      'contributionRecur' => 'CRM_Contribute_BAO_ContributionRecur',
      'contributionType' => 'CRM_Financial_BAO_FinancialType',
      'financialType' => 'CRM_Financial_BAO_FinancialType',
      'contributionPage' => 'CRM_Contribute_BAO_ContributionPage',
    ];
    foreach ($entities as $entity => $bao) {
      if (!empty($ids[$entity])) {
        $this->_relatedObjects[$entity] = new $bao();
        $this->_relatedObjects[$entity]->id = $ids[$entity];
        if (!$this->_relatedObjects[$entity]->find(TRUE)) {
          throw new CRM_Core_Exception($entity . ' could not be loaded');
        }
      }
    }
  }

  /**
   * Should an email receipt be sent for this contribution when complete.
   *
   * @param array $input
   *
   * @return mixed
   */
  protected function isEmailReceipt($input) {
    if (isset($input['is_email_receipt'])) {
      return $input['is_email_receipt'];
    }
    if (!empty($this->_relatedObjects['contribution_page_id'])) {
      return $this->_relatedObjects['contribution_page_id']->is_email_receipt;
    }
    return TRUE;
  }

  /**
   * Function to replace contribution tokens.
   *
   * @param array $contributionIds
   *
   * @param string $subject
   *
   * @param array $subjectToken
   *
   * @param string $text
   *
   * @param string $html
   *
   * @param array $messageToken
   *
   * @param bool $escapeSmarty
   *
   * @return array
   * @throws \CiviCRM_API3_Exception
   */
  public static function replaceContributionTokens(
    $contributionIds,
    $subject,
    $subjectToken,
    $text,
    $html,
    $messageToken,
    $escapeSmarty
  ) {
    if (empty($contributionIds)) {
      return [];
    }
    $contributionDetails = [];
    foreach ($contributionIds as $id) {
      $result = self::getContributionTokenValues($id, $messageToken);
      $contributionDetails[$result['values'][$result['id']]['contact_id']]['subject'] = CRM_Utils_Token::replaceContributionTokens($subject, $result, FALSE, $subjectToken, FALSE, $escapeSmarty);
      $contributionDetails[$result['values'][$result['id']]['contact_id']]['text'] = CRM_Utils_Token::replaceContributionTokens($text, $result, FALSE, $messageToken, FALSE, $escapeSmarty);
      $contributionDetails[$result['values'][$result['id']]['contact_id']]['html'] = CRM_Utils_Token::replaceContributionTokens($html, $result, FALSE, $messageToken, FALSE, $escapeSmarty);
    }
    return $contributionDetails;
  }

  /**
   * Get the contribution fields for $id and display labels where
   * appropriate (if the token is present).
   *
   * @param int $id
   * @param array $messageToken
   * @return array
   */
  public static function getContributionTokenValues($id, $messageToken) {
    if (empty($id)) {
      return [];
    }
    $result = civicrm_api3('Contribution', 'get', ['id' => $id]);
    // lab.c.o mail#46 - show labels, not values, for custom fields with option values.
    if (!empty($messageToken)) {
      foreach ($result['values'][$id] as $fieldName => $fieldValue) {
        if (strpos($fieldName, 'custom_') === 0 && array_search($fieldName, $messageToken['contribution']) !== FALSE) {
          $result['values'][$id][$fieldName] = CRM_Core_BAO_CustomField::displayValue($result['values'][$id][$fieldName], $fieldName);
        }
      }
    }
    return $result;
  }

  /**
   * Get invoice_number for contribution.
   *
   * @param int $contributionID
   *
   * @return string
   */
  public static function getInvoiceNumber($contributionID) {
    if ($invoicePrefix = self::checkContributeSettings('invoice_prefix', TRUE)) {
      return $invoicePrefix . $contributionID;
    }

    return NULL;
  }

  /**
   * Load the values needed for the event message.
   *
   * @param int $eventID
   * @param int $participantID
   * @param int|null $contributionID
   *
   * @return array
   * @throws \CRM_Core_Exception
   */
  protected function loadEventMessageTemplateParams(int $eventID, int $participantID, $contributionID): array {

    $eventParams = [
      'id' => $eventID,
    ];
    $values = ['event' => []];

    CRM_Event_BAO_Event::retrieve($eventParams, $values['event']);
    // add custom fields for event
    $eventGroupTree = CRM_Core_BAO_CustomGroup::getTree('Event', NULL, $eventID);

    $eventCustomGroup = [];
    foreach ($eventGroupTree as $key => $group) {
      if ($key === 'info') {
        continue;
      }

      foreach ($group['fields'] as $k => $customField) {
        $groupLabel = $group['title'];
        if (!empty($customField['customValue'])) {
          foreach ($customField['customValue'] as $customFieldValues) {
            $eventCustomGroup[$groupLabel][$customField['label']] = CRM_Utils_Array::value('data', $customFieldValues);
          }
        }
      }
    }
    $values['event']['customGroup'] = $eventCustomGroup;

    //get participant details
    $participantParams = [
      'id' => $participantID,
    ];

    $values['participant'] = [];

    CRM_Event_BAO_Participant::getValues($participantParams, $values['participant'], $participantIds);
    // add custom fields for event
    $participantGroupTree = CRM_Core_BAO_CustomGroup::getTree('Participant', NULL, $participantID);
    $participantCustomGroup = [];
    foreach ($participantGroupTree as $key => $group) {
      if ($key === 'info') {
        continue;
      }

      foreach ($group['fields'] as $k => $customField) {
        $groupLabel = $group['title'];
        if (!empty($customField['customValue'])) {
          foreach ($customField['customValue'] as $customFieldValues) {
            $participantCustomGroup[$groupLabel][$customField['label']] = CRM_Utils_Array::value('data', $customFieldValues);
          }
        }
      }
    }
    $values['participant']['customGroup'] = $participantCustomGroup;

    //get location details
    $locationParams = [
      'entity_id' => $eventID,
      'entity_table' => 'civicrm_event',
    ];
    $values['location'] = CRM_Core_BAO_Location::getValues($locationParams);

    $ufJoinParams = [
      'entity_table' => 'civicrm_event',
      'entity_id' => $eventID,
      'module' => 'CiviEvent',
    ];

    list($custom_pre_id,
      $custom_post_ids
      ) = CRM_Core_BAO_UFJoin::getUFGroupIds($ufJoinParams);

    $values['custom_pre_id'] = $custom_pre_id;
    $values['custom_post_id'] = $custom_post_ids;

    // set lineItem for event contribution
    if ($contributionID) {
      $participantIds = CRM_Event_BAO_Participant::getParticipantIds($contributionID);
      if (!empty($participantIds)) {
        foreach ($participantIds as $pIDs) {
          $lineItem = CRM_Price_BAO_LineItem::getLineItems($pIDs);
          if (!CRM_Utils_System::isNull($lineItem)) {
            $values['lineItem'][] = $lineItem;
          }
        }
      }
    }
    return $values;
  }

}
