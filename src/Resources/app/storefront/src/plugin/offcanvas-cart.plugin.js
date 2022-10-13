import Plugin from "src/plugin-system/plugin.class";

export default class IvyPaymentPlugin extends Plugin {
  init() {
    var scriptElements = this.el.getElementsByTagName("template");

    Array.from(scriptElements).forEach((scriptEle) => {
      let scripts = Array.from(document.querySelectorAll("script")).map(
        (scr) => scr.src
      );

      if (scriptEle.dataset.src) {
        if (!scripts.includes(scriptEle.dataset.src)) {
          this.loadScript(scriptEle, this.docLoaded);
        } else {
          this.docLoaded();
        }
      }
    });
  }

  loadScript(scriptEle, callback) {
    var script = document.createElement("script");
    script.defer = true;
    script.type = "text/javascript";

    // Then bind the event to the callback function.
    // There are several events for cross browser compatibility.
    script.onreadystatechange = callback;
    script.onload = callback;

    // Fire the loading
    script.src = scriptEle.dataset.src;
    document.head.append(script);
  }

  docLoaded() {
    window.document.dispatchEvent(
      new Event("DOMContentLoaded", {
        bubbles: true,
        cancelable: true,
      })
    );
  }
}
