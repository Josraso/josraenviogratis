{*
 * @author    josra
 * @copyright josra
 *}
<div class="panel jeg-rules-head">
  <div class="row">
    <div class="col-lg-9">
      <p>
        {l s='Cada regla cambia el precio del transporte, no crea ningún descuento: en el pedido, el albarán y la factura el transporte sale a 0 € (o al precio rebajado) y no aparece ninguna línea de descuento.' mod='josraenviogratis'}
      </p>
      <p class="help-block">
        {l s='Las reglas se revisan por prioridad (de menor a mayor número) y, para cada transportista, se aplica la primera que se cumple.' mod='josraenviogratis'}
      </p>
    </div>
    <div class="col-lg-3 text-right">
      <a href="{$jeg_add_url|escape:'html':'UTF-8'}" class="btn btn-primary">
        <i class="icon-plus"></i> {l s='Añadir regla' mod='josraenviogratis'}
      </a>
    </div>
  </div>
  {if !$jeg_count}
    <div class="alert alert-info">{l s='Todavía no hay reglas. Crea la primera con el botón "Añadir regla".' mod='josraenviogratis'}</div>
  {/if}
</div>
