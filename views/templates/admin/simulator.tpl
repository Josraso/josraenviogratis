{*
 * @author    josra
 * @copyright josra
 *}
<div class="panel">
  <div class="panel-heading"><i class="icon-beaker"></i> {l s='Simulador' mod='josraenviogratis'}</div>
  <p>{l s='Mete el ID de un carrito y verás, para cada transportista, cuánto costaría el porte sin el módulo, qué regla se aplica y por qué no se aplican las demás. No cambia nada en el carrito.' mod='josraenviogratis'}</p>
  <form method="post" action="{$jeg_form_url|escape:'html':'UTF-8'}" class="form-inline">
    <div class="form-group">
      <label for="jeg_id_cart">{l s='ID de carrito' mod='josraenviogratis'}</label>
      <input type="text" name="jeg_id_cart" id="jeg_id_cart" class="form-control fixed-width-md" value="{$jeg_id_cart|escape:'html':'UTF-8'}" />
    </div>
    <button type="submit" class="btn btn-primary"><i class="icon-search"></i> {l s='Simular' mod='josraenviogratis'}</button>
  </form>

  {if $jeg_recent}
    <p class="help-block jeg-recent">
      {l s='Últimos carritos:' mod='josraenviogratis'}
      {foreach $jeg_recent as $rc}
        <a href="{$rc.url|escape:'html':'UTF-8'}" class="label label-default" title="{$rc.date|escape:'html':'UTF-8'}">#{$rc.id_cart|intval}{if $rc.customer} {$rc.customer|escape:'html':'UTF-8'}{/if}{if $rc.id_order} ({l s='pedido' mod='josraenviogratis'} {$rc.id_order|intval}){/if}</a>
      {/foreach}
    </p>
  {/if}
</div>

{if $jeg_sim_error}
  <div class="alert alert-warning">{$jeg_sim_error|escape:'html':'UTF-8'}</div>
{/if}

{if $jeg_sim}
  <div class="panel">
    <div class="panel-heading">{l s='Carrito' mod='josraenviogratis'} #{$jeg_sim.id_cart|intval}</div>
    <dl class="dl-horizontal">
      <dt>{l s='Productos distintos' mod='josraenviogratis'}</dt><dd>{$jeg_sim.products|intval}</dd>
      <dt>{l s='Peso' mod='josraenviogratis'}</dt><dd>{$jeg_sim.weight|escape:'html':'UTF-8'}</dd>
      <dt>{l s='Destino' mod='josraenviogratis'}</dt><dd>{$jeg_sim.location|escape:'html':'UTF-8'}</dd>
      <dt>{l s='Cliente' mod='josraenviogratis'}</dt><dd>{$jeg_sim.customer|escape:'html':'UTF-8'}</dd>
    </dl>
    {if $jeg_sim.no_rules}
      <div class="alert alert-info">{l s='No hay reglas activas.' mod='josraenviogratis'}</div>
    {/if}
  </div>

  {foreach $jeg_sim.carriers as $c}
    <div class="panel">
      <div class="panel-heading">
        <i class="icon-truck"></i> {$c.name|escape:'html':'UTF-8'} <small>(ref. {$c.id_reference|intval})</small>
        {if $c.selected}<span class="label label-info">{l s='elegido en el carrito' mod='josraenviogratis'}</span>{/if}
      </div>
      <p>
        {l s='Porte sin el módulo:' mod='josraenviogratis'} <strong>{if $c.raw}{$c.raw|escape:'html':'UTF-8'}{else}&mdash;{/if}</strong>
        &nbsp;&rarr;&nbsp;
        {l s='porte final:' mod='josraenviogratis'} <strong>{$c.final|escape:'html':'UTF-8'}</strong>
        {if $c.rule}&nbsp;<span class="label label-success">{l s='Regla aplicada:' mod='josraenviogratis'} {$c.rule|escape:'html':'UTF-8'}</span>{/if}
      </p>
      {if $c.evals}
        <table class="table">
          <thead>
            <tr>
              <th class="text-center">{l s='Prioridad' mod='josraenviogratis'}</th>
              <th>{l s='Regla' mod='josraenviogratis'}</th>
              <th>{l s='Resultado' mod='josraenviogratis'}</th>
              <th>{l s='Importe calculado' mod='josraenviogratis'}</th>
              <th>{l s='Motivos' mod='josraenviogratis'}</th>
            </tr>
          </thead>
          <tbody>
            {foreach $c.evals as $ev}
              <tr>
                <td class="text-center">{$ev.priority|intval}</td>
                <td>{$ev.name|escape:'html':'UTF-8'}</td>
                <td>
                  {if $ev.applied}
                    <span class="label label-success">{l s='Aplicada' mod='josraenviogratis'}</span>
                  {elseif $ev.ok}
                    <span class="label label-warning">{l s='Se cumple, pero hay otra con más prioridad' mod='josraenviogratis'}</span>
                  {else}
                    <span class="label label-default">{l s='No se cumple' mod='josraenviogratis'}</span>
                  {/if}
                </td>
                <td>{if $ev.amount}{$ev.amount|escape:'html':'UTF-8'}{else}&mdash;{/if}</td>
                <td>
                  {if $ev.reasons}
                    <ul class="list-unstyled">
                      {foreach $ev.reasons as $r}<li>{$r|escape:'html':'UTF-8'}</li>{/foreach}
                    </ul>
                  {else}&mdash;{/if}
                </td>
              </tr>
            {/foreach}
          </tbody>
        </table>
      {/if}
    </div>
  {foreachelse}
    <div class="alert alert-warning">{l s='Ningún transportista activo llega al destino de este carrito.' mod='josraenviogratis'}</div>
  {/foreach}
{/if}
