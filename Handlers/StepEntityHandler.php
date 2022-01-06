<?php

namespace App\Handlers;

use App\DTO\SaveStepDTO;
use App\Exceptions\EntityException;
# ...
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log as FacadesLog;
# ...
use Psr\Log\LogLevel;

/**
 * Class StepEntityHandler
 * @package App\Handlers
 * @property IHandler   $nextHandler
 * @property string     $className
 * @property string     $tableName
 * @property string     $foreignKeyProperty
 *
 * @property Collection $preloadedEntities
 * @property Collection $messages
 */
abstract class StepEntityHandler implements IHandler
{
    use RunnableTrait;

    /** @var string[] */
    const STEP_INSERT_OPERATION_PER_MODEl = [
        Customer::class => InsertCustomerOperation::class,
        Product::class  => InsertProductOperation::class,
    ];

    /** @var string[] */
    const STEP_UPDATE_OPERATION_PER_MODEl = [
        Customer::class => UpdateCustomerOperation::class,
        Product::class  => UpdateProductOperation::class,
    ];

    /**
     * @var Collection $preloadedEntities
     */
    private $preloadedEntities;

    /**
     * @var Collection $messages
     */
    private $messages;

    /**
     * StepEntityHandler constructor.
     */
    public function __construct()
    {
        $this->preloadedEntities = collect([]);
        $this->messages          = collect([]);
    }

    /**
     * @param string $className
     * @return $this
     */
    public function setClassName(string $className)
    {
        $this->className = $className;

        return $this;
    }

    /**
     * @param string $tableName
     * @return $this
     */
    public function setTableName(string $tableName)
    {
        $this->tableName = $tableName;

        return $this;
    }

    /**
     * @param string $foreignKeyProperty
     * @return $this
     */
    public function setForeignKeyProperty(string $foreignKeyProperty)
    {
        $this->foreignKeyProperty = $foreignKeyProperty;

        return $this;
    }

    /**
     * @param $tableName
     * @return Model|null
     */
    public function getPreloadedStepEntity($tableName)
    {
        return $this->preloadedEntities->get($tableName);
    }

    /**
     * @return Collection
     */
    public function getPreloadedStepEntities()
    {
        return $this->preloadedEntities;
    }

    /**
     * @param $stepEntity
     * @return $this
     */
    public function setPreloadedStepEntity($stepEntity)
    {
        $this->preloadedEntities->put($this->tableName, $stepEntity);

        return $this;
    }

    /**
     * @var IHandler
     */
    private $nextHandler;

    public function setNext(IHandler $handler): IHandler
    {
        $this->nextHandler = $handler;

        return $handler;
    }

    /**
     * @param SaveStepDTO $saveStepDTO
     * @param array       $mappedData
     * @param Collection  $stepEntities
     * @param Collection  $finalizedHandlers
     * @return mixed|null
     */
    public function handle(SaveStepDTO $saveStepDTO, array $mappedData, Collection $stepEntities, Collection $finalizedHandlers)
    {
        if ($this->nextHandler) {
            return $this->nextHandler->handle($saveStepDTO, $mappedData, $stepEntities, $finalizedHandlers);
        }

        return null;
    }

    /**
     * @param string $entityClassName
     * @return string|null
     */
    protected function getInsertingClassForEntity(string $entityClassName)
    {
        $insertingClass = null;
        if (!empty(static::STEP_INSERT_OPERATION_PER_MODEl[$entityClassName])) {
            $insertingClass = static::STEP_INSERT_OPERATION_PER_MODEl[$entityClassName];
        }

        return $insertingClass;
    }

    /**
     * @param string $entityClassName
     * @return string|null
     */
    protected function getUpdatingClassForEntity(string $entityClassName)
    {
        $insertingClass = null;
        if (!empty(static::STEP_UPDATE_OPERATION_PER_MODEl[$entityClassName])) {
            $insertingClass = static::STEP_UPDATE_OPERATION_PER_MODEl[$entityClassName];
        }

        return $insertingClass;
    }

    /**
     * @param string      $entityClassName
     * @param string      $entityTableName
     * @param array       $mappedData
     * @param SaveStepDTO $saveStepDTO
     * @return Model|null
     * @throws EntityException
     */
    protected function insertStepEntity(string $entityClassName, string $entityTableName, array $mappedData, SaveStepDTO $saveStepDTO)
    {
        $insertingClass = $this->getInsertingClassForEntity($entityClassName);
        if (empty($insertingClass)) {
            throw new EntityException("Unable to find inserting operation for {$entityClassName}");
        }

        if (empty($mappedData[$entityTableName])) {
            //$this->logMessage(LogLevel::INFO, "Inserting entity without form mapped data {$entityClassName}. Pivot-ish table without form data or skipped entity.");
        }

        $formData = $mappedData[$entityTableName] ?? [];

        try {
            /** @var Model|null $insertedEntity */
            $insertedEntity = $this->run(
                $insertingClass,
                [
                    'data'     => $formData,
                    'user'     => $saveStepDTO->getUser(),
                    'stepData' => $saveStepDTO->getData(),
                ]
            );
        } catch (\Exception $e) {
            $this->logMessage(LogLevel::ERROR, $e->getMessage());
            $insertedEntity = null;
        }
        $isInstance = $insertedEntity instanceof Model;

        if ($isInstance) {
            $this->logMessage(LogLevel::INFO, "Entity with ID: [{$insertedEntity->id}] inserted into table " . $entityTableName);
        }

        return $insertedEntity;
    }

    /**
     * @param string      $entityClassName
     * @param string      $entityTableName
     * @param array       $mappedData
     * @param SaveStepDTO $saveStepDTO
     * @param Model       $entityToUpdate
     * @return mixed|null
     * @throws EntityException
     */
    protected function updateStepEntity(string $entityClassName, string $entityTableName, array $mappedData, SaveStepDTO $saveStepDTO, Model $entityToUpdate)
    {
        $updatingClass = $this->getUpdatingClassForEntity($entityClassName);
        $formData      = $mappedData[$entityTableName] ?? [];
        $isInstance    = $entityToUpdate instanceof Model;

        /**
         * To prevent form data with empty values only that trigger update:
         * [
         *    "customer_id" => null
         * ]
         * Empty array [] means it's pivot, array with null values means JS went wrong and no data provided
         */
        $formDataFiltered = array_filter($formData);
        $isFiltered = empty($formDataFiltered) && !empty($formData);

        if (empty($updatingClass)) {
            throw new EntityException("Unable to find inserting operation for {$entityClassName}");
        }

        if (!$isInstance || ($isFiltered && empty($formDataFiltered))) {
            $this->logMessage(LogLevel::WARNING, "No entity to update for {$entityClassName} or empty form data given. Skipping process.");

            return null;
        }

        if (empty($formData)) {
            $this->logMessage(LogLevel::INFO, "Updating entity without form mapped data {$entityClassName}. Pivot-ish table without form data or skipped entity.");
        }

        try {
            /** @var Model|null $insertedEntity */
            $updatedEntity = $this->run(
                $updatingClass,
                [
                    'data'     => $formData,
                    'user'     => $saveStepDTO->getUser(),
                    'entity'   => $entityToUpdate,
                    'stepData' => $saveStepDTO->getData(),
                ]
            );
        } catch (\Exception $e) {
            //TODO: Check if there is warning in view if this happens! Otherwise user will continue on not update data
            $exceptionClass = get_class($e);
            $this->logMessage(LogLevel::ERROR, "Unable to update entity!!! Cause: [{$exceptionClass}] " . $e->getMessage());
            $updatedEntity = null;
        }

        if ($updatedEntity instanceof Model) {
            $this->logMessage(LogLevel::INFO, "Entity with ID: [{$updatedEntity->id}] updated in table " . $entityTableName, $updatedEntity->getAttributes());
        }

        return $updatedEntity;
    }

    /**
     * Without sense to use LoggerTrait
     *
     * @param $level
     * @param $message
     * @param $attributes
     */
    protected function logMessage($level, $message, $attributes = [])
    {
        $levelMessages = $this->getMessagesByLevel($level);
        $levelMessages->push($message);
        $this->messages->put($level, $levelMessages);

        FacadesLog::{$level}($message, $attributes);
    }

    /**
     * @param $level
     * @return Collection|mixed
     */
    protected function getMessagesByLevel($level)
    {
        return $this->messages->get($level) ?? collect();
    }
}
