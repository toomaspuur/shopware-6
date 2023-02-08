<?php

declare(strict_types=1);
/*
 * (c) WIZMO GmbH <plugins@shopentwickler.berlin>
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace WizmoGmbh\IvyPayment\Core\Checkout\Payment\Controller;

use Shopware\Core\Checkout\Cart\Order\OrderConverter;
use Shopware\Core\Checkout\Order\OrderEntity;
use Shopware\Core\Checkout\Payment\Cart\Token\TokenFactoryInterfaceV2;
use Shopware\Core\Checkout\Payment\Cart\Token\TokenStruct;
use Shopware\Core\Checkout\Payment\Exception\AsyncPaymentFinalizeException;
use Shopware\Core\Checkout\Payment\Exception\CustomerCanceledAsyncPaymentException;
use Shopware\Core\Checkout\Payment\Exception\InvalidTokenException;
use Shopware\Core\Checkout\Payment\Exception\InvalidTransactionException;
use Shopware\Core\Checkout\Payment\Exception\TokenExpiredException;
use Shopware\Core\Checkout\Payment\Exception\UnknownPaymentMethodException;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepositoryInterface;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\Routing\Annotation\RouteScope;
use Shopware\Core\Framework\Routing\Annotation\Since;
use Shopware\Core\Framework\Routing\Exception\MissingRequestParameterException;
use Shopware\Core\Framework\ShopwareException;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Storefront\Controller\StorefrontController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use WizmoGmbh\IvyPayment\Components\Config\ConfigHandler;
use WizmoGmbh\IvyPayment\Core\Checkout\Order\IvyPaymentSessionEntity;
use WizmoGmbh\IvyPayment\IvyApi\ApiClient;
use WizmoGmbh\IvyPayment\Logger\IvyLogger;
use WizmoGmbh\IvyPayment\Services\IvyPaymentService;
use WizmoGmbh\IvyPayment\Express\Service\ExpressService;


class IvyPaymentController extends StorefrontController
{
    private IvyPaymentService $paymentService;

    private OrderConverter $orderConverter;

    private TokenFactoryInterfaceV2 $tokenFactoryInterfaceV2;

    private EntityRepositoryInterface $orderRepository;

    private EntityRepositoryInterface $ivyPaymentSessionRepository;

    private ConfigHandler $configHandler;

    private ApiClient $ivyApiClient;

    private IvyLogger $logger;

    private ExpressService $expressService;

    /**
     * @param IvyPaymentService $paymentService
     * @param OrderConverter $orderConverter
     * @param TokenFactoryInterfaceV2 $tokenFactoryInterfaceV2
     * @param EntityRepositoryInterface $orderRepository
     * @param EntityRepositoryInterface $ivyPaymentSessionRepository
     * @param ConfigHandler $configHandler
     * @param ApiClient $ivyApiClient
     * @param IvyLogger $logger
     * @param ExpressService $expressService
     */
    public function __construct(
        IvyPaymentService $paymentService,
        OrderConverter $orderConverter,
        TokenFactoryInterfaceV2 $tokenFactoryInterfaceV2,
        EntityRepositoryInterface $orderRepository,
        EntityRepositoryInterface $ivyPaymentSessionRepository,
        ConfigHandler $configHandler,
        ApiClient $ivyApiClient,
        IvyLogger $logger,
        ExpressService $expressService
    ) {
        $this->paymentService = $paymentService;
        $this->orderConverter = $orderConverter;
        $this->tokenFactoryInterfaceV2 = $tokenFactoryInterfaceV2;
        $this->orderRepository = $orderRepository;
        $this->ivyPaymentSessionRepository = $ivyPaymentSessionRepository;
        $this->configHandler = $configHandler;
        $this->ivyApiClient = $ivyApiClient;
        $this->logger = $logger;
        $this->expressService = $expressService;
    }

    /**
     * @Since("6.0.0.0")
     * @Route("/ivypayment/failed-transaction", name="ivypayment.failed.transaction", methods={"GET", "POST"}, defaults={"XmlHttpRequest"=true})
     * @RouteScope(scopes={"storefront"})
     *
     * @throws AsyncPaymentFinalizeException
     * @throws CustomerCanceledAsyncPaymentException
     * @throws InvalidTransactionException
     * @throws TokenExpiredException
     * @throws UnknownPaymentMethodException
     */
    public function failedTransaction(Request $request): Response
    {
        $expressRedirect = $this->handleExpress($request, true);
        if ($expressRedirect) {
            return $expressRedirect;
        }

        $finishUrl = '/account/order';

        $context = Context::createDefaultContext();
        $swOrderId = $this->getSwOrderIdFromReference((string)($request->get('reference') ?? ''), $context);

        if (!empty($swOrderId)) {
            $this->finalizeTransaction($request, 'failed');

            $finishUrl = '/account/order/edit/' . $swOrderId . '?error-code=CHECKOUT__UNKNOWN_ERROR';
        }

        return new RedirectResponse($finishUrl);
    }

    /**
     * @Since("6.0.0.0")
     * @Route("/ivypayment/update-transaction", name="ivypayment.update.transaction", methods={"GET", "POST"}, defaults={"XmlHttpRequest"=true,"csrf_protected"=false,"auth_required"=false})
     * @RouteScope(scopes={"storefront"})
     *
     * @throws AsyncPaymentFinalizeException
     * @throws CustomerCanceledAsyncPaymentException
     * @throws InvalidTransactionException
     * @throws TokenExpiredException
     * @throws UnknownPaymentMethodException
     * @psalm-suppress InvalidArrayAccess
     */
    public function updateTransaction(Request $request, SalesChannelContext $salesChannelContext): Response
    {
        $this->logger->info('received webhook');

        $type = $request->request->get('type');
        /** @var array $payload */
        $payload = $request->request->get('payload');

        if (empty($type) || empty($payload)) {
            $this->logger->error('bad webhook request');
            return new JsonResponse(null, Response::HTTP_BAD_REQUEST);
        }

        if ($type === 'order_created' || $type === 'order_updated') {
            if (!isset($payload['status'])) {
                $this->logger->error('bad webhook request');
                return new JsonResponse(null, Response::HTTP_BAD_REQUEST);
            }
    
            $this->logger->debug('notification payload: ' . \print_r($payload, true));
            $request->request->set('status', $payload['status']);
    
            $paymentToken = $payload['metadata']['_sw_payment_token'] ?? null;
    
            if ($paymentToken === null) {
                $this->logger->error('bad webhook request missing _sw_payment_token');
                throw new MissingRequestParameterException('_sw_payment_token');
            }
    
            $isValid = $this->expressService->isValidRequest($request, $salesChannelContext);
    
            if (!$isValid) {
                $this->logger->error('webhook request: unauthenticated request');
                return new JsonResponse(null, Response::HTTP_FORBIDDEN);
            }
    
            $this->logger->info('webhook request: valid request');
    
            $this->paymentService->updateTransaction(
                $paymentToken,
                $request,
                $salesChannelContext
            );
        }

        return new JsonResponse(null, Response::HTTP_OK);
    }

    /**
     * @Since("6.0.0.0")
     * @Route("/ivypayment/finalize-transaction", name="ivypayment.finalize.transaction", methods={"GET", "POST"}, defaults={"XmlHttpRequest"=true})
     * @RouteScope(scopes={"storefront"})
     *
     * @throws AsyncPaymentFinalizeException
     * @throws CustomerCanceledAsyncPaymentException
     * @throws InvalidTransactionException
     * @throws TokenExpiredException
     * @throws UnknownPaymentMethodException
     */
    public function finalizeTransaction(Request $request, string $status = 'final'): Response
    {
        $paymentToken = $request->get('_sw_payment_token');
        $context = Context::createDefaultContext();

        $swOrderId = $this->getSwOrderIdFromReference((string)($request->get('reference') ?? ''), $context);
        $ivyOrderId = $request->get('order-id');

        if ($paymentToken === null) {
            throw new MissingRequestParameterException('_sw_payment_token');
        }

        $salesChannelContext = $this->assembleSalesChannelContext($paymentToken);

        $result = $this->paymentService->finalizeTransaction(
            $paymentToken,
            $request,
            $salesChannelContext
        );

        if ((string)$swOrderId !== '') {
            $criteria = new Criteria();
            $criteria->addFilter(new EqualsFilter('swOrderId', $swOrderId));
            $ivyPaymentId = $this->ivyPaymentSessionRepository->searchIds($criteria, $context)->firstId();
            if ($ivyPaymentId !== null) {
                $data = [
                    'id'        => $ivyPaymentId,
                    'swOrderId' => $swOrderId,
                    'status'    => $status,
                ];

                if (!empty($ivyOrderId)) {
                    $data['ivyOrderId'] = $ivyOrderId;
                }
                $this->ivyPaymentSessionRepository->upsert([$data], $context);
            }
        }

        $response = $this->handleException($result);
        if ($response !== null) {
            return $response;
        }

        $finishUrl = $result->getFinishUrl();
        if ($finishUrl) {
            return new RedirectResponse($finishUrl);
        }

        return new JsonResponse(null, Response::HTTP_NO_CONTENT);
    }

    /**
     * @param string $referenceId
     * @param Context $context
     * @return string
     */
    private function getSwOrderIdFromReference(string $referenceId, Context $context): string
    {
        // if reference with ordernumber updated
        $criteria = new Criteria();
        $criteria->addFilter(new EqualsFilter('orderNumber', $referenceId));
        /** @var OrderEntity|null $orderEntity */
        $orderEntity = $this->orderRepository->search($criteria, $context)->first();
        if ($orderEntity !== null) {
            return $orderEntity->getId();
        }
        return $referenceId;
    }

    /**
     * @psalm-suppress PossiblyNullArgument
     */
    private function handleException(TokenStruct $token): ?Response
    {
        if ($token->getException() === null) {
            return null;
        }

        if ($token->getErrorUrl() === null) {
            return null;
        }

        $url = $token->getErrorUrl();

        $exception = $token->getException();
        if ($exception instanceof ShopwareException) {
            $this->logger->error($exception->getMessage());

            return new RedirectResponse(
                $url . (\parse_url($url, \PHP_URL_QUERY) ? '&' : '?') . 'error-code=' . $exception->getErrorCode()
            );
        }

        return new RedirectResponse($url);
    }

    /**
     * @psalm-suppress PossiblyInvalidArgument
     */
    private function assembleSalesChannelContext(string $paymentToken): SalesChannelContext
    {
        $context = Context::createDefaultContext();

        $transactionId = $this->tokenFactoryInterfaceV2->parseToken($paymentToken)->getTransactionId();
        if ($transactionId === null) {
            throw new InvalidTokenException($paymentToken);
        }

        $criteria = new Criteria();
        $criteria->addFilter(new EqualsFilter('transactions.id', $transactionId));
        $criteria->addAssociation('transactions');
        $criteria->addAssociation('orderCustomer');

        /** @var OrderEntity|null $order */
        $order = $this->orderRepository->search($criteria, $context)->first();

        if ($order === null) {
            throw new InvalidTokenException($paymentToken);
        }

        return $this->orderConverter->assembleSalesChannelContext($order, $context);
    }

    /**
     * @psalm-suppress PossiblyInvalidArgument
     */
    private function isValidRequest(Request $request, SalesChannelContext $salesChannelContext): bool
    {
        $config = $this->configHandler->getFullConfig($salesChannelContext);
        $headers = $request->server->getHeaders();
        $hash = \hash_hmac(
            'sha256',
            $request->getContent(),
            $config['IvyWebhookSecret']
        );
        if (isset($headers['X-Ivy-Signature']) && $headers['X-Ivy-Signature'] === $hash) {
            return true;
        }
        return isset($headers['X_IVY_SIGNATURE']) && $headers['X_IVY_SIGNATURE'] === $hash;
    }

    /**
     * @param Request $request
     * @param bool $failed
     * @return Response|null
     */
    private function handleExpress(Request $request, bool $failed = false): ?Response
    {
        $referenceId = $request->get('reference');
        $criteria = new Criteria([$referenceId]);
        /** @var IvyPaymentSessionEntity|null $expressSession */
        $expressSession = $this->ivyPaymentSessionRepository
                ->search($criteria, Context::createDefaultContext())
                ->first();
        if ($expressSession !== null) {
            if ($failed) {
                $this->addFlash(self::DANGER, $this->trans('ivypaymentplugin.express.checkout.error'));
            }
            $swOrderId = $expressSession->getSwOrderId() ;
            if ($swOrderId === null) {
                return $this->redirectToRoute('frontend.checkout.cart.page');
            }
            $request->query->set('reference', $swOrderId);
            $this->finalizeTransaction($request, 'failed');
            $finishUrl = '/account/order/edit/' . $swOrderId . '?error-code=CHECKOUT__UNKNOWN_ERROR';
            return new RedirectResponse($finishUrl);
        }
        return null;
    }
}
