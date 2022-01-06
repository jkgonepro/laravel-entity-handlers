<?php

namespace App\Classes;

use App\DTO\SaveStepDTO;
use App\DTO\UpdateStepStatusDTO;
# ...
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
# ...

/**
 * Class UpdateCustomerDataStep
 * @package App\Classes
 *
 * @property CustomerDataStepFinalizer $finalizer
 */
class UpdateCustomerDataStep implements ISaveStep
{
    use RunnableTrait;

    /**
     * @var CustomerDataStepFinalizer $finalizer
     */
    private $finalizer;

    /**
     * UpdateCustomerDataStep constructor.
     */
    public function __construct()
    {
        $this->finalizer = new CustomerDataStepFinalizer();

        $this->finalizer
            ->setDataRulesClass(SaveCustomerDataRules::class)
            ->setMappingClass(MapCustomerToTableColumns::class)
            ->setCompletionCheckerClass(CustomerCompletionChecker::class)
            ->initiateCompletionChecker();

        $this->preloadedEntities = collect([
            Customer::class                  => null,
            CustomerAddress::class           => null,
            CustomerPaymentSettings::class   => null,
            CustomerInvoicintSettings::class => null,
        ]);
    }

    /**
     * Order:
     * 1. Run validator
     * 2. Map steps data
     * 3. Define handlers
     * 4. Set queue
     * 5. Run chain
     * 6. Run checker
     * 7. Update status
     * 8. Done
     *
     * @param SaveStepDTO $saveStepDTO
     * @return array
     */
    public function save(SaveStepDTO $saveStepDTO): array
    {
       try {
            $this->initializePreloadedEntities($saveStepDTO);
        } catch (\Exception $e) {
            $errors = collect([]);
            $errors->push($e->getMessage());

            return [
                'registrationCustomerId' => null,
                'flow'                   => [],
                'missingFields'          => [],
                'errors'                 => $errors->toArray(),
            ];
        }

        // 1.
        $this->finalizer->runValidator($saveStepDTO);

        // 2.
        $this->finalizer->mapStepData($saveStepDTO);

        // 3.
        $customerInsertHandler = new InsertEntityHandler();
        $customerInsertHandler
            ->setClassName(Customer::class)
            ->setTableName(Customer::getTableName())
            ->setForeignKeyProperty('customerId');

        $customerAddressInsertHandler = new InsertEntityHandler();
        $customerAddressInsertHandler
            ->setClassName(CustomerAddress::class)
            ->setTableName(CustomerAddress::getTableName())
            ->setForeignKeyProperty('customerAddressId');

        $customerPaymentSettingsInsertHandler = new InsertEntityHandler();
        $customerPaymentSettingsInsertHandler
            ->setClassName(CustomerPaymentSettings::class)
            ->setTableName(CustomerPaymentSettings::getTableName())
            ->setForeignKeyProperty('customerPaymentSettingsId');

        $customerInvoicingSettingsInsertHandler = new InsertEntityHandler();
        $customerInvoicingSettingsInsertHandler
            ->setClassName(CustomerInvoicintSettings::class)
            ->setTableName(CustomerInvoicintSettings::getTableName())
            ->setForeignKeyProperty('customerInvoicingSettingsId');

        // 4.
        $customerInsertHandler
            ->setNext($customerAddressInsertHandler)
            ->setNext($customerPaymentSettingsInsertHandler)
            ->setNext($customerInvoicingSettingsInsertHandler);

        // 5.
        $this->finalizer->runSavingChain($customerInsertHandler);

        // 6. - if chain not finished, update should handle
        $this->finalizer->runCompletionChecker(
            collect([
                'dataProvider'                    => $saveStepDTO->getData()['dataProvider'] ?? null,
                'customer'                        => $this->finalizer->getCustomerEntity(),
            ])
        );

        // 7.
        if($this->finalizer->isStepCompleted()){
            $this->run(
                UpdateStepStatusOperation::class,
                [
                    'updateStepStatusDTO' => $this->run(
                        PrepareCustomerDataStepStatusDataJob::class,
                        [
                            'customer'    => $this->finalizer->getCustomerEntity(),
                            'saveStepDTO' => $saveStepDTO,
                        ]
                    ),
                ]
            );
        } else {
            Log::warning("Step saved but not complete. Cause: " . $this->finalizer->getCompletionCheckerInstance()->getErrors());
        }

        // 8.
        return [
            'customerId'    => $this->finalizer->getCustomerEntity()->id,
            'steps'          => json_decode($this->finalizer->getCustomerEntity()->registration_steps),
            'missingFields' => $this->finalizer->getCompletionCheckerInstance()->getMissingFields()->toArray(),
            'errors'        => $this->finalizer->getCompletionCheckerInstance()->getErrors()->toArray(),
        ];
    }

    /**
         * @param SaveStepDTO $saveStepDTO
         * @throws EntityException
         */
    private function initializePreloadedEntities(SaveStepDTO $saveStepDTO)
    {
      /** @var Customer $registrationEmployee */
      $registrationCustomerId = Customer::find($saveStepDTO->getData()->get('registrationEmployeeId'));

      if ($registrationCustomer instanceof Customer) {
          $this->preloadedEntities->put(Customer::class, $registrationCustomer);

          // TODO:
      } else {
          throw new EntityException(__('messages.unable-to-preload-data-for-update'));
      }
    }
}
