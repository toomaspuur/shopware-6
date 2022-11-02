import IvyPaymentPlugin from "./offcanvas-cart.plugin";
import DomAccess from 'src/helper/dom-access.helper';
import HttpClient from 'src/service/http-client.service';
import ElementLoadingIndicatorUtil from 'src/utility/loading-indicator/element-loading-indicator.util';
import AjaxOffCanvas from 'src/plugin/offcanvas/ajax-offcanvas.plugin';

export default class IvyCheckoutConfirmPlugin extends IvyPaymentPlugin {
    init() {
        super.init();
        this._client = new HttpClient();
        this.confirmOrderForm = DomAccess.querySelector(document, '#confirmOrderForm');
        this.confirmFormSubmit = DomAccess.querySelector(document, '#confirmOrderForm button[type="submit"]');
        this.confirmFormSubmit.addEventListener('click', this.onConfirmOrderSubmit.bind(this));
    }
    onConfirmOrderSubmit() {
        if (!this.confirmOrderForm.checkValidity()) {
            return;
        }
        event.preventDefault();
        this.createSession();
    }

    createSession() {
        ElementLoadingIndicatorUtil.create(document.body);
        AjaxOffCanvas.close();
        this._client.get(this.el.dataset.action, function(response) {
            ElementLoadingIndicatorUtil.remove(document.body);
            let decodedResponse = JSON.parse(response);
            if (decodedResponse.redirectUrl) {
                if (typeof startIvyCheckout === 'function') {
                    startIvyCheckout(decodedResponse.redirectUrl, 'popup');
                } else {
                    console.error('startIvyCheckout is not defined');
                }
            } else {
                console.error('cannot create ivy session');
                location.reload();
            }
        });
    }
}
