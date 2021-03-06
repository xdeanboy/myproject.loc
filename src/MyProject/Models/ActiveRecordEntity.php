<?php

namespace MyProject\Models;

use MyProject\Exceptions\DbException;
use MyProject\Services\Db;

abstract class ActiveRecordEntity implements \JsonSerializable
{
    protected $id;

    public function getId(): int
    {
        return $this->id;
    }

    abstract protected static function getTableName(): string;

    public function __set($name, $value)
    {
        $nameToCamelCase = $this->underscoreToCamelCase($name);
        $this->$nameToCamelCase = $value;
    }

    private function camelCaseToUnderscore(string $source): string
    {
        //camelCase => camel_case
        return strtolower(preg_replace('/(?<!^)[A-Z]/', '_$0', $source));
    }

    private function underscoreToCamelCase(string $source): string
    {
        //camel_case => camelCase
        return lcfirst(str_replace('_', '', ucwords($source, '_')));
    }

    public static function findAll(): ?array
    {
        $db = Db::getInstance();
        $result = $db->query('SELECT * FROM `' . static::getTableName() . '` ;', [], static::class);

        if ($result === null) {
            return null;
        }

        return $result;
    }

    public static function getById(int $id): ?self
    {
        $db = Db::getInstance();

        $result = $db->query('SELECT * FROM `' . static::getTableName() . '` WHERE id=:id ',
            [':id' => $id],
            static::class);

        return $result ? $result[0] : null;
    }

    private function mapPropertiesToDb(): array
    {
        $reflector = new \ReflectionObject($this);
        $properties = $reflector->getProperties();

        $mappedProperties = [];
        foreach ($properties as $property) {
            $propertyName = $property->getName();
            $propertyToUnderscore = $this->camelCaseToUnderscore($propertyName);
            $mappedProperties[$propertyToUnderscore] = $this->$propertyName;
        }

        return $mappedProperties;
    }

    public function save(): void
    {
        $mappedProperties = $this->mapPropertiesToDb();

        if ($this->id !== null) {
            $this->update($mappedProperties);
        } else {
            $this->insert($mappedProperties);
        }
    }

    private function update(array $mappedProperties): void
    {
        /*update article
         *UPDATE tableName SET column1 = value1, column2 = value2 WHERE id=:id;
         *column1 = :param1
         *[:param1 = :value1]
         */

        $columns2params = [];
        $params2value = [];
        $index = 1;

        foreach ($mappedProperties as $propertyName => $value) {
            $params = ':param' . $index++;
            $params2value[$params] = $value;
            $columns2params[] = $propertyName . ' = ' . $params;
        }

        $valueForSet = implode(', ', $columns2params);
        $sql = 'UPDATE ' . static::getTableName() . ' SET ' . $valueForSet . ' WHERE id=' . $this->id;

        $db = Db::getInstance();
        $db->query($sql, $params2value, static::class);

    }

    private function insert(array $mappedProperties): void
    {
        /*add new article
         *INSERT INTO tableName (column1, column2) VALUES (value1, value2);
         *[:param1 => value1]
         */

        $filerProperties = array_filter($mappedProperties);
        $columns = [];
        $params = [];
        $params2values = [];
        $index = 1;


        foreach ($filerProperties as $columnName => $value) {
            $param = ':param' . $index++;
            $columns[] = $columnName;
            $params2values[$param] = $value;
            $params[] = $param;
        }

        $columnsViaSemicolon = implode(', ', $columns);
        $paramsViaSemicolon = implode(', ', $params);

        $db = Db::getInstance();
        $sql = 'INSERT INTO ' . static::getTableName() . ' ( ' . $columnsViaSemicolon . ' ) VALUES ( ' . $paramsViaSemicolon . ' );';

        $db->query($sql, $params2values, static::class);
        $this->id = $db->getLastId();
    }

    public function delete(): void
    {
        $db = Db::getInstance();
        $sql = 'DELETE FROM `' . static::getTableName() . '` WHERE id=:id;';
        $db->query($sql, [':id' => $this->id], static::class);
    }

    public static function findByOneColumn(string $columnName, $value): ?self
    {
        $db = Db::getInstance();

        $sql = 'SELECT * FROM`' . static::getTableName() . '` WHERE ' . $columnName . ' = :value LIMIT 1;';
        $result = $db->query($sql, [':value' => $value], static::class);

        if ($result === []) {
            return null;
        }

        return $result[0];
    }

    public static function findAllByColumn(string $columnName, $value): ?array
    {
        $db = Db::getInstance();

        $result = $db->query('SELECT * FROM `' . static::getTableName() . '` WHERE ' . $columnName . ' = :value;',
            [':value' => $value],
            static::class);

        return !empty($result) ? $result : null;
    }

    public static function findAllSortByDesc(string $sortByColumns): ?array
    {
        $db = Db::getInstance();

        $result = $db->query('SELECT * FROM `' . static::getTableName() . '` ORDER BY ' . $sortByColumns . ' DESC',
        [], static::class);

        return !empty($result) ? $result : null;
    }

    public function jsonSerialize()
    {
        return $this->mapPropertiesToDb();
    }

    public static function getPagesCount(int $countElements): int
    {
        $db = Db::getInstance();

        $result = $db->query('SELECT COUNT(*) AS cnt FROM ' . static::getTableName() . ';');
        return ceil($result[0]->cnt / $countElements);
    }

    public static function getPage(int $pageNum, int $countElements): array
    {
        $db = Db::getInstance();

        return $db->query(
            sprintf('SELECT * FROM `%s` ORDER BY id LIMIT %d OFFSET %d;',
            static::getTableName(),
            $countElements,
                ($pageNum - 1) * $countElements
            ),
            [],
            static::class
        );
    }
}