<?php

namespace App\\Handlers;

use App\DTO\SaveStepDTO;
use Illuminate\Support\Collection;

/**
 * Interface IHandler
 * @package App\Handlers
 */
interface IHandler
{
    /**
     * @param IHandler $handler
     * @return IHandler
     */
    public function setNext(IHandler $handler): IHandler;

    /**
     * @param SaveStepDTO $saveStepDTO
     * @param array       $mappedData
     * @param Collection  $stepEntities
     * @param Collection  $finalizedHandlers
     * @return mixed
     */
    public function handle(SaveStepDTO $saveStepDTO, array $mappedData, Collection $stepEntities, Collection $finalizedHandlers);
}
