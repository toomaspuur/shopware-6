import Plugin from 'src/plugin-system/plugin.class';

export default class IvyPaymentPlugin extends Plugin {


    init() {
        var scriptSrc = this.el.getElementsByTagName("script")[0].src;

        this.loadScript(scriptSrc, this.docLoaded);

    }

    loadScript(url, callback) {
        var div = this.el;
        var script = document.createElement('script');
        script.async = false;
        script.type = 'text/javascript';
        script.src = url;

        // Then bind the event to the callback function.
        // There are several events for cross browser compatibility.
        script.onreadystatechange = callback;
        script.onload = callback;

        // Fire the loading
        div.appendChild(script);
    }

    docLoaded() {
        window.document.dispatchEvent(new Event("DOMContentLoaded", {
            bubbles: true,
            cancelable: true
        }));
    }


}
