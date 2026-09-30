<?php
declare(strict_types=1);

namespace Bank;

/** A business-rule failure (unbalanced entry, closed day, insufficient funds ...). Safe to show to staff. */
class LedgerException extends \RuntimeException
{
}
