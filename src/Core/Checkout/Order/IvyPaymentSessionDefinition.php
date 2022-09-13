<?php

declare(strict_types=1);

/*
 * (c) WIZMO GmbH <plugins@shopentwickler.berlin>
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace WizmoGmbh\IvyPayment\Core\Checkout\Order;

use Shopware\Core\Checkout\Order\OrderDefinition;
use Shopware\Core\Framework\DataAbstractionLayer\EntityDefinition;
use Shopware\Core\Framework\DataAbstractionLayer\Field\FkField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\Flag\ApiAware;
use Shopware\Core\Framework\DataAbstractionLayer\Field\Flag\PrimaryKey;
use Shopware\Core\Framework\DataAbstractionLayer\Field\Flag\Required;
use Shopware\Core\Framework\DataAbstractionLayer\Field\IdField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\JsonField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\LongTextField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\StringField;
use Shopware\Core\Framework\DataAbstractionLayer\FieldCollection;

class IvyPaymentSessionDefinition extends EntityDefinition
{
    public const ENTITY_NAME = 'wizmogmbh_ivypayment';

    public function getEntityName(): string
    {
        return self::ENTITY_NAME;
    }

    public function getCollectionClass(): string
    {
        return IvyPaymentSessionCollection::class;
    }

    public function getEntityClass(): string
    {
        return IvyPaymentSessionEntity::class;
    }

    protected function defineFields(): FieldCollection
    {
        return new FieldCollection([
            (new IdField('id', 'id'))->addFlags(new Required(), new PrimaryKey()),
            (new StringField('status', 'status'))->addFlags(new Required()),
            (new FkField('sw_order_id', 'swOrderId', OrderDefinition::class))->addFlags(new ApiAware()),
            (new StringField('app_id', 'ivySessionId')),
            (new StringField('ivy_order_id', 'ivyOrderId')),
            (new LongTextField('ivy_co2Grams', 'ivyCo2Grams')),
            (new JsonField('express_temp_data', 'expressTempData'))
        ]);
    }
}
