<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * No item exists with the requested id. Mapped to HTTP 404.
 */
class ItemNotFoundException extends RuntimeException {}
