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

    private ConfigHandler $configHandler;

    private ApiClient $ivyApiClient;

    private IvyLogger $logger;

    private ExpressService $expressService;

    /**
     * @param IvyPaymentService $paymentService
     * @param OrderConverter $orderConverter
     * @param TokenFactoryInterfaceV2 $tokenFactoryInterfaceV2
     * @param EntityRepositoryInterface $orderRepository
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
        ConfigHandler $configHandler,
        ApiClient $ivyApiClient,
        IvyLogger $logger,
        ExpressService $expressService
    ) {
        $this->paymentService = $paymentService;
        $this->orderConverter = $orderConverter;
        $this->tokenFactoryInterfaceV2 = $tokenFactoryInterfaceV2;
        $this->orderRepository = $orderRepository;
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
        $finishUrl = '/account/order';
        $referenceId = $request->get('reference');

        $order = $this->expressService->getIvyOrderByReference($referenceId);

        if ($order !== null) {
            $this->finalizeTransaction($request, 'failed');
            $finishUrl = '/account/order/edit/' . $order->getId() . '?error-code=CHECKOUT__UNKNOWN_ERROR';
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
     * @throws UnknownPaymentMethodException|\Doctrine\DBAL\Exception
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

        $isValid = $this->expressService->isValidRequest($request, $salesChannelContext);

        if (!$isValid) {
            $this->logger->error('webhook request: unauthenticated request');
            return new JsonResponse(null, Response::HTTP_FORBIDDEN);
        }

        $this->logger->info('webhook request: valid request ==> '. $type);

        if ($type === 'order_created' || $type === 'order_updated') {
            if (!isset($payload['status'])) {
                $this->logger->error('bad webhook request');
                return new JsonResponse(null, Response::HTTP_BAD_REQUEST);
            }
    
            $this->logger->debug('webhook payload: ' . \print_r($payload, true));

            $referenceId = $payload['referenceId'];
            $paymentToken = $payload['metadata']['_sw_payment_token'];

            if ($paymentToken === null) {
                $order = $this->expressService->getIvyOrderByReference($referenceId);
                $this->logger->debug('no payment token');
                if ($order === null) {
                    $this->logger->error('webhook request: order not found with: '.$referenceId);
                    return new JsonResponse(null, Response::HTTP_NOT_FOUND);
                }

                $transactionId = $order
                    ->getTransactions()
                    ->filterByPaymentMethodId($this->expressService->getPaymentMethodId())
                    ->first()
                    ->getId();
            } else {
                $this->logger->debug('payment token: '.$paymentToken);
                $token = $this->tokenFactoryInterfaceV2->parseToken($paymentToken);
                $transactionId = $token->getTransactionId();
            }

            $request->request->set('status', $payload['status']);
            $this->logger->debug('set status to: ' . $payload['status'] . ' for referenceId: '.$referenceId);

            $this->paymentService->updateTransaction(
                $paymentToken,
                $transactionId,
                $this->expressService->getPaymentMethodId(),
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

        if ($paymentToken === null) {
            throw new MissingRequestParameterException('_sw_payment_token');
        }

        $salesChannelContext = $this->assembleSalesChannelContext($paymentToken);

        $result = $this->paymentService->finalizeTransaction(
            $paymentToken,
            $request,
            $salesChannelContext
        );

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
}
