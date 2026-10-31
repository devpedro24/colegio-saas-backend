<?php

return [
    // Executes only the bundled, data-only layout engine. Never a command from a request.
    'node_binary' => env('BULLETIN_NODE_BINARY', 'node'),
    'layout_timeout' => 20,
];
