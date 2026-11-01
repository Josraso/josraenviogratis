{*
 * @author    josra
 * @copyright josra
 *}
<div class="panel">
  <div class="panel-heading"><i class="icon-code"></i> {l s='Posiciones' mod='josraenviogratis'}</div>
  <ul>
    <li><strong>{l s='Carrito:' mod='josraenviogratis'}</strong> displayShoppingCartFooter</li>
    <li><strong>{l s='Ventana de producto añadido:' mod='josraenviogratis'}</strong> displayCartModalFooter</li>
    <li><strong>{l s='Paso de transporte:' mod='josraenviogratis'}</strong> displayBeforeCarrier</li>
    <li><strong>{l s='Ficha de producto:' mod='josraenviogratis'}</strong> displayProductAdditionalInfo</li>
  </ul>
  <p>{l s='Si tu tema no llama a alguno de esos hooks o quieres el aviso en otro sitio, añade esto en la plantilla del tema:' mod='josraenviogratis'}</p>
  <pre>{literal}{hook h='displayJosraEnvioGratis'}{/literal}</pre>
  <p class="help-block">{l s='El aviso se actualiza solo cuando el cliente cambia el carrito o el transporte, sin recargar la página.' mod='josraenviogratis'}</p>
</div>
