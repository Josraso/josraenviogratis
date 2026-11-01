{*
 * @author    josra
 * @copyright josra
 *}
<div class="panel jeg-help">
  <div class="panel-heading"><i class="icon-question-circle"></i> {l s='Cómo funciona' mod='josraenviogratis'}</div>

  <h4>{l s='Qué hace' mod='josraenviogratis'}</h4>
  <p>{l s='Pone el transporte gratis, a precio fijo o con un porcentaje de rebaja cuando el carrito cumple las condiciones de una regla. No usa reglas de carrito ni descuentos: cambia el precio del propio transporte. En el pedido, el albarán, la factura de PrestaShop y lo que se importa a FacturaScripts, el transporte sale a 0 € (o al precio rebajado) y no hay ninguna línea de descuento ni IVA de transporte que cuadrar.' mod='josraenviogratis'}</p>

  <h4>{l s='Prioridades' mod='josraenviogratis'}</h4>
  <p>{l s='Las reglas se revisan de menor a mayor número de prioridad. Para cada transportista se aplica la primera que se cumple y las demás se ignoran. Pon arriba las más concretas (por ejemplo, "Baleares desde 150 €") y abajo las generales ("Península desde 50 €").' mod='josraenviogratis'}</p>

  <h4>{l s='Condiciones' mod='josraenviogratis'}</h4>
  <ul>
    <li>{l s='Importe mínimo y máximo: con o sin IVA, solo productos o productos + portes, antes o después de cupones.' mod='josraenviogratis'}</li>
    <li>{l s='Unidades, productos distintos y peso.' mod='josraenviogratis'}</li>
    <li>{l s='Productos que la activan (productos, categorías, marcas o proveedores): basta con que lleve alguno, o tienen que serlo todos, y un mínimo de unidades.' mod='josraenviogratis'}</li>
    <li>{l s='Productos excluidos: o anulan la regla (voluminosos, refrigerados…) o simplemente no cuentan para el importe ni las unidades.' mod='josraenviogratis'}</li>
    <li>{l s='Transportistas, zonas, países y provincias.' mod='josraenviogratis'}</li>
    <li>{l s='Grupos de clientes, particular o empresa (dirección con empresa y NIF-IVA) y solo primer pedido.' mod='josraenviogratis'}</li>
    <li>{l s='Fechas, días de la semana, horario, tiendas y monedas.' mod='josraenviogratis'}</li>
  </ul>
  <p class="help-block">{l s='No se puede condicionar por método de pago: el porte se calcula antes de que el cliente lo elija.' mod='josraenviogratis'}</p>

  <h4>{l s='Lo que ve el cliente' mod='josraenviogratis'}</h4>
  <ul>
    <li>{l s='Mientras no sabemos su dirección: los "Textos que ve el cliente" de las reglas de portes gratis (por ejemplo "Portes gratis a partir de 50 € en Península").' mod='josraenviogratis'}</li>
    <li>{l s='Con dirección: "Te faltan X € para tener portes gratis", calculado con la regla que le corresponde de verdad, con barra de progreso.' mod='josraenviogratis'}</li>
    <li>{l s='Cuando ya los tiene: la etiqueta "¡Tienes portes gratis!" en el carrito. Las opciones de transporte salen como "Gratis" de forma nativa.' mod='josraenviogratis'}</li>
    <li>{l s='En la ficha: "Portes gratis con este producto", solo en las reglas que lo tengan activado.' mod='josraenviogratis'}</li>
  </ul>

  <h4>{l s='Según la versión de PrestaShop' mod='josraenviogratis'}</h4>
  {if $jeg_ps9}
    <p>{l s='Esta tienda es PrestaShop' mod='josraenviogratis'} {$jeg_ps_version|escape:'html':'UTF-8'}. {l s='El módulo usa el hook actionCartGetPackageShippingCost, que se ejecuta después de que cada transportista calcule su precio (también los de módulos como MBE). Vale para todos los transportistas y no se modifica ninguno.' mod='josraenviogratis'}</p>
  {else}
    <p>{l s='Esta tienda es PrestaShop' mod='josraenviogratis'} {$jeg_ps_version|escape:'html':'UTF-8'}. {l s='En 1.7 y 8 no existe ese hook, así que los transportistas que actives en la pestaña Transportistas se calculan a través del módulo. PrestaShop sigue usando sus rangos y el módulo solo rebaja el resultado. Los transportistas cuyo precio calcula otro módulo por su cuenta (por ejemplo, por API) no se pueden usar en esta versión.' mod='josraenviogratis'}</p>
    <p>{l s='Al desinstalar el módulo, o con el botón de la pestaña Transportistas, todo vuelve a como estaba. No borres la carpeta del módulo por FTP sin desinstalarlo antes.' mod='josraenviogratis'}</p>
  {/if}

  <h4>{l s='Pedidos' mod='josraenviogratis'}</h4>
  <p>{l s='Cada pedido guarda qué regla le dio los portes; se ve en la ficha del pedido del back-office.' mod='josraenviogratis'}</p>

  <h4>{l s='Si algo no cuadra' mod='josraenviogratis'}</h4>
  <p>{l s='Usa el Simulador con el ID del carrito: muestra para cada transportista el porte normal, el final y el motivo exacto por el que cada regla se cumple o no.' mod='josraenviogratis'}</p>
</div>
