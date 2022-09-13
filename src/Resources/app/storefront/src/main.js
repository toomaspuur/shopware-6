
import IvyPaymentOffcanvasCart from './plugin/offcanvas-cart.plugin';
import IvyExpressCheckoutPlugin from "./plugin/ivy-express.plugin";
import IvyCheckoutConfirmPlugin from "./plugin/ivy-confirm.plugin";

const PluginManager = window.PluginManager;

PluginManager.register('IvyPaymentOffcanvasCart', IvyPaymentOffcanvasCart, '#IvyOffcanvasCart');
PluginManager.register('IvyExpressCheckoutPlugin', IvyExpressCheckoutPlugin, '.ivy--express-checkout-btn');
PluginManager.register('IvyCheckoutConfirmPlugin', IvyCheckoutConfirmPlugin, '[data-ivy-checkout="true"]');

// Necessary for the webpack hot module reloading server
if (module.hot) {
    module.hot.accept();
}
