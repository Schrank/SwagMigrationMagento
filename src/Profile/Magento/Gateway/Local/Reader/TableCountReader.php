<?php declare(strict_types=1);

namespace Swag\MigrationMagento\Profile\Magento\Gateway\Local\Reader;

use Shopware\Core\Framework\Context;
use Swag\MigrationMagento\Profile\Magento\Gateway\Connection\ConnectionFactoryInterface;
use SwagMigrationAssistant\Migration\DataSelection\DataSet\CountingInformationStruct;
use SwagMigrationAssistant\Migration\DataSelection\DataSet\CountingQueryStruct;
use SwagMigrationAssistant\Migration\DataSelection\DataSet\DataSet;
use SwagMigrationAssistant\Migration\DataSelection\DataSet\DataSetRegistryInterface;
use SwagMigrationAssistant\Migration\Logging\Log\CannotReadEntityCountLog;
use SwagMigrationAssistant\Migration\Logging\LoggingService;
use SwagMigrationAssistant\Migration\MigrationContextInterface;
use SwagMigrationAssistant\Migration\TotalStruct;

class TableCountReader
{
    /**
     * @var ConnectionFactoryInterface
     */
    private $connectionFactory;

    /**
     * @var DataSetRegistryInterface
     */
    private $dataSetRegistry;

    /**
     * @var LoggingService
     */
    private $loggingService;

    public function __construct(
        ConnectionFactoryInterface $connectionFactory,
        DataSetRegistryInterface $dataSetRegistry,
        LoggingService $loggingService
    ) {
        $this->connectionFactory = $connectionFactory;
        $this->dataSetRegistry = $dataSetRegistry;
        $this->loggingService = $loggingService;
    }

    /**
     * @return TotalStruct[]
     */
    public function readTotals(MigrationContextInterface $migrationContext, Context $context): array
    {
        $dataSets = $this->dataSetRegistry->getDataSets($migrationContext);
        $countingInformation = $this->getCountingInformation($dataSets);
        $connection = $this->connectionFactory->createDatabaseConnection($migrationContext);

        $totals = [];
        foreach ($countingInformation as $countingInfo) {
            $totalQueries = $countingInfo->getQueries();
            $entityName = $countingInfo->getEntityName();

            if ($totalQueries->count() === 0) {
                $totals[$entityName] = new TotalStruct($entityName, 0);

                continue;
            }

            $total = 0;
            /** @var CountingQueryStruct $queryStruct */
            foreach ($totalQueries as $queryStruct) {
                try {
                    $query = $connection->createQueryBuilder();
                    $query = $query->select('COUNT(*)')->from($queryStruct->getTableName());

                    if ($queryStruct->getCondition()) {
                        $query->where($queryStruct->getCondition());
                    }
                    $total += (int) $query->execute()->fetchColumn();
                } catch (\Exception $exception) {
                    $this->loggingService->addLogEntry(new CannotReadEntityCountLog(
                        $migrationContext->getRunUuid(),
                        $entityName,
                        $queryStruct->getTableName(),
                        $queryStruct->getCondition(),
                        (string) $exception->getCode(),
                        $exception->getMessage()
                    ));
                }
            }

            $totals[$entityName] = new TotalStruct($entityName, $total);
        }

        $this->loggingService->saveLogging($context);

        return $totals;
    }

    /**
     * @param DataSet[] $dataSets
     *
     * @return CountingInformationStruct[]
     */
    private function getCountingInformation(array $dataSets): array
    {
        $countingInformation = [];

        foreach ($dataSets as $dataSet) {
            if ($dataSet->getCountingInformation() !== null) {
                $countingInformation[] = $dataSet->getCountingInformation();
            }
        }

        return $countingInformation;
    }
}
