<?php declare(strict_types=1);

namespace WizmoGmbh\IvyPayment\Migration;

use Doctrine\DBAL\Connection;
use Shopware\Core\Framework\Migration\MigrationStep;

class Migration1657212687 extends MigrationStep
{
    public function getCreationTimestamp(): int
    {
        return 1657212687;
    }

    /**
     * @param Connection $connection
     * @return void
     * @throws \Doctrine\DBAL\Exception
     */
    public function update(Connection $connection): void
    {
        $sql = 'ALTER TABLE `wizmogmbh_ivypayment` ADD `express_temp_data` JSON NOT NULL AFTER `ivy_co2Grams`;';
        $connection->executeStatement($sql);
    }

    public function updateDestructive(Connection $connection): void
    {
        // implement update destructive
    }
}
