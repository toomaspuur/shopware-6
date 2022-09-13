<?php declare(strict_types=1);

namespace WizmoGmbh\IvyPayment\Migration;

use Doctrine\DBAL\Connection;
use Shopware\Core\Framework\Migration\MigrationStep;

class Migration1658008261 extends MigrationStep
{
    public function getCreationTimestamp(): int
    {
        return 1658008261;
    }

    /**
     * @param Connection $connection
     * @return void
     * @throws \Doctrine\DBAL\Exception
     */
    public function update(Connection $connection): void
    {
        $sql = '
            ALTER TABLE `wizmogmbh_ivypayment` MODIFY `sw_order_id` BINARY(16) NULL DEFAULT NULL;
            ALTER TABLE `wizmogmbh_ivypayment` MODIFY `express_temp_data` JSON NULL DEFAULT NULL;
        ';
        $connection->executeStatement($sql);
    }

    public function updateDestructive(Connection $connection): void
    {
        // implement update destructive
    }
}
