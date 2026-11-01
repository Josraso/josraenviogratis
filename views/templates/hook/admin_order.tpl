{*
 * @author    josra
 * @copyright josra
 *}
{if $jeg_order.new_layout}
  <div class="card mt-2">
    <div class="card-header">
      <h3 class="card-header-title">{l s='Portes' mod='josraenviogratis'}</h3>
    </div>
    <div class="card-body">
      <p class="mb-1">{l s='Regla aplicada:' mod='josraenviogratis'} <strong>{$jeg_order.rule_name|escape:'html':'UTF-8'}</strong> (#{$jeg_order.id_rule|intval})</p>
      <p class="mb-0">
        {if $jeg_order.before}{l s='Porte normal:' mod='josraenviogratis'} {$jeg_order.before|escape:'html':'UTF-8'} &rarr; {/if}
        {l s='cobrado:' mod='josraenviogratis'} <strong>{$jeg_order.after|escape:'html':'UTF-8'}</strong>
      </p>
    </div>
  </div>
{else}
  <div class="panel">
    <div class="panel-heading"><i class="icon-truck"></i> {l s='Portes' mod='josraenviogratis'}</div>
    <p>{l s='Regla aplicada:' mod='josraenviogratis'} <strong>{$jeg_order.rule_name|escape:'html':'UTF-8'}</strong> (#{$jeg_order.id_rule|intval})</p>
    <p>
      {if $jeg_order.before}{l s='Porte normal:' mod='josraenviogratis'} {$jeg_order.before|escape:'html':'UTF-8'} &rarr; {/if}
      {l s='cobrado:' mod='josraenviogratis'} <strong>{$jeg_order.after|escape:'html':'UTF-8'}</strong>
    </p>
  </div>
{/if}
