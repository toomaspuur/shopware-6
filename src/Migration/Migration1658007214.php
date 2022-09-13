<?php declare(strict_types=1);

namespace WizmoGmbh\IvyPayment\Migration;

use Doctrine\DBAL\Connection;
use Shopware\Core\Framework\Migration\MigrationStep;

class Migration1658007214 extends MigrationStep
{
    public function getCreationTimestamp(): int
    {
        return 1658007214;
    }

    /**
     * @param Connection $connection
     * @return void
     * @throws \Doctrine\DBAL\Exception
     */
    public function update(Connection $connection): void
    {
        $sql = '
            ALTER TABLE `wizmogmbh_ivypayment` ADD PRIMARY KEY(id);
            ALTER TABLE `wizmogmbh_ivypayment` ADD CONSTRAINT ivy_app_id UNIQUE (app_id);
        ';
        $connection->executeStatement($sql);
    }

    public function updateDestructive(Connection $connection): void
    {
        // implement update destructive
    }
}
