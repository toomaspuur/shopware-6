<?php

declare(strict_types=1);

namespace WizmoGmbh\IvyPayment\Subscriber;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception;
use Shopware\Core\Defaults;
use Shopware\Core\Framework\Uuid\Uuid;
use Shopware\Core\System\SalesChannel\Context\AbstractSalesChannelContextFactory;
use Shopware\Storefront\Framework\Routing\Router;
use Shopware\Storefront\Framework\Twig\TemplateConfigAccessor;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Routing\RouterInterface;
use WizmoGmbh\IvyPayment\Components\Config\ConfigHandler;
use WizmoGmbh\IvyPayment\Exception\IvyApiException;
use WizmoGmbh\IvyPayment\IvyApi\ApiClient;
use Symfony\Component\HttpFoundation\Response;

class ConfigSubscriber implements EventSubscriberInterface
{
    private ConfigHandler $configHandler;

    private RouterInterface $router;

    private ApiClient $ivyApiClient;

    private TemplateConfigAccessor $templateConfigAccessor;

    private AbstractSalesChannelContextFactory $salesChannelContextFactory;

    private Connection $connection;

    /**
     * @param ConfigHandler $configHandler
     * @param RouterInterface $router
     * @param ApiClient $ivyApiClient
     * @param TemplateConfigAccessor $templateConfigAccessor
     * @param AbstractSalesChannelContextFactory $salesChannelContextFactory
     * @param Connection $connection
     */
    public function __construct(
        ConfigHandler $configHandler,
        RouterInterface $router,
        ApiClient $ivyApiClient,
        TemplateConfigAccessor $templateConfigAccessor,
        AbstractSalesChannelContextFactory $salesChannelContextFactory,
        Connection $connection
    )
    {
        $this->configHandler = $configHandler;
        $this->router = $router;
        $this->ivyApiClient = $ivyApiClient;
        $this->templateConfigAccessor = $templateConfigAccessor;
        $this->salesChannelContextFactory = $salesChannelContextFactory;
        $this->connection = $connection;
    }

    public static function getSubscribedEvents(): array
    {
        return [
            // Ensure event is executed before "Shopware\Core\System\SalesChannel\Api\StoreApiResponseListener".
            KernelEvents::RESPONSE => ['onResponse', 11000],
        ];
    }

    /**
     * @param ResponseEvent $event
     * @return void
     * @throws \Exception
     */
    public function onResponse(ResponseEvent $event): void
    {
        $request = $event->getRequest();
        if ($request->attributes->get('_route') !== 'api.action.core.save.system-config.batch') {
            return;
        }
        foreach ($request->request->all() as $salesChannelId => $kvs) {
            if ($salesChannelId === 'null') {
                $salesChannelId = null;
            }
            $errors = [];
            if (isset ($kvs['WizmoGmbhIvyPayment.config.ProductionIvyApiKey']) && (string)$kvs['WizmoGmbhIvyPayment.config.ProductionIvyApiKey'] !== '') {
                try {
                    $this->updateMerchant($salesChannelId, false);
                } catch (\Exception $e) {
                    $errors[] = $e->getMessage();
                }
            }

            if (isset ($kvs['WizmoGmbhIvyPayment.config.SandboxIvyApiKey']) && (string)$kvs['WizmoGmbhIvyPayment.config.SandboxIvyApiKey'] !== '') {
                try {
                    $this->updateMerchant($salesChannelId, true);
                } catch (\Exception $e) {
                    $errors[] = $e->getMessage();
                }
            }
            if (!empty($errors)) {
                $event->setResponse(new JsonResponse([
                    'errors' => \implode('; ', $errors),
                ], Response::HTTP_BAD_REQUEST));
            }
        }
    }

    /**
     * @param string|null $salesChannelId
     * @param bool $isSandBox
     * @return void
     * @throws Exception
     * @throws IvyApiException
     */
    private function updateMerchant(?string $salesChannelId, bool $isSandBox): void
    {
        $config = $this->configHandler->getFullConfigBySalesChannelId($salesChannelId, $isSandBox, true);
        $quoteCallbackUrl = $this->router->generate('frontend.ivyexpress.callback', [], Router::ABSOLUTE_URL);
        $successCallbackUrl = $this->router->generate('frontend.ivypayment.finalize.transaction', [], Router::ABSOLUTE_URL);
        $errorCallbackUrl = $this->router->generate('frontend.ivypayment.failed.transaction', [], Router::ABSOLUTE_URL);
        $webhookUrl = $this->router->generate('ivypayment.update.transaction', [], Router::ABSOLUTE_URL);
        $privacyUrl = $this->router->generate('frontend.cms.page', ['id' => $config['privacyPage']], Router::ABSOLUTE_URL);
        $tosUrl = $this->router->generate('frontend.cms.page', ['id' => $config['tosPage']], Router::ABSOLUTE_URL);
        $completeCallbackUrl = $this->router->generate('frontend.ivyexpress.confirm', [], Router::ABSOLUTE_URL);

        if (!$salesChannelId) {
            $salesChannelId = $this->connection->createQueryBuilder()
                ->select('LOWER(HEX(s.id))')
                ->from('sales_channel', 's')
                ->where('s.active = 1')
                ->andWhere('s.type_id = UNHEX(:salesChannelType)')
                ->setParameter('salesChannelType', Defaults::SALES_CHANNEL_TYPE_STOREFRONT)
                ->execute()
                ->fetchOne();
        }

        $salesChannelContext = $this->salesChannelContextFactory->create(Uuid::randomHex(), $salesChannelId, []);
        $themeId = $this->connection->createQueryBuilder()
            ->select('LOWER(HEX(t.theme_id))')
            ->from('theme_sales_channel', 't')
            ->where('t.sales_channel_id = UNHEX(:salesChannelId)')
            ->setParameter('salesChannelId', $salesChannelId)
            ->execute()
            ->fetchOne();

        $logoUrl = $this->templateConfigAccessor->theme('sw-logo-tablet', $salesChannelContext, $themeId);

        $jsonContent = \json_encode([
            'quoteCallbackUrl' => $quoteCallbackUrl,
            'successCallbackUrl' => $successCallbackUrl,
            'errorCallbackUrl' => $errorCallbackUrl,
            'completeCallbackUrl' => $completeCallbackUrl,
            'webhookUrl' => $webhookUrl,
            'privacyUrl' => $privacyUrl,
            'tosUrl' => $tosUrl,
            'shopLogo' => $logoUrl,
        ]);
        $this->ivyApiClient->sendApiRequest('merchant/update', $config, $jsonContent);
    }
}
