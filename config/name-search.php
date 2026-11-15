<?php

// A separate key from APP_KEY (which Laravel's own 'encrypted' casts use) - keeping the
// search index's key distinct means rotating one doesn't force rotating the other, and a
// leak of one doesn't automatically expose the other. Falls back to APP_KEY so the app
// still works with no extra setup; set NAME_SEARCH_KEY in production for real separation.
return [
    'key' => env('NAME_SEARCH_KEY', env('APP_KEY')),
];
