import HttpClient from 'src/service/http-client.service';
import IvyPaymentPlugin from "./offcanvas-cart.plugin";
import Iterator from 'src/helper/iterator.helper';
import AjaxOffCanvas from 'src/plugin/offcanvas/ajax-offcanvas.plugin';
import ElementLoadingIndicatorUtil from 'src/utility/loading-indicator/element-loading-indicator.util';

export default class IvyExpressCheckoutPlugin extends IvyPaymentPlugin {

    init() {
        super.init();
        this._client = new HttpClient();
        this.addEventListeners();
    }

    addEventListeners() {
        this.el.addEventListener('click', this.createSession.bind(this));
    }

    createSession() {
        ElementLoadingIndicatorUtil.create(document.body);
        AjaxOffCanvas.close();
        if (this.el.dataset.addToIvycart) {
            const form = document.querySelector('#productDetailPageBuyProductForm');
            if (form) {
                fetch(form.action, {
                    method: form.method,
                    body: new FormData(form)
                }).then(function(response) {
                    if (response.ok) {
                        this._fetchCartWidgets();
                        this.openIviPopup();
                    } else {
                        ElementLoadingIndicatorUtil.remove(document.body);
                    }
                }.bind(this));
            }
        } else {
            this.openIviPopup();
        }
    }

    /**
     * Update all registered cart widgets
     *
     * @private
     */
    _fetchCartWidgets() {
        const CartWidgetPluginInstances = PluginManager.getPluginInstances('CartWidget');
        Iterator.iterate(CartWidgetPluginInstances, instance => instance.fetch());

        this.$emitter.publish('fetchCartWidgets');
    }

    openIviPopup() {
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
