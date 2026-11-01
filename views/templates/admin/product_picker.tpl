{*
 * @author    josra
 * @copyright josra
 *}
<div class="jeg-picker" data-name="{$jeg_picker_name|escape:'html':'UTF-8'}">
  <div class="jeg-chips">
    {foreach $jeg_picker_items as $item}
      <span class="jeg-chip label label-info">
        {$item.label|escape:'html':'UTF-8'}
        <a href="#" class="jeg-chip-del" title="{l s='Quitar' mod='josraenviogratis'}">&times;</a>
        <input type="hidden" name="{$jeg_picker_name|escape:'html':'UTF-8'}[]" value="{$item.id|intval}" />
      </span>
    {/foreach}
  </div>
  <div class="jeg-search-wrap">
    <input type="text" class="form-control jeg-psearch" autocomplete="off"
           placeholder="{l s='Buscar por nombre, referencia o ID…' mod='josraenviogratis'}" />
    <div class="jeg-results list-group"></div>
  </div>
</div>
