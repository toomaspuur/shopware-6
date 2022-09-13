<?php

declare(strict_types=1);

/*
 * (c) WIZMO GmbH <plugins@shopentwickler.berlin>
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace WizmoGmbh\IvyPayment\Setup;

use Doctrine\DBAL\Connection;
use Shopware\Core\Framework\Plugin\Context\UninstallContext;

class Uninstaller
{
    private Connection $connection;

    public function __construct(Connection $connection)
    {
        $this->connection = $connection;
    }

    public function uninstall(UninstallContext $context, array $tables): void
    {
        if ($context->keepUserData()) {
            return;
        }

        foreach ($tables as $table) {
            if ($this->connection->getSchemaManager()->tablesExist([$table])) {
                $this->connection->getSchemaManager()->dropTable($table);
            }
        }
    }
}
