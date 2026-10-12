<?php

namespace App\Modules\Identity\Directory;

use RuntimeException;

/** The directory could not be reached or refused the service account. */
class DirectoryUnavailable extends RuntimeException {}
