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

namespace Civi\Api4\Query;

use Civi\API\SelectQuery;
use Civi\Api4\Event\Events;
use Civi\Api4\Event\PostSelectQueryEvent;
use Civi\Api4\Service\Schema\Joinable\CustomGroupJoinable;
use Civi\Api4\Service\Schema\Joinable\Joinable;
use Civi\Api4\Utils\FormattingUtil;
use Civi\Api4\Utils\CoreUtil;
use CRM_Core_DAO_AllCoreTables as AllCoreTables;
use CRM_Utils_Array as UtilsArray;

/**
 * A query `node` may be in one of three formats:
 *
 * * leaf: [$fieldName, $operator, $criteria]
 * * negated: ['NOT', $node]
 * * branch: ['OR|NOT', [$node, $node, ...]]
 *
 * Leaf operators are one of:
 *
 * * '=', '<=', '>=', '>', '<', 'LIKE', "<>", "!=",
 * * "NOT LIKE", 'IN', 'NOT IN', 'BETWEEN', 'NOT BETWEEN',
 * * 'IS NOT NULL', or 'IS NULL'.
 */
class Api4SelectQuery extends SelectQuery {

  /**
   * @var int
   */
  protected $apiVersion = 4;

  /**
   * @var array
   *   Maps select fields to [<table_alias>, <column_alias>]
   */
  protected $fkSelectAliases = [];

  /**
   * @var \Civi\Api4\Service\Schema\Joinable\Joinable[]
   *   The joinable tables that have been joined so far
   */
  protected $joinedTables = [];

  /**
   * @param string $entity
   * @param bool $checkPermissions
   * @param array $fields
   */
  public function __construct($entity, $checkPermissions, $fields) {
    require_once 'api/v3/utils.php';
    $this->entity = $entity;
    $this->checkPermissions = $checkPermissions;

    $baoName = CoreUtil::getBAOFromApiName($entity);
    $bao = new $baoName();

    $this->entityFieldNames = _civicrm_api3_field_names(_civicrm_api3_build_fields_array($bao));
    $this->apiFieldSpec = (array) $fields;

    \CRM_Utils_SQL_Select::from($this->getTableName($baoName) . ' ' . self::MAIN_TABLE_ALIAS);

    // Add ACLs first to avoid redundant subclauses
    $this->query->where($this->getAclClause(self::MAIN_TABLE_ALIAS, $baoName));
  }

  /**
   * Why walk when you can
   *
   * @return array|int
   */
  public function run() {
    $this->addJoins();
    $this->buildSelectFields();
    $this->buildWhereClause();

    // Select
    if (in_array('row_count', $this->select)) {
      $this->query->select("count(*) as c");
    }
    else {
      foreach ($this->selectFields as $column => $alias) {
        $this->query->select("$column as `$alias`");
      }
      // Order by
      $this->buildOrderBy();
    }

    // Limit
    if (!empty($this->limit) || !empty($this->offset)) {
      $this->query->limit($this->limit, $this->offset);
    }

    $results = [];
    $sql = $this->query->toSQL();
    $query = \CRM_Core_DAO::executeQuery($sql);

    while ($query->fetch()) {
      if (in_array('row_count', $this->select)) {
        $results[]['row_count'] = (int) $query->c;
        break;
      }
      $results[$query->id] = [];
      foreach ($this->selectFields as $column => $alias) {
        $returnName = $alias;
        $alias = str_replace('.', '_', $alias);
        $results[$query->id][$returnName] = property_exists($query, $alias) ? $query->$alias : NULL;
      };
    }
    $event = new PostSelectQueryEvent($results, $this);
    \Civi::dispatcher()->dispatch(Events::POST_SELECT_QUERY, $event);

    return $event->getResults();
  }

  /**
   * Gets all FK fields and does the required joins
   */
  protected function addJoins() {
    $allFields = array_merge($this->select, array_keys($this->orderBy));
    $recurse = function($clauses) use (&$allFields, &$recurse) {
      foreach ($clauses as $clause) {
        if ($clause[0] === 'NOT' && is_string($clause[1][0])) {
          $recurse($clause[1][1]);
        }
        elseif (in_array($clause[0], ['AND', 'OR', 'NOT'])) {
          $recurse($clause[1]);
        }
        elseif (is_array($clause[0])) {
          array_walk($clause, $recurse);
        }
        else {
          $allFields[] = $clause[0];
        }
      }
    };
    $recurse($this->where);
    $dotFields = array_unique(array_filter($allFields, function ($field) {
      return strpos($field, '.') !== FALSE;
    }));

    foreach ($dotFields as $dotField) {
      $this->joinFK($dotField);
    }
  }

  /**
   * Populate $this->selectFields
   *
   * @throws \Civi\API\Exception\UnauthorizedException
   */
  protected function buildSelectFields() {
    $return_all_fields = (empty($this->select) || !is_array($this->select));
    $return = $return_all_fields ? $this->entityFieldNames : $this->select;
    if ($return_all_fields || in_array('custom', $this->select)) {
      foreach (array_keys($this->apiFieldSpec) as $fieldName) {
        if (strpos($fieldName, 'custom_') === 0) {
          $return[] = $fieldName;
        }
      }
    }

    // Always select the ID if the table has one.
    if (array_key_exists('id', $this->apiFieldSpec) || strstr($this->entity, 'Custom_')) {
      $this->selectFields[self::MAIN_TABLE_ALIAS . ".id"] = "id";
    }

    // core return fields
    foreach ($return as $fieldName) {
      $field = $this->getField($fieldName);
      if (strpos($fieldName, '.') && !empty($this->fkSelectAliases[$fieldName]) && !array_filter($this->getPathJoinTypes($fieldName))) {
        $this->selectFields[$this->fkSelectAliases[$fieldName]] = $fieldName;
      }
      elseif ($field && in_array($field['name'], $this->entityFieldNames)) {
        $this->selectFields[self::MAIN_TABLE_ALIAS . "." . UtilsArray::value('column_name', $field, $field['name'])] = $field['name'];
      }
    }
  }

  /**
   * @inheritDoc
   */
  protected function buildWhereClause() {
    foreach ($this->where as $clause) {
      $sql_clause = $this->treeWalkWhereClause($clause);
      $this->query->where($sql_clause);
    }
  }

  /**
   * @inheritDoc
   */
  protected function buildOrderBy() {
    foreach ($this->orderBy as $field => $dir) {
      if ($dir !== 'ASC' && $dir !== 'DESC') {
        throw new \API_Exception("Invalid sort direction. Cannot order by $field $dir");
      }
      if ($this->getField($field)) {
        $this->query->orderBy(self::MAIN_TABLE_ALIAS . '.' . $field . " $dir");
      }
      else {
        throw new \API_Exception("Invalid sort field. Cannot order by $field $dir");
      }
    }
  }

  /**
   * Recursively validate and transform a branch or leaf clause array to SQL.
   *
   * @param array $clause
   * @return string SQL where clause
   *
   * @uses validateClauseAndComposeSql() to generate the SQL etc.
   * @todo if an 'and' is nested within and 'and' (or or-in-or) then should
   * flatten that to be a single list of clauses.
   */
  protected function treeWalkWhereClause($clause) {
    switch ($clause[0]) {
      case 'OR':
      case 'AND':
        // handle branches
        if (count($clause[1]) === 1) {
          // a single set so AND|OR is immaterial
          return $this->treeWalkWhereClause($clause[1][0]);
        }
        else {
          $sql_subclauses = [];
          foreach ($clause[1] as $subclause) {
            $sql_subclauses[] = $this->treeWalkWhereClause($subclause);
          }
          return '(' . implode("\n" . $clause[0], $sql_subclauses) . ')';
        }

      case 'NOT':
        // If we get a group of clauses with no operator, assume AND
        if (!is_string($clause[1][0])) {
          $clause[1] = ['AND', $clause[1]];
        }
        return 'NOT (' . $this->treeWalkWhereClause($clause[1]) . ')';

      default:
        return $this->validateClauseAndComposeSql($clause);
    }
  }

  /**
   * Validate and transform a leaf clause array to SQL.
   * @param array $clause [$fieldName, $operator, $criteria]
   * @return string SQL
   * @throws \API_Exception
   * @throws \Exception
   */
  protected function validateClauseAndComposeSql($clause) {
    // Pad array for unary operators
    list($key, $operator, $value) = array_pad($clause, 3, NULL);
    $fieldSpec = $this->getField($key);
    // derive table and column:
    $table_name = NULL;
    $column_name = NULL;
    if (in_array($key, $this->entityFieldNames)) {
      $table_name = self::MAIN_TABLE_ALIAS;
      $column_name = $key;
    }
    elseif (strpos($key, '.') && isset($this->fkSelectAliases[$key])) {
      list($table_name, $column_name) = explode('.', $this->fkSelectAliases[$key]);
    }

    if (!$table_name || !$column_name) {
      throw new \API_Exception("Invalid field '$key' in where clause.");
    }

    FormattingUtil::formatValue($value, $fieldSpec, $this->getEntity());

    $sql_clause = \CRM_Core_DAO::createSQLFilter("`$table_name`.`$column_name`", [$operator => $value]);
    if ($sql_clause === NULL) {
      throw new \API_Exception("Invalid value in where clause for field '$key'");
    }
    return $sql_clause;
  }

  /**
   * @inheritDoc
   */
  protected function getFields() {
    return $this->apiFieldSpec;
  }

  /**
   * Fetch a field from the getFields list
   *
   * @param string $fieldName
   *
   * @return string|null
   */
  protected function getField($fieldName) {
    if ($fieldName) {
      $fieldPath = explode('.', $fieldName);
      if (count($fieldPath) > 1) {
        $fieldName = implode('.', array_slice($fieldPath, -2));
      }
      return UtilsArray::value($fieldName, $this->apiFieldSpec);
    }
    return NULL;
  }

  /**
   * @param $key
   * @throws \API_Exception
   */
  protected function joinFK($key) {
    $pathArray = explode('.', $key);

    if (count($pathArray) < 2) {
      return;
    }

    /** @var \Civi\Api4\Service\Schema\Joiner $joiner */
    $joiner = \Civi::container()->get('joiner');
    $field = array_pop($pathArray);
    $pathString = implode('.', $pathArray);

    if (!$joiner->canJoin($this, $pathString)) {
      return;
    }

    $joinPath = $joiner->join($this, $pathString);
    /** @var \Civi\Api4\Service\Schema\Joinable\Joinable $lastLink */
    $lastLink = array_pop($joinPath);

    // Cache field info for retrieval by $this->getField()
    $prefix = array_pop($pathArray) . '.';
    if (!isset($this->apiFieldSpec[$prefix . $field])) {
      $joinEntity = $lastLink->getEntity();
      // Custom fields are already prefixed
      if ($lastLink instanceof CustomGroupJoinable) {
        $prefix = '';
      }
      foreach ($lastLink->getEntityFields() as $fieldObject) {
        $this->apiFieldSpec[$prefix . $fieldObject->getName()] = $fieldObject->toArray() + ['entity' => $joinEntity];
      }
    }

    if (!$lastLink->getField($field)) {
      throw new \API_Exception('Invalid join');
    }

    // custom groups use aliases for field names
    if ($lastLink instanceof CustomGroupJoinable) {
      $field = $lastLink->getSqlColumn($field);
    }

    $this->fkSelectAliases[$key] = sprintf('%s.%s', $lastLink->getAlias(), $field);
  }

  /**
   * @param \Civi\Api4\Service\Schema\Joinable\Joinable $joinable
   *
   * @return $this
   */
  public function addJoinedTable(Joinable $joinable) {
    $this->joinedTables[] = $joinable;

    return $this;
  }

  /**
   * @return FALSE|string
   */
  public function getFrom() {
    return AllCoreTables::getTableForClass(AllCoreTables::getFullName($this->entity));
  }

  /**
   * @return string
   */
  public function getEntity() {
    return $this->entity;
  }

  /**
   * @return array
   */
  public function getSelect() {
    return $this->select;
  }

  /**
   * @return array
   */
  public function getWhere() {
    return $this->where;
  }

  /**
   * @return array
   */
  public function getOrderBy() {
    return $this->orderBy;
  }

  /**
   * @return mixed
   */
  public function getLimit() {
    return $this->limit;
  }

  /**
   * @return mixed
   */
  public function getOffset() {
    return $this->offset;
  }

  /**
   * @return array
   */
  public function getSelectFields() {
    return $this->selectFields;
  }

  /**
   * @return bool
   */
  public function isFillUniqueFields() {
    return $this->isFillUniqueFields;
  }

  /**
   * @return \CRM_Utils_SQL_Select
   */
  public function getQuery() {
    return $this->query;
  }

  /**
   * @return array
   */
  public function getJoins() {
    return $this->joins;
  }

  /**
   * @return array
   */
  public function getApiFieldSpec() {
    return $this->apiFieldSpec;
  }

  /**
   * @return array
   */
  public function getEntityFieldNames() {
    return $this->entityFieldNames;
  }

  /**
   * @return array
   */
  public function getAclFields() {
    return $this->aclFields;
  }

  /**
   * @return bool|string
   */
  public function getCheckPermissions() {
    return $this->checkPermissions;
  }

  /**
   * @return int
   */
  public function getApiVersion() {
    return $this->apiVersion;
  }

  /**
   * @return array
   */
  public function getFkSelectAliases() {
    return $this->fkSelectAliases;
  }

  /**
   * @return \Civi\Api4\Service\Schema\Joinable\Joinable[]
   */
  public function getJoinedTables() {
    return $this->joinedTables;
  }

  /**
   * @return \Civi\Api4\Service\Schema\Joinable\Joinable
   */
  public function getJoinedTable($alias) {
    foreach ($this->joinedTables as $join) {
      if ($join->getAlias() == $alias) {
        return $join;
      }
    }
  }

  /**
   * Get table name on basis of entity
   *
   * @param string $baoName
   *
   * @return void
   */
  public function getTableName($baoName) {
    if (strstr($this->entity, 'Custom_')) {
      $this->query = \CRM_Utils_SQL_Select::from(CoreUtil::getCustomTableByName(str_replace('Custom_', '', $this->entity)) . ' ' . self::MAIN_TABLE_ALIAS);
      $this->entityFieldNames = array_keys($this->apiFieldSpec);
    }
    else {
      $bao = new $baoName();
      $this->query = \CRM_Utils_SQL_Select::from($bao->tableName() . ' ' . self::MAIN_TABLE_ALIAS);
    }
  }

  /**
   * Separates a string like 'emails.location_type.label' into an array, where
   * each value in the array tells whether it is 1-1 or 1-n join type
   *
   * @param string $pathString
   *   Dot separated path to the field
   *
   * @return array
   *   Index is table alias and value is boolean whether is 1-to-many join
   */
  public function getPathJoinTypes($pathString) {
    $pathParts = explode('.', $pathString);
    // remove field
    array_pop($pathParts);
    $path = [];
    $query = $this;
    $isMultipleChecker = function($alias) use ($query) {
      foreach ($query->getJoinedTables() as $table) {
        if ($table->getAlias() === $alias) {
          return $table->getJoinType() === Joinable::JOIN_TYPE_ONE_TO_MANY;
        }
      }
      return FALSE;
    };

    foreach ($pathParts as $part) {
      $path[$part] = $isMultipleChecker($part);
    }

    return $path;
  }

}
