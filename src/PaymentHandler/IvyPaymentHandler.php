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
use Shopware\Core\Framework\Validation\DataBag\RequestDataBag;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use WizmoGmbh\IvyPayment\Logger\IvyLogger;
use WizmoGmbh\IvyPayment\Express\Service\ExpressService;


class IvyPaymentHandler implements AsynchronousPaymentHandlerInterface
{
    private OrderTransactionStateHandler $transactionStateHandler;

    private EntityRepositoryInterface $orderRepository;

    private IvyLogger $logger;

    private ExpressService $expressService;

    /**
     * @param OrderTransactionStateHandler $transactionStateHandler
     * @param EntityRepositoryInterface $orderRepository
     * @param IvyLogger $logger
     * @param ExpressService $expressService
     */
    public function __construct(
        OrderTransactionStateHandler $transactionStateHandler,
        EntityRepositoryInterface $orderRepository,
        IvyLogger $logger,
        ExpressService $expressService
    ) {
        $this->transactionStateHandler = $transactionStateHandler;
        $this->orderRepository = $orderRepository;

        $this->logger = $logger;
        $this->expressService = $expressService;
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
        $this->logger->debug('!called pay!' . print_r($dataBag, true));

        $returnUrl = $transaction->getReturnUrl();
        $returnUrl = \str_replace('///', '//', $returnUrl);
        $contextToken = $this->getToken($returnUrl);
        $this->logger->debug('contextToken: '.$contextToken);

        $paymentDetails = $dataBag->get('paymentDetails');
        if (!empty($paymentDetails) && !empty($paymentDetails->get('confirmed'))) {
            //Checkoutsession already existing just return
            $this->logger->info('pseudo pay: immediately return url to finalize transaction');
            return new RedirectResponse($returnUrl);
        } else {
            $this->logger->info('checkout needs to be created');
            try {
                $order = $this->getOrderById($dataBag->get('orderId'), $salesChannelContext);
                $returnUrl = $this->expressService->createCheckoutSession(
                    $contextToken,
                    $salesChannelContext,
                    false,
                    $order
                );
                return new RedirectResponse($returnUrl);
            } catch (\Exception $e) {
                throw new AsyncPaymentProcessException(
                    $transaction->getOrderTransaction()->getId(),
                    'An error occurred during the communication with external payment gateway' . \PHP_EOL . $e->getMessage()
                );
            }    
        }
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

    private function getToken(string $returnUrl): ?string
    {
        $query = \parse_url($returnUrl, \PHP_URL_QUERY);
        \parse_str((string) $query, $params);

        return $params['_sw_payment_token'] ?? null;
    }

    /**
     * @return OrderEntity|null
     */
    private function getOrderById(string $id, SalesChannelContext $salesChannelContext): OrderEntity
    {
        $criteria = (new Criteria())
            ->addFilter(new EqualsFilter('id', $id))
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
