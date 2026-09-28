<?php

namespace App\Domain\CustomerPortal\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['organization_id', 'portal_grant_id', 'lease_id', 'maintenance_request_id', 'operation_key', 'title', 'description', 'priority'])]
class PortalServiceRequest extends Model {}
