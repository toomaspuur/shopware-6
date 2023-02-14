<?php

namespace WizmoGmbh\IvyPayment\Core\IvyPayment;

use Doctrine\DBAL\Exception;
use Shopware\Core\Checkout\Cart\Cart;
use Shopware\Core\Checkout\Order\OrderEntity;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepositoryInterface;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\Plugin\PluginEntity;
use Shopware\Core\Framework\Uuid\Uuid;
use Shopware\Core\PlatformRequest;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Symfony\Component\Serializer\Encoder\JsonEncoder;
use Symfony\Component\Serializer\Encoder\XmlEncoder;
use Symfony\Component\Serializer\Serializer;
use WizmoGmbh\IvyPayment\Components\Config\ConfigHandler;
use WizmoGmbh\IvyPayment\Components\CustomObjectNormalizer;
use WizmoGmbh\IvyPayment\Exception\IvyApiException;
use WizmoGmbh\IvyPayment\IvyApi\ApiClient;

class IvyCheckoutSession
{
    private Serializer $serializer;
    private ApiClient $ivyApiClient;

    private createIvyOrderData $createIvyOrderData;

    private ConfigHandler $configHandler;

    private string $version;

    public function __construct(
        EntityRepositoryInterface $pluginRepository,
        ConfigHandler $configHandler,
        createIvyOrderData $createIvyOrderData,
        ApiClient $ivyApiClient
    ) {
        $this->configHandler = $configHandler;
        $this->createIvyOrderData = $createIvyOrderData;
        $this->ivyApiClient = $ivyApiClient;
        $encoders = [new XmlEncoder(), new JsonEncoder()];
        $normalizers = [new CustomObjectNormalizer()];
        $this->serializer = new Serializer($normalizers, $encoders);
        $criteria = new Criteria();
        $criteria->addFilter(new EqualsFilter('name', 'WizmoGmbhIvyPayment'));
        /** @var PluginEntity $plugin */
        $plugin = $pluginRepository->search($criteria, Context::createDefaultContext())->first();
        $this->version = $plugin->getVersion();
    }

    /**
     * @param string $contextToken
     * @param SalesChannelContext $salesChannelContext
     * @param bool $express
     * @param OrderEntity|null $order
     * @return string
     * @throws Exception
     * @throws IvyApiException
     */
    public function createCheckoutSession(string $contextToken, SalesChannelContext $salesChannelContext, bool $express, OrderEntity $order = null, Cart $cart = null): string
    {
        $config = $this->configHandler->getFullConfig($salesChannelContext);
        if ($order) {
            $ivySessionData = $this->createIvyOrderData->getSessionCreateDataFromOrder($order, $config);
            $referenceId = $order->getId();
        } else if ($cart) {
            $ivySessionData = $this->createIvyOrderData->getIvySessionDataFromCart(
                $cart,
                $salesChannelContext,
                $config,
                $express,
                $express
            );
            $referenceId = Uuid::randomHex();
        } else {
            throw new IvyApiException('An order or cart must be provided');
        }

        $ivySessionData->setReferenceId($referenceId);

        //Add the token as sw-context and payment-token
        $ivySessionData->setMetadata([
            PlatformRequest::HEADER_CONTEXT_TOKEN => $contextToken,
            '_sw_payment_token' => $contextToken,
        ]);
        // add plugin version as string to know whether to redirect to confirmation page after ivy checkout
        $ivySessionData->setPlugin('sw6-' . $this->version);

        $jsonContent = $this->serializer->serialize($ivySessionData, 'json');
        $response = $this->ivyApiClient->sendApiRequest('checkout/session/create', $config, $jsonContent);


        if (empty($response['redirectUrl'])) {
            throw new IvyApiException('cannot obtain ivy redirect url');
        }

        return $response['redirectUrl'];
    }
}