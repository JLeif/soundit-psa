<?php

namespace App\Services\Mesh;

/**
 * A write Mesh REFUSED at the application layer (HTTP 400) — the server
 * validated the request and declined it. Distinct from MeshClientException
 * (transport / auth / 5xx) because a 400 is a DETERMINATE refusal: Mesh
 * validated the request and did not act, so nothing needs reconciling. The
 * vendor's own validation text is kept on the exception (the message and
 * vendorBody()), but it is the vendor's response body, so the executor
 * reports a refusal by its status only (C-56, card FLzMLDxF) through
 * statusPhrase(), not by this text.
 *
 * Two validators are measured (2026-09-01 enforcement test, prod tenant):
 *   - `sender`  → {"detail":"No Allow/Block Rules added","errors":["Invalid sender: …special-use or reserved…"]}
 *   - `comment` → {"comment":["String invalid"]}
 * The exact accepted charset for `comment` was NOT narrowed; the wrapper
 * therefore generates the comment itself rather than relying on knowing it.
 */
class MeshWriteRejectedException extends MeshClientException
{
    /** @param array<string, mixed> $vendorBody */
    public function __construct(
        string $message,
        private readonly array $vendorBody = [],
    ) {
        parent::__construct($message, 400);
    }

    /** @return array<string, mixed> */
    public function vendorBody(): array
    {
        return $this->vendorBody;
    }
}
