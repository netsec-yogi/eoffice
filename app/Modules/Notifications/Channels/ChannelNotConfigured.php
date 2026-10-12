<?php

namespace App\Modules\Notifications\Channels;

use RuntimeException;

/** The channel has no working provider. Delivery fails visibly; nothing is faked. */
class ChannelNotConfigured extends RuntimeException {}
