<?php

declare(strict_types=1);

/*
 * (c) WIZMO GmbH <plugins@shopentwickler.berlin>
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace WizmoGmbh\IvyPayment\Core\Checkout\Order;

use Shopware\Core\Framework\DataAbstractionLayer\Entity;
use Shopware\Core\Framework\DataAbstractionLayer\EntityCollection;

/**
 * @method void                         add(IvyPaymentSessionEntity|Entity $entity)
 * @method void                         set(string $key, IvyPaymentSessionEntity|Entity $entity)
 * @method \Generator                   getIterator()
 * @method IvyPaymentSessionEntity[]    getElements()
 * @method IvyPaymentSessionEntity|null get(mixed|null $key)
 * @method IvyPaymentSessionEntity|null first()
 * @method IvyPaymentSessionEntity|null last()
 */
class IvyPaymentSessionCollection extends EntityCollection
{
    protected function getExpectedClass(): string
    {
        return IvyPaymentSessionEntity::class;
    }
}
