# Requisitos de Negocio - Módulo Update Stock

Este documento detalla los requisitos funcionales, lógica de negocio y reglas técnicas que rigen el funcionamiento del módulo de actualización de stock a partir de archivos de texto con códigos EAN.

---

## 1. Objetivo General
El módulo permite a los administradores de la tienda actualizar el stock físico y disponible de los productos y combinaciones de PrestaShop mediante la subida de uno o varios archivos de texto generados típicamente por lectores de códigos de barras (scanners). Además, realiza chequeos automáticos de consistencia y permite gestionar copias de seguridad de las tablas afectadas para prevenir la pérdida de datos.

---

## 2. Formato de los Archivos de Inventario
*   **Subida Múltiple**: La interfaz debe permitir la subida simultánea de uno o varios archivos de texto.
*   **Formato de línea**: Cada línea de los archivos cargados tiene el formato `EAN;;;;;` (separadores vacíos tras el EAN provenientes de scanners de mano) o simplemente `EAN`.
*   **Lógica de conteo**: 
    *   Si un EAN aparece $X$ veces a lo largo de los archivos seleccionados, se considera que la cantidad física contada para ese EAN es $X$.
    *   No se especifica una cantidad explícita en el archivo; el inventario se calcula por acumulación/conteo de líneas.
*   **Validaciones Previas a la Subida**:
    *   Tipo de archivo: solo archivos de texto (.txt).
    *   Tamaño de archivo: límite máximo razonable para evitar caídas del servidor.
    *   Estructura: Validar que cada línea empiece con un EAN numérico válido seguido de los delimitadores correspondientes.
    *   Si un archivo no supera las validaciones, debe ser rechazado inmediatamente sin guardarse en el servidor.

---

## 3. Mapeo de EAN a Base de Datos
La relación entre el código EAN del archivo y el catálogo de PrestaShop se realiza mediante las siguientes tablas:
1.  **Productos con Combinaciones**: Se busca el EAN en `ps_product_attribute.ean13` para obtener el `id_product` y el `id_product_attribute`.
2.  **Productos Simples (Sin Combinaciones)**: Se busca el EAN en `ps_product.ean13` para obtener el `id_product` (asociado a `id_product_attribute = 0`).

---

## 4. Lógica de Actualización de Stock

### Ámbito de la Actualización (Selector en Pantalla)
*   **Tienda Única**: Modifica únicamente el registro de stock correspondiente al `id_shop` activo en la tabla `ps_stock_available`.
*   **Multitienda**: Modifica los registros de stock de manera global o según corresponda, actualizando y sumando los valores acumulados en la tabla `ps_stock`.

### Tipo de Inventario (Checkbox en Pantalla)
*   **Inventario Total (Poner a 0 los productos no listados)**:
    *   Si está **activo**, cualquier producto o combinación registrado en la base de datos cuyo EAN **no** esté en los archivos procesados se considerará agotado y su stock se modificará a `0`.
    *   Si está **inactivo** (Inventario Parcial), los productos/combinaciones que no aparezcan en los archivos subidos mantendrán su stock intacto en la base de datos.

### Campos a Actualizar
Para cada registro correspondiente en `ps_stock_available`:
*   `physical_quantity` $\rightarrow$ Se actualiza con el conteo físico del inventario.
*   `quantity` (Stock Disponible) $\rightarrow$ Se calcula como:
    $$\text{quantity} = \text{physical\_quantity} - \text{reserved\_quantity}$$
    *(Donde `reserved_quantity` es la cantidad reservada para pedidos abiertos).*

### Coherencia de Combinaciones (`id_product_attribute = 0`)
*   En PrestaShop, el registro con `id_product_attribute = 0` representa el stock total acumulado de un producto.
*   El módulo no debe actualizar este registro de manera aislada con valores del archivo. Al finalizar el inventario, debe recalcularse para que sea la suma exacta de las cantidades de todas las combinaciones reales del producto.

---

## 5. Informes CSV Generados
Tras la ejecución (o previsualización) del inventario, se deben generar e indicar enlaces para descargar los siguientes informes CSV:
1.  **Informe de Comparación (Antes vs. Después)**:
    *   Campos: `EAN`, `Cantidad antes`, `Cantidad después`, `Nombre del producto` (desde `ps_product_lang`).
2.  **Informe de Productos Desactivados**:
    *   Aquellos productos simples o combinaciones totales (`id_product_attribute = 0`) que han quedado con stock `0`. 
    *   *Nota*: De acuerdo con los requisitos, los productos que queden a 0 deben desactivarse automáticamente poniendo `ps_product_shop.active = 0`.
3.  **Informe de EAN Inexistentes**:
    *   EANs que estaban presentes en los archivos pero no se encontraron en la base de datos de la tienda.
4.  **Informe de Inconsistencias**:
    *   Detalle de discrepancias detectadas por los tests (ver Sección 7).

---

## 6. Copias de Seguridad (Backups)
*   **Creación Automática**: Antes de ejecutar cualquier actualización o modificación de stock en la base de datos, el módulo debe realizar de manera obligatoria una copia de seguridad en formato SQL de las tablas afectadas:
    *   `ps_stock_available`
    *   `ps_stock`
    *   `ps_product`
    *   `ps_product_shop`
*   **Garantía de Integridad**: El módulo no debe borrar ni reemplazar tablas de producción ni confirmarse sin asegurar previamente que la copia de seguridad se ha generado de forma correcta y que se encuentra en un estado recuperable.
*   **Gestión en Interfaz**: Los archivos de backup se guardan en el servidor identificados por fecha y hora (timestamp). El usuario debe poder ver el listado de backups disponibles en la pantalla principal y elegir individualmente si **Restaurarlos** o **Eliminarlos**.

---

## 7. Pruebas de Consistencia y Acciones Correctivas
Tras la ejecución del inventario y antes de dar por finalizado el proceso, el módulo debe ejecutar pruebas de consistencia automáticas. 

### Reglas de Validación
1.  **Suma de Combinaciones**: Para cada `id_product` afectado que tenga combinaciones, verificar que:
    $$\text{quantity}_{(id\_product\_attribute = 0)} = \sum \text{quantity}_{(id\_product\_attribute \neq 0)}$$
2.  **Evitar Stocks Negativos**: Ningún registro en `ps_stock_available` puede quedar con `physical_quantity < 0` o `quantity < 0`. En caso de detectarse, se fuerza a `0` y se registra en el informe y en el log.
3.  **Integridad de la Ecuación**: Verificar que `quantity` sea igual a `physical_quantity - reserved_quantity`. Si no se cumple, se recalcula la cantidad disponible (`quantity`) y se registra la corrección.
4.  **Desactivación por Stock Cero**: Confirmar que los productos con cantidad total igual a 0 estén configurados como inactivos (`active = 0` en `ps_product_shop`). Si siguen activos, el módulo los desactiva y registra el cambio.
5.  **Validación de EAN**: Los EANs inexistentes no deben alterar ninguna tabla de la base de datos y aparecerán solo en el reporte de desconocidos.
6.  **Validación en Multitienda**: Verificar que no se mezclen las cantidades entre tiendas distintas y que la suma agregada en `ps_stock` para una tienda coincida con la suma de `ps_stock_available` de la misma.

### Tratamiento de Errores Críticos
Si se detecta una inconsistencia grave (por ejemplo, stocks negativos persistentes o incoherencias insalvables entre `ps_stock_available` y `ps_stock`):
*   Se aborta la confirmación del inventario (haciendo un rollback de la transacción si es posible).
*   Se muestra una advertencia crítica en pantalla.
*   Se ofrece al usuario la restauración inmediata del backup y la descarga de los informes para su análisis técnico.

---

## 8. Elementos Clave de la Interfaz de Usuario
*   **Flujo de Previsualización (Analizar / Preview)**: 
    *   El usuario sube y selecciona los archivos y el modo de inventario.
    *   Al pulsar "Analizar", el sistema procesa los archivos y la base de datos y genera una previsualización de los cambios que se van a aplicar (junto con la descarga de un reporte previo).
    *   El botón "Ejecutar Inventario" **no debe estar visible** hasta que el usuario haya ejecutado primero la previsualización y verificado los cambios estimados.
*   **Visor de Logs en Pantalla**:
    *   En la parte inferior de la pantalla principal se incluye un visor de logs internos.
    *   Un menú desplegable permite seleccionar la cantidad de líneas a visualizar (`Sin logs`, `10`, `30`, `50` o `100` últimas líneas).
    *   Si se selecciona una opción numérica, la caja de log se refresca automáticamente cada pocos segundos mediante peticiones Ajax en segundo plano para reflejar el progreso y las operaciones del inventario.
*   **Separación de Correcciones (Apply Fixes)**:
    *   El inventario por defecto solo *reporta* las inconsistencias detectadas sin corregirlas automáticamente en la base de datos.
    *   Se muestra un botón específico **"Apply Suggested Fixes"** (Aplicar Correcciones Sugeridas) que permite al administrador lanzar de forma manual y controlada el proceso de subsanación de inconsistencias (corrección de stock negativo, recálculo de sumas y desactivación de productos agotados).
