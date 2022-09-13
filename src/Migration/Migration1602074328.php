<?php declare(strict_types=1);
/*
 * (c) WIZMO GmbH <plugins@shopentwickler.berlin>
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace WizmoGmbh\IvyPayment\Migration;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception;
use Shopware\Core\Framework\Migration\InheritanceUpdaterTrait;
use Shopware\Core\Framework\Migration\MigrationStep;

class Migration1602074328 extends MigrationStep
{
    use InheritanceUpdaterTrait;

    public function getCreationTimestamp(): int
    {
        return 1602074328;
    }

    /**
     * @throws Exception
     */
    public function update(Connection $connection): void
    {
        $this->runQuery($connection);
    }

    public function updateDestructive(Connection $connection): void
    {
    }

    /**
     * @throws Exception
     */
    private function runQuery(Connection $connection): void
    {
        $query = <<<SQL
                CREATE TABLE `wizmogmbh_ivypayment` (
                    `id` BINARY(16) NOT NULL,
                    `status` VARCHAR(255) NULL DEFAULT NULL COLLATE 'utf8mb4_unicode_ci',
                    `sw_order_id` BINARY(16) NOT NULL,
                    `ivy_order_id` VARCHAR(255) NULL DEFAULT NULL COLLATE 'utf8mb4_unicode_ci',
                    `app_id` VARCHAR(255) NULL DEFAULT NULL COLLATE 'utf8mb4_unicode_ci',
                    `ivy_co2Grams` VARCHAR(255) NULL DEFAULT '' COLLATE 'utf8mb4_unicode_ci',
                    `created_at` DATETIME(3) NOT NULL,
                    `updated_at` DATETIME(3) NULL DEFAULT NULL
                )
                COLLATE='utf8mb4_unicode_ci'
                ENGINE=InnoDB
                ;
            COMMIT;
        SQL;

        $connection->executeStatement($query);
    }
}
