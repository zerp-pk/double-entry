<?php

/**
 * The services call creatorId(), a global helper the host application defines to scope
 * every query to the owning company. The package does not ship it and does not depend
 * on the app, so tests stand one in. Guarded, so the app's own definition always wins
 * when the module runs for real.
 */
if (!function_exists('creatorId')) {
    function creatorId()
    {
        return 1;
    }
}
