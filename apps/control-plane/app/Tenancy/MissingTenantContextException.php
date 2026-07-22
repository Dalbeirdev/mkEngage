<?php

declare(strict_types=1);

namespace App\Tenancy;

use RuntimeException;

final class MissingTenantContextException extends RuntimeException {}
