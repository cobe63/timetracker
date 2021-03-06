<?php
// +----------------------------------------------------------------------+
// | Anuko Time Tracker
// +----------------------------------------------------------------------+
// | Copyright (c) Anuko International Ltd. (https://www.anuko.com)
// +----------------------------------------------------------------------+
// | LIBERAL FREEWARE LICENSE: This source code document may be used
// | by anyone for any purpose, and freely redistributed alone or in
// | combination with other software, provided that the license is obeyed.
// |
// | There are only two ways to violate the license:
// |
// | 1. To redistribute this code in source form, with the copyright
// |    notice or license removed or altered. (Distributing in compiled
// |    forms without embedded copyright notices is permitted).
// |
// | 2. To redistribute modified versions of this code in *any* form
// |    that bears insufficient indications that the modifications are
// |    not the work of the original author(s).
// |
// | This license applies to this document only, not any other software
// | that it may be combined with.
// |
// +----------------------------------------------------------------------+
// | Contributors:
// | https://www.anuko.com/time_tracker/credits.htm
// +----------------------------------------------------------------------+

import('ttUserHelper');
import('ttRoleHelper');
import('ttTaskHelper');
import('ttProjectHelper');
import('ttClientHelper');
import('ttInvoiceHelper');
import('ttTimeHelper');
import('ttCustomFieldHelper');
import('ttExpenseHelper');
import('ttFavReportHelper');

// ttOrgImportHelper class is used to import organization data from an XML file
// prepared by ttOrgExportHelper and consisting of nested groups with their info.
class ttOrgImportHelper {
  var $errors               = null; // Errors go here. Set in constructor by reference.
  var $schema_version       = null; // Database schema version from XML file we import from.
  var $conflicting_logins   = null; // A comma-separated list of logins we cannot import.
  var $canImport      = true;    // False if we cannot import data due to a conflict such as login collision.
  var $firstPass      = true;    // True during first pass through the file.
  var $org_id         = null;    // Organization id (same as top group_id).
  var $current_group_id        = null; // Current group id during parsing.
  var $current_parent_group_id = null; // Current parent group id during parsing.
  var $top_role_id    = 0;       // Top role id.

  // Entity maps for current group. They map XML ids with database ids.
  var $currentGroupRoleMap    = array();
  var $currentGroupTaskMap    = array();
  var $currentGroupProjectMap = array();
  var $currentGroupClientMap  = array();
  var $currentGroupUserMap    = array();
  var $currentGroupInvoiceMap = array();
  var $currentGroupLogMap     = array();
  var $currentGroupCustomFieldMap = array();
  var $currentGroupCustomFieldOptionMap = array();

  // Constructor.
  function __construct(&$errors) {
    $this->errors = &$errors;
    $this->top_role_id = ttRoleHelper::getRoleByRank(512, 0);
  }

  // startElement - callback handler for opening tags in XML.
  function startElement($parser, $name, $attrs) {
    global $i18n;

    // First pass through the file determines if we can import data.
    // We require 2 things:
    //   1) Database schema version must be set. This ensures we have a compatible file.
    //   2) No login coillisions are allowed.
    if ($this->firstPass) {
      if ($name == 'ORG' && $this->canImport) {
         if ($attrs['SCHEMA'] == null) {
           // We need (database) schema attribute to be available for import to work.
           // Old Time Tracker export files don't have this.
           // Current import code does not work with old format because we had to
           // restructure data in export files for subgroup support.
           $this->canImport = false;
           $this->errors->add($i18n->get('error.format'));
           return;
         }
      }

      // In first pass we check user logins for potential collisions with existing.
      if ($name == 'USER' && $this->canImport) {
        $login = $attrs['LOGIN'];
        if ('' != $attrs['STATUS'] && ttUserHelper::getUserByLogin($login)) {
          // We have a login collision. Append colliding login to a list of things we cannot import.
          $this->conflicting_logins .= ($this->conflicting_logins ? ", $login" : $login);
          // The above is printed in error message with all found colliding logins.
        }
      }
    }

    // Second pass processing. We import data here, one tag at a time.
    if (!$this->firstPass && $this->canImport && $this->errors->no()) {
      $mdb2 = getConnection();

      // We are in second pass and can import data.
      if ($name == 'GROUP') {
        // Create a new group.
        $this->current_group_id = $this->createGroup(array(
          'parent_id' => $this->current_parent_group_id,
          'org_id' => $this->org_id,
          'name' => $attrs['NAME'],
          'currency' => $attrs['CURRENCY'],
          'decimal_mark' => $attrs['DECIMAL_MARK'],
          'lang' => $attrs['LANG'],
          'date_format' => $attrs['DATE_FORMAT'],
          'time_format' => $attrs['TIME_FORMAT'],
          'week_start' => $attrs['WEEK_START'],
          'tracking_mode' => $attrs['TRACKING_MODE'],
          'project_required' => $attrs['PROJECT_REQUIRED'],
          'task_required' => $attrs['TASK_REQUIRED'],
          'record_type' => $attrs['RECORD_TYPE'],
          'bcc_email' => $attrs['BCC_EMAIL'],
          'allow_ip' => $attrs['ALLOW_IP'],
          'password_complexity' => $attrs['PASSWORD_COMPLEXITY'],
          'plugins' => $attrs['PLUGINS'],
          'lock_spec' => $attrs['LOCK_SPEC'],
          'workday_minutes' => $attrs['WORKDAY_MINUTES'],
          'custom_logo' => $attrs['CUSTOM_LOGO'],
          'config' => $attrs['CONFIG']));

        // Special handling for top group.
        if (!$this->org_id && $this->current_group_id) {
          $this->org_id = $this->current_group_id;
          $sql = "update tt_groups set org_id = $this->current_group_id where org_id is NULL and id = $this->current_group_id";
          $affected = $mdb2->exec($sql);
        }
        // Set parent group to create subgroups with this group as parent at next entry here.
        $this->current_parent_group_id = $this->current_group_id;
        return;
      }

      if ($name == 'ROLES') {
        // If we get here, we have to recycle $currentGroupRoleMap.
        unset($this->currentGroupRoleMap);
        $this->currentGroupRoleMap = array();
        // Role map is reconstructed after processing <role> elements in XML. See below.
        return;
      }

      if ($name == 'ROLE') {
        // We get here when processing <role> tags for the current group.
        $role_id = ttRoleHelper::insert(array(
          'group_id' => $this->current_group_id,
          'org_id' => $this->org_id,
          'name' => $attrs['NAME'],
          'description' => $attrs['DESCRIPTION'],
          'rank' => $attrs['RANK'],
          'rights' => $attrs['RIGHTS'],
          'status' => $attrs['STATUS']));
        if ($role_id) {
          // Add a mapping.
          $this->currentGroupRoleMap[$attrs['ID']] = $role_id;
        } else $this->errors->add($i18n->get('error.db'));
        return;
      }

      if ($name == 'TASKS') {
        // If we get here, we have to recycle $currentGroupTaskMap.
        unset($this->currentGroupTaskMap);
        $this->currentGroupTaskMap = array();
        // Task map is reconstructed after processing <task> elements in XML. See below.
        return;
      }

      if ($name == 'TASK') {
        // We get here when processing <task> tags for the current group.
        $task_id = ttTaskHelper::insert(array(
          'group_id' => $this->current_group_id,
          'org_id' => $this->org_id,
          'name' => $attrs['NAME'],
          'description' => $attrs['DESCRIPTION'],
          'status' => $attrs['STATUS']));
        if ($task_id) {
          // Add a mapping.
          $this->currentGroupTaskMap[$attrs['ID']] = $task_id;
        } else $this->errors->add($i18n->get('error.db'));
        return;
      }

      if ($name == 'PROJECTS') {
        // If we get here, we have to recycle $currentGroupProjectMap.
        unset($this->currentGroupProjectMap);
        $this->currentGroupProjectMap = array();
        // Project map is reconstructed after processing <project> elements in XML. See below.
        return;
      }

      if ($name == 'PROJECT') {
        // We get here when processing <project> tags for the current group.

        // Prepare a list of task ids.
        $tasks = explode(',', $attrs['TASKS']);
        foreach ($tasks as $id)
          $mapped_tasks[] = $this->currentGroupTaskMap[$id];

        $project_id = ttProjectHelper::insert(array(
          'group_id' => $this->current_group_id,
          'org_id' => $this->org_id,
          'name' => $attrs['NAME'],
          'description' => $attrs['DESCRIPTION'],
          'tasks' => $mapped_tasks,
          'status' => $attrs['STATUS']));
        if ($project_id) {
          // Add a mapping.
          $this->currentGroupProjectMap[$attrs['ID']] = $project_id;
        } else $this->errors->add($i18n->get('error.db'));
        return;
      }

      if ($name == 'CLIENTS') {
        // If we get here, we have to recycle $currentGroupClientMap.
        unset($this->currentGroupClientMap);
        $this->currentGroupClientMap = array();
        // Client map is reconstructed after processing <client> elements in XML. See below.
        return;
      }

      if ($name == 'CLIENT') {
        // We get here when processing <client> tags for the current group.

        // Prepare a list of project ids.
        if ($attrs['PROJECTS']) {
          $projects = explode(',', $attrs['PROJECTS']);
          foreach ($projects as $id)
            $mapped_projects[] = $this->currentGroupProjectMap[$id];
        }

        $client_id = ttClientHelper::insert(array(
          'group_id' => $this->current_group_id,
          'org_id' => $this->org_id,
          'name' => $attrs['NAME'],
          'address' => $attrs['ADDRESS'],
          'tax' => $attrs['TAX'],
          'projects' => $mapped_projects,
          'status' => $attrs['STATUS']));
        if ($client_id) {
          // Add a mapping.
          $this->currentGroupClientMap[$attrs['ID']] = $client_id;
        } else $this->errors->add($i18n->get('error.db'));
        return;
      }

      if ($name == 'USERS') {
        // If we get here, we have to recycle $currentGroupUserMap.
        unset($this->currentGroupUserMap);
        $this->currentGroupUserMap = array();
        // User map is reconstructed after processing <user> elements in XML. See below.
        return;
      }

      if ($name == 'USER') {
        // We get here when processing <user> tags for the current group.

        $role_id = $attrs['ROLE_ID'] === '0' ? $this->top_role_id :  $this->currentGroupRoleMap[$attrs['ROLE_ID']]; // 0 (not null) means top manager role.

        $user_id = ttUserHelper::insert(array(
          'group_id' => $this->current_group_id,
          'org_id' => $this->org_id,
          'role_id' => $role_id,
          'client_id' => $this->currentGroupClientMap[$attrs['CLIENT_ID']],
          'name' => $attrs['NAME'],
          'login' => $attrs['LOGIN'],
          'password' => $attrs['PASSWORD'],
          'rate' => $attrs['RATE'],
          'email' => $attrs['EMAIL'],
          'status' => $attrs['STATUS']), false);
        if ($user_id) {
          // Add a mapping.
          $this->currentGroupUserMap[$attrs['ID']] = $user_id;
        } else $this->errors->add($i18n->get('error.db'));
        return;
      }

      if ($name == 'USER_PROJECT_BIND') {
        if (!ttUserHelper::insertBind(array(
          'user_id' => $this->currentGroupUserMap[$attrs['USER_ID']],
          'project_id' => $this->currentGroupProjectMap[$attrs['PROJECT_ID']],
          'group_id' => $this->current_group_id,
          'org_id' => $this->org_id,
          'rate' => $attrs['RATE'],
          'status' => $attrs['STATUS']))) {
          $this->errors->add($i18n->get('error.db'));
        }
        return;
      }

      if ($name == 'INVOICES') {
        // If we get here, we have to recycle $currentGroupInvoiceMap.
        unset($this->currentGroupInvoiceMap);
        $this->currentGroupInvoiceMap = array();
        // Invoice map is reconstructed after processing <invoice> elements in XML. See below.
        return;
      }

      if ($name == 'INVOICE') {
        // We get here when processing <invoice> tags for the current group.
        $invoice_id = ttInvoiceHelper::insert(array(
          'group_id' => $this->current_group_id,
          'org_id' => $this->org_id,
          'name' => $attrs['NAME'],
          'date' => $attrs['DATE'],
          'client_id' => $this->currentGroupClientMap[$attrs['CLIENT_ID']],
          'status' => $attrs['STATUS']));
        if ($invoice_id) {
          // Add a mapping.
          $this->currentGroupInvoiceMap[$attrs['ID']] = $invoice_id;
        } else $this->errors->add($i18n->get('error.db'));
        return;
      }

      if ($name == 'LOG') {
        // If we get here, we have to recycle $currentGroupLogMap.
        unset($this->currentGroupLogMap);
        $this->currentGroupLogMap = array();
        // Log map is reconstructed after processing <log_item> elements in XML. See below.
        return;
      }

      if ($name == 'LOG_ITEM') {
        // We get here when processing <log_item> tags for the current group.
        $log_item_id = ttTimeHelper::insert(array(
          'user_id' => $this->currentGroupUserMap[$attrs['USER_ID']],
          'group_id' => $this->current_group_id,
          'org_id' => $this->org_id,
          'date' => $attrs['DATE'],
          'start' => $attrs['START'],
          'finish' => $attrs['FINISH'],
          'duration' => $attrs['DURATION'],
          'client' => $this->currentGroupClientMap[$attrs['CLIENT_ID']],
          'project' => $this->currentGroupProjectMap[$attrs['PROJECT_ID']],
          'task' => $this->currentGroupTaskMap[$attrs['TASK_ID']],
          'invoice' => $this->currentGroupInvoiceMap[$attrs['INVOICE_ID']],
          'note' => (isset($attrs['COMMENT']) ? $attrs['COMMENT'] : ''),
          'billable' => $attrs['BILLABLE'],
          'paid' => $attrs['PAID'],
          'status' => $attrs['STATUS']));
        if ($log_item_id) {
          // Add a mapping.
          $this->currentGroupLogMap[$attrs['ID']] = $log_item_id;
        } else $this->errors->add($i18n->get('error.db'));
        return;
      }

      if ($name == 'CUSTOM_FIELDS') {
        // If we get here, we have to recycle $currentGroupCustomFieldMap.
        unset($this->currentGroupCustomFieldMap);
        $this->currentGroupCustomFieldMap = array();
        // Custom field map is reconstructed after processing <custom_field> elements in XML. See below.
        return;
      }

      if ($name == 'CUSTOM_FIELD') {
        // We get here when processing <custom_field> tags for the current group.
        $custom_field_id = ttCustomFieldHelper::insertField(array(
          'group_id' => $this->current_group_id,
          // 'org_id' => $this->org_id, TODO: add this when org_id field is added to the table.
          'type' => $attrs['TYPE'],
          'label' => $attrs['LABEL'],
          'required' => $attrs['REQUIRED'],
          'status' => $attrs['STATUS']));
        if ($custom_field_id) {
          // Add a mapping.
          $this->currentGroupCustomFieldMap[$attrs['ID']] = $custom_field_id;
        } else $this->errors->add($i18n->get('error.db'));
        return;
      }

      if ($name == 'CUSTOM_FIELD_OPTIONS') {
        // If we get here, we have to recycle $currentGroupCustomFieldOptionMap.
        unset($this->currentGroupCustomFieldOptionMap);
        $this->currentGroupCustomFieldOptionMap = array();
        // Custom field option map is reconstructed after processing <custom_field_option> elements in XML. See below.
        return;
      }

      if ($name == 'CUSTOM_FIELD_OPTION') {
        // We get here when processing <custom_field_option> tags for the current group.
        $custom_field_option_id = ttCustomFieldHelper::insertOption(array(
          // 'group_id' => $this->current_group_id, TODO: add this when group_id field is added to the table.
          // 'org_id' => $this->org_id, TODO: add this when org_id field is added to the table.
          'field_id' => $this->currentGroupCustomFieldMap[$attrs['FIELD_ID']],
          'value' => $attrs['VALUE']));
        if ($custom_field_option_id) {
          // Add a mapping.
          $this->currentGroupCustomFieldOptionMap[$attrs['ID']] = $custom_field_option_id;
        } else $this->errors->add($i18n->get('error.db'));
        return;
      }

      if ($name == 'CUSTOM_FIELD_LOG_ENTRY') {
        // We get here when processing <custom_field_log_entry> tags for the current group.
        if (!ttCustomFieldHelper::insertLogEntry(array(
          // 'group_id' => $this->current_group_id, TODO: add this when group_id field is added to the table.
          // 'org_id' => $this->org_id, TODO: add this when org_id field is added to the table.
          'log_id' => $this->currentGroupLogMap[$attrs['LOG_ID']],
          'field_id' => $this->currentGroupCustomFieldMap[$attrs['FIELD_ID']],
          'option_id' => $this->currentGroupCustomFieldOptionMap[$attrs['OPTION_ID']],
          'value' => $attrs['VALUE'],
          'status' => $attrs['STATUS']))) {
          $this->errors->add($i18n->get('error.db'));
        }
        return;
      }

      if ($name == 'EXPENSE_ITEM') {
        // We get here when processing <expense_item> tags for the current group.
        $expense_item_id = ttExpenseHelper::insert(array(
          'date' => $attrs['DATE'],
          'user_id' => $this->currentGroupUserMap[$attrs['USER_ID']],
          'group_id' => $this->current_group_id,
          // 'org_id' => $this->org_id, TODO: add this when org_id field is added to the table.
          'client_id' => $this->currentGroupClientMap[$attrs['CLIENT_ID']],
          'project_id' => $this->currentGroupProjectMap[$attrs['PROJECT_ID']],
          'name' => $attrs['NAME'],
          'cost' => $attrs['COST'],
          'invoice_id' => $this->currentGroupInvoiceMap[$attrs['INVOICE_ID']],
          'paid' => $attrs['PAID'],
          'status' => $attrs['STATUS']));
        if (!$expense_item_id) $this->errors->add($i18n->get('error.db'));
        return;
      }

      if ($name == 'PREDEFINED_EXPENSE') {
        if (!$this->insertPredefinedExpense(array(
          'group_id' => $this->current_group_id,
          'org_id' => $this->org_id,
          'name' => $attrs['NAME'],
          'cost' => $attrs['COST']))) {
          $this->errors->add($i18n->get('error.db'));
        }
        return;
      }

      if ($name == 'MONTHLY_QUOTA') {
        if (!$this->insertMonthlyQuota(array(
          'group_id' => $this->current_group_id,
          'org_id' => $this->org_id,
          'year' => $attrs['YEAR'],
          'month' => $attrs['MONTH'],
          'minutes' => $attrs['MINUTES']))) {
          $this->errors->add($i18n->get('error.db'));
        }
        return;
      }

      if ($name == 'FAV_REPORT') {
        $user_list = '';
        if (strlen($attrs['USERS']) > 0) {
          $arr = explode(',', $attrs['USERS']);
          foreach ($arr as $v)
            $user_list .= (strlen($user_list) == 0 ? '' : ',').$this->currentGroupUserMap[$v];
        }
        $fav_report_id = ttFavReportHelper::insertReport(array(
          'name' => $attrs['NAME'],
          'user_id' => $this->currentGroupUserMap[$attrs['USER_ID']],
          'client' => $this->currentGroupClientMap[$attrs['CLIENT_ID']],
          'option' => $this->currentGroupCustomFieldOptionMap[$attrs['CF_1_OPTION_ID']],
          'project' => $this->currentGroupProjectMap[$attrs['PROJECT_ID']],
          'task' => $this->currentGroupTaskMap[$attrs['TASK_ID']],
          'billable' => $attrs['BILLABLE'],
          'users' => $user_list,
          'period' => $attrs['PERIOD'],
          'from' => $attrs['PERIOD_START'],
          'to' => $attrs['PERIOD_END'],
          'chclient' => (int) $attrs['SHOW_CLIENT'],
          'chinvoice' => (int) $attrs['SHOW_INVOICE'],
          'chpaid' => (int) $attrs['SHOW_PAID'],
          'chip' => (int) $attrs['SHOW_IP'],
          'chproject' => (int) $attrs['SHOW_PROJECT'],
          'chstart' => (int) $attrs['SHOW_START'],
          'chduration' => (int) $attrs['SHOW_DURATION'],
          'chcost' => (int) $attrs['SHOW_COST'],
          'chtask' => (int) $attrs['SHOW_TASK'],
          'chfinish' => (int) $attrs['SHOW_END'],
          'chnote' => (int) $attrs['SHOW_NOTE'],
          'chcf_1' => (int) $attrs['SHOW_CUSTOM_FIELD_1'],
          'chunits' => (int) $attrs['SHOW_WORK_UNITS'],
          'group_by1' => $attrs['GROUP_BY1'],
          'group_by2' => $attrs['GROUP_BY2'],
          'group_by3' => $attrs['GROUP_BY3'],
          'chtotalsonly' => (int) $attrs['SHOW_TOTALS_ONLY']));
         if (!$fav_report_id) $this->errors->add($i18n->get('error.db'));
         return;
      }
    }
  }

  // importXml - uncompresses the file, reads and parses its content. During parsing,
  // startElement, endElement, and dataElement functions are called as many times as necessary.
  // Actual import occurs in the endElement handler.
  function importXml() {
    global $i18n;

    // Do we have a compressed file?
    $compressed = false;
    $file_ext = substr($_FILES['xmlfile']['name'], strrpos($_FILES['xmlfile']['name'], '.') + 1);
    if (in_array($file_ext, array('bz','tbz','bz2','tbz2'))) {
      $compressed = true;
    }

    // Create a temporary file.
    $dirName = dirname(TEMPLATE_DIR . '_c/.');
    $filename = tempnam($dirName, 'import_');

    // If the file is compressed - uncompress it.
    if ($compressed) {
      if (!$this->uncompress($_FILES['xmlfile']['tmp_name'], $filename)) {
        $this->errors->add($i18n->get('error.sys'));
        return;
      }
      unlink($_FILES['xmlfile']['tmp_name']);
    } else {
      if (!move_uploaded_file($_FILES['xmlfile']['tmp_name'], $filename)) {
        $this->errors->add($i18n->get('error.upload'));
        return;
      }
    }

    // Initialize XML parser.
    $parser = xml_parser_create();
    xml_set_object($parser, $this);
    xml_set_element_handler($parser, 'startElement', false);

    // We need to parse the file 2 times:
    //   1) First pass: determine if import is possible.
    //   2) Second pass: import data, one tag at a time.

    // Read and parse the content of the file. During parsing, startElement is called back for each tag.
    $file = fopen($filename, 'r');
    while (($data = fread($file, 4096)) && $this->errors->no()) {
      if (!xml_parse($parser, $data, feof($file))) {
        $this->errors->add(sprintf($i18n->get('error.xml'),
          xml_get_current_line_number($parser),
          xml_error_string(xml_get_error_code($parser))));
      }
    }
    if ($this->conflicting_logins) {
      $this->canImport = false;
      $this->errors->add($i18n->get('error.user_exists'));
      $this->errors->add(sprintf($i18n->get('error.cannot_import'), $this->conflicting_logins));
    }

    $this->firstPass = false; // We are done with 1st pass.
    xml_parser_free($parser);
    if ($file) fclose($file);
    if (!$this->canImport) {
      unlink($filename);
      return;
    }
    if ($this->errors->yes()) return; // Exit if we have errors.

    // Now we can do a second pass, where real work is done.
    $parser = xml_parser_create();
    xml_set_object($parser, $this);
    xml_set_element_handler($parser, 'startElement', false);

    // Read and parse the content of the file. During parsing, startElement is called back for each tag.
    $file = fopen($filename, 'r');
    while (($data = fread($file, 4096)) && $this->errors->no()) {
      if (!xml_parse($parser, $data, feof($file))) {
        $this->errors->add(sprintf($i18n->get('error.xml'),
          xml_get_current_line_number($parser),
          xml_error_string(xml_get_error_code($parser))));
      }
    }
    xml_parser_free($parser);
    if ($file) fclose($file);
    unlink($filename);
  }

  // uncompress - uncompresses the content of the $in file into the $out file.
  function uncompress($in, $out) {
    // Do we have the uncompress function?
    if (!function_exists('bzopen'))
      return false;

    // Initial checks of file names and permissions.
    if (!file_exists($in) || !is_readable ($in))
      return false;
    if ((!file_exists($out) && !is_writable(dirname($out))) || (file_exists($out) && !is_writable($out)))
      return false;

    if (!$out_file = fopen($out, 'wb'))
      return false;
    if (!$in_file = bzopen ($in, 'r'))
      return false;

    while (!feof($in_file)) {
      $buffer = bzread($in_file, 4096);
      fwrite($out_file, $buffer, 4096);
    }
    bzclose($in_file);
    fclose ($out_file);
    return true;
  }

  // createGroup function creates a new group.
  private function createGroup($fields) {
    global $user;
    global $i18n;
    $mdb2 = getConnection();

    $columns = '(parent_id, org_id, name, currency, decimal_mark, lang, date_format, time_format'.
      ', week_start, tracking_mode, project_required, task_required, record_type, bcc_email'.
      ', allow_ip, password_complexity, plugins, lock_spec'.
      ', workday_minutes, config, created, created_ip, created_by)';

    $values = ' values (';
    $values .= $mdb2->quote($fields['parent_id']);
    $values .= ', '.$mdb2->quote($fields['org_id']);
    $values .= ', '.$mdb2->quote(trim($fields['name']));
    $values .= ', '.$mdb2->quote(trim($fields['currency']));
    $values .= ', '.$mdb2->quote($fields['decimal_mark']);
    $values .= ', '.$mdb2->quote($fields['lang']);
    $values .= ', '.$mdb2->quote($fields['date_format']);
    $values .= ', '.$mdb2->quote($fields['time_format']);
    $values .= ', '.(int)$fields['week_start'];
    $values .= ', '.(int)$fields['tracking_mode'];
    $values .= ', '.(int)$fields['project_required'];
    $values .= ', '.(int)$fields['task_required'];
    $values .= ', '.(int)$fields['record_type'];
    $values .= ', '.$mdb2->quote($fields['bcc_email']);
    $values .= ', '.$mdb2->quote($fields['allow_ip']);
    $values .= ', '.$mdb2->quote($fields['password_complexity']);
    $values .= ', '.$mdb2->quote($fields['plugins']);
    $values .= ', '.$mdb2->quote($fields['lock_spec']);
    $values .= ', '.(int)$fields['workday_minutes'];
    $values .= ', '.$mdb2->quote($fields['config']);
    $values .= ', now(), '.$mdb2->quote($_SERVER['REMOTE_ADDR']).', '.$mdb2->quote($user->id);
    $values .= ')';

    $sql = 'insert into tt_groups '.$columns.$values;
    $affected = $mdb2->exec($sql);
    if (is_a($affected, 'PEAR_Error')) {
      $this->errors->add($i18n->get('error.db'));
      return false;
    }

    $group_id = $mdb2->lastInsertID('tt_groups', 'id');
    return $group_id;
  }

  // insertMonthlyQuota - a helper function to insert a monthly quota.
  private function insertMonthlyQuota($fields) {
    $mdb2 = getConnection();
    $group_id = (int) $fields['group_id'];
    $org_id = (int) $fields['org_id'];
    $year = (int) $fields['year'];
    $month = (int) $fields['month'];
    $minutes = (int) $fields['minutes'];

    $sql = "INSERT INTO tt_monthly_quotas (group_id, org_id, year, month, minutes)".
      " values ($group_id, $org_id, $year, $month, $minutes)";
    $affected = $mdb2->exec($sql);
    return (!is_a($affected, 'PEAR_Error'));
  }

  // insertPredefinedExpense - a helper function to insert a predefined expense.
  private function insertPredefinedExpense($fields) {
    $mdb2 = getConnection();
    $group_id = (int) $fields['group_id'];
    $org_id = (int) $fields['org_id'];
    $name = $mdb2->quote($fields['name']);
    $cost = $mdb2->quote($fields['cost']);

    $sql = "INSERT INTO tt_predefined_expenses (group_id, org_id, name, cost)".
      " values ($group_id, $org_id, $name, $cost)";
    $affected = $mdb2->exec($sql);
    return (!is_a($affected, 'PEAR_Error'));
  }
}
