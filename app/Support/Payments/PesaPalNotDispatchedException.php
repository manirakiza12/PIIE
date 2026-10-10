<?php

namespace App\Support\Payments;

/**
 * The request was refused by our own validation before anything was sent to
 * PesaPal, so no order exists at the provider.
 *
 * This is deliberately distinct from PesaPalException, which also covers
 * ambiguous transport failures where an order may or may not have been
 * created. Only a genuine PesaPalException must keep an order reserved to
 * avoid double-charging; a PesaPalNotDispatchedException means nothing was
 * ever sent, so holding the reservation would strand the applicant with a
 * payment row they can neither complete nor clear.
 */
final class PesaPalNotDispatchedException extends PesaPalException
{
}