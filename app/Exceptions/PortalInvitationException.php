<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * A portal invitation was refused for a reason the person sending it can fix:
 * the address belongs to another client's account, or access to it was
 * deliberately revoked. The message is user-facing Azerbaijani.
 */
class PortalInvitationException extends RuntimeException {}
