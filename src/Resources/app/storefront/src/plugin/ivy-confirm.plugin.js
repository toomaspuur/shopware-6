import IvyPaymentPlugin from "./offcanvas-cart.plugin";
import DomAccess from 'src/helper/dom-access.helper';
import StoreApiClient from 'src/service/store-api-client.service';
import FormSerializeUtil from 'src/utility/form/form-serialize.util';
import ElementLoadingIndicatorUtil from 'src/utility/loading-indicator/element-loading-indicator.util';

export default class IvyCheckoutConfirmPlugin extends IvyPaymentPlugin {
    init() {
        super.init();
        this._client = new StoreApiClient();
        this.confirmOrderForm = DomAccess.querySelector(document, '#confirmOrderForm');
        this.confirmFormSubmit = DomAccess.querySelector(document, '#confirmOrderForm button[type="submit"]');
        this.responseHandler = this.handlePaymentAction;
        this.confirmFormSubmit.addEventListener('click', this.onConfirmOrderSubmit.bind(this));
    }
    onConfirmOrderSubmit() {
        if (!this.confirmOrderForm.checkValidity()) {
            return;
        }
        event.preventDefault();
        this.createOrder();
    }

    createOrder() {
        ElementLoadingIndicatorUtil.create(document.body);
        this._client.post(
            this.el.dataset.checkoutOrderUrl,
            FormSerializeUtil.serialize(this.confirmOrderForm),
            this.initPayment.bind(this));
    }

    initPayment(response) {
        let order;
        try {
            order = JSON.parse(response);
        } catch (error) {
            ElementLoadingIndicatorUtil.remove(document.body);
            console.error(error);
            return;
        }
        this._client.post(
            this.el.dataset.checkoutPayUrl,
            JSON.stringify({
                'orderId': order.id,
                'finishUrl': this.el.dataset.checkoutFinishUrl.replace('-orderId-', order.id),
                'errorUrl': this.el.dataset.checkoutErrorUrl.replace('-orderId-', order.id),
            }),
            this.handlePaymentresponse.bind(this));
    }

    handlePaymentresponse(response) {
        try {
            let decodedResponse = JSON.parse(response);
            if (decodedResponse.redirectUrl) {
                if (typeof startIvyCheckout === 'function') {
                    startIvyCheckout(decodedResponse.redirectUrl, 'popup');
                } else {
                    console.error('startIvyCheckout is not defined');
                }
            } else {
                console.error('cannot create ivy session');
            }
        } catch (error) {
            ElementLoadingIndicatorUtil.remove(document.body);
            console.error(error);
        }
        ElementLoadingIndicatorUtil.remove(document.body);
    }
}
