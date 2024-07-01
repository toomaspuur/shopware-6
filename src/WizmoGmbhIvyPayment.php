<?php

declare(strict_types=1);

/*
 * (c) WIZMO GmbH <plugins@shopentwickler.berlin>
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace WizmoGmbh\IvyPayment;

use Doctrine\DBAL\Connection;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\Plugin;
use Shopware\Core\Framework\Plugin\Context\ActivateContext;
use Shopware\Core\Framework\Plugin\Context\DeactivateContext;
use Shopware\Core\Framework\Plugin\Context\InstallContext;
use Shopware\Core\Framework\Plugin\Context\UninstallContext;
use Shopware\Core\Framework\Plugin\Context\UpdateContext;
use Shopware\Core\Framework\Plugin\Util\PluginIdProvider;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use WizmoGmbh\IvyPayment\PaymentHandler\IvyPaymentHandler;
use WizmoGmbh\IvyPayment\Setup\DataHolder\Tables;
use WizmoGmbh\IvyPayment\Setup\Uninstaller;

class WizmoGmbhIvyPayment extends Plugin
{
    public function install(InstallContext $context): void
    {
        $this->addPaymentMethod($context->getContext());
    }

    public function activate(ActivateContext $context): void
    {
        $this->setPaymentMethodIsActive(true, $context->getContext());
        parent::activate($context);
    }

    public function deactivate(DeactivateContext $context): void
    {
        $this->setPaymentMethodIsActive(false, $context->getContext());
        parent::deactivate($context);
    }

    public function uninstall(UninstallContext $context): void
    {
        $tables = Tables::$tables;
        $this->getUninstaller()->uninstall($context, $tables);

        // Only set the payment method to inactive when uninstalling. Removing the payment method would
        // cause data consistency issues, since the payment method might have been used in several orders
        $this->setPaymentMethodIsActive(false, $context->getContext());
    }

    private function getUninstaller(): Uninstaller
    {
        return new Uninstaller($this->getConnection());
    }

    private function getConnection(): Connection
    {
        $connection = $this->container->get(Connection::class);
        if (!$connection instanceof Connection) {
            throw new \Exception('DBAL connection service not found!');
        }

        return $connection;
    }

    private function addPaymentMethod(Context $context): void
    {
        /** @var EntityRepository $paymentRepository */
        $paymentRepository = $this->container->get('payment_method.repository');

        /** @var PluginIdProvider $pluginIdProvider */
        $pluginIdProvider = $this->container->get(PluginIdProvider::class);
        $pluginId = $pluginIdProvider->getPluginIdByBaseClass(static::class, $context);

        $paymentMethodExists = $this->getPaymentMethodId();

        if ($paymentMethodExists) {
            $ivyPaymentData = [
                'id' => $paymentMethodExists,
                'pluginId' => $pluginId,
            ];
            $paymentRepository->update([$ivyPaymentData], $context);
            return;
        }


        $ivyPaymentData = [
            // payment handler will be selected by the identifier
            'handlerIdentifier' => IvyPaymentHandler::class,
            'name' => 'IvyPayment',
            'description' => 'Ivy - Payments with Impact',
            'pluginId' => $pluginId,
        ];

        $paymentRepository->create([$ivyPaymentData], $context);
    }

    private function setPaymentMethodIsActive(bool $active, Context $context): void
    {
        /** @var EntityRepository $paymentRepository */
        $paymentRepository = $this->container->get('payment_method.repository');

        $paymentMethodId = $this->getPaymentMethodId();

        // Payment does not even exist, so nothing to (de-)activate here
        if (!$paymentMethodId) {
            return;
        }

        $paymentMethod = [
            'id' => $paymentMethodId,
            'active' => $active,
        ];

        $paymentRepository->update([$paymentMethod], $context);
    }

    private function getPaymentMethodId(): ?string
    {
        /** @var EntityRepository $paymentRepository */
        $paymentRepository = $this->container->get('payment_method.repository');

        // Fetch ID for update
        $paymentCriteria = (new Criteria())->addFilter(new EqualsFilter('handlerIdentifier', IvyPaymentHandler::class));

        return $paymentRepository->searchIds($paymentCriteria, Context::createDefaultContext())->firstId();
    }
}
