<?php
declare(strict_types=1);


namespace WizmoGmbh\IvyPayment\Express\Subscriber;

use Shopware\Storefront\Framework\Routing\Router;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use WizmoGmbh\IvyPayment\Express\Service\ExpressService;

class KernelSubscriber implements EventSubscriberInterface
{
    private ExpressService $expressService;

    /**
     * @param ExpressService $expressService
     */
    public function __construct(ExpressService $expressService)
    {
        $this->expressService = $expressService;
    }

    /**
     * @return array[]
     */
    public static function getSubscribedEvents(): array
    {
        return [
            RequestEvent::class => ['onRequestEvent', 99999],
        ];
    }

    /**
     * @param RequestEvent $event
     * @return void
     */
    public function onRequestEvent(RequestEvent $event): void
    {
        $quoteCallbackUri = $this->expressService->getCallbackUri(Router::RELATIVE_PATH);
        if (\str_ends_with($event->getRequest()->getPathInfo(), $quoteCallbackUri) === true) {
            $this->expressService->restoreShopwareSession($event->getRequest());
        }
    }
}
