<?php declare(strict_types=1);

namespace WizmoGmbh\IvyPayment\Express\Controller;

use Doctrine\DBAL\Exception;
use Shopware\Core\Checkout\Cart\Exception\InvalidCartException;
use Shopware\Core\Checkout\Cart\SalesChannel\CartService;
use Shopware\Core\Checkout\Order\OrderEntity;
use Shopware\Core\Framework\Validation\DataBag\RequestDataBag;
use Shopware\Core\Framework\Validation\Exception\ConstraintViolationException;
use Shopware\Core\PlatformRequest;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Storefront\Controller\StorefrontController;
use Shopware\Storefront\Page\GenericPageLoaderInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Validator\ConstraintViolationInterface;
use WizmoGmbh\IvyPayment\Components\Config\ConfigHandler;
use WizmoGmbh\IvyPayment\Components\IvyJsonResponse;
use WizmoGmbh\IvyPayment\Core\IvyPayment\IvyCheckoutSession;
use WizmoGmbh\IvyPayment\Exception\IvyException;
use WizmoGmbh\IvyPayment\Express\Service\ExpressService;
use WizmoGmbh\IvyPayment\IvyApi\ApiClient;
use WizmoGmbh\IvyPayment\Logger\IvyLogger;

/**
 * @Route(defaults={"_routeScope"={"storefront"}})
 */
class ExpressController extends StorefrontController
{

    private ExpressService $expressService;

    private IvyLogger $logger;

    private ConfigHandler $configHandler;

    private array $errors = [];

    private IvyCheckoutSession $ivyCheckoutSession;
    private CartService $cartService;
    private ApiClient $ivyApiClient;

    /**
     * @param ExpressService $expressService
     * @param ConfigHandler $configHandler
     * @param GenericPageLoaderInterface $genericLoader
     * @param IvyLogger $logger
     * @param IvyCheckoutSession $ivyCheckoutSession
     * @param CartService $cartService
     */
    public function __construct
    (
        ExpressService $expressService,
        ConfigHandler $configHandler,
        IvyLogger $logger,
        IvyCheckoutSession $ivyCheckoutSession,
        CartService $cartService,
        ApiClient $ivyApiClient
    )
    {
        $this->expressService = $expressService;
        $this->configHandler = $configHandler;
        $this->logger = $logger;
        $this->ivyCheckoutSession = $ivyCheckoutSession;
        $this->cartService = $cartService;
        $this->ivyApiClient = $ivyApiClient;
    }

    /**
     * @Route("/ivycheckout/start", name="frontend.ivycheckout.start", methods={"GET"}, defaults={"XmlHttpRequest"=true})
     */
    public function checkoutStart(Request $request, SalesChannelContext $salesChannelContext): Response
    {
        return $this->_checkoutStart($request->getSession()->get(PlatformRequest::HEADER_CONTEXT_TOKEN), $salesChannelContext, false);
    }

    /**
     * @Route("/ivyexpress/start", name="frontend.ivyexpress.start", methods={"GET"}, defaults={"XmlHttpRequest"=true})
     */
    public function expressStart(Request $request, SalesChannelContext $salesChannelContext): Response
    {
        return $this->_checkoutStart($request->getSession()->get(PlatformRequest::HEADER_CONTEXT_TOKEN), $salesChannelContext, true);
    }

    public function _checkoutStart(string $contextToken, SalesChannelContext $salesChannelContext, bool $express): IvyJsonResponse
    {
        $this->logger->setName('START');
        $this->logger->setLevel($this->configHandler->getLogLevel($salesChannelContext));
        $this->logger->info('-- create session -- express: ' . $express);
        $salesChannelContext = $this->expressService->switchPaymentMethod($salesChannelContext);
        $token = $salesChannelContext->getToken();
        $cart = $this->cartService->getCart($token, $salesChannelContext);
        $data = [];
        try {
            $redirectUrl = $this->ivyCheckoutSession->createCheckoutSession($contextToken, $salesChannelContext, $express, null, $cart);
            $this->logger->info('redirect to ' . $redirectUrl);
            $data['success'] = true;
            $data['redirectUrl'] = $redirectUrl;
        } catch (\Exception $e) {
            $message = $e->getMessage();
            $this->logger->error($message);
            $this->addFlash(self::DANGER, $this->trans('ivypaymentplugin.express.session.error'));
            $data['success'] = false;
            $data['error'] = $message;
        }
        \ini_set('serialize_precision', '-1');
        return new IvyJsonResponse($data);
    }

    /**
     * @Route("/ivyexpress/callback", name="frontend.ivyexpress.callback", methods={"POST", "GET"},
     *     defaults={
     *          "XmlHttpRequest"=true,
     *          "csrf_protected"=false,
     *          "auth_required"=false,
     *     })
     * @throws Exception
     */
    public function callback(Request $request, RequestDataBag $inputData, SalesChannelContext $salesChannelContext): Response
    {
        $this->logger->setName('QOUTE');
        $this->logger->setLevel($this->configHandler->getLogLevel($salesChannelContext));

        $this->logger->info('received ivy callback: ' . print_r($inputData->all(), true));

        $isValid = $this->expressService->isValidRequest($request, $salesChannelContext);
        $this->logger->debug('signature ' . ($isValid ? 'valid' : 'not valid'));

        $outputData = [];
        $errorStatus = null;
        try {
            if ($isValid === true) {
                $payload = $data = $inputData->all();
                $shipping = $data['shipping'] ?? null;
                try {
                    $contextToken = $payload['metadata'][PlatformRequest::HEADER_CONTEXT_TOKEN];
                    $this->logger->info('found context token ' . $contextToken);
                    $salesChannelContext = $this->expressService->reloadContext($salesChannelContext, $contextToken);
                    $this->logger->info(
                        'loaded context with token : ' . $salesChannelContext->getToken(
                        ) . ' customerId: ' . $this->getCustomerIdFromContext($salesChannelContext)
                    );

                    if (\is_array($shipping) && isset($shipping['shippingAddress'])) {
                        $updated = $this->expressService->updateUser($payload, $contextToken, $salesChannelContext);
                        if (!$updated) {
                            $this->logger->debug('not updated, try to create new guest and login');
                            $storeApiResponse = $this->expressService->createAndLoginQuickCustomer(
                                $payload,
                                $contextToken,
                                $salesChannelContext
                            );
                            $customer = $storeApiResponse->getCustomer();
                            $this->logger->info('created customer: ' . $customer->getEmail());
                            $contextToken = $storeApiResponse->headers->get(PlatformRequest::HEADER_CONTEXT_TOKEN);
                            $this->logger->info('new context token: ' . $contextToken);
                            $salesChannelContext = $this->expressService->reloadContext(
                                $salesChannelContext,
                                $contextToken
                            );
                            $this->logger->info(
                                'loaded new context. Token: ' . $salesChannelContext->getToken(
                                ) . ', customerId: ' . $this->getCustomerIdFromContext($salesChannelContext)
                            );

                            $this->logger->info('save new context token');
                            $outputData['metadata'][PlatformRequest::HEADER_CONTEXT_TOKEN] = $contextToken;
                        }
                    }
                } catch (\Exception $e) {
                    $errorStatus = $this->handleException($e);
                }

                if (!isset($contextToken)) {
                    throw new IvyException('can not obtain $contextToken');
                }
                $salesChannelContext = $this->expressService->reloadContext($salesChannelContext, $contextToken);

                $customer = $salesChannelContext->getCustomer();
                $activeShipping = $customer ? $customer->getActiveShippingAddress() : null;
                $activeShippingCountry = $activeShipping ? $activeShipping->getCountry() : null;
                if ($activeShippingCountry) {
                    $this->logger->debug('active shipping country: ' . $activeShippingCountry->getName());
                } else {
                    $this->logger->debug('not shipping country found in context');
                }
                $salesChannelContext = $this->expressService->reloadContext($salesChannelContext, $contextToken);

                if (\is_array($shipping) && isset($shipping['shippingAddress'])) {
                    try {
                        $this->expressService->getAllShippingVariants($salesChannelContext, $outputData);
                    } catch (\Exception $e) {
                        $error = 'shipping callback error: ' . $e->getMessage();
                        $this->errors[] = $error;
                        $this->logger->error($error);
                        $outputData['shippingMethods'] = [];
                    }
                }

                $discount = $data['discount'] ?? null;
                if (\is_array($discount) && isset($discount['voucher'])) {
                    $voucherCode = (string) $discount['voucher'];
                    try {
                        $this->expressService->addPromotion($voucherCode, $salesChannelContext, $outputData);
                    } catch (\Exception $e) {
                        $error = 'discount callback error: ' . $e->getMessage();
                        $this->errors[] = $error;
                        $this->logger->error($error);
                        $outputData['discount'] = [];
                    }
                }
            } else {
                $this->errors[] = 'not valid signature';
                $errorStatus = Response::HTTP_FORBIDDEN;
            }
        } catch (\Throwable $e) {
            $errorStatus = $this->handleException($e);
        }

        if ($errorStatus !== null) {
            $outputData['shippingMethods'] = [];
            $outputData['discount'] = [];
        }

        if (!empty($this->errors)) {
            $outputData['errors'] = $this->errors;
        }

        \ini_set('serialize_precision', '-1');
        $response = new IvyJsonResponse($outputData);
        $signature = $this->expressService->sign((string) $response->getContent(), $salesChannelContext);

        $response->headers->set('X-Ivy-Signature', $signature);
        $this->logger->info('output body:' . $response->getContent());
        $this->logger->info('X-Ivy-Signature:' . $signature);
        return $response;
    }


    /**
     * @Route("/ivyexpress/confirm", name="frontend.ivyexpress.confirm", methods={"POST", "GET"},
     *     defaults={
     *          "XmlHttpRequest"=true,
     *          "csrf_protected"=false,
     *          "auth_required"=false,
     *     })
     * @throws Exception
     */
    public function confirmAction(Request $request, RequestDataBag $inputData, SalesChannelContext $salesChannelContext): Response
    {
        $this->logger->setName('CONFIRM');
        $errorStatus = null;
        $outputData = [];
        $finishUrl = $this->expressService->getFinishUri();
        $this->logger->setLevel($this->configHandler->getLogLevel($salesChannelContext));
        $this->logger->debug('confirm action (finish url: ' . $finishUrl . ')');
        $isValid = $this->expressService->isValidRequest($request, $salesChannelContext);
        $this->logger->debug('signature ' . ($isValid ? 'valid' : 'not valid'));

        if ($isValid === true) {
            try {
                $payload = $inputData->all();
                $this->logger->debug('confirm payload: ' . var_export($payload, true));
                if (empty($payload)) {
                    throw new IvyException('empty payload');
                }

                $this->logger->info('confirm payload is valid, start create order');

                $referenceId = $payload['referenceId'] ?? null;

                // always prefer an existing order
                $existingOrder = $this->expressService->getIvyOrderByReference($referenceId);
                if ($existingOrder) {
                    $this->logger->info('order existing');
                    $response = new IvyJsonResponse([
                        'redirectUrl' => $finishUrl,
                        'referenceId' => $existingOrder->getId(),
                        'displayId' => $existingOrder->getOrderNumber(),
                        'metadata' => $payload['metadata'],
                    ]);
                    $signature = $this->expressService->sign(\stripslashes((string) $response->getContent()), $salesChannelContext);
                    $response->headers->set('X-Ivy-Signature', $signature);
                    return $response;
                }

                $isExpress = $payload['express'];
                $this->logger->info('express: ' . \var_export($isExpress, true));

                $contextToken = $payload['metadata'][PlatformRequest::HEADER_CONTEXT_TOKEN];
                $this->logger->info('found context token ' . $contextToken);
                $salesChannelContext = $this->expressService->reloadContext($salesChannelContext, $contextToken);
                $this->logger->info('loaded context with token : ' . $salesChannelContext->getToken() . ' customerId: ' . $this->getCustomerIdFromContext($salesChannelContext));

                if ($isExpress) {
                    $contextToken = $this->expressService->setShippingMethod($payload, $contextToken, $salesChannelContext);
                    $this->expressService->updateUser($payload, $contextToken, $salesChannelContext);
                    $salesChannelContext = $this->expressService->reloadContext($salesChannelContext, $contextToken);
                    $outputData['metadata'][PlatformRequest::HEADER_CONTEXT_TOKEN] = $contextToken;
                }

                $this->expressService->validateConfirmPayload($payload, $contextToken, $salesChannelContext);

                $outputData = [
                    'redirectUrl' => $finishUrl,
                ];
            } catch (\Throwable $e) {
                $errorStatus = $this->handleException($e);
            }
        } else {
            $errorStatus = Response::HTTP_FORBIDDEN;
        }

        if (!empty($this->errors)) {
            $outputData['errors'] = $this->errors;
        }

        \ini_set('serialize_precision', '-1');
        $response = new IvyJsonResponse($outputData);
        $signature = $this->expressService->sign(\stripslashes((string) $response->getContent()), $salesChannelContext);
        if ($errorStatus !== null) {
            $response->setStatusCode($errorStatus);
        }
        $response->headers->set('X-Ivy-Signature', $signature);
        $this->logger->info('output body:' . $response->getContent());
        $this->logger->info('X-Ivy-Signature:' . $signature);
        return $response;
    }

    /**
     * @Route("/ivyexpress/finish", name="frontend.ivyexpress.finish", methods={"GET"})
     */
    public function finishAction(Request $request, RequestDataBag $inputData, SalesChannelContext $salesChannelContext): Response
    {
        $this->logger->setLevel($this->configHandler->getLogLevel($salesChannelContext));
        $this->logger->setName('FINISH');
        try {
            $referenceId = $request->get('reference');
            $ivyOrderId = $request->get('order-id');

            $this->logger->debug('finish action reference: ' . $referenceId);
            $this->logger->debug('order-id ' . $ivyOrderId);
            $config = $this->configHandler->getFullConfig($salesChannelContext);
            $payload = $this->ivyApiClient->sendApiRequest('order/details', $config, \json_encode(['id' => $ivyOrderId]));
            $this->logger->info('ivyOrderData: ' . \print_r($payload, true));
            $contextToken = $payload['metadata'][PlatformRequest::HEADER_CONTEXT_TOKEN];
            if (!empty($contextToken)) {
                $this->logger->info('switch storefront context to order context');
                $session = $request->getSession();
                $session->migrate(false);
                $session->set('sessionId', $session->getId());
                $session->set(PlatformRequest::HEADER_CONTEXT_TOKEN, $contextToken);
                $request->headers->set(PlatformRequest::HEADER_CONTEXT_TOKEN, $contextToken);
            }
            $swOrder = $this->expressService->getIvyOrderByReference($referenceId);
            if ($swOrder === null) {
                //order is not yet created
                $this->logger->info('order by reference not found');
                $status = $payload['status'] ?? null;
                switch ($status) {
                    case 'paid';
                    case 'waiting_for_payment':
                        [ $swOrder, $token ] = $this->expressService->checkoutConfirm($inputData, $payload, $salesChannelContext);
                        break;
                    case 'canceled':
                    case 'failed':
                        $this->logger->error('payment failed or canceled');
                        $this->addFlash(self::DANGER, $this->trans('ivypaymentplugin.express.checkout.error'));
                        break;
                }
            }

            if ($swOrder instanceof OrderEntity) {
                return $this->redirectToRoute('frontend.checkout.finish.page',['orderId' => $swOrder->getId()]);
            }
            $this->logger->info('order is not created');
        } catch (\Throwable $e) {
            $this->logger->error($e->getMessage());
            $this->addFlash(self::DANGER, $this->trans('ivypaymentplugin.express.checkout.error'));
        }
        return $this->redirectToRoute('frontend.checkout.cart.page');
    }

    /**
     * @param \Throwable $e
     * @return int
     */
    private function handleException(\Throwable $e): int
    {
        $errorStatus = Response::HTTP_INTERNAL_SERVER_ERROR;
        if ($e instanceof IvyException) {
            $errorStatus = Response::HTTP_BAD_REQUEST;
        } elseif ($e instanceof ConstraintViolationException) {
            $errorStatus = Response::HTTP_BAD_REQUEST;
            /** @var ConstraintViolationInterface $violation */
            foreach ($e->getViolations() as $violation) {
                $this->logger->error($violation->getMessage());
            }
        } elseif ($e instanceof InvalidCartException) {
            $errorStatus = Response::HTTP_BAD_REQUEST;
            $this->logger->error('can not create order, cart is invalid');
            foreach ($e->getErrors() as $error) {
                $this->logger->error(\print_r($error, true));
            }
        }
        $this->logger->error($e->getMessage());
        $this->errors[] = $e->getMessage() . ' ' . $e->getTraceAsString();
        return $errorStatus;
    }

    /**
     * @param SalesChannelContext $channelContext
     * @return string|null
     */
    private function getCustomerIdFromContext(SalesChannelContext $channelContext): ?string
    {
        if (\method_exists($channelContext, 'getCustomerId')) {
            return $channelContext->getCustomerId();
        }
        if (\method_exists($channelContext, 'getCustomer')) {
            $customer = $channelContext->getCustomer();
            if ($customer !== null) {
                return $customer->getId();
            }
        }
        return null;
    }
}
