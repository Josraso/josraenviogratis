{*
 * Panel informativo del pedido. No crea nada: solo deja constancia de qué regla de este
 * módulo decidió el porte.
 *
 * @author    josra
 * @copyright josra
 *}
{capture name='jeg_order_body'}
  <p class="{if $jeg_order.new_layout}mb-1{/if}">
    {if $jeg_order.free}
      {l s='Portes gratis por la regla' mod='josraenviogratis'}
    {else}
      {l s='Porte rebajado por la regla' mod='josraenviogratis'}
    {/if}
    <strong>«{$jeg_order.rule_name|escape:'html':'UTF-8'}»</strong>
    <span class="text-muted">(#{$jeg_order.id_rule|intval})</span>
  </p>
  <p class="{if $jeg_order.new_layout}mb-1{/if}">
    {if $jeg_order.before}
      {l s='Porte normal' mod='josraenviogratis'} <s>{$jeg_order.before|escape:'html':'UTF-8'}</s> &rarr;
    {/if}
    {l s='Cobrado' mod='josraenviogratis'} <strong>{$jeg_order.after|escape:'html':'UTF-8'}</strong>
  </p>
  <p class="text-muted small {if $jeg_order.new_layout}mb-0{/if}">
    {l s='Es una regla del módulo Envío gratis con reglas, no un descuento: el pedido no lleva ninguna regla de carrito.' mod='josraenviogratis'}
  </p>
{/capture}
{if $jeg_order.new_layout}
  <div class="card mt-2">
    <div class="card-header">
      <h3 class="card-header-title">{l s='Envío gratis con reglas' mod='josraenviogratis'}</h3>
    </div>
    <div class="card-body">
      {$smarty.capture.jeg_order_body nofilter}
    </div>
  </div>
{else}
  <div class="panel">
    <div class="panel-heading"><i class="icon-truck"></i> {l s='Envío gratis con reglas' mod='josraenviogratis'}</div>
    {$smarty.capture.jeg_order_body nofilter}
  </div>
{/if}
