{*
 * @author    josra
 * @copyright josra
 *}
{capture name='jeg_cfilter'}
  <div class="btn-group jeg-cfilter" role="group">
    <a href="{$jeg_url_active|escape:'html':'UTF-8'}" class="btn btn-default{if !$jeg_show_all} active{/if}">{l s='Solo activos' mod='josraenviogratis'} ({$jeg_count_active|intval})</a>
    <a href="{$jeg_url_all|escape:'html':'UTF-8'}" class="btn btn-default{if $jeg_show_all} active{/if}">{l s='Todos' mod='josraenviogratis'} ({$jeg_count_total|intval})</a>
  </div>
{/capture}
{if $jeg_ps9}
  <div class="panel">
    <div class="panel-heading"><i class="icon-truck"></i> {l s='Transportistas' mod='josraenviogratis'}</div>
    <div class="alert alert-success">
      {l s='En PrestaShop 9 el módulo ajusta el porte con el hook del carrito, después de que cada transportista (también los de módulos como MBE o Correos Express) haya calculado su precio. No se modifica ningún transportista y todos se pueden usar en las reglas.' mod='josraenviogratis'}
    </div>
    {$smarty.capture.jeg_cfilter nofilter}

    <table class="table">
      <thead>
        <tr>
          <th>{l s='Transportista' mod='josraenviogratis'}</th>
          <th class="text-center">{l s='Referencia' mod='josraenviogratis'}</th>
          <th>{l s='Precio calculado por' mod='josraenviogratis'}</th>
          <th class="text-center">{l s='Activo' mod='josraenviogratis'}</th>
        </tr>
      </thead>
      <tbody>
        {foreach $jeg_carriers as $c}
          <tr>
            <td>{$c.name|escape:'html':'UTF-8'}</td>
            <td class="text-center">{$c.id_reference|intval}</td>
            <td>{if $c.module}{$c.module|escape:'html':'UTF-8'}{else}{l s='PrestaShop (rangos)' mod='josraenviogratis'}{/if}</td>
            <td class="text-center">{if $c.active}<i class="icon-check text-success"></i>{else}<i class="icon-remove text-danger"></i>{/if}</td>
          </tr>
        {/foreach}
      </tbody>
    </table>
  </div>
{else}
  <form method="post" action="{$jeg_form_url|escape:'html':'UTF-8'}" class="form-horizontal">
    <div class="panel">
      <div class="panel-heading"><i class="icon-truck"></i> {l s='Transportistas gestionados por el módulo' mod='josraenviogratis'}</div>

      <div class="alert alert-info">
        <p>{l s='En PrestaShop 1.7 y 8 no hay ningún hook para cambiar el precio del transporte sin override. Por eso, los transportistas que actives aquí pasan a calcularse "a través" del módulo: PrestaShop sigue calculando con sus rangos de peso o precio y le pasa ese importe al módulo, que lo devuelve igual o rebajado según tus reglas. Si ninguna regla se cumple, el precio es exactamente el de siempre.' mod='josraenviogratis'}</p>
        <p>{l s='Los valores originales de cada transportista se guardan y se restauran al desinstalar el módulo o con el botón de abajo. Si desactivas el módulo, los transportistas siguen funcionando con su precio normal.' mod='josraenviogratis'}</p>
      </div>
      <div class="alert alert-warning">
        {l s='Importante: no borres la carpeta del módulo por FTP sin desinstalarlo antes. Si la carpeta desaparece, PrestaShop no encuentra el módulo y los transportistas gestionados dejan de salir en el pedido. Lo mismo al migrar la tienda: instala el módulo en la tienda nueva o pulsa antes "Devolver todos a su estado original".' mod='josraenviogratis'}
      </div>

      {$smarty.capture.jeg_cfilter nofilter}

      <table class="table">
        <thead>
          <tr>
            <th>{l s='Transportista' mod='josraenviogratis'}</th>
            <th class="text-center">{l s='Referencia' mod='josraenviogratis'}</th>
            <th>{l s='Tipo' mod='josraenviogratis'}</th>
            <th>{l s='Estado ahora' mod='josraenviogratis'}</th>
            <th class="text-center">{l s='Gestionar con el módulo' mod='josraenviogratis'}</th>
          </tr>
        </thead>
        <tbody>
          {foreach $jeg_carriers as $c}
            <tr>
              <td>
                {$c.name|escape:'html':'UTF-8'}
                {if !$c.active}<span class="label label-default">{l s='desactivado' mod='josraenviogratis'}</span>{/if}
                {if $c.is_free}<span class="label label-default">{l s='ya es gratuito' mod='josraenviogratis'}</span>{/if}
              </td>
              <td class="text-center">{$c.id_reference|intval}</td>
              <td>
                {if $c.orig_type == 'native'}
                  {l s='Nativo (rangos de PrestaShop)' mod='josraenviogratis'}
                {elseif $c.orig_type == 'module_ranges'}
                  {l s='Creado por el módulo' mod='josraenviogratis'} <strong>{$c.module|escape:'html':'UTF-8'}</strong>, {l s='con rangos de PrestaShop' mod='josraenviogratis'}
                {else}
                  {l s='Calculado por el módulo' mod='josraenviogratis'} <strong>{$c.module|escape:'html':'UTF-8'}</strong>
                {/if}
              </td>
              <td>
                {if $c.managed}
                  <span class="label label-success">{l s='Gestionado' mod='josraenviogratis'}</span>
                {elseif !$c.can}
                  <span class="label label-danger">{l s='No compatible en 1.7/8' mod='josraenviogratis'}</span>
                  <p class="help-block">{l s='Su precio lo calcula otro módulo (normalmente por API). Cambiarlo rompería los envíos y etiquetas de ese módulo. En PrestaShop 9 sí funciona.' mod='josraenviogratis'}</p>
                {else}
                  <span class="label label-default">{l s='Sin gestionar: las reglas no le afectan' mod='josraenviogratis'}</span>
                {/if}
              </td>
              <td class="text-center">
                {if $c.can}
                  <span class="switch prestashop-switch fixed-width-lg">
                    <input type="radio" name="jeg_carrier[{$c.id_reference|intval}]" id="jeg_carrier_{$c.id_reference|intval}_on" value="1"{if $c.wanted} checked="checked"{/if} />
                    <label for="jeg_carrier_{$c.id_reference|intval}_on">{l s='Sí' mod='josraenviogratis'}</label>
                    <input type="radio" name="jeg_carrier[{$c.id_reference|intval}]" id="jeg_carrier_{$c.id_reference|intval}_off" value="0"{if !$c.wanted} checked="checked"{/if} />
                    <label for="jeg_carrier_{$c.id_reference|intval}_off">{l s='No' mod='josraenviogratis'}</label>
                    <a class="slide-button btn"></a>
                  </span>
                  {if $c.orig_type == 'module_ranges'}
                    <p class="help-block text-left">{l s='Si lo activas, este transportista deja de figurar como de su módulo. Comprueba después que ese módulo sigue creando bien los envíos y las etiquetas.' mod='josraenviogratis'}</p>
                  {/if}
                {else}
                  &mdash;
                {/if}
              </td>
            </tr>
          {foreachelse}
            <tr><td colspan="5">{if $jeg_show_all}{l s='No hay transportistas.' mod='josraenviogratis'}{else}{l s='No hay transportistas activos. Pulsa "Todos" para ver los desactivados.' mod='josraenviogratis'}{/if}</td></tr>
          {/foreach}
        </tbody>
      </table>

      <div class="panel-footer">
        <button type="submit" name="submitJegCarriers" class="btn btn-primary pull-right">
          <i class="icon-save"></i> {l s='Guardar' mod='josraenviogratis'}
        </button>
        <button type="submit" name="submitJegReleaseAll" class="btn btn-default"
                onclick="return confirm('{l s='Todos los transportistas volverán a como estaban antes de instalar el módulo y las reglas dejarán de aplicarse. ¿Seguir?' mod='josraenviogratis' js=1}');">
          <i class="icon-undo"></i> {l s='Devolver todos a su estado original' mod='josraenviogratis'}
        </button>
      </div>
    </div>
  </form>
{/if}
