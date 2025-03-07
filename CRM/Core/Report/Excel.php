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
 * Class CRM_Core_Report_Excel
 */
class CRM_Core_Report_Excel {

  /**
   * Code copied from phpMyAdmin (v2.6.1-pl3)
   * File: PHPMYADMIN/libraries/export/csv.php
   * Function: PMA_exportData
   *
   * Outputs a result set with a given header
   * in the string buffer result
   *
   * @param array $header
   *   column headers.
   * @param array $rows
   *   result set rows.
   * @param string $titleHeader
   * @param bool $print
   *   Should the output be printed.
   * @param bool $outputHeader
   *
   * @return mixed
   *   empty if output is printed, else output
   *
   */
  public static function makeCSVTable($header, $rows, $titleHeader = NULL, $print = TRUE, $outputHeader = TRUE) {
    if ($titleHeader) {
      echo $titleHeader;
    }

    $result = '';

    $config = CRM_Core_Config::singleton();
    $seperator = $config->fieldSeparator;
    $enclosed = '"';
    $escaped = $enclosed;
    $add_character = "\015\012";

    $schema_insert = '';
    foreach ($header as $field) {
      if ($enclosed == '') {
        $schema_insert .= stripslashes($field);
      }
      else {
        $schema_insert .= $enclosed . str_replace($enclosed, $escaped . $enclosed, stripslashes($field)) . $enclosed;
      }
      $schema_insert .= $seperator;
    }
    // end while

    if ($outputHeader) {
      // need to add PMA_exportOutputHandler functionality out here, rather than
      // doing it the moronic way of assembling a buffer
      $out = trim(substr($schema_insert, 0, -1)) . $add_character;
      if ($print) {
        echo $out;
      }
      else {
        $result .= $out;
      }
    }

    $fields_cnt = count($header);
    foreach ($rows as $row) {
      $schema_insert = '';
      $colNo = 0;

      foreach ($row as $j => $value) {
        if (!isset($value) || is_null($value)) {
          $schema_insert .= '';
        }
        elseif ($value == '0' || $value != '') {
          // loic1 : always enclose fields
          //$value = ereg_replace("\015(\012)?", "\012", $value);
          $value = preg_replace("/\015(\012)?/", "\012", $value);
          if ($enclosed == '') {
            $schema_insert .= $value;
          }
          else {
            if ((substr($value, 0, 1) == CRM_Core_DAO::VALUE_SEPARATOR) &&
              (substr($value, -1, 1) == CRM_Core_DAO::VALUE_SEPARATOR)
            ) {

              $strArray = explode(CRM_Core_DAO::VALUE_SEPARATOR, $value);

              foreach ($strArray as $key => $val) {
                if (trim($val) == '') {
                  unset($strArray[$key]);
                }
              }

              $str = implode($seperator, $strArray);
              $value = &$str;
            }

            $schema_insert .= $enclosed . str_replace($enclosed, $escaped . $enclosed, $value) . $enclosed;
          }
        }
        else {
          $schema_insert .= '';
        }

        if ($colNo < $fields_cnt - 1) {
          $schema_insert .= $seperator;
        }
        $colNo++;
      }
      // end for

      $out = $schema_insert . $add_character;
      if ($print) {
        echo $out;
      }
      else {
        $result .= $out;
      }
    }

    if ($print) {
      return;
    }
    else {
      return $result;
    }
  }

  /**
   * @param string $fileName
   * @param $header
   * @param $rows
   * @param null $titleHeader
   * @param bool $outputHeader
   */
  public function writeHTMLFile($fileName, $header, $rows, $titleHeader = NULL, $outputHeader = TRUE) {
    if ($outputHeader) {
      CRM_Utils_System::download(CRM_Utils_String::munge($fileName),
        'application/vnd.ms-excel',
        CRM_Core_DAO::$_nullObject,
        'xls',
        FALSE
      );
    }

    echo "<table><thead><tr>";
    foreach ($header as $field) {
      echo "<th>$field</th>";
    }
    echo "</tr></thead><tbody>";

    foreach ($rows as $row) {
      $schema_insert = '';
      $colNo = 0;
      echo "<tr>";
      foreach ($row as $j => $value) {
        echo "<td>" . htmlentities($value, ENT_COMPAT, 'UTF-8') . "</td>";
      }
      echo "</tr>";
    }

    echo "</tbody></table>";
  }

  /**
   * Write a CSV file to the browser output.
   *
   * @param string $fileName
   *   The name of the file that will be downloaded (this is sent to the browser).
   * @param array $header
   *   An array of the headers.
   * @param array $rows
   *   An array of arrays of the table contents.
   * @param string $titleHeader
   *   If set this will be the title in the CSV.
   * @param bool $outputHeader
   *   Should we output the header row.
   * @param bool $saveFile
   *   -.
   *
   * @return void
   */
  public static function writeCSVFile($fileName, $header, $rows, $titleHeader = NULL, $outputHeader = TRUE, $saveFile = NULL) {
    if ($outputHeader && !$saveFile) {
      CRM_Utils_System::download(CRM_Utils_String::munge($fileName),
        'text/x-csv',
        CRM_Core_DAO::$_nullObject,
        'csv',
        FALSE
      );
    }

    if (!empty($rows)) {
      $print = TRUE;
      if ($saveFile) {
        $print = FALSE;
      }
      return self::makeCSVTable($header, $rows, $titleHeader, $print, $outputHeader);
    }
  }

}
