<?php

return [

    /*
    |--------------------------------------------------------------------
    | Egg Buffer Quantity
    |--------------------------------------------------------------------
    |
    | The client always sends this many extra eggs beyond the day's real
    | headcount (a spoilage/breakage margin) — e.g. 2500 headcount means
    | 2505 eggs actually purchased and sent. This buffer is a real cost
    | (the extra eggs are bought and paid for) but is never billed to the
    | factory, which pays a fixed rate per person actually served.
    |
    */

    'egg_buffer_quantity' => (int) env('TIFFIN_EGG_BUFFER_QUANTITY', 5),

];
