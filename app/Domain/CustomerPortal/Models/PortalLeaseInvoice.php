<?php

namespace App\Domain\CustomerPortal\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['organization_id', 'lease_id', 'invoice_id', 'created_by', 'revoked_at', 'revocation_reason'])]
class PortalLeaseInvoice extends Model {}
