<?php

namespace App\Http\Controllers;

use Illuminate\Foundation\Auth\Access\AuthorizesRequests;

abstract class Controller
{
    /**
     * Every ledger screen is gated, so the trait belongs on the base class
     * rather than being remembered per controller. Laravel 11 removed it from
     * the generated skeleton; a controller that forgets to add it back fails
     * open, which is the wrong direction for an app where a Viewer must not be
     * able to post.
     */
    use AuthorizesRequests;
}
