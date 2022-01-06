<?php

namespace App\Handlers;

use App\DTO\SaveStepDTO;
use App\Exceptions\EntityException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Psr\Log\LogLevel;

/**
 * Class UpdateEntityHandler
 * @package App\Handlers
 *
 * @property string $className
 * @property string $tableName
 * @property string $foreignKeyProperty
 */
final class UpdateEntityHandler extends StepEntityHandler implements IEntityHandler
{
    /**
     * @param SaveStepDTO $saveStepDTO
     * @param array       $mappedData
     * @param Collection  $stepEntities
     * @param Collection  $finalizedHandlers
     * @return mixed|void|null
     * @throws EntityException
     */
    public function handle(SaveStepDTO $saveStepDTO, array $mappedData, Collection $stepEntities, Collection $finalizedHandlers)
    {
        $updatedEntity = null;
        $formData      = $mappedData[$this->tableName] ?? [];

        if ($this->getPreloadedStepEntity($this->tableName) instanceof Model) {

            $updatedEntity = $this->updateStepEntity(
                $this->className, // e.g. Customer::class,
                $this->tableName,// e.g. Customer::getTableName(),
                $mappedData, // array of arrays with each table mapped data
                $saveStepDTO, // original DTO
                $this->getPreloadedStepEntity($this->tableName)
            );

        } else { // there is data from form but nothing to update, insert
            if (!empty($formData)){
                $this->logMessage(LogLevel::WARNING, "No preloaded entity or data to update {$this->className}. Running insert on step data.");
            }

            $insertedEntity = $this->insertStepEntity(
                $this->className, // e.g. Customer::class,
                $this->tableName,// e.g. Customer::getTableName(),
                $mappedData, // array of arrays with each table mapped data
                $saveStepDTO // original DTO
            );

            $this->logMessage(LogLevel::WARNING, "Updated entity not loaded, possibly does not exist. Inserting and continuing handler with new ID.");

            $updatedEntity = $insertedEntity;
        }

        $updatedId = null;
        if ($updatedEntity instanceof Model) {
            $stepEntities[$this->className] = $updatedEntity;

            // update data
            if (isset($this->foreignKeyProperty)) {
                $data                            = $saveStepDTO->getData();
                $data[$this->foreignKeyProperty] = $updatedId = $updatedEntity->id;
                $saveStepDTO->setData($data);
            }
        } // no throwing, some entities are saved without mapped data from form

        $finalizedHandlers->put($this->className, $updatedId);

        // trigger next handle
        parent::handle($saveStepDTO, $mappedData, $stepEntities, $finalizedHandlers);
    }
}
