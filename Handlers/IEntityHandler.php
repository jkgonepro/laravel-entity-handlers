<?php

namespace App\Handlers;

/**
 * Interface IEntityHandler
 * @package App\\Handlers
 */
interface IEntityHandler
{
    /**
     * @param string $className
     * @return $this
     */
    public function setClassName(string $className);

    /**
     * @param string $tableName
     * @return $this
     */
    public function setTableName(string $tableName);
}
