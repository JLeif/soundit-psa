<?php

namespace App\Services\ControlD;

/** A POST was explicitly rejected by the vendor envelope (HTTP 4xx), not an unknown write. */
class ControlDWriteRejectedException extends ControlDClientException {}
