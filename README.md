# StockFlow

Sistema de gestión de almacén e inventario con valoración a **Precio Promedio Ponderado (PPP)** y cierres contables mensuales. Construido con Laravel 13 y Filament 5.

## Características

- **Catálogo**: artículos, marcas, tipos, departamentos, destinos y proveedores.
- **Movimientos de inventario**: órdenes de entrada, órdenes de salida (despacho) y órdenes de ajuste.
- **Valoración PPP**: costo promedio recalculado en cada entrada; las salidas se valoran al PPP vigente.
- **Cierres mensuales**: snapshot del inventario por artículo, con valor inicial, compras, salidas y valor final.
- **Reportes Excel**: entradas, salidas y movimientos del mes, con vista previa en el panel.
- **Roles y permisos**: control de acceso por rol con Filament Shield.

## Requisitos

- PHP 8.3 o superior
- Composer
- Node.js y npm
- MySQL / MariaDB

## Instalación

```bash
composer install
npm install
cp .env.example .env
php artisan key:generate
```

Configura la conexión a la base de datos en `.env` (`DB_CONNECTION=mysql`, `DB_HOST`, `DB_PORT`, `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD`) y luego:

```bash
php artisan migrate
npm run build
php artisan serve
```

El panel de administración queda disponible en `/admin`.

## Cierres mensuales

Cada cierre toma su inventario inicial del snapshot del mes anterior, formando una cadena de meses:

```
Julio → Agosto → Septiembre → Octubre
```

Reglas:

- **Solo se puede reabrir el último mes cerrado.** Si existe un cierre posterior ya cerrado, su valor inicial dependería del mes reabierto y quedaría desactualizado.
- Al reabrir un mes se pueden crear, editar o eliminar transacciones de ese mes.
- Al **re-cerrar**, se recalculan desde el cierre anterior todos los artículos que tienen movimientos en el mes. Los artículos sin movimientos conservan su snapshot sin cambios.
- Tras re-cerrar un mes, limpia la caché (`php artisan cache:clear`): la vista previa del Excel de un mes cerrado se guarda 24 horas.

La identidad contable de cada cierre debe cumplirse:

```
Inventario final = Inventario inicial + Compras − Salidas
```

## Pruebas

```bash
php artisan test
```

## Licencia

Software propietario. Todos los derechos reservados.
