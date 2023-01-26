<?php declare(strict_types=1);

namespace WizmoGmbh\IvyPayment\Express\Service;

use Doctrine\DBAL\Exception;
use Monolog\Logger;
use Shopware\Core\Checkout\Cart\Exception\OrderNotFoundException;
use Shopware\Core\Checkout\Cart\LineItem\LineItem;
use Shopware\Core\Checkout\Cart\SalesChannel\CartService;
use Shopware\Core\Checkout\Order\OrderEntity;
use Shopware\Core\Checkout\Order\SalesChannel\AbstractOrderRoute;
use Shopware\Core\Checkout\Promotion\Cart\PromotionItemBuilder;
use Shopware\Core\Checkout\Shipping\SalesChannel\AbstractShippingMethodRoute;
use Shopware\Core\Checkout\Shipping\ShippingMethodCollection;
use Shopware\Core\Checkout\Shipping\ShippingMethodEntity;
use Shopware\Core\Content\Newsletter\Exception\SalesChannelDomainNotFoundException;
use Shopware\Core\Framework\Api\Controller\SalesChannelProxyController;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepositoryInterface;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Sorting\FieldSorting;
use Shopware\Core\Framework\Plugin\PluginEntity;
use Shopware\Core\Framework\Uuid\Uuid;
use Shopware\Core\Framework\Validation\DataBag\DataBag;
use Shopware\Core\PlatformRequest;
use Shopware\Core\System\SalesChannel\Context\SalesChannelContextServiceInterface;
use Shopware\Core\System\SalesChannel\Context\SalesChannelContextServiceParameters;
use Shopware\Core\System\SalesChannel\Entity\SalesChannelRepositoryInterface;
use Shopware\Core\System\SalesChannel\SalesChannel\SalesChannelContextSwitcher;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Core\System\SalesChannel\SalesChannelEntity;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use Shopware\Storefront\Framework\Routing\Router;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\RouterInterface;
use Symfony\Component\Serializer\Encoder\JsonEncoder;
use Symfony\Component\Serializer\Encoder\XmlEncoder;
use Symfony\Component\Serializer\Serializer;
use WizmoGmbh\IvyPayment\Components\Config\ConfigHandler;
use WizmoGmbh\IvyPayment\Components\CustomObjectNormalizer;
use WizmoGmbh\IvyPayment\Core\Checkout\Order\IvyPaymentSessionEntity;
use WizmoGmbh\IvyPayment\Core\IvyPayment\createIvyOrderData;
use WizmoGmbh\IvyPayment\Exception\IvyApiException;
use WizmoGmbh\IvyPayment\Exception\IvyException;
use WizmoGmbh\IvyPayment\IvyApi\ApiClient;
use WizmoGmbh\IvyPayment\PaymentHandler\IvyPaymentHandler;

class ExpressService
{
    private SystemConfigService $systemConfigService;

    private EntityRepositoryInterface $salesChannelRepo;

    private EntityRepositoryInterface $paymentRepository;

    private CartService $cartService;

    private ConfigHandler $configHandler;

    private RouterInterface $router;

    private createIvyOrderData $createIvyOrderData;

    private Serializer $serializer;

    private EntityRepositoryInterface $ivyPaymentSessionRepository;

    private ApiClient $ivyApiClient;

    private SalesChannelContextSwitcher $channelContextSwitcher;

    private SalesChannelContextServiceInterface $contextService;

    private PromotionItemBuilder $promotionItemBuilder;

    private Logger $logger;

    private SalesChannelRepositoryInterface $countryRepository;

    private AbstractShippingMethodRoute $shippingMethodRoute;

    private SalesChannelProxyController $salesChannelProxyController;

    private AbstractOrderRoute $orderRoute;

    private EntityRepositoryInterface $orderRepository;

    private string $version;


    /**
     * @param EntityRepositoryInterface $salesChannelRepo
     * @param EntityRepositoryInterface $paymentRepository
     * @param EntityRepositoryInterface $orderRepository
     * @param EntityRepositoryInterface $pluginRepository
     * @param CartService $cartService
     * @param SystemConfigService $systemConfigService
     * @param ConfigHandler $configHandler
     * @param RouterInterface $router
     * @param createIvyOrderData $createIvyOrderData
     * @param EntityRepositoryInterface $ivyPaymentSessionRepository
     * @param SalesChannelRepositoryInterface $countryRepository
     * @param ApiClient $ivyApiClient
     * @param SalesChannelContextSwitcher $channelContextSwitcher
     * @param SalesChannelContextServiceInterface $contextService
     * @param PromotionItemBuilder $promotionItemBuilder
     * @param AbstractShippingMethodRoute $shippingMethodRoute
     * @param SalesChannelProxyController $salesChannelProxyController
     * @param Logger $logger
     */
    public function __construct(
        EntityRepositoryInterface $salesChannelRepo,
        EntityRepositoryInterface $paymentRepository,
        EntityRepositoryInterface $orderRepository,
        EntityRepositoryInterface $pluginRepository,
        CartService $cartService,
        SystemConfigService $systemConfigService,
        ConfigHandler $configHandler,
        RouterInterface $router,
        createIvyOrderData $createIvyOrderData,
        EntityRepositoryInterface $ivyPaymentSessionRepository,
        SalesChannelRepositoryInterface $countryRepository,
        ApiClient $ivyApiClient,
        SalesChannelContextSwitcher $channelContextSwitcher,
        SalesChannelContextServiceInterface $contextService,
        PromotionItemBuilder $promotionItemBuilder,
        AbstractShippingMethodRoute $shippingMethodRoute,
        SalesChannelProxyController $salesChannelProxyController,
        Logger $logger
    ) {
        $this->systemConfigService = $systemConfigService;
        $this->salesChannelRepo = $salesChannelRepo;
        $this->paymentRepository = $paymentRepository;
        $this->cartService = $cartService;
        $this->configHandler = $configHandler;
        $this->router = $router;
        $this->createIvyOrderData = $createIvyOrderData;
        $this->ivyPaymentSessionRepository = $ivyPaymentSessionRepository;
        $this->ivyApiClient = $ivyApiClient;
        $encoders = [new XmlEncoder(), new JsonEncoder()];
        $normalizers = [new CustomObjectNormalizer()];
        $this->serializer = new Serializer($normalizers, $encoders);
        $this->channelContextSwitcher = $channelContextSwitcher;
        $this->contextService = $contextService;
        $this->promotionItemBuilder = $promotionItemBuilder;
        $this->logger = $logger;
        $this->countryRepository = $countryRepository;
        $this->shippingMethodRoute = $shippingMethodRoute;
        $this->salesChannelProxyController = $salesChannelProxyController;
        $this->orderRepository = $orderRepository;
        $criteria = new Criteria();
        $criteria->addFilter(new EqualsFilter('name', 'WizmoGmbhIvyPayment'));
        /** @var PluginEntity $plugin */
        $plugin = $pluginRepository->search($criteria, Context::createDefaultContext())->first();
        $this->version = $plugin->getVersion();
    }

    /**
     * @param string $endpoint
     * @param string $method
     * @param string $contextToken
     * @param SalesChannelContext $salesChannelContext
     * @param array $data
     * @return JsonResponse
     */
    public function storeProxy(
        string $endpoint,
        string $method,
        string $contextToken,
        SalesChannelContext $salesChannelContext,
        array $data = []
    ) : JsonResponse
    {
        $request = new Request([], $data);
        $request->setMethod($method);
        $request->headers->set(PlatformRequest::HEADER_CONTEXT_TOKEN, $contextToken);
        /** @var JsonResponse $response */
        $response = $this->salesChannelProxyController->proxy($endpoint,
            $salesChannelContext->getSalesChannelId(),
            $request,
            $salesChannelContext->getContext()
        );
        return $response;
    }

    /**
     * @param SalesChannelContext $salesChannelContext
     * @return SalesChannelContext
     */
    public function switchPaymentMethod(SalesChannelContext $salesChannelContext): SalesChannelContext
    {
        $this->logger->info('set ivy payment method');
        $switchData = [
            'paymentMethodId'  => $this->getPaymentMethodId(),
        ];
        try {
            $this->channelContextSwitcher->update(new DataBag($switchData), $salesChannelContext);
        } catch (\Exception $e) {
            $this->logger->warning('shipping not allowed. skip.');
        }
        return $this->reloadContext($salesChannelContext);
    }

    /**
     * @param SalesChannelContext $salesChannelContext
     * @param $outputData
     * @return void
     * @throws Exception
     */
    public function getAllShippingVariants(SalesChannelContext $salesChannelContext, &$outputData): void
    {
        $this->logger->info('getAllShippingVariants for context token: ' . $salesChannelContext->getToken());
        $criteria = new Criteria([$salesChannelContext->getSalesChannelId()]);
        $criteria->addAssociations(['countries', 'country', 'shippingMethods']);
        /** @var SalesChannelEntity $salesChannelLoaded */
        $salesChannelLoaded = $this->salesChannelRepo->search($criteria, $salesChannelContext->getContext())->first();
        $countries = [];
        foreach ($salesChannelLoaded->getCountries() as $country) {
            $countries[] = $country->getIso();
        }
        $shippingMethods = [];

        $allowedShippingMethod = $this->getShippingMethods($salesChannelContext);
        /** @var ShippingMethodEntity $shippingMethod */
        foreach ($allowedShippingMethod as $shippingMethod) {
            $this->logger->info('check shippping method: ' . $shippingMethod->getName() . ' id: ' . $shippingMethod->getId());
            $switchData = [
                'countryId'        => $salesChannelLoaded->getCountry()->getId(),
                'paymentMethodId'  => $this->getPaymentMethodId(),
                'shippingMethodId' => $shippingMethod->getId()
            ];

            try {
                $this->channelContextSwitcher->update(new DataBag($switchData), $salesChannelContext);
            } catch (\Exception $e) {
                $this->logger->warning('shipping not allowed. skip.');
                continue;
            }
            $salesChannelContext = $this->reloadContext($salesChannelContext);

            $cart = $this->cartService->getCart($salesChannelContext->getToken(), $salesChannelContext);
            $delivery = $cart->getDeliveries()->first();
            if ($delivery) {
                $shippingMethods[] = [
                    'price'     => \round($delivery->getShippingCosts()->getTotalPrice(), 2),
                    'name'      => $shippingMethod->getName(),
                    'reference' => $shippingMethod->getId(),
                    'countries' => $countries,
                ];
            } else {
                $this->logger->info('not delivery found in cart. skip.');
            }
        }
        $this->logger->debug('allowed shippings: ' . \print_r($shippingMethods, true));
        $outputData['shippingMethods'] = $shippingMethods;
        /* TEMP
        $cart = $this->cartService->getCart($salesChannelContext->getToken(), $salesChannelContext);
        $config = $this->configHandler->getFullConfig($salesChannelContext);
        $ivyExpressSessionData = $this->createIvyOrderData->getIvySessionDataFromCart(
            $cart,
            $salesChannelContext,
            $config,
            true,
            true
        );
        $price = $ivyExpressSessionData->getPrice();
        $outputData['price']['totalNet'] = $price->getTotalNet();
        $outputData['price']['vat'] = $price->getVat();
        $outputData['price']['total'] = $price->getTotal();
        */
    }

    /**
     * @param SalesChannelContext $context
     * @return ShippingMethodCollection
     */
    private function getShippingMethods(SalesChannelContext $context): ShippingMethodCollection
    {
        $request = new Request();
        $request->query->set('onlyAvailable', '1');

        $shippingMethods = $this->shippingMethodRoute
            ->load($request, $context, new Criteria())
            ->getShippingMethods();

        if (!$shippingMethods->has($context->getShippingMethod()->getId())) {
            $shippingMethods->add($context->getShippingMethod());
        }
        return $shippingMethods->filterByActiveRules($context);
    }

    /**
     * @param SalesChannelContext $salesChannelContext
     * @param string|null $contextToken
     * @return SalesChannelContext
     */
    public function reloadContext(SalesChannelContext $salesChannelContext, string $contextToken = null): SalesChannelContext
    {
        return $this->contextService->get(
            new SalesChannelContextServiceParameters(
                $salesChannelContext->getSalesChannelId(),
                $contextToken ?? $salesChannelContext->getToken(),
                $salesChannelContext->getLanguageId(),
                $salesChannelContext->getCurrencyId(),
                $salesChannelContext->getDomainId(),
            )
        );
    }

    /**
     * @param array $shipping
     * @param SalesChannelContext $salesChannelContext
     * @return bool
     */
    public function switchShippingContext(array $shipping, SalesChannelContext $salesChannelContext): bool
    {
        $countryIso = $shipping['country'] ?? '';
        $this->logger->info('try to switch country context to ' . $countryIso);
        $criteria = new Criteria();
        $criteria
            ->addFilter(new EqualsFilter('country.active', true))
            ->addFilter(new EqualsFilter('iso', $countryIso));
        $countryId = $this->countryRepository->searchIds($criteria, $salesChannelContext)->firstId();
        if ($countryId === null) {
            $this->logger->warning('country with iso ' . $countryIso . ' not found');
            return false;
        }
        $this->logger->info('found country id ' . $countryId);
        $switchData = [
            'countryId'       => $countryId,
            'paymentMethodId' => $this->getPaymentMethodId(),
        ];
        $this->channelContextSwitcher->update(new DataBag($switchData), $salesChannelContext);
        return true;
    }

    /**
     * @param Request $request
     * @param SalesChannelContext $salesChannelContext
     * @return string
     * @throws IvyApiException
     * @throws Exception
     */
    public function createNormalSession(Request $request, SalesChannelContext $salesChannelContext): string
    {
        $config = $this->configHandler->getFullConfig($salesChannelContext);
        $token = $salesChannelContext->getToken();
        $cart = $this->cartService->getCart($token, $salesChannelContext);
        $ivySessionData = $this->createIvyOrderData->getIvySessionDataFromCart(
            $cart,
            $salesChannelContext,
            $config,
            false,
            false
        );
        $referenceId = Uuid::randomHex();
        $ivySessionData->setReferenceId($referenceId);
        $contextToken = $request->getSession()->get(PlatformRequest::HEADER_CONTEXT_TOKEN);
        $ivySessionData->setMetadata([
            PlatformRequest::HEADER_CONTEXT_TOKEN => $contextToken
        ]);
        // add plugin version as string to know whether to redirect to confirmation page after ivy checkout
        $ivySessionData->setPlugin('sw6-' . $this->version);

        $jsonContent = $this->serializer->serialize($ivySessionData, 'json');
        $response = $this->ivyApiClient->sendApiRequest('checkout/session/create', $config, $jsonContent);


        if (empty($response['redirectUrl'])) {
            throw new IvyApiException('cannot obtain ivy redirect url');
        }

        $tempData = [
            'express'                             => false,
            PlatformRequest::HEADER_CONTEXT_TOKEN => $contextToken,
            'sessionId'                           => $request->getSession()->getId(),
        ];

        $this->ivyPaymentSessionRepository->upsert([
            [
                'id'              => $referenceId,
                'status'          => 'initConfirm',
                'swOrderId'       => null,
                'ivySessionId'    => $response['id'],
                'ivyCo2Grams'     => (string)($response['co2Grams'] ?? ''),
                'expressTempData' => $tempData
            ],
        ], $salesChannelContext->getContext());

        return $response['redirectUrl'];
    }

    /**
     * @param Request $request
     * @param SalesChannelContext $salesChannelContext
     * @return string
     * @throws IvyApiException
     * @throws Exception
     */
    public function createExpressSession(Request $request, SalesChannelContext $salesChannelContext): string
    {
        $config = $this->configHandler->getFullConfig($salesChannelContext);
        $token = $salesChannelContext->getToken();
        $cart = $this->cartService->getCart($token, $salesChannelContext);

        $ivyExpressSessionData = $this->createIvyOrderData->getIvySessionDataFromCart(
            $cart,
            $salesChannelContext,
            $config,
            true,
            true
        );

        
        $referenceId = Uuid::randomHex();
        $ivyExpressSessionData->setReferenceId($referenceId);
        $contextToken = $request->getSession()->get(PlatformRequest::HEADER_CONTEXT_TOKEN);
        $ivyExpressSessionData->setMetadata([
            PlatformRequest::HEADER_CONTEXT_TOKEN => $contextToken
        ]);
        // add plugin version as string to know whether to redirect to confirmation page after ivy checkout
        $ivyExpressSessionData->setPlugin('sw6-' . $this->version);

        $jsonContent = $this->serializer->serialize($ivyExpressSessionData, 'json');
        $response = $this->ivyApiClient->sendApiRequest('checkout/session/create', $config, $jsonContent);


        if (empty($response['redirectUrl'])) {
            throw new IvyApiException('cannot obtain ivy redirect url');
        }

        $tempData = [
            'express'                             => true,
            PlatformRequest::HEADER_CONTEXT_TOKEN => $contextToken,
            'sessionId'                           => $request->getSession()->getId(),
        ];

        $this->ivyPaymentSessionRepository->upsert([
            [
                'id'              => $referenceId,
                'status'          => 'initExpress',
                'swOrderId'       => null,
                'ivySessionId'    => $response['id'],
                'ivyCo2Grams'     => (string)($response['co2Grams'] ?? ''),
                'expressTempData' => $tempData
            ],
        ], $salesChannelContext->getContext());

        return $response['redirectUrl'];
    }

    /**
     * @param string $referenceId
     * @return IvyPaymentSessionEntity|null
     */
    public function getIvySessionByReference(string $referenceId): ?IvyPaymentSessionEntity
    {
        $context = Context::createDefaultContext();
        if (\mb_strlen($referenceId, '8bit') !== 32 || !\preg_match('/' . Uuid::VALID_PATTERN . '/', $referenceId)) {
            // if reference with ordernumber updated
            $criteria = new Criteria();
            $criteria->addFilter(new EqualsFilter('orderNumber', $referenceId));
            /** @var OrderEntity|null $orderEntity */
            $orderEntity = $this->orderRepository->search($criteria, $context)->first();
            if ($orderEntity === null) {
                return null;
            }
            $criteria = new Criteria();
            $criteria->addFilter(new EqualsFilter('swOrderId', $orderEntity->getId()));
        } else {
            $criteria = new Criteria([$referenceId]);
        }
        /** @var IvyPaymentSessionEntity $ivyPaymentSession */
        return $this->ivyPaymentSessionRepository->search($criteria, $context)->first();
    }

    /**
     * @param string $referenceId
     * @param SalesChannelContext $salesChannelContext
     * @return array
     * @throws Exception
     * @throws IvyApiException
     */
    public function getIvyDetail(string $referenceId, SalesChannelContext $salesChannelContext): array
    {
        $this->logger->info('get ivy order data for referenceId ' . $referenceId);
        $config = $this->configHandler->getFullConfig($salesChannelContext);
        $jsonContent = \json_encode(['id' => $referenceId]);
        return $this->ivyApiClient->sendApiRequest('order/details', $config, $jsonContent);
    }


    /**
     * @param Request $request
     * @param SalesChannelContext $salesChannelContext
     * @return bool
     * @throws Exception
     */
    public function isValidRequest(Request $request, SalesChannelContext $salesChannelContext): bool
    {
        $headers = $request->server->getHeaders();
        $hash = $this->sign($request->getContent(), $salesChannelContext);
        if (isset($headers['X-Ivy-Signature']) && $headers['X-Ivy-Signature'] === $hash) {
            return true;
        }
        return isset($headers['X_IVY_SIGNATURE']) && $headers['X_IVY_SIGNATURE'] === $hash;
    }

    /**
     * @param string $body
     * @param SalesChannelContext $salesChannelContext
     * @return false|string
     * @throws Exception
     */
    public function sign(string $body, SalesChannelContext $salesChannelContext)
    {
        $config = $this->configHandler->getFullConfig($salesChannelContext);
        return \hash_hmac(
            'sha256',
            $body,
            $config['IvyWebhookSecret']
        );
    }

    /**
     * @param array $data
     * @param string $contextToken
     * @param SalesChannelContext $salesChannelContext
     * @return bool
     * @throws Exception
     * @throws IvyException
     */
    public function updateUser(
        array $data,
        string $contextToken,
        SalesChannelContext $salesChannelContext
    ): bool
    {
        $this->logger->debug('try to update user');
        $userData = $this->prepareUserData($data, $salesChannelContext);

        $request = new Request([], []);
        $request->setMethod('POST');
        $request->headers->set(PlatformRequest::HEADER_CONTEXT_TOKEN, $contextToken);
        /** @var JsonResponse $response */
        $response = $this->salesChannelProxyController->proxy(
            'account/customer',
            $salesChannelContext->getSalesChannelId(),
            $request,
            $salesChannelContext->getContext()
        );
        $customerData = \json_decode((string)$response->getContent(), true);
        if (isset($customerData['guest']) && $customerData['guest']) {
            $this->logger->info('already guest -> update');
            if (!empty($userData)) {
                $mainData = $userData;
                $billingAddress = $userData['billingAddress'];
                $shippingAddress = $userData['shippingAddress'];
                unset($mainData['billingAddress'], $mainData['shippingAddress'], $mainData['email']);

                $request = new Request([], $mainData);
                $request->setMethod('POST');
                $request->headers->set(PlatformRequest::HEADER_CONTEXT_TOKEN, $contextToken);
                /** @var JsonResponse $response */
                $response = $this->salesChannelProxyController->proxy(
                    'account/change-profile',
                    $salesChannelContext->getSalesChannelId(),
                    $request,
                    $salesChannelContext->getContext()
                );

                $request = new Request([], $billingAddress);
                $request->setMethod('PATCH');
                $request->headers->set(PlatformRequest::HEADER_CONTEXT_TOKEN, $contextToken);
                /** @var JsonResponse $response */
                $response = $this->salesChannelProxyController->proxy(
                    'account/address/' . $customerData['defaultBillingAddressId'],
                    $salesChannelContext->getSalesChannelId(),
                    $request,
                    $salesChannelContext->getContext()
                );

                $request = new Request([], $shippingAddress);
                $request->setMethod('PATCH');
                $request->headers->set(PlatformRequest::HEADER_CONTEXT_TOKEN, $contextToken);
                /** @var JsonResponse $response */
                $response = $this->salesChannelProxyController->proxy(
                    'account/address/' . $customerData['defaultShippingAddressId'],
                    $salesChannelContext->getSalesChannelId(),
                    $request,
                    $salesChannelContext->getContext()
                );

                $this->logger->info('guest data updated');
                return true;
            }
            $this->logger->info('can not update user from request (empty data)');
            return true;
        }
        $this->logger->info('geust not yet registered, can not update');
        return false;
    }

    /**
     * @param array $data
     * @param SalesChannelContext $salesChannelContext
     * @return array
     * @throws Exception
     * @throws IvyException
     */
    protected function prepareUserData(
        array $data,
        SalesChannelContext $salesChannelContext
    ): array
    {
        $billingAddress = $data['billingAddress'] ?? $data['shippingAddress'] ?? $data['shipping']['shippingAddress'] ?? [];
        $shippingAddress = $data['shippingAddress'] ?? $data['shipping']['shippingAddress'] ?? [];
        $this->logger->debug('recived address: ' . \print_r($billingAddress, true));
        if (\is_array($billingAddress) && !empty($billingAddress)) {
            $countryId = $this->getCountryIdFromAddress($billingAddress, $salesChannelContext);
            $shippingCountryId = $this->getCountryIdFromAddress($shippingAddress, $salesChannelContext);

            $config = $this->configHandler->getFullConfig($salesChannelContext);
            $salutationId = $config['defaultSalutation'];

            return [
                'guest'                  => true,
                'email'                  => $data['shopperEmail'] ?? '',
                'defaultPaymentMethodId' => $this->getPaymentMethodId(),
                'storefrontUrl'          => $this->getStorefrontUrl($salesChannelContext),
                'accountType'            => 'private',
                'salutationId'           => $salutationId,
                'firstName'              => $billingAddress['firstName'] ?? '',
                'lastName'               => $billingAddress['lastName'] ?? '',
                'billingAddress'         => [
                    'salutationId'           => $salutationId,
                    'firstName'              => $shippingAddress['firstName'] ?? '',
                    'lastName'               => $shippingAddress['lastName'] ?? '',
                    'zipcode'                => $billingAddress['zipCode'] ?? '',
                    'city'                   => $billingAddress['city'] ?? '',
                    'street'                 => $billingAddress['line1'] ?? '',
                    'additionalAddressLine1' => $billingAddress['line2'] ?? '',
                    'countryId'              => $countryId,
                    'phoneNumber'            => $data['shopperPhone'] ?? '',
                ],
                'shippingAddress'        => [
                    'salutationId'           => $salutationId,
                    'firstName'              => $shippingAddress['firstName'] ?? '',
                    'lastName'               => $shippingAddress['lastName'] ?? '',
                    'zipcode'                => $shippingAddress['zipCode'] ?? '',
                    'city'                   => $shippingAddress['city'] ?? '',
                    'street'                 => $shippingAddress['line1'] ?? '',
                    'additionalAddressLine1' => $shippingAddress['line2'] ?? '',
                    'countryId'              => $shippingCountryId,
                    'phoneNumber'            => $data['shopperPhone'] ?? '',
                ],
                'acceptedDataProtection' => true,
            ];
        }
        return [];
    }

    /**
     * @param array $data
     * @param string $contextToken
     * @param SalesChannelContext $salesChannelContext
     * @return JsonResponse
     * @throws Exception
     * @throws IvyException
     */
    public function createAndLoginQuickCustomer(
        array $data,
        string $contextToken,
        SalesChannelContext $salesChannelContext
    ): JsonResponse
    {
        $userData = $this->prepareUserData($data, $salesChannelContext);
        if (!empty($userData)) {
            $request = new Request([], $userData);
            $request->setMethod('POST');
            $request->headers->set(PlatformRequest::HEADER_CONTEXT_TOKEN, $contextToken);

            /** @var JsonResponse $response */
            $response = $this->salesChannelProxyController->proxy('account/register',
                $salesChannelContext->getSalesChannelId(),
                $request,
                $salesChannelContext->getContext()
            );
            return $response;
        }
        throw new IvyException('can not create user from request');
    }

    /**
     * @param IvyPaymentSessionEntity $ivyPaymentSession
     * @param array $data
     * @param string $contextToken
     * @param SalesChannelContext $salesChannelContext
     * @return array
     * @throws IvyException
     */
    public function checkoutConfirm(
        IvyPaymentSessionEntity $ivyPaymentSession,
        array $data,
        string $contextToken,
        SalesChannelContext $salesChannelContext
    ): array
    {
        $request = new Request([], []);
        $request->setMethod('POST');
        $request->headers->set(PlatformRequest::HEADER_CONTEXT_TOKEN, $contextToken);

        /** @var JsonResponse $response */
        $response = $this->salesChannelProxyController->proxy('checkout/order',
            $salesChannelContext->getSalesChannelId(),
            $request,
            $salesChannelContext->getContext()
        );

        $responseContent = (string)$response->getContent();
        $created = false;
        if (!empty($responseContent)) {
            $orderData = \json_decode($responseContent, true);
            if (\is_array($orderData) && !empty($orderData['id'])) {
                $created = true;
                $this->logger->info('created order ' . $orderData['orderNumber'] . ' (' . $orderData['id'] . ')');
            } else {
                $this->logger->error($responseContent);
            }
        }

        if (!$created) {
            throw new IvyException('order can not be created');
        }

        $tempData = $ivyPaymentSession->getExpressTempData();
        unset($tempData['cookies']);
        $tempData[PlatformRequest::HEADER_CONTEXT_TOKEN] = $contextToken;

        $this->ivyPaymentSessionRepository->upsert([
            [
                'id'              => $ivyPaymentSession->getId(),
                'status'          => $data['status'] ?? 'createOrder',
                'swOrderId'       => $orderData['id'],
                'expressTempData' => $tempData,
            ]
        ], $salesChannelContext->getContext());

        $this->logger->info('Initiate a payment for an order');
        $request = new Request([], [
            'express'      => true,
            'iviSessionId' => $ivyPaymentSession->getId(),
            'orderId'      => $orderData['id'],
        ]);
        $request->setMethod('POST');
        $request->headers->set(PlatformRequest::HEADER_CONTEXT_TOKEN, $contextToken);
        /** @var JsonResponse $response */
        $response = $this->salesChannelProxyController->proxy('handle-payment',
            $salesChannelContext->getSalesChannelId(),
            $request,
            $salesChannelContext->getContext()
        );
        $paymentHandlerData = \json_decode((string)$response->getContent(), true);
        $redirectUrl = \stripslashes($paymentHandlerData['redirectUrl']);
        $this->logger->info('redirectUrl: ' . $redirectUrl);
        $paymentToken = $this->getToken(\stripslashes($paymentHandlerData['redirectUrl'] ?? ''));
        $this->logger->info('paymentToken: ' . $paymentToken);
        $this->savePaymentToken($ivyPaymentSession->getId(), $paymentToken, $salesChannelContext);
        $orderData['_sw_payment_token'] = $paymentToken;
        return $orderData;
    }

    /**
     * @param IvyPaymentSessionEntity $ivyPaymentSession
     * @param string $orderNumber
     * @param string $ivyOrderId
     * @param SalesChannelContext $salesChannelContext
     * @return void
     * @throws Exception
     */
    public function updateIvyExpressOrder(
        IvyPaymentSessionEntity $ivyPaymentSession,
        string $orderNumber,
        string $ivyOrderId,
        SalesChannelContext $salesChannelContext
    ): void {
        $tempData = $ivyPaymentSession->getExpressTempData();
        $contextToken = $tempData[PlatformRequest::HEADER_CONTEXT_TOKEN];

        $config = $this->configHandler->getFullConfig($salesChannelContext);
        $jsonContent = \json_encode([
            'id'          => $ivyOrderId,
            'referenceId' => $orderNumber,
            'metadata'    => [
                PlatformRequest::HEADER_CONTEXT_TOKEN => $contextToken
            ]
        ]);
        $this->logger->debug('update ivy order: ' . \print_r($jsonContent, true));
        try {
            $this->ivyApiClient->sendApiRequest('order/update', $config, $jsonContent);
        } catch (\Exception $e) {
            $this->logger->error('cann not update ivy order: ' . $e->getMessage());
        }
    }

    /**
     * @param string $orderId
     * @param SalesChannelContext $salesChannelContext
     * @return OrderEntity
     */
    public function getExpressOrder(string $orderId, SalesChannelContext $salesChannelContext): OrderEntity
    {
        $criteria = (new Criteria([$orderId]))
            ->addAssociation('lineItems.cover')
            ->addAssociation('transactions.paymentMethod')
            ->addAssociation('deliveries.shippingMethod')
            ->addAssociation('billingAddress.salutation')
            ->addAssociation('billingAddress.country')
            ->addAssociation('billingAddress.countryState')
            ->addAssociation('deliveries.shippingOrderAddress.salutation')
            ->addAssociation('deliveries.shippingOrderAddress.country')
            ->addAssociation('deliveries.shippingOrderAddress.countryState');

        $criteria->getAssociation('transactions')->addSorting(new FieldSorting('createdAt'));

        /** @var OrderEntity|null $order */
        $order = $this->orderRepository->search($criteria, $salesChannelContext->getContext())->first();

        if (!$order) {
            throw new OrderNotFoundException($orderId);
        }

        return $order;
    }

    /**
     * @param array $payload
     * @param string $contextToken
     * @param SalesChannelContext $salesChannelContext
     * @return void
     * @throws IvyException
     */
    public function validateConfirmPayload(array $payload, string $contextToken, SalesChannelContext $salesChannelContext): void
    {
        $request = new Request();
        $request->setMethod('GET');
        $request->headers->set(PlatformRequest::HEADER_CONTEXT_TOKEN, $contextToken);
        /** @var JsonResponse $response */
        $response = $this->salesChannelProxyController->proxy('checkout/cart',
            $salesChannelContext->getSalesChannelId(),
            $request,
            $salesChannelContext->getContext()
        );
        $cartData = \json_decode((string)$response->getContent(), true);

        $cartPrice = $cartData['price'];

        /* TEMP
        $shippingPrice = $cartData['deliveries'][0]['shippingCosts'];
        $shippingVat = $shippingPrice['calculatedTaxes'][0]['tax'];
        $shippingTotal = $shippingPrice['totalPrice'];
        */

        // TEMP $shippingNet = $shippingTotal - $shippingVat;

        // TEMP $totalNet = $cartPrice['netPrice'] - $shippingNet;

        $total = $cartPrice['totalPrice'];

        // TEMP $vat = $cartPrice['calculatedTaxes'][0]['tax'] - $shippingVat;

        $violations = [];
        $accuracy = 0.0001;

        if (\abs($total - $payload['price']['total']) > $accuracy) {
            $violations[] = '$payload["price"]["total"] is ' . $payload['price']['total'] . ' waited ' . $total;
        }

        /* TEMP
        if (\abs($totalNet - $payload['price']['totalNet']) > $accuracy) {
            $violations[] = '$payload["price"]["totalNet"] is ' . $payload['price']['totalNet'] . ' waited ' . $totalNet;
        }
        if (\abs($vat - $payload['price']['vat']) > $accuracy) {
            $violations[] = '$payload["price"]["vat"] is ' . $payload['price']['vat'] . ' waited ' . $vat;
        }
        if (\abs($shippingTotal - $payload['price']['shipping']) > $accuracy) {
            $violations[] = '$payload["price"]["shipping"] is ' . $payload['price']['shipping'] . ' waited ' . $shippingTotal;
        }
        */

        if ($salesChannelContext->getCurrency()->getIsoCode() !== $payload['price']['currency']) {
            $violations[] = '$payload["price"]["currency"] is ' . $payload['price']['currency'] . ' waited ' . $salesChannelContext->getCurrency()->getIsoCode();
        }

        $payloadLineItems = $payload['lineItems'] ?? [];
        if (empty($payloadLineItems) || !\is_array($payloadLineItems)) {
            $violations[] = 'checkout confirm with empty line items';
        }

        /*
        foreach ($payloadLineItems as $key => $payloadLineItem) {
            /** @var lineItem $lineItem */

        /*
        $lineItem = $cartData['lineItems'][$key];
        if ($lineItem['label'] !== $payloadLineItem['name']) {
            $violations[] = '$payloadLineItem["name"] is ' . $payloadLineItem["name"] . ' waited ' . $lineItem['label'];
        }
        if ($lineItem['referencedId'] !== $payloadLineItem['referenceId']) {
            $violations[] = '$payloadLineItem["referenceId"] is ' . $payloadLineItem["referenceId"] . ' waited ' . $lineItem['referencedId'];
        }

        $calculatedPrice = $lineItem['price'];
        $tax = $calculatedPrice['calculatedTaxes'][0];
        if ($tax) {
            $netTotal = $tax['price'] - $tax['tax'];
            $netUnitPrice = $netTotal / $calculatedPrice['quantity'];
            $vat = $tax['tax'] / $calculatedPrice['quantity'];
        } else {
            $netUnitPrice = $calculatedPrice['unitPrice'];
            $vat = 0;
        }
        $singleNet = $netUnitPrice;
        $singleVat = $vat;
        $quantity = $lineItem['quantity'];
        $amount = ($singleNet + $singleVat) * $quantity;


        if (\abs($singleNet - $payloadLineItem['singleNet']) > $accuracy) {
            $violations[] = '$payloadLineItem["singleNet"] is ' . $payloadLineItem["singleNet"] . ' waited ' . $singleNet;
        }
        if (\abs($singleVat - $payloadLineItem['singleVat']) > $accuracy) {
            $violations[] = '$payloadLineItem["singleVat"] is ' . $payloadLineItem["singleVat"] . ' waited ' . $singleVat;
        }
        if (\abs($amount - $payloadLineItem['amount']) > $accuracy) {
            $violations[] = '$payloadLineItem["amount"] is ' . $payloadLineItem["amount"] . ' waited ' . $amount;
        }

        if ((int)$quantity !== (int)$payloadLineItem['quantity']) {
            $violations[] = '$payloadLineItem["quantity"] is ' . $payloadLineItem["quantity"] . ' waited ' . $quantity;
        }
    }
    */

        if (!empty($violations)) {
            throw new IvyException(\print_r($violations, true));
        }
    }

    /**
     * @param SalesChannelContext $salesChannelContext
     * @return string
     */
    public function getStorefrontUrl(SalesChannelContext $salesChannelContext): string
    {
        $salesChannel = $salesChannelContext->getSalesChannel();
        $domainUrl = $this->systemConfigService->get('core.loginRegistration.doubleOptInDomain', $salesChannel->getId());

        if (\is_string($domainUrl) && $domainUrl !== '') {
            return $domainUrl;
        }

        $domains = $salesChannel->getDomains();
        if ($domains === null) {
            throw new SalesChannelDomainNotFoundException($salesChannel);
        }

        $domain = $domains->first();
        if ($domain === null) {
            throw new SalesChannelDomainNotFoundException($salesChannel);
        }

        return $domain->getUrl();
    }

    /**
     * @param int $referenceType
     * @return string
     */
    public function getCallbackUri(int $referenceType = Router::ABSOLUTE_PATH): string
    {
        return $this->router->generate('frontend.ivyexpress.callback', [], $referenceType);
    }

    /**
     * @return string
     */
    public function getFinishUri(): string
    {
        return $this->router->generate('frontend.ivyexpress.finish', [], Router::ABSOLUTE_URL);
    }

    /**
     * @param array $payload
     * @param string $contextToken
     * @param SalesChannelContext $salesChannelContext
     * @return string
     */
    public function setShippingMethod(array $payload, string $contextToken, SalesChannelContext $salesChannelContext): string
    {
        $shippingMethod = $payload['shippingMethod'];
        $this->logger->info('set shipping method:  ' . \print_r($shippingMethod, true));

        $request = new Request([], [
            'paymentMethodId'  => $this->getPaymentMethodId(),
            'shippingMethodId' => $shippingMethod['reference'],
        ]);
        $request->setMethod('PATCH');
        $request->headers->set(PlatformRequest::HEADER_CONTEXT_TOKEN, $contextToken);

        /** @var JsonResponse $response */
        $response = $this->salesChannelProxyController->proxy('context',
            $salesChannelContext->getSalesChannelId(),
            $request,
            $salesChannelContext->getContext()
        );

        $contextData = \json_decode((string)$response->getContent(), true);

        $newToken = $contextData['contextToken'];
        $this->logger->info('update context ' . $newToken);
        return $newToken;
    }

    /**
     * @param string $code
     * @param SalesChannelContext $salesChannelContext
     * @param $outputData
     * @return void
     * @throws Exception
     * @throws IvyException
     */
    public function addPromotion(string $code, SalesChannelContext $salesChannelContext, &$outputData): void
    {
        if ($code === '') {
            throw new IvyException('Discount code is required');
        }
        $cart = $this->cartService->getCart($salesChannelContext->getToken(), $salesChannelContext);
        try {
            // 1. here discount line item has no price yet
            $lineItem = $this->promotionItemBuilder->buildPlaceholderItem($code);
            $cart = $this->cartService->add($cart, $lineItem, $salesChannelContext);
        } catch (\Exception $e) {
            throw new IvyException('Can not add discout: ' . $e->getMessage());
        }

        // 2. we need load validated and recalculated line item from cart, to obtain correct amount
        $loadedLineItem = null;
        /** @var LineItem $currentItem */
        foreach ($cart->getLineItems() as $currentItem) {
            if ($currentItem->isGood() ===  true) {
                continue;
            }
            if ($currentItem->getPayloadValue('code') === $code) {
                $loadedLineItem = $currentItem;
                break;
            }
        }

        if ($loadedLineItem === null) {
            // 3. discout was removed from cart by one cart-processor
            $this->logger->error('discount lineitem with code ' . $code . ' not found in cart');
        }

        $config = $this->configHandler->getFullConfig($salesChannelContext);
        $ivyExpressSessionData = $this->createIvyOrderData->getIvySessionDataFromCart(
            $cart,
            $salesChannelContext,
            $config,
            true,
            true
        );


        if ($loadedLineItem && ($discountPrice = $loadedLineItem->getPrice()) !== null) {
            // 4. discout was successful added to cart
            $outputData['discount'] = [
                'amount' => -$discountPrice->getTotalPrice(),
            ];
        } else {
            $outputData['discount'] = [];
        }
        $price = $ivyExpressSessionData->getPrice();
        $outputData['price']['totalNet'] = $price->getTotalNet();
        $outputData['price']['vat'] = $price->getVat();
        $outputData['price']['total'] = $price->getTotal();
    }

    /**
     * @return string|null
     */
    private function getPaymentMethodId(): ?string
    {
        // Fetch ID for update
        $paymentCriteria = (new Criteria())->addFilter(new EqualsFilter('handlerIdentifier', IvyPaymentHandler::class));
        return $this->paymentRepository->searchIds($paymentCriteria, Context::createDefaultContext())->firstId();
    }

    /**
     * @param array $address
     * @param SalesChannelContext $salesChannelContext
     * @return string
     * @throws IvyException
     */
    private function getCountryIdFromAddress(array $address, SalesChannelContext $salesChannelContext): string
    {
        $countryIso = $address['country'] ?? null;
        $countryId = null;
        if ($countryIso !== null) {
            $criteria = new Criteria();
            $criteria->addFilter(new EqualsFilter('iso', $countryIso));
            $countryId = $this->countryRepository->searchIds($criteria, $salesChannelContext)->firstId();
        }
        if ($countryId === null) {
            throw new IvyException('country with iso ' . $countryIso . ' not found');
        }
        return $countryId;
    }

    /**
     * @param IvyPaymentSessionEntity $ivyPaymentSession
     * @param SalesChannelContext $salesChannelContext
     * @return void
     */
    public function flushTempData(IvyPaymentSessionEntity $ivyPaymentSession, SalesChannelContext $salesChannelContext): void
    {
        $this->ivyPaymentSessionRepository->upsert([
            [
                'id'              => $ivyPaymentSession->getId(),
                'expressTempData' => $ivyPaymentSession->getExpressTempData()
            ],
        ], $salesChannelContext->getContext());
    }

    /**
     * @param $iviSessionId
     * @param $paymentToken
     * @param SalesChannelContext $salesChannelContext
     * @return void
     */
    public function savePaymentToken($iviSessionId, $paymentToken, SalesChannelContext $salesChannelContext): void
    {
        $ivyPaymentSession = $this->getIvySessionByReference($iviSessionId);
        $tempData = $ivyPaymentSession->getExpressTempData();
        $tempData['_sw_payment_token'] = $paymentToken;
        $this->ivyPaymentSessionRepository->upsert([
            [
                'id'              => $iviSessionId,
                'expressTempData' => $tempData
            ],
        ], $salesChannelContext->getContext());
    }

    /**
     * @param string $returnUrl
     * @return string|null
     */
    private function getToken(string $returnUrl): ?string
    {
        $query = \parse_url($returnUrl, \PHP_URL_QUERY);
        \parse_str((string)$query, $params);

        return $params['_sw_payment_token'] ?? null;
    }

}
