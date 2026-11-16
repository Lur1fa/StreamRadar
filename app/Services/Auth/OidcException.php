<?php

namespace App\Services\Auth;

use RuntimeException;

/**
 * Raised for any OIDC failure. The message is safe to show to the end user;
 * technical detail is logged (scrubbed) at the point of failure instead.
 */
class OidcException extends RuntimeException {}
