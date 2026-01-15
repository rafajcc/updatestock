# Update Stock Module (Antigravity)

## description (English)
This PrestaShop module (compatible with 1.7.5+) allows you to update product physical stock by uploading text files containing EAN codes. It is designed for efficient inventory management using physical barcode scanners.

### Features
- **File Upload**: Upload multiple text files with scanned EAN codes.
- **EAN Counting**: Automatically counts occurrences of each EAN to determine physical quantity.
- **Stock Update**: Updates `physical_quantity` and recalculated `quantity` (physical - reserved).
- **Multistore Support**: Select to update only the current shop or apply globally.
- **Total Inventory Mode**: Option to set quantity to 0 for products NOT present in the uploaded files.
- **Consistency Checks**:
    - Validates sum of attribute quantities vs product total.
    - detects and fixes negative stock.
    - Equation integrity check (`quantity = physical - reserved`).
- **Safety First**:
    - **Automatic Backups**: Creates a SQL dump of stock tables before every execution.
    - **Restore**: One-click restore functionality from the interface.
- **Reporting**: Generates detailed CSV reports for every operation (Log, Zeroed Products, Unknown EANs, consistency fixes).

### Installation
1. Zip the `updatestock` folder.
2. Go to PrestaShop Back Office > Modules > Module Manager.
3. Click "Upload a module" and select the zip file.
4. Configure/Use via "Stock Update Inventory" menu (under Catalog or Administration).

### Usage
1. Prepare your inventory text files. Format per line: `EAN;;;;;` (standard scanner output) or just `EAN`.
2. Go to the module interface.
3. Upload files.
4. Select files to process.
5. Choose scope (Single Shop vs Multistore).
6. (Optional) Check "Total Inventory" if this is a full count and you want to zero out missing items.
7. Click "Execute Inventory".
8. Review the success message and download reports.

---

## Descripción (Español)
Este módulo para PrestaShop (compatible con 1.7.5+) permite actualizar el stock físico de los productos subiendo archivos de texto con códigos EAN. Está diseñado para inventarios eficientes usando lectores de códigos de barras.

### Características
- **Subida de Archivos**: Suba múltiples archivos de texto con EANs escaneados.
- **Conteo de EAN**: Cuenta automáticamente las ocurrencias de cada EAN.
- **Actualización de Stock**: Actualiza `physical_quantity` y recalcula `quantity`.
- **Soporte Multitienda**: Actualice solo la tienda actual o globalmente.
- **Modo Inventario Total**: Opción para poner a 0 el stock de productos NO presentes en los archivos.
- **Chequeos de Consistencia**:
    - Valida suma de combinaciones vs total.
    - Detecta y corrige stocks negativos.
    - Verificación de ecuación (`quantity = physical - reserved`).
- **Seguridad**:
    - **Backups Automáticos**: Crea copia de seguridad SQL antes de ejecutar.
    - **Restaurar**: Botón de restauración en un clic.
- **Informes**: Genera CSV detallados (Log, Productos a 0, EAN desconocidos, inconsistencias).

### Instalación
1. Comprima la carpeta `updatestock`.
2. Vaya al Back Office > Módulos > Gestor de Módulos.
3. Suba el archivo zip.
4. Acceda desde el menú "Stock Update Inventory".
