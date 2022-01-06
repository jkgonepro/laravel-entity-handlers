<?php

namespace App\Handlers;

use App\DTO\SaveStepDTO;
use App\Exceptions\EntityException;
# ...
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
# ...

/**
 * Class InsertEntityHandler
 * @package App\Handlers
 * Info: Get Jobs mappings per entity from StepEntityHandler (used by getInsertingClassForEntity)
 *
 * @property string $className
 * @property string $tableName
 * @property string $foreignKeyProperty
 */
final class InsertEntityHandler extends StepEntityHandler implements IEntityHandler
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
        $insertedEntity = $this->insertStepEntity(
            $this->className, // e.g. Customer::class,
            $this->tableName,// e.g. Customer::getTableName(),
            $mappedData, // array of arrays with each table mapped data
            $saveStepDTO // original DTO
        );

        $insertedId = null;
        if($insertedEntity instanceof Model){
            $stepEntities[$this->className] = $insertedEntity;

            // update data
            if(isset($this->foreignKeyProperty)){
                $data = $saveStepDTO->getData();
                $data[$this->foreignKeyProperty] = $insertedId = $insertedEntity->id;
                $saveStepDTO->setData($data);
            }
        } // no throwing, some entities are saved without mapped data from form

        $finalizedHandlers->put($this->className, $insertedId);

        // trigger next handle
        parent::handle($saveStepDTO, $mappedData, $stepEntities, $finalizedHandlers);
    }
}
