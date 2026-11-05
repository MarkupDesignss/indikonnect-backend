<?php

return [
    // Keep false until OrderController::dispatch/ship/deliver business side effects
    // have been reviewed and integrated. Tracking itself works independently.
    'auto_update_order_status' => (bool) env('INDIAPOST_SYNC_ORDER_STATUS', false),
];
