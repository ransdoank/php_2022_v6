<?php

namespace DTApi\Http\Controllers;

use Illuminate\Foundation\Bus\DispatchesJobs;
use Illuminate\Routing\Controller as BaseController;
use Illuminate\Foundation\Validation\ValidatesRequests;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
// -------------------------------------------
// Improvement:
// use Illuminate\Foundation\Auth\Access\AuthorizesResources;

class Controller extends BaseController
{
    // use AuthorizesRequests, AuthorizesResources, DispatchesJobs, ValidatesRequests;
    // -------------------------------------------
    // Improvement:
    //     Deprecated Trait: The AuthorizesResources trait is not used in recent versions of Laravel and actually doesn't exist anymore as of Laravel 5.3 and above. It was included to help handle authorization on resourceful controllers but has since been replaced by policy abilities mapped within the AuthServiceProvider class.
    //     Code Redundancy: The traits AuthorizesRequests and AuthorizesResources would have overlapped in functionality when AuthorizesResources was available, leading to unnecessary redundancy.
    use AuthorizesRequests, DispatchesJobs, ValidatesRequests;

}
