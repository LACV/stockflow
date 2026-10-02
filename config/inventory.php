<?php

return [

    /*
    | Umbral de "stock bajo".
    | Cuando el stock de un artículo es menor o igual a este valor se
    | muestra en amarillo (advertencia); con 0 o negativo se muestra en rojo.
    */
    'stock_minimo' => env('INVENTORY_STOCK_MINIMO', 2),

];
