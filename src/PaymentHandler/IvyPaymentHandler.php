<?php

declare(strict_types=1);
/*
 * (c) WIZMO GmbH <plugins@shopentwickler.berlin>
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace WizmoGmbh\IvyPayment\PaymentHandler;

use GuzzleHttp\Exception\GuzzleException;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionStateHandler;
use Shopware\Core\Checkout\Order\OrderEntity;
use Shopware\Core\Checkout\Payment\Cart\AsyncPaymentTransactionStruct;
use Shopware\Core\Checkout\Payment\Cart\PaymentHandler\AsynchronousPaymentHandlerInterface;
use Shopware\Core\Checkout\Payment\Exception\AsyncPaymentProcessException;
use Shopware\Core\Checkout\Payment\Exception\CustomerCanceledAsyncPaymentException;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepositoryInterface;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\Uuid\Uuid;
use Shopware\Core\Framework\Validation\DataBag\RequestDataBag;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Serializer\Encoder\JsonEncoder;
use Symfony\Component\Serializer\Encoder\XmlEncoder;
use Symfony\Component\Serializer\Normalizer\ObjectNormalizer;
use Symfony\Component\Serializer\Serializer;
use WizmoGmbh\IvyPayment\Components\Config\ConfigHandler;
use WizmoGmbh\IvyPayment\Components\CustomObjectNormalizer;
use WizmoGmbh\IvyPayment\Core\IvyPayment\createIvyOrderData;
use WizmoGmbh\IvyPayment\IvyApi\ApiClient;
use WizmoGmbh\IvyPayment\Logger\IvyLogger;


class IvyPaymentHandler implements AsynchronousPaymentHandlerInterface
{
    private OrderTransactionStateHandler $transactionStateHandler;

    private ConfigHandler $configHandler;

    private createIvyOrderData $createIvyOrderData;

    private EntityRepositoryInterface $ivyPaymentSessionRepository;

    private EntityRepositoryInterface $orderRepository;

    private IvyLogger $logger;

    private ApiClient $apiClient;

    /**
     * @param OrderTransactionStateHandler $transactionStateHandler
     * @param EntityRepositoryInterface $orderRepository
     * @param createIvyOrderData $createIvyOrderData
     * @param EntityRepositoryInterface $ivyPaymentSessionRepository
     * @param ConfigHandler $configHandler
     * @param ApiClient $apiClient
     * @param IvyLogger $logger
     */
    public function __construct(
        OrderTransactionStateHandler $transactionStateHandler,
        EntityRepositoryInterface $orderRepository,
        createIvyOrderData $createIvyOrderData,
        EntityRepositoryInterface $ivyPaymentSessionRepository,
        ConfigHandler $configHandler,
        ApiClient $apiClient,
        IvyLogger $logger
    ) {
        $this->transactionStateHandler = $transactionStateHandler;
        $this->orderRepository = $orderRepository;
        $this->createIvyOrderData = $createIvyOrderData;
        $this->ivyPaymentSessionRepository = $ivyPaymentSessionRepository;
        $this->configHandler = $configHandler;

        $this->logger = $logger;
        $this->apiClient = $apiClient;
    }

    /**
     * @param AsyncPaymentTransactionStruct $transaction
     * @param RequestDataBag $dataBag
     * @param SalesChannelContext $salesChannelContext
     * @return RedirectResponse
     * @throws GuzzleException
     */
    public function pay(AsyncPaymentTransactionStruct $transaction, RequestDataBag $dataBag, SalesChannelContext $salesChannelContext): RedirectResponse
    {
        if ($dataBag->get('express', false) === true) {
            // pseudo pay: immediately return url to finalize transaction
            $returnUrl = $transaction->getReturnUrl();
            $returnUrl = \str_replace('///', '//', $returnUrl);
            return new RedirectResponse($returnUrl);
        }

        $redirectUrl = '';
        // Method that sends the return URL to the external gateway and gets a redirect URL back
        try {
            $response = $this->createCheckoutSession($transaction, $salesChannelContext);

            if (\is_array($response) && !empty($response['redirectUrl'])) {
                $redirectUrl = $response['redirectUrl'];
                $this->writeDb($transaction, $response, $salesChannelContext);
            }
        } catch (\Exception $e) {
            throw new AsyncPaymentProcessException(
                $transaction->getOrderTransaction()->getId(),
                'An error occurred during the communication with external payment gateway' . \PHP_EOL . $e->getMessage()
            );
        }

        // Redirect to external gateway
        return new RedirectResponse($redirectUrl);
    }

    /**
     * @psalm-suppress InvalidArrayAccess
     * @psalm-suppress RedundantConditionGivenDocblockType
     */
    public function finalize(AsyncPaymentTransactionStruct $transaction, Request $request, SalesChannelContext $salesChannelContext): void
    {

        $transactionId = $transaction->getOrderTransaction()->getId();

        // Example check if the user cancelled. Might differ for each payment provider
        if ($request->query->getBoolean('cancel')) {
            throw new CustomerCanceledAsyncPaymentException(
                $transactionId,
                'Customer canceled the payment on the Ivy page'
            );
        }

        // Example check for the actual status of the payment. Might differ for each payment provider
        $paymentState = $request->query->getAlpha('status');
        if (empty($paymentState)) {
            /** @var array $payload */
            $payload = $request->request->get('payload');
            if ($payload !== null && isset($payload['status'])) {
                $paymentState = $payload['status'];
            }
        }

        $context = $salesChannelContext->getContext();

        switch ($paymentState) {
            case 'failed':
                $this->transactionStateHandler->fail($transactionId, $context);
                break;

            case 'canceled':
                $this->transactionStateHandler->cancel($transactionId, $context);
                break;

            case 'processing':
                $this->transactionStateHandler->process($transactionId, $context);
                break;

            case 'authorised':
            case 'waiting_for_payment':
                $this->transactionStateHandler->authorize($transactionId, $context);
                break;

            case 'paid':
                $this->transactionStateHandler->paid($transactionId, $context);
                break;

            case 'disputed':
            case 'in_refund':
                $this->transactionStateHandler->chargeback($transactionId, $context);
                break;

            case 'refunded':
                $this->transactionStateHandler->refund($transactionId, $context);
                break;

            case 'in_dispute':
            default:
                break;
        }
    }

    /**
     * @param AsyncPaymentTransactionStruct $transaction
     * @param SalesChannelContext $salesChannelContext
     * @return array|false
     * @throws \Doctrine\DBAL\Exception
     * @throws \WizmoGmbh\IvyPayment\Exception\IvyApiException
     *
     * @return false|mixed
     * @psalm-suppress PossiblyNullArgument
     * @psalm-suppress PossiblyUndefinedVariable
     */
    private function createCheckoutSession(AsyncPaymentTransactionStruct $transaction, SalesChannelContext $salesChannelContext)
    {
        $this->logger->setLevel($this->configHandler->getLogLevel($salesChannelContext));
        $this->logger->info('create normal ivy session');
        $config = $this->configHandler->getFullConfig($salesChannelContext);

        $encoders = [new XmlEncoder(), new JsonEncoder()];
        $normalizers = [new CustomObjectNormalizer()];
        $serializer = new Serializer($normalizers, $encoders);

        $order = $transaction->getOrder();

        if ($order->getBillingAddress() === null || $order->getCurrency() === null) {
            $order = $this->getOrderData($order, $salesChannelContext);
        }

        $data = $this->createIvyOrderData->getSessionCreateDataFromOrder($order, $config);
        $returnUrl = $transaction->getReturnUrl();
        $returnUrl = \str_replace('///', '//', $returnUrl);
        $token = $this->getToken($returnUrl);
        $this->logger->info('found token ' . $token);
        if ($token !== null) {
            $data->setMetadata(['_sw_payment_token' => $token]);
            $data->setVerificationToken($token);
            $jsonContent = $serializer->serialize($data, 'json');
            return $this->apiClient->sendApiRequest('checkout/session/create', $config, $jsonContent);
        }

        return false;
    }

    private function getToken(string $returnUrl): ?string
    {
        $query = \parse_url($returnUrl, \PHP_URL_QUERY);
        \parse_str((string) $query, $params);

        return $params['_sw_payment_token'] ?? null;
    }

    private function writeDb(AsyncPaymentTransactionStruct $transaction, array $content, SalesChannelContext $salesChannelContext): void
    {
        if (!empty($content['redirectUrl'])) {
            $this->ivyPaymentSessionRepository->create([
                [
                    'id' => Uuid::randomHex(),
                    'status' => 'createOrder',
                    'swOrderId' => $transaction->getOrder()->getId(),
                    'ivySessionId' => $content['id'],
                ],
            ], $salesChannelContext->getContext());
        }
    }

    /**
     * @return mixed|null
     */
    private function getOrderData(OrderEntity $order, SalesChannelContext $salesChannelContext)
    {
        $criteria = (new Criteria())
            ->addFilter(new EqualsFilter('id', $order->getId()))
            ->addFilter(new EqualsFilter('versionId', $order->getVersionId()))
            ->addAssociation('transactions.paymentMethod')
            ->addAssociation('deliveries.shippingMethod')
            ->addAssociation('orderCustomer.customer')
            ->addAssociation('lineItems')
            ->addAssociation('lineItems.cover')
            ->addAssociation('addresses')
            ->addAssociation('currency')
            ->addAssociation('addresses.country')
            ->addAssociation('addresses.countryState')
            ->addAssociation('transactions.paymentMethod')
            ->addAssociation('deliveries.shippingMethod')
            ->addAssociation('billingAddress.salutation')
            ->addAssociation('billingAddress.country')
            ->addAssociation('billingAddress.countryState')
            ->addAssociation('deliveries.shippingOrderAddress.salutation')
            ->addAssociation('deliveries.shippingOrderAddress.country')
            ->addAssociation('deliveries.shippingOrderAddress.countryState')
            ->setTotalCountMode(Criteria::TOTAL_COUNT_MODE_EXACT);

        return $this->orderRepository->search($criteria, $salesChannelContext->getContext())->first();
    }
}
