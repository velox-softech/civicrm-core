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
 *
 * @package CRM
 * @copyright CiviCRM LLC https://civicrm.org/licensing
 */

/**
 * This class generates form components for processing a participation fee block
 */
class CRM_Event_Form_EventFees {

  /**
   * Set variables up before form is built.
   *
   * @param CRM_Core_Form $form
   *
   * @throws \CRM_Core_Exception
   */
  public static function preProcess(&$form) {
    //as when call come from register.php
    if (!$form->_eventId) {
      $form->_eventId = CRM_Utils_Request::retrieve('eventId', 'Positive', $form);
    }

    $form->_pId = CRM_Utils_Request::retrieve('participantId', 'Positive', $form);
    $form->_discountId = CRM_Utils_Request::retrieve('discountId', 'Positive', $form);

    $form->_fromEmails = CRM_Event_BAO_Event::getFromEmailIds($form->_eventId);

    //CRM-6907 set event specific currency.
    if ($form->_eventId &&
      ($currency = CRM_Core_DAO::getFieldValue('CRM_Event_DAO_Event', $form->_eventId, 'currency'))
    ) {
      CRM_Core_Config::singleton()->defaultCurrency = $currency;
    }
  }

  /**
   * This function sets the default values for the form in edit/view mode.
   *
   * @param CRM_Core_Form $form
   *
   * @return array
   */
  public static function setDefaultValues(&$form) {
    $defaults = [];

    if ($form->_eventId) {
      //get receipt text and financial type
      $returnProperities = ['confirm_email_text', 'financial_type_id', 'campaign_id', 'start_date'];
      $details = [];
      CRM_Core_DAO::commonRetrieveAll('CRM_Event_DAO_Event', 'id', $form->_eventId, $details, $returnProperities);
      if (!empty($details[$form->_eventId]['financial_type_id'])) {
        $defaults[$form->_pId]['financial_type_id'] = $details[$form->_eventId]['financial_type_id'];
      }
    }

    if ($form->_pId) {
      $ids = [];
      $params = ['id' => $form->_pId];

      CRM_Event_BAO_Participant::getValues($params, $defaults, $ids);
      if ($form->_action == CRM_Core_Action::UPDATE) {
        $discounts = [];
        if (!empty($form->_values['discount'])) {
          foreach ($form->_values['discount'] as $key => $value) {
            $value = current($value);
            $discounts[$key] = $value['name'];
          }
        }

        if ($form->_discountId && !empty($discounts[$defaults[$form->_pId]['discount_id']])) {
          $form->assign('discount', $discounts[$defaults[$form->_pId]['discount_id']]);
        }

        $form->assign('fee_amount', CRM_Utils_Array::value('fee_amount', $defaults[$form->_pId]));
        $form->assign('fee_level', CRM_Utils_Array::value('fee_level', $defaults[$form->_pId]));
      }
      $defaults[$form->_pId]['send_receipt'] = 0;
    }
    else {
      $defaults[$form->_pId]['send_receipt'] = (strtotime(CRM_Utils_Array::value('start_date', $details[$form->_eventId])) >= time()) ? 1 : 0;
      if ($form->_eventId && !empty($details[$form->_eventId]['confirm_email_text'])) {
        //set receipt text
        $defaults[$form->_pId]['receipt_text'] = $details[$form->_eventId]['confirm_email_text'];
      }

      $defaults[$form->_pId]['receive_date'] = date('Y-m-d H:i:s');
    }

    //CRM-11601 we should keep the record contribution
    //true by default while adding participant
    if ($form->_action == CRM_Core_Action::ADD && !$form->_mode && $form->_isPaidEvent) {
      $defaults[$form->_pId]['record_contribution'] = 1;
    }

    //CRM-13420
    if (empty($defaults['payment_instrument_id'])) {
      $defaults[$form->_pId]['payment_instrument_id'] = key(CRM_Core_OptionGroup::values('payment_instrument', FALSE, FALSE, FALSE, 'AND is_default = 1'));
    }
    if ($form->_mode) {
      $config = CRM_Core_Config::singleton();
      // set default country from config if no country set
      if (empty($defaults[$form->_pId]["billing_country_id-{$form->_bltID}"])) {
        $defaults[$form->_pId]["billing_country_id-{$form->_bltID}"] = $config->defaultContactCountry;
      }

      if (empty($defaults["billing_state_province_id-{$form->_bltID}"])) {
        $defaults[$form->_pId]["billing_state_province_id-{$form->_bltID}"] = $config->defaultContactStateProvince;
      }

      $billingDefaults = $form->getProfileDefaults('Billing', $form->_contactId);
      $defaults[$form->_pId] = array_merge($defaults[$form->_pId], $billingDefaults);
    }

    // if user has selected discount use that to set default
    if (isset($form->_discountId)) {
      $defaults[$form->_pId]['discount_id'] = $form->_discountId;

      //hack to set defaults for already selected discount value
      if ($form->_action == CRM_Core_Action::UPDATE && !$form->_originalDiscountId) {
        $form->_originalDiscountId = $defaults[$form->_pId]['discount_id'];
        if ($form->_originalDiscountId) {
          $defaults[$form->_pId]['discount_id'] = $form->_originalDiscountId;
        }
      }
      $discountId = $form->_discountId;
    }
    else {
      $discountId = CRM_Core_BAO_Discount::findSet($form->_eventId, 'civicrm_event');
    }

    if ($discountId) {
      $priceSetId = CRM_Core_DAO::getFieldValue('CRM_Core_BAO_Discount', $discountId, 'price_set_id');
    }
    else {
      $priceSetId = CRM_Price_BAO_PriceSet::getFor('civicrm_event', $form->_eventId);
    }

    if (($form->_action == CRM_Core_Action::ADD) && $form->_eventId && $discountId) {
      // this case is for add mode, where we show discount automatically
      $defaults[$form->_pId]['discount_id'] = $discountId;
    }

    if ($priceSetId) {
      // get price set default values, CRM-4090
      if (in_array(get_class($form),
        [
          'CRM_Event_Form_Participant',
          'CRM_Event_Form_Registration_Register',
          'CRM_Event_Form_Registration_AdditionalParticipant',
        ]
      )) {
        $priceSetValues = self::setDefaultPriceSet($form->_pId, $form->_eventId);
        if (!empty($priceSetValues)) {
          $defaults[$form->_pId] = array_merge($defaults[$form->_pId], $priceSetValues);
        }
      }

      if ($form->_action == CRM_Core_Action::ADD && !empty($form->_priceSet['fields'])) {
        foreach ($form->_priceSet['fields'] as $key => $val) {
          foreach ($val['options'] as $keys => $values) {
            if ($values['is_default']) {
              if (get_class($form) != 'CRM_Event_Form_Participant' && !empty($values['is_full'])) {
                continue;
              }

              if ($val['html_type'] == 'CheckBox') {
                $defaults[$form->_pId]["price_{$key}"][$keys] = 1;
              }
              else {
                $defaults[$form->_pId]["price_{$key}"] = $keys;
              }
            }
          }
        }
      }

      $form->assign('totalAmount', CRM_Utils_Array::value('fee_amount', $defaults[$form->_pId]));
      if ($form->_action == CRM_Core_Action::UPDATE) {
        $fee_level = $defaults[$form->_pId]['fee_level'];
        CRM_Event_BAO_Participant::fixEventLevel($fee_level);
        $form->assign('fee_level', $fee_level);
        $form->assign('fee_amount', CRM_Utils_Array::value('fee_amount', $defaults[$form->_pId]));
      }
    }

    //CRM-4453
    if (!empty($defaults[$form->_pId]['participant_fee_currency'])) {
      $form->assign('fee_currency', $defaults[$form->_pId]['participant_fee_currency']);
    }

    // CRM-4395
    if ($contriId = $form->get('onlinePendingContributionId')) {
      $defaults[$form->_pId]['record_contribution'] = 1;
      $contribution = new CRM_Contribute_DAO_Contribution();
      $contribution->id = $contriId;
      $contribution->find(TRUE);
      foreach ([
        'financial_type_id',
        'payment_instrument_id',
        'contribution_status_id',
        'receive_date',
        'total_amount',
      ] as $f) {
        $defaults[$form->_pId][$f] = $contribution->$f;
      }
    }
    return $defaults[$form->_pId];
  }

  /**
   * This function sets the default values for price set.
   *
   * @param int $participantID
   * @param int $eventID
   * @param bool $includeQtyZero
   *
   * @return array
   */
  public static function setDefaultPriceSet($participantID, $eventID = NULL, $includeQtyZero = TRUE) {
    $defaults = [];
    if (!$eventID && $participantID) {
      $eventID = CRM_Core_DAO::getFieldValue('CRM_Event_DAO_Participant', $participantID, 'event_id');
    }
    if (!$participantID || !$eventID) {
      return $defaults;
    }

    // get price set ID.
    $priceSetID = CRM_Price_BAO_PriceSet::getFor('civicrm_event', $eventID);
    if (!$priceSetID) {
      return $defaults;
    }

    // use line items for setdefault price set fields, CRM-4090
    $lineItems[$participantID] = CRM_Price_BAO_LineItem::getLineItems($participantID, 'participant', FALSE, $includeQtyZero);

    if (is_array($lineItems[$participantID]) &&
      !CRM_Utils_System::isNull($lineItems[$participantID])
    ) {

      $priceFields = $htmlTypes = $optionValues = [];
      foreach ($lineItems[$participantID] as $lineId => $items) {
        $priceFieldId = CRM_Utils_Array::value('price_field_id', $items);
        $priceOptionId = CRM_Utils_Array::value('price_field_value_id', $items);
        if ($priceFieldId && $priceOptionId) {
          $priceFields[$priceFieldId][] = $priceOptionId;
        }
      }

      if (empty($priceFields)) {
        return $defaults;
      }

      // get all price set field html types.
      $sql = '
SELECT  id, html_type
  FROM  civicrm_price_field
 WHERE  id IN (' . implode(',', array_keys($priceFields)) . ')';
      $fieldDAO = CRM_Core_DAO::executeQuery($sql);
      while ($fieldDAO->fetch()) {
        $htmlTypes[$fieldDAO->id] = $fieldDAO->html_type;
      }

      foreach ($lineItems[$participantID] as $lineId => $items) {
        $fieldId = $items['price_field_id'];
        $htmlType = CRM_Utils_Array::value($fieldId, $htmlTypes);
        if (!$htmlType) {
          continue;
        }

        if ($htmlType == 'Text') {
          $defaults["price_{$fieldId}"] = $items['qty'];
        }
        else {
          $fieldOptValues = CRM_Utils_Array::value($fieldId, $priceFields);
          if (!is_array($fieldOptValues)) {
            continue;
          }

          foreach ($fieldOptValues as $optionId) {
            if ($htmlType == 'CheckBox') {
              $defaults["price_{$fieldId}"][$optionId] = TRUE;
            }
            else {
              $defaults["price_{$fieldId}"] = $optionId;
              break;
            }
          }
        }
      }
    }

    return $defaults;
  }

  /**
   * Build the form object.
   *
   * @param CRM_Core_Form $form
   */
  public static function buildQuickForm(&$form) {
    if ($form->_eventId) {
      $form->_isPaidEvent = CRM_Core_DAO::getFieldValue('CRM_Event_DAO_Event', $form->_eventId, 'is_monetary');
      if ($form->_isPaidEvent) {
        $form->addElement('hidden', 'hidden_feeblock', 1);
      }

      // make sure this is for backoffice registration.
      if ($form->getName() == 'Participant') {
        $eventfullMsg = CRM_Event_BAO_Participant::eventFullMessage($form->_eventId, $form->_pId);
        $form->addElement('hidden', 'hidden_eventFullMsg', $eventfullMsg, ['id' => 'hidden_eventFullMsg']);
      }
    }

    if ($form->_pId) {
      if (CRM_Core_DAO::getFieldValue('CRM_Event_DAO_ParticipantPayment',
        $form->_pId, 'contribution_id', 'participant_id'
      )
      ) {
        $form->_online = !$form->isBackOffice;
      }
    }

    if ($form->_isPaidEvent) {
      $params = ['id' => $form->_eventId];
      CRM_Event_BAO_Event::retrieve($params, $event);

      //retrieve custom information
      $form->_values = [];
      CRM_Event_Form_Registration::initEventFee($form, $event['id']);
      CRM_Event_Form_Registration_Register::buildAmount($form, TRUE, $form->_discountId);
      $lineItem = [];
      $invoiceSettings = Civi::settings()->get('contribution_invoice_settings');
      $invoicing = CRM_Utils_Array::value('invoicing', $invoiceSettings);
      $totalTaxAmount = 0;
      if (!CRM_Utils_System::isNull(CRM_Utils_Array::value('line_items', $form->_values))) {
        $lineItem[] = $form->_values['line_items'];
        foreach ($form->_values['line_items'] as $key => $value) {
          $totalTaxAmount = $value['tax_amount'] + $totalTaxAmount;
        }
      }
      if ($invoicing) {
        $form->assign('totalTaxAmount', $totalTaxAmount);
      }
      $form->assign('lineItem', empty($lineItem) ? FALSE : $lineItem);
      $discounts = [];
      if (!empty($form->_values['discount'])) {
        foreach ($form->_values['discount'] as $key => $value) {
          $value = current($value);
          $discounts[$key] = $value['name'];
        }

        $element = $form->add('select', 'discount_id',
          ts('Discount Set'),
          [
            0 => ts('- select -'),
          ] + $discounts,
          FALSE,
          ['class' => "crm-select2"]
        );

        if ($form->_online) {
          $element->freeze();
        }
      }
      if (CRM_Financial_BAO_FinancialType::isACLFinancialTypeStatus()
        && !CRM_Utils_Array::value('fee', $form->_values)
        && CRM_Utils_Array::value('snippet', $_REQUEST) == CRM_Core_Smarty::PRINT_NOFORM
      ) {
        CRM_Core_Session::setStatus(ts('You do not have all the permissions needed for this page.'), 'Permission Denied', 'error');
        return FALSE;
      }

      CRM_Core_Payment_Form::buildPaymentForm($form, $form->_paymentProcessor, FALSE, TRUE, self::getDefaultPaymentInstrumentId());
      if (!$form->_mode) {
        $form->addElement('checkbox', 'record_contribution', ts('Record Payment?'), NULL,
          ['onclick' => "return showHideByValue('record_contribution','','payment_information','table-row','radio',false);"]
        );
        // Check permissions for financial type first
        if (CRM_Financial_BAO_FinancialType::isACLFinancialTypeStatus()) {
          CRM_Financial_BAO_FinancialType::getAvailableFinancialTypes($financialTypes, $form->_action);
        }
        else {
          $financialTypes = CRM_Contribute_PseudoConstant::financialType();
        }

        $form->add('select', 'financial_type_id',
          ts('Financial Type'),
          ['' => ts('- select -')] + $financialTypes
        );

        $form->add('datepicker', 'receive_date', ts('Received'), [], FALSE, ['time' => TRUE]);

        $form->add('select', 'payment_instrument_id',
          ts('Payment Method'),
          ['' => ts('- select -')] + CRM_Contribute_PseudoConstant::paymentInstrument(),
          FALSE, ['onChange' => "return showHideByValue('payment_instrument_id','4','checkNumber','table-row','select',false);"]
        );
        // don't show transaction id in batch update mode
        $path = CRM_Utils_System::currentPath();
        $form->assign('showTransactionId', FALSE);
        if ($path != 'civicrm/contact/search/basic') {
          $form->add('text', 'trxn_id', ts('Transaction ID'));
          $form->addRule('trxn_id', ts('Transaction ID already exists in Database.'),
            'objectExists', ['CRM_Contribute_DAO_Contribution', $form->_eventId, 'trxn_id']
          );
          $form->assign('showTransactionId', TRUE);
        }

        $form->add('select', 'contribution_status_id',
          ts('Payment Status'), CRM_Contribute_BAO_Contribution_Utils::getContributionStatuses('participant')
        );

        $form->add('text', 'check_number', ts('Check Number'),
          CRM_Core_DAO::getAttribute('CRM_Contribute_DAO_Contribution', 'check_number')
        );

        $form->add('text', 'total_amount', ts('Amount'),
          CRM_Core_DAO::getAttribute('CRM_Contribute_DAO_Contribution', 'total_amount')
        );
      }
    }
    else {
      $form->add('text', 'amount', ts('Event Fee(s)'));
    }
    $form->assign('onlinePendingContributionId', $form->get('onlinePendingContributionId'));

    $form->assign('paid', $form->_isPaidEvent);

    $form->addElement('checkbox',
      'send_receipt',
      ts('Send Confirmation?'), NULL,
      ['onclick' => "showHideByValue('send_receipt','','notice','table-row','radio',false); showHideByValue('send_receipt','','from-email','table-row','radio',false);"]
    );

    $form->add('select', 'from_email_address', ts('Receipt From'), $form->_fromEmails['from_email_id']);

    $form->add('textarea', 'receipt_text', ts('Confirmation Message'));

    // Retrieve the name and email of the contact - form will be the TO for receipt email ( only if context is not standalone)
    if ($form->_context != 'standalone') {
      if ($form->_contactId) {
        list($form->_contributorDisplayName,
          $form->_contributorEmail
          ) = CRM_Contact_BAO_Contact_Location::getEmailDetails($form->_contactId);
        $form->assign('email', $form->_contributorEmail);
      }
      else {
        //show email block for batch update for event
        $form->assign('batchEmail', TRUE);
      }
    }

    $mailingInfo = Civi::settings()->get('mailing_backend');
    $form->assign('outBound_option', $mailingInfo['outBound_option']);
    $form->assign('hasPayment', $form->_paymentId);
  }

  /**
   * Get the default payment instrument id.
   *
   * @todo resolve relationship between this form & abstractEdit -which should be it's parent.
   *
   * @return int
   */
  protected static function getDefaultPaymentInstrumentId() {
    $paymentInstrumentID = CRM_Utils_Request::retrieve('payment_instrument_id', 'Integer');
    if ($paymentInstrumentID) {
      return $paymentInstrumentID;
    }
    return key(CRM_Core_OptionGroup::values('payment_instrument', FALSE, FALSE, FALSE, 'AND is_default = 1'));
  }

}
