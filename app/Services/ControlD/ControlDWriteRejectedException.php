<?php

namespace App\Services\ControlD;

/** An explicit HTTP 4xx rejected the request; unlike a timeout, this is not an unknown write. */
class ControlDWriteRejectedException extends ControlDClientException {}
