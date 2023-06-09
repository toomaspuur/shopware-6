<?php

declare(strict_types=1);

/*
 * (c) WIZMO GmbH <plugins@shopentwickler.berlin>
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace WizmoGmbh\IvyPayment\Subscriber;

use Shopware\Core\Checkout\Payment\PaymentMethodEntity;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Storefront\Page\Checkout\Cart\CheckoutCartPageLoadedEvent;
use Shopware\Storefront\Page\Checkout\Confirm\CheckoutConfirmPageLoadedEvent;
use Shopware\Storefront\Page\Checkout\Offcanvas\OffcanvasCartPageLoadedEvent;
use Shopware\Storefront\Page\Checkout\Register\CheckoutRegisterPageLoadedEvent;
use Shopware\Storefront\Page\PageLoadedEvent;
use Shopware\Storefront\Page\Product\ProductPageLoadedEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use WizmoGmbh\IvyPayment\Components\Config\ConfigHandler;
use WizmoGmbh\IvyPayment\PaymentHandler\IvyPaymentHandler;

class ButtonSubscriber implements EventSubscriberInterface
{
    private ConfigHandler $configHandler;

    private EntityRepository $salesChannelRepository;

    public function __construct(
        ConfigHandler $configHandler,
        EntityRepository $salesChannelRepository
    ) {
        $this->configHandler = $configHandler;
        $this->salesChannelRepository = $salesChannelRepository;
    }

    public static function getSubscribedEvents(): array
    {
        return [
            ProductPageLoadedEvent::class => 'onIvyButtonLoaded',
            CheckoutCartPageLoadedEvent::class => 'onIvyButtonLoaded',
            CheckoutConfirmPageLoadedEvent::class => 'onIvyButtonLoaded',
            OffcanvasCartPageLoadedEvent::class => 'onIvyButtonLoaded',
            CheckoutRegisterPageLoadedEvent::class => 'onIvyButtonLoaded',
        ];
    }

    /**
     * @param PageLoadedEvent $event
     * @return void
     * @throws \Doctrine\DBAL\Exception
     */
    public function onIvyButtonLoaded(PageLoadedEvent $event): void
    {
        $paymentMethods = $event->getPage()->getSalesChannelPaymentMethods();
        if ($paymentMethods === null) {
            $criteria = new Criteria([$event->getSalesChannelContext()->getSalesChannel()->getId()]);
            $criteria->addAssociation('paymentMethods');
            $loadedSalesChannel = $this->salesChannelRepository->search($criteria, $event->getContext())->first();
            if ($loadedSalesChannel !== null) {
                $paymentMethods = $loadedSalesChannel->getPaymentMethods();
            }
        }
        if ($paymentMethods !== null) {
            /** @var PaymentMethodEntity $paymentMethod */
            foreach ($paymentMethods as $paymentMethod) {
                if ($paymentMethod->getActive() && $paymentMethod->getHandlerIdentifier() === IvyPaymentHandler::class) {
                    $config = $this->configHandler->getFullConfig($event->getSalesChannelContext());
                    $event->getPage()->assign($config);
                    break;
                }
            }
        }
        $config = $this->configHandler->getFullConfig($event->getSalesChannelContext());
        $event->getPage()->assign($config);
    }
}
