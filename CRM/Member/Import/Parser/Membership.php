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
use Civi\Api4\Membership;

/**
 * class to parse membership csv files
 */
class CRM_Member_Import_Parser_Membership extends CRM_Import_Parser {

  /**
   * Array of metadata for all available fields.
   *
   * @var array
   */
  protected $fieldMetadata = [];

  /**
   * Has this parser been fixed to expect `getMappedRow` to break it up
   * by entity yet? This is a transitional property to allow the classes
   * to be fixed up individually.
   *
   * @var bool
   */
  protected $isUpdatedForEntityRowParsing = TRUE;

  /**
   * Array of successfully imported membership id's
   *
   * @var array
   */
  protected $_newMemberships;

  /**
   * Separator being used
   * @var string
   */
  protected $_separator;

  /**
   * Get information about the provided job.
   *  - name
   *  - id (generally the same as name)
   *  - label
   *
   *  e.g. ['activity_import' => ['id' => 'activity_import', 'label' => ts('Activity Import'), 'name' => 'activity_import']]
   *
   * @return array
   */
  public static function getUserJobInfo(): array {
    return [
      'membership_import' => [
        'id' => 'membership_import',
        'name' => 'membership_import',
        'label' => ts('Membership Import'),
        'entity' => 'Membership',
        'url' => 'civicrm/import/membership',

      ],
    ];
  }

  /**
   * Get a list of entities this import supports.
   *
   * @return array
   */
  public function getImportEntities() : array {
    return [
      'Membership' => ['text' => ts('Membership Fields'), 'is_contact' => FALSE],
      'Contact' => ['text' => ts('Contact Fields'), 'is_contact' => TRUE],
    ];
  }

  /**
   * Validate the values.
   *
   * @param array $values
   *   The array of values belonging to this line.
   *
   * @throws \CRM_Core_Exception
   */
  public function validateValues(array $values): void {
    $params = $this->getMappedRow($values);
    $errors = [];
    foreach ($params as $key => $value) {
      $errors = array_merge($this->getInvalidValues($value, $key), $errors);
    }
    $this->validateRequiredFields($this->getRequiredFields(), $params['Membership']);

    //To check whether start date or join date is provided
    if (empty($params['Membership']['start_date']) && empty($params['Membership']['join_date'])) {
      $errors[] = 'Membership Start Date is required to create a memberships.';
    }
    //fix for CRM-2219 Update Membership
    if ($this->isUpdateExisting() && !empty($params['Membership']['is_override']) && empty($params['Membership']['status_id'])) {
      $errors[] = 'Required parameter missing: Status';
    }
    if ($errors) {
      throw new CRM_Core_Exception('Invalid value for field(s) : ' . implode(',', $errors));
    }
  }

  /**
   * Get the required fields.
   *
   * @return array
   */
  public function getRequiredFields(): array {
    return [[$this->getRequiredFieldsForMatch(), $this->getRequiredFieldsForCreate()]];
  }

  /**
   * Get required fields to create a contribution.
   *
   * @return array
   */
  public function getRequiredFieldsForCreate(): array {
    return ['membership_type_id'];
  }

  /**
   * Get required fields to match a contribution.
   *
   * @return array
   */
  public function getRequiredFieldsForMatch(): array {
    return [['id']];
  }

  /**
   * Handle the values in import mode.
   *
   * @param array $values
   *   The array of values belonging to this line.
   *
   * @return int|void|null
   *   the result of this processing - which is ignored
   */
  public function import(array $values) {
    $rowNumber = (int) ($values[array_key_last($values)]);
    try {
      $params = $this->getMappedRow($values);
      $this->removeEmptyValues($params);
      $membershipParams = $params['Membership'];
      $contactParams = $params['Contact'] ?? [];
      if (!empty($membershipParams['contact_id'])) {
        $this->validateContactID($membershipParams['contact_id'], $this->getContactType());
      }

      $existingMembership = [];
      if (!empty($membershipParams['id'])) {
        $existingMembership = Membership::get()
          ->addWhere('id', '=', $membershipParams['id'])
          ->execute()->single();
      }
      $formatted = $formatValues = $membershipParams;
      // don't add to recent items, CRM-4399
      $formatted['skipRecentView'] = TRUE;
      if (!$this->isUpdateExisting()) {
        $formatted['custom'] = CRM_Core_BAO_CustomField::postProcess($formatted,
          NULL,
          'Membership'
        );
      }

      $startDate = $membershipParams['start_date'] ?? $existingMembership['start_date'] ?? NULL;
      // Assign join date equal to start date if join date is not provided.
      $joinDate = $membershipParams['join_date'] ?? $existingMembership['join_date'] ?? $startDate;
      $endDate = $membershipParams['end_date'] ?? $existingMembership['end_date'] ?? NULL;
      $membershipTypeID = $membershipParams['membership_type_id'] ?? $existingMembership['membership_type_id'];
      $isOverride = $membershipParams['is_override'] ?? $existingMembership['is_override'] ?? FALSE;

      if (!$existingMembership && empty($formatValues['contact_id'])) {
        $error = $this->checkContactDuplicate($params['Contact']);

        if (CRM_Core_Error::isAPIError($error, CRM_Core_Error::DUPLICATE_CONTACT)) {
          $matchedIDs = (array) $error['error_message']['params'];
          if (count($matchedIDs) > 1) {
            throw new CRM_Core_Exception('Multiple matching contact records detected for this row. The membership was not imported', CRM_Import_Parser::ERROR);
          }
          else {
            $cid = $matchedIDs[0];
            $formatted['contact_id'] = $cid;

            //fix for CRM-1924
            $calcDates = CRM_Member_BAO_MembershipType::getDatesForMembershipType($membershipTypeID,
              $joinDate,
              $startDate,
              $endDate
            );
            $this->formattedDates($calcDates, $formatted);

            //fix for CRM-3570, exclude the statuses those having is_admin = 1
            //now user can import is_admin if is override is true.
            $excludeIsAdmin = FALSE;
            if (!$isOverride) {
              $formatted['exclude_is_admin'] = $excludeIsAdmin = TRUE;
            }
            $calcStatus = CRM_Member_BAO_MembershipStatus::getMembershipStatusByDate($startDate,
              $endDate,
              $joinDate,
              'now',
              $excludeIsAdmin,
              $membershipTypeID,
              $formatted
            );

            if (empty($formatted['status_id'])) {
              $formatted['status_id'] = $calcStatus['id'];
            }
            elseif (!$isOverride) {
              if (empty($calcStatus)) {
                throw new CRM_Core_Exception('Status in import row (' . $formatValues['status_id'] . ') does not match calculated status based on your configured Membership Status Rules. Record was not imported.', CRM_Import_Parser::ERROR);
              }
              if ($formatted['status_id'] != $calcStatus['id']) {
                //Status Hold" is either NOT mapped or is FALSE
                throw new CRM_Core_Exception('Status in import row (' . $formatValues['status_id'] . ') does not match calculated status based on your configured Membership Status Rules (' . $calcStatus['name'] . '). Record was not imported.', CRM_Import_Parser::ERROR);
              }
            }

            $newMembership = civicrm_api3('membership', 'create', $formatted);

            $this->_newMemberships[] = $newMembership['id'];
            $this->setImportStatus($rowNumber, 'IMPORTED', '');
            return CRM_Import_Parser::VALID;
          }
        }
        else {
          // Using new Dedupe rule.
          $ruleParams = [
            'contact_type' => $this->getContactType(),
            'used' => 'Unsupervised',
          ];
          $fieldsArray = CRM_Dedupe_BAO_DedupeRule::dedupeRuleFields($ruleParams);
          $disp = '';

          foreach ($fieldsArray as $value) {
            if (array_key_exists(trim($value), $contactParams)) {
              $paramValue = $contactParams[trim($value)];
              if (is_array($paramValue)) {
                $disp .= $contactParams[trim($value)][0][trim($value)] . " ";
              }
              else {
                $disp .= $contactParams[trim($value)] . " ";
              }
            }
          }

          if (!empty($contactParams['external_identifier'])) {
            if ($disp) {
              $disp .= "AND {$contactParams['external_identifier']}";
            }
            else {
              $disp = $contactParams['external_identifier'];
            }
          }
          throw new CRM_Core_Exception('No matching Contact found for (' . $disp . ')', CRM_Import_Parser::ERROR);
        }
      }
      else {
        if (!empty($formatValues['external_identifier'])) {
          $checkCid = new CRM_Contact_DAO_Contact();
          $checkCid->external_identifier = $formatValues['external_identifier'];
          $checkCid->find(TRUE);
          if ($checkCid->id != $formatted['contact_id']) {
            throw new CRM_Core_Exception('Mismatch of External ID:' . $formatValues['external_identifier'] . ' and Contact Id:' . $formatted['contact_id'], CRM_Import_Parser::ERROR);
          }
        }

        //to calculate dates
        $calcDates = CRM_Member_BAO_MembershipType::getDatesForMembershipType($membershipTypeID,
          $joinDate,
          $startDate,
          $endDate
        );
        $this->formattedDates($calcDates, $formatted);
        //end of date calculation part

        //fix for CRM-3570, exclude the statuses those having is_admin = 1
        //now user can import is_admin if is override is true.
        $excludeIsAdmin = FALSE;
        if (!$isOverride) {
          $formatted['exclude_is_admin'] = $excludeIsAdmin = TRUE;
        }
        $calcStatus = CRM_Member_BAO_MembershipStatus::getMembershipStatusByDate($startDate,
          $endDate,
          $joinDate,
          'now',
          $excludeIsAdmin,
          $membershipTypeID,
          $formatted
        );
        if (empty($formatted['status_id'])) {
          $formatted['status_id'] = $calcStatus['id'] ?? NULL;
        }
        elseif (!$isOverride) {
          if (empty($calcStatus)) {
            throw new CRM_Core_Exception('Status in import row (' . ($formatValues['status_id'] ?? '') . ') does not match calculated status based on your configured Membership Status Rules. Record was not imported.', CRM_Import_Parser::ERROR);
          }
          if ($formatted['status_id'] != $calcStatus['id']) {
            //Status Hold" is either NOT mapped or is FALSE
            throw new CRM_Core_Exception('Status in import row (' . ($formatValues['status_id'] ?? '') . ') does not match calculated status based on your configured Membership Status Rules (' . $calcStatus['name'] . '). Record was not imported.', CRM_Import_Parser::ERROR);
          }
        }

        $newMembership = civicrm_api3('membership', 'create', $formatted);
        $this->setImportStatus($rowNumber, 'IMPORTED', '', $newMembership['id']);
        return CRM_Import_Parser::VALID;
      }
    }
    catch (CRM_Core_Exception $e) {
      $this->setImportStatus($rowNumber, 'ERROR', $e->getMessage());
      return CRM_Import_Parser::ERROR;
    }
  }

  /**
   *  to calculate join, start and end dates
   *
   * @param array $calcDates
   *   Array of dates returned by getDatesForMembershipType().
   *
   * @param $formatted
   *
   */
  public function formattedDates($calcDates, &$formatted) {
    $dates = [
      'join_date',
      'start_date',
      'end_date',
    ];

    foreach ($dates as $d) {
      if (isset($formatted[$d]) &&
        !CRM_Utils_System::isNull($formatted[$d])
      ) {
        $formatted[$d] = CRM_Utils_Date::isoToMysql($formatted[$d]);
      }
      elseif (isset($calcDates[$d])) {
        $formatted[$d] = CRM_Utils_Date::isoToMysql($calcDates[$d]);
      }
    }
  }

  /**
   * Set field metadata.
   */
  protected function setFieldMetadata(): void {
    if (empty($this->importableFieldsMetadata)) {
      $metadata = $this->getImportableFields($this->getContactType());
      // We are consolidating on `importableFieldsMetadata` - but both still used.
      $this->importableFieldsMetadata = $this->fieldMetadata = $metadata;
    }
  }

  /**
   * @param string $contactType
   *
   * @return array|mixed
   * @throws \CRM_Core_Exception
   */
  protected function getImportableFields(string $contactType = 'Individual'): array {
    $fields = Civi::cache('fields')->get('membership_importable_fields' . $contactType);
    if (!$fields) {
      $fields = ['' => ['title' => '- ' . ts('do not import') . ' -']];

      $tmpFields = CRM_Member_DAO_Membership::import();
      $tmpContactField = $this->getContactMatchingFields();
      $tmpFields['membership_contact_id']['title'] .= ' ' . ts('(match to contact)');

      $fields = array_merge($fields, $tmpContactField);
      $fields = array_merge($fields, $tmpFields);
      $fields = array_merge($fields, CRM_Core_BAO_CustomField::getFieldsForImport('Membership'));
      Civi::cache('fields')->set('membership_importable_fields' . $contactType, $fields);
    }
    return $fields;
  }

  /**
   * Get the metadata field for which importable fields does not key the actual field name.
   *
   * @return string[]
   */
  protected function getOddlyMappedMetadataFields(): array {
    $uniqueNames = ['membership_id', 'membership_contact_id', 'membership_start_date', 'membership_join_date', 'membership_end_date', 'membership_source', 'member_is_override', 'member_is_test', 'member_is_pay_later', 'member_campaign_id'];
    $fields = [];
    foreach ($uniqueNames as $name) {
      $fields[$this->importableFieldsMetadata[$name]['name']] = $name;
    }
    // Include the parent fields as they could be present if required for matching ...in theory.
    return array_merge($fields, parent::getOddlyMappedMetadataFields());
  }

}
