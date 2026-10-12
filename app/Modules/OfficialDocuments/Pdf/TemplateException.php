<?php

namespace App\Modules\OfficialDocuments\Pdf;

use RuntimeException;

/** A supplied file that cannot serve as a template (not a PDF, encrypted, unreadable). */
class TemplateException extends RuntimeException {}
