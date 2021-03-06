<?php

/**
 * OciCDbAuthManager class file implementes CDbAuthManager but for ORACLE.
 * 
 * @copyright 2008-2013 Yii Software LLC
 * @license http://www.yiiframework.com/license/
 *
 * Yii doesn't support Oracle well, Oracle use capital letters for tables and column names because of convention.
 * Also SQLite check was removed, because its oracle only.
 */
class OciCDbAuthManager extends CAuthManager {

    /**
     * @var string the ID of the {@link CDbConnection} application component. Defaults to 'db'.
     * The database must have the tables as declared in "framework/web/auth/*.sql".
     */
    public $connectionID = 'db';

    /**
     * @var string the name of the table storing authorization items. Defaults to 'AuthItem'.
     */
    public $itemTable = 'AUTH_ITEM';

    /**
     * @var string the name of the table storing authorization item hierarchy. Defaults to 'AuthItemChild'.
     */
    public $itemChildTable = 'AUTH_ITEM_CHILD';

    /**
     * @var string the name of the table storing authorization item assignments. Defaults to 'AuthAssignment'.
     */
    public $assignmentTable = 'AUTH_ASSIGNMENT';

    /**
     * @var CDbConnection the database connection. By default, this is initialized
     * automatically as the application component whose ID is indicated as {@link connectionID}.
     */
    public $db;

    /**
     * Initializes the application component.
     * This method overrides the parent implementation by establishing the database connection.
     */
    public function init() {
        parent::init();
        $this->getDbConnection();
    }

    /**
     * Performs access check for the specified user.
     * @param string $itemName the name of the operation that need access check
     * @param mixed $userId the user ID. This should can be either an integer and a string representing
     * the unique identifier of a user. See {@link IWebUser::getId}.
     * @param array $params name-value pairs that would be passed to biz rules associated
     * with the tasks and roles assigned to the user.
     * Since version 1.1.11 a param with name 'userId' is added to this array, which holds the value of <code>$userId</code>.
     * @return boolean whether the operations can be performed by the user.
     */
    public function checkAccess($itemName, $userId, $params = array()) {
        $assignments = $this->getAuthAssignments($userId);
        return $this->checkAccessRecursive($itemName, $userId, $params, $assignments);
    }

    /**
     * Performs access check for the specified user.
     * This method is internally called by {@link checkAccess}.
     * @param string $itemName the name of the operation that need access check
     * @param mixed $userId the user ID. This should can be either an integer and a string representing
     * the unique identifier of a user. See {@link IWebUser::getId}.
     * @param array $params name-value pairs that would be passed to biz rules associated
     * with the tasks and roles assigned to the user.
     * Since version 1.1.11 a param with name 'userId' is added to this array, which holds the value of <code>$userId</code>.
     * @param array $assignments the assignments to the specified user
     * @return boolean whether the operations can be performed by the user.
     * @since 1.1.3
     */
    protected function checkAccessRecursive($itemName, $userId, $params, $assignments) {
        if (($item = $this->getAuthItem($itemName)) === null)
            return false;
        Yii::trace('Checking permission "' . $item->getName() . '"', 'application.protected.components.OciCDbAuthManager');
        if (!isset($params['userId']))
            $params['userId'] = $userId;
        if ($this->executeBizRule($item->getBizRule(), $params, $item->getData())) {
            if (in_array($itemName, $this->defaultRoles))
                return true;
            if (isset($assignments[$itemName])) {
                $assignment = $assignments[$itemName];
                if ($this->executeBizRule($assignment->getBizRule(), $params, $assignment->getData()))
                    return true;
            }
            $parents = $this->db->createCommand()
                    ->select('PARENT')
                    ->from($this->itemChildTable)
                    ->where('CHILD=:name', array(':name' => $itemName))
                    ->queryColumn();
            foreach ($parents as $parent) {
                if ($this->checkAccessRecursive($parent, $userId, $params, $assignments))
                    return true;
            }
        }
        return false;
    }

    /**
     * Adds an item as a child of another item.
     * @param string $itemName the parent item name
     * @param string $childName the child item name
     * @return boolean whether the item is added successfully
     * @throws CException if either parent or child doesn't exist or if a loop has been detected.
     */
    public function addItemChild($itemName, $childName) {
        if ($itemName === $childName)
            throw new CException(Yii::t('yii', 'Cannot add "{name}" as a child of itself.', array('{name}' => $itemName)));

        $rows = $this->db->createCommand()
                ->select()
                ->from($this->itemTable)
                ->where('NAME=:name1 OR NAME=:name2', array(
                    ':name1' => $itemName,
                    ':name2' => $childName
                ))
                ->queryAll();

        if (count($rows) == 2) {
            if ($rows[0]['NAME'] === $itemName) {
                $parentType = $rows[0]['TYPE'];
                $childType = $rows[1]['TYPE'];
            } else {
                $childType = $rows[0]['TYPE'];
                $parentType = $rows[1]['TYPE'];
            }
            $this->checkItemChildType($parentType, $childType);
            if ($this->detectLoop($itemName, $childName))
                throw new CException(Yii::t('yii', 'Cannot add "{child}" as a child of "{name}". A loop has been detected.', array('{child}' => $childName, '{name}' => $itemName)));

            $this->db->createCommand()
                    ->insert($this->itemChildTable, array(
                        'PARENT' => $itemName,
                        'CHILD' => $childName,
            ));

            return true;
        } else
            throw new CException(Yii::t('yii', 'Either "{parent}" or "{child}" does not exist.', array('{child}' => $childName, '{parent}' => $itemName)));
    }

    /**
     * Removes a child from its parent.
     * Note, the child item is not deleted. Only the parent-child relationship is removed.
     * @param string $itemName the parent item name
     * @param string $childName the child item name
     * @return boolean whether the removal is successful
     */
    public function removeItemChild($itemName, $childName) {
        return $this->db->createCommand()
                        ->delete($this->itemChildTable, 'PARENT=:parent AND CHILD=:child', array(
                            ':parent' => $itemName,
                            ':child' => $childName
                        )) > 0;
    }

    /**
     * Returns a value indicating whether a child exists within a parent.
     * @param string $itemName the parent item name
     * @param string $childName the child item name
     * @return boolean whether the child exists
     */
    public function hasItemChild($itemName, $childName) {
        return $this->db->createCommand()
                        ->select('PARENT')
                        ->from($this->itemChildTable)
                        ->where('PARENT=:parent AND CHILD=:child', array(
                            ':parent' => $itemName,
                            ':child' => $childName))
                        ->queryScalar() !== false;
    }

    /**
     * Returns the children of the specified item.
     * @param mixed $names the parent item name. This can be either a string or an array.
     * The latter represents a list of item names.
     * @return array all child items of the parent
     */
    public function getItemChildren($names) {
        if (is_string($names))
            $condition = 'PARENT=' . $this->db->quoteValue($names);
        elseif (is_array($names) && $names !== array()) {
            foreach ($names as &$name)
                $name = $this->db->quoteValue($name);
            $condition = 'PARENT IN (' . implode(', ', $names) . ')';
        }

        $rows = $this->db->createCommand()
                ->select('NAME, TYPE, DESCRIPTION, BIZRULE, DATA')
                ->from(array(
                    $this->itemTable,
                    $this->itemChildTable
                ))
                ->where($condition . ' AND NAME=CHILD')
                ->queryAll();

        $children = array();
        foreach ($rows as $row) {
            if (($data = @unserialize($row['DATA'])) === false)
                $data = null;
            $children[$row['NAME']] = new CAuthItem($this, $row['NAME'], $row['TYPE'], $row['DESCRIPTION'], $row['BIZRULE'], $data);
        }
        return $children;
    }

    /**
     * Assigns an authorization item to a user.
     * @param string $itemName the item name
     * @param mixed $userId the user ID (see {@link IWebUser::getId})
     * @param string $bizRule the business rule to be executed when {@link checkAccess} is called
     * for this particular authorization item.
     * @param mixed $data additional data associated with this assignment
     * @return CAuthAssignment the authorization assignment information.
     * @throws CException if the item does not exist or if the item has already been assigned to the user
     */
    public function assign($itemName, $userId, $bizRule = null, $data = null) {
        $this->db->createCommand()
                ->insert($this->assignmentTable, array(
                    'ITEMNAME' => $itemName,
                    'USERID' => $userId,
                    'BIZRULE' => $bizRule,
                    'DATA' => serialize($data)
        ));
        return new CAuthAssignment($this, $itemName, $userId, $bizRule, $data);
    }

    /**
     * Revokes an authorization assignment from a user.
     * @param string $itemName the item name
     * @param mixed $userId the user ID (see {@link IWebUser::getId})
     * @return boolean whether removal is successful
     */
    public function revoke($itemName, $userId) {
        return $this->db->createCommand()
                        ->delete($this->assignmentTable, 'ITEMNAME=:itemname AND USERID=:userid', array(
                            ':itemname' => $itemName,
                            ':userid' => $userId
                        )) > 0;
    }

    /**
     * Returns a value indicating whether the item has been assigned to the user.
     * @param string $itemName the item name
     * @param mixed $userId the user ID (see {@link IWebUser::getId})
     * @return boolean whether the item has been assigned to the user.
     */
    public function isAssigned($itemName, $userId) {
        return $this->db->createCommand()
                        ->select('ITEMNAME')
                        ->from($this->assignmentTable)
                        ->where('ITEMNAME=:itemname AND USERID=:userid', array(
                            ':itemname' => $itemName,
                            ':userid' => $userId))
                        ->queryScalar() !== false;
    }

    /**
     * Returns the item assignment information.
     * @param string $itemName the item name
     * @param mixed $userId the user ID (see {@link IWebUser::getId})
     * @return CAuthAssignment the item assignment information. Null is returned if
     * the item is not assigned to the user.
     */
    public function getAuthAssignment($itemName, $userId) {
        $row = $this->db->createCommand()
                ->select()
                ->from($this->assignmentTable)
                ->where('ITEMNAME=:itemname AND USERID=:userid', array(
                    ':itemname' => $itemName,
                    ':userid' => $userId))
                ->queryRow();
        if ($row !== false) {
            if (($data = @unserialize($row['DATA'])) === false)
                $data = null;
            return new CAuthAssignment($this, $row['ITEMNAME'], $row['USERID'], $row['BIZRULE'], $data);
        } else
            return null;
    }

    /**
     * Returns the item assignments for the specified user.
     * @param mixed $userId the user ID (see {@link IWebUser::getId})
     * @return array the item assignment information for the user. An empty array will be
     * returned if there is no item assigned to the user.
     */
    public function getAuthAssignments($userId) {
        $rows = $this->db->createCommand()
                ->select()
                ->from($this->assignmentTable)
                ->where('USERID=:userid', array(':userid' => $userId))
                ->queryAll();
        $assignments = array();
        foreach ($rows as $row) {
            if (($data = @unserialize($row['DATA'])) === false)
                $data = null;
            $assignments[$row['ITEMNAME']] = new CAuthAssignment($this, $row['ITEMNAME'], $row['USERID'], $row['BIZRULE'], $data);
        }
        return $assignments;
    }

    /**
     * Saves the changes to an authorization assignment.
     * @param CAuthAssignment $assignment the assignment that has been changed.
     */
    public function saveAuthAssignment($assignment) {
        $this->db->createCommand()
                ->update($this->assignmentTable, array(
                    'BIZRULE' => $assignment->getBizRule(),
                    'DATA' => serialize($assignment->getData()),
                        ), 'ITEMNAME=:itemname AND USERID=:userid', array(
                    'ITEMNAME' => $assignment->getItemName(),
                    'USERID' => $assignment->getUserId()
        ));
    }

    /**
     * Returns the authorization items of the specific type and user.
     * @param integer $type the item type (0: operation, 1: task, 2: role). Defaults to null,
     * meaning returning all items regardless of their type.
     * @param mixed $userId the user ID. Defaults to null, meaning returning all items even if
     * they are not assigned to a user.
     * @return array the authorization items of the specific type.
     */
    public function getAuthItems($type = null, $userId = null) {
        if ($type === null && $userId === null) {
            $command = $this->db->createCommand()
                    ->select()
                    ->from($this->itemTable);
        } elseif ($userId === null) {
            $command = $this->db->createCommand()
                    ->select()
                    ->from($this->itemTable)
                    ->where('TYPE=:type', array(':type' => $type));
        } elseif ($type === null) {
            $command = $this->db->createCommand()
                    ->select('NAME,TYPE,DESCRIPTION,t1.BIZRULE,t1.DATA')
                    ->from(array(
                        $this->itemTable . ' t1',
                        $this->assignmentTable . ' t2'
                    ))
                    ->where('NAME=ITEMNAME AND USERID=:userid', array(':userid' => $userId));
        } else {
            $command = $this->db->createCommand()
                    ->select('NAME,TYPE,DESCRIPTION,t1.BIZRULE,t1.DATA')
                    ->from(array(
                        $this->itemTable . ' t1',
                        $this->assignmentTable . ' t2'
                    ))
                    ->where('NAME=ITEMNAME AND TYPE=:type AND USERID=:userid', array(
                ':type' => $type,
                ':userid' => $userId
            ));
        }
        $items = array();
        foreach ($command->queryAll() as $row) {
            if (($data = @unserialize($row['DATA'])) === false)
                $data = null;
            $items[$row['NAME']] = new CAuthItem($this, $row['NAME'], $row['TYPE'], $row['DESCRIPTION'], $row['BIZRULE'], $data);
        }
        return $items;
    }

    /**
     * Creates an authorization item.
     * An authorization item represents an action permission (e.g. creating a post).
     * It has three types: operation, task and role.
     * Authorization items form a hierarchy. Higher level items inheirt permissions representing
     * by lower level items.
     * @param string $name the item name. This must be a unique identifier.
     * @param integer $type the item type (0: operation, 1: task, 2: role).
     * @param string $description description of the item
     * @param string $bizRule business rule associated with the item. This is a piece of
     * PHP code that will be executed when {@link checkAccess} is called for the item.
     * @param mixed $data additional data associated with the item.
     * @return CAuthItem the authorization item
     * @throws CException if an item with the same name already exists
     */
    public function createAuthItem($name, $type, $description = '', $bizRule = null, $data = null) {
        $this->db->createCommand()
                ->insert($this->itemTable, array(
                    'NAME' => $name,
                    'TYPE' => $type,
                    'DESCRIPTION' => $description,
                    'BIZRULE' => $bizRule,
                    'DATA' => serialize($data)
        ));
        return new CAuthItem($this, $name, $type, $description, $bizRule, $data);
    }

    /**
     * Removes the specified authorization item.
     * @param string $name the name of the item to be removed
     * @return boolean whether the item exists in the storage and has been removed
     */
    public function removeAuthItem($name) {
        return $this->db->createCommand()
                        ->delete($this->itemTable, 'NAME=:name', array(
                            ':name' => $name
                        )) > 0;
    }

    /**
     * Returns the authorization item with the specified name.
     * @param string $name the name of the item
     * @return CAuthItem the authorization item. Null if the item cannot be found.
     */
    public function getAuthItem($name) {
        $row = $this->db->createCommand()
                ->select()
                ->from($this->itemTable)
                ->where('NAME=:name', array(':name' => $name))
                ->queryRow();

        if ($row !== false) {
            if (($data = @unserialize($row['DATA'])) === false)
                $data = null;
            return new CAuthItem($this, $row['NAME'], $row['TYPE'], $row['DESCRIPTION'], $row['BIZRULE'], $data);
        } else
            return null;
    }

    /**
     * Saves an authorization item to persistent storage.
     * @param CAuthItem $item the item to be saved.
     * @param string $oldName the old item name. If null, it means the item name is not changed.
     */
    public function saveAuthItem($item, $oldName = null) {
        $this->db->createCommand()
                ->update($this->itemTable, array(
                    'NAME' => $item->getName(),
                    'TYPE' => $item->getType(),
                    'DESCRIPTION' => $item->getDescription(),
                    'BIZRULE' => $item->getBizRule(),
                    'DATA' => serialize($item->getData()),
                        ), 'NAME=:whereName', array(
                    ':whereName' => $oldName === null ? $item->getName() : $oldName,
        ));
    }

    /**
     * Saves the authorization data to persistent storage.
     */
    public function save() {

    }

    /**
     * Removes all authorization data.
     */
    public function clearAll() {
        $this->clearAuthAssignments();
        $this->db->createCommand()->delete($this->itemChildTable);
        $this->db->createCommand()->delete($this->itemTable);
    }

    /**
     * Removes all authorization assignments.
     */
    public function clearAuthAssignments() {
        $this->db->createCommand()->delete($this->assignmentTable);
    }

    /**
     * Checks whether there is a loop in the authorization item hierarchy.
     * @param string $itemName parent item name
     * @param string $childName the name of the child item that is to be added to the hierarchy
     * @return boolean whether a loop exists
     */
    protected function detectLoop($itemName, $childName) {
        if ($childName === $itemName)
            return true;
        foreach ($this->getItemChildren($childName) as $child) {
            if ($this->detectLoop($itemName, $child->getName()))
                return true;
        }
        return false;
    }

    /**
     * @return CDbConnection the DB connection instance
     * @throws CException if {@link connectionID} does not point to a valid application component.
     */
    protected function getDbConnection() {
        if ($this->db !== null)
            return $this->db;
        elseif (($this->db = Yii::app()->getComponent($this->connectionID)) instanceof CDbConnection)
            return $this->db;
        else
            throw new CException(Yii::t('yii', 'OciCDbAuthManager.connectionID "{id}" is invalid. Please make sure it refers to the ID of a CDbConnection application component.', array('{id}' => $this->connectionID)));
    }

}
