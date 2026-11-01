/**
 * josraenviogratis — refresca el aviso de portes cuando cambia el carrito o el transporte.
 *
 * @author    josra
 * @copyright josra
 */
(function () {
  'use strict';

  var timer = null;
  var busy = false;
  var pending = false;

  function boxes() {
    return document.querySelectorAll('.josraenviogratis-box[data-jeg-pos]');
  }

  function refresh() {
    if (typeof jegAjaxUrl === 'undefined' || !jegAjaxUrl) {
      return;
    }
    var list = boxes();
    if (!list.length) {
      return;
    }
    if (busy) {
      pending = true;
      return;
    }
    var positions = [];
    var idProduct = 0;
    for (var i = 0; i < list.length; i++) {
      var pos = list[i].getAttribute('data-jeg-pos');
      if (pos === 'modal') {
        continue; // la ventana de "producto añadido" ya llega pintada con el carrito nuevo
      }
      if (positions.indexOf(pos) === -1) {
        positions.push(pos);
      }
      if (pos === 'product') {
        idProduct = parseInt(list[i].getAttribute('data-jeg-product'), 10) || 0;
      }
    }
    if (!positions.length) {
      return;
    }

    busy = true;
    var xhr = new XMLHttpRequest();
    xhr.open('POST', jegAjaxUrl, true);
    xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded; charset=UTF-8');
    xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
    xhr.onreadystatechange = function () {
      if (xhr.readyState !== 4) {
        return;
      }
      busy = false;
      if (xhr.status === 200) {
        var data = null;
        try {
          data = JSON.parse(xhr.responseText);
        } catch (e) {
          data = null;
        }
        if (data && data.html) {
          var current = boxes();
          for (var j = 0; j < current.length; j++) {
            var p = current[j].getAttribute('data-jeg-pos');
            if (Object.prototype.hasOwnProperty.call(data.html, p)) {
              current[j].innerHTML = data.html[p];
            }
          }
        }
      }
      if (pending) {
        pending = false;
        schedule();
      }
    };
    xhr.send(
      'ajax=1&positions=' + encodeURIComponent(positions.join(',')) +
      '&jeg_product=' + encodeURIComponent(idProduct) +
      '&_=' + Date.now()
    );
  }

  function schedule() {
    if (timer) {
      clearTimeout(timer);
    }
    timer = setTimeout(refresh, 150);
  }

  function bind() {
    if (typeof prestashop === 'undefined' || !prestashop || typeof prestashop.on !== 'function') {
      return;
    }
    prestashop.on('updateCart', schedule);
    prestashop.on('updatedCart', schedule);
    prestashop.on('updatedDeliveryForm', schedule);
    prestashop.on('changedCheckoutStep', schedule);
    prestashop.on('updatedAddressForm', schedule);
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', bind);
  } else {
    bind();
  }
})();
